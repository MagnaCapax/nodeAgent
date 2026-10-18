# ADR-0001: Architecture, layout and languages

## Metadata
- Author: Aleksi Ursin / MCX
- Date: 2026-10-18
- Status: Accepted

## Context

nodeAgent is a telemetry and hardware health agent for bare-metal servers. It runs from cron, collects CPU, memory, network, filesystem, iostat, ioping and storage health (SMART, NVMe) snapshots into a local state directory, assembles them into one payload, optionally encrypts it with GnuPG, and submits it to a nodeAgentCollector instance, by default the `.2` address of the server's own IPv4 /24. It does no analysis on the host; interpretation is the collector's job. It has to run unchanged on Debian, Ubuntu, RHEL, Rocky Linux, Arch and similar systems, and removing `/opt/mcxNodeAgent` turns it off. The collector lives in a separate repository; this repository keeps a reference copy of the payload contract.

This repository had no decision records. This ADR writes down the architecture as it is, so later ADRs have something to supersede, and records how the organisation-wide [engineering principles](../../development/engineering-principles.md) apply here.

## Options Considered
- Leave the architecture undocumented outside the README: rejected. The README mixes the shipped design with suggestions (an "Architecture Outline" of modules that do not exist under those names, scheduler variants, future metrics), so a contributor cannot tell which parts are decided.
- Record the shipped architecture in one ADR, with later changes as new ADRs that supersede parts of it: chosen.

## Decision

### Layout and what each part owns

| Path | Owns |
|---|---|
| `mcxNodeAgent/` | The tree that is installed as `/opt/mcxNodeAgent`. `bin/install_agent.php` copies this directory, and only this directory. |
| `mcxNodeAgent/bin/` | Command-line entry points: `agent.php` (`health`, `inspect`, `submit`), `install_agent.php`, `install_cron.php`, `check_requirements.php`, `inspect_metrics.php`. |
| `mcxNodeAgent/cron/` | The three cron entry points: `collect_metrics.php` (CPU, memory, network, filesystems), `collect_storage.php` (iostat, ioping, storage health) and `send_payload.php` (build, then submit). Each runs its tasks as separate PHP processes. |
| `mcxNodeAgent/cron/tasks/` | One collector or step per file (`collect_cpu.php`, `build_payload.php`, `submit_payload.php`, ...). Collectors write JSON snapshots to the state directory. |
| `mcxNodeAgent/lib/` | Shared functions in the `McxNodeAgent` namespace: configuration (`config.php`), environment and distribution detection (`environment.php`, `system.php`), package and tool checks (`tooling.php`), logging (`logger.php`), cron rendering and running (`cron.php`), payload assembly (`payload.php`), submission with retry, hooks and fallback transport (`submission.php`), and helpers including GnuPG encryption (`helpers.php`). |
| `mcxNodeAgent/conf/` | `agent.json` (defaults; every top-level key can be overridden by an `MCXNA_<KEY>` environment variable) and `cron.template`, rendered into `/etc/cron.d/mcx-node-agent`. |
| `mcxNodeAgent/VERSION` | The agent version reported in the payload metadata. |
| `hooks/` | Sample `pre_submit` and `on_failure` hooks. The agent looks for live hooks in `hooks/` inside the installed tree (`/opt/mcxNodeAgent/hooks/`); the installer does not copy these samples. |
| `schemas/` | The payload contract: `API.md` (human-readable), `envelope.schema.json` and `metrics/*.schema.json`. A reference copy of the collector's contract, kept in sync with it. |
| `tests/` | A dependency-free test harness: `run.php`, `TestCase.php`, `mocks.php`, `*Test.php` and `/proc` and `/sys` fixtures. |

Runtime data goes outside the tree: snapshots and locks in `/var/lib/mcxNodeAgent`, logs in `/var/log/mcxNodeAgent` (both configurable).

### Languages

Everything is PHP, following principle 1 (PHP for logic, shell only as thin glue, no Python). The agent's work is the kind of code principle 1 says must not be shell: parsing `/proc`, `/sys` and command output, counter arithmetic, building and encoding JSON payloads, retry and backoff, and HTTP submission. `json_encode` builds the payload, so collected text cannot inject fields. One runtime also serves the distribution goal: the README states that the agent scripts require only PHP and the configured directories, and missing system tools (`iostat`, `smartctl`, `nvme`, `ioping`, `gpg`) are detected at run time and logged instead of being hard requirements. Submission uses the cURL extension when it is present and PHP's own HTTP stream otherwise.

