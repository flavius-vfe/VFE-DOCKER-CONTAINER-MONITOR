#!/usr/bin/php
<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/vfe.docker.container.monitor/include/guardian.php';

$containerName = trim((string)($argv[1] ?? ''));
$jobId = trim((string)($argv[2] ?? ''));
if ($containerName === '' || $jobId === '') {
    fwrite(STDERR, "Usage: guardian-test-worker.php <container> <job-id>\n");
    exit(2);
}

$job = cg_test_job_load($containerName);
if ($job === [] || !hash_equals((string)($job['job_id'] ?? ''), $jobId)) {
    fwrite(STDERR, "On-demand job was not found\n");
    exit(3);
}

$finished = false;
$cancelSignal = false;
$lockHandle = null;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$cancelSignal): void { $cancelSignal = true; });
    pcntl_signal(SIGINT, static function () use (&$cancelSignal): void { $cancelSignal = true; });
}

register_shutdown_function(static function () use (&$finished, &$lockHandle, &$cancelSignal, $containerName, $jobId): void {
    cg_container_check_unlock($lockHandle);
    if ($finished) {
        return;
    }
    try {
        cg_test_job_update($containerName, static function (array $current) use ($jobId, &$cancelSignal): array {
            if (!hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
                return $current;
            }
            if (!in_array((string)($current['status'] ?? ''), ['queued', 'running', 'cancelling'], true)) {
                return $current;
            }
            $cancelled = $cancelSignal || !empty($current['cancel_requested']);
            $activeCheck = (string)($current['current_check'] ?? '');
            $current['status'] = $cancelled ? 'cancelled' : 'failed';
            $current['message'] = $cancelled ? 'Test cancelled' : 'Test worker stopped unexpectedly';
            $current['finished_at'] = microtime(true);
            $current['current_check'] = '';
            $current['current_child_pid'] = 0;
            if ($activeCheck !== '' && isset($current['checks'][$activeCheck]) && is_array($current['checks'][$activeCheck])) {
                $current['checks'][$activeCheck]['status'] = $cancelled ? 'cancelled' : 'failed';
                $current['checks'][$activeCheck]['ok'] = false;
                $current['checks'][$activeCheck]['message'] = $current['message'];
                $current['checks'][$activeCheck]['failure_reason'] = $current['message'];
                $current['checks'][$activeCheck]['tested_at'] = microtime(true);
            }
            return $current;
        });
    } catch (Throwable) {
        // The worker is already shutting down; diagnostics are best effort.
    }
});

$lockHandle = cg_container_check_lock($containerName, true);
if ($lockHandle === false) {
    cg_test_job_update($containerName, static function (array $current) use ($jobId): array {
        if (!hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
            return $current;
        }
        $current['status'] = 'failed';
        $current['message'] = 'Another check is already running for this container';
        $current['finished_at'] = microtime(true);
        return $current;
    });
    $finished = true;
    exit(4);
}

$job = cg_test_job_update($containerName, static function (array $current) use ($jobId): array {
    if (!hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
        throw new RuntimeException('The on-demand job was replaced before it started.');
    }
    $current['status'] = 'running';
    $current['started_at'] = microtime(true);
    $current['worker_pid'] = getmypid();
    $current['message'] = 'Running enabled checks';
    return $current;
});

$config = isset($job['container_config']) && is_array($job['container_config'])
    ? cg_normalize_container_config($job['container_config'])
    : cg_default_container_config();
$mode = (string)($job['mode'] ?? 'manual');

$isCancelled = static function () use (&$cancelSignal, $containerName, $jobId): bool {
    if ($cancelSignal) {
        return true;
    }
    $current = cg_test_job_load($containerName);
    return $current === []
        || !hash_equals((string)($current['job_id'] ?? ''), $jobId)
        || !empty($current['cancel_requested']);
};

$progress = static function (string $check, string $status, array $data) use ($containerName, $jobId): void {
    cg_test_job_update($containerName, static function (array $current) use ($jobId, $check, $status, $data): array {
        if (!hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
            return $current;
        }
        if (!isset($current['checks']) || !is_array($current['checks'])) {
            $current['checks'] = [];
        }
        $existing = is_array($current['checks'][$check] ?? null) ? $current['checks'][$check] : [];
        if ($status === 'running') {
            $current['current_check'] = $check;
            $current['checks'][$check] = array_replace($existing, [
                'status' => 'running',
                'message' => 'Running…',
                'target' => (string)($data['target'] ?? ($existing['target'] ?? '')),
                'started_at' => (float)($data['started_at'] ?? microtime(true)),
            ]);
        } else {
            $current['checks'][$check] = array_replace($existing, $data);
            $current['current_check'] = '';
            $current['completed_checks'] = (int)($current['completed_checks'] ?? 0) + 1;
        }
        return $current;
    });
};

