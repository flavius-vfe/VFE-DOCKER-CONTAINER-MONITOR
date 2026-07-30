#!/usr/bin/php
<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/vfe.docker.container.monitor/include/guardian.php';

if (!is_dir(CG_RUNTIME_DIR) && !mkdir(CG_RUNTIME_DIR, 0755, true) && !is_dir(CG_RUNTIME_DIR)) {
    fwrite(STDERR, "Unable to create runtime directory\n");
    exit(1);
}

$lockHandle = fopen(CG_LOCK_FILE, 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "VFE Docker Container Monitor is already running\n");
    exit(0);
}

file_put_contents(CG_PID_FILE, (string)getmypid());
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGHUP, static function (): void { /* Configuration is reloaded every loop. */ });
}

register_shutdown_function(static function () use ($lockHandle): void {
    @unlink(CG_PID_FILE);
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
});

/** Persist state and keep the daemon heartbeat current. */
$persist = static function (array &$runtime, bool $globalEnabled, string $lastError = ''): void {
    $runtime['daemon'] = [
        'pid' => getmypid(),
        'heartbeat' => cg_now(),
        'global_enabled' => $globalEnabled,
        'last_error' => $lastError,
    ];
    cg_runtime_save($runtime);
};

cg_log('INFO', 'VFE Docker Container Monitor daemon started', ['pid' => getmypid()]);

