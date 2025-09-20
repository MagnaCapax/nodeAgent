# nodeAgent

## Overview
nodeAgent is a privacy-first monitoring daemon tailored for bare-metal servers operated by Pulsed Media / Magna Capax Finland Oy. It captures hardware health and utilization signals required for fleet operations while keeping raw data on the node. Only essential rollups are transmitted to the nodeAgentCollector service (maintained in a separate repository), typically reachable on the local IPv4 /24 at the `.2` address. The agent is designed to live in `/opt/mcxNodeAgent`, run via cron-backed collection routines, and operate without distro-specific assumptions.

Operators retain full control: removing `/opt/mcxNodeAgent` (or `/opt/nodeAgent` on legacy deployments) disables telemetry immediately.

## Project Goals
- Deliver a single, distro-agnostic toolkit that runs unmodified on Debian, Ubuntu, RHEL, Rocky Linux, Arch, and similar systems.
- Prioritize privacy by limiting outbound data and keeping detailed logs on the node.
- Provide high-signal metrics for capacity planning, hardware health, and abuse detection without invasive inspection.
- Remain KISS-aligned—simple shell tooling, minimal dependencies, and documented fallbacks.
- Favor fail-soft behavior: collectors should capture what they can, log gaps, and continue.
- Autodetect node topology (distro family, outbound IPv4) to configure sensible defaults without user input.
- Document the collector contract explicitly so downstream tooling stays in sync (`schemas/`).
- Defer all analysis to the collector backend—nodeAgent’s responsibility stops at gathering and transmitting metrics.

## Deployment Layout
The agent should follow a predictable filesystem structure to support cron jobs, logging, and idempotent upgrades:
- `/opt/mcxNodeAgent/bin/` – executable PHP CLI collectors, submission helpers, and bootstrap scripts.
- `/opt/mcxNodeAgent/lib/` – shared PHP helpers (environment detection, parsing, logging primitives).
- `/opt/mcxNodeAgent/cron/` – cron-safe entrypoints that sequence collectors and submissions.
- `/opt/mcxNodeAgent/conf/` – user-editable configuration (endpoints, scheduling knobs, feature flags).
- `/var/lib/mcxNodeAgent/` – transient caches, lock files, and metric snapshots (configurable via `agent.json`).
- `/var/log/mcxNodeAgent/` – rotating logs and collector output for local inspection.
- `/etc/cron.d/mcx-node-agent` – cron schedule installed by the provisioning script.

This repository now ships the scaffolding under `mcxNodeAgent/` mirroring the intended on-host layout. Provisioning routines should copy that tree into `/opt/mcxNodeAgent` as part of installation, preserving executable bits on the PHP entrypoints under `bin/`.

## Installation
1. Clone the repository and enter the workspace:
   ```bash
   git clone https://github.com/<org>/nodeAgent.git
   cd nodeAgent
   ```
2. Run the provisioning helper (append `--dry-run` to preview without applying changes):
   ```bash
   sudo php mcxNodeAgent/bin/install_agent.php
   ```
   The helper copies files into `/opt/mcxNodeAgent`, ensures `/var/lib` and `/var/log` paths exist, verifies tooling, and installs cron jobs.
   Use `--only-requirements`, `--only-cron`, or `--list-packages` for targeted workflows.
3. Review and, if required, customise `/opt/mcxNodeAgent/conf/agent.json` with local collector details, ping targets, sampling intervals, cron cadences, log format, and preferred `/var` storage locations. Leaving `collector_endpoint` set to `auto` points the agent at the `.2` host inside the detected /24.
4. Run the prerequisite inspector to confirm required tooling is present (append `--dry-run` to preview or `--no-install` to skip package installation):
   ```bash
   sudo php /opt/mcxNodeAgent/bin/check_requirements.php
   ```
   Any missing commands are reported along with distro-aware package hints, and—when run as root—automatically installed.
5. Reinstall the cron schedule manually if needed:
   ```bash
   sudo php /opt/mcxNodeAgent/bin/install_cron.php
   ```
   Use `--dry-run` to preview the cron file without writing it.

## Runtime Requirements
- PHP 8.x CLI with JSON and (optionally) cURL extensions enabled.
- Standard Linux utilities (`iostat`, `ping`, `smartctl`, `nvme`) are detected at runtime; missing tools result in logged warnings instead of hard failures.
- Optional utilities (`ioping`, `gpg`) unlock storage latency sampling and encrypted transport; the agent will warn and fall back gracefully when they are absent.
- Access to `/proc`, `/sys`, and `/var/log` for metric collection and logging.
- nodeAgent performs no on-host analytics; it only gathers metrics and forwards them according to configuration.

