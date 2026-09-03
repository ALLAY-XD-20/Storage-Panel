# Security Documentation

This document describes the threat model and the specific mitigations
implemented in Storage Panel. Read this before exposing the panel to the
internet.

## 1. Path Jail (STORAGE_ROOT confinement)

**Threat:** a malicious or malformed path lets a user read/write/delete
files outside the intended storage directory (`../../etc/passwd`, absolute
paths, URL-encoded traversal, null-byte truncation, symlink escape).

**Mitigation:** every single filesystem operation — list, read, write,
delete, rename, move, copy, chmod, zip, download, preview — passes
through `resolve_safe_path()` in `includes/filesystem.php`, which:

1. Rejects null bytes outright.
2. Normalizes `/` separators and strips leading slashes so input is
   always treated as relative to `STORAGE_ROOT`.
3. Defensively `rawurldecode()`s once and re-checks for null bytes
   (guards against double-encoded traversal).
4. Manually collapses `.`/`..` segments (needed because `realpath()`
   returns `false` for paths that don't exist yet, e.g. new files).
5. Verifies the collapsed path is inside `STORAGE_ROOT` as a string
   prefix check.
6. If the path exists, calls `realpath()` on it and **re-verifies** the
   resolved (symlink-followed) path is still inside `STORAGE_ROOT` —
   this is what prevents a symlink planted inside `STORAGE_ROOT` from
   pointing somewhere else on the server and being followed.
7. For new paths (create operations), verifies the *parent* directory
   resolves inside `STORAGE_ROOT` before allowing the write.

No API endpoint constructs a filesystem path any other way. `files.php`,
`upload.php`, `download.php`, `preview.php`, `edit.php`, and `zip.php`
all call this function before touching disk.

## 2. Zip Slip Protection

**Threat:** a malicious ZIP archive contains entries like
`../../../../var/www/html/shell.php` that, if extracted naively, write
outside the intended destination.

**Mitigation** (`api/zip.php`, `extract` action):

- Every entry name is checked for null bytes and rejected if present.
- Entry names are split on `/` and any entry containing a literal `..`
  segment is skipped (not extracted).
- The computed absolute target path is independently collapsed and
  re-verified to sit inside the resolved destination directory before
  any bytes are written; entries that would escape are skipped, not
  aborting the whole extraction (so a legitimate archive with one bad
  entry still extracts everything safe).
- Extraction destination itself must first pass through
  `resolve_safe_path()`, so even the "safe" entries can't land outside
  `STORAGE_ROOT`.

## 3. Command Execution Safety

**Threat:** shell command injection via user-controlled input reaching
`shell_exec`/`exec`.

**Mitigation:** `includes/stats.php` never builds a shell command string
from request input. Every call to `safe_shell()` passes a fixed, hardcoded
command literal (currently only used as a fallback for detecting the
server's primary IP via `hostname -I`). All other system stats (CPU, RAM,
disk, uptime, OS info) are read directly from `/proc/*` and PHP's native
`disk_total_space()`/`disk_free_space()`/`sys_getloadavg()` — no shell
invocation at all for the vast majority of stats. If `shell_exec` is
disabled in `php.ini` (`disable_functions`), the panel detects this via
`shell_supported()` and simply omits the one shell-derived value rather
than erroring.

No user input is ever interpolated into a shell command anywhere in this
codebase.

## 4. Authentication & Session Security

- Passwords hashed with `password_hash()` (bcrypt/argon2 depending on
  PHP build), verified with `password_verify()`.
- `session_regenerate_id(true)` on every successful login (prevents
  session fixation).
- Session cookies: `HttpOnly`, `SameSite=Strict`, and `Secure` once
  `FORCE_SECURE_COOKIE` is enabled (do this after configuring HTTPS).
- Idle session timeout (`SESSION_TIMEOUT_SECONDS`, default 30 minutes) —
  enforced server-side on every request, not just client-side.
- Login rate limiting: 5 failed attempts per IP locks that IP out for 15
  minutes (`LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_SECONDS`), tracked in
  SQLite so it survives restarts.
- A small `usleep()` on failed login attempts to reduce the value of
  timing-based username enumeration.
- Logout invalidates the session server-side and expires the cookie.

## 5. CSRF Protection

Every state-changing request (`POST`/`PUT`/`DELETE`/`PATCH`) to any
`api/*.php` endpoint is rejected unless it carries a valid CSRF token
(`includes/security.php: require_csrf()`), checked via `hash_equals()`
against the token stored in the server-side session. The token is
embedded in a `<meta>` tag on page load and sent as an `X-CSRF-Token`
header (or JSON body field) by the front end on every mutating call.

## 6. XSS Protection

- All dynamic values inserted into HTML on the PHP side go through `h()`
  (an `htmlspecialchars()` wrapper).
- The front end builds DOM content via `escapeHtml()` for any
  user-controlled string (file/folder names, paths, search queries)
  before inserting into `innerHTML`.
- A `Content-Security-Policy` header restricts script sources to `self`
  plus the two pinned CDN origins used for Chart.js and Ace, blocks
  framing (`frame-ancestors 'none'`), and restricts object/embed
  sources implicitly via `default-src 'self'`.
- `X-Content-Type-Options: nosniff` prevents MIME-sniffing based XSS on
  downloaded/previewed files.

## 7. Download & Preview Authentication

**Threat:** if uploaded files were served directly by the web server
(e.g. `https://panel.example.com/storage/secret.txt`), authentication
and the path jail would be bypassed entirely.

**Mitigation:**

- `STORAGE_ROOT` is never inside the web server's public document root
  in the recommended deployment (default suggestion: `/var/www/storage`,
  separate from the panel's own directory). Even the bundled
  `storage/` placeholder directory inside the panel folder is blocked
  from direct web access via `.htaccess` / the Nginx config, belt-and-braces.
- Every download goes through `api/download.php`, which: requires an
  authenticated session, resolves and validates the path via
  `resolve_safe_path()`, confirms the target is a regular file (not a
  directory or device), sets `Content-Disposition: attachment`, and
  streams it in 1MB chunks (never loads the whole file into memory).
- `api/preview.php` follows the same authentication and path-validation
  flow for inline (non-download) previews, with a 500KB cap on text
  preview size.
- HTTP `Range` requests are supported on both endpoints for efficient
  seeking in large media files, with the requested range validated
  against the actual file size before any data is sent.

## 8. Upload Safety

- Uploads are chunked client-side (5MB chunks) and streamed to disk with
  `fopen`/`fwrite`/`stream_copy_to_stream` — the full file is never
  held in PHP memory, which matters on a 4GB RAM VPS.
- Extension checks run both client-adjacent (via settings) and
  server-side (`is_blocked_extension()` / `is_allowed_extension()`)
  before any chunk is accepted; the default blocklist includes PHP and
  other server-executable extensions specifically to prevent uploading
  a web shell into a web-accessible location.
- A configurable `max_upload_mb` setting is enforced by checking the
  assembled temp file's size after every chunk, so an oversized upload
  is rejected mid-stream rather than after full assembly.
- Final filenames are de-duplicated (`name-1.ext`, `name-2.ext`, …)
  rather than silently overwriting existing files.
- Uploaded files are written with `0640` permissions, not
  world-readable/executable.

## 9. Permission Changes (chmod)

- Only numeric octal modes matching `[0-7]{3,4}` are accepted — no
  symbolic mode strings, no shell involvement (`chmod()` is called via
  PHP's native function, not a shell command).
- The front end requires an explicit confirmation dialog before applying
  any permission change, showing the resulting numeric mode.

## 10. Error Handling & Information Disclosure

- `display_errors` is off; all errors are logged server-side to
  `logs/app.log` (outside the web-accessible `storage/` tree, and itself
  blocked from direct web access).
- API errors return a generic, user-safe message (`json_error()`) —
  never a raw exception message, stack trace, SQL error, or filesystem
  path.
- `PathSecurityException` messages are intentionally generic ("Access
  outside storage root is not allowed.") to avoid confirming the
  existence/structure of paths outside the jail.

## 11. Activity Logging

Every mutating action (login, logout, upload, download, delete, rename,
move, copy, edit, permission changes, ZIP create/extract) is recorded in
SQLite with username, action, affected path, IP address, and timestamp.
File **contents** and passwords are never written to the log. Admins can
clear the log from the Activity view; this action is itself logged
before the table is cleared.

## 12. What This Panel Does Not Protect Against

Being transparent about scope:

- **Server-level compromise.** If an attacker gets root on the VPS, all
  bets are off — this panel only controls what's reachable through it.
- **Weak admin passwords.** The panel enforces a minimum length (8
  characters) at install/creation time but cannot force overall
  password strength. Use a strong, unique password and consider putting
  the panel behind a VPN or IP allowlist for extra defense in depth.
- **Multi-user / per-file ACLs.** Storage Panel implements a single-admin
  model (the installer creates one account; you can create more via
  `database/init.php`, but there is currently no per-user permission
  scoping within `STORAGE_ROOT`).
- **Malware scanning of uploaded content.** The panel restricts
  executable extensions but does not scan file contents for malware.
  If this matters for your use case, add a scanning step (e.g. ClamAV)
  at the web server or OS level.

## Reporting

If you find a security issue in this codebase, review
`includes/filesystem.php` (`resolve_safe_path`) and `api/zip.php`
(`extract`) first, as they are the two highest-value points to audit —
almost every meaningful vulnerability class in a file manager routes
through one of these two chokepoints.
