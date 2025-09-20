<?php
// Shared constant definitions for mcxNodeAgent libraries.

declare(strict_types=1);

namespace McxNodeAgent;

// Root path for repository files relative to the lib directory.
const ROOT_DIR = __DIR__ . '/..';
// Default location for writable state such as cached metrics.
const DEFAULT_STATE_DIR = '/var/lib/mcxNodeAgent';
// Default path for agent logs; falls back to state directory when absent.
const DEFAULT_LOG_DIR = '/var/log/mcxNodeAgent';
// Preferred log format when configuration does not override it.
const DEFAULT_LOG_FORMAT = 'text';
// Default collector port and path used when autodetecting endpoints.
const DEFAULT_COLLECTOR_PORT = 8080;
const DEFAULT_COLLECTOR_PATH = '/nodeAgentCollector/';
// Metric flags shipped with the agent; individual collectors may be disabled.
const DEFAULT_METRIC_FLAGS = [
    'cpu' => true,
    'memory' => true,
    'network' => true,
    'storage' => true,
    'storage_latency' => true,
    'storage_health' => true,
    'filesystem' => true,
];
// Busy-threshold defaults used by throttling guards when hosts are under load.
const DEFAULT_BUSY_THRESHOLDS = [
    'load1' => null,
    'ping_ms' => null,
    'skip_probability' => 0.9,
];
