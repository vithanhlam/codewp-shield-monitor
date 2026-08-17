import qrcode from 'qrcode-generator';

const target = document.getElementById('shield_monitor-qr');

if (target && target.dataset.payload) {
  try {
    const qr = qrcode(0, 'M');
    qr.addData(target.dataset.payload, 'Byte');
    qr.make();
    target.innerHTML = qr.createSvgTag(5, 4);
  } catch (error) {
    target.textContent = 'Unable to generate QR code.';
  }
}

const scanButton = document.getElementById('shield_monitor-malware-scan-start');
const progressWrap = document.getElementById('shield_monitor-malware-progress');
const progressBar = progressWrap ? progressWrap.querySelector('.sp-scan-progress-bar') : null;
const progressFill = progressBar ? progressBar.querySelector('i') : null;
const progressLabel = document.getElementById('shield_monitor-malware-progress-label');
const progressDetail = document.getElementById('shield_monitor-malware-progress-detail');
const adminConfig = window.ShieldMonitorAdmin || {};

const tabLinks = Array.from(document.querySelectorAll('.shield_monitor-nav a[href^="#"]'));
const tabPanels = [
  document.getElementById('overview'),
  document.querySelector('.sp-score-tasks'),
  document.getElementById('login-access'),
  document.getElementById('advanced-hardening'),
  document.getElementById('database-security'),
  document.getElementById('integrity'),
  document.getElementById('malware'),
  document.getElementById('threats'),
  document.getElementById('logs'),
  document.getElementById('recommendations'),
  document.getElementById('connect'),
  document.getElementById('settings'),
  document.getElementById('notifications'),
].filter(Boolean);

const showDashboardTab = (tabId = 'overview', updateHash = true) => {
  const normalized = tabLinks.some((link) => link.getAttribute('href') === `#${tabId}`) ? tabId : 'overview';
  tabPanels.forEach((panel) => {
    const belongsToOverview = normalized === 'overview' && (panel.id === 'overview' || panel.classList.contains('sp-score-tasks'));
    panel.hidden = !(belongsToOverview || panel.id === normalized);
  });
  tabLinks.forEach((link) => {
    const active = link.getAttribute('href') === `#${normalized}`;
    link.classList.toggle('is-active', active);
    link.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  if (updateHash && window.history.replaceState) {
    window.history.replaceState(null, '', `#${normalized}`);
  }
};

if (tabLinks.length) {
  tabLinks.forEach((link) => {
    link.setAttribute('role', 'tab');
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showDashboardTab(link.getAttribute('href').slice(1));
    });
  });
  const initialTab = window.location.hash ? window.location.hash.slice(1) : 'overview';
  showDashboardTab(initialTab, Boolean(window.location.hash));
}

const updateScanProgress = (payload = {}, fallback = '') => {
  const progress = Math.max(0, Math.min(100, Number(payload.progress || 0)));
  if (progressWrap) {
    progressWrap.hidden = false;
  }
  if (progressFill) {
    progressFill.style.width = `${progress}%`;
  }
  if (progressBar) {
    progressBar.setAttribute('aria-valuenow', String(progress));
  }
  if (progressLabel) {
    progressLabel.textContent = `${progress}%`;
  }
  if (progressDetail) {
    const files = payload.files_total ? `${payload.files_done || 0}/${payload.files_total} files` : '';
    const database = payload.db_total ? `${payload.db_done || 0}/${payload.db_total} database rows` : '';
    const limited = payload.file_limited ? 'File queue was capped to protect server resources.' : '';
    progressDetail.textContent = [payload.message || fallback, files, database, limited].filter(Boolean).join(' | ');
  }
};

const scanRequest = async (action) => {
  const body = new URLSearchParams();
  body.set('action', action);
  body.set('nonce', adminConfig.nonce || '');

  const response = await fetch(adminConfig.ajaxUrl || ajaxurl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body,
  });
  const data = await response.json();
  if (!response.ok || !data.success) {
    throw new Error((data.data && data.data.message) || adminConfig.i18n?.scanFailed || 'Scan failed.');
  }
  return data.data || {};
};

const runScanStep = async () => {
  const payload = await scanRequest('shield_monitor_malware_scan_step');
  updateScanProgress(payload, adminConfig.i18n?.scanRunning);
  if (payload.status === 'completed') {
    updateScanProgress({ ...payload, progress: 100, message: adminConfig.i18n?.scanComplete }, adminConfig.i18n?.scanComplete);
    window.setTimeout(() => {
      window.location.hash = 'malware';
      window.location.reload();
    }, 900);
    return;
  }
  window.setTimeout(runScanStep, 350);
};

if (scanButton && progressWrap && adminConfig.ajaxUrl) {
  scanButton.addEventListener('click', async () => {
    scanButton.disabled = true;
    scanButton.classList.add('is-busy');
    updateScanProgress({ progress: 0, message: adminConfig.i18n?.scanStarting }, adminConfig.i18n?.scanStarting);
    try {
      const payload = await scanRequest('shield_monitor_malware_scan_start');
      updateScanProgress(payload, adminConfig.i18n?.scanRunning);
      window.setTimeout(runScanStep, 350);
    } catch (error) {
      updateScanProgress({ progress: 0, message: error.message || adminConfig.i18n?.scanFailed }, adminConfig.i18n?.scanFailed);
      scanButton.disabled = false;
      scanButton.classList.remove('is-busy');
    }
  });
}

const historyTable = document.getElementById('shield_monitor-history-table');
const historyFilter = document.getElementById('shield_monitor-history-filter');
const historySearch = document.getElementById('shield_monitor-history-search');
const historyEmpty = document.getElementById('shield_monitor-history-empty');

const filterHistory = () => {
  if (!historyTable) {
    return;
  }
  const category = historyFilter ? historyFilter.value : 'all';
  const query = historySearch ? historySearch.value.trim().toLowerCase() : '';
  let visible = 0;
  historyTable.querySelectorAll('tbody tr').forEach((row) => {
    const categoryMatch = category === 'all' || row.dataset.category === category;
    const searchMatch = !query || (row.dataset.search || '').includes(query);
    const show = categoryMatch && searchMatch;
    row.hidden = !show;
    if (show) {
      visible += 1;
    }
  });
  if (historyEmpty) {
    historyEmpty.hidden = visible > 0;
  }
};

if (historyTable) {
  historyFilter?.addEventListener('change', filterHistory);
  historySearch?.addEventListener('input', filterHistory);
}
