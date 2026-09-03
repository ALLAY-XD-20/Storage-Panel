<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

start_secure_session();
send_security_headers();
require_login();

$token = csrf_token();
$username = h($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Storage Panel</title>
<meta name="csrf-token" content="<?= h($token) ?>">
<link rel="stylesheet" href="assets/css/app.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.9/ace.js"></script>
</head>
<body>
<div class="app-shell">

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" stroke="currentColor" stroke-width="1.6"/></svg>
      <span>Storage Panel</span>
    </div>
    <nav class="sidebar-nav">
      <a href="#" class="nav-item active" data-view="dashboard"><i data-icon="grid"></i>Dashboard</a>
      <a href="#" class="nav-item" data-view="files"><i data-icon="folder"></i>Files</a>
      <a href="#" class="nav-item" data-view="storage"><i data-icon="disk"></i>Storage</a>
      <a href="#" class="nav-item" data-view="system"><i data-icon="cpu"></i>System Stats</a>
      <a href="#" class="nav-item" data-view="activity"><i data-icon="clock"></i>Activity Log</a>
      <a href="#" class="nav-item" data-view="settings"><i data-icon="gear"></i>Settings</a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-chip"><i data-icon="user"></i><span><?= $username ?></span></div>
      <a href="logout.php" class="nav-item logout"><i data-icon="logout"></i>Logout</a>
    </div>
  </aside>

  <!-- Main -->
  <div class="main-area">
    <header class="topbar">
      <button class="icon-btn mobile-only" id="sidebarToggle"><i data-icon="menu"></i></button>
      <div class="topbar-title" id="viewTitle">Dashboard</div>
      <div class="topbar-right">
        <span class="server-clock" id="serverClock">--:--:--</span>
      </div>
    </header>

    <main class="content" id="content">

      <!-- DASHBOARD VIEW -->
      <section class="view" id="view-dashboard">
        <div class="cards-grid">
          <div class="card stat-card">
            <div class="stat-label">Storage Used</div>
            <div class="stat-value" id="dashUsed">—</div>
            <div class="progress-bar"><div class="progress-fill" id="dashStorageBar" style="width:0%"></div></div>
            <div class="stat-sub" id="dashStorageSub">— / —</div>
          </div>
          <div class="card stat-card">
            <div class="stat-label">CPU Usage</div>
            <div class="stat-value" id="dashCpu">—%</div>
            <div class="progress-bar"><div class="progress-fill accent-cpu" id="dashCpuBar" style="width:0%"></div></div>
            <div class="stat-sub" id="dashCpuSub">—</div>
          </div>
          <div class="card stat-card">
            <div class="stat-label">RAM Usage</div>
            <div class="stat-value" id="dashRam">—%</div>
            <div class="progress-bar"><div class="progress-fill accent-ram" id="dashRamBar" style="width:0%"></div></div>
            <div class="stat-sub" id="dashRamSub">—</div>
          </div>
          <div class="card stat-card">
            <div class="stat-label">Items</div>
            <div class="stat-value" id="dashItems">—</div>
            <div class="stat-sub" id="dashItemsSub">files / folders (root)</div>
          </div>
        </div>

        <div class="panels-grid">
          <div class="card">
            <h3>Storage Breakdown</h3>
            <canvas id="storageChart" height="180"></canvas>
          </div>
          <div class="card">
            <h3>Recently Modified</h3>
            <ul class="simple-list" id="recentList"><li class="muted">Loading…</li></ul>
          </div>
        </div>

        <div class="panels-grid">
          <div class="card">
            <h3>Server</h3>
            <div class="kv-grid" id="dashServerInfo"></div>
          </div>
          <div class="card">
            <h3>Load Average</h3>
            <div class="kv-grid" id="dashLoadInfo"></div>
          </div>
        </div>
      </section>

      <!-- FILES VIEW -->
      <section class="view hidden" id="view-files">
        <div class="breadcrumb-row">
          <div class="nav-buttons">
            <button class="icon-btn" id="btnBack" title="Back"><i data-icon="arrow-left"></i></button>
            <button class="icon-btn" id="btnForward" title="Forward"><i data-icon="arrow-right"></i></button>
            <button class="icon-btn" id="btnUp" title="Up one directory"><i data-icon="arrow-up"></i></button>
            <button class="icon-btn" id="btnHome" title="Home"><i data-icon="home"></i></button>
            <button class="icon-btn" id="btnRefresh" title="Refresh"><i data-icon="refresh"></i></button>
          </div>
          <nav class="breadcrumb" id="breadcrumb"></nav>
        </div>

        <div class="toolbar">
          <div class="toolbar-group">
            <div class="dropdown">
              <button class="btn btn-primary" id="btnNew">+ New</button>
              <div class="dropdown-menu" id="newMenu">
                <button data-action="new-folder"><i data-icon="folder"></i>New Folder</button>
                <button data-action="new-file"><i data-icon="file"></i>New File</button>
              </div>
            </div>
            <button class="btn" id="btnUpload"><i data-icon="upload"></i>Upload</button>
            <input type="file" id="fileInput" multiple hidden>
            <button class="btn" id="btnZipSelected" disabled><i data-icon="archive"></i>Zip Selected</button>
          </div>
          <div class="toolbar-group">
            <div class="search-box">
              <input type="text" id="searchInput" placeholder="Search files…">
              <button class="icon-btn" id="btnSearch"><i data-icon="search"></i></button>
            </div>
            <select id="sortSelect">
              <option value="name">Name</option>
              <option value="size">Size</option>
              <option value="modified">Modified</option>
            </select>
            <button class="icon-btn" id="btnOrder" title="Toggle order"><i data-icon="sort"></i></button>
            <button class="icon-btn" id="btnViewToggle" title="Toggle view"><i data-icon="list"></i></button>
          </div>
        </div>

        <div class="selection-bar hidden" id="selectionBar">
          <span id="selectionCount">0 selected</span>
          <div class="selection-actions">
            <button class="btn btn-sm" data-bulk="copy">Copy</button>
            <button class="btn btn-sm" data-bulk="move">Move</button>
            <button class="btn btn-sm" data-bulk="zip">Zip</button>
            <button class="btn btn-sm btn-danger" data-bulk="delete">Delete</button>
          </div>
        </div>

        <div id="uploadProgressArea"></div>

        <div class="file-area" id="fileArea">
          <table class="file-table hidden" id="fileTable">
            <thead>
              <tr>
                <th class="col-check"><input type="checkbox" id="selectAll"></th>
                <th>Name</th><th>Type</th><th>Size</th><th>Modified</th><th>Permissions</th><th>Actions</th>
              </tr>
            </thead>
            <tbody id="fileTableBody"></tbody>
          </table>
          <div class="file-grid" id="fileGrid"></div>
          <div class="empty-state hidden" id="emptyState">This folder is empty.</div>
          <div class="loading-state" id="loadingState">Loading files…</div>
        </div>
        <div class="pagination" id="pagination"></div>
      </section>

      <!-- STORAGE VIEW -->
      <section class="view hidden" id="view-storage">
        <div class="card">
          <h3>Disk Usage</h3>
          <canvas id="storageChart2" height="120"></canvas>
          <div class="kv-grid" id="storageKv"></div>
        </div>
        <div class="panels-grid">
          <div class="card"><h3>Largest Files</h3><ul class="simple-list" id="largestFiles"><li class="muted">Not calculated yet — this can be slow on large trees.</li></ul></div>
          <div class="card"><h3>File Type Distribution</h3><canvas id="typeChart" height="180"></canvas></div>
        </div>
      </section>

      <!-- SYSTEM VIEW -->
      <section class="view hidden" id="view-system">
        <div class="cards-grid">
          <div class="card"><h3>CPU</h3><div class="kv-grid" id="sysCpu"></div></div>
          <div class="card"><h3>Memory</h3><div class="kv-grid" id="sysRam"></div></div>
          <div class="card"><h3>Disk</h3><div class="kv-grid" id="sysDisk"></div></div>
          <div class="card"><h3>Operating System</h3><div class="kv-grid" id="sysOs"></div></div>
        </div>
      </section>

      <!-- ACTIVITY VIEW -->
      <section class="view hidden" id="view-activity">
        <div class="card">
          <div class="card-header-row">
            <h3>Activity Log</h3>
            <button class="btn btn-danger btn-sm" id="btnClearLog">Clear Log</button>
          </div>
          <table class="file-table">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Path</th><th>IP</th></tr></thead>
            <tbody id="activityBody"></tbody>
          </table>
        </div>
      </section>

      <!-- SETTINGS VIEW -->
      <section class="view hidden" id="view-settings">
        <div class="card">
          <h3>Panel Settings</h3>
          <form id="settingsForm" class="settings-form">
            <label>Storage Root <input type="text" id="setStorageRoot" disabled></label>
            <label>Max Upload Size (MB) <input type="number" id="setMaxUpload" min="1"></label>
            <label>Allowed Extensions <input type="text" id="setAllowedExt" placeholder="* for all, or comma list"></label>
            <label>Blocked Extensions <input type="text" id="setBlockedExt"></label>
            <label>Session Timeout (seconds) <input type="number" id="setSessionTimeout" min="60"></label>
            <label>Auto Refresh Interval (seconds) <input type="number" id="setAutoRefresh" min="2"></label>
            <button type="submit" class="btn btn-primary">Save Settings</button>
          </form>
        </div>
        <div class="card">
          <h3>PHP Configuration</h3>
          <div class="kv-grid" id="phpConfigKv"></div>
          <p class="muted" id="phpWarning"></p>
        </div>
      </section>

    </main>
  </div>
</div>

<!-- Modals -->
<div class="modal-overlay hidden" id="modalOverlay">
  <div class="modal" id="modalBox"></div>
</div>

<div id="toastContainer" class="toast-container"></div>

<script src="assets/js/icons.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
