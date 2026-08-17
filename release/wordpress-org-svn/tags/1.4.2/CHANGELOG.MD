# Changelog

All notable changes to CodeWP Shield Monitor (ShieldPress) are documented in this file.

---

## 1.4.2 — 2026-07-25

### Security — wp2shell Virtual Patches (CVE-2026-63030 / CVE-2026-60137)

- **Always-active virtual patch** for CVE-2026-63030: blocks unauthenticated access to the REST API batch endpoint (`/batch/v1`), preventing the batch-route confusion RCE attack. Logged in users (Gutenberg) are unaffected.
- **Always-active virtual patch** for CVE-2026-60137: sanitizes `author__not_in` query parameter to always be an array of integers, preventing the scalar string SQL injection vector in `WP_Query`.
- **WAF POST body inspection**: the WAF now inspects `rest_route` in the POST body regardless of `is_admin()` context, closing the URL-only WAF bypass used in wp2shell exploits.
- **New WAF rule** `wp2shell_batch_route`: detects `/batch/v\d` patterns in request URI and parameters (active when WAF is enabled).
- **3 new threat patterns** for wp2shell indicators:
  - `builtin_wp2shell_batch_payload` (WAF rule): batch API sub-request with encoded/malformed path.
  - `builtin_wp2shell_author_notin_sqli` (WAF rule): `author__not_in` with SQL metacharacters.
  - `builtin_wp2shell_shell_upload` (file signature): webshell containing wp2shell/WP-SHELLSTORM indicators.

### New Feature — REST API Management Panel

#### Plugin Namespace Access (Tab 1)
- New **REST API** sidebar panel for centralized API access control.
- **Auto-detects** all installed plugins with REST API namespaces using `rest_get_server()->get_namespaces()`.
- Results cached as a transient (12-hour TTL), automatically invalidated on plugin activation/deactivation.
- Each detected namespace shown with plugin name, namespace slug, and toggle switch.
- **Recommendation badges** for namespaces that typically need public access: Contact Form 7 ("Required for form submissions"), WooCommerce Store ("Required for Cart & Checkout blocks"), CookieYes ("Required for cookie consent banner").
- Known namespace lookup table maps common prefixes (wc, rankmath, litespeed, cky, etc.) to human-readable plugin names.
- Core namespaces (`wp/v2`, `oembed`) and ShieldPress own namespace excluded from list — managed separately.
- **Master toggle** `restrict_rest_api` moved from Settings panel to this panel.
- `oEmbed` namespace now always allowed when REST restriction is active (prevents embed preview breakage).

#### Core API Controls (Tab 2)
- 7 granular controls for WordPress core REST API operations:

| Control | Default | Blocks |
|---|---|---|
| Block user creation | **ON** | POST/PUT/PATCH/DELETE on `/wp/v2/users` |
| Block plugin management | **ON** | All methods on `/wp/v2/plugins` (completely hidden) |
| Block settings access | **ON** | Write methods on `/wp/v2/settings` |
| Block site export | **ON** | `/wp/v2/export` |
| Block application passwords | **ON** | `application-passwords` routes |
| Block media upload | OFF | POST on `/wp/v2/media` |
| Block post/page creation | OFF | POST on `/wp/v2/posts` and `/wp/v2/pages` |

- Controls work **independently** of the master Restrict REST API toggle — they apply to all non-administrator users including authenticated editors/authors.
- Administrator users (`manage_options`) are always exempt.
- Enforcement uses `rest_endpoints` filter to remove route+method combinations.

### Settings Changes
- New settings: `rest_api_public_namespaces` (array), `rest_api_block_user_creation`, `rest_api_block_media_upload`, `rest_api_block_post_creation`, `rest_api_block_plugin_management`, `rest_api_block_settings_access`, `rest_api_block_export`, `rest_api_block_app_passwords`.
- Removed: `rest_api_allowed_namespaces` (textarea string) — replaced by `rest_api_public_namespaces` (array).
- Removed old REST API whitelist textarea and toggle JS from Settings panel.

