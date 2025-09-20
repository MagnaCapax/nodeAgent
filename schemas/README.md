# nodeAgentCollector Submission Schemas

This directory documents the payload envelope and per-metric schemas that nodeAgent submits to the nodeAgentCollector API. (The collector now resides in a separate repository; these copies are kept for reference and validation when evolving the agent.) All payloads are transmitted as JSON. By default the raw metrics are encrypted using GnuPG symmetric mode with a passphrase derived from the node's primary IPv4 and MAC address (`sha256(ip|mac)`), then base64 encoded inside the submission envelope. When encryption is unavailable, the agent falls back to plaintext while flagging the absence in the envelope metadata.

The human-readable contract lives in [`API.md`](API.md). The auxiliary `*.schema.json` files are retained for tooling that prefers machine-readable validation. Keep this directory in sync with the versions tracked in the nodeAgentCollector repository.
