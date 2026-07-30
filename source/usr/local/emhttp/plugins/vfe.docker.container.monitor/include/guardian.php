<?php

declare(strict_types=1);

const CG_PLUGIN = 'vfe.docker.container.monitor';
const CG_CONFIG_DIR = '/boot/config/plugins/vfe.docker.container.monitor';
const CG_CONFIG_FILE = CG_CONFIG_DIR . '/config.json';
const CG_RUNTIME_DIR = '/var/lib/vfe-docker-container-monitor';
const CG_RUNTIME_FILE = CG_RUNTIME_DIR . '/runtime.json';
const CG_RUNTIME_LOCK_FILE = '/var/run/vfe-docker-container-monitor-runtime.lock';
const CG_TEST_JOB_DIR = CG_RUNTIME_DIR . '/jobs';
const CG_CHECK_LOCK_DIR = '/var/run/vfe-docker-container-monitor-checks';
const CG_TEST_WORKER = '/usr/local/emhttp/plugins/vfe.docker.container.monitor/scripts/guardian-test-worker.php';
const CG_PID_FILE = '/var/run/vfe-docker-container-monitor.pid';
const CG_LOCK_FILE = '/var/run/vfe-docker-container-monitor.lock';
const CG_LOG_FILE = '/var/log/vfe-docker-container-monitor.log';

function cg_now(): int
{
    return time();
}

function cg_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function cg_run(array $argv, int $timeout = 15): array
{
    if ($argv === []) {
        return ['ok' => false, 'code' => 127, 'output' => 'Empty command'];
    }

    $timeout = max(1, min(300, $timeout));
    $parts = array_map(static fn($arg): string => escapeshellarg((string)$arg), $argv);
    $cmd = 'timeout --signal=TERM --kill-after=2s ' . $timeout . 's ' . implode(' ', $parts) . ' 2>&1';
    $lines = [];
    $code = 0;
    exec($cmd, $lines, $code);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'output' => trim(implode("\n", $lines)),
    ];
}

function cg_atomic_write_json(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    $payload = $json . "\n";
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $handle = @fopen($tmp, 'wb');
    if ($handle === false) {
        return false;
    }

    $written = 0;
    $length = strlen($payload);
    $ok = true;
    while ($written < $length) {
        $chunk = @fwrite($handle, substr($payload, $written));
        if ($chunk === false || $chunk === 0) {
            $ok = false;
            break;
        }
        $written += $chunk;
    }
    if ($ok) {
        $ok = @fflush($handle);
        if ($ok && function_exists('fsync')) {
            @fsync($handle); // Best effort: some flash filesystems may not expose fsync.
        }
    }
    @fclose($handle);

    if (!$ok || $written !== $length) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    clearstatcache(true, $path);

    // Do not report success until the bytes can be read back from the persistent
    // flash path. This catches mount/write failures instead of leaving the UI
    // claiming that an in-memory draft was saved.
    $readBack = @file_get_contents($path);
    return is_string($readBack) && hash_equals(hash('sha256', $payload), hash('sha256', $readBack));
}