---

## 1.4.1 — 2026-07-13

### Performance Improvements

#### Audit Log — Batch INSERT & Rate-Limiting
- Events are now buffered in memory during a request and flushed as a **single multi-row INSERT** on the `shutdown` hook, reducing DB writes from N queries to 1.
- **Rate-limiting**: when the same `event_type + IP` exceeds 20 events within 5 minutes, further events are suppressed and a single `rate_limited` summary event is logged instead. Prevents DB flood during brute-force attacks.
- New event type: `rate_limited` (category: system, severity: low).

#### Audit Log — Batch DELETE Cleanup
- Daily cleanup now deletes old events in **batches of 500** rows with a loop (max 5,000 per run), preventing table locks on large datasets.

#### Malware Cache — Auto-Cleanup
- Stale entries in `wp_shield_monitor_malware_cache` older than **14 days** are now automatically purged during the daily cleanup cron, preventing unbounded table growth.

#### File Monitor — Snapshot Hash Optimization
- Fixed **double SHA256 hash** computation: each file was hashed with `hash_file('sha256')` twice per scan. Now computed once and reused. Reduces I/O by ~33%.

#### File Monitor — Admin Init Scan Deferral
- `maybe_scan_when_cron_overdue()` no longer runs the file scan **synchronously** on `admin_init`. Instead it schedules a cron event to run 10 seconds later.
- Lock increased from 5 minutes to **30 minutes**, overdue threshold from 15 to 30 minutes.
- Result: admin page loads are **no longer blocked** by file integrity scans.

#### File Monitor — Batch UPDATE in Baseline Sync
- Unchanged files are now updated with a **single batch UPDATE** per 500 paths instead of individual UPDATE queries per file.
- Deleted files are also batch-updated in one query.
- Reduces queries per scan from **~1,500 to ~3–5**.

### Security — Threat Patterns

#### 29 New Starter Patterns (9 → 38 total)
- **16 new file signatures**: variable function invocation, `create_function()`, `call_user_func` from user input, `preg_replace /e`, hex-encoded calls, XOR decryption loops (Sign1 malware), plugin reinfector (`file_put_contents` to plugin paths), `phar://`/`zip://` stream wrappers, fake `wp_` functions with eval/exec, credential harvesting to file, `unserialize()` on user input (CVE-2024-8353, CVE-2025-7384), direct `$wpdb` write to `wp_users` (mu-plugins backdoor 2025), `str_rot13` + remote fetch, `str_replace` function name assembly, GIF/PHP polyglot files, alternative PHP extensions in uploads.
- **7 new database patterns**: credit card skimmer checkout-page activation, payment field event capture, `String.fromCharCode` array injection (Balada Injector), obfuscator.io hex arrays, referrer-based TDS redirects (Sign1/SocGholish), PHP eval payloads in `wp_options`, `btoa` + `fetch` POST exfiltration.
- **6 new WAF rules**: PHP Object Injection in requests (CVE-2024-8353), `LOAD_FILE`/`INTO OUTFILE` SQL injection, `extractvalue`/`updatexml` error-based SQLi (CVE-2024-27956), SSRF internal network IP blocking (127.0.0.1, 169.254.x, 10.x, 192.168.x), CRLF/HTTP header injection, XML-RPC pingback SSRF/DDoS.
- All 38 regex patterns validated with PHP `preg_match()`.

### UI Changes
- Removed duplicate **Maximum login attempts** and **Lockout duration** fields from the Settings page. These are now only configurable in **Login Access → Brute-force protection** on the Security Dashboard.
- Settings page `login_protection` checkbox description updated to direct users to Login Access for configuration.

---

## 1.4.0 — 2026-07-12

### New Features (Free)