## Control & Auditing
- Every collector can be enabled or disabled via the `metrics` section in `conf/agent.json`. Example:
  ```json
  "metrics": { "cpu": true, "network": false }
  ```
  Disabled collectors exit immediately and the payload enumerates them under `meta.disabled_metrics`.
- `bin/inspect_metrics.php` previews the current state of collected data and the assembled payload. Example:
  ```bash
  php /opt/mcxNodeAgent/bin/inspect_metrics.php --metric=memory --diff
  ```
  Use `--raw` for the full JSON envelope or `--format=markdown` for a Markdown summary.
- `bin/agent.php health` runs a quick readiness check (tooling, directory permissions). `bin/agent.php inspect --raw` mirrors the inspection helper.
- `bin/agent.php submit --dry-run` previews the envelope without sending; add `--retries=` or `--backoff=` to override submission behaviour, and `--debug` to echo server responses.
- `maintenance_until` (ISO datetime or epoch) pauses all collectors until the timestamp elapses.
- Optional hook: create `hooks/pre_submit.php` returning `false` to prevent transmission (payload is passed as an array for privacy filters).
- Failure alert hook: `hooks/on_failure.php` receives `{count, message, timestamp}` once the configured `failure_alert_threshold` is exceeded; use it to trigger email/webhook notifications.
- Sample hook templates live in `hooks/pre_submit.sample.php` and `hooks/on_failure.sample.php`.
- Enable `submission_compress` to gzip payloads; the companion collector automatically decompresses requests with `Content-Encoding: gzip`.
- The payload contract is documented in detail under `schemas/API.md`.
- The agent performs zero in-agent analytics—interpretation is deferred entirely to the collector backend.
- Raw counter snapshots (jiffies, byte counts, SMART attributes, etc.) accompany every metric so the backend receives the same evidence the agent observed.
- When `busy_thresholds` are configured the agent samples system state (load average, gateway ping) and probabilistically skips heavy collectors (default 90% skip) to avoid overloading production workloads.
- A companion collector (`nodeAgentCollector`) stores both the raw envelope and decrypted payload, ensuring downstream pipelines can replay the exact data the agent produced. The collector now lives in its own repository; see the “Collector Companion” section below.
- Set `log_format` to `json` in `conf/agent.json` when structured logging is required for aggregation pipelines; leave it as `text` for human-friendly output.

## Architecture Outline
nodeAgent favors small, composable scripts coordinated by cron. A suggested module layout:
- `env-detect` – probes distro, package availability, block devices, and capabilities at runtime.
- `collector-*` scripts – gather specific metric families (CPU, memory, storage, network, etc.).
- `aggregator` – normalizes collector output, resolves node identity, and prepares payloads.
- `submitter` – manages secure HTTP/S submissions to the `.2` collector endpoint with retry/backoff.
- `scheduler` – thin wrapper invoked by cron to sequence collectors, enforce locking, and emit status logs.
- `installer` – idempotent setup tool to place files under `/opt/mcxNodeAgent`, install cron, and perform smoke tests.

All modules should share logging helpers (e.g., `print_step`, `warn`, `die_soft`) to maintain consistent observability.

## Data Flow
1. **Cron Trigger**: Scheduler script runs on a defined cadence (e.g., every minute for lightweight checks, every five minutes for heavier metrics).
2. **Environment Verification**: Scheduler calls `env-detect`; missing utilities trigger warnings and optional self-healing (e.g., hinting that `sysstat` or `smartmontools` is required).
3. **Metric Collection**: Collector scripts execute in sequence or parallel (with locking) and capture metrics into the configured state directory (default `/var/lib/mcxNodeAgent`). Each collector stores both processed values and the raw counters/output gathered from the system.
4. **Aggregation**: Aggregator converts the collected raw samples into a structured payload (without summarising beyond what the collector already recorded), tagging timestamps, collectors, and node identity.
5. **Submission**: Submitter posts aggregations to the auto-discovered collector endpoint (defaults to the `.2` peer inside the detected /24), retrying on transient failures and deferring on hard faults.
6. **Logging**: Each step writes to `/var/log/mcxNodeAgent/agent.log`, keeping concise status lines for tracing long-running operations.