function cg_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function cg_log(string $level, string $message, array $context = []): void
{
    $level = strtoupper($level);
    $line = sprintf('[%s] %-5s %s', date('Y-m-d H:i:s'), $level, $message);
    if ($context !== []) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            $line .= ' ' . $encoded;
        }
    }
    $line .= "\n";

    if (is_file(CG_LOG_FILE) && filesize(CG_LOG_FILE) > 2 * 1024 * 1024) {
        @rename(CG_LOG_FILE, CG_LOG_FILE . '.1');
    }
    @file_put_contents(CG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function cg_tail_log(int $lines = 150): array
{
    $lines = max(10, min(1000, $lines));
    if (!is_file(CG_LOG_FILE)) {
        return [];
    }
    $result = cg_run(['tail', '-n', (string)$lines, CG_LOG_FILE], 5);
    if (!$result['ok'] && $result['output'] === '') {
        return [];
    }
    return preg_split('/\R/', trim($result['output'])) ?: [];
}

function cg_default_container_config(): array
{
    return [
        'enabled' => false,
        'restart_enabled' => true,
        'check_mode' => 'all',
        'check_interval' => 30,
        'timeout' => 5,
        'failures_before_action' => 3,
        'startup_grace' => 90,
        'restart_cooldown' => 300,
        'maximum_restarts' => 3,
        'restart_window' => 3600,
        'quarantine_duration' => 3600,
        'dependencies' => [],
        'use_auto_dependency' => true,
        'checks' => [
            'docker_state' => ['enabled' => true],
            'docker_health' => ['enabled' => false],
            'ping' => ['enabled' => false, 'target_mode' => 'auto', 'host' => ''],
            'tcp' => ['enabled' => false, 'target_mode' => 'auto', 'host' => '', 'port' => 0],
            'http' => ['enabled' => false, 'target_mode' => 'auto', 'url' => '', 'expected_codes' => '200-399'],
            'https' => ['enabled' => false, 'target_mode' => 'auto', 'url' => '', 'expected_codes' => '200-399', 'verify_tls' => false],
        ],
    ];
}

function cg_default_config(): array
{
    return [
        'version' => 4,
        'updated_at' => 0,
        'global_enabled' => true,
        'ui_refresh_seconds' => 5,
        'containers' => [],
    ];
}

function cg_config_load(): array
{
    $loaded = cg_read_json(CG_CONFIG_FILE, []);
    $config = array_replace_recursive(cg_default_config(), $loaded);
    if (!isset($config['containers']) || !is_array($config['containers'])) {
        $config['containers'] = [];
    }
    foreach ($config['containers'] as $name => $containerConfig) {
        if (!is_array($containerConfig)) {
            $containerConfig = [];
        }
        $config['containers'][$name] = array_replace_recursive(cg_default_container_config(), $containerConfig);
    }
    return $config;
}

function cg_int(mixed $value, int $default, int $min, int $max): int
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function cg_bool(mixed $value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    if (is_string($value)) {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
    return $default;
}

function cg_normalize_container_config(array $input): array
{
    $defaults = cg_default_container_config();
    $checksInput = isset($input['checks']) && is_array($input['checks']) ? $input['checks'] : [];
    $dependencies = isset($input['dependencies']) && is_array($input['dependencies']) ? $input['dependencies'] : [];
    $dependencies = array_values(array_unique(array_filter(array_map(
        static fn($v): string => trim((string)$v),
        $dependencies
    ), static fn(string $v): bool => $v !== '')));

    $expected = static function (mixed $value): string {
        $value = trim((string)$value);
        return preg_match('/^\d{3}(?:-\d{3})?(?:,\d{3}(?:-\d{3})?)*$/', $value) ? $value : '200-399';
    };

    $targetMode = static function (mixed $value): string {
        return strtolower(trim((string)$value)) === 'manual' ? 'manual' : 'auto';
    };

    return [
        'enabled' => cg_bool($input['enabled'] ?? $defaults['enabled']),
        'restart_enabled' => cg_bool($input['restart_enabled'] ?? $defaults['restart_enabled'], true),
        'check_mode' => (($input['check_mode'] ?? 'all') === 'any') ? 'any' : 'all',
        'check_interval' => cg_int($input['check_interval'] ?? null, 30, 5, 86400),
        'timeout' => cg_int($input['timeout'] ?? null, 5, 1, 120),
        'failures_before_action' => cg_int($input['failures_before_action'] ?? null, 3, 1, 100),
        'startup_grace' => cg_int($input['startup_grace'] ?? null, (int)$defaults['startup_grace'], 0, 86400),
        'restart_cooldown' => cg_int($input['restart_cooldown'] ?? null, (int)$defaults['restart_cooldown'], 0, 86400),
        'maximum_restarts' => cg_int($input['maximum_restarts'] ?? null, (int)$defaults['maximum_restarts'], 0, 1000),
        'restart_window' => cg_int($input['restart_window'] ?? null, 3600, 60, 604800),
        'quarantine_duration' => cg_int($input['quarantine_duration'] ?? null, 3600, 0, 2592000),
        'dependencies' => $dependencies,
        'use_auto_dependency' => cg_bool($input['use_auto_dependency'] ?? true, true),
        'checks' => [
            'docker_state' => [
                'enabled' => cg_bool($checksInput['docker_state']['enabled'] ?? true, true),
            ],
            'docker_health' => [
                'enabled' => cg_bool($checksInput['docker_health']['enabled'] ?? false),
            ],
            'ping' => [
                'enabled' => cg_bool($checksInput['ping']['enabled'] ?? false),
                'target_mode' => $targetMode($checksInput['ping']['target_mode'] ?? 'auto'),
                'host' => trim((string)($checksInput['ping']['host'] ?? '')),
            ],
            'tcp' => [
                'enabled' => cg_bool($checksInput['tcp']['enabled'] ?? false),
                'target_mode' => $targetMode($checksInput['tcp']['target_mode'] ?? 'auto'),
                'host' => trim((string)($checksInput['tcp']['host'] ?? '')),
                'port' => cg_int($checksInput['tcp']['port'] ?? null, 0, 0, 65535),
            ],
            'http' => [
                'enabled' => cg_bool($checksInput['http']['enabled'] ?? false),
                'target_mode' => $targetMode($checksInput['http']['target_mode'] ?? 'auto'),
                'url' => trim((string)($checksInput['http']['url'] ?? '')),
                'expected_codes' => $expected($checksInput['http']['expected_codes'] ?? '200-399'),
            ],
            'https' => [
                'enabled' => cg_bool($checksInput['https']['enabled'] ?? false),
                'target_mode' => $targetMode($checksInput['https']['target_mode'] ?? 'auto'),
                'url' => trim((string)($checksInput['https']['url'] ?? '')),
                'expected_codes' => $expected($checksInput['https']['expected_codes'] ?? '200-399'),
                'verify_tls' => cg_bool($checksInput['https']['verify_tls'] ?? false),
            ],
        ],
    ];
}

function cg_config_save(array $input): array
{
    $output = cg_default_config();
    $output['version'] = 4;
    $output['updated_at'] = cg_now();
    $output['global_enabled'] = cg_bool($input['global_enabled'] ?? true, true);
    $output['ui_refresh_seconds'] = cg_int($input['ui_refresh_seconds'] ?? null, 5, 2, 60);
    $containers = isset($input['containers']) && is_array($input['containers']) ? $input['containers'] : [];
    foreach ($containers as $name => $containerConfig) {
        $name = trim((string)$name);
        if ($name === '' || !is_array($containerConfig)) {
            continue;
        }
        $output['containers'][$name] = cg_normalize_container_config($containerConfig);
    }

    if (!is_dir(CG_CONFIG_DIR) && !mkdir(CG_CONFIG_DIR, 0755, true) && !is_dir(CG_CONFIG_DIR)) {
        throw new RuntimeException('Cannot create persistent configuration directory.');
    }
    if (!cg_atomic_write_json(CG_CONFIG_FILE, $output)) {
        throw new RuntimeException('Cannot save configuration to ' . CG_CONFIG_FILE . '. Check that the flash drive is writable.');
    }

    $readBack = cg_read_json(CG_CONFIG_FILE, []);
    if ($readBack === [] || (int)($readBack['updated_at'] ?? 0) !== (int)$output['updated_at']) {
        throw new RuntimeException('Configuration write verification failed for ' . CG_CONFIG_FILE . '.');
    }
    cg_log('INFO', 'Configuration saved and verified', [
        'containers' => count($output['containers']),
        'updated_at' => $output['updated_at'],
        'sha256' => hash_file('sha256', CG_CONFIG_FILE) ?: '',
    ]);
    return $readBack;
}

function cg_runtime_load(): array
{
    $runtime = cg_read_json(CG_RUNTIME_FILE, ['containers' => [], 'updated_at' => 0]);
    if (!isset($runtime['containers']) || !is_array($runtime['containers'])) {
        $runtime['containers'] = [];
    }
    return $runtime;
}

function cg_runtime_record_stamp(array $record): float
{
    return max(
        (float)($record['_updated_at'] ?? 0),
        (float)($record['last_check'] ?? 0),
        (float)($record['last_action_at'] ?? 0),
        (float)($record['last_test_at'] ?? 0)
    );
}

function cg_runtime_save(array $runtime): bool
{
    $lock = @fopen(CG_RUNTIME_LOCK_FILE, 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        cg_log('ERROR', 'Unable to lock runtime state', ['path' => CG_RUNTIME_LOCK_FILE]);
        return false;
    }

    try {
        $disk = cg_read_json(CG_RUNTIME_FILE, ['containers' => [], 'updated_at' => 0]);
        $diskContainers = is_array($disk['containers'] ?? null) ? $disk['containers'] : [];
        $incomingContainers = is_array($runtime['containers'] ?? null) ? $runtime['containers'] : [];

        // Preserve a newer per-container record written by an on-demand worker.
        // The daemon keeps a local snapshot during each loop, so a whole-file
        // write must not overwrite a newer immediate/manual result.
        foreach ($diskContainers as $name => $diskRecord) {
            if (!isset($incomingContainers[$name])) {
                $incomingContainers[$name] = $diskRecord;
                continue;
            }
            if (is_array($diskRecord) && is_array($incomingContainers[$name])
                && cg_runtime_record_stamp($diskRecord) > cg_runtime_record_stamp($incomingContainers[$name])) {
                $incomingContainers[$name] = $diskRecord;
            }
        }
        $runtime['containers'] = $incomingContainers;
        $runtime['updated_at'] = cg_now();
        $ok = cg_atomic_write_json(CG_RUNTIME_FILE, $runtime);
        if (!$ok) {
            cg_log('ERROR', 'Unable to save runtime state', ['path' => CG_RUNTIME_FILE]);
        }
        return $ok;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function cg_runtime_update_container(string $name, callable $mutator): array
{
    $lock = @fopen(CG_RUNTIME_LOCK_FILE, 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock runtime state.');
    }
    try {
        $runtime = cg_read_json(CG_RUNTIME_FILE, ['containers' => [], 'updated_at' => 0]);
        if (!isset($runtime['containers']) || !is_array($runtime['containers'])) {
            $runtime['containers'] = [];
        }
        $current = array_replace(cg_container_runtime_default(), $runtime['containers'][$name] ?? []);
        $next = $mutator($current, $runtime);
        if (!is_array($next)) {
            $next = $current;
        }
        $next['_updated_at'] = microtime(true);
        $runtime['containers'][$name] = $next;
        $runtime['updated_at'] = cg_now();
        if (!cg_atomic_write_json(CG_RUNTIME_FILE, $runtime)) {
            throw new RuntimeException('Unable to save runtime state.');
        }
        return $next;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function cg_container_lock_path(string $name): string
{
    return CG_CHECK_LOCK_DIR . '/' . hash('sha256', $name) . '.lock';
}

/** @return resource|false */
function cg_container_check_lock(string $name, bool $nonBlocking = true)
{
    if (!is_dir(CG_CHECK_LOCK_DIR) && !mkdir(CG_CHECK_LOCK_DIR, 0755, true) && !is_dir(CG_CHECK_LOCK_DIR)) {
        return false;
    }
    $handle = @fopen(cg_container_lock_path($name), 'c+');
    if ($handle === false) {
        return false;
    }
    $mode = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
    if (!@flock($handle, $mode)) {
        @fclose($handle);
        return false;
    }
    @ftruncate($handle, 0);
    @fwrite($handle, (string)getmypid());
    @fflush($handle);
    return $handle;
}

function cg_container_check_unlock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function cg_test_job_path(string $container): string
{
    return CG_TEST_JOB_DIR . '/' . hash('sha256', $container) . '.json';
}

function cg_test_job_lock_path(string $container): string
{
    return CG_TEST_JOB_DIR . '/' . hash('sha256', $container) . '.lock';
}

function cg_test_job_load(string $container): array
{
    return cg_read_json(cg_test_job_path($container), []);
}

function cg_test_job_save(string $container, array $job): bool
{
    if (!is_dir(CG_TEST_JOB_DIR) && !mkdir(CG_TEST_JOB_DIR, 0755, true) && !is_dir(CG_TEST_JOB_DIR)) {
        return false;
    }
    $job['updated_at'] = microtime(true);
    return cg_atomic_write_json(cg_test_job_path($container), $job);
}

function cg_test_job_update(string $container, callable $mutator): array
{
    if (!is_dir(CG_TEST_JOB_DIR) && !mkdir(CG_TEST_JOB_DIR, 0755, true) && !is_dir(CG_TEST_JOB_DIR)) {
        throw new RuntimeException('Unable to create the on-demand test directory.');
    }
    $lock = @fopen(cg_test_job_lock_path($container), 'c+');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock the on-demand test state.');
    }
    try {
        $job = cg_test_job_load($container);
        $next = $mutator($job);
        if (!is_array($next)) {
            $next = $job;
        }
        if (!cg_test_job_save($container, $next)) {
            throw new RuntimeException('Unable to save on-demand test state.');
        }
        return $next;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function cg_test_job_public(array $job): array
{
    unset($job['container_config']);
    return $job;
}

function cg_pid_is_test_worker(int $pid, string $jobId = ''): bool
{
    if ($pid <= 1 || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($cmdline) || $cmdline === '') {
        return false;
    }
    $text = str_replace("\0", ' ', $cmdline);
    return str_contains($text, 'guardian-test-worker.php') && ($jobId === '' || str_contains($text, $jobId));
}

function cg_test_job_refresh_liveness(string $container): array
{
    $job = cg_test_job_load($container);
    if ($job === []) {
        return [];
    }
    $status = (string)($job['status'] ?? '');
    if (in_array($status, ['queued', 'running', 'cancelling'], true)) {
        $pid = (int)($job['worker_pid'] ?? 0);
        $created = (float)($job['created_at'] ?? 0);
        $alive = cg_pid_is_test_worker($pid, (string)($job['job_id'] ?? ''));
        if (!$alive && microtime(true) - $created > 2.0) {
            $job = cg_test_job_update($container, static function (array $current): array {
                if (!in_array((string)($current['status'] ?? ''), ['queued', 'running', 'cancelling'], true)) {
                    return $current;
                }
                $cancelled = !empty($current['cancel_requested']) || ($current['status'] ?? '') === 'cancelling';
                $activeCheck = (string)($current['current_check'] ?? '');
                $current['status'] = $cancelled ? 'cancelled' : 'failed';
                $current['finished_at'] = microtime(true);
                $current['current_check'] = '';
                $current['message'] = $cancelled ? 'Test cancelled' : 'Test worker exited unexpectedly';
                if ($activeCheck !== '' && isset($current['checks'][$activeCheck]) && is_array($current['checks'][$activeCheck])) {
                    $current['checks'][$activeCheck]['status'] = $cancelled ? 'cancelled' : 'failed';
                    $current['checks'][$activeCheck]['ok'] = false;
                    $current['checks'][$activeCheck]['message'] = $current['message'];
                    $current['checks'][$activeCheck]['failure_reason'] = $current['message'];
                    $current['checks'][$activeCheck]['tested_at'] = microtime(true);
                }
                return $current;
            });
        }
    }
    return $job;
}

function cg_spawn_test_worker(string $container, string $jobId): array
{
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : '');
    $grouped = $setsid !== '';
    $prefix = $grouped ? escapeshellarg($setsid) . ' ' : '';
    $command = $prefix . escapeshellarg($php) . ' ' . escapeshellarg(CG_TEST_WORKER) . ' '
        . escapeshellarg($container) . ' ' . escapeshellarg($jobId)
        . ' >/dev/null 2>&1 & echo $!';
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    $pid = (int)trim((string)($lines[0] ?? '0'));
    if ($code !== 0 || $pid <= 1) {
        return ['pid' => 0, 'grouped' => false];
    }
    return ['pid' => $pid, 'grouped' => $grouped];
}

function cg_container_runtime_default(): array
{
    return [
        'last_check' => 0,
        'last_result' => null,
        'last_message' => 'Not checked yet',
        'last_details' => [],
        'last_check_source' => '',
        'consecutive_failures' => 0,
        'restart_timestamps' => [],
        'cooldown_until' => 0,
        'quarantined_until' => 0,
        'last_action' => '',
        'last_action_at' => 0,
        'last_test_at' => 0,
        'last_test_result' => null,
        'last_test_message' => '',
        'last_test_details' => [],
        '_updated_at' => 0.0,
    ];
}

function cg_list_containers(): array
{
    $idsResult = cg_run(['docker', 'ps', '-aq', '--no-trunc'], 15);
    if (!$idsResult['ok'] && trim($idsResult['output']) === '') {
        return [];
    }
    $ids = array_values(array_filter(preg_split('/\R/', trim($idsResult['output'])) ?: []));
    if ($ids === []) {
        return [];
    }

    $inspect = cg_run(array_merge(['docker', 'inspect'], $ids), 30);
    if (!$inspect['ok'] && trim($inspect['output']) === '') {
        cg_log('ERROR', 'docker inspect failed', ['code' => $inspect['code']]);
        return [];
    }
    $items = json_decode($inspect['output'], true);
    if (!is_array($items)) {
        cg_log('ERROR', 'Unable to decode docker inspect output');
        return [];
    }

    $containers = [];
    $idToName = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = ltrim((string)($item['Name'] ?? ''), '/');
        $id = (string)($item['Id'] ?? '');
        if ($name === '' || $id === '') {
            continue;
        }
        $idToName[$id] = $name;
        $idToName[substr($id, 0, 12)] = $name;
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = ltrim((string)($item['Name'] ?? ''), '/');
        $id = (string)($item['Id'] ?? '');
        if ($name === '' || $id === '') {
            continue;
        }
        $state = is_array($item['State'] ?? null) ? $item['State'] : [];
        $hostConfig = is_array($item['HostConfig'] ?? null) ? $item['HostConfig'] : [];
        $networkSettings = is_array($item['NetworkSettings'] ?? null) ? $item['NetworkSettings'] : [];
        $config = is_array($item['Config'] ?? null) ? $item['Config'] : [];
        $networkMode = (string)($hostConfig['NetworkMode'] ?? '');
        $autoDependency = '';
        if (str_starts_with($networkMode, 'container:')) {
            $ref = substr($networkMode, strlen('container:'));
            $autoDependency = $idToName[$ref] ?? '';
            if ($autoDependency === '') {
                foreach ($idToName as $candidateId => $candidateName) {
                    if (str_starts_with($candidateId, $ref)) {
                        $autoDependency = $candidateName;
                        break;
                    }
                }
            }
        }

        $ips = [];
        $networks = is_array($networkSettings['Networks'] ?? null) ? $networkSettings['Networks'] : [];
        foreach ($networks as $networkName => $network) {
            if (!is_array($network)) {
                continue;
            }
            $ip = trim((string)($network['IPAddress'] ?? ''));
            if ($ip !== '') {
                $ips[] = ['network' => (string)$networkName, 'ip' => $ip];
            }
        }

        $ports = [];
        $portBindings = is_array($networkSettings['Ports'] ?? null) ? $networkSettings['Ports'] : [];
        foreach ($portBindings as $containerPort => $bindings) {
            $protoParts = explode('/', (string)$containerPort, 2);
            $internalPort = (int)($protoParts[0] ?? 0);
            $protocol = $protoParts[1] ?? 'tcp';
            if (is_array($bindings) && $bindings !== []) {
                foreach ($bindings as $binding) {
                    if (!is_array($binding)) {
                        continue;
                    }
                    $hostIp = (string)($binding['HostIp'] ?? '');
                    $hostPort = (int)($binding['HostPort'] ?? 0);
                    if ($hostPort > 0) {
                        $ports[] = [
                            'container_port' => $internalPort,
                            'host_port' => $hostPort,
                            'host_ip' => $hostIp,
                            'protocol' => $protocol,
                            'published' => true,
                        ];
                    }
                }
            } elseif ($internalPort > 0) {
                $ports[] = [
                    'container_port' => $internalPort,
                    'host_port' => 0,
                    'host_ip' => '',
                    'protocol' => $protocol,
                    'published' => false,
                ];
            }
        }

        $health = is_array($state['Health'] ?? null) ? (string)($state['Health']['Status'] ?? 'none') : 'none';
        $containers[$name] = [
            'id' => $id,
            'short_id' => substr($id, 0, 12),
            'name' => $name,
            'image' => (string)($config['Image'] ?? ''),
            'running' => (bool)($state['Running'] ?? false),
            'status' => (string)($state['Status'] ?? 'unknown'),
            'health' => $health,
            'started_at' => (string)($state['StartedAt'] ?? ''),
            'finished_at' => (string)($state['FinishedAt'] ?? ''),
            'restart_count' => (int)($item['RestartCount'] ?? 0),
            'network_mode' => $networkMode,
            'auto_dependency' => $autoDependency,
            'ips' => $ips,
            'ports' => $ports,
            'labels' => is_array($config['Labels'] ?? null) ? $config['Labels'] : [],
        ];
    }

    ksort($containers, SORT_NATURAL | SORT_FLAG_CASE);
    return $containers;
}

function cg_resolve_auto_host(array $container): string
{
    if (!empty($container['ips'][0]['ip'])) {
        return (string)$container['ips'][0]['ip'];
    }
    foreach ($container['ports'] ?? [] as $port) {
        if (!empty($port['published'])) {
            return '127.0.0.1';
        }
    }
    return '';
}

function cg_resolve_auto_tcp_target(array $container): array
{
    foreach ($container['ports'] ?? [] as $port) {
        if (($port['protocol'] ?? 'tcp') !== 'tcp') {
            continue;
        }
        $hostPort = (int)($port['host_port'] ?? 0);
        if (!empty($port['published']) && $hostPort > 0) {
            return ['host' => '127.0.0.1', 'port' => $hostPort];
        }
        $containerPort = (int)($port['container_port'] ?? 0);
        $ip = (string)($container['ips'][0]['ip'] ?? '');
        if ($containerPort > 0 && $ip !== '') {
            return ['host' => $ip, 'port' => $containerPort];
        }
    }
    return ['host' => cg_resolve_auto_host($container), 'port' => 0];
}

function cg_resolve_auto_web_target(string $scheme, array $container): array
{
    $preferred = $scheme === 'https'
        ? [443, 8443, 9443, 10443]
        : [80, 8080, 8000, 8888, 3000, 5000, 8008];
    $candidates = [];
    foreach ($container['ports'] ?? [] as $port) {
        if (($port['protocol'] ?? 'tcp') !== 'tcp') {
            continue;
        }
        $containerPort = (int)($port['container_port'] ?? 0);
        $hostPort = (int)($port['host_port'] ?? 0);
        $published = !empty($port['published']) && $hostPort > 0;
        $host = $published ? '127.0.0.1' : (string)($container['ips'][0]['ip'] ?? '');
        $effectivePort = $published ? $hostPort : $containerPort;
        if ($host === '' || $effectivePort < 1) {
            continue;
        }
        $rankPort = $containerPort > 0 ? $containerPort : $effectivePort;
        $rank = array_search($rankPort, $preferred, true);
        if ($rank === false) {
            continue;
        }
        $candidates[] = [
            'host' => $host,
            'port' => $effectivePort,
            'rank' => (int)$rank,
        ];
    }
    if ($candidates !== []) {
        usort($candidates, static fn(array $a, array $b): int => $a['rank'] <=> $b['rank']);
        return ['host' => $candidates[0]['host'], 'port' => $candidates[0]['port']];
    }
    return ['host' => cg_resolve_auto_host($container), 'port' => 0];
}

function cg_resolve_auto_url(string $scheme, array $container): string
{
    $target = cg_resolve_auto_web_target($scheme, $container);
    $host = (string)($target['host'] ?? '');
    $port = (int)($target['port'] ?? 0);
    if ($host === '') {
        return '';
    }
    $defaultPort = $scheme === 'https' ? 443 : 80;
    if ($port < 1 || $port === $defaultPort) {
        return $scheme . '://' . $host . '/';
    }
    return $scheme . '://' . $host . ':' . $port . '/';
}

function cg_discovery_for(array $container): array
{
    $hosts = [];
    foreach ($container['ips'] ?? [] as $item) {
        $ip = (string)($item['ip'] ?? '');
        if ($ip !== '') {
            $hosts[] = ['label' => ($item['network'] ?? 'network') . ': ' . $ip, 'value' => $ip];
        }
    }
    $hosts[] = ['label' => 'Unraid host: 127.0.0.1', 'value' => '127.0.0.1'];

    $ports = [];
    foreach ($container['ports'] ?? [] as $port) {
        $hostPort = (int)($port['host_port'] ?? 0);
        $containerPort = (int)($port['container_port'] ?? 0);
        if ($hostPort > 0) {
            $ports[] = [
                'label' => sprintf('Host %d → container %d/%s', $hostPort, $containerPort, $port['protocol'] ?? 'tcp'),
                'host' => '127.0.0.1',
                'port' => $hostPort,
                'published' => true,
            ];
        }
        if ($containerPort > 0 && !empty($container['ips'][0]['ip'])) {
            $ports[] = [
                'label' => sprintf('%s:%d/%s', $container['ips'][0]['ip'], $containerPort, $port['protocol'] ?? 'tcp'),
                'host' => (string)$container['ips'][0]['ip'],
                'port' => $containerPort,
                'published' => false,
            ];
        }
    }

    return [
        'hosts' => array_values(array_unique($hosts, SORT_REGULAR)),
        'ports' => array_values(array_unique($ports, SORT_REGULAR)),
        'auto_dependency' => (string)($container['auto_dependency'] ?? ''),
        'network_mode' => (string)($container['network_mode'] ?? ''),
    ];
}

function cg_dependencies_for(string $name, array $config, array $containers): array
{
    $itemConfig = $config['containers'][$name] ?? cg_default_container_config();
    $dependencies = is_array($itemConfig['dependencies'] ?? null) ? $itemConfig['dependencies'] : [];
    if (($itemConfig['use_auto_dependency'] ?? true) && !empty($containers[$name]['auto_dependency'])) {
        $dependencies[] = (string)$containers[$name]['auto_dependency'];
    }
    $dependencies = array_values(array_unique(array_filter(array_map('strval', $dependencies), static fn(string $v): bool => $v !== '' && $v !== $name)));
    return $dependencies;
}

function cg_dependents_for(string $name, array $config, array $containers): array
{
    $dependents = [];
    foreach ($containers as $candidate => $_metadata) {
        if (in_array($name, cg_dependencies_for($candidate, $config, $containers), true)) {
            $dependents[] = $candidate;
        }
    }
    return $dependents;
}

function cg_http_code_matches(int $code, string $spec): bool
{
    foreach (explode(',', $spec) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (str_contains($part, '-')) {
            [$min, $max] = array_map('intval', explode('-', $part, 2));
            if ($code >= $min && $code <= $max) {
                return true;
            }
        } elseif ($code === (int)$part) {
            return true;
        }
    }
    return false;
}

function cg_check_target(string $checkName, array $itemConfig, array $container): string
{
    $checks = is_array($itemConfig['checks'] ?? null) ? $itemConfig['checks'] : [];
    if ($checkName === 'docker_state') {
        return 'Docker container ' . (string)($container['name'] ?? '');
    }
    if ($checkName === 'docker_health') {
        return 'Docker HEALTHCHECK';
    }
    if ($checkName === 'ping') {
        $mode = (string)($checks['ping']['target_mode'] ?? 'auto');
        return $mode === 'manual'
            ? trim((string)($checks['ping']['host'] ?? ''))
            : cg_resolve_auto_host($container);
    }
    if ($checkName === 'tcp') {
        $mode = (string)($checks['tcp']['target_mode'] ?? 'auto');
        if ($mode === 'manual') {
            $host = trim((string)($checks['tcp']['host'] ?? ''));
            $port = (int)($checks['tcp']['port'] ?? 0);
        } else {
            $auto = cg_resolve_auto_tcp_target($container);
            $host = (string)($auto['host'] ?? '');
            $port = (int)($auto['port'] ?? 0);
        }
        return $host !== '' && $port > 0 ? $host . ':' . $port : trim($host . ($port > 0 ? ':' . $port : ''));
    }
    if ($checkName === 'http' || $checkName === 'https') {
        $mode = (string)($checks[$checkName]['target_mode'] ?? 'auto');
        return $mode === 'manual'
            ? trim((string)($checks[$checkName]['url'] ?? ''))
            : cg_resolve_auto_url($checkName, $container);
    }
    return '';
}

function cg_check_plan(array $itemConfig, array $container): array
{
    $checks = is_array($itemConfig['checks'] ?? null) ? $itemConfig['checks'] : [];
    $plan = [];
    foreach (['docker_state', 'docker_health', 'ping', 'tcp', 'http', 'https'] as $name) {
        if (empty($checks[$name]['enabled'])) {
            continue;
        }
        $plan[$name] = [
            'enabled' => true,
            'status' => 'waiting',
            'target' => cg_check_target($name, $itemConfig, $container),
            'ok' => null,
            'message' => 'Waiting',
            'failure_reason' => '',
            'duration_ms' => 0,
            'tested_at' => 0,
            'metadata' => [],
        ];
    }
    return $plan;
}

function cg_check_result(bool $ok, string $message, string $target, float $started, array $metadata = [], string $failureReason = ''): array
{
    return [
        'enabled' => true,
        'status' => $ok ? 'passed' : 'failed',
        'ok' => $ok,
        'message' => $message,
        'failure_reason' => $ok ? '' : ($failureReason !== '' ? $failureReason : $message),
        'target' => $target,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        'tested_at' => microtime(true),
        'metadata' => $metadata,
    ];
}

function cg_run_controlled(array $argv, int $timeout = 15, ?callable $cancelled = null, ?callable $pidCallback = null): array
{
    if ($argv === []) {
        return ['ok' => false, 'code' => 127, 'output' => 'Empty command', 'cancelled' => false, 'timed_out' => false];
    }
    $timeout = max(1, min(300, $timeout));
    $command = implode(' ', array_map(static fn($arg): string => escapeshellarg((string)$arg), $argv));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'code' => 127, 'output' => 'Unable to start command', 'cancelled' => false, 'timed_out' => false];
    }
    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $status = proc_get_status($process);
    $pid = (int)($status['pid'] ?? 0);
    if ($pidCallback !== null) {
        $pidCallback($pid);
    }
    $started = microtime(true);
    $output = '';
    $wasCancelled = false;
    $timedOut = false;
    $lastStatus = $status;

    while (true) {
        $output .= (string)stream_get_contents($pipes[1]);
        $output .= (string)stream_get_contents($pipes[2]);
        $lastStatus = proc_get_status($process);
        if (empty($lastStatus['running'])) {
            break;
        }
        if ($cancelled !== null && $cancelled()) {
            $wasCancelled = true;
            @proc_terminate($process, 15);
            usleep(150000);
            $after = proc_get_status($process);
            if (!empty($after['running'])) {
                @proc_terminate($process, 9);
            }
            break;
        }
        if (microtime(true) - $started >= $timeout) {
            $timedOut = true;
            @proc_terminate($process, 15);
            usleep(150000);
            $after = proc_get_status($process);
            if (!empty($after['running'])) {
                @proc_terminate($process, 9);
            }
            break;
        }
        usleep(100000);
    }

    $output .= (string)stream_get_contents($pipes[1]);
    $output .= (string)stream_get_contents($pipes[2]);
    // A group cancellation may terminate the child before this polling loop
    // observes the cancel flag. Re-check it before interpreting the exit code.
    if (!$wasCancelled && $cancelled !== null && $cancelled()) {
        $wasCancelled = true;
    }
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = (int)($lastStatus['exitcode'] ?? -1);
    $closed = @proc_close($process);
    if ($exitCode < 0 && is_int($closed)) {
        $exitCode = $closed;
    }
    if ($wasCancelled) {
        $exitCode = 130;
    } elseif ($timedOut) {
        $exitCode = 124;
    }
    return [
        'ok' => $exitCode === 0,
        'code' => $exitCode,
        'output' => trim($output),
        'cancelled' => $wasCancelled,
        'timed_out' => $timedOut,
        'pid' => $pid,
    ];
}

function cg_tcp_connect_controlled(string $host, int $port, int $timeout, ?callable $cancelled = null): array
{
    $addressHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    $address = 'tcp://' . $addressHost . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $address,
        $errno,
        $errstr,
        0,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
    );
    if (!is_resource($socket)) {
        return ['ok' => false, 'cancelled' => false, 'errno' => $errno, 'error' => $errstr ?: "TCP error $errno"];
    }
    stream_set_blocking($socket, false);
    $deadline = microtime(true) + max(1, $timeout);
    try {
        while (microtime(true) < $deadline) {
            if ($cancelled !== null && $cancelled()) {
                return ['ok' => false, 'cancelled' => true, 'errno' => 0, 'error' => 'Test cancelled'];
            }
            $read = [];
            $write = [$socket];
            $except = [$socket];
            $selected = @stream_select($read, $write, $except, 0, 100000);
            if ($selected === false) {
                return ['ok' => false, 'cancelled' => false, 'errno' => $errno, 'error' => 'Unable to wait for TCP connection'];
            }
            if ($selected === 0) {
                continue;
            }
            $socketError = 0;
            if (function_exists('socket_import_stream')) {
                $native = @socket_import_stream($socket);
                if ($native !== false) {
                    $socketError = (int)@socket_get_option($native, SOL_SOCKET, SO_ERROR);
                }
            }
            if ($socketError === 0 && @stream_socket_get_name($socket, true) !== false) {
                return ['ok' => true, 'cancelled' => false, 'errno' => 0, 'error' => ''];
            }
            $message = $socketError > 0 && function_exists('socket_strerror')
                ? socket_strerror($socketError)
                : ($errstr ?: 'Connection failed');
            return ['ok' => false, 'cancelled' => false, 'errno' => $socketError ?: $errno, 'error' => $message];
        }
        return ['ok' => false, 'cancelled' => false, 'errno' => 110, 'error' => "TCP connection timed out after {$timeout}s"];
    } finally {
        @fclose($socket);
    }
}

/**
 * Execute each enabled check exactly once. Scheduling, startup grace, failure
 * thresholds, cooldown, restart windows and quarantine are intentionally not
 * part of this function. Callers decide how a result affects policy.
 */
function cg_perform_checks(
    string $name,
    array $itemConfig,
    array $containers,
    ?callable $progress = null,
    ?callable $cancelled = null,
    ?callable $childPid = null
): array {
    $container = $containers[$name] ?? null;
    if (!is_array($container)) {
        return ['ok' => false, 'message' => 'Container not found', 'details' => [], 'cancelled' => false];
    }

    $timeout = cg_int($itemConfig['timeout'] ?? null, 5, 1, 120);
    $checks = is_array($itemConfig['checks'] ?? null) ? $itemConfig['checks'] : [];
    $results = [];
    $notify = static function (string $check, string $status, array $data = []) use ($progress): void {
        if ($progress !== null) {
            $progress($check, $status, $data);
        }
    };
    $isCancelled = static fn(): bool => $cancelled !== null && (bool)$cancelled();

    foreach (['docker_state', 'docker_health', 'ping', 'tcp', 'http', 'https'] as $checkName) {
        if (empty($checks[$checkName]['enabled'])) {
            continue;
        }
        if ($isCancelled()) {
            return ['ok' => false, 'message' => 'Test cancelled', 'details' => $results, 'cancelled' => true];
        }

        $target = cg_check_target($checkName, $itemConfig, $container);
        $notify($checkName, 'running', ['target' => $target, 'started_at' => microtime(true)]);
        $started = microtime(true);
        $result = null;

        if ($checkName === 'docker_state') {
            $ok = !empty($container['running']);
            $result = cg_check_result($ok, $ok ? 'Container is running' : 'Container is not running', $target, $started);
        } elseif ($checkName === 'docker_health') {
            $health = (string)($container['health'] ?? 'none');
            $ok = $health === 'healthy';
            $message = $health === 'none' ? 'No Docker HEALTHCHECK is configured' : 'Docker health is ' . $health;
            $result = cg_check_result($ok, $message, $target, $started, ['health' => $health]);
        } elseif ($checkName === 'ping') {
            $host = $target;
            if ($host === '') {
                $result = cg_check_result(false, 'No target host is configured', '', $started);
            } else {
                $run = cg_run_controlled(['ping', '-n', '-c', '1', '-W', (string)$timeout, $host], $timeout + 2, $cancelled, $childPid);
                if (!empty($run['cancelled'])) {
                    return ['ok' => false, 'message' => 'Test cancelled', 'details' => $results, 'cancelled' => true];
                }
                $message = $run['ok'] ? $host . ' replied' : ($run['timed_out'] ? "Ping timed out after {$timeout}s" : ($run['output'] ?: 'Ping failed'));
                $result = cg_check_result((bool)$run['ok'], $message, $host, $started, ['exit_code' => $run['code']]);
            }
        } elseif ($checkName === 'tcp') {
            $mode = (string)($checks['tcp']['target_mode'] ?? 'auto');
            if ($mode === 'manual') {
                $host = trim((string)($checks['tcp']['host'] ?? ''));
                $port = (int)($checks['tcp']['port'] ?? 0);
            } else {
                $auto = cg_resolve_auto_tcp_target($container);
                $host = (string)($auto['host'] ?? '');
                $port = (int)($auto['port'] ?? 0);
            }
            if ($host === '' || $port < 1) {
                $result = cg_check_result(false, $mode === 'manual' ? 'Manual TCP host or port is not configured' : 'No TCP target could be discovered automatically', $target, $started);
            } else {
                $tcp = cg_tcp_connect_controlled($host, $port, $timeout, $cancelled);
                if (!empty($tcp['cancelled'])) {
                    return ['ok' => false, 'message' => 'Test cancelled', 'details' => $results, 'cancelled' => true];
                }
                $ok = !empty($tcp['ok']);
                $message = $ok ? "$host:$port accepted a TCP connection" : (string)($tcp['error'] ?? 'TCP connection failed');
                $result = cg_check_result($ok, $message, "$host:$port", $started, ['error_number' => (int)($tcp['errno'] ?? 0)]);
            }
        } else {
            $scheme = $checkName;
            $url = $target;
            if (!preg_match('#^https?://#i', $url)) {
                $result = cg_check_result(false, 'A valid URL is required', $url, $started);
            } else {
                $marker = '__CG_HTTP_META__';
                $writeOut = $marker . '%{http_code}\t%{time_total}\t%{url_effective}\t%{remote_ip}\t%{ssl_verify_result}';
                $args = [
                    'curl', '--silent', '--show-error', '--location', '--output', '/dev/null',
                    '--write-out', $writeOut, '--connect-timeout', (string)$timeout,
                    '--max-time', (string)$timeout,
                ];
                if ($scheme === 'https' && empty($checks[$scheme]['verify_tls'])) {
                    $args[] = '--insecure';
                }
                $args[] = $url;
                $run = cg_run_controlled($args, $timeout + 3, $cancelled, $childPid);
                if (!empty($run['cancelled'])) {
                    return ['ok' => false, 'message' => 'Test cancelled', 'details' => $results, 'cancelled' => true];
                }
                $output = (string)$run['output'];
                $markerPos = strrpos($output, $marker);
                $metaRaw = $markerPos === false ? '' : substr($output, $markerPos + strlen($marker));
                $errorOutput = trim($markerPos === false ? $output : substr($output, 0, $markerPos));
                $parts = explode("\t", $metaRaw);
                $code = isset($parts[0]) && is_numeric($parts[0]) ? (int)$parts[0] : 0;
                $timeTotal = isset($parts[1]) && is_numeric($parts[1]) ? (float)$parts[1] : 0.0;
                $finalUrl = (string)($parts[2] ?? $url);
                $remoteIp = (string)($parts[3] ?? '');
                $sslVerify = isset($parts[4]) && is_numeric($parts[4]) ? (int)$parts[4] : -1;
                $expected = (string)($checks[$scheme]['expected_codes'] ?? '200-399');
                $codeMatches = cg_http_code_matches($code, $expected);
                $ok = (bool)$run['ok'] && $codeMatches;
                if (!$run['ok']) {
                    $message = !empty($run['timed_out']) ? "Request timed out after {$timeout}s" : ($errorOutput ?: 'Request failed');
                } elseif (!$codeMatches) {
                    $message = "HTTP $code is outside expected $expected";
                } else {
                    $message = "HTTP $code from $finalUrl";
                }
                $tls = $scheme !== 'https'
                    ? 'not applicable'
                    : (empty($checks[$scheme]['verify_tls']) ? 'verification disabled' : ($sslVerify === 0 ? 'verified' : 'verification failed'));
                $metadata = [
                    'status_code' => $code,
                    'expected_codes' => $expected,
                    'resolved_ip' => $remoteIp,
                    'final_url' => $finalUrl,
                    'connection_seconds' => $timeTotal,
                    'tls' => $tls,
                    'ssl_verify_result' => $sslVerify,
                    'exit_code' => $run['code'],
                ];
                $result = cg_check_result($ok, $message, $url, $started, $metadata, $errorOutput);
            }
        }

        $results[$checkName] = $result;
        $notify($checkName, 'complete', $result);
    }

    if ($results === []) {
        return ['ok' => false, 'message' => 'No checks are enabled', 'details' => [], 'cancelled' => false];
    }

    $mode = ($itemConfig['check_mode'] ?? 'all') === 'any' ? 'any' : 'all';
    $passed = array_filter($results, static fn(array $result): bool => !empty($result['ok']));
    $ok = $mode === 'any' ? count($passed) > 0 : count($passed) === count($results);
    $failedNames = array_keys(array_filter($results, static fn(array $result): bool => empty($result['ok'])));
    $message = $ok ? 'All active checks passed' : ('Failed: ' . implode(', ', $failedNames));
    if ($mode === 'any' && $ok) {
        $message = 'At least one active check passed';
    }

    return ['ok' => $ok, 'message' => $message, 'details' => $results, 'cancelled' => false];
}

function cg_simulate_failure_policy(string $name, array $itemConfig, array $runtimeRecord, array $container): array
{
    $now = cg_now();
    $currentFailures = max(0, (int)($runtimeRecord['consecutive_failures'] ?? 0));
    $afterFailure = $currentFailures + 1;
    $threshold = max(1, (int)($itemConfig['failures_before_action'] ?? 3));
    $cooldownUntil = (int)($runtimeRecord['cooldown_until'] ?? 0);
    $cooldownRemaining = max(0, $cooldownUntil - $now);
    $quarantineUntil = (int)($runtimeRecord['quarantined_until'] ?? 0);
    $quarantined = $quarantineUntil === -1 || $quarantineUntil > $now;
    $window = max(60, (int)($itemConfig['restart_window'] ?? 3600));
    $restartTimestamps = array_values(array_filter(
        is_array($runtimeRecord['restart_timestamps'] ?? null) ? $runtimeRecord['restart_timestamps'] : [],
        static fn($timestamp): bool => is_numeric($timestamp) && (int)$timestamp >= cg_now() - $window
    ));
    $maximumRestarts = max(0, (int)($itemConfig['maximum_restarts'] ?? 3));
    $restartLimitReached = $maximumRestarts > 0 && count($restartTimestamps) >= $maximumRestarts;
    $automaticActionEnabled = !empty($itemConfig['restart_enabled']);
    $decision = '';
    $wouldAct = false;
    $wouldQuarantine = false;

    if (!$automaticActionEnabled) {
        $decision = 'Report only: no automatic action would be performed.';
    } elseif ($quarantined) {
        $decision = 'Container is quarantined, so no automatic action would be performed.';
    } elseif ($afterFailure < $threshold) {
        $remaining = $threshold - $afterFailure;
        $decision = "Failure counter would become {$afterFailure} of {$threshold}; {$remaining} more consecutive failure(s) would be required.";
    } elseif ($cooldownRemaining > 0) {
        $decision = "Failure threshold would be met, but restart cooldown has {$cooldownRemaining}s remaining.";
    } elseif ($restartLimitReached) {
        $wouldQuarantine = true;
        $duration = max(0, (int)($itemConfig['quarantine_duration'] ?? 3600));
        $decision = $duration === 0
            ? 'Restart limit is reached; the container would be quarantined indefinitely.'
            : "Restart limit is reached; the container would be quarantined for {$duration}s.";
    } else {
        $wouldAct = true;
        $decision = !empty($container['running'])
            ? 'Failure threshold would be met; VFE Docker Container Monitor would safely restart the container.'
            : 'Failure threshold would be met; VFE Docker Container Monitor would safely start the container.';
    }

    return [
        'container' => $name,
        'assumption' => 'The next automatic check fails.',
        'current_failures' => $currentFailures,
        'failures_after_simulated_failure' => $afterFailure,
        'failures_before_action' => $threshold,
        'cooldown_remaining' => $cooldownRemaining,
        'restart_count_in_window' => count($restartTimestamps),
        'maximum_restarts' => $maximumRestarts,
        'restart_window' => $window,
        'quarantined' => $quarantined,
        'quarantined_until' => $quarantineUntil,
        'automatic_action_enabled' => $automaticActionEnabled,
        'would_act' => $wouldAct,
        'would_quarantine' => $wouldQuarantine,
        'decision' => $decision,
    ];
}

function cg_docker_action(string $name, string $action, int $timeout = 60): array
{
    if (!in_array($action, ['start', 'stop', 'restart'], true)) {
        return ['ok' => false, 'message' => 'Unsupported Docker action'];
    }
    $run = cg_run(['docker', $action, $name], $timeout);
    $message = $run['ok'] ? ucfirst($action) . " completed for $name" : ($run['output'] ?: ucfirst($action) . ' failed');
    cg_log($run['ok'] ? 'INFO' : 'ERROR', $message, ['container' => $name, 'action' => $action, 'code' => $run['code']]);
    return ['ok' => $run['ok'], 'message' => $message, 'output' => $run['output']];
}

function cg_wait_ready(string $name, array $config, int $maxWait = 90): array
{
    $deadline = time() + max(5, min(300, $maxWait));
    do {
        $containers = cg_list_containers();
        if (!isset($containers[$name])) {
            return ['ok' => false, 'message' => "$name disappeared while waiting"];
        }
        $itemConfig = $config['containers'][$name] ?? cg_default_container_config();
        $hasEnabledChecks = false;
        foreach (($itemConfig['checks'] ?? []) as $check) {
            if (!empty($check['enabled'])) {
                $hasEnabledChecks = true;
                break;
            }
        }
        if (!$hasEnabledChecks) {
            return !empty($containers[$name]['running'])
                ? ['ok' => true, 'message' => "$name is running"]
                : ['ok' => false, 'message' => "$name is not running"];
        }
        $result = cg_perform_checks($name, $itemConfig, $containers);
        if ($result['ok']) {
            return ['ok' => true, 'message' => "$name is ready"];
        }
        sleep(2);
    } while (time() < $deadline);

    return ['ok' => false, 'message' => "$name did not become ready within {$maxWait}s"];
}

function cg_start_tree(string $name, array $config, array &$visiting = [], array &$started = []): array
{
    if (isset($started[$name])) {
        return ['ok' => true, 'message' => "$name already processed"];
    }
    if (isset($visiting[$name])) {
        return ['ok' => false, 'message' => 'Dependency cycle detected at ' . $name];
    }
    $visiting[$name] = true;
    $containers = cg_list_containers();
    if (!isset($containers[$name])) {
        unset($visiting[$name]);
        return ['ok' => false, 'message' => "Container $name not found"];
    }

    foreach (cg_dependencies_for($name, $config, $containers) as $dependency) {
        $result = cg_start_tree($dependency, $config, $visiting, $started);
        if (!$result['ok']) {
            unset($visiting[$name]);
            return $result;
        }
        $depConfig = $config['containers'][$dependency] ?? cg_default_container_config();
        $waitSeconds = max(15, min(300, (int)($depConfig['startup_grace'] ?? 90) + 30));
        $ready = cg_wait_ready($dependency, $config, $waitSeconds);
        if (!$ready['ok']) {
            unset($visiting[$name]);
            return ['ok' => false, 'message' => "Dependency $dependency is not ready: {$ready['message']}"];
        }
    }

    $containers = cg_list_containers();
    if (empty($containers[$name]['running'])) {
        $action = cg_docker_action($name, 'start', 90);
        if (!$action['ok']) {
            unset($visiting[$name]);
            return $action;
        }
    }
    $started[$name] = true;
    unset($visiting[$name]);
    return ['ok' => true, 'message' => "Started $name with dependencies"];
}

function cg_stop_tree(string $name, array $config, array &$visiting = [], array &$stopped = []): array
{
    if (isset($stopped[$name])) {
        return ['ok' => true, 'message' => "$name already processed"];
    }
    if (isset($visiting[$name])) {
        return ['ok' => false, 'message' => 'Dependency cycle detected at ' . $name];
    }
    $visiting[$name] = true;
    $containers = cg_list_containers();
    if (!isset($containers[$name])) {
        unset($visiting[$name]);
        return ['ok' => false, 'message' => "Container $name not found"];
    }

    foreach (cg_dependents_for($name, $config, $containers) as $dependent) {
        $result = cg_stop_tree($dependent, $config, $visiting, $stopped);
        if (!$result['ok']) {
            unset($visiting[$name]);
            return $result;
        }
    }

    $containers = cg_list_containers();
    if (!empty($containers[$name]['running'])) {
        $action = cg_docker_action($name, 'stop', 90);
        if (!$action['ok']) {
            unset($visiting[$name]);
            return $action;
        }
    }
    $stopped[$name] = true;
    unset($visiting[$name]);
    return ['ok' => true, 'message' => "Stopped $name after dependents"];
}

function cg_restart_tree(string $name, array $config): array
{
    $containers = cg_list_containers();
    if (!isset($containers[$name])) {
        return ['ok' => false, 'message' => "Container $name not found"];
    }
    $runningDependents = [];
    $collect = function (string $target) use (&$collect, &$runningDependents, $config): void {
        $snapshot = cg_list_containers();
        foreach (cg_dependents_for($target, $config, $snapshot) as $dependent) {
            if (!in_array($dependent, $runningDependents, true) && !empty($snapshot[$dependent]['running'])) {
                $runningDependents[] = $dependent;
                $collect($dependent);
            }
        }
    };
    $collect($name);

    $visiting = [];
    $stopped = [];
    foreach (array_reverse($runningDependents) as $dependent) {
        $result = cg_stop_tree($dependent, $config, $visiting, $stopped);
        if (!$result['ok']) {
            return $result;
        }
    }

    $action = cg_docker_action($name, 'restart', 120);
    if (!$action['ok']) {
        return $action;
    }
    $ready = cg_wait_ready($name, $config, max(30, (int)(($config['containers'][$name]['startup_grace'] ?? 90) + 30)));
    if (!$ready['ok']) {
        return $ready;
    }

    $started = [];
    foreach (array_reverse($runningDependents) as $dependent) {
        $visit = [];
        $result = cg_start_tree($dependent, $config, $visit, $started);
        if (!$result['ok']) {
            return $result;
        }
    }
    return ['ok' => true, 'message' => "Restarted $name and restored dependent containers"];
}

function cg_pid_is_guardian(int $pid): bool
{
    if ($pid <= 1 || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($cmdline) || $cmdline === '') {
        return false;
    }
    return str_contains(str_replace("\0", ' ', $cmdline), 'guardian-daemon.php');
}

function cg_daemon_status(): array
{
    $pid = is_file(CG_PID_FILE) ? (int)trim((string)@file_get_contents(CG_PID_FILE)) : 0;
    $running = cg_pid_is_guardian($pid);
    $runtime = cg_runtime_load();
    $heartbeat = (int)($runtime['daemon']['heartbeat'] ?? 0);
    $heartbeatAge = $heartbeat > 0 ? max(0, cg_now() - $heartbeat) : null;
    return [
        'running' => $running,
        'pid' => $running ? $pid : 0,
        'heartbeat' => $heartbeat,
        'heartbeat_age' => $heartbeatAge,
        'responsive' => $running && $heartbeatAge !== null && $heartbeatAge <= 30,
        'global_enabled' => !empty($runtime['daemon']['global_enabled']),
        'last_error' => (string)($runtime['daemon']['last_error'] ?? ''),
    ];
}

function cg_started_epoch(array $container): int
{
    $value = (string)($container['started_at'] ?? '');
    if ($value === '' || str_starts_with($value, '0001-')) {
        return 0;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? 0 : $timestamp;
}
