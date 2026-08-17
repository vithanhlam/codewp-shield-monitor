# CodeWP Shield Monitor Threat Intelligence

CodeWP Shield Monitor includes local threat intelligence checks to reduce common WordPress malware, backdoor, redirect, and account-takeover risks. Findings are review signals, not automatic proof of malware, and no destructive action is taken automatically.

## Implemented Checks

- [x] Admin Anomaly Monitor
  - Detect new administrator accounts, role changes to administrator, administrator email changes, dormant administrator logins, and suspicious administrator usernames.
  - Add severity labels, admin alerts, and a dedicated wp-admin review panel.

- [x] Plugin and Theme Risk Monitor
  - Flag abandoned plugins/themes, inactive plugins with executable files, non-WordPress.org sources, unknown update metadata, and removed or suspicious packages where metadata is available.
  - Show risk context without auto-deleting anything.

- [x] Uploads PHP Guard
  - Detect executable files in uploads, including `.php`, `.phtml`, `.phar`, double extensions such as `.jpg.php`, and suspicious disguised files.
  - Add optional quarantine workflow only after explicit administrator confirmation.

- [x] Option, Cron, and MU-Plugin Scanner
  - Scan autoloaded options, cron events, `mu-plugins`, `.user.ini`, `.htaccess`, and `wp-config.php` metadata for suspicious redirects, encoded payloads, unknown callbacks, and unexpected executable code.
  - Store only sanitized evidence snippets and labels.

- [x] Fake CAPTCHA and ClickFix Detector
  - Detect fake CAPTCHA, fake Cloudflare checks, clipboard abuse, PowerShell instructions, `Win + R` instructions, and ClickFix-style social engineering copy or scripts.
  - Treat these findings as high risk when found in public content, options, themes, or plugin files.

- [x] External Script Allowlist
  - Let administrators approve known external script domains such as analytics, payment, CDN, CAPTCHA, and marketing services.
  - Alert when new external JavaScript domains appear outside the allowlist.

- [x] SEO Cloaking Check
  - Compare the homepage response for a normal browser user agent and a search-engine user agent.
  - Flag unexpected redirects, injected scripts, changed titles, or outbound links that appear only for crawlers.

- [x] File Integrity Diff
  - Expand file integrity reporting with clearer categories for added, modified, deleted, executable, public-upload, inactive-plugin, and active-theme files.
  - Prioritize suspicious PHP files in uploads, inactive plugins, and recently modified entry files.

- [x] Recovery and Incident Report Toolkit
  - Generate an incident report with suspicious files, suspicious options, cron entries, administrator changes, recent logins, and recommended manual review steps.
  - Add safe actions such as export report, mark reviewed, and later optional quarantine.

- [x] Security Baseline Hardening
  - Add checks for application passwords, debug display, directory listing, backup/config leaks, exposed `.env`, exposed archives, file permissions, XML-RPC, REST user enumeration, and dashboard file editing.
  - Keep hardening actions explicit and reversible.

## Implementation Notes

- Public endpoints must never trigger scans or expose detailed diagnostics.
- Expensive scans should run through wp-admin, cron, or authenticated token endpoints only.
- Potentially destructive actions must require explicit administrator confirmation and a nonce.
- Findings should be treated as review signals, not automatic proof of malware.
- Store relative paths, hashes, labels, and short sanitized evidence snippets; never store full file contents or full database values.
