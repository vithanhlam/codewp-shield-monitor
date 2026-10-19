# CodeWP Shield Monitor Version Information

Version: 1.5.0
Release date: 2026-10-19
Requires at least: WordPress 6.4
Tested up to: WordPress 7.1
Requires PHP: 7.4
License: GPLv2 or later

## Highlights

- Optional early UDRPC transport blocking, independent of WAF and admin identity.
- Installation lock also covers multisite super-admin capability checks.
- Reviewed offline core/plugin CVE alerts with exact version boundaries.
- Removed paid plans, licensing, premium addons and license-based metrics push.
- Preserved built-in features, App pairing and token-scoped APIs.

## Release Checklist

- `shield-monitor.php` version matches `readme.txt` Stable tag.
- Built admin JavaScript exists at `assets/js/admin.js`.
- Development files are excluded from release packages.
- WordPress.org assets are stored separately from the installable plugin zip.
