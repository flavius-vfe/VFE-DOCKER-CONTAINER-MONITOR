#!/bin/bash
set +e

/etc/rc.d/rc.vfe-docker-container-monitor stop >/dev/null 2>&1
removepkg vfe.docker.container.monitor >/dev/null 2>&1

rm -rf /usr/local/emhttp/plugins/vfe.docker.container.monitor
rm -f /etc/rc.d/rc.vfe-docker-container-monitor
rm -f /usr/local/emhttp/webGui/event/starting_svcs/vfe-docker-container-monitor
rm -f /usr/local/emhttp/webGui/event/stopping_svcs/vfe-docker-container-monitor
rm -f /usr/local/emhttp/plugins/dynamix/images/vfe.docker.container.monitor.png
rm -f /boot/config/plugins/images/vfe.docker.container.monitor.png
rm -rf /var/lib/vfe-docker-container-monitor
rm -rf /var/run/vfe-docker-container-monitor-checks
rm -f /var/run/vfe-docker-container-monitor*
rm -f /var/log/vfe-docker-container-monitor*
rm -f /var/log/plugins/vfe.docker.container.monitor*
rm -f /var/log/packages/vfe.docker.container.monitor*
rm -rf /boot/config/plugins/vfe.docker.container.monitor

logger -t vfe-docker-container-monitor "VFE Docker Container Monitor removed"
echo "VFE Docker Container Monitor has been removed."
exit 0
