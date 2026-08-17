# CodeWP Shield Monitor Version Information

Version: 1.4.2
Release date: 2026-07-25
Requires at least: WordPress 6.4
Tested up to: WordPress 7.0
Requires PHP: 7.4
License: GPLv2 or later

## Highlights

- wp2shell virtual patches (CVE-2026-63030, CVE-2026-60137) — always active.
- REST API management panel with auto-detected plugin namespace whitelist and core endpoint controls.
- 7 core REST API endpoint restrictions for non-admin users (user creation, plugin management, settings, export, app passwords).
- 3 new wp2shell threat detection patterns.
- Backup overdue alerts: 3-day, 7-day, and 30-day thresholds with email, admin notice, and API notifications.
- CVE vulnerability scanning: detects known security vulnerabilities in installed plugins using WordPress.org database.
- Upload file size limit: reject images exceeding configurable max size (default 1 MB) before saving to disk.
- Auto-resize large images: resize by width on upload, height auto-proportional (default max 1920 px).
- Media Library bypass: uploads from Media → Add New skip all optimization (resize, WebP, size limit) for banners and originals.
- License system with domain-binding, site fingerprint, callback verification, and daily auto-verification.
- Addon architecture: `addons/` directory with auto-discovery, header parsing, and license-gated loading.
- Premium admin page: upsell view for free users, tabbed addon UI for licensed users.
- Media Pro addon: bulk WebP conversion with safe pipeline (verify before DB update, rollback on failure), orphaned thumbnail cleanup with 3-layer safety checks, restore feature.
- WP-CLI commands: `wp shieldpress media scan/convert/cleanup/restore` with progress bars, dry-run, and batch control.
- Admin notices no longer render inside ShieldPress page content (wp-header-end fix).

## Release Checklist

- `shield-monitor.php` version matches `readme.txt` Stable tag.
- Built admin JavaScript exists at `assets/js/admin.js`.
- Development files are excluded from release packages.
- WordPress.org assets are stored separately from the installable plugin zip.
