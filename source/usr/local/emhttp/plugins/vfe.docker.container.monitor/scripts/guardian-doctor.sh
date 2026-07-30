#!/bin/bash
set -u

PLUGIN="vfe.docker.container.monitor"
BASE="/usr/local/emhttp/plugins/$PLUGIN"
CFG="/boot/config/plugins/$PLUGIN/config.json"
LOG="/var/log/vfe-docker-container-monitor.log"
RC="/etc/rc.d/rc.vfe-docker-container-monitor"

RUNTIME="/var/lib/vfe-docker-container-monitor/runtime.json"
PIDFILE="/var/run/vfe-docker-container-monitor.pid"

ok()   { printf '[ OK ] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; }
fail() { printf '[FAIL] %s\n' "$*"; FAILED=1; }
FAILED=0

echo "VFE Docker Container Monitor diagnostics"
echo "Generated: $(date -Is 2>/dev/null || date)"
if [[ -r /etc/unraid-version ]]; then
  echo "Unraid: $(tr '\n' ' ' < /etc/unraid-version)"
else
  warn "/etc/unraid-version is unavailable"
fi

echo
[[ -d "$BASE" ]] && ok "Plugin files installed at $BASE" || fail "Plugin directory missing: $BASE"
[[ -x "$RC" ]] && ok "Service script is executable" || fail "Service script missing or not executable: $RC"
[[ -x "$BASE/scripts/guardian-daemon.php" ]] && ok "Daemon is executable" || fail "Daemon missing or not executable"
[[ -x "$BASE/scripts/guardian-test-worker.php" ]] && ok "On-demand test worker is executable" || fail "On-demand test worker missing or not executable"
[[ -r "$CFG" ]] && ok "Configuration is readable: $CFG" || warn "Configuration is not present yet: $CFG"
CFG_DIR="$(dirname "$CFG")"
if [[ -d "$CFG_DIR" ]]; then
  probe="$CFG_DIR/.guardian-write-test.$$"
  if printf '%s\n' "write-test" > "$probe" 2>/dev/null && [[ "$(cat "$probe" 2>/dev/null)" == "write-test" ]]; then
    rm -f "$probe"
    ok "Persistent config directory is writable and readable"
  else
    rm -f "$probe" 2>/dev/null || true
    fail "Persistent config directory is not writable: $CFG_DIR"
  fi
fi

if command -v php >/dev/null 2>&1; then
  ok "PHP found: $(command -v php) ($(php -r 'echo PHP_VERSION;' 2>/dev/null))"
  for f in "$BASE/VFEDockerContainerMonitor.page" "$BASE/include/api.php" "$BASE/include/guardian.php" "$BASE/scripts/guardian-daemon.php" "$BASE/scripts/guardian-test-worker.php"; do
    [[ -f "$f" ]] || continue
    if php -l "$f" >/dev/null 2>&1; then ok "PHP syntax: $f"; else fail "PHP syntax error: $f"; fi
  done
  if [[ -r "$CFG" ]]; then
    if php -r '$d=json_decode(file_get_contents($argv[1]),true); exit(is_array($d) ? 0 : 1);' "$CFG"; then
      updated="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["updated_at"]??0);' "$CFG" 2>/dev/null)"
      schema="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["version"]??0);' "$CFG" 2>/dev/null)"
      if [[ "$schema" == "4" ]]; then
        ok "Configuration JSON is valid (schema=4, updated_at=${updated:-0})"
      else
        warn "Configuration JSON is valid but schema is ${schema:-0}; opening/saving the page will migrate it to schema 4"
      fi
    else
      fail "Configuration JSON is invalid: $CFG"
    fi
  fi
else
  fail "PHP executable not found"
fi

if command -v docker >/dev/null 2>&1; then
  if docker info >/dev/null 2>&1; then
    ok "Docker daemon is reachable"
    echo "Containers: $(docker ps -aq 2>/dev/null | wc -l)"
  else
    warn "Docker CLI exists but the Docker daemon is not reachable"
  fi
else
  warn "Docker CLI is unavailable"
fi

if [[ -r /usr/local/emhttp/state/var.ini ]]; then
  ok "Unraid 7.x state file found: /usr/local/emhttp/state/var.ini"
elif [[ -r /var/local/emhttp/var.ini ]]; then
  ok "Legacy state file found: /var/local/emhttp/var.ini"
else
  warn "No Unraid state var.ini was found"
fi

if [[ -x "$RC" ]]; then
  if "$RC" status; then
    ok "VFE monitor service is running"
    if [[ -s "$PIDFILE" ]]; then
      pid="$(cat "$PIDFILE" 2>/dev/null)"
      if [[ "$pid" =~ ^[0-9]+$ && -r "/proc/$pid/cmdline" ]] && tr '\0' ' ' < "/proc/$pid/cmdline" | grep -Fq 'guardian-daemon.php'; then
        ok "PID $pid belongs to guardian-daemon.php"
      else
        fail "PID file does not point to guardian-daemon.php"
      fi
    fi
  else
    warn "VFE monitor service is stopped"
  fi
fi

if [[ -r "$RUNTIME" ]]; then
  if command -v php >/dev/null 2>&1; then
    heartbeat="$(php -r '$d=json_decode(@file_get_contents($argv[1]),true); echo (int)($d["daemon"]["heartbeat"]??0);' "$RUNTIME" 2>/dev/null)"
    now="$(date +%s)"
    if [[ "$heartbeat" =~ ^[0-9]+$ && "$heartbeat" -gt 0 ]]; then
      age=$((now-heartbeat))
      if (( age <= 30 )); then ok "Daemon heartbeat is current (${age}s old)"; else warn "Daemon heartbeat is stale (${age}s old)"; fi
    else
      warn "Runtime file has no daemon heartbeat"
    fi
  fi
else
  warn "Runtime state file is missing: $RUNTIME"
fi

if [[ -f "$LOG" ]]; then
  echo
  echo "Last 40 guardian log lines:"
  tail -n 40 "$LOG"
else
  warn "No guardian runtime log yet"
fi

exit "$FAILED"