#### Backup Overdue Alerts
- Sends alerts when no backup has been detected for an extended period.
- Three escalating thresholds: **3 days** (notice), **7 days** (warning), **30 days** (critical).
- Auto-detects last backup time from popular plugins: UpdraftPlus, BackWPup, WPvivid, Duplicator, BlogVault, Jetpack.
- Sends alerts via email to admin, admin dashboard notice, and audit log event (`backup_overdue_alert`).
- Fires `shield_monitor_backup_overdue` action hook for external consumers (mobile push, API).
- New REST endpoint: `GET /wp-json/codewp-shield-monitor/v1/backup-status`.
- New field `backup_alert` in the `/status` API response.
- Setting: `alert_backup_overdue` (enabled by default).

#### Vulnerability Alerts (CVE)
- Scans all installed plugins for known security vulnerabilities using the WordPress.org vulnerability database.
- Checks twice daily and immediately after plugin updates.
- Two detection methods: WordPress `update_plugins` transient (WP 6.3+) and direct WordPress.org Plugin API query.
- Only alerts for vulnerabilities affecting the currently installed version (checks `fixed_in` field).
- Extracts CVE IDs from vulnerability title, references, and dedicated fields.
- Maps severity from CVSS scores: critical (9.0+), high (7.0+), medium (4.0+), low.
- Sends alerts via email with CVE details, admin dashboard notice (red/yellow by severity), and audit log event (`vulnerability_detected`).
- Fires `shield_monitor_vulnerability_detected` action hook.
- New REST endpoint: `GET /wp-json/codewp-shield-monitor/v1/vulnerabilities`.
- New field `vulnerabilities` in the `/status` API response with counts, severity breakdown, and affected plugin names.
- Setting: `alert_vulnerability` (enabled by default).

#### Upload File Size Limit
- Reject images that exceed a configurable maximum file size before they are saved to disk.
- Default: 1024 KB (1 MB). Configurable range: 100 KB – 20480 KB (20 MB).
- Only applies to `image/*` file types.
- Uploads from **Media → Add New** are exempt so editors can upload banners and full-size originals.
- Clear error message: "This image is too large (2.5 MB). Maximum allowed size is 1 MB."
- Settings: `upload_limit_enabled`, `upload_max_size_kb`.

#### Auto-Resize Large Images on Upload
- Automatically resize images wider than a configurable maximum width.
- Height is adjusted automatically to maintain aspect ratio (proportional resize).
- Default maximum width: 1920 px. Configurable range: 200 – 10000 px.
- Uses WordPress native `wp_get_image_editor()` for safe resizing (supports GD and Imagick).
- JPEG quality set to 90% for resized images.
- Only applies to JPEG and PNG uploads.
- Uploads from **Media → Add New** are exempt.
- If resize fails for any reason, the original image is kept untouched.
- Settings: `image_resize_enabled`, `image_max_width`.

#### Media Library Upload Bypass
- Uploads from **Media → Add New** (`media-new.php` / `upload.php`) skip all media optimization:
  - No file size limit check
  - No auto-resize
  - No WebP conversion
- This allows editors to upload banners, full-resolution originals, and other large assets without restrictions.
- Uploads from post editor, Gutenberg blocks, WooCommerce product pages, and other contexts are still processed normally.
- Custom filter `shield_monitor_skip_media_optimization` available for developers to extend bypass logic.

### New Features (Pro — requires license)

#### License System
- New licensing system to unlock premium addons and features.
- License key format: `SPMP-XXXX-XXXX-XXXX-XXXX`.
- Connects to `https://api.shieldpress.net/api/licenses/` for activation, verification, and deactivation.
- Supports two billing types: **Annual** and **Lifetime**.
- Supports two license sources: **Standalone** (purchased directly) and **SaaS** (included with monitoring plan).
- Daily automatic license verification via cron.
- Site fingerprint (`sha256` of site URL + table prefix + AUTH_KEY) sent with every request for anti-piracy domain binding.
- Callback verification endpoint: `POST /wp-json/codewp-shield-monitor/v1/license-verify` — allows the API server to verify the plugin is genuinely installed on the claimed site.
- Grace period: 7 days after expiry before Pro features are disabled.
- Expiry warnings in admin: 30 days (yellow), 7 days (red), grace period, expired.
- Plans: Free, Pro ($49/yr or $149 lifetime), Business ($99/yr or $299 lifetime), Agency ($199/yr or $499 lifetime).
- Options: `shieldpress_license_key`, `shieldpress_license_status`, `shieldpress_license_data` (transient).
- Purchase URL: https://shieldpress.net/shield-monitor-pro

