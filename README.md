# Storage Panel

A self-hosted, production-oriented VPS file/storage manager built in plain
PHP 8.2+. No framework, no Node build step, no MySQL — settings and
metadata live in SQLite, and every uploaded file lives directly on your
VPS filesystem under a configurable `STORAGE_ROOT`.

## Features

- Live dashboard: CPU, RAM, disk, load average, uptime, OS/kernel/PHP info
- Full file manager: upload (chunked, drag & drop), download, rename,
  move, copy, duplicate, delete, create file/folder
- Built-in code editor (Ace) with syntax highlighting for common text formats
- Inline preview for images, video, audio, PDF, and text/code files
- Recursive search by name/extension
- ZIP create/extract with Zip Slip protection
- Visual + numeric permissions editor (chmod)
- Activity log (login, upload, delete, rename, permission changes, …)
- Settings page (upload limits, allowed/blocked extensions, session timeout)
- Hardened against path traversal, symlink escape, null-byte injection,
  and command injection — see `SECURITY.md`

## Requirements

- Linux VPS (tested against a 4GB RAM / 512GB disk profile, but scales down)
- PHP 8.2+ with: `pdo_sqlite`, `json`, `fileinfo`, `openssl`, `mbstring`, `zip`
- Apache (with `mod_rewrite`, `mod_headers`) or Nginx + PHP-FPM
- Shell access to run `install.sh` (bash)

No MySQL/MariaDB is required. No Node/npm build step is required — the
front end is vanilla JS/CSS plus two CDN-loaded libraries (Chart.js for
the storage donut chart, Ace for the code editor).

## Quick Install

```bash
# 1. Upload/clone the project to your VPS, e.g.:
cd /var/www
git clone <your-repo-or-upload> storage-panel
cd storage-panel

# 2. Run the installer
bash install.sh
```

The installer will:

1. Detect your OS and PHP version
2. Verify required PHP extensions are installed
3. Create `database/` and `logs/` directories
4. Ask for your desired `STORAGE_ROOT` (refuses system paths like `/`, `/etc`, `/root`, `/proc`, `/sys`, `/var/lib`, `/boot`)
5. Ask for an admin username and password (min. 8 characters)
6. Write `config.php` with your chosen `STORAGE_ROOT`
7. Initialize the SQLite database and create the admin account
8. Set safe permissions and attempt to `chown` to your web server user
9. Test write access to `STORAGE_ROOT`
10. Test real disk-size detection
11. Print your panel URL and next steps

After installation, point your web server's document root at the project
folder (see `apache/storage-panel.conf` or `nginx/storage-panel.conf`),
reload the web server, and visit the panel URL to log in.

## Manual Installation

If you prefer not to run `install.sh`:

```bash
mkdir -p database logs
chmod 750 database logs
php database/init.php <admin_username> <admin_password> /var/www/storage
chown -R www-data:www-data database logs /var/www/storage   # adjust user as needed
```

Then edit `config.php` and set `STORAGE_ROOT` to your chosen path (or
export a `STORAGE_ROOT` environment variable for your web server/PHP-FPM
pool, which takes precedence).

### Apache

1. Copy `apache/storage-panel.conf` to `/etc/apache2/sites-available/`
2. Edit the `ServerName`, `DocumentRoot`, and storage-root paths
3. `sudo a2ensite storage-panel && sudo a2enmod rewrite headers`
4. `sudo systemctl reload apache2`

The bundled root `.htaccess` (plus per-directory `.htaccess` files in
`database/`, `includes/`, `logs/`, `storage/`) blocks direct web access to
sensitive paths even if someone guesses the URL.

### Nginx

1. Copy `nginx/storage-panel.conf` to `/etc/nginx/sites-available/`
2. Edit `server_name`, `root`, and the PHP-FPM socket path
3. Symlink into `sites-enabled`, then `sudo nginx -t && sudo systemctl reload nginx`

Nginx doesn't read `.htaccess`, so the sample config explicitly denies
`/database/`, `/includes/`, `/logs/`, `/storage/`, dotfiles, and sensitive
extensions.

**Either way: enable HTTPS** (e.g. via Certbot) and then set
`FORCE_SECURE_COOKIE = true` in `config.php` so session cookies require TLS.

## Directory Structure

