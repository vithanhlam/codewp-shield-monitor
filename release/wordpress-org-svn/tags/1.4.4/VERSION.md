# CodeWP Shield Monitor Version Information

Version: 1.4.4
Release date: 2026-09-24
Requires at least: WordPress 6.4
Tested up to: WordPress 7.0
Requires PHP: 7.4
License: GPLv2 or later

## Highlights

- Expanded integrity monitoring scope and coverage reporting.
- Added conditional redirect indicators and explicit partial-scan reporting.
- Scheduled automatic integrity checks for off-peak hours and deferred login-triggered malware scans.

- Fixed fatal error during plugin uninstall when dependent classes were not loaded.
- Softened UI placeholder text that could trigger hosting malware scanner false positives.

## Release Checklist

- `shield-monitor.php` version matches `readme.txt` Stable tag.
- Built admin JavaScript exists at `assets/js/admin.js`.
- Development files are excluded from release packages.
- WordPress.org assets are stored separately from the installable plugin zip.