## Metrics Collected
- `iostat` series for every detected block device (requires `sysstat`).
- SMART health summaries for storage (`smartctl` when present).
- NVMe and RAID status via `nvme-cli`/`mdadm` if available.
- Storage latency sampling via `ioping` (when available) to capture min/avg/max I/O delay.
- Storage health summaries via `smartctl`/`nvme smart-log` to spot failing devices early.
- Network interface throughput, bandwidth utilization, error counters, and carrier state.
- CPU load, utilization, and saturation (from `/proc/stat`, `mpstat`, or `sar`).
- Memory availability, pressure metrics, swap usage, and OOM events.
- Filesystem utilisation (blocks and inodes) for each mounted filesystem.
- Optional add-ons: additional latency probes or process inventory—each must meet privacy standards before inclusion.

## Scheduling Model
Install a cron file (`/etc/cron.d/mcx-node-agent`) with representative jobs:
- `*/1 * * * *` – `php /opt/mcxNodeAgent/cron/collect_metrics.php` to update CPU, memory, and network snapshots under `/var/lib/mcxNodeAgent`.
- `*/5 * * * *` – `php /opt/mcxNodeAgent/cron/collect_storage.php` to gather iostat and storage-related metrics without burdening the system.
- `*/5 * * * *` – `php /opt/mcxNodeAgent/cron/send_payload.php` to aggregate the latest snapshots and submit them to the collector endpoint.
- `@reboot` – trigger the fast collector cron to re-establish fresh baselines after power events.

Cron entries should guard against overlapping runs via lock files in the configured state directory when concurrency becomes a concern.

The cron installer renders `/etc/cron.d/mcx-node-agent` from `conf/cron.template`, replacing placeholders (e.g., `##CRON_CORE_INTERVAL##`) with values sourced from `agent.json`. This keeps the schedule idempotent and easy to audit.
Pass `--metrics=cpu,network` (or similar) to the cron scripts for targeted debugging runs.

### Scheduler Variants
- **systemd timers:** create timer/service units calling the PHP scripts; enable with `systemctl enable --now nodeAgent-collect.timer`.
- **User crontab:** run `crontab -e` and add entries such as `* * * * * /usr/bin/php /opt/mcxNodeAgent/cron/collect_metrics.php --metrics=cpu,network` for ad-hoc filtering.
- **Anacron:** add jobs to `/etc/anacrontab` for laptops or intermittently powered nodes (`1   10  mcx-collect  /usr/bin/php ...`).
- **OpenRC / run-crons:** drop executable wrappers into `/etc/periodic/{hourly,daily}` invoking the same PHP scripts.
- **Launchd (macOS/FreeBSD):** use `.plist` launch daemons with `StartInterval` or `StartCalendarInterval` keys pointing to the PHP entrypoints.

Select the scheduler that matches your distribution; the agent scripts require only PHP and the configured directories.

## Profiling & Telemetry Envelope
- Every collector records a `profiling.duration_ms` value in its JSON output, allowing the aggregator to track runtime cost.
- `build_payload.php` summarises collector timings under `meta.profiling.collectors` and records its own build time.
- `submit_payload.php` encrypts the payload using GPG symmetric encryption with the passphrase `sha256(primaryIPv4|primaryMAC)`. The submission envelope contains encryption metadata (see `schemas/envelope.schema.json`).
- When GPG is unavailable the agent sends plaintext, but flags the condition in the envelope metadata while logging a warning.
- `meta.agent.version` and `meta.agent.build_timestamp` identify the agent revision (pulled from `VERSION` or git metadata) for fleet diagnostics.

## Relaxed REST Collector API
- nodeAgent targets a "Relaxed REST" collector: HTTP method semantics are ignored and the server inspects `$_REQUEST` for the envelope. The agent submits JSON by default, but alternate clients may POST form data or query parameters without breaking compatibility.
- nodeAgent expects an HTTP 2xx response on success; non-2xx responses are logged and treated as transient failures for operator review.

## Installation & Recovery Workflow
1. Detect and record host environment (distro, package manager, storage layout).
2. Stage binaries and scripts under `/opt/mcxNodeAgent` with idempotent copy routines.
3. Install or refresh cron schedules, preserving local overrides when present.
4. Run smoke tests (`--dry-run`, `--self-test`) to confirm collectors function without destructive effects.
5. Provide uninstall helper that stops cron jobs, archives logs, and removes `/opt/mcxNodeAgent` safely.

Re-running the installer must be safe on partially configured systems and should repair known drift (e.g., missing cron entries, stale locks).

## Privacy and Opt-Out
- Outbound data is limited to summarized metrics required for operations; raw logs remain on the node.
- No third-party endpoints are contacted—only the colocated collector within the same /24 network unless explicitly reconfigured.
- To stop telemetry, remove `/opt/mcxNodeAgent`, disable associated cron entries, or run the provided uninstall script.