```
storage-panel/
├── index.php              # Main dashboard / file manager shell
├── login.php / logout.php
├── config.php              # STORAGE_ROOT + all tunables
├── install.sh
├── database/
│   ├── init.php             # DB + admin account bootstrap (called by install.sh)
│   └── panel.sqlite         # created on first run
├── api/                     # JSON endpoints, each requires login + CSRF
│   ├── stats.php            # live system/storage stats
│   ├── files.php            # list/mkdir/newfile/delete/rename/move/copy/chmod/properties/search-adjacent
│   ├── upload.php           # chunked upload
│   ├── download.php         # authenticated streaming download (range support)
│   ├── preview.php          # inline preview streaming
│   ├── edit.php             # text editor load/save
│   ├── zip.php               # archive create/extract
│   ├── search.php           # filename/extension search
│   ├── settings.php         # panel settings get/update
│   └── activity.php         # activity log list/clear
├── includes/
│   ├── auth.php              # login/logout/session helpers
│   ├── security.php          # CSRF, headers, session hardening, rate limiting
│   ├── filesystem.php        # STORAGE_ROOT jail + all disk operations
│   ├── stats.php             # /proc + safe shell_exec based system stats
│   ├── db.php                 # SQLite bootstrap + settings/activity helpers
│   └── helpers.php
├── assets/
│   ├── css/app.css
│   └── js/{app.js, icons.js}
├── apache/storage-panel.conf
├── nginx/storage-panel.conf
├── .htaccess (+ per-directory .htaccess files)
├── README.md
└── SECURITY.md
```

Note on architecture: the original spec listed separate `mkdir.php`,
`rename.php`, `move.php`, `copy.php`, `delete.php`, `chmod.php` files.
This build consolidates those into `api/files.php?action=...` — same
functionality, fewer near-duplicate files to keep in sync, and it's the
single choke point that all calls `resolve_safe_path()` through.

## Configuration Reference (`config.php`)

| Constant | Purpose |
|---|---|
| `STORAGE_ROOT` | The only directory tree the panel can touch. |
| `SESSION_TIMEOUT_SECONDS` | Idle session timeout (default 1800s). |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_SECONDS` | Login rate limiting. |
| `FORCE_SECURE_COOKIE` | Set `true` once HTTPS is active. |
| `CHUNK_SIZE_BYTES` | Upload chunk size (default 5MB). |
| `EDITABLE_EXTENSIONS` | Which file types open in the code editor. |

Runtime settings (upload size cap, allowed/blocked extensions, session
timeout, auto-refresh interval) are editable from the Settings tab and
stored in SQLite — no file edit or restart required.

## Troubleshooting

**"The PHP zip extension is not installed"**
Install it: `sudo apt install php8.2-zip` (or your distro's equivalent),
then restart PHP-FPM/Apache.

**Uploads fail partway through large files**
Check `logs/app.log`. Common causes: disk full, `STORAGE_ROOT` not
writable by the web server user, or a reverse proxy in front of
Nginx/Apache with its own body-size limit (e.g. Cloudflare, another
Nginx level) that needs raising separately.

**CPU/RAM/uptime show as 0 or "Unknown"**
The panel reads `/proc/stat`, `/proc/meminfo`, `/proc/cpuinfo`, and
`/proc/uptime` directly. This works on standard Linux VPS instances but
not inside some restricted containers where `/proc` is masked — in that
case those specific metrics will show as unavailable, but the file
manager and disk stats (which use PHP's `disk_total_space`/`disk_free_space`)
still work normally.

**Login keeps failing / "Too many failed attempts"**
Rate limiting locks an IP out for `LOGIN_LOCKOUT_SECONDS` (15 minutes by
default) after `LOGIN_MAX_ATTEMPTS` (5) failures. Wait it out, or reset by
clearing the `login_attempts` table in `database/panel.sqlite` if you
have direct server access and are sure it's you.

**"Access outside storage root is not allowed"**
This is the path-jail working as intended — every operation is
re-validated against `STORAGE_ROOT` on every request. If you believe this
is wrong, check that `STORAGE_ROOT` in `config.php` points to a real,
non-symlinked directory and that the path you're trying to reach is
actually inside it.

**Permission denied writing files**
Ensure `STORAGE_ROOT`, `database/`, and `logs/` are owned by (or
group-writable by) your web server user (`www-data`, `nginx`, etc.):
`chown -R www-data:www-data /var/www/storage database logs`.

**500 error with no detail shown**
By design, stack traces and internal paths are never shown to the
browser. Check `logs/app.log` (or your web server's PHP error log) for
the real error.

## Security

See `SECURITY.md` for the full threat model and hardening details
(path traversal, Zip Slip, CSRF, session handling, command execution
safety, and the download/preview authentication flow).
