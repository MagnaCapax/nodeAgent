# nodeAgentCollector API Overview

> _Note:_ nodeAgentCollector now lives in its own repository. This document is retained for quick reference while evolving the agent; ensure changes here stay in sync with the collector project.

nodeAgent submits telemetry to a Relaxed REST endpoint. The collector should ignore HTTP verb semantics and examine the decoded request payload (e.g., `$_REQUEST` in PHP). The agent always sends JSON by default, encapsulated in the envelope described below. Other clients may choose alternate encodings while preserving the same field structure.

## Transport Envelope

| Field | Type | Description |
|-------|------|-------------|
| `version` | string | Schema version (`1.0`). |
| `encrypted` | bool | Indicates whether the payload is encrypted. |
| `encryption.method` | string | `gpg-symmetric` when encryption is active. |
| `encryption.key_hint` | string | Derivation hint (`sha256(ip+mac)`). |
| `encryption.fingerprint` | string | First 16 hex chars of the derived passphrase’s SHA-256. |
| `ciphertext` | string | Base64 encoded GPG payload when `encrypted=true`. |
| `payload` | object | Raw metrics object when encryption is disabled. |
| `meta.profiling.encryption_ms` | number | Time spent preparing encryption. |

When encryption is unavailable the agent includes the plaintext metrics under `payload` and sets `encrypted=false`. The collector should honour this flag and avoid attempting to decrypt plaintext submissions.

## Metrics Payload

`payload.metrics` groups per-metric documents. Disabled collectors are omitted and enumerated in `payload.meta.disabled_metrics`.

### CPU (`metrics.cpu`)
- `usage_percent`: Aggregate usage over the sampling window.
- `core_count`: Logical CPUs observed.
- `architecture`: Kernel architecture (e.g., `x86_64`).
- `model`: CPU model string.
- `profiling.duration_ms`: Collector runtime.
- `profiling.sampling_interval_s`: Interval used when sampling `/proc/stat`.
- `raw_counters.before/after`: Snapshot of total and idle jiffies collected from `/proc/stat`.

### Memory (`metrics.memory`)
- `mem_total_kb`, `mem_available_kb`, `mem_used_kb`.
- `swap_total_kb`, `swap_free_kb`, `swap_used_kb`.
- `mem_usage_percent`, `swap_usage_percent`.
- `profiling.duration_ms`.
- `raw_meminfo`: Key/value map of `/proc/meminfo` at the time of sampling.

### Network (`metrics.network`)
- `interfaces`: Array of interfaces with RX/TX bytes per second and `operstate`.
- `pings`: Array of latency probes with `avg_rtt_ms`, `packet_loss`, and exit status.
- `profiling.duration_ms`, `interfaces_observed`, `ping_targets`.
- `interfaces[].raw_counters.before/after`: RX/TX byte counters from `/sys/class/net` for the measurement window.
- `pings[].raw_output`: Raw `ping` output captured for each probe.

### Storage Throughput (`metrics.storage`)
- `devices`: Each block device with `tps`, `kb_read_s`, `kb_wrtn_s` (when `iostat` available) or fallback counters when only `/proc/diskstats` is present.
- `profiling.duration_ms`, `iostat_used` (boolean), `sample_window_s` (default `3`).
- `raw_source`: Captured `iostat` lines or `/proc/diskstats` records for replay/troubleshooting.

### Storage Latency (`metrics.storage_latency`)
- `target`: Path probed via `ioping`.
- `available`: False when `ioping` is missing.
- `requests`: Summary of total requests, duration, throughput.
- `latency.min/avg/max/mdev`: Latency values including units.
- `profiling.duration_ms`.
- `available=false` payloads still include profiling data to reveal collector cost.

### Storage Health (`metrics.storage_health`)
- `devices`: SMART-capable devices with `healthy`, optional `model`, `serial`, and diagnostic messages.
- `nvme`: NVMe devices with `critical_warning` and `temperature_k` (Kelvin) when available.
- `profiling.duration_ms`.
- `devices[].attributes`: SMART attribute table (value/worst/threshold/raw) when available.
- `nvme[].nvme_stat`: Selected NVMe log counters (power cycles, media errors, etc.).

### Filesystem (`metrics.filesystem`)
- `filesystems`: Array with `filesystem`, `type`, `blocks_kb`, `used_kb`, `available_kb`, `use_percent`, and `mountpoint` derived from `df -P -T`.
- `profiling.duration_ms`.

### Metadata (`payload.meta`)
- `profiling.build_duration_ms`: Time to assemble the payload.
- `profiling.collectors`: Map of collector durations.
- `disabled_metrics`: Metrics disabled via configuration.
- `agent`: Includes `version` and `build_timestamp` to help fleet tooling reason about agent revisions.
- `sequence`: Monotonic counter assigned by the agent to help deduplicate submissions.

## Privacy & Control
- Operators can disable individual collectors via `conf/agent.json` (`metrics` section) without editing code.
- `bin/inspect_metrics.php` prints the current state of collected data and the would-be submission payload, allowing administrators to audit telemetry prior to transmission.
- No analytical decisions are performed on-host: nodeAgent only gathers and forwards metrics.

## Endpoints Summary

### `/health`
Returns `{"status":"ok"}` with HTTP 200 when the collector is ready. Errors yield HTTP 503.

### `/ingest`
Accepts Relaxed REST envelopes as described above. Method and `Content-Type` are ignored. Responses:

- `202 Accepted` on success
- `400 Bad Request` for schema violations (example: `{ "error": "Missing version" }`)
- `401 Unauthorized` when the `X-Collector-Token` header is missing or invalid
- `500 Internal Server Error` for unexpected failures

### `/metrics`
Prometheus exposition format containing collector health gauges and counters (`collector_ingest_success_total`, etc.).