$childPid = static function (int $pid) use ($containerName, $jobId): void {
    cg_test_job_update($containerName, static function (array $current) use ($jobId, $pid): array {
        if (hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
            $current['current_child_pid'] = $pid;
        }
        return $current;
    });
};

try {
    $containers = cg_list_containers();
    $result = cg_perform_checks($containerName, $config, $containers, $progress, $isCancelled, $childPid);
    $now = cg_now();
    $runtimeRecord = null;

    if ($mode === 'run_now' && empty($result['cancelled'])) {
        // Immediate automatic-style check: update automatic result and failure
        // count, but deliberately never start/restart/quarantine anything.
        $runtimeRecord = cg_runtime_update_container($containerName, static function (array $current) use ($result, $now): array {
            $current['last_check'] = $now;
            $current['last_result'] = (bool)$result['ok'];
            $current['last_message'] = 'Immediate check: ' . (string)$result['message'] . '; no action performed';
            $current['last_details'] = $result['details'];
            $current['last_check_source'] = 'run-now';
            if (!empty($result['ok'])) {
                $current['consecutive_failures'] = 0;
            } else {
                $current['consecutive_failures'] = (int)($current['consecutive_failures'] ?? 0) + 1;
            }
            return $current;
        });
    }

    $job = cg_test_job_update($containerName, static function (array $current) use ($jobId, $result, $runtimeRecord): array {
        if (!hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
            return $current;
        }
        $wasCancelled = !empty($result['cancelled']) || !empty($current['cancel_requested']);
        $activeCheck = (string)($current['current_check'] ?? '');
        $current['status'] = $wasCancelled ? 'cancelled' : 'completed';
        $current['ok'] = $wasCancelled ? null : (bool)$result['ok'];
        $current['message'] = $wasCancelled ? 'Test cancelled' : (string)$result['message'];
        $current['finished_at'] = microtime(true);
        $current['current_check'] = '';
        $current['current_child_pid'] = 0;
        $current['result_details'] = $result['details'];
        if ($wasCancelled && $activeCheck !== '' && isset($current['checks'][$activeCheck]) && is_array($current['checks'][$activeCheck])) {
            $current['checks'][$activeCheck]['status'] = 'cancelled';
            $current['checks'][$activeCheck]['ok'] = false;
            $current['checks'][$activeCheck]['message'] = 'Test cancelled';
            $current['checks'][$activeCheck]['failure_reason'] = 'Test cancelled';
            $current['checks'][$activeCheck]['tested_at'] = microtime(true);
        }
        if (is_array($runtimeRecord)) {
            $current['runtime'] = $runtimeRecord;
        }
        return $current;
    });

    cg_log(!empty($result['ok']) ? 'INFO' : 'WARN', $mode === 'run_now' ? 'Immediate check executed' : 'Manual test executed', [
        'container' => $containerName,
        'result' => $result['ok'] ?? null,
        'cancelled' => $result['cancelled'] ?? false,
        'message' => $result['message'] ?? '',
        'uses_unsaved' => !empty($job['uses_unsaved']),
    ]);
    $finished = true;
    cg_container_check_unlock($lockHandle);
    $lockHandle = null;
    exit(0);
} catch (Throwable $error) {
    cg_log('ERROR', 'On-demand check worker failed', ['container' => $containerName, 'error' => $error->getMessage()]);
    cg_test_job_update($containerName, static function (array $current) use ($jobId, $error): array {
        if (!hash_equals((string)($current['job_id'] ?? ''), $jobId)) {
            return $current;
        }
        $current['status'] = 'failed';
        $current['message'] = $error->getMessage();
        $current['finished_at'] = microtime(true);
        $current['current_check'] = '';
        $current['current_child_pid'] = 0;
        return $current;
    });
    $finished = true;
    cg_container_check_unlock($lockHandle);
    $lockHandle = null;
    exit(1);
}
