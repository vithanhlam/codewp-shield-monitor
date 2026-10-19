# WordPress.org preflight — 1.5.0

Date: 2026-10-19. Tested the exact GitHub release ZIP, SHA-256 `8782b6efc9b6fe4585359b355d03a07e1d8bc591eb7f5b8410d23dbc8d84609a`, in a fresh disposable WordPress 7.1.3 / SQLite installation under the actual `codewp-shield-monitor` plugin directory.

## Functional results

- Upgraded a real SVN 1.4.4 installation through WordPress's plugin installer using the ZIP: 10 checks passed, including preservation of custom settings and paired API tokens, retirement of license state/schedules, physical removal of paid-module files, and the new UDRPC switch defaulting off.
- Re-ran 15 HTTP scenarios against the installed ZIP: all matched expectations. Valid signed RPC can install/activate the harmless marker without containment; the installation lock refuses it; the UDRPC switch returns 403 independently of WAF and administrator identity.
- Admin dashboard: 7 checks passed, including rendering of the new switch/compatibility explanation, absence of paid menus, and anonymous denial of detailed vulnerability results.
- Release/migration/API regression: 31 checks passed. Real multisite capability regression: 18 checks passed. Isolated uninstall: 3 checks passed. Offline advisory version boundaries: 44 checks passed. PHP syntax checked in both SVN trunk and tag.
- Both prepared SVN trees contain exactly the same 37 file contents as the tested ZIP; no lab drivers, keys, temporary files or development dependency directories are included. JavaScript source and its third-party notice are included alongside the compiled bundle.

## Automated standards checks — outstanding findings disclosed

WordPress.org's official Readme Validator reported no errors and only two optional notes: no Upgrade Notice section and no donation link.

Plugin Check was run in update mode and compared with the previously released 1.4.4 source. The current package still has **50 existing ERROR findings**; this is **not a clean Plugin Check pass**. No new ERROR signatures were introduced by 1.5.0. Warning differences were reviewed as existing internal table identifiers with changed line numbers and the already-approved plugin slug.

The existing findings include missing translator comments/ordered placeholders, numeric output escaping style, native filesystem calls in monitoring/media code, a generated prepared-SQL placeholder list, and remote-content checks. The flagged remote items include opt-in CAPTCHA services documented in readme and scanner allowlist strings rather than new external script loading. The generated SQL placeholder list contains fixed placeholder tokens, with row values passed separately to `prepare`; it is not request-supplied SQL.

These standards findings remain maintenance work; they have not been silently marked as fixed. Passing the functional regression tests is not a claim of exhaustive security or compatibility. Only PHP 8.5 was available for this run, so the declared PHP 7.4 minimum was not runtime-tested here. The reported Premium no-key UDRPC bypass remains unreproduced because no Premium package or original request was provided.

The lab server was bound to localhost, external WordPress HTTP requests and WP-Cron were disabled, and only generated lab keys and a marker plugin that records its activation were used.

## Publication

Published trunk and tag `1.5.0` atomically to WordPress.org SVN revision **3754879**. Both local SVN targets are clean after commit.

The official WordPress.org API now reports **1.5.0**. Downloaded the generated WordPress.org ZIP and verified that all **37 file contents** match the tested GitHub ZIP exactly (ZIP container metadata may differ).
