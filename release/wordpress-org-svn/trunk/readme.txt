=== CodeWP Shield Monitor ===
Contributors: vithanhlam
Tags: security, login, hardening, audit-log, privacy
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first WordPress security hardening, login protection, and local audit logging.

== Description ==

CodeWP Shield Monitor (ShieldPress) adds a careful baseline of WordPress security controls without sending site data to third parties by default.

Monitor your website health anywhere — visit [shieldpress.net](https://shieldpress.net) or download the ShieldPress app on [iOS](https://apps.apple.com/us/app/shieldpress/id6790792856) and [Android](https://play.google.com/store/apps/details?id=com.shieldpress.app) to keep track of your site's security status, receive real-time alerts, and manage protection settings on the go.

**Security & Hardening**

* Rate limits repeated failed logins by hashed IP address.
* Restricts public user enumeration.
* Adds conservative browser security headers.
* Optionally disables XML-RPC.
* Disables dashboard file editing.
* Hides the default login/admin paths behind a custom login slug when enabled.
* Shows failed-login IPs with manual block and unlock controls.
* Adds honeypot fields to login, registration, and comment forms to silently block automated bots.
* Blocks PHP execution inside the uploads directory and prevents uploading dangerous file types.
* Supports comment and registration rate limiting per IP with optional math CAPTCHA challenges.

**Monitoring & Scanning**

* Records a local security audit log with configurable retention (30 days default).
* Monitors important WordPress files once daily during off-peak hours using SHA-256 hashes.
* Runs lightweight suspicious-code and database scans with severity-based findings.
* Adds threat intelligence checks for admin anomalies, executable uploads, suspicious options, cron hooks, MU plugins, fake CAPTCHA content, external scripts, cloaking signals, and hardening gaps.
* Ships 38 built-in threat detection patterns covering web shells, backdoors, obfuscation techniques, credit card skimmers, SEO spam, PHP object injection, SQL injection, SSRF, and more — based on real-world CVEs and active malware campaigns (Balada Injector, Sign1, SocGholish, mu-plugins backdoors).
* Skips previously clean malware-scan files while their SHA-256 hash is unchanged.
* Flags external JavaScript and URLs outside the current site domain in source or database content.
* Lets administrators run manual scans or schedule scans daily, weekly, or monthly.
* Emails alerts for administrator logins, blocked login attacks, file changes, and suspicious scan findings.

**Firewall & Threat Patterns**

* Includes a Web Application Firewall (WAF) to block SQL injection, XSS, path traversal, PHP object injection, SSRF, CRLF injection, and other common attack patterns.
* Provides an extensible threat pattern engine for custom malware signatures, WAF rules, and database content patterns with import/export support.
* Rate-limits audit log events to prevent database flooding during brute-force attacks.

**Activity & Notifications**

* Records recent public content create/update activity and new administrator access.
* Records WordPress core, plugin, and theme update events.
* Records plugin and theme lifecycle events, including activation, deactivation, installs, and updates.
* Pushes Contact Form 7 submissions, WooCommerce orders, and selected custom post type creations to the authenticated events API.
* Provides an incident-response summary with prioritized findings and next review steps.

**App & API Integration**

* Displays basic WordPress security and update status in wp-admin.
* Provides token-authenticated REST endpoints for the ShieldPress App and Web dashboard.
* Pairs the App using a local QR code and a short-lived, one-time exchange code.
* Creates scoped, one-time quick-login URLs for paired App/Web clients when enabled.

**Tools**

* Provides database cleanup tools for spam, revisions, orphaned data, expired transients, and inactive subscriber accounts.
* Offers media optimization with optional thumbnail generation control and automatic WebP conversion on upload.

CodeWP Shield Monitor hashes IP addresses in its 30-day audit log. For failed-login lockout management, it may also store recent source IP addresses, attempt counts, lockout status, and last failed-login time so administrators can block or unlock those IPs. File contents and post body content are never stored.

== Installation ==

1. Upload the `codewp-shield-monitor` folder to `/wp-content/plugins/`.
2. Activate CodeWP Shield Monitor through the Plugins screen.
3. Open CodeWP Shield Monitor in the WordPress dashboard and review the defaults.

== External services ==

CodeWP Shield Monitor can connect to the official WordPress.org checksum API when the administrator enables core checksum verification. The service is used to compare local WordPress core file hashes with official release hashes. It sends the installed WordPress version and site locale at most once every 12 hours; it does not send stored credentials, file contents, full database values, post body content, audit-log IP hashes, API tokens, or CAPTCHA tokens. WordPress.org provides this service under the WordPress.org Terms of Service and Privacy Policy.

Terms: https://wordpress.org/about/terms-of-service/
Privacy: https://wordpress.org/about/privacy/

CodeWP Shield Monitor can connect to Cloudflare Turnstile only when an administrator enables CAPTCHA challenges, selects Cloudflare Turnstile, saves a Turnstile site key and secret key, and chooses the forms to protect. Public pages that may contain selected login, registration, or WooCommerce checkout forms can load Cloudflare's Turnstile JavaScript from challenges.cloudflare.com to display the challenge. During protected form submissions, the plugin sends the Turnstile response token, configured secret key, and visitor IP address to Cloudflare's siteverify endpoint to validate the challenge. This is required for the optional Turnstile CAPTCHA feature.

Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/

CodeWP Shield Monitor can connect to Google reCAPTCHA only when an administrator enables CAPTCHA challenges, selects Google reCAPTCHA v2 or v3, saves a reCAPTCHA site key and secret key, and chooses the forms to protect. Public pages that may contain selected login, registration, or WooCommerce checkout forms can load Google's reCAPTCHA JavaScript from google.com to display or run the challenge. During protected form submissions, the plugin sends the reCAPTCHA response token, configured secret key, and visitor IP address to Google's siteverify endpoint to validate the challenge. When Google reCAPTCHA v3 is selected, the plugin also checks the returned score against the configured threshold, which defaults to 0.1. This is required for the optional Google reCAPTCHA feature.

Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

== Frequently Asked Questions ==

= Does CodeWP Shield Monitor send data to an external service? =

CodeWP Shield Monitor sends the installed WordPress version, locale, and CodeWP Shield Monitor version user-agent to the official WordPress.org checksum API at most once every 12 hours when core checksum verification is enabled. Stored IP fields, credentials, file contents, and post body content are not included; as with any network request, WordPress.org can observe the request's source IP. If an administrator explicitly pairs CodeWP Shield Monitor App or Web, that client can request site security and monitoring data from token-protected REST endpoints hosted on the site. The administrator can disable checksum verification or revoke client access at any time.

= What audit data is stored locally? =

CodeWP Shield Monitor stores 30-day history in its local event table for login, lockout, user-role, administrator-access, public content create/update, notification, plugin/theme lifecycle, software-update, file-change, and suspicious-code scan metadata. Content audit entries store metadata such as post ID, public post type, title, status, changed field names, WordPress user ID, and username when available. Notification events can store selected Contact Form 7 field values, WooCommerce order metadata, and selected post type creation metadata. File-change entries store relative file labels and cryptographic hashes only. Suspicious-code scan reports store severity, relative file paths or database record labels, heuristic signatures, and short sanitized evidence snippets. File uploads, file contents, full database values, CAPTCHA tokens, post body content, API tokens, and plain-text IP addresses are never stored in the audit log. Recent source IP addresses can be stored separately for failed-login lockout management.

WordPress.org Privacy Policy: https://wordpress.org/about/privacy/

= Can XML-RPC remain enabled? =

Yes. XML-RPC blocking is disabled by default because Jetpack and remote publishing may depend on it.

== Screenshots ==

1. CodeWP Shield Monitor security dashboard with protection score, file integrity, malware scan, and audit log panels.

== Changelog ==

= 1.4.4 =
* Expanded integrity monitoring to parent/child themes, active/inactive plugins, MU-plugins, drop-ins, uploads and configuration/verification files, with coverage and skipped-file reporting.
* Added mobile/touch conditional redirect and meta refresh indicators with a trusted external domain filter.
* Scheduled heuristic scans now identify their file/database limits and partial coverage in reports.
* Moved file integrity scans to 04:15 in the WordPress site timezone; runs requested outside the 01:00–05:59 window are deferred. Malware scans triggered after administrator login are deferred to the configured scan time.

= 1.4.3 =
* Fixed fatal error on plugin uninstall when dependent classes were not loaded by uninstall.php.
* Softened threat-pattern UI placeholder text that could trigger hosting malware scanners (false positives).

= 1.4.2 =
* Added virtual patches for wp2shell critical RCE chain (CVE-2026-63030 and CVE-2026-60137) — always active, no configuration required.
* Added REST API batch endpoint blocking for unauthenticated requests to prevent batch-route confusion attacks.
* Added author__not_in SQL injection sanitization to enforce array-of-integers input on every WP_Query.
* Added POST body inspection for rest_route parameter in both WAF and hardening modules to prevent URL-only WAF bypass.
* Added new REST API management panel with sidebar navigation for centralized API access control.
* Added auto-detection of installed plugin REST API namespaces with toggle-based whitelist UI — replaces manual namespace textarea.
* Added recommendation badges for namespaces that typically need public access (Contact Form 7, WooCommerce Store, CookieYes).
* Added 7 core REST API endpoint controls: block user creation, plugin management, settings access, site export, application passwords, media upload, and post creation for non-administrator users.
* Core API controls work independently of the master Restrict REST API toggle and default to blocking high-risk operations (user creation, plugin management, settings, export, app passwords).
* Added 3 new wp2shell-specific threat patterns: batch API malformed sub-request WAF rule, author__not_in SQLi WAF rule, and webshell upload file signature.
* Added oEmbed namespace to the always-allowed REST API list to prevent embed preview breakage when REST API restriction is enabled.
* Added namespace cache invalidation on plugin activation and deactivation for automatic whitelist updates.
* Moved Restrict public REST API toggle from Settings panel to the new REST API panel.

= 1.4.1 =
* Audit log events are now buffered and written as a single batch INSERT on shutdown, reducing database writes per request.
* Added rate-limiting for audit log events: suppresses flood from the same event type and IP after 20 events in 5 minutes, logging a single summary instead.
* Daily cleanup now deletes old audit events in batches of 500 to prevent table locks on large datasets.
* Malware scan cache entries older than 14 days are now automatically purged during daily cleanup.
* Fixed double SHA-256 hash computation in file monitor snapshots, reducing hashing I/O by ~33%.
* File integrity scans triggered by overdue cron are now deferred to a background cron event instead of running synchronously on admin page loads.
* File baseline sync now uses batch UPDATE queries for unchanged files, reducing queries per scan from ~1,500 to ~3–5.
* Added 29 new built-in threat detection patterns (9 → 38 total): 16 file signatures covering variable function backdoors, create_function, preg_replace /e, XOR decryption loops (Sign1), plugin reinfectors, phar:// wrappers, credential harvesters, PHP object injection (CVE-2024-8353), direct wp_users writes, str_rot13 remote fetch, GIF/PHP polyglots, and more; 7 database patterns for credit card skimmers, Balada Injector hex arrays, TDS referrer redirects, and eval payloads in wp_options; 6 WAF rules for PHP object injection, LOAD_FILE/INTO OUTFILE SQLi, error-based SQLi, SSRF internal network blocking, CRLF injection, and XML-RPC pingback abuse.
* Removed duplicate login attempt limit fields from the Settings page; now configurable only in Login Access on the Security Dashboard.

= 1.3.5 =
* Added database cleanup tools for spam comments, unapproved comments, trashed comments, trashed posts, post revisions, auto-drafts, expired transients, orphaned post/comment metadata, and inactive subscriber accounts.
* Added batch-based AJAX cleanup that processes 500 records per request to avoid server timeouts on large datasets, with live progress and remaining count display.
* Added media optimization module with the option to disable WordPress intermediate image size generation and keep only original files.
* Added automatic WebP image conversion on upload with configurable quality (1-100), using GD or Imagick with automatic fallback.
* Added option to keep or delete the original JPEG/PNG/GIF file after WebP conversion.
* Added selectable image sizes to preserve when extra sizes are disabled (thumbnail, medium, medium_large, large, 1536x1536, 2048x2048).
* Added inner tab navigation within Advanced Hardening (Software locks / CAPTCHA / Staff PIN), Database (Table prefix / Cleanup), and Media (Image sizes / WebP) panels for cleaner organization.
* Redesigned Advanced Hardening panel from a single two-column form into three focused tabbed sections, each with its own save button.

= 1.3.4 =
* Added a basic Web Application Firewall (WAF) that inspects incoming HTTP requests for SQL injection, XSS, path traversal, command injection, remote file inclusion, null byte, and PHP wrapper attack patterns.
* Added an extensible Threat Patterns module with a custom rules engine for file malware signatures, WAF request rules, and database content scan patterns.
* Added an admin UI for adding, editing, deleting, enabling/disabling, importing, and exporting custom threat patterns in JSON format.
* Added built-in starter threat patterns for web shells, backdoors, crypto miners, malicious redirects, SEO spam, XXE, and PHP_SELF XSS attacks.
* Integrated custom threat patterns into the existing malware scanner for both file and database scans.
* Added honeypot fields to login, registration, and comment forms to block automated bots without affecting user experience.
* Added PHP execution blocking in the uploads directory via automatic .htaccess rules and dangerous file type upload prevention.
* Extended CAPTCHA provider support (reCAPTCHA v2/v3, Cloudflare Turnstile) to WordPress comment forms.
* Added comment rate limiting per IP with configurable maximum comments and time window.
* Added a basic math CAPTCHA challenge (e.g. "3 + 5 = ?") for comment forms, shown when rate limits are exceeded or always-on.
* Added registration rate limiting per IP with automatic math CAPTCHA challenge when limits are exceeded.
* Added WAF blocked request logging with the last 50 blocked requests visible in the Threat Patterns admin panel.
* Redesigned the admin dashboard layout from a single scrolling page to a sidebar navigation with content panels for better usability.
* Added feature descriptions to each sidebar navigation item to help users understand each security module.
* Removed decorative border accents from section panels for a cleaner visual appearance.

= 1.3.3 =
* Added a public scan summary API at `/wp-json/codewp-shield-monitor/v1/public/scan` for public score buttons and lightweight external checks.
* Limited the public scan response to high-level score and warning summaries; it does not start malware, file-integrity, or other server-intensive scans.
* Updated Staff PIN protection so WooCommerce/customer storefront logins are not prompted for a PIN by default.
* Clarified Staff PIN labels and helper text for non-customer/site staff login protection.
* Added configurable Staff PIN role selection and an emergency recovery constant for disabling login challenges after lockouts.
* Expanded CAPTCHA settings with Google reCAPTCHA v2/v3, Cloudflare Turnstile, per-form protection for login, registration, and WooCommerce checkout, plus a configurable v3 score threshold defaulting to 0.1.
* Stopped granting quick-login API scope to new paired clients by default and added per-client quick-login scope controls.
* Added audit events for quick-login failures and API token scope changes.
* Improved public summary API cache headers and added noindex headers to REST responses.
* Added Cloudflare-aware request IP handling with a developer filter for proxy environments.

= 1.3.2 =
* Addressed WordPress.org review feedback for login asset enqueueing, AJAX/admin endpoint path handling, external service disclosures, and plugin/root path resolution.
* Made WordPress.org checksum verification, App/Web quick-login, and form/order notification events opt-in by default.
* Clarified App/Web quick-login flow and exposed quick-login method metadata in the authenticated status API.
* Improved quick-login defaults for upgraded sites where the option existed before the quick-login setting was explicitly configured.

= 1.3.1 =
* Added advanced hardening controls for plugin/theme installation locks, login CAPTCHA, admin PIN, quick-login, and database table prefix review.
* Added token-protected quick-login API support for paired App/Web clients.
* Added administrator password-change and always-on failed-login audit events to the authenticated events API.
* Added hidden login path controls and failed-login IP block/unlock management.
* Added plugin and theme lifecycle audit events to the local log and authenticated API.
* Added malware scan caching so clean unchanged files are skipped on later scans.
* Added external JavaScript and external URL detection for source and database scans.
* Added fallback file-integrity scans when WP-Cron is overdue and administrators open wp-admin.
* Added App/Web notification events for Contact Form 7, WooCommerce, and selected post types.

= 1.3.0 =
* Added API capabilities, WordPress configuration, multisite scope, auto-update state, and heuristic 2FA/backup integration detection.
* Added WordPress.org core checksum verification and WP-Cron/file-baseline health metadata.
* Added cursor-based security event synchronization.
* Added API token scopes, expiry, legacy-token migration, and secure rotation.
* Added recent public content create/update, new administrator, software update, and expanded file-change audit groups for wp-admin and the authenticated API.
* Added lightweight suspicious-file and database scan reports with severity levels and administrator-only JSON export.
* Added manual malware scans, configurable daily/weekly/monthly scan schedules, scan time settings, and suspicious-finding email alerts.

= 1.2.2 =
* Added authenticated API metrics for CPU load, server/PHP memory, and disk usage in bytes and human-readable units.
* Added authenticated site and server IP metadata.

= 1.2.1 =
* Added authenticated API inventory counts for users, posts, pages, products, plugins, and themes.
* Added database, locale, timezone, multisite, active theme, and CodeWP Shield Monitor version metadata.

= 1.2.0 =
* Added important-file integrity monitoring and 30-day file-change history.
* Added administrator login, login lockout, and file-change email alerts.
* Added a redesigned security dashboard and hardening recommendations.
* Exposed integrity and 30-day login statistics through the authenticated API.

= 1.1.0 =
* Added a focused wp-admin security dashboard.
* Added secure App/Web pairing by QR code or manual code.
* Added authenticated security status and event REST APIs.

= 1.0.0 =
* Initial release.
