# CodeWP Shield Monitor Version Information

Version: 1.3.2
Release date: 2026-06-26
Requires at least: WordPress 6.5
Tested up to: WordPress 7.0
Requires PHP: 7.4
License: GPLv2 or later

## Highlights

- Hidden login path controls with failed-login IP block and unlock management.
- Plugin and theme lifecycle audit events exposed in wp-admin and authenticated API responses.
- Malware scan cache for clean unchanged files using SHA-256 hashes.
- External JavaScript and external URL detection outside the current site domain.
- Fallback file-integrity scans when WP-Cron is overdue and an administrator opens wp-admin.
- App/Web quick-login status now exposes method/flow metadata and auto-heals old unconfigured quick-login options to the default enabled state.

## Release Checklist

- `shield-monitor.php` version matches `readme.txt` Stable tag.
- Built admin JavaScript exists at `assets/js/admin.js`.
- Development files are excluded from release packages.
- WordPress.org assets are stored separately from the installable plugin zip.
