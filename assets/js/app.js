/* Storage Panel — front-end application logic. Vanilla JS, no framework. */
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const API = 'api/';

  // ---------------------------------------------------------------
  // Fetch helper
  // ---------------------------------------------------------------
  async function api(path, opts = {}) {
    opts.headers = Object.assign({}, opts.headers, {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': CSRF,
    });
    if (opts.body && !(opts.body instanceof FormData)) {
      opts.headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(API + path, opts);
    let data;
    try { data = await res.json(); } catch { data = { ok: false, error: 'Invalid server response.' }; }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Request failed.');
    }
    return data;
  }
  function apiGet(path) { return api(path); }
  function apiPost(path, body) { return api(path, { method: 'POST', body: JSON.stringify(body || {}) }); }

  // ---------------------------------------------------------------
  // Toasts
  // ---------------------------------------------------------------
  function toast(message, type = 'info') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = message;
    document.getElementById('toastContainer').appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  // ---------------------------------------------------------------
  // Modal helper
  // ---------------------------------------------------------------
  const overlay = document.getElementById('modalOverlay');
  const modalBox = document.getElementById('modalBox');
  function openModal(html, opts = {}) {
    modalBox.className = 'modal' + (opts.large ? ' modal-lg' : '');
    modalBox.innerHTML = html;
    overlay.classList.remove('hidden');
    Icons.renderAll(modalBox);
    return modalBox;
  }
  function closeModal() {
    overlay.classList.add('hidden');
    modalBox.innerHTML = '';
  }
  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

  function confirmDialog(title, message, opts = {}) {
    return new Promise((resolve) => {
      openModal(`
        <h2>${escapeHtml(title)}</h2>
        <p>${message}</p>
        <div class="modal-actions">
          <button class="btn" id="mCancel">Cancel</button>
          <button class="btn ${opts.danger ? 'btn-danger' : 'btn-primary'}" id="mOk">${escapeHtml(opts.okLabel || 'Confirm')}</button>
        </div>
      `);
      document.getElementById('mCancel').onclick = () => { closeModal(); resolve(false); };
      document.getElementById('mOk').onclick = () => { closeModal(); resolve(true); };
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function fmtBytes(bytes) {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
  }
  function fmtDate(ts) {
    const d = new Date(ts * 1000);
    return d.toLocaleString();
  }

  // ---------------------------------------------------------------
  // View / nav switching
  // ---------------------------------------------------------------
  const views = ['dashboard', 'files', 'storage', 'system', 'activity', 'settings'];
  function switchView(view) {
    views.forEach((v) => document.getElementById('view-' + v).classList.toggle('hidden', v !== view));
    document.querySelectorAll('.nav-item[data-view]').forEach((el) => el.classList.toggle('active', el.dataset.view === view));
    document.getElementById('viewTitle').textContent = view.charAt(0).toUpperCase() + view.slice(1);
    document.getElementById('sidebar').classList.remove('open');

    if (view === 'dashboard') loadDashboard();
    if (view === 'files') loadDirectory(state.currentPath);
    if (view === 'storage') loadStorageView();
    if (view === 'system') loadSystemView();
    if (view === 'activity') loadActivity();
    if (view === 'settings') loadSettings();
  }
  document.querySelectorAll('.nav-item[data-view]').forEach((el) => {
    el.addEventListener('click', (e) => { e.preventDefault(); switchView(el.dataset.view); });
  });
  document.getElementById('sidebarToggle').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
  });

  // ---------------------------------------------------------------
  // Clock
  // ---------------------------------------------------------------
  function tickClock() {
    document.getElementById('serverClock').textContent = new Date().toLocaleTimeString();
  }
  setInterval(tickClock, 1000);
  tickClock();

  // =================================================================
  // DASHBOARD
  // =================================================================
  async function loadDashboard() {
    try {
      const data = await apiGet('stats.php');
      const sys = data.system;

      document.getElementById('dashUsed').textContent = sys.disk.percent + '%';
      document.getElementById('dashStorageBar').style.width = sys.disk.percent + '%';
      document.getElementById('dashStorageSub').textContent = `${sys.disk.used_human} used of ${sys.disk.total_human}`;

      document.getElementById('dashCpu').textContent = sys.cpu.usage_percent + '%';
      document.getElementById('dashCpuBar').style.width = sys.cpu.usage_percent + '%';
      document.getElementById('dashCpuSub').textContent = `${sys.cpu.cores} cores / ${sys.cpu.threads} threads`;

      document.getElementById('dashRam').textContent = sys.ram.percent + '%';
      document.getElementById('dashRamBar').style.width = sys.ram.percent + '%';
      document.getElementById('dashRamSub').textContent = `${sys.ram.used_human} / ${sys.ram.total_human}`;

      document.getElementById('dashItems').textContent = data.storage_root_summary.files + data.storage_root_summary.folders;
      document.getElementById('dashItemsSub').textContent = `${data.storage_root_summary.files} files / ${data.storage_root_summary.folders} folders (root)`;

      document.getElementById('dashServerInfo').innerHTML = kvHtml({
        'Hostname': sys.hostname, 'Server IP': sys.server_ip, 'OS': sys.os.name,
        'Kernel': sys.os.kernel, 'PHP Version': sys.php_version, 'Uptime': sys.uptime_human,
        'Timezone': sys.timezone, 'Current Time': sys.current_time,
      });
      document.getElementById('dashLoadInfo').innerHTML = kvHtml({
        '1 min': sys.load_average['1min'], '5 min': sys.load_average['5min'], '15 min': sys.load_average['15min'],
      });

      drawStorageChart('storageChart', sys.disk);
      loadRecentFiles();
    } catch (e) { toast(e.message, 'error'); }
  }

  function kvHtml(obj) {
    return Object.entries(obj).map(([k, v]) => `
      <div class="kv-item"><span class="kv-label">${escapeHtml(k)}</span><span>${escapeHtml(String(v))}</span></div>
    `).join('');
  }

  function drawStorageChart(canvasId, disk) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return;
    const existing = Chart.getChart(ctx);
    if (existing) existing.destroy();
    new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Used', 'Free'],
        datasets: [{ data: [disk.used, disk.free], backgroundColor: ['#4f8cff', '#232c3a'], borderWidth: 0 }],
      },
      options: {
        plugins: { legend: { labels: { color: '#8b98a9' } } },
        cutout: '70%',
      },
    });
  }

  async function loadRecentFiles() {
    const list = document.getElementById('recentList');
    try {
      const data = await apiGet('files.php?action=recent');
      if (!data.items.length) { list.innerHTML = '<li class="muted">No recent files found.</li>'; return; }
      list.innerHTML = data.items.map((f) => `
        <li><span>${escapeHtml(f.name)}</span><span class="muted">${fmtBytes(f.size)}</span></li>
      `).join('');
    } catch { list.innerHTML = '<li class="muted">Could not load recent files.</li>'; }
  }

  // =================================================================
  // STORAGE VIEW
  // =================================================================
  async function loadStorageView() {
    try {
      const data = await apiGet('stats.php');
      const disk = data.system.disk;
      document.getElementById('storageKv').innerHTML = kvHtml({
        'Total': disk.total_human, 'Used': disk.used_human, 'Free': disk.free_human, 'Usage': disk.percent + '%',
      });
      drawStorageChart('storageChart2', disk);
    } catch (e) { toast(e.message, 'error'); }
  }

  // =================================================================
  // SYSTEM VIEW
  // =================================================================
  async function loadSystemView() {
    try {
      const data = await apiGet('stats.php');
      const s = data.system;
      document.getElementById('sysCpu').innerHTML = kvHtml({
        'Model': s.cpu.model, 'Cores': s.cpu.cores, 'Threads': s.cpu.threads, 'Usage': s.cpu.usage_percent + '%',
      });
      document.getElementById('sysRam').innerHTML = kvHtml({
        'Total': s.ram.total_human, 'Used': s.ram.used_human, 'Available': s.ram.available_human, 'Usage': s.ram.percent + '%',
      });
      document.getElementById('sysDisk').innerHTML = kvHtml({
        'Total': s.disk.total_human, 'Used': s.disk.used_human, 'Free': s.disk.free_human, 'Usage': s.disk.percent + '%',
      });
      document.getElementById('sysOs').innerHTML = kvHtml({
        'OS': s.os.name, 'Kernel': s.os.kernel, 'Architecture': s.os.arch, 'Hostname': s.hostname,
        'Uptime': s.uptime_human, 'PHP': s.php_version,
      });
    } catch (e) { toast(e.message, 'error'); }
  }

  // =================================================================
  // ACTIVITY LOG
  // =================================================================
  async function loadActivity() {
    const body = document.getElementById('activityBody');
    try {
      const data = await apiGet('activity.php?action=list');
      body.innerHTML = data.items.map((r) => `
        <tr><td>${escapeHtml(r.created_at)}</td><td>${escapeHtml(r.username || '')}</td>
        <td>${escapeHtml(r.action)}</td><td>${escapeHtml(r.path || '')}</td><td>${escapeHtml(r.ip || '')}</td></tr>
      `).join('') || '<tr><td colspan="5" class="muted">No activity recorded yet.</td></tr>';
    } catch (e) { toast(e.message, 'error'); }
  }
  document.getElementById('btnClearLog').addEventListener('click', async () => {
    if (!(await confirmDialog('Clear Activity Log', 'This will permanently delete all logged activity. Continue?', { danger: true, okLabel: 'Clear Log' }))) return;
    try { await apiPost('activity.php?action=clear', {}); toast('Activity log cleared.', 'success'); loadActivity(); }
    catch (e) { toast(e.message, 'error'); }
  });

  // =================================================================
  // SETTINGS
  // =================================================================
  async function loadSettings() {
    try {
      const data = await apiGet('settings.php?action=get');
      const s = data.settings;
      document.getElementById('setStorageRoot').value = s.storage_root;
      document.getElementById('setMaxUpload').value = s.max_upload_mb;
      document.getElementById('setAllowedExt').value = s.allowed_extensions;
      document.getElementById('setBlockedExt').value = s.blocked_extensions;
      document.getElementById('setSessionTimeout').value = s.session_timeout;
      document.getElementById('setAutoRefresh').value = s.auto_refresh_interval;

      document.getElementById('phpConfigKv').innerHTML = kvHtml(s.php);
      const maxMb = parseInt(s.php.upload_max_filesize) || 0;
      document.getElementById('phpWarning').textContent = maxMb && maxMb < 100
        ? `Note: PHP's upload_max_filesize (${s.php.upload_max_filesize}) may limit single non-chunked uploads. This panel uses chunked uploads to work around this where practical.`
        : '';
    } catch (e) { toast(e.message, 'error'); }
  }
  document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await apiPost('settings.php?action=update', {
        max_upload_mb: document.getElementById('setMaxUpload').value,
        allowed_extensions: document.getElementById('setAllowedExt').value,
        blocked_extensions: document.getElementById('setBlockedExt').value,
        session_timeout: document.getElementById('setSessionTimeout').value,
        auto_refresh_interval: document.getElementById('setAutoRefresh').value,
      });
      toast('Settings saved.', 'success');
    } catch (e) { toast(e.message, 'error'); }
  });

  // =================================================================
  // FILES VIEW
  // =================================================================
  const state = {
    currentPath: '',
    history: [''],
    historyIndex: 0,
    view: 'list',
    sort: 'name',
    order: 'asc',
    items: [],
    selected: new Set(),
    offset: 0,
    limit: 100,
    total: 0,
    searchMode: false,
  };

  function renderBreadcrumb() {
    const parts = state.currentPath ? state.currentPath.split('/') : [];
    let acc = '';
    let html = `<a href="#" data-path="">Home</a>`;
    parts.forEach((p) => {
      acc += (acc ? '/' : '') + p;
      html += `<span class="sep">/</span><a href="#" data-path="${escapeHtml(acc)}">${escapeHtml(p)}</a>`;
    });
    const bc = document.getElementById('breadcrumb');
    bc.innerHTML = html;
    bc.querySelectorAll('a').forEach((a) => {
      a.addEventListener('click', (e) => { e.preventDefault(); navigateTo(a.dataset.path); });
    });
  }

  function navigateTo(path, pushHistory = true) {
    state.currentPath = path;
    state.selected.clear();
    state.offset = 0;
    if (pushHistory) {
      state.history = state.history.slice(0, state.historyIndex + 1);
      state.history.push(path);
      state.historyIndex = state.history.length - 1;
    }
    loadDirectory(path);
  }

  document.getElementById('btnBack').addEventListener('click', () => {
    if (state.historyIndex > 0) { state.historyIndex--; navigateTo(state.history[state.historyIndex], false); }
  });
  document.getElementById('btnForward').addEventListener('click', () => {
    if (state.historyIndex < state.history.length - 1) { state.historyIndex++; navigateTo(state.history[state.historyIndex], false); }
  });
  document.getElementById('btnUp').addEventListener('click', () => {
    if (!state.currentPath) return;
    const parts = state.currentPath.split('/'); parts.pop();
    navigateTo(parts.join('/'));
  });
  document.getElementById('btnHome').addEventListener('click', () => navigateTo(''));
  document.getElementById('btnRefresh').addEventListener('click', () => loadDirectory(state.currentPath));

  async function loadDirectory(path) {
    state.searchMode = false;
    renderBreadcrumb();
    document.getElementById('loadingState').classList.remove('hidden');
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('fileTable').classList.add('hidden');
    document.getElementById('fileGrid').classList.add('hidden');

    try {
      const q = new URLSearchParams({ path, offset: state.offset, limit: state.limit, sort: state.sort, order: state.order });
      const data = await apiGet('files.php?action=list&' + q.toString());
      state.items = data.items;
      state.total = data.total;
      renderFiles();
    } catch (e) {
      toast(e.message, 'error');
      document.getElementById('loadingState').classList.add('hidden');
    }
  }

  function currentIcon(item) {
    if (item.type === 'folder') return 'folder';
    return 'file';
  }

  function renderFiles() {
    document.getElementById('loadingState').classList.add('hidden');
    const table = document.getElementById('fileTable');
    const grid = document.getElementById('fileGrid');
    const empty = document.getElementById('emptyState');

    if (!state.items.length) {
      empty.classList.remove('hidden');
      table.classList.add('hidden');
      grid.classList.add('hidden');
      renderPagination();
      updateSelectionBar();
      return;
    }
    empty.classList.add('hidden');

    if (state.view === 'list') {
      table.classList.remove('hidden');
      grid.classList.add('hidden');
      document.getElementById('fileTableBody').innerHTML = state.items.map(rowHtml).join('');
      attachRowHandlers();
    } else {
      grid.classList.remove('hidden');
      table.classList.add('hidden');
      grid.innerHTML = state.items.map(gridItemHtml).join('');
      attachGridHandlers();
    }
    renderPagination();
    updateSelectionBar();
  }

  function fullPath(name) {
    return state.currentPath ? state.currentPath + '/' + name : name;
  }

  function rowHtml(item) {
    const path = fullPath(item.name);
    const selected = state.selected.has(path) ? 'selected' : '';
    return `
      <tr data-path="${escapeHtml(path)}" data-type="${item.type}" class="${selected}">
        <td class="col-check"><input type="checkbox" class="row-check" ${state.selected.has(path) ? 'checked' : ''}></td>
        <td><div class="file-name-cell" data-open>${Icons.svg(currentIcon(item))}<span>${escapeHtml(item.name)}</span></div></td>
        <td>${item.type === 'folder' ? 'Folder' : (item.ext || 'file').toUpperCase()}</td>
        <td>${item.type === 'folder' ? '—' : fmtBytes(item.size)}</td>
        <td>${fmtDate(item.modified)}</td>
        <td>${item.perms}</td>
        <td>
          <div class="row-actions">
            <button class="icon-btn" data-act="rename" title="Rename">${Icons.svg('edit')}</button>
            <button class="icon-btn" data-act="delete" title="Delete">${Icons.svg('trash')}</button>
            <button class="icon-btn" data-act="menu" title="More">${Icons.svg('more-vertical')}</button>
          </div>
        </td>
      </tr>`;
  }

  function gridItemHtml(item) {
    const path = fullPath(item.name);
    const selected = state.selected.has(path) ? 'selected' : '';
    return `
      <div class="grid-item ${selected}" data-path="${escapeHtml(path)}" data-type="${item.type}">
        ${Icons.svg(currentIcon(item), 34)}
        <span class="name">${escapeHtml(item.name)}</span>
      </div>`;
  }

  function attachRowHandlers() {
    document.querySelectorAll('#fileTableBody tr').forEach((tr) => {
      const path = tr.dataset.path;
      const type = tr.dataset.type;
      tr.querySelector('[data-open]').addEventListener('click', () => openItem(path, type));
      tr.querySelector('.row-check').addEventListener('change', (e) => {
        toggleSelect(path, e.target.checked);
      });
      tr.querySelector('[data-act="rename"]').addEventListener('click', () => renameDialog(path));
      tr.querySelector('[data-act="delete"]').addEventListener('click', () => deleteItems([path]));
      tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => showContextMenu(e, path, type));
      tr.addEventListener('contextmenu', (e) => { e.preventDefault(); showContextMenu(e, path, type); });
    });
  }
  function attachGridHandlers() {
    document.querySelectorAll('.grid-item').forEach((el) => {
      const path = el.dataset.path;
      const type = el.dataset.type;
      el.addEventListener('click', (e) => {
        if (e.ctrlKey || e.metaKey) { toggleSelect(path, !state.selected.has(path)); renderFiles(); }
        else openItem(path, type);
      });
      el.addEventListener('contextmenu', (e) => { e.preventDefault(); showContextMenu(e, path, type); });
    });
  }

  function toggleSelect(path, on) {
    if (on) state.selected.add(path); else state.selected.delete(path);
    updateSelectionBar();
  }
  function updateSelectionBar() {
    const bar = document.getElementById('selectionBar');
    const n = state.selected.size;
    bar.classList.toggle('hidden', n === 0);
    document.getElementById('selectionCount').textContent = `${n} selected`;
    document.getElementById('btnZipSelected').disabled = n === 0;
  }
  document.getElementById('selectAll').addEventListener('change', (e) => {
    state.items.forEach((i) => toggleSelect(fullPath(i.name), e.target.checked));
    renderFiles();
  });

  function openItem(path, type) {
    if (type === 'folder') { navigateTo(path); return; }
    const ext = path.split('.').pop().toLowerCase();
    const previewable = [...IMG_EXT, ...VID_EXT, ...AUD_EXT, ...TXT_EXT, 'pdf'];
    if (previewable.includes(ext)) previewFile(path, ext);
    else window.open('api/download.php?path=' + encodeURIComponent(path), '_blank');
  }

  const IMG_EXT = ['jpg','jpeg','png','gif','webp','svg'];
  const VID_EXT = ['mp4','webm','mov'];
  const AUD_EXT = ['mp3','wav','ogg'];
  const TXT_EXT = ['txt','json','html','htm','css','js','php','md','conf','env','log','xml','yaml','yml'];
  const EDIT_EXT = ['txt','php','html','htm','css','js','json','xml','yaml','yml','md','log','conf','env','ini','sql','sh','py','csv'];

  function renderPagination() {
    const pages = Math.ceil(state.total / state.limit) || 1;
    const current = Math.floor(state.offset / state.limit) + 1;
    const pag = document.getElementById('pagination');
    if (pages <= 1) { pag.innerHTML = ''; return; }
    let html = '';
    for (let p = 1; p <= pages; p++) {
      html += `<button class="${p === current ? 'active' : ''}" data-page="${p}">${p}</button>`;
    }
    pag.innerHTML = html;
    pag.querySelectorAll('button').forEach((b) => {
      b.addEventListener('click', () => {
        state.offset = (parseInt(b.dataset.page) - 1) * state.limit;
        loadDirectory(state.currentPath);
      });
    });
  }

  document.getElementById('sortSelect').addEventListener('change', (e) => {
    state.sort = e.target.value; loadDirectory(state.currentPath);
  });
  document.getElementById('btnOrder').addEventListener('click', () => {
    state.order = state.order === 'asc' ? 'desc' : 'asc'; loadDirectory(state.currentPath);
  });
  document.getElementById('btnViewToggle').addEventListener('click', () => {
    state.view = state.view === 'list' ? 'grid' : 'list';
    renderFiles();
  });

  const newMenu = document.getElementById('newMenu');
  document.getElementById('btnNew').addEventListener('click', (e) => {
    e.stopPropagation();
    newMenu.classList.toggle('open');
  });
  document.addEventListener('click', () => newMenu.classList.remove('open'));
  newMenu.addEventListener('click', (e) => e.stopPropagation());
  newMenu.querySelector('[data-action="new-folder"]').addEventListener('click', () => { newMenu.classList.remove('open'); newFolderDialog(); });
  newMenu.querySelector('[data-action="new-file"]').addEventListener('click', () => { newMenu.classList.remove('open'); newFileDialog(); });

  function newFolderDialog() {
    openModal(`
      <h2>New Folder</h2>
      <div class="form-group"><label>Folder name</label><input type="text" id="mName" autofocus></div>
      <div class="modal-actions"><button class="btn" id="mCancel">Cancel</button><button class="btn btn-primary" id="mOk">Create</button></div>
    `);
    document.getElementById('mCancel').onclick = closeModal;
    document.getElementById('mOk').onclick = async () => {
      const name = document.getElementById('mName').value.trim();
      if (!name) return;
      try {
        await apiPost('files.php?action=mkdir', { parent: state.currentPath, name });
        closeModal(); toast('Folder created.', 'success'); loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    };
  }
  function newFileDialog() {
    openModal(`
      <h2>New File</h2>
      <div class="form-group"><label>File name</label><input type="text" id="mName" placeholder="e.g. notes.txt" autofocus></div>
      <div class="modal-actions"><button class="btn" id="mCancel">Cancel</button><button class="btn btn-primary" id="mOk">Create</button></div>
    `);
    document.getElementById('mCancel').onclick = closeModal;
    document.getElementById('mOk').onclick = async () => {
      const name = document.getElementById('mName').value.trim();
      if (!name) return;
      try {
        await apiPost('files.php?action=newfile', { parent: state.currentPath, name });
        closeModal(); toast('File created.', 'success'); loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    };
  }

  function renameDialog(path) {
    const name = path.split('/').pop();
    openModal(`
      <h2>Rename</h2>
      <div class="form-group"><label>New name</label><input type="text" id="mName" value="${escapeHtml(name)}" autofocus></div>
      <div class="modal-actions"><button class="btn" id="mCancel">Cancel</button><button class="btn btn-primary" id="mOk">Rename</button></div>
    `);
    document.getElementById('mCancel').onclick = closeModal;
    document.getElementById('mOk').onclick = async () => {
      const newName = document.getElementById('mName').value.trim();
      if (!newName) return;
      try {
        await apiPost('files.php?action=rename', { path, new_name: newName });
        closeModal(); toast('Renamed.', 'success'); loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    };
  }

  async function deleteItems(paths) {
    try {
      const preview = await apiPost('files.php?action=delete_preview', { paths });
      const msg = preview.folders > 0
        ? `This will permanently delete ${preview.files} file(s) and ${preview.folders} folder(s).`
        : `This will permanently delete ${preview.files} file(s).`;
      if (!(await confirmDialog('Delete', msg, { danger: true, okLabel: 'Delete' }))) return;
      await apiPost('files.php?action=delete', { paths });
      toast('Deleted.', 'success');
      paths.forEach((p) => state.selected.delete(p));
      loadDirectory(state.currentPath);
    } catch (e) { toast(e.message, 'error'); }
  }

  function destinationPicker(title, onPick) {
    let browsePath = '';
    async function render() {
      const q = new URLSearchParams({ path: browsePath, limit: 200 });
      const data = await apiGet('files.php?action=list&' + q.toString());
      const folders = data.items.filter((i) => i.type === 'folder');
      openModal(`
        <h2>${escapeHtml(title)}</h2>
        <p class="muted">Current: /${escapeHtml(browsePath)}</p>
        <div class="file-area" style="max-height:300px;overflow:auto;">
          <div class="file-grid" style="grid-template-columns:1fr;">
            ${browsePath ? `<div class="grid-item" style="flex-direction:row;justify-content:flex-start;" data-up>${Icons.svg('arrow-up')}<span class="name">.. (up)</span></div>` : ''}
            ${folders.map((f) => `<div class="grid-item" style="flex-direction:row;justify-content:flex-start;" data-folder="${escapeHtml(f.name)}">${Icons.svg('folder')}<span class="name">${escapeHtml(f.name)}</span></div>`).join('') || '<p class="muted">No subfolders.</p>'}
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn" id="mCancel">Cancel</button>
          <button class="btn btn-primary" id="mOk">Select This Folder</button>
        </div>
      `, { large: false });
      Icons.renderAll(modalBox);
      const up = modalBox.querySelector('[data-up]');
      if (up) up.addEventListener('click', () => { browsePath = browsePath.split('/').slice(0, -1).join('/'); render(); });
      modalBox.querySelectorAll('[data-folder]').forEach((el) => {
        el.addEventListener('click', () => { browsePath = browsePath ? browsePath + '/' + el.dataset.folder : el.dataset.folder; render(); });
      });
      document.getElementById('mCancel').onclick = closeModal;
      document.getElementById('mOk').onclick = () => { closeModal(); onPick(browsePath); };
    }
    render();
  }

  function moveItems(paths) {
    destinationPicker('Move to…', async (dest) => {
      try {
        await apiPost('files.php?action=move', { paths, destination: dest });
        toast('Moved.', 'success');
        state.selected.clear();
        loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    });
  }
  function copyItems(paths) {
    destinationPicker('Copy to…', async (dest) => {
      try {
        await apiPost('files.php?action=copy', { paths, destination: dest });
        toast('Copied.', 'success');
        loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    });
  }
  async function duplicateItem(path) {
    try {
      await apiPost('files.php?action=duplicate', { path });
      toast('Duplicated.', 'success');
      loadDirectory(state.currentPath);
    } catch (e) { toast(e.message, 'error'); }
  }

  function zipItems(paths) {
    openModal(`
      <h2>Create ZIP</h2>
      <div class="form-group"><label>Archive name</label><input type="text" id="mName" value="archive.zip"></div>
      <div class="modal-actions"><button class="btn" id="mCancel">Cancel</button><button class="btn btn-primary" id="mOk">Create</button></div>
    `);
    document.getElementById('mCancel').onclick = closeModal;
    document.getElementById('mOk').onclick = async () => {
      const zip_name = document.getElementById('mName').value.trim() || 'archive.zip';
      try {
        await apiPost('zip.php?action=create', { paths, destination: state.currentPath, zip_name });
        closeModal(); toast('Archive created.', 'success'); loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    };
  }
  async function extractZip(path) {
    try {
      await apiPost('zip.php?action=extract', { path, destination: state.currentPath });
      toast('Archive extracted.', 'success');
      loadDirectory(state.currentPath);
    } catch (e) { toast(e.message, 'error'); }
  }

  document.querySelectorAll('[data-bulk]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const paths = Array.from(state.selected);
      if (!paths.length) return;
      const action = btn.dataset.bulk;
      if (action === 'delete') deleteItems(paths);
      if (action === 'move') moveItems(paths);
      if (action === 'copy') copyItems(paths);
      if (action === 'zip') zipItems(paths);
    });
  });
  document.getElementById('btnZipSelected').addEventListener('click', () => zipItems(Array.from(state.selected)));

  async function showProperties(path) {
    try {
      const p = await apiGet('files.php?action=properties&path=' + encodeURIComponent(path));
      openModal(`
        <h2>Properties</h2>
        <div class="kv-grid">
          ${kvHtml({
            Name: p.name, Type: p.type, Size: p.size_human || '—', Modified: p.modified,
            Owner: p.owner, Group: p.group, Permissions: p.permissions_octal + ' (' + p.permissions_human + ')',
            MIME: p.mime || '—', Extension: p.extension || '—',
          })}
        </div>
        <div class="modal-actions">
          <button class="btn" id="mChmod">Edit Permissions</button>
          <button class="btn btn-primary" id="mClose">Close</button>
        </div>
      `);
      document.getElementById('mClose').onclick = closeModal;
      document.getElementById('mChmod').onclick = () => permissionsDialog(path, p.permissions_octal);
    } catch (e) { toast(e.message, 'error'); }
  }

  function permissionsDialog(path, octal) {
    octal = (octal || '644').slice(-3);
    const bits = octal.split('').map(Number);
    const labels = ['Owner', 'Group', 'Others'];
    openModal(`
      <h2>Permissions</h2>
      <div class="perm-grid">
        ${labels.map((label, idx) => `
          <div class="perm-col">
            <h4>${label}</h4>
            <label><input type="checkbox" data-bit="${idx}" data-val="4" ${bits[idx] & 4 ? 'checked' : ''}> Read</label>
            <label><input type="checkbox" data-bit="${idx}" data-val="2" ${bits[idx] & 2 ? 'checked' : ''}> Write</label>
            <label><input type="checkbox" data-bit="${idx}" data-val="1" ${bits[idx] & 1 ? 'checked' : ''}> Execute</label>
          </div>
        `).join('')}
      </div>
      <div class="numeric-perm" id="numericPerm">${octal}</div>
      <div class="modal-actions"><button class="btn" id="mCancel">Cancel</button><button class="btn btn-primary" id="mOk">Apply</button></div>
    `);
    function recalc() {
      const vals = [0, 0, 0];
      modalBox.querySelectorAll('input[type=checkbox]').forEach((cb) => {
        const bit = parseInt(cb.dataset.bit);
        if (cb.checked) vals[bit] |= parseInt(cb.dataset.val);
      });
      const newOctal = vals.join('');
      document.getElementById('numericPerm').textContent = newOctal;
      return newOctal;
    }
    modalBox.querySelectorAll('input[type=checkbox]').forEach((cb) => cb.addEventListener('change', recalc));
    document.getElementById('mCancel').onclick = closeModal;
    document.getElementById('mOk').onclick = async () => {
      const mode = recalc();
      if (!(await confirmDialog('Change Permissions', `Set permissions to <strong>${mode}</strong> for this item? Incorrect permissions can make files inaccessible.`, { okLabel: 'Apply' }))) return;
      try {
        await apiPost('files.php?action=chmod', { path, mode });
        closeModal(); toast('Permissions updated.', 'success'); loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    };
  }

  let ctxMenuEl = null;
  function showContextMenu(e, path, type) {
    e.preventDefault();
    if (ctxMenuEl) ctxMenuEl.remove();
    const isZip = path.toLowerCase().endsWith('.zip');
    const ext = path.split('.').pop().toLowerCase();
    const canEdit = EDIT_EXT.includes(ext);
    const menu = document.createElement('div');
    menu.className = 'context-menu open';
    menu.style.left = e.clientX + 'px';
    menu.style.top = e.clientY + 'px';
    menu.innerHTML = `
      <button data-a="open">${Icons.svg('folder-open')} Open</button>
      ${type === 'file' ? `<button data-a="preview">${Icons.svg('eye')} Preview</button>` : ''}
      ${type === 'file' && canEdit ? `<button data-a="edit">${Icons.svg('edit')} Edit</button>` : ''}
      ${type === 'file' ? `<button data-a="download">${Icons.svg('download')} Download</button>` : ''}
      <hr>
      <button data-a="rename">${Icons.svg('edit')} Rename</button>
      <button data-a="copy">${Icons.svg('copy')} Copy</button>
      <button data-a="move">${Icons.svg('arrow-right')} Move</button>
      ${type === 'file' ? `<button data-a="duplicate">${Icons.svg('copy')} Duplicate</button>` : ''}
      ${isZip ? `<button data-a="extract">${Icons.svg('archive')} Extract</button>` : ''}
      <button data-a="zip">${Icons.svg('archive')} Add to ZIP</button>
      <hr>
      <button data-a="properties">${Icons.svg('file')} Properties</button>
      <button data-a="delete" class="danger">${Icons.svg('trash')} Delete</button>
    `;
    document.body.appendChild(menu);
    ctxMenuEl = menu;
    const close = () => { menu.remove(); ctxMenuEl = null; document.removeEventListener('click', close); };
    setTimeout(() => document.addEventListener('click', close), 0);

    menu.querySelectorAll('button').forEach((btn) => {
      btn.addEventListener('click', () => {
        close();
        const a = btn.dataset.a;
        if (a === 'open') openItem(path, type);
        if (a === 'preview') previewFile(path, ext);
        if (a === 'edit') openEditor(path);
        if (a === 'download') window.open('api/download.php?path=' + encodeURIComponent(path), '_blank');
        if (a === 'rename') renameDialog(path);
        if (a === 'copy') copyItems([path]);
        if (a === 'move') moveItems([path]);
        if (a === 'duplicate') duplicateItem(path);
        if (a === 'extract') extractZip(path);
        if (a === 'zip') zipItems([path]);
        if (a === 'properties') showProperties(path);
        if (a === 'delete') deleteItems([path]);
      });
    });
  }

  async function previewFile(path, ext) {
    const name = path.split('/').pop();
    if (IMG_EXT.includes(ext)) {
      openModal(`<h2>${escapeHtml(name)}</h2><div class="preview-body"><img src="api/preview.php?path=${encodeURIComponent(path)}"></div>
        <div class="modal-actions"><button class="btn" id="mClose">Close</button></div>`, { large: true });
    } else if (VID_EXT.includes(ext)) {
      openModal(`<h2>${escapeHtml(name)}</h2><div class="preview-body"><video controls src="api/preview.php?path=${encodeURIComponent(path)}"></video></div>
        <div class="modal-actions"><button class="btn" id="mClose">Close</button></div>`, { large: true });
    } else if (AUD_EXT.includes(ext)) {
      openModal(`<h2>${escapeHtml(name)}</h2><div class="preview-body"><audio controls style="width:100%" src="api/preview.php?path=${encodeURIComponent(path)}"></audio></div>
        <div class="modal-actions"><button class="btn" id="mClose">Close</button></div>`, { large: true });
    } else if (ext === 'pdf') {
      openModal(`<h2>${escapeHtml(name)}</h2><div class="preview-body" style="height:70vh;width:100%"><iframe src="api/preview.php?path=${encodeURIComponent(path)}" style="width:100%;height:100%;border:none;"></iframe></div>
        <div class="modal-actions"><button class="btn" id="mClose">Close</button></div>`, { large: true });
    } else if (TXT_EXT.includes(ext)) {
      try {
        const res = await fetch('api/preview.php?path=' + encodeURIComponent(path), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF } });
        const data = await res.json();
        openModal(`<h2>${escapeHtml(name)}</h2><div class="preview-body"><pre>${escapeHtml(data.content || '')}</pre></div>
          ${data.truncated ? '<p class="muted">Preview truncated — download to view the full file.</p>' : ''}
          <div class="modal-actions"><button class="btn" id="mClose">Close</button></div>`, { large: true });
      } catch (e) { toast('Could not load preview.', 'error'); return; }
    } else {
      window.open('api/download.php?path=' + encodeURIComponent(path), '_blank');
      return;
    }
    document.getElementById('mClose').onclick = closeModal;
  }

  let aceEditor = null;
  let editorDirty = false;
  async function openEditor(path) {
    try {
      const data = await apiGet('edit.php?action=load&path=' + encodeURIComponent(path));
      openModal(`
        <div class="editor-toolbar">
          <h2 style="margin:0;">${escapeHtml(path.split('/').pop())}</h2>
          <div>
            <button class="btn btn-sm" id="mSaveAs">Save As</button>
            <button class="btn btn-sm btn-primary" id="mSave">Save</button>
          </div>
        </div>
        <div id="editorContainer"></div>
      `, { large: true });
      aceEditor = ace.edit('editorContainer');
      aceEditor.setTheme('ace/theme/tomorrow_night');
      aceEditor.session.setMode(aceModeFor(data.extension));
      aceEditor.setValue(data.content, -1);
      editorDirty = false;
      aceEditor.session.on('change', () => { editorDirty = true; });

      document.getElementById('mSave').onclick = () => saveEditor(path);
      document.getElementById('mSaveAs').onclick = () => saveEditorAs(path);

      const escHandler = (e) => {
        if (e.key === 'Escape') attemptCloseEditor();
        if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveEditor(path); }
      };
      document.addEventListener('keydown', escHandler);
      modalBox._escHandler = escHandler;
    } catch (e) { toast(e.message, 'error'); }
  }
  function aceModeFor(ext) {
    const map = { php: 'php', html: 'html', htm: 'html', css: 'css', js: 'javascript', json: 'json',
      xml: 'xml', yaml: 'yaml', yml: 'yaml', md: 'markdown', sh: 'sh', py: 'python', sql: 'sql' };
    return 'ace/mode/' + (map[ext] || 'text');
  }
  async function saveEditor(path) {
    try {
      await apiPost('edit.php?action=save', { path, content: aceEditor.getValue() });
      editorDirty = false;
      toast('Saved.', 'success');
    } catch (e) { toast(e.message, 'error'); }
  }
  function saveEditorAs(path) {
    const dir = path.split('/').slice(0, -1).join('/');
    openModal(`
      <h2>Save As</h2>
      <div class="form-group"><label>File name</label><input type="text" id="mName" value="${escapeHtml(path.split('/').pop())}"></div>
      <div class="modal-actions"><button class="btn" id="mCancel">Cancel</button><button class="btn btn-primary" id="mOk">Save</button></div>
    `);
    const content = aceEditor.getValue();
    document.getElementById('mCancel').onclick = () => openEditor(path);
    document.getElementById('mOk').onclick = async () => {
      const name = document.getElementById('mName').value.trim();
      if (!name) return;
      try {
        await apiPost('files.php?action=newfile', { parent: dir, name });
        await apiPost('edit.php?action=save', { path: dir ? dir + '/' + name : name, content });
        closeModal(); toast('Saved as new file.', 'success'); loadDirectory(state.currentPath);
      } catch (e) { toast(e.message, 'error'); }
    };
  }
  async function attemptCloseEditor() {
    if (editorDirty && !(await confirmDialog('Unsaved Changes', 'You have unsaved changes. Close without saving?', { danger: true, okLabel: 'Discard' }))) return;
    if (modalBox._escHandler) document.removeEventListener('keydown', modalBox._escHandler);
    closeModal();
  }

  const fileInput = document.getElementById('fileInput');
  document.getElementById('btnUpload').addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });

  const fileArea = document.getElementById('fileArea');
  ['dragover', 'dragenter'].forEach((ev) => fileArea.addEventListener(ev, (e) => { e.preventDefault(); fileArea.style.outline = '2px dashed var(--accent)'; }));
  ['dragleave', 'drop'].forEach((ev) => fileArea.addEventListener(ev, (e) => { e.preventDefault(); fileArea.style.outline = 'none'; }));
  fileArea.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) handleFiles(e.dataTransfer.files); });

  const CHUNK_SIZE = 5 * 1024 * 1024;
  function handleFiles(fileList) {
    Array.from(fileList).forEach(uploadFile);
  }

  function uploadFile(file) {
    const uploadId = 'u' + Date.now() + Math.random().toString(36).slice(2);
    const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
    const area = document.getElementById('uploadProgressArea');
    const item = document.createElement('div');
    item.className = 'upload-item';
    item.innerHTML = `
      <div class="upload-head"><span>${escapeHtml(file.name)}</span><span class="pct">0%</span></div>
      <div class="progress-bar"><div class="progress-fill" style="width:0%"></div></div>
      <div class="muted speed">Preparing…</div>
    `;
    area.appendChild(item);
    const fill = item.querySelector('.progress-fill');
    const pct = item.querySelector('.pct');
    const speedEl = item.querySelector('.speed');

    let uploaded = 0;
    const startTime = Date.now();

    async function sendChunk(index) {
      const start = index * CHUNK_SIZE;
      const end = Math.min(start + CHUNK_SIZE, file.size);
      const chunk = file.slice(start, end);

      const form = new FormData();
      form.append('chunk', chunk, file.name);
      form.append('destination', state.currentPath);
      form.append('filename', file.name);
      form.append('upload_id', uploadId);
      form.append('chunk_index', index);
      form.append('total_chunks', totalChunks);

      const res = await fetch('api/upload.php', {
        method: 'POST', body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
      });
      const data = await res.json();
      if (!res.ok || data.ok === false) throw new Error(data.error || 'Upload failed.');

      uploaded = end;
      const percent = Math.round((uploaded / file.size) * 100);
      fill.style.width = percent + '%';
      pct.textContent = percent + '%';
      const elapsed = (Date.now() - startTime) / 1000;
      const speed = uploaded / Math.max(elapsed, 0.1);
      const remaining = (file.size - uploaded) / Math.max(speed, 1);
      speedEl.textContent = `${fmtBytes(speed)}/s — ${Math.ceil(remaining)}s remaining`;

      return data;
    }

    (async () => {
      try {
        for (let i = 0; i < totalChunks; i++) {
          await sendChunk(i);
        }
        speedEl.textContent = 'Complete.';
        toast(`${file.name} uploaded.`, 'success');
        setTimeout(() => item.remove(), 2500);
        loadDirectory(state.currentPath);
      } catch (e) {
        speedEl.textContent = 'Failed: ' + e.message;
        item.classList.add('upload-failed');
        toast(`Upload failed: ${e.message}`, 'error');
      }
    })();
  }

  document.getElementById('btnSearch').addEventListener('click', doSearch);
  document.getElementById('searchInput').addEventListener('keydown', (e) => { if (e.key === 'Enter') doSearch(); });
  async function doSearch() {
    const q = document.getElementById('searchInput').value.trim();
    if (!q) { loadDirectory(state.currentPath); return; }
    document.getElementById('loadingState').classList.remove('hidden');
    try {
      const query = new URLSearchParams({ path: state.currentPath, q, recursive: '1', sort: state.sort, order: state.order });
      const data = await apiGet('search.php?' + query.toString());
      state.searchMode = true;
      renderSearchResults(data.items);
    } catch (e) { toast(e.message, 'error'); }
    document.getElementById('loadingState').classList.add('hidden');
  }
  function renderSearchResults(items) {
    const table = document.getElementById('fileTable');
    const grid = document.getElementById('fileGrid');
    const empty = document.getElementById('emptyState');
    document.getElementById('pagination').innerHTML = '';
    if (!items.length) { empty.classList.remove('hidden'); table.classList.add('hidden'); grid.classList.add('hidden'); return; }
    empty.classList.add('hidden');
    table.classList.remove('hidden');
    grid.classList.add('hidden');
    document.getElementById('fileTableBody').innerHTML = items.map((item) => `
      <tr data-path="${escapeHtml(item.path)}" data-type="${item.type}">
        <td class="col-check"></td>
        <td><div class="file-name-cell" data-open>${Icons.svg(item.type === 'folder' ? 'folder' : 'file')}<span>${escapeHtml(item.path)}</span></div></td>
        <td>${item.type === 'folder' ? 'Folder' : 'File'}</td>
        <td>${item.type === 'folder' ? '—' : fmtBytes(item.size)}</td>
        <td>${fmtDate(item.modified)}</td>
        <td>—</td>
        <td></td>
      </tr>
    `).join('');
    document.querySelectorAll('#fileTableBody tr').forEach((tr) => {
      tr.querySelector('[data-open]').addEventListener('click', () => {
        const type = tr.dataset.type;
        if (type === 'folder') navigateTo(tr.dataset.path);
        else openItem(tr.dataset.path, 'file');
      });
    });
    Icons.renderAll();
  }

  let refreshTimer = null;
  function startAutoRefresh() {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(() => {
      const dashActive = !document.getElementById('view-dashboard').classList.contains('hidden');
      const sysActive = !document.getElementById('view-system').classList.contains('hidden');
      if (dashActive) loadDashboard();
      if (sysActive) loadSystemView();
    }, 8000);
  }

  Icons.renderAll();
  loadDashboard();
  startAutoRefresh();
})();
