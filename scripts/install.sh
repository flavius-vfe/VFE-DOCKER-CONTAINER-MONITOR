#!/bin/bash
set -euo pipefail
PLUGIN_DIR="/boot/config/plugins/vfe.docker.container.monitor"
OLD_PLUGIN_DIR="/boot/config/plugins/container.guardian"
PACKAGE="vfe.docker.container.monitor-2026.07.30-x86_64-1.txz"
ENCODED_PACKAGE="${PACKAGE}.b64"
EXPECTED_SHA256="707fb4d7fb995d248bae5b7b521c821b17305d58717adfa7d02c1208b57bc5cb"
INSTALL_LOG="${PLUGIN_DIR}/install.log"

mkdir -p "$PLUGIN_DIR"
touch "$INSTALL_LOG"
chmod 0755 "$PLUGIN_DIR" 2>/dev/null || true
chmod 0644 "$INSTALL_LOG" 2>/dev/null || true
exec > >(tee -a "$INSTALL_LOG") 2>&1

echo "[$(date '+%F %T')] Installing VFE Docker Container Monitor 2026.07.30 for Unraid 7.3.0+"

if [[ ! -f "$PLUGIN_DIR/config.json" && -f "$OLD_PLUGIN_DIR/config.json" ]]; then
  cp -p "$OLD_PLUGIN_DIR/config.json" "$PLUGIN_DIR/config.json"
  echo "Migrated configuration from Container Guardian."
fi

base64 -d "$PLUGIN_DIR/$ENCODED_PACKAGE" > "$PLUGIN_DIR/$PACKAGE"
rm -f "$PLUGIN_DIR/$ENCODED_PACKAGE"
echo "$EXPECTED_SHA256  $PLUGIN_DIR/$PACKAGE" | sha256sum -c -

/etc/rc.d/rc.container-guardian stop >/dev/null 2>&1 || true
removepkg container.guardian >/dev/null 2>&1 || true
rm -rf /usr/local/emhttp/plugins/container.guardian
rm -f /etc/rc.d/rc.container-guardian
rm -f /usr/local/emhttp/webGui/event/starting_svcs/container-guardian
rm -f /usr/local/emhttp/webGui/event/stopping_svcs/container-guardian
rm -rf /var/lib/container-guardian /var/run/container-guardian-checks
rm -f /var/run/container-guardian.pid /var/run/container-guardian.lock /var/run/container-guardian-runtime.lock

upgradepkg --install-new --reinstall "$PLUGIN_DIR/$PACKAGE"

chmod 0755 /etc/rc.d/rc.vfe-docker-container-monitor
chmod 0755 /usr/local/emhttp/plugins/vfe.docker.container.monitor/scripts/guardian-daemon.php
chmod 0755 /usr/local/emhttp/plugins/vfe.docker.container.monitor/scripts/guardian-test-worker.php
chmod 0755 /usr/local/emhttp/plugins/vfe.docker.container.monitor/scripts/guardian-doctor.sh
chmod 0755 /usr/local/emhttp/webGui/event/starting_svcs/vfe-docker-container-monitor
chmod 0755 /usr/local/emhttp/webGui/event/stopping_svcs/vfe-docker-container-monitor

mkdir -p /boot/config/plugins/images /usr/local/emhttp/plugins/dynamix/images
cp -f /usr/local/emhttp/plugins/vfe.docker.container.monitor/images/vfe.docker.container.monitor.png /boot/config/plugins/images/vfe.docker.container.monitor.png
cp -f /usr/local/emhttp/plugins/vfe.docker.container.monitor/images/vfe.docker.container.monitor.png /usr/local/emhttp/plugins/dynamix/images/vfe.docker.container.monitor.png
chmod 0644 /boot/config/plugins/images/vfe.docker.container.monitor.png /usr/local/emhttp/plugins/dynamix/images/vfe.docker.container.monitor.png

mkdir -p /var/lib/vfe-docker-container-monitor/jobs /var/run/vfe-docker-container-monitor-checks
chmod 0755 /var/lib/vfe-docker-container-monitor /var/lib/vfe-docker-container-monitor/jobs /var/run/vfe-docker-container-monitor-checks

if [[ ! -f "$PLUGIN_DIR/config.json" ]]; then
  cat > "$PLUGIN_DIR/config.json" <<'JSON'
{
  "version": 4,
  "updated_at": 0,
  "global_enabled": false,
  "ui_refresh_seconds": 5,
  "containers": {}
}
JSON
fi
chmod 0600 "$PLUGIN_DIR/config.json" 2>/dev/null || true
php -r '$d=json_decode(file_get_contents($argv[1]),true); if(!is_array($d)){fwrite(STDERR,"Invalid configuration JSON\n"); exit(1);}' "$PLUGIN_DIR/config.json"

find "$PLUGIN_DIR" -maxdepth 1 -type f -name 'vfe.docker.container.monitor-*.txz' ! -name "$PACKAGE" -delete 2>/dev/null || true
rm -f /boot/config/plugins/container.guardian.plg /boot/config/plugins/container.guardian-local.plg

if /etc/rc.d/rc.vfe-docker-container-monitor restart; then
  echo "VFE Docker Container Monitor service started."
else
  echo "WARNING: Plugin files installed, but the service did not start."
  echo "Run: /usr/local/emhttp/plugins/vfe.docker.container.monitor/scripts/guardian-doctor.sh"
fi

logger -t vfe-docker-container-monitor "VFE Docker Container Monitor 2026.07.30 installed for Unraid 7.3.0+"
echo "VFE Docker Container Monitor 2026.07.30 installed."
exit 0
