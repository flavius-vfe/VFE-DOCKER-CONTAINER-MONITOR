<?php

declare(strict_types=1);

require_once __DIR__ . '/guardian.php';

/**
 * Reconcile volatile runtime records after a persistent configuration save.
 * $containerNames limits the update to selected containers; null updates all.
 */
function cg_api_reconcile_runtime(array $previous, array $saved, ?array $containerNames = null): array
{
    $runtime = cg_runtime_load();
    $globalJustEnabled = empty($previous['global_enabled']) && !empty($saved['global_enabled']);
    $names = $containerNames ?? array_keys($saved['containers'] ?? []);
    foreach ($names as $containerName) {
        if (!isset($saved['containers'][$containerName])) {
            continue;
        }
        $containerConfig = $saved['containers'][$containerName];
        $current = array_replace(cg_container_runtime_default(), $runtime['containers'][$containerName] ?? []);
        $wasEnabled = !empty($previous['containers'][$containerName]['enabled']);
        $isEnabled = !empty($containerConfig['enabled']);
        if ($isEnabled && (!$wasEnabled || $globalJustEnabled || (int)$current['last_check'] === 0)) {
            $current['last_check'] = 0;
            $current['last_result'] = null;
            $current['last_message'] = !empty($saved['global_enabled'])
                ? 'Queued for first automatic check'
                : 'Global monitoring is disabled';
            $current['last_details'] = [];
            $current['last_check_source'] = '';
            $current['consecutive_failures'] = 0;
        } elseif (!$isEnabled) {
            $current['last_result'] = null;
            $current['last_message'] = 'Monitoring disabled for this container';
            $current['last_details'] = [];
            $current['last_check_source'] = '';
            $current['consecutive_failures'] = 0;
        }
        $current['_updated_at'] = microtime(true);
        $runtime['containers'][$containerName] = $current;
    }
    cg_runtime_save($runtime);
    return $runtime;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = is_array($_POST) ? $_POST : [];

// Unraid's global CSRF bootstrap can populate $_POST with only csrf_token even
// when the plugin request body is JSON. Never let that hide the real payload.
// Parse every supported transport and merge the decoded payload over $_POST.
if (isset($_POST['payload']) && is_string($_POST['payload'])) {
    $decodedPayload = json_decode($_POST['payload'], true);
    if (!is_array($decodedPayload)) {
        cg_json_response(['ok' => false, 'error' => 'Invalid encoded request payload.'], 400);
    }
    $body = array_replace($body, $decodedPayload);
}

$rawBody = file_get_contents('php://input');
if (is_string($rawBody) && trim($rawBody) !== '') {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json') || str_starts_with(ltrim($rawBody), '{')) {
        $decodedJson = json_decode($rawBody, true);
        if (!is_array($decodedJson)) {
            cg_json_response(['ok' => false, 'error' => 'Invalid JSON request body.'], 400);
        }
        $body = array_replace($body, $decodedJson);
    }
}

if ($method === 'POST' && empty($body['action'])) {
    cg_json_response(['ok' => false, 'error' => 'POST action is required.'], 400);
}
$action = (string)($body['action'] ?? $_GET['action'] ?? 'snapshot');

try {
    if ($action === 'snapshot') {
        $config = cg_config_load();
        $containers = cg_list_containers();
        $runtime = cg_runtime_load();
        $discoveries = [];
        $tests = [];
        foreach ($containers as $name => $container) {
            $discoveries[$name] = cg_discovery_for($container);
            $job = cg_test_job_refresh_liveness($name);
            if ($job !== []) {
                $tests[$name] = cg_test_job_public($job);
            }
        }
        cg_json_response([
            'ok' => true,
            'config' => $config,
            'containers' => $containers,
            'runtime' => $runtime,
            'tests' => $tests,
            'discoveries' => $discoveries,
            'daemon' => cg_daemon_status(),
            'server_time' => cg_now(),
        ]);
    }

    if ($action === 'log') {
        cg_json_response(['ok' => true, 'lines' => cg_tail_log((int)($_GET['lines'] ?? 150))]);
    }

    if ($action === 'test_status') {
        $name = trim((string)($_GET['container'] ?? $body['container'] ?? ''));
        if ($name === '') {
            cg_json_response(['ok' => false, 'error' => 'Container is required.'], 400);
        }
        $job = cg_test_job_refresh_liveness($name);
        cg_json_response(['ok' => true, 'job' => $job === [] ? null : cg_test_job_public($job)]);
    }

    if ($method === 'GET') {
        cg_json_response(['ok' => false, 'error' => 'Unsupported GET action.'], 400);
    }

    if ($action === 'save') {
        if (!isset($body['config']) || !is_array($body['config'])) {
            cg_json_response(['ok' => false, 'error' => 'Configuration payload is required.'], 400);
        }
        $input = $body['config'];
        $previous = cg_config_load();
        $saved = cg_config_save($input);
        $runtime = cg_api_reconcile_runtime($previous, $saved);

        $run = cg_run(['/etc/rc.d/rc.vfe-docker-container-monitor', 'start'], 15);
        $savedAt = (int)($saved['updated_at'] ?? cg_now());
        cg_json_response([
            'ok' => true,
            'operation' => 'save',
            'message' => 'Configuration saved persistently and verified.',
            'config' => $saved,
            'runtime' => $runtime,
            'saved_at' => $savedAt,
            'config_path' => CG_CONFIG_FILE,
            'config_sha256' => is_file(CG_CONFIG_FILE) ? (hash_file('sha256', CG_CONFIG_FILE) ?: '') : '',
            'client_revision' => (int)($body['client_revision'] ?? 0),
            'daemon_message' => $run['output'],
        ]);
    }

    if ($action === 'save_container') {
        $name = trim((string)($body['container'] ?? ''));
        if ($name === '') {
            cg_json_response(['ok' => false, 'error' => 'Container is required.'], 400);
        }
        if (!isset($body['container_config']) || !is_array($body['container_config'])) {
            cg_json_response(['ok' => false, 'error' => 'Container configuration payload is required.'], 400);
        }
        $previous = cg_config_load();
        $input = $previous;
        if (!isset($input['containers']) || !is_array($input['containers'])) {
            $input['containers'] = [];
        }
        $input['containers'][$name] = cg_normalize_container_config($body['container_config']);
        $saved = cg_config_save($input);
        $runtime = cg_api_reconcile_runtime($previous, $saved, [$name]);
        $run = cg_run(['/etc/rc.d/rc.vfe-docker-container-monitor', 'start'], 15);
        $savedAt = (int)($saved['updated_at'] ?? cg_now());
        cg_json_response([
            'ok' => true,
            'operation' => 'save_container',
            'message' => $name . ': settings saved persistently and verified.',
            'container' => $name,
            'container_config' => $saved['containers'][$name] ?? cg_default_container_config(),
            'config' => $saved,
            'runtime' => $runtime,
            'saved_at' => $savedAt,
            'config_path' => CG_CONFIG_FILE,
            'config_sha256' => is_file(CG_CONFIG_FILE) ? (hash_file('sha256', CG_CONFIG_FILE) ?: '') : '',
            'client_revision' => (int)($body['client_revision'] ?? 0),
            'daemon_message' => $run['output'],
        ]);
    }

    if ($action === 'daemon') {
        $daemonAction = (string)($body['daemon_action'] ?? 'status');
        if (!in_array($daemonAction, ['start', 'stop', 'restart'], true)) {
            cg_json_response(['ok' => false, 'error' => 'Unsupported daemon action.'], 400);
        }
        $run = cg_run(['/etc/rc.d/rc.vfe-docker-container-monitor', $daemonAction], 30);
        cg_json_response([
            'ok' => $run['ok'],
            'message' => $run['output'] ?: ('Daemon ' . $daemonAction . ' completed.'),
            'daemon' => cg_daemon_status(),
        ], $run['ok'] ? 200 : 500);
    }

    $containers = cg_list_containers();
    $name = trim((string)($body['container'] ?? ''));
    if ($name === '' || !isset($containers[$name])) {
        cg_json_response(['ok' => false, 'error' => 'Container was not found.'], 404);
    }

    if (in_array($action, ['test_start', 'run_now_start', 'test'], true)) {
        $mode = $action === 'run_now_start' ? 'run_now' : 'manual';
        $draft = isset($body['container_config']) && is_array($body['container_config'])
            ? cg_normalize_container_config($body['container_config'])
            : (cg_config_load()['containers'][$name] ?? cg_default_container_config());
        $savedConfig = cg_config_load()['containers'][$name] ?? cg_default_container_config();
        $savedConfig = cg_normalize_container_config($savedConfig);
        $usesUnsaved = json_encode($draft, JSON_UNESCAPED_SLASHES) !== json_encode($savedConfig, JSON_UNESCAPED_SLASHES);

        $existing = cg_test_job_refresh_liveness($name);
        if ($existing !== [] && in_array((string)($existing['status'] ?? ''), ['queued', 'running', 'cancelling'], true)) {
            cg_json_response([
                'ok' => false,
                'error' => 'A check is already running for this container.',
                'job' => cg_test_job_public($existing),
            ], 409);
        }
        $probeLock = cg_container_check_lock($name, true);
        if ($probeLock === false) {
            cg_json_response(['ok' => false, 'error' => 'A check or safe container action is already running for this container.'], 409);
        }
        cg_container_check_unlock($probeLock);

        $jobId = bin2hex(random_bytes(16));
        $plan = cg_check_plan($draft, $containers[$name]);
        $enabledCount = count(array_filter($plan, static fn(array $item): bool => !empty($item['enabled'])));
        $job = [
            'job_id' => $jobId,
            'container' => $name,
            'mode' => $mode,
            'status' => 'queued',
            'ok' => null,
            'message' => $mode === 'manual' ? 'Manual test queued' : 'Immediate automatic-style check queued',
            'created_at' => microtime(true),
            'started_at' => 0,
            'finished_at' => 0,
            'worker_pid' => 0,
            'current_child_pid' => 0,
            'current_check' => '',
            'completed_checks' => 0,
            'total_checks' => $enabledCount,
            'cancel_requested' => false,
            'uses_unsaved' => $usesUnsaved,
            'counter_effect' => $mode === 'manual' ? 'none' : 'updates automatic failure counter; never performs an action',
            'guardrails_bypassed' => [
                'check_interval', 'failures_before_action', 'startup_grace', 'restart_cooldown',
                'maximum_restarts', 'restart_window', 'quarantine', 'monitoring_enabled',
            ],
            'timeout_applies' => true,
            'container_config' => $draft,
            'checks' => $plan,
            'result_details' => [],
        ];
        if (!cg_test_job_save($name, $job)) {
            throw new RuntimeException('Unable to create the on-demand check job.');
        }
        $spawn = cg_spawn_test_worker($name, $jobId);
        $pid = (int)($spawn['pid'] ?? 0);
        $grouped = !empty($spawn['grouped']);
        if ($pid <= 1) {
            cg_test_job_update($name, static function (array $current): array {
                $current['status'] = 'failed';
                $current['message'] = 'Unable to start the on-demand check worker';
                $current['finished_at'] = microtime(true);
                return $current;
            });
            throw new RuntimeException('Unable to start the on-demand check worker.');
        }
        $job = cg_test_job_update($name, static function (array $current) use ($pid, $grouped): array {
            $current['worker_pid'] = $pid;
            $current['worker_grouped'] = $grouped;
            return $current;
        });
        cg_json_response([
            'ok' => true,
            'message' => $mode === 'manual'
                ? 'Manual test started. Scheduling and restart guardrails are bypassed; timeout still applies.'
                : 'Immediate automatic-style check started. It may update the failure counter but cannot perform an action.',
            'job' => cg_test_job_public($job),
        ], 202);
    }

    if ($action === 'test_cancel') {
        $job = cg_test_job_refresh_liveness($name);
        if ($job === [] || !in_array((string)($job['status'] ?? ''), ['queued', 'running', 'cancelling'], true)) {
            cg_json_response(['ok' => false, 'error' => 'There is no active test to cancel.'], 409);
        }
        $jobId = (string)($job['job_id'] ?? '');
        $pid = (int)($job['worker_pid'] ?? 0);
        $grouped = !empty($job['worker_grouped']);
        $job = cg_test_job_update($name, static function (array $current): array {
            $current['cancel_requested'] = true;
            $current['status'] = 'cancelling';
            $current['message'] = 'Cancellation requested';
            return $current;
        });
        if (cg_pid_is_test_worker($pid, $jobId)) {
            if (function_exists('posix_kill')) {
                if ($grouped) {
                    @posix_kill(-$pid, 15);
                }
                @posix_kill($pid, 15);
            } else {
                if ($grouped) {
                    cg_run(['kill', '-TERM', '--', '-' . $pid], 3);
                }
                cg_run(['kill', '-TERM', (string)$pid], 3);
            }
        }
        cg_json_response(['ok' => true, 'message' => 'Cancellation requested.', 'job' => cg_test_job_public($job)]);
    }

    if ($action === 'simulate') {
        $draft = isset($body['container_config']) && is_array($body['container_config'])
            ? cg_normalize_container_config($body['container_config'])
            : (cg_config_load()['containers'][$name] ?? cg_default_container_config());
        $runtime = cg_runtime_load();
        $current = array_replace(cg_container_runtime_default(), $runtime['containers'][$name] ?? []);
        cg_json_response([
            'ok' => true,
            'simulation' => cg_simulate_failure_policy($name, $draft, $current, $containers[$name]),
        ]);
    }

    if ($action === 'command') {
        $command = (string)($body['command'] ?? '');
        $config = cg_config_load();
        $result = ['ok' => false, 'message' => 'Unsupported command.'];
        $commandLock = cg_container_check_lock($name, true);
        if ($commandLock === false) {
            cg_json_response(['ok' => false, 'error' => 'A check or safe action is already running for this container.'], 409);
        }
        try {
            if ($command === 'start') {
                $visiting = [];
                $started = [];
                $result = cg_start_tree($name, $config, $visiting, $started);
            } elseif ($command === 'stop') {
                $visiting = [];
                $stopped = [];
                $result = cg_stop_tree($name, $config, $visiting, $stopped);
            } elseif ($command === 'restart') {
                $result = cg_restart_tree($name, $config);
            } elseif ($command === 'unquarantine') {
                $current = cg_runtime_update_container($name, static function (array $runtimeRecord): array {
                    $runtimeRecord['quarantined_until'] = 0;
                    $runtimeRecord['consecutive_failures'] = 0;
                    $runtimeRecord['restart_timestamps'] = [];
                    $runtimeRecord['cooldown_until'] = 0;
                    $runtimeRecord['last_action'] = 'unquarantine';
                    $runtimeRecord['last_action_at'] = cg_now();
                    $runtimeRecord['last_message'] = 'Quarantine manually cleared';
                    return $runtimeRecord;
                });
                cg_log('INFO', 'Quarantine manually cleared', ['container' => $name]);
                $result = ['ok' => true, 'message' => 'Quarantine cleared.'];
            }
        } finally {
            cg_container_check_unlock($commandLock);
        }

        if (!$result['ok'] && $result['message'] === 'Unsupported command.') {
            cg_json_response(['ok' => false, 'error' => $result['message']], 400);
        }
        cg_json_response([
            'ok' => (bool)$result['ok'],
            'message' => (string)$result['message'],
        ], $result['ok'] ? 200 : 500);
    }

    cg_json_response(['ok' => false, 'error' => 'Unsupported action.'], 400);
} catch (Throwable $error) {
    cg_log('ERROR', 'API error', ['action' => $action, 'error' => $error->getMessage()]);
    cg_json_response(['ok' => false, 'error' => $error->getMessage()], 500);
}