while ($running) {
    try {
        $config = cg_config_load();
        $globalEnabled = !empty($config['global_enabled']);
        $runtime = cg_runtime_load();

        // Always create visible runtime records. This prevents a monitored card
        // from remaining at "Waiting for first check" when global monitoring is
        // paused or the Docker inventory call fails.
        foreach ($config['containers'] as $name => $itemConfig) {
            $current = array_replace(cg_container_runtime_default(), $runtime['containers'][$name] ?? []);
            if (empty($itemConfig['enabled'])) {
                if ((int)$current['last_check'] === 0) {
                    $current['last_message'] = 'Monitoring disabled for this container';
                }
            } elseif (!$globalEnabled && (int)$current['last_check'] === 0) {
                $current['last_message'] = 'Global monitoring is disabled';
            } elseif ($globalEnabled && (int)$current['last_check'] === 0 && $current['last_message'] === 'Not checked yet') {
                $current['last_message'] = 'Queued for first automatic check';
            }
            $runtime['containers'][$name] = $current;
        }
        $persist($runtime, $globalEnabled);

        if ($globalEnabled) {
            $containers = cg_list_containers();
            foreach ($config['containers'] as $name => $itemConfig) {
                if (!$running) {
                    break;
                }
                if (empty($itemConfig['enabled'])) {
                    continue;
                }

                $current = array_replace(cg_container_runtime_default(), $runtime['containers'][$name] ?? []);
                $now = cg_now();

                if (!isset($containers[$name])) {
                    if (($current['last_message'] ?? '') !== 'Container not found') {
                        cg_log('WARN', 'Configured container not found', ['container' => $name]);
                    }
                    $current['last_check'] = $now;
                    $current['last_result'] = false;
                    $current['last_message'] = 'Container not found';
                    $current['last_details'] = [];
                    $current['last_check_source'] = 'automatic';
                    $runtime['containers'][$name] = $current;
                    $persist($runtime, $globalEnabled);
                    continue;
                }

                $quarantineUntil = (int)($current['quarantined_until'] ?? 0);
                if ($quarantineUntil > 0 && $quarantineUntil <= $now) {
                    $current['quarantined_until'] = 0;
                    $current['consecutive_failures'] = 0;
                    $current['last_message'] = 'Quarantine expired; monitoring resumed';
                    cg_log('INFO', 'Quarantine expired', ['container' => $name]);
                }
                if ((int)$current['quarantined_until'] === -1 || (int)$current['quarantined_until'] > $now) {
                    $runtime['containers'][$name] = $current;
                    $persist($runtime, $globalEnabled);
                    continue;
                }

                $interval = max(5, (int)($itemConfig['check_interval'] ?? 30));
                $lastCheck = (int)($current['last_check'] ?? 0);
                // If the server clock moved backwards, a future timestamp could
                // otherwise suppress checks for hours or days.
                if ($lastCheck > $now + $interval) {
                    $lastCheck = 0;
                    $current['last_check'] = 0;
                    $current['last_message'] = 'Clock change detected; first check re-queued';
                }
                if ($lastCheck > 0 && $now - $lastCheck < $interval) {
                    $runtime['containers'][$name] = $current;
                    $persist($runtime, $globalEnabled);
                    continue;
                }

                $startedEpoch = cg_started_epoch($containers[$name]);
                $startupGrace = max(0, (int)($itemConfig['startup_grace'] ?? 90));
                if (!empty($containers[$name]['running']) && $startedEpoch > 0 && $now < $startedEpoch + $startupGrace) {
                    $remaining = ($startedEpoch + $startupGrace) - $now;
                    $current['last_check'] = $now;
                    $current['last_result'] = null;
                    $current['last_message'] = "Startup grace active ({$remaining}s remaining)";
                    $current['last_details'] = [];
                    $current['last_check_source'] = 'automatic';
                    $runtime['containers'][$name] = $current;
                    $persist($runtime, $globalEnabled);
                    continue;
                }

                $checkLock = cg_container_check_lock($name, true);
                if ($checkLock === false) {
                    // An on-demand check or safe action owns this container. Do
                    // not touch its automatic result, counters, or timestamps.
                    $persist($runtime, $globalEnabled);
                    continue;
                }

                try {
                    $result = cg_perform_checks($name, $itemConfig, $containers);
                    $current['last_check'] = $now;
                    $current['last_result'] = (bool)$result['ok'];
                    $current['last_message'] = (string)$result['message'];
                    $current['last_details'] = $result['details'];
                    $current['last_check_source'] = 'automatic';
                    $current['_updated_at'] = microtime(true);

                    if ($result['ok']) {
                        if ((int)$current['consecutive_failures'] > 0) {
                            cg_log('INFO', 'Container recovered', ['container' => $name]);
                        }
                        $current['consecutive_failures'] = 0;
                        $runtime['containers'][$name] = $current;
                        $persist($runtime, $globalEnabled);
                        continue;
                    }

                    $current['consecutive_failures'] = (int)$current['consecutive_failures'] + 1;
                    cg_log('WARN', 'Health check failed', [
                        'container' => $name,
                        'failures' => $current['consecutive_failures'],
                        'message' => $result['message'],
                    ]);

                    $threshold = max(1, (int)($itemConfig['failures_before_action'] ?? 3));
                    if (empty($itemConfig['restart_enabled']) || $current['consecutive_failures'] < $threshold) {
                        $runtime['containers'][$name] = $current;
                        $persist($runtime, $globalEnabled);
                        continue;
                    }

                    $cooldownUntil = (int)($current['cooldown_until'] ?? 0);
                    if ($cooldownUntil > $now) {
                        $current['last_message'] .= '; restart cooldown active';
                        $runtime['containers'][$name] = $current;
                        $persist($runtime, $globalEnabled);
                        continue;
                    }

                    $window = max(60, (int)($itemConfig['restart_window'] ?? 3600));
                    $restartTimestamps = array_values(array_filter(
                        is_array($current['restart_timestamps'] ?? null) ? $current['restart_timestamps'] : [],
                        static fn($timestamp): bool => is_numeric($timestamp) && (int)$timestamp >= cg_now() - $window
                    ));
                    $maximumRestarts = max(0, (int)($itemConfig['maximum_restarts'] ?? 3));
                    if ($maximumRestarts > 0 && count($restartTimestamps) >= $maximumRestarts) {
                        $duration = max(0, (int)($itemConfig['quarantine_duration'] ?? 3600));
                        $current['quarantined_until'] = $duration === 0 ? -1 : $now + $duration;
                        $current['last_action'] = 'quarantine';
                        $current['last_action_at'] = $now;
                        $current['last_message'] = $duration === 0
                            ? 'Quarantined indefinitely after restart limit'
                            : "Quarantined for {$duration}s after restart limit";
                        cg_log('ERROR', 'Container quarantined after restart limit', [
                            'container' => $name,
                            'restarts' => count($restartTimestamps),
                            'window' => $window,
                            'duration' => $duration,
                        ]);
                        $current['_updated_at'] = microtime(true);
                        $runtime['containers'][$name] = $current;
                        $persist($runtime, $globalEnabled);
                        continue;
                    }

                    $restartTimestamps[] = $now;
                    $current['restart_timestamps'] = $restartTimestamps;
                    $current['last_action'] = !empty($containers[$name]['running']) ? 'restart' : 'start';
                    $current['last_action_at'] = $now;
                    $current['_updated_at'] = microtime(true);
                    $runtime['containers'][$name] = $current;
                    $persist($runtime, $globalEnabled);

                    $actionResult = !empty($containers[$name]['running'])
                        ? cg_restart_tree($name, $config)
                        : (function () use ($name, $config): array {
                            $visiting = [];
                            $started = [];
                            return cg_start_tree($name, $config, $visiting, $started);
                        })();

                    $current['last_message'] = (string)$actionResult['message'];
                    $current['last_result'] = (bool)$actionResult['ok'];
                    $current['last_check_source'] = 'automatic-action';
                    $current['consecutive_failures'] = 0;
                    $current['cooldown_until'] = $now + max(0, (int)($itemConfig['restart_cooldown'] ?? 300));
                    $current['_updated_at'] = microtime(true);
                    $runtime['containers'][$name] = $current;
                    $persist($runtime, $globalEnabled);
                } finally {
                    cg_container_check_unlock($checkLock);
                }
            }
        }

        $persist($runtime, $globalEnabled);
    } catch (Throwable $error) {
        cg_log('ERROR', 'Daemon loop error', ['error' => $error->getMessage()]);
        $runtime = isset($runtime) && is_array($runtime) ? $runtime : cg_runtime_load();
        $globalEnabled = isset($globalEnabled) ? (bool)$globalEnabled : false;
        $persist($runtime, $globalEnabled, $error->getMessage());
    }

    for ($i = 0; $i < 10 && $running; $i++) {
        usleep(100000);
    }
}

cg_log('INFO', 'VFE Docker Container Monitor daemon stopped', ['pid' => getmypid()]);
exit(0);