## Configuration and Logging
- Primary configuration lives in `/opt/mcxNodeAgent/conf/agent.json`; defaults cover collector endpoint behaviour (auto-deriving the `.2` peer), ping targets, sampling intervals, cron cadences, submission toggles, and the preferred `/var` storage roots. Environment overrides can be applied with `MCXNA_*` variables for automation workflows.
- Notable keys:
  - `collector_endpoint`/`collector_port`/`collector_path` – leave `collector_endpoint` as `auto` to target the `.2` peer, or override with a custom URL.
  - `cron_core_interval`, `cron_iostat_interval`, `cron_submission_interval` – control minutes between the respective cron runs.
  - `state_dir` and `log_dir` – customise on-disk storage locations if `/var` is unsuitable.
  - `ioping_target` – filesystem path probed by `ioping` for latency snapshots.
  - `submission_retries`, `submission_backoff_base` – control delivery resilience.
  - `submission_compress` – when `true`, payloads are gzipped before submission.
  - `failure_alert_threshold` – number of consecutive failures before triggering `hooks/on_failure.php`.
  - `log_format` – `text`, `json` (both console and file), or `json_file` (JSON in file, text on console).
  - `log_rotate_max_bytes`, `log_rotate_keep` – optional log rotation controls.
  - `metrics` – per-collector boolean flags to enable/disable specific data sources.
  - `busy_thresholds` – optional throttling controls such as `load1`, `ping_ms`, and `skip_probability` (fraction of runs skipped when the host is overloaded).
  ```json
  "busy_thresholds": { "load1": 6.0, "ping_ms": 250, "skip_probability": 0.9 }
  ```
  - `maintenance_until` – ISO8601 timestamp or epoch after which collectors resume automatically.
- Shared helpers in `/opt/mcxNodeAgent/lib/` expose PHP functions such as `loadConfig()`, `resolvePingTargets()`, and a JSON-safe logging facility emitting to `/var/log/mcxNodeAgent/agent.log` with a state-directory fallback.
- Collectors write JSON snapshots into `/var/lib/mcxNodeAgent` (or the configured state directory), after which `build_payload.php` assembles a combined payload for `submit_payload.php` to POST.
- Schema definitions and narrative field descriptions reside in `schemas/API.md`. Downstream services should track those notes to remain compatible with the agent's payload contract.

## Distro & Dependency Checks
- `bin/check_requirements.php` detects the host distribution, reports missing commands (e.g., `iostat`, `smartctl`, `nvme`, `ioping`, `gpg`), and installs the needed packages when run as root (use `--dry-run` or `--no-install` to preview only).
- The cron installer reuses this logic to warn about gaps before writing schedules, keeping the process idempotent and transparent.

## Testing
- A lightweight test harness lives under `tests/`. Run `php tests/run.php` after local changes to exercise basic configuration helpers and schema scaffolding.
- Tests rely only on the PHP CLI, using in-repo assertions to honour the no-extra-dependency policy.

## Future Metrics (Recommendations)
1. Filesystem utilisation (blocks and inodes) per mountpoint.
2. Drive and NVMe temperatures via `smartctl`/`nvme smart-log`.
3. Sensor readings for CPU/package temperature (`sensors` / `lm-sensors`).
4. Systemd unit health (failed units, services in degraded state).
5. Kernel Machine Check Exception (MCE) counters from `/var/log/mcelog` or `ras-mc-ctl`.
6. PCIe error counters per device (AER status).
7. Interrupt distribution and softirqs to detect saturation (`/proc/interrupts`).
8. Network quality metrics (ethtool statistics, FEC errors, carrier loss counts).
9. Filesystem latency via `fio --status-interval` snapshots for critical mounts.
10. Container/runtime inventory (running Dockerd/Podman workloads) for consolidation planning.

## Collector Companion
- nodeAgentCollector is maintained in a standalone repository that provides API endpoints, database schemas, and installer helpers to accept agent telemetry. Consult that project’s README for deployment guidance and keep schemas in sync with the copies under `schemas/` here.

## Development Notes
- Favor Bash for automation; escalate to PHP only when workflows become unwieldy but remain within repo standards.
- Maintain at least one line of commentary for every ten lines of code to keep complex logic readable.
- Validate scripts with `shellcheck` and format using `shfmt -w` before submitting patches.
- Record manual verification steps (e.g., `--help`, `--dry-run`, sample log snippets) in change notes.
- Review `AGENTS.md` for repository-wide expectations and always check for more specific `AGENTS.md` files in subdirectories before editing.

For questions or design proposals (e.g., new collectors, dependency additions), open a discussion before implementation so the privacy and maintenance impacts can be reviewed.
