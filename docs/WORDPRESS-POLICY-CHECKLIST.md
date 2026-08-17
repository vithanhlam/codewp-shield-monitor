# WordPress Plugin Review Checklist

Reviewed for CodeWP Shield Monitor 1.3.2 on 2026-06-28 against WordPress.org review feedback `R codewp-shield-monitor/vithanhlam/24Jun26/T2 28Jun26/4.0.1`.

## Directory guidelines

- GPL-2.0-or-later license is declared in the plugin header and `readme.txt`.
- The plugin is complete and functional; no trialware or locked local features.
- No tracking, telemetry, affiliate links, or unsolicited dashboard notices. Optional App access requires an explicit administrator pairing action and is revocable.
- Optional core checksum verification contacts only the official WordPress.org checksum API, sends only WordPress version and locale, is rate-limited to once per 12 hours, is disclosed, and can be disabled.
- Optional login CAPTCHA loads Cloudflare Turnstile or Google reCAPTCHA only after administrator opt-in, and the service is disclosed in `readme.txt`.
- No executable code is downloaded and no third-party CDN is required for core plugin operation.
- External calls and personally sensitive notification-event sources are opt-in by default. New installs do not enable WordPress.org checksum calls, CAPTCHA, App/Web quick-login, Contact Form 7 event capture, or WooCommerce order event capture until an administrator enables them.
- Human-readable source files are included.
- WordPress default libraries and APIs are used.
- The plugin does not write to WordPress core files, `.htaccess`, or `wp-config.php`.
- Data is removed during uninstall. Deactivation preserves settings and data, as WordPress users expect.
- Author is `vithanhlam`. No unverified Author URI is declared; any Plugin URI must remain owned/controlled and relevant to the plugin.
- The plugin public slug has been updated to `codewp-shield-monitor` after the previous slug was not approved. Final slug approval remains WordPress.org's decision.

## 2026-06-28 review fixes and lessons

- Do not hardcode `wp-admin/admin-ajax.php` or `wp-admin/admin-post.php` in generated URLs or path checks. Use `admin_url( 'admin-ajax.php' )` / `admin_url( 'admin-post.php' )`, then pass the value to JavaScript with `wp_localize_script()` or derive paths from those URLs when checking requests.
- Do not print raw `<style>` or `<script>` blocks from plugin PHP. Register/enqueue CSS and JavaScript with `wp_enqueue_style()`, `wp_enqueue_script()`, `wp_add_inline_style()`, or `wp_add_inline_script()` on the correct hook.
- Remote JavaScript is only acceptable for a documented service-style feature. For optional CAPTCHA, enqueue Cloudflare Turnstile or Google reCAPTCHA only when the administrator enables the provider and supplies keys, and document exactly what is loaded and posted.
- Every external service must be disclosed in `readme.txt` under `External services`: service purpose, data sent, when it is sent, and working Terms/Privacy links.
- Avoid plugin-directory assumptions such as `WP_PLUGIN_DIR` when a WordPress path helper or a plugin-file-derived path is available. Prefer constants defined from the main plugin file such as `SHIELD_MONITOR_FILE`, `SHIELD_MONITOR_PATH`, and `SHIELD_MONITOR_URL`.
- Avoid raw WordPress root path concatenation in feature code where possible. Use WordPress APIs such as `get_home_path()` for the install root and normalize paths before comparisons.
- Literal third-party domain lists in detection-only code can look like remote calls in review tooling. Keep those lists clearly scoped, documented, and avoid them unless the feature truly needs known-host classification.
- Do not add features that rewrite `wp-config.php`, `.htaccess`, WordPress core files, or server configuration files. For dangerous hardening tasks such as database prefix changes, show status/advice instead of performing the operation.

## Security

- Every PHP entry point blocks direct access.
- Settings require `manage_options` and use the Settings API nonce.
- Inputs are sanitized and bounded; admin output is escaped.
- SQL writes use `$wpdb` format arguments; variable values in queries use prepared statements.
- IP addresses are never stored in plain text. HMAC-SHA-256 uses the site's WordPress auth salt.
- Login errors do not reveal whether a username exists.
- No use of `eval`, encoded/obfuscated code, unsafe deserialization, or remote code execution.
- XML-RPC blocking is disabled by default to avoid breaking Jetpack and remote publishing.
- Content Security Policy is not injected automatically because a generic policy can break themes and plugins.

## Privacy

- All data remains on the WordPress site except documented opt-in external service requests.
- A privacy-policy suggestion is registered with WordPress.
- Personal-data exporter and eraser callbacks are registered.
- Login and file-change event retention is fixed at 30 days.
- File monitoring stores SHA-256 hashes and relative paths, never file contents.
- Login IP and browser data is used only in administrator-requested email alerts and is not persisted in event logs.
- `readme.txt` includes an `External services` section for WordPress.org checksums, Cloudflare Turnstile, and Google reCAPTCHA. Paired App/Web clients are documented in FAQ because they connect to token-protected endpoints hosted on the WordPress site; the plugin does not make outbound service calls to the App/Web host.

## Internationalization

- English is the source/default language.
- All user-facing PHP strings use the `codewp-shield-monitor` text domain.
- Vietnamese is shipped as `languages/codewp-shield-monitor-vi_VN.l10n.php`.
- A POT template is included for future translations.

## Official references

- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/apis/security/
- https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
- https://developer.wordpress.org/plugins/privacy/suggesting-text-for-the-site-privacy-policy/
