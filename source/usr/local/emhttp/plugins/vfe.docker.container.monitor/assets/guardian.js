(() => {
  'use strict';

  const boot = window.VFE_DOCKER_CONTAINER_MONITOR || {};
  const apiUrl = boot.apiUrl || '/plugins/vfe.docker.container.monitor/include/api.php';
  async function currentCsrfToken() {
    // Unraid 7.3 injects the active request token into window.csrf_token.
    // It may arrive just after this bundle, so wait briefly before a POST.
    for (let attempt = 0; attempt < 20; attempt += 1) {
      if (window.csrf_token) return String(window.csrf_token);
      await new Promise(resolve => window.setTimeout(resolve, 100));
    }
    const hidden = document.querySelector('#cg-csrf-token');
    if (hidden?.value) return String(hidden.value);
    return String(boot.csrfTokenFallback || '');
  }

  const state = {
    snapshot: null,
    config: null,
    persistedConfig: null,
    globalDirty: false,
    dirtyContainers: new Set(),
    timer: null,
    saveInFlight: new Set(),
    revision: 0,
    busy: new Set(),
    openCards: new Set(),
    testPolling: new Set(),
    notifiedJobs: new Set(),
    simulations: new Map(),
    renderPending: false,
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const containerRoot = $('#cg-containers');
  const loading = $('#cg-loading');

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function encodedName(name) {
    return escapeHtml(encodeURIComponent(name));
  }

  function decodedName(value) {
    try { return decodeURIComponent(value || ''); } catch (_) { return value || ''; }
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function defaultContainerConfig() {
    return {
      enabled: false,
      restart_enabled: true,
      check_mode: 'all',
      check_interval: 30,
      timeout: 5,
      failures_before_action: 3,
      startup_grace: 90,
      restart_cooldown: 300,
      maximum_restarts: 3,
      restart_window: 3600,
      quarantine_duration: 3600,
      dependencies: [],
      use_auto_dependency: true,
      checks: {
        docker_state: { enabled: true },
        docker_health: { enabled: false },
        ping: { enabled: false, target_mode: 'auto', host: '' },
        tcp: { enabled: false, target_mode: 'auto', host: '', port: 0 },
        http: { enabled: false, target_mode: 'auto', url: '', expected_codes: '200-399' },
        https: { enabled: false, target_mode: 'auto', url: '', expected_codes: '200-399', verify_tls: false },
      },
    };
  }

  function ensureContainerConfig(name) {
    if (!state.config.containers) state.config.containers = {};
    if (!state.config.containers[name]) state.config.containers[name] = defaultContainerConfig();
    return state.config.containers[name];
  }

  function getPath(object, path) {
    return path.split('.').reduce((current, key) => current?.[key], object);
  }

  function setPath(object, path, value) {
    const keys = path.split('.');
    const final = keys.pop();
    const parent = keys.reduce((current, key) => {
      if (!current[key] || typeof current[key] !== 'object') current[key] = {};
      return current[key];
    }, object);
    parent[final] = value;
  }

  async function request(payload = null, query = '') {
    const options = { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'include' };
    let url = apiUrl + query;
    if (payload) {
      const token = await currentCsrfToken();
      if (!token) {
        throw new Error('The Unraid WebGUI CSRF token is unavailable. Reload VFE Docker Container Monitor from the Unraid WebGUI.');
      }
      options.method = 'POST';
      // Form encoding is the most reliable transport through Unraid 7.3's
      // global CSRF bootstrap. The API also accepts JSON, but putting both the
      // token and payload in $_POST prevents a token-only $_POST from hiding
      // the actual configuration body.
      const form = new URLSearchParams();
      form.set('csrf_token', token);
      form.set('payload', JSON.stringify(payload));
      options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      options.headers['X-Csrf-Token'] = token;
      options.body = form.toString();
    }
    const response = await fetch(url, options);
    let data;
    try {
      data = await response.json();
    } catch (_) {
      throw new Error(`VFE monitor API returned HTTP ${response.status}.`);
    }
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || data.message || `Request failed with HTTP ${response.status}.`);
    }
    return data;
  }

  function showBanner(message, kind = 'info', timeout = 5000) {
    const banner = $('#cg-banner');
    banner.textContent = message;
    banner.className = `cg-banner ${kind === 'error' ? 'cg-error' : kind === 'success' ? 'cg-success' : ''}`;
    banner.hidden = false;
    if (timeout > 0) {
      window.setTimeout(() => {
        if (banner.textContent === message) banner.hidden = true;
      }, timeout);
    }
  }

  function setSaveStatus(message, kind = '') {
    const status = $('#cg-save-status');
    if (!status) return;
    status.textContent = message;
    status.className = `cg-save-status ${kind}`.trim();
  }

  function hasAnyUnsavedChanges() {
    return state.globalDirty || state.dirtyContainers.size > 0;
  }

  function updateSaveStatus(message = '') {
    const globalButton = $('#cg-save');
    const unsavedCount = state.dirtyContainers.size + (state.globalDirty ? 1 : 0);
    if (globalButton) {
      globalButton.textContent = unsavedCount > 0 ? `Save configuration — all containers (${unsavedCount} unsaved)` : 'Save configuration (all containers)';
      globalButton.classList.toggle('cg-unsaved-button', unsavedCount > 0);
    }
    if (message) {
      setSaveStatus(message, hasAnyUnsavedChanges() ? 'pending' : 'saved');
    } else if (hasAnyUnsavedChanges()) {
      setSaveStatus('Unsaved changes — press a Save button', 'pending');
    } else {
      setSaveStatus('All settings saved', 'saved');
    }
    renderSummary();
  }

  function markContainerDirty(name) {
    state.revision += 1;
    if (hasUnsavedContainer(name)) state.dirtyContainers.add(name);
    else state.dirtyContainers.delete(name);
    updateSaveStatus();
  }

  function markGlobalDirty() {
    state.revision += 1;
    const persisted = state.persistedConfig || {};
    state.globalDirty = Boolean(
      Boolean(state.config?.global_enabled) !== Boolean(persisted.global_enabled)
      || Number(state.config?.ui_refresh_seconds || 5) !== Number(persisted.ui_refresh_seconds || 5)
    );
    updateSaveStatus();
  }

  function clearContainerDirty(name, savedAt = 0) {
    state.dirtyContainers.delete(name);
    const when = savedAt ? new Date(savedAt * 1000).toLocaleTimeString() : '';
    updateSaveStatus(when ? `${name} saved at ${when}` : `${name} settings saved`);
  }

  function clearAllDirty(savedAt = 0) {
    state.globalDirty = false;
    state.dirtyContainers.clear();
    const when = savedAt ? new Date(savedAt * 1000).toLocaleTimeString() : '';
    updateSaveStatus(when ? `All settings saved at ${when}` : 'All settings saved');
  }

  function formatEpoch(epoch) {
    const value = Number(epoch || 0);
    if (!value) return 'never';
    return new Date(value * 1000).toLocaleString();
  }

  function checkLabel(name) {
    return ({
      docker_state: 'Docker state',
      docker_health: 'Docker health',
      ping: 'Ping',
      tcp: 'TCP port',
      http: 'HTTP',
      https: 'HTTPS',
    })[name] || name;
  }

  function renderCheckResults(runtime) {
    const details = runtime?.last_details || {};
    const rows = Object.entries(details).filter(([, item]) => item?.enabled !== false && item?.status !== 'disabled');
    if (!rows.length) return '';
    return `<div class="cg-check-results cg-automatic-results">
      <div class="cg-check-results-title">Latest automatic check details</div>
      ${rows.map(([name, item]) => renderResultRow(name, item, false)).join('')}
    </div>`;
  }

  function formatMilliseconds(value) {
    const ms = Number(value || 0);
    if (!ms) return '—';
    return ms >= 1000 ? `${(ms / 1000).toFixed(2)}s` : `${ms}ms`;
  }

  function renderResultMetadata(item) {
    const meta = item?.metadata || {};
    const parts = [];
    if (item?.target) parts.push(`<span><b>Target:</b> ${escapeHtml(item.target)}</span>`);
    if (item?.duration_ms) parts.push(`<span><b>Duration:</b> ${escapeHtml(formatMilliseconds(item.duration_ms))}</span>`);
    if (item?.tested_at) parts.push(`<span><b>Tested:</b> ${escapeHtml(new Date(Number(item.tested_at) * 1000).toLocaleString())}</span>`);
    if (item?.failure_reason) parts.push(`<span class="cg-failure-reason"><b>Reason:</b> ${escapeHtml(item.failure_reason)}</span>`);
    if (meta.resolved_ip) parts.push(`<span><b>Resolved IP:</b> ${escapeHtml(meta.resolved_ip)}</span>`);
    if (meta.status_code) parts.push(`<span><b>HTTP status:</b> ${escapeHtml(meta.status_code)}</span>`);
    if (meta.final_url) parts.push(`<span><b>Final URL:</b> ${escapeHtml(meta.final_url)}</span>`);
    if (meta.tls) parts.push(`<span><b>TLS:</b> ${escapeHtml(meta.tls)}</span>`);
    if (meta.connection_seconds) parts.push(`<span><b>HTTP total:</b> ${escapeHtml(Number(meta.connection_seconds).toFixed(3))}s</span>`);
    return parts.length ? `<div class="cg-result-meta">${parts.join('')}</div>` : '';
  }

  function resultStatus(item) {
    const status = item?.status || (item?.ok === true ? 'passed' : item?.ok === false ? 'failed' : 'waiting');
    return ({
      waiting: ['WAIT', 'waiting'],
      running: ['RUN', 'running'],
      passed: ['PASS', 'pass'],
      failed: ['FAIL', 'fail'],
      cancelled: ['CANCEL', 'cancelled'],
    })[status] || ['WAIT', 'waiting'];
  }

  function renderResultRow(name, item, showMetadata = true) {
    const [label, css] = resultStatus(item);
    return `<div class="cg-result-row ${css}">
      <span class="cg-result-status">${label}</span>
      <strong>${escapeHtml(checkLabel(name))}</strong>
      <div class="cg-result-content"><span>${escapeHtml(item?.message || '')}</span>${showMetadata ? renderResultMetadata(item) : ''}</div>
    </div>`;
  }

  function hasUnsavedContainer(name) {
    const current = ensureContainerConfig(name);
    const persisted = state.persistedConfig?.containers?.[name] || defaultContainerConfig();
    return JSON.stringify(current) !== JSON.stringify(persisted);
  }

  function renderSimulation(name) {
    const simulation = state.simulations.get(name);
    if (!simulation) return '';
    return `<div class="cg-policy-panel">
      <div class="cg-check-results-title">Failure-policy simulation — no changes made</div>
      <p><strong>${escapeHtml(simulation.decision || '')}</strong></p>
      <div class="cg-policy-grid">
        <span>Current failures: <b>${escapeHtml(simulation.current_failures)}</b></span>
        <span>After simulated failure: <b>${escapeHtml(simulation.failures_after_simulated_failure)} / ${escapeHtml(simulation.failures_before_action)}</b></span>
        <span>Cooldown remaining: <b>${escapeHtml(simulation.cooldown_remaining)}s</b></span>
        <span>Restarts in window: <b>${escapeHtml(simulation.restart_count_in_window)} / ${simulation.maximum_restarts === 0 ? 'unlimited' : escapeHtml(simulation.maximum_restarts)}</b></span>
      </div>
      <button type="button" class="cg-btn cg-btn-small" data-close-simulation data-name="${encodedName(name)}">Close simulation</button>
    </div>`;
  }

  function renderOnDemand(name, job) {
    if (!job) return renderSimulation(name);
    const active = ['queued', 'running', 'cancelling'].includes(job.status);
    const modeLabel = job.mode === 'run_now' ? 'RUN CHECK NOW' : 'MANUAL TEST';
    const statusLabel = ({ queued: 'QUEUED', running: 'TESTING', cancelling: 'CANCELLING', completed: job.ok ? 'PASS' : 'FAIL', cancelled: 'CANCELLED', failed: 'ERROR' })[job.status] || String(job.status || '').toUpperCase();
    const enabled = Number(job.total_checks || 0);
    const completed = Number(job.completed_checks || 0);
    const percent = enabled > 0 ? Math.min(100, Math.round((completed / enabled) * 100)) : 0;
    const rows = Object.entries(job.checks || {}).filter(([, item]) => item?.enabled !== false && item?.status !== 'disabled');
    return `<div class="cg-on-demand ${active ? 'active' : ''}">
      <div class="cg-on-demand-head">
        <div><span class="cg-source">${modeLabel}</span> <strong>${escapeHtml(statusLabel)}</strong> — ${escapeHtml(job.message || '')}</div>
        <div>${completed}/${enabled} checks · ${job.finished_at ? escapeHtml(new Date(Number(job.finished_at) * 1000).toLocaleString()) : active ? 'in progress' : ''}</div>
      </div>
      ${active ? `<div class="cg-progress" aria-label="Check progress"><span style="width:${percent}%"></span></div>` : ''}
      <div class="cg-on-demand-note">Interval, failure threshold, startup grace, cooldown, restart limits, restart window, quarantine, and monitoring switches are bypassed. The per-check timeout still applies. ${job.mode === 'manual' ? 'No counters or actions are changed.' : 'Failure counters may change; no container action is ever performed.'}</div>
      <div class="cg-check-results cg-on-demand-results">
        ${rows.map(([check, item]) => renderResultRow(check, item, true)).join('') || '<div class="cg-help">No check plan is available.</div>'}
      </div>
      ${job.uses_unsaved ? '<div class="cg-unsaved-test"><strong>This check used unsaved values currently shown.</strong></div>' : ''}
      ${renderSimulation(name)}
    </div>`;
  }

  function quarantineLabel(runtime, serverTime) {
    const until = Number(runtime?.quarantined_until || 0);
    if (until === -1) return 'Quarantined indefinitely';
    if (until > Number(serverTime || Date.now() / 1000)) return `Quarantined until ${formatEpoch(until)}`;
    return '';
  }

  function isAttention(name) {
    const container = state.snapshot?.containers?.[name];
    const runtime = state.snapshot?.runtime?.containers?.[name] || {};
    const cfg = ensureContainerConfig(name);
    const quarantine = quarantineLabel(runtime, state.snapshot?.server_time);
    return Boolean(cfg.enabled && (quarantine || runtime.last_result === false || !container?.running));
  }

  function renderSummary() {
    if (!state.snapshot || !state.config) return;
    const names = Object.keys(state.snapshot.containers || {});
    const running = names.filter(name => state.snapshot.containers[name].running).length;
    const monitored = names.filter(name => ensureContainerConfig(name).enabled).length;
    const attention = names.filter(isAttention).length;
    $('#cg-count-all').textContent = String(names.length);
    $('#cg-count-running').textContent = String(running);
    $('#cg-count-monitored').textContent = String(monitored);
    $('#cg-count-alert').textContent = String(attention);
    const daemon = state.snapshot.daemon || {};
    if (!daemon.running) {
      $('#cg-daemon-status').textContent = 'Stopped';
    } else if (daemon.responsive === false) {
      const age = daemon.heartbeat_age == null ? 'no heartbeat' : `heartbeat ${daemon.heartbeat_age}s old`;
      $('#cg-daemon-status').textContent = `Running; heartbeat delayed (${daemon.pid}; ${age})`;
    } else {
      const age = daemon.heartbeat_age == null ? '' : ` · heartbeat ${daemon.heartbeat_age}s`;
      $('#cg-daemon-status').textContent = `Running (${daemon.pid})${age}`;
    }
  }

  function field(label, name, value, min, max, suffix = '') {
    return `<label class="cg-field"><span>${escapeHtml(label)}${suffix ? ` (${escapeHtml(suffix)})` : ''}</span><input type="number" min="${min}" max="${max}" value="${escapeHtml(value)}" data-field="${escapeHtml(name)}"></label>`;
  }

  function checkRow(name, label, enabled, body, testHint = '') {
    return `
      <div class="cg-check-row">
        <label class="cg-check-toggle"><input type="checkbox" data-field="checks.${name}.enabled" ${enabled ? 'checked' : ''}> ${escapeHtml(label)}</label>
        <div>${body}</div>
        <span class="cg-help">${escapeHtml(testHint)}</span>
      </div>`;
  }

  function targetModeControl(checkName, mode, automaticPreview = '') {
    return `<div class="cg-target-mode">
      <label class="cg-inline-field"><span>Target selection</span><select data-field="checks.${escapeHtml(checkName)}.target_mode">
        <option value="auto" ${mode !== 'manual' ? 'selected' : ''}>Automatic discovery</option>
        <option value="manual" ${mode === 'manual' ? 'selected' : ''}>Manual target</option>
      </select></label>
      <span class="cg-auto-preview"><b>Automatic target:</b> ${escapeHtml(automaticPreview || 'none discovered')}</span>
    </div>`;
  }

  function automaticWebPreview(container, scheme, fallbackHost = '') {
    const preferred = scheme === 'https' ? [443, 8443, 9443, 10443] : [80, 8080, 8000, 8888, 3000, 5000, 8008];
    const candidates = (container.ports || [])
      .filter(port => (port.protocol || 'tcp') === 'tcp')
      .map(port => {
        const containerPort = Number(port.container_port || 0);
        const rank = preferred.indexOf(containerPort);
        if (rank < 0) return null;
        const published = Boolean(port.published && Number(port.host_port || 0) > 0);
        const host = published ? '127.0.0.1' : (container.ips?.[0]?.ip || '');
        const effectivePort = published ? Number(port.host_port) : containerPort;
        return host && effectivePort ? { host, port: effectivePort, rank } : null;
      })
      .filter(Boolean)
      .sort((a, b) => a.rank - b.rank);
    const target = candidates[0] || { host: fallbackHost, port: 0 };
    if (!target.host) return '';
    const defaultPort = scheme === 'https' ? 443 : 80;
    return target.port && target.port !== defaultPort
      ? `${scheme}://${target.host}:${target.port}/`
      : `${scheme}://${target.host}/`;
  }

  function renderDependencies(name, cfg, containers, autoDependency) {
    const names = Object.keys(containers).filter(candidate => candidate !== name);
    const selected = new Set(cfg.dependencies || []);
    if (!names.length) return '<div class="cg-help">No other containers were found.</div>';
    return `<div class="cg-deps">${names.map(candidate => `
      <label><input type="checkbox" data-dependency="${encodedName(candidate)}" ${selected.has(candidate) ? 'checked' : ''}> ${escapeHtml(candidate)}${candidate === autoDependency ? ' <span class="cg-badge warn">auto network provider</span>' : ''}</label>
    `).join('')}</div>`;
  }

  function renderCard(name) {
    const container = state.snapshot.containers[name];
    const runtime = state.snapshot.runtime?.containers?.[name] || {};
    const job = state.snapshot.tests?.[name] || null;
    const jobActive = Boolean(job && ['queued', 'running', 'cancelling'].includes(job.status));
    const discovery = state.snapshot.discoveries?.[name] || { hosts: [], ports: [], auto_dependency: '' };
    const cfg = ensureContainerConfig(name);
    const dirty = hasUnsavedContainer(name);
    if (dirty) state.dirtyContainers.add(name); else state.dirtyContainers.delete(name);
    const containerSaveKey = `container:${name}`;
    const containerSaving = state.saveInFlight.has(containerSaveKey);
    const quarantine = quarantineLabel(runtime, state.snapshot.server_time);
    const attention = isAttention(name);
    const health = container.health || 'none';
    const dotClass = quarantine ? 'danger' : attention ? 'warning' : container.running ? 'running' : '';
    const cardClass = quarantine ? 'cg-quarantined' : attention ? 'cg-attention' : '';
    const ips = (container.ips || []).map(item => `${item.network}: ${item.ip}`).join(', ') || 'none discovered';
    const ports = (container.ports || []).map(port => {
      if (port.published) return `${port.host_ip || '0.0.0.0'}:${port.host_port} → ${port.container_port}/${port.protocol}`;
      return `${port.container_port}/${port.protocol} exposed`;
    }).join(', ') || 'none discovered';
    const autoDependency = discovery.auto_dependency || '';
    const deps = [...new Set([...(cfg.dependencies || []), ...(cfg.use_auto_dependency && autoDependency ? [autoDependency] : [])])];
    const detailsOpen = state.openCards.has(name) ? 'open' : '';
    const busy = state.busy.has(name) || jobActive;
    const encoded = encodedName(name);
    const lastResult = runtime.last_result;
    const lastMessage = quarantine || runtime.last_message || (cfg.enabled ? 'Waiting for first automatic check' : 'Automatic monitoring disabled');
    const lastClass = quarantine ? 'bad' : lastResult === true ? 'ok' : lastResult === false ? 'warn' : '';
    const failureCount = Number(runtime.consecutive_failures || 0);
    const source = ({ automatic: 'AUTO', 'automatic-action': 'AUTO ACTION', 'run-now': 'RUN NOW' })[runtime.last_check_source] || '';
    const automaticResults = renderCheckResults(runtime);
    const onDemand = renderOnDemand(name, job);

    const hostOptions = [`<option value="">Choose discovered host…</option>`, ...(discovery.hosts || []).map(item => `<option value="${escapeHtml(item.value)}">${escapeHtml(item.label)}</option>`)].join('');
    const portOptions = [`<option value="">Choose discovered port…</option>`, ...(discovery.ports || []).map(item => `<option value="${escapeHtml(`${item.host}|${item.port}`)}">${escapeHtml(item.label)}</option>`)].join('');
    const autoHost = discovery.hosts?.find(item => item.value !== '127.0.0.1')?.value || discovery.hosts?.[0]?.value || '';
    const autoPort = discovery.ports?.[0] || null;
    const autoTcpTarget = autoPort ? `${autoPort.host}:${autoPort.port}` : '';
    const autoHttpTarget = automaticWebPreview(container, 'http', autoHost);
    const autoHttpsTarget = automaticWebPreview(container, 'https', autoHost);
    const pingManual = cfg.checks.ping.target_mode === 'manual';
    const tcpManual = cfg.checks.tcp.target_mode === 'manual';
    const httpManual = cfg.checks.http.target_mode === 'manual';
    const httpsManual = cfg.checks.https.target_mode === 'manual';

    return `
      <article class="cg-card ${cardClass}" data-card-name="${encoded}">
        <div class="cg-card-head">
          <div class="cg-title-row">
            <span class="cg-status-dot ${dotClass}"></span>
            <h3 title="${escapeHtml(name)}">${escapeHtml(name)}</h3>
          </div>
          <div class="cg-badges">
            <span class="cg-badge ${container.running ? 'ok' : ''}">${escapeHtml(container.status)}</span>
            <span class="cg-badge ${health === 'healthy' ? 'ok' : health === 'unhealthy' ? 'bad' : ''}">health: ${escapeHtml(health)}</span>
            ${cfg.enabled ? '<span class="cg-badge ok">monitored</span>' : '<span class="cg-badge">not monitored</span>'}
            ${dirty ? '<span class="cg-badge warn">unsaved settings</span>' : ''}
            ${jobActive ? '<span class="cg-badge warn">check running</span>' : ''}
            ${quarantine ? '<span class="cg-badge bad">quarantined</span>' : ''}
          </div>
        </div>

        <div class="cg-card-meta">
          <div class="cg-meta-line"><span class="cg-meta-label">Image</span><span class="cg-meta-value">${escapeHtml(container.image)}</span></div>
          <div class="cg-meta-line"><span class="cg-meta-label">Network</span><span class="cg-meta-value">${escapeHtml(container.network_mode || 'default')}${autoDependency ? ` · provider: <strong>${escapeHtml(autoDependency)}</strong>` : ''}</span></div>
          <div class="cg-meta-line"><span class="cg-meta-label">IP addresses</span><span class="cg-meta-value">${escapeHtml(ips)}</span></div>
          <div class="cg-meta-line"><span class="cg-meta-label">Ports</span><span class="cg-meta-value">${escapeHtml(ports)}</span></div>
          <div class="cg-meta-line"><span class="cg-meta-label">Dependencies</span><span class="cg-meta-value">${deps.length ? deps.map(escapeHtml).join(', ') : 'none'}</span></div>
        </div>

        <div class="cg-health-strip">
          <span class="cg-health-text"><span class="cg-badge ${lastClass}">${lastResult === true ? 'PASS' : lastResult === false ? 'FAIL' : 'IDLE'}</span>${source ? ` <span class="cg-source">${source}</span>` : ''} ${escapeHtml(lastMessage)}</span>
          <span class="cg-failure-count">Automatic failures: ${failureCount} · Last automatic check: ${escapeHtml(formatEpoch(runtime.last_check))}</span>
        </div>
        ${automaticResults}
        ${onDemand}

        <div class="cg-action-row">
          <button class="cg-btn cg-btn-small" data-command="start" data-name="${encoded}" ${busy || container.running ? 'disabled' : ''}>Start safely</button>
          <button class="cg-btn cg-btn-small cg-btn-danger" data-command="stop" data-name="${encoded}" ${busy || !container.running ? 'disabled' : ''} title="Stops running dependents before this container">Stop safely</button>
          <button class="cg-btn cg-btn-small cg-btn-warning" data-command="restart" data-name="${encoded}" ${busy || !container.running ? 'disabled' : ''} title="Restarts this container and restores running dependents">Restart safely</button>
          <button class="cg-btn cg-btn-small cg-btn-primary" data-start-test data-name="${encoded}" ${busy ? 'disabled' : ''} title="Runs all currently enabled checks once, immediately. Only Timeout applies; no counters or actions change.">Test checks</button>
          <button class="cg-btn cg-btn-small" data-run-now data-name="${encoded}" ${busy ? 'disabled' : ''} title="Runs an automatic-style check now. It may update the failure counter but never starts or restarts the container.">Run check now</button>
          <button class="cg-btn cg-btn-small" data-simulate data-name="${encoded}" ${jobActive ? 'disabled' : ''} title="Assumes the next automatic check fails and shows the policy decision without changing anything.">Simulate failure policy</button>
          ${jobActive ? `<button class="cg-btn cg-btn-small cg-btn-danger" data-cancel-test data-name="${encoded}">Cancel test</button>` : ''}
          ${quarantine ? `<button class="cg-btn cg-btn-small" data-command="unquarantine" data-name="${encoded}" ${busy ? 'disabled' : ''}>Clear quarantine</button>` : ''}
          <button class="cg-btn cg-btn-small cg-btn-primary ${dirty ? 'cg-unsaved-button' : ''}" data-save-container data-name="${encoded}" ${containerSaving ? 'disabled' : ''}>${containerSaving ? 'Saving…' : dirty ? 'Save settings *' : 'Save settings'}</button>
          <span class="cg-action-help">Nothing autosaves. Test checks uses the values currently shown, including unsaved values. Press Save settings for this container or Save configuration for all containers.</span>
        </div>

        <details class="cg-config" data-config-name="${encoded}" ${detailsOpen}>
          <summary>Checks, actions, timing, and dependencies</summary>
          <div class="cg-config-body">
            <div class="cg-section">
              <div class="cg-field-grid">
                <label class="cg-field"><span>Monitor this container</span><select data-field="enabled"><option value="true" ${cfg.enabled ? 'selected' : ''}>Enabled</option><option value="false" ${!cfg.enabled ? 'selected' : ''}>Disabled</option></select></label>
                <label class="cg-field"><span>Automatic action</span><select data-field="restart_enabled"><option value="true" ${cfg.restart_enabled ? 'selected' : ''}>Restart/start</option><option value="false" ${!cfg.restart_enabled ? 'selected' : ''}>Report only</option></select></label>
                <label class="cg-field"><span>Check result policy</span><select data-field="check_mode"><option value="all" ${cfg.check_mode !== 'any' ? 'selected' : ''}>All enabled checks pass</option><option value="any" ${cfg.check_mode === 'any' ? 'selected' : ''}>Any enabled check passes</option></select></label>
              </div>
            </div>

            <div class="cg-section">
              <h4>Timing and restart guardrails</h4>
              <div class="cg-field-grid">
                ${field('Check interval', 'check_interval', cfg.check_interval, 5, 86400, 'seconds')}
                ${field('Timeout', 'timeout', cfg.timeout, 1, 120, 'seconds')}
                ${field('Failures before action', 'failures_before_action', cfg.failures_before_action, 1, 100)}
                ${field('Startup grace', 'startup_grace', cfg.startup_grace, 0, 86400, 'seconds')}
                ${field('Restart cooldown', 'restart_cooldown', cfg.restart_cooldown, 0, 86400, 'seconds')}
                ${field('Maximum restarts', 'maximum_restarts', cfg.maximum_restarts, 0, 1000, '0 = unlimited')}
                ${field('Restart window', 'restart_window', cfg.restart_window, 60, 604800, 'seconds')}
                ${field('Quarantine duration', 'quarantine_duration', cfg.quarantine_duration, 0, 2592000, '0 = indefinite')}
              </div>
              <p class="cg-help"><strong>Test checks ignores every value in this section except Timeout.</strong> Automatic monitoring uses all of them.</p>
            </div>

            <div class="cg-section">
              <h4>Health checks</h4>
              ${checkRow('docker_state', 'Docker state', cfg.checks.docker_state.enabled, '<span class="cg-help">Checks whether Docker reports the container as running.</span>', 'No target required')}
              ${checkRow('docker_health', 'Docker health', cfg.checks.docker_health.enabled, '<span class="cg-help">Uses the image/container HEALTHCHECK result.</span>', health === 'none' ? 'No HEALTHCHECK detected' : `Current: ${health}`)}
              ${checkRow('ping', 'Ping', cfg.checks.ping.enabled, `${targetModeControl('ping', cfg.checks.ping.target_mode, autoHost)}<div class="cg-check-extra single"><label class="cg-inline-field"><span>Manual host or IP address</span><input type="text" spellcheck="false" autocapitalize="none" placeholder="Example: 192.168.0.191" value="${escapeHtml(cfg.checks.ping.host || '')}" data-field="checks.ping.host" ${pingManual ? '' : 'disabled'}></label><label class="cg-inline-field"><span>Copy a discovered host into manual mode</span><select data-discovery-host>${hostOptions}</select></label></div>`, 'ICMP from Unraid host')}
              ${checkRow('tcp', 'TCP port', cfg.checks.tcp.enabled, `${targetModeControl('tcp', cfg.checks.tcp.target_mode, autoTcpTarget)}<div class="cg-check-extra"><label class="cg-inline-field"><span>Manual host or IP address</span><input type="text" spellcheck="false" autocapitalize="none" placeholder="Example: 192.168.0.191" value="${escapeHtml(cfg.checks.tcp.host || '')}" data-field="checks.tcp.host" ${tcpManual ? '' : 'disabled'}></label><label class="cg-inline-field"><span>Manual TCP port</span><input type="number" min="1" max="65535" placeholder="Example: 53" value="${escapeHtml(cfg.checks.tcp.port || '')}" data-field="checks.tcp.port" ${tcpManual ? '' : 'disabled'}></label></div><div class="cg-discovery"><label class="cg-inline-field cg-grow"><span>Copy a discovered port into manual mode</span><select data-discovery-port>${portOptions}</select></label></div>`, 'Opens a TCP socket')}
              ${checkRow('http', 'HTTP', cfg.checks.http.enabled, `${targetModeControl('http', cfg.checks.http.target_mode, autoHttpTarget)}<div class="cg-check-extra"><label class="cg-inline-field"><span>Manual HTTP URL</span><input type="url" spellcheck="false" autocapitalize="none" placeholder="http://host:port/path" value="${escapeHtml(cfg.checks.http.url || '')}" data-field="checks.http.url" ${httpManual ? '' : 'disabled'}></label><label class="cg-inline-field"><span>Expected codes</span><input type="text" spellcheck="false" value="${escapeHtml(cfg.checks.http.expected_codes || '200-399')}" data-field="checks.http.expected_codes" title="Examples: 200-399 or 200,204,301-302"></label></div>`, 'HTTP response from Unraid host')}
              ${checkRow('https', 'HTTPS', cfg.checks.https.enabled, `${targetModeControl('https', cfg.checks.https.target_mode, autoHttpsTarget)}<div class="cg-check-extra"><label class="cg-inline-field"><span>Manual HTTPS URL</span><input type="url" spellcheck="false" autocapitalize="none" placeholder="https://host:port/path" value="${escapeHtml(cfg.checks.https.url || '')}" data-field="checks.https.url" ${httpsManual ? '' : 'disabled'}></label><label class="cg-inline-field"><span>Expected codes</span><input type="text" spellcheck="false" value="${escapeHtml(cfg.checks.https.expected_codes || '200-399')}" data-field="checks.https.expected_codes"></label></div><label class="cg-check-toggle cg-tls-toggle"><input type="checkbox" data-field="checks.https.verify_tls" ${cfg.checks.https.verify_tls ? 'checked' : ''}> Verify TLS certificate</label>`, 'Self-signed TLS works when verification is off')}
              <div class="cg-discovery"><button type="button" class="cg-btn cg-btn-small" data-autofill data-name="${encoded}">Auto-fill first IP and port</button></div>
            </div>

            <div class="cg-section">
              <h4>Dependencies and safe order</h4>
              <label class="cg-check-toggle"><input type="checkbox" data-field="use_auto_dependency" ${cfg.use_auto_dependency ? 'checked' : ''}> Automatically require the Docker network provider</label>
              <p class="cg-help">Detected from <code>network_mode: container:&lt;provider&gt;</code>. Explicit dependencies are started first and must become ready. Stops occur in reverse order.</p>
              ${renderDependencies(name, cfg, state.snapshot.containers, autoDependency)}
            </div>
            <div class="cg-container-save-bar">
              <span>${dirty ? 'This container has unsaved settings.' : 'This container is saved.'}</span>
              <button type="button" class="cg-btn cg-btn-primary ${dirty ? 'cg-unsaved-button' : ''}" data-save-container data-name="${encoded}" ${containerSaving ? 'disabled' : ''}>${containerSaving ? 'Saving…' : dirty ? 'Save settings *' : 'Save settings'}</button>
            </div>
          </div>
        </details>
      </article>`;
  }

  function matchesFilter(name) {
    const term = ($('#cg-filter').value || '').trim().toLowerCase();
    const stateFilter = $('#cg-status-filter').value;
    const container = state.snapshot.containers[name];
    const cfg = ensureContainerConfig(name);
    const haystack = [name, container.image, container.status, container.health, container.network_mode, ...(container.ips || []).map(v => v.ip)].join(' ').toLowerCase();
    if (term && !haystack.includes(term)) return false;
    if (stateFilter === 'running' && !container.running) return false;
    if (stateFilter === 'stopped' && container.running) return false;
    if (stateFilter === 'monitored' && !cfg.enabled) return false;
    if (stateFilter === 'attention' && !isAttention(name)) return false;
    return true;
  }

  function configEditInProgress() {
    const active = document.activeElement;
    return Boolean(active?.closest?.('.cg-config'));
  }

  function renderContainers({ force = false } = {}) {
    if (!state.snapshot || !state.config) return;
    if (!force && configEditInProgress()) {
      state.renderPending = true;
      renderSummary();
      return;
    }
    state.renderPending = false;
    const names = Object.keys(state.snapshot.containers || {}).filter(matchesFilter);
    loading.hidden = true;
    containerRoot.innerHTML = names.length ? names.map(renderCard).join('') : '<div class="cg-empty">No containers match the current filter.</div>';
    updateSaveStatus();
  }

  async function refreshSnapshot({ forceConfigReload = false } = {}) {
    if (!state.snapshot) loading.hidden = false;
    const data = await request(null, '?action=snapshot');
    state.snapshot = data;

    if (!state.config || forceConfigReload) {
      state.config = clone(data.config);
      state.persistedConfig = clone(data.config);
      state.globalDirty = false;
      state.dirtyContainers.clear();
    } else {
      const serverConfig = clone(data.config);
      if (!state.globalDirty) {
        state.config.global_enabled = Boolean(serverConfig.global_enabled);
        state.config.ui_refresh_seconds = Number(serverConfig.ui_refresh_seconds || 5);
      }
      if (!state.config.containers) state.config.containers = {};
      for (const [name, savedContainer] of Object.entries(serverConfig.containers || {})) {
        if (!state.dirtyContainers.has(name)) state.config.containers[name] = clone(savedContainer);
      }
      state.persistedConfig = serverConfig;
    }

    $('#cg-global-enabled').checked = Boolean(state.config.global_enabled);
    $('#cg-ui-refresh').value = String(state.config.ui_refresh_seconds || 5);
    renderContainers();
    for (const [name, job] of Object.entries(data.tests || {})) {
      if (['queued', 'running', 'cancelling'].includes(job?.status)) pollOnDemand(name);
    }
    scheduleRefresh();
  }

  function scheduleRefresh() {
    if (state.timer) window.clearInterval(state.timer);
    const seconds = Math.max(2, Number(state.config?.ui_refresh_seconds || 5));
    state.timer = window.setInterval(async () => {
      if (state.busy.size) return;
      try { await refreshSnapshot(); } catch (error) { showBanner(error.message, 'error', 0); }
    }, seconds * 1000);
  }

  async function saveContainer(name) {
    if (!state.config) return;
    const key = `container:${name}`;
    if (state.saveInFlight.has(key) || state.saveInFlight.has('all')) return;
    const draft = clone(ensureContainerConfig(name));
    const revision = ++state.revision;
    state.saveInFlight.add(key);
    renderContainers({ force: !configEditInProgress() });
    setSaveStatus(`${name}: saving…`, 'saving');
    try {
      const result = await request({
        action: 'save_container',
        container: name,
        container_config: draft,
        client_revision: revision,
      });
      if (result.operation !== 'save_container' || result.container !== name || Number(result.client_revision ?? -1) !== revision || !result.container_config || !result.config) {
        throw new Error('The server did not confirm the per-container save.');
      }
      state.persistedConfig = clone(result.config);
      if (state.snapshot) {
        state.snapshot.config = clone(result.config);
        if (result.runtime) state.snapshot.runtime = clone(result.runtime);
      }
      if (JSON.stringify(ensureContainerConfig(name)) === JSON.stringify(draft)) {
        state.config.containers[name] = clone(result.container_config);
        clearContainerDirty(name, Number(result.saved_at || 0));
      } else {
        state.dirtyContainers.add(name);
        updateSaveStatus(`${name} was saved, but newer unsaved edits remain`);
      }
      showBanner(result.message || `${name}: settings saved.`, 'success', 7000);
    } catch (error) {
      state.dirtyContainers.add(name);
      setSaveStatus(`${name}: save failed — changes remain unsaved`, 'error');
      throw error;
    } finally {
      state.saveInFlight.delete(key);
      renderContainers({ force: !configEditInProgress() });
    }
  }

  async function saveAllConfig() {
    if (!state.config || state.saveInFlight.size > 0) return;
    const draft = clone(state.config);
    const revision = ++state.revision;
    state.saveInFlight.add('all');
    const button = $('#cg-save');
    if (button) button.disabled = true;
    setSaveStatus('Saving all container settings…', 'saving');
    try {
      const result = await request({ action: 'save', config: draft, client_revision: revision });
      if (result.operation !== 'save' || Number(result.client_revision ?? -1) !== revision || !result.config) {
        throw new Error('The server did not confirm the complete configuration save.');
      }
      state.persistedConfig = clone(result.config);
      if (state.snapshot) {
        state.snapshot.config = clone(result.config);
        if (result.runtime) state.snapshot.runtime = clone(result.runtime);
      }
      if (JSON.stringify(state.config) === JSON.stringify(draft)) {
        state.config = clone(result.config);
        clearAllDirty(Number(result.saved_at || 0));
      } else {
        state.globalDirty = Boolean(
          Boolean(state.config.global_enabled) !== Boolean(result.config.global_enabled)
          || Number(state.config.ui_refresh_seconds || 5) !== Number(result.config.ui_refresh_seconds || 5)
        );
        state.dirtyContainers.clear();
        for (const name of Object.keys(state.config.containers || {})) {
          const current = state.config.containers[name] || defaultContainerConfig();
          const persisted = result.config.containers?.[name] || defaultContainerConfig();
          if (JSON.stringify(current) !== JSON.stringify(persisted)) state.dirtyContainers.add(name);
        }
        updateSaveStatus('Configuration saved, but newer unsaved edits remain');
      }
      showBanner(result.message || 'All configuration settings saved.', 'success', 7000);
    } catch (error) {
      setSaveStatus('Save failed — all browser edits remain unsaved', 'error');
      throw error;
    } finally {
      state.saveInFlight.delete('all');
      if (button) button.disabled = false;
      renderContainers({ force: !configEditInProgress() });
    }
  }

  async function runCommand(name, command) {
    state.busy.add(name);
    renderContainers();
    try {
      const result = await request({ action: 'command', container: name, command });
      showBanner(result.message || `${command} completed.`, 'success', 7000);
      await refreshSnapshot();
    } catch (error) {
      showBanner(error.message, 'error', 0);
    } finally {
      state.busy.delete(name);
      renderContainers();
    }
  }

  function wait(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
  }

  function updateJobSnapshot(name, job) {
    if (!state.snapshot.tests) state.snapshot.tests = {};
    if (job) state.snapshot.tests[name] = clone(job); else delete state.snapshot.tests[name];
    if (job?.runtime) {
      if (!state.snapshot.runtime) state.snapshot.runtime = { containers: {} };
      if (!state.snapshot.runtime.containers) state.snapshot.runtime.containers = {};
      state.snapshot.runtime.containers[name] = clone(job.runtime);
    }
  }

  async function pollOnDemand(name) {
    if (state.testPolling.has(name)) return;
    state.testPolling.add(name);
    try {
      while (true) {
        const result = await request(null, `?action=test_status&container=${encodeURIComponent(name)}`);
        const job = result.job || null;
        updateJobSnapshot(name, job);
        renderContainers();
        if (!job || !['queued', 'running', 'cancelling'].includes(job.status)) {
          if (job && !state.notifiedJobs.has(job.job_id)) {
            state.notifiedJobs.add(job.job_id);
            const label = job.mode === 'run_now' ? 'Run check now' : 'Manual test';
            if (job.status === 'completed') {
              showBanner(`${name}: ${label} ${job.ok ? 'PASSED' : 'FAILED'}.`, job.ok ? 'success' : 'error', 10000);
            } else if (job.status === 'cancelled') {
              showBanner(`${name}: ${label} cancelled.`, 'info', 7000);
            } else {
              showBanner(`${name}: ${job.message || `${label} failed.`}`, 'error', 0);
            }
          }
          break;
        }
        await wait(500);
      }
    } catch (error) {
      showBanner(error.message, 'error', 0);
    } finally {
      state.testPolling.delete(name);
    }
  }

  async function startOnDemand(name, mode = 'manual') {
    state.busy.add(name);
    state.openCards.add(name);
    renderContainers();
    try {
      const action = mode === 'run_now' ? 'run_now_start' : 'test_start';
      const result = await request({ action, container: name, container_config: ensureContainerConfig(name) });
      updateJobSnapshot(name, result.job);
      renderContainers();
      showBanner(result.message || 'On-demand check started.', 'success', 6000);
      pollOnDemand(name);
    } catch (error) {
      showBanner(error.message, 'error', 0);
    } finally {
      state.busy.delete(name);
      renderContainers();
    }
  }

  async function cancelOnDemand(name) {
    try {
      const result = await request({ action: 'test_cancel', container: name });
      updateJobSnapshot(name, result.job);
      renderContainers();
      showBanner(result.message || 'Cancellation requested.', 'info', 5000);
      pollOnDemand(name);
    } catch (error) {
      showBanner(error.message, 'error', 0);
    }
  }

  async function simulatePolicy(name) {
    try {
      const result = await request({ action: 'simulate', container: name, container_config: ensureContainerConfig(name) });
      state.simulations.set(name, clone(result.simulation));
      renderContainers();
    } catch (error) {
      showBanner(error.message, 'error', 0);
    }
  }

  function discardDraft(name) {
    if (!state.config.containers) state.config.containers = {};
    state.config.containers[name] = clone(state.persistedConfig?.containers?.[name] || defaultContainerConfig());
    state.dirtyContainers.delete(name);
    updateSaveStatus();
    renderContainers({ force: !configEditInProgress() });
    showBanner(`${name}: unsaved container changes discarded.`, 'success', 5000);
  }

  function autofill(name, card) {
    const discovery = state.snapshot.discoveries?.[name] || {};
    const cfg = ensureContainerConfig(name);
    const firstHost = discovery.hosts?.find(item => item.value !== '127.0.0.1')?.value || discovery.hosts?.[0]?.value || '';
    const firstPort = discovery.ports?.[0] || null;
    if (firstHost) {
      cfg.checks.ping.target_mode = 'manual';
      cfg.checks.ping.host = firstHost;
    }
    if (firstPort) {
      cfg.checks.tcp.target_mode = 'manual';
      cfg.checks.tcp.host = firstPort.host;
      cfg.checks.tcp.port = Number(firstPort.port);
      cfg.checks.http.target_mode = 'manual';
      cfg.checks.https.target_mode = 'manual';
      if (!cfg.checks.http.url) cfg.checks.http.url = `http://${firstPort.host}:${firstPort.port}/`;
      if (!cfg.checks.https.url) cfg.checks.https.url = `https://${firstPort.host}:${firstPort.port}/`;
    }
    markContainerDirty(name);
    state.openCards.add(name);
    renderContainers({ force: true });
    card?.scrollIntoView({ block: 'nearest' });
  }


  async function refreshLog() {
    const result = await request(null, '?action=log&lines=200');
    $('#cg-log').textContent = (result.lines || []).join('\n') || 'No log entries yet.';
  }

  function updateFieldFromControl(target, configDetails) {
    const name = decodedName(configDetails.dataset.configName);
    const cfg = ensureContainerConfig(name);
    const path = target.dataset.field;
    let value;
    if (target.type === 'checkbox') value = target.checked;
    else if (target.type === 'number') value = target.value === '' ? 0 : Number(target.value);
    else if (target.value === 'true' || target.value === 'false') value = target.value === 'true';
    else value = target.value;
    setPath(cfg, path, value);
    markContainerDirty(name);
    if (path === 'enabled') renderSummary();
    if (path.endsWith('.target_mode')) {
      state.openCards.add(name);
      renderContainers({ force: true });
    }
  }

  // Capture typed values immediately. Waiting for the change event allowed the
  // five-second dashboard refresh to redraw a focused field and erase text.
  document.addEventListener('input', event => {
    const target = event.target;
    if (!target.matches?.('[data-field]') || target.type === 'checkbox' || target.tagName === 'SELECT') return;
    const configDetails = target.closest('[data-config-name]');
    if (configDetails) updateFieldFromControl(target, configDetails);
  });

  document.addEventListener('change', event => {
    const target = event.target;
    const configDetails = target.closest('[data-config-name]');
    if (configDetails) {
      const name = decodedName(configDetails.dataset.configName);
      const cfg = ensureContainerConfig(name);
      if (target.matches('[data-field]')) {
        updateFieldFromControl(target, configDetails);
      }
      if (target.matches('[data-dependency]')) {
        const dependency = decodedName(target.dataset.dependency);
        const selected = new Set(cfg.dependencies || []);
        if (target.checked) selected.add(dependency); else selected.delete(dependency);
        cfg.dependencies = [...selected];
        markContainerDirty(name);
      }
      if (target.matches('[data-discovery-host]') && target.value) {
        cfg.checks.ping.target_mode = 'manual';
        cfg.checks.ping.host = target.value;
        markContainerDirty(name);
        state.openCards.add(name);
        renderContainers({ force: true });
      }
      if (target.matches('[data-discovery-port]') && target.value) {
        const [host, port] = target.value.split('|');
        cfg.checks.tcp.target_mode = 'manual';
        cfg.checks.tcp.host = host;
        cfg.checks.tcp.port = Number(port);
        markContainerDirty(name);
        state.openCards.add(name);
        renderContainers({ force: true });
      }
    }
  });


  document.addEventListener('focusout', () => {
    window.setTimeout(() => {
      if (state.renderPending && !configEditInProgress()) renderContainers({ force: true });
    }, 0);
  });

  document.addEventListener('toggle', event => {
    const details = event.target;
    if (!details.matches?.('[data-config-name]')) return;
    const name = decodedName(details.dataset.configName);
    if (details.open) state.openCards.add(name); else state.openCards.delete(name);
  }, true);

  document.addEventListener('click', event => {
    const commandButton = event.target.closest('[data-command]');
    if (commandButton) {
      runCommand(decodedName(commandButton.dataset.name), commandButton.dataset.command);
      return;
    }
    const testButton = event.target.closest('[data-start-test]');
    if (testButton) {
      startOnDemand(decodedName(testButton.dataset.name), 'manual');
      return;
    }
    const runNowButton = event.target.closest('[data-run-now]');
    if (runNowButton) {
      startOnDemand(decodedName(runNowButton.dataset.name), 'run_now');
      return;
    }
    const cancelButton = event.target.closest('[data-cancel-test]');
    if (cancelButton) {
      cancelOnDemand(decodedName(cancelButton.dataset.name));
      return;
    }
    const simulateButton = event.target.closest('[data-simulate]');
    if (simulateButton) {
      simulatePolicy(decodedName(simulateButton.dataset.name));
      return;
    }
    const closeSimulation = event.target.closest('[data-close-simulation]');
    if (closeSimulation) {
      state.simulations.delete(decodedName(closeSimulation.dataset.name));
      renderContainers();
      return;
    }
    const saveContainerButton = event.target.closest('[data-save-container]');
    if (saveContainerButton) {
      saveContainer(decodedName(saveContainerButton.dataset.name)).catch(error => showBanner(error.message, 'error', 0));
      return;
    }
    const discardDraftButton = event.target.closest('[data-discard-draft]');
    if (discardDraftButton) {
      discardDraft(decodedName(discardDraftButton.dataset.name));
      return;
    }
    const autofillButton = event.target.closest('[data-autofill]');
    if (autofillButton) {
      const name = decodedName(autofillButton.dataset.name);
      autofill(name, autofillButton.closest('.cg-card'));
    }
  });

  $('#cg-global-enabled').addEventListener('change', event => {
    state.config.global_enabled = event.target.checked;
    markGlobalDirty();
  });
  $('#cg-ui-refresh').addEventListener('change', event => {
    state.config.ui_refresh_seconds = Number(event.target.value);
    markGlobalDirty();
    scheduleRefresh();
  });
  $('#cg-filter').addEventListener('input', renderContainers);
  $('#cg-status-filter').addEventListener('change', renderContainers);
  $('#cg-save').addEventListener('click', () => saveAllConfig().catch(error => showBanner(error.message, 'error', 0)));
  $('#cg-refresh').addEventListener('click', () => refreshSnapshot().then(() => { if (hasAnyUnsavedChanges()) showBanner('Runtime refreshed. Unsaved form edits were preserved.', 'success', 5000); }).catch(error => showBanner(error.message, 'error', 0)));
  $('#cg-log-refresh').addEventListener('click', () => refreshLog().catch(error => showBanner(error.message, 'error', 0)));
  $('#cg-daemon-restart').addEventListener('click', async event => {
    event.target.disabled = true;
    try {
      const result = await request({ action: 'daemon', daemon_action: 'restart' });
      showBanner(result.message || 'VFE monitor service restarted.', 'success');
      await refreshSnapshot();
    } catch (error) {
      showBanner(error.message, 'error', 0);
    } finally {
      event.target.disabled = false;
    }
  });

  window.addEventListener('beforeunload', event => {
    if (!hasAnyUnsavedChanges() && state.saveInFlight.size === 0) return;
    event.preventDefault();
    event.returnValue = '';
  });

  refreshSnapshot({ forceConfigReload: true })
    .then(refreshLog)
    .catch(error => {
      loading.hidden = true;
      showBanner(error.message, 'error', 0);
    });
})();