#### Addon System
- New `addons/` directory for premium addon modules.
- Addons are automatically discovered and loaded based on the active license plan.
- Each addon has a standard header format: Addon Name, Addon Slug, Requires Plan, Description, Version.
- Addons are only loaded when `is_feature_allowed(slug)` returns true for the active license.
- `Shield_Monitor_Addons` class handles discovery, header parsing, and conditional loading.

#### Premium Admin Page
- New **Premium** submenu under Shield Monitor (always visible).
- Without license: shows an upsell page with feature cards for all premium addons (Media Pro, Advanced Firewall, White Label).
- With license: shows the active addon UIs with tab navigation for multiple addons.
- Badge `[PRO]` shown next to menu label when no license is active.

#### Media Pro Addon
- Bulk convert all existing PNG/JPEG images in the media library to WebP format.
- Converts main files and all registered thumbnail sizes.
- Original files are always preserved as backup (stored in `_shield_monitor_original_file` post meta).
- Safe conversion pipeline:
  1. Convert main file → verify WebP is valid (RIFF/WEBP magic bytes, minimum file size).
  2. Convert each thumbnail individually — failures don't affect other thumbnails.
  3. Update database ONLY after all conversions are verified.
  4. If main conversion fails → rollback all created WebP files, keep original untouched.
  5. If DB update fails → rollback all files, restore original attachment record.
- Full original state saved for restore: `_shield_monitor_original_file`, `_shield_monitor_original_mime`, `_shield_monitor_original_metadata`.
- Scan-first workflow for thumbnail cleanup:
  1. Click "Scan Uploads Folder" → scans for orphaned thumbnails.
  2. Review results: count, total size (MB), last scanned time.
  3. Click "Delete Orphaned Thumbnails" → batch delete with progress.
- Three safety layers for thumbnail cleanup:
  1. Content reference check: scans `post_content` for filename references — skips files used in posts.
  2. Original file check: only deletes thumbnails whose full-size original still exists — prevents deleting the only copy.
  3. Metadata registry check: cross-references `_wp_attachment_metadata` to ensure file isn't registered.
- Small batch sizes for web UI: 10 images/batch for WebP conversion, 5 files/batch for cleanup.
- Suggestion to use WP-CLI for large cleanups (500+ files).
- Restore feature: revert WebP-converted images back to their original format and metadata.

##### WP-CLI Commands
- `wp shieldpress media scan` — Scan uploads folder for orphaned thumbnails and display report.
- `wp shieldpress media convert [--quality=82] [--limit=0] [--dry-run]` — Bulk convert images to WebP with progress bar.
- `wp shieldpress media cleanup [--yes] [--dry-run] [--limit=0]` — Delete orphaned thumbnails with confirmation and progress.
- `wp shieldpress media restore [--yes] [--dry-run] [--limit=0]` — Restore WebP-converted images to originals.
- CLI uses larger batch sizes (50 files) for better performance and no timeout issues.
- All commands support `--dry-run` for safe preview.

### Admin UI Changes

#### License Page
- New submenu: **Shield Monitor → License**.
- Custom styled page with header bar showing current plan badge and status indicator.
- Two-column layout: License Details (left) + Plans & Pricing comparison (right).
- Plans comparison table with custom check/cross circle icons.
- Column highlight for the currently active plan.
- Centered activation form with shield icon and monospace key input.
- Expiry warning banners (yellow, red, grace, expired) with renewal link.
- SaaS license notice with link to manage subscription.
- Available Addons section with status indicators (active/inactive).