There are no shell scripts in the repository; cron lines call `php` directly. No Python, no Composer dependencies.

PHP version: the README asks for PHP 8.x with the JSON extension (cURL optional). The code uses PHP 8.0 functions such as `str_starts_with`. Files use `declare(strict_types=1)`.

### Build, run and test
- Build: none.
- Install: `sudo php mcxNodeAgent/bin/install_agent.php` copies the tree to `/opt/mcxNodeAgent`, creates the state and log directories, checks tooling and installs the cron file; `--dry-run` previews it. `bin/check_requirements.php` and `bin/install_cron.php` run those steps alone.
- Run: cron runs the three entry points at the intervals set in `agent.json`. `bin/agent.php health`, `bin/agent.php submit --dry-run` and `bin/inspect_metrics.php` inspect state without sending.
- Test: `php tests/run.php`. It loads every `tests/*Test.php`, runs each test case and exits non-zero on any failure. It needs only the PHP CLI. There is no CI.

### Conventions in use
- Functions are camelCase in the `McxNodeAgent` namespace (`loadConfig`, `resolvePingTargets`, `assemblePayload`).
- File names are snake_case (`collect_cpu.php`, `install_agent.php`). Configuration keys and payload fields are snake_case too (`collector_endpoint`, `meta.disabled_metrics`); they are interfaces shared with deployed `agent.json` files, `MCXNA_*` variables and the collector.
- Fail soft: a missing tool or a disabled collector is logged and skipped; a failing collector is logged with its exit code and the other collectors still run. The one deliberate stop: when building the payload fails, `send_payload.php` exits before submitting.
- Raw counters travel with every processed metric, and each collector records its own run time (`profiling.duration_ms`).
- Safe defaults and switches: `--dry-run` on the installers and on submission, `maintenance_until` to pause collection, `busy_thresholds` to skip heavy collectors on a loaded host, and a `pre_submit` hook that can block transmission.
- At least one comment line per ten lines of code (AGENTS.md).

### How the principles apply
1. **PHP, shell as glue:** as above. A new collector or tool is PHP.
2. **Files around 150 lines:** 10 of the 30 tracked runtime PHP files were over 150 lines when this ADR was written; the largest is `lib/helpers.php` at 313. Split them when changing them, not in a sweep.
3. **camelCase, broad to specific:** new variables, functions and code files are camelCase. Existing file names stay: the cron entry paths are written into installed `/etc/cron.d/mcx-node-agent` files, and the task files follow the same pattern. Configuration keys and payload fields stay snake_case, because renaming one breaks deployed configurations and the collector.
4. **KISS, DRY, YAGNI:** the agent gathers and sends; it does not analyse. Shared logic lives in `lib/`, and the cron entry points reuse one runner (`runCronScript`).
5. **Delete first:** before adding a metric or a setting, check that the collector actually uses it.
6. **Pit of success:** the default endpoint is discovered, not typed; encryption is on whenever `gpg` exists and its absence is flagged in the envelope; previews are one flag away.
7. **Never break old users:** keep `agent.json` keys, `MCXNA_*` variables, cron entry paths and the payload contract compatible; change the payload together with the collector and `schemas/`.
8. **Tests that can fail:** `php tests/run.php` before every commit, and a new behaviour gets a test that fails when the behaviour is broken.
9. **Decisions are binding:** significant changes ship with an ADR in this directory.
10. **Fail soft, explain why:** as in the conventions above.

## Consequences
- A contributor can see which parts of the README are the shipped design.
- Known gaps, recorded here rather than fixed in this ADR: ten runtime files are over the size target; there is no CI; the hook samples are not part of the installed tree; `AGENTS.md` and the README still carry "default to Bash" and "simple shell tooling" lines that predate principle 1 and need a decision of their own.
- Any change to the layout, the installed paths, the payload contract or the language rule needs a new ADR that supersedes the relevant part of this one.

## References
- [README.md](../../README.md), [schemas/API.md](../../schemas/API.md), [schemas/README.md](../../schemas/README.md)
- [Engineering principles](../../development/engineering-principles.md)