#### Dashboard Improvements
- Admin notices from other plugins no longer appear inside the ShieldPress dashboard content.
- Added `<hr class="wp-header-end">` to all pages (Dashboard, License, Premium) to properly separate WordPress admin notices.
- Media section sidebar description updated to reflect all four sub-features.

#### Media Section Tabs
- Expanded from 2 tabs to 4:
  - **Image sizes** — Disable extra thumbnail generation.
  - **Upload limit** — Set maximum file size for image uploads.
  - **Auto resize** — Set maximum width, height auto-proportional.
  - **WebP conversion** — Auto-convert to WebP on upload.
- All media tabs note that uploads from Media → Add New are exempt.

### API Changes

#### New Endpoints
- `GET /wp-json/codewp-shield-monitor/v1/backup-status` — Backup alert status with thresholds and alert level.
- `GET /wp-json/codewp-shield-monitor/v1/vulnerabilities` — List of detected CVE vulnerabilities.
- `POST /wp-json/codewp-shield-monitor/v1/license-verify` — Callback verification for license anti-piracy.

#### Status API Updates
- New `backup_alert` field: enabled, last_backup_at, days_since, alert_level, thresholds.
- New `vulnerabilities` field: enabled, last_checked_at, total_count, critical_count, high_count, plugins_affected.
- New event types in event catalog: `backup` category (backup_overdue_alert, backup_detected), `vulnerability` category (vulnerability_detected, vulnerability_resolved).

### New Settings
- `alert_backup_overdue` — Enable backup overdue alerts (default: on).
- `alert_vulnerability` — Enable CVE vulnerability alerts (default: on).
- `upload_limit_enabled` — Enable image upload file size limit (default: off).
- `upload_max_size_kb` — Maximum image upload size in KB (default: 1024).
- `image_resize_enabled` — Enable auto-resize on upload (default: off).
- `image_max_width` — Maximum image width in pixels (default: 1920).

### New Files
- `includes/class-shield-monitor-backup-alert.php` — Backup overdue detection and alerting.
- `includes/class-shield-monitor-vulnerability-alert.php` — CVE vulnerability scanning and alerting.
- `includes/class-shield-monitor-license.php` — License key management, activation, verification.
- `includes/class-shield-monitor-license-page.php` — License admin page UI.
- `includes/class-shield-monitor-addons.php` — Addon discovery and conditional loading.
- `includes/class-shield-monitor-addon-pages.php` — Premium admin page (upsell + addon content).
- `addons/media-pro/addon.php` — Media Pro addon entry file.
- `addons/media-pro/class-media-pro.php` — Bulk WebP conversion, thumbnail cleanup, restore, WP-CLI.
- `addons/media-pro/media-pro.js` — Frontend AJAX batch processing with progress bars.

### Technical Notes
- Backup alert cron: `shield_monitor_backup_check` (daily).
- Vulnerability check cron: `shield_monitor_vulnerability_check` (twice daily).
- License verification: piggybacks on existing `shield_monitor_daily_cleanup` cron.
- All new cron hooks properly cleared on plugin deactivation and uninstall.
- All new options properly deleted on plugin uninstall.

---

## 1.3.5

- Security dashboard with comprehensive settings UI.
- Malware scanner with scheduled and manual scans.
- File integrity monitoring with core checksum verification.
- Login protection with brute-force lockouts.
- WAF with request filtering and honeypot.
- Threat intelligence pattern detection.
- Token-authenticated REST API with pairing system.
- Quick login via mobile app.
- Email alerts for admin login, lockouts, file changes, malware findings.
- Notification events for Contact Form 7 and WooCommerce orders.
- Security headers, user enumeration blocking, XML-RPC control.
- Admin PIN protection and CAPTCHA integration.
- Media optimization: disable image sizes, WebP conversion on upload.
- Database cleanup tools.
- Privacy-first design: IP addresses irreversibly hashed, no external data sharing by default.
