# Additional Notes

Threat intelligence checks are summarized in [SECURITY-ROADMAP.md](SECURITY-ROADMAP.md).

## Primary Language

CodeWP Shield Monitor will be developed with English as the primary language.

### Supported Languages

* English (Default)
* Vietnamese

### Future Languages

* Spanish
* Portuguese
* German

All components support multilingual:

* Website
* Dashboard
* Mobile App
* WordPress Plugin

Language detection:

```text
English (default)
Vietnamese
```

User can switch language anytime.

---

# Suggested Domain

Preferred:

```text
shieldpress.net
```

Alternatives:

```text
getshield_monitor.com
shield_monitorhq.com
shieldpress.net
```

---

# Business Model

CodeWP Shield Monitor is a SaaS product.

Revenue source:

* Subscription
* Agency Plan
* White Label Plan

Plugin remains FREE.

The Mobile App remains FREE.

Users pay for monitoring capacity and advanced security features.

---

# Pricing Strategy

## Free

Perfect for personal websites.

Price:

```text
$0/month
```

Features:

* Up to 5 Websites
* Uptime Monitoring
* SSL Monitoring
* Domain Monitoring
* Plugin Updates
* Theme Updates
* CPU / RAM / Disk
* WooCommerce Notifications

---

## Pro

Perfect for freelancers.

Price:

```text
$4.99/month
or
$49/year
```

Features:

* Up to 50 Websites
* Security Monitoring
* Health Score
* Advanced Notifications
* Login Monitoring
* File Monitoring
* Team Updates

---

## Agency

Perfect for agencies.

Price:

```text
$12.99/month
or
$129/year
```

Features:

* Up to 250 Websites
* Malware Detection
* Vulnerability Scanner
* Client Management
* Team Members
* Advanced Reports

---

## Unlimited

Perfect for hosting companies and large agencies.

Price:

```text
$24.99/month
or
$249/year
```

Features:

* Unlimited Websites
* White Label
* Priority Support
* Advanced API Access
* Unlimited Team Members

---

# Development Roadmap

## Phase 1 (MVP)

Estimated 2-3 months.

Features:

* Website Online / Offline
* Uptime Monitoring
* SSL Expiry
* Domain Expiry
* WordPress Version
* PHP Version
* Plugin Updates
* Theme Updates
* CPU
* RAM
* Disk
* WooCommerce Order Notifications
* Push Notifications

Goal:

Launch Beta.

---

## Phase 2

Estimated 1-2 months.

Features:

* Health Score
* Security Monitoring
* Login Monitoring
* New Admin Detection
* File Change Detection

Goal:

First paying customers.

---

## Phase 3

Estimated 2-3 months.

Features:

* Malware Detection
* Vulnerability Scanner
* Agency Dashboard
* Team Management
* White Label

Goal:

Scale internationally.

---

# Long-Term Vision

CodeWP Shield Monitor aims to become:

"WordPress UptimeRobot + Security Monitor + Malware Protection"

inside a single platform.

Monitor.
Protect.
Alert.

CodeWP Shield Monitor.

---

# Web Management Dashboard Integration

This section is the implementation note for the CodeWP Shield Monitor website used to manage WordPress sites that have the CodeWP Shield Monitor plugin installed.

## Responsibility Boundary

The WordPress plugin is a secure connector and local security agent. It provides:

* WordPress, PHP, database, and CodeWP Shield Monitor versions
* User, administrator, post, page, product, plugin, and theme counts
* Active theme/template metadata
* WordPress core, plugin, and theme update status
* CPU load, server RAM, PHP memory, disk usage, site IP, and server IP
* Security score and hardening status
* Administrator login and failed-login statistics
* Important-file integrity status
* Login, lockout, pairing, role-change, and file-change events

The CodeWP Shield Monitor Web service must monitor these items externally:

* Website online/offline state and uptime percentage
* Response time and HTTP status
* SSL certificate expiry
* Domain expiry
* Push, email, and advanced notification delivery
* Historical charts, reports, teams, agencies, and white-label features

Important: a website cannot reliably detect its own downtime. Uptime must be checked by CodeWP Shield Monitor infrastructure from outside the WordPress server.

## API Base and Endpoints

API namespace:

```text
https://example.com/wp-json/codewp-shield-monitor/v1
```

Endpoints:

| Method | Endpoint | Authentication | Purpose |
| --- | --- | --- | --- |
| `POST` | `/pair` | One-time pairing code | Exchange a code for an API token |
| `GET` | `/status` | Bearer token | Read site, inventory, system, update, and security status |
| `GET` | `/events?limit=20` | Bearer token | Read the latest security events |

Authenticated calls may use:

```http
Authorization: Bearer secret-token
```

Fallback for servers that strip the Authorization header:

```http
X-CodeWP Shield Monitor-Token: secret-token
```

Limits:

* Pairing code lifetime: 10 minutes
* Pairing code usage: once only
* Pairing attempts: 10 per IP per 15 minutes
* Authenticated API requests: 120 per token per minute

## Website Pairing Flow

1. The user installs and activates CodeWP Shield Monitor on WordPress.
2. The user opens **wp-admin → CodeWP Shield Monitor → App / Web**.
3. The plugin displays a QR code, website URL, and manual code such as `ABCDE-23456`.
4. On the CodeWP Shield Monitor website, the user chooses **Add Website** and either scans the QR code or enters the URL and code.
5. The CodeWP Shield Monitor backend sends the following request directly to the WordPress site:

```http
POST https://example.com/wp-json/codewp-shield-monitor/v1/pair
Content-Type: application/json

{
  "code": "ABCDE-23456",
  "client_type": "web",
  "client_name": "CodeWP Shield Monitor Web"
}
```

6. WordPress returns the API token once, together with `status_url` and `events_url`.
7. The backend encrypts the token at rest and immediately requests `/status` to verify ownership and connectivity.
8. The website stores the first snapshot and starts external uptime monitoring.

The API token must never be returned to browser JavaScript, stored in local storage, included in analytics, or written to application logs.

## Status Payload for the Web Dashboard

`GET /status` returns these top-level objects:

```text
api_version
generated_at
site
inventory
system
updates
security
health_score
```

### Site

```text
site.name
site.url
site.wordpress_version
site.php_version
site.database_version
site.shield_monitor_version
site.locale
site.timezone
site.is_multisite
site.blog_id
site.network_id
```

### Inventory

```text
inventory.users.total
inventory.users.administrators
inventory.posts.total / published / draft / pending / private / future
inventory.pages.total / published / draft / pending / private / future
inventory.products.available
inventory.products.total / published / draft / pending / private / future
inventory.plugins.total / active / inactive
inventory.plugins.items[].file / name / active / current_version / latest_version / update_available
inventory.themes.total
inventory.themes.active.name / stylesheet / template / version
inventory.themes.active.current_version / latest_version / update_available
inventory.themes.items[].slug / name / stylesheet / template / active / current_version / latest_version / update_available
```

Inventory returns aggregate counts and public software metadata only. It does not expose usernames, emails, article content, customer information, order details, or plugin settings.

### System Resources and IP

```text
system.cpu.cores
system.cpu.load_1m / load_5m / load_15m
system.cpu.usage_percent_estimate
system.memory.total_bytes / used_bytes / free_bytes / usage_percent
system.memory.total_human / used_human / free_human
system.php_memory.used_bytes / peak_bytes / limit_bytes
system.php_memory.used_human / peak_human / limit_human
system.disk.total_bytes / used_bytes / free_bytes / usage_percent
system.disk.total_human / used_human / free_human
system.network.site_host / site_ip / server_ip
```

Notes:

* Use byte fields for calculations and charts. Human-readable fields are for display only.
* CPU percentage is an estimate based on one-minute load divided by detected CPU cores.
* Full server RAM metrics require Linux `/proc/meminfo`. Unsupported hosts may return `null`.
* `site_ip` is resolved from the domain. `server_ip` may be private, proxied, or container-local.

### Updates

```text
updates.core.update_available / new_version
updates.plugins.update_count / items[]
updates.themes.update_count / items[]
```

Update items include the installed version and available version so the dashboard can show exactly what needs attention.

### Security

```text
security.https
security.login_protection
security.block_enumeration
security.security_headers
security.file_editor_disabled
security.xmlrpc_disabled
security.events_24h
security.latest_login
security.admin_logins_30_days
security.new_admin_users_30_days
security.failed_logins_30_days
security.recent_file_changes_30_days
security.file_integrity.enabled
security.file_integrity.monitored_files
security.file_integrity.writable_files
security.file_integrity.changes_30_days
security.file_integrity.last_scan
security.file_integrity.last_result
security.file_integrity.baseline_created_at
security.file_integrity.next_scan_at
security.file_integrity.cron_overdue
security.file_integrity.coverage
security.file_integrity.core_checksum
health_score
```

## Security Events

`GET /events?limit=20&after_id=1234` accepts a limit from 1 to 100 and returns:

```text
events[].id
events[].type
events[].user_id
events[].details
events[].created_at
next_cursor
latest_id
has_more
```

Expected event types include:

```text
admin_login
login_success
login_failed
login_lockout
admin_user_created
file_added
file_modified
file_deleted
user_registered
role_changed
app_paired
app_revoked
enumeration_blocked
```

Events may include a relative file path or attempt count. They never include IP addresses, browser data, API tokens, file contents, or absolute server paths.

For reliable synchronization, persist `next_cursor` per site and continue requesting while `has_more` is true. Omitting `after_id` preserves legacy newest-first behavior and should not be used for background ingestion.

## Capabilities, Configuration, and Integrations

Feature-detect using `schema_version` and `capabilities`. The API reports support for event cursor synchronization, scoped/expiring/rotatable tokens, file integrity, core checksums, system metrics, webhooks, and remote actions.

`event_sync.latest_id` gives the newest local event ID and `event_sync.retention_days` reports the 30-day retention window. After pairing, the backend may set its initial cursor to `latest_id` for new events only, or start from `after_id=0` to backfill retained history.

WordPress hardening, auto-update, and scheduler state is returned in `configuration`. The `integrations` object heuristically detects known 2FA and backup plugins; `detected` does not prove configuration, so check `verified_configuration` before showing a protected state.

## Token Lifecycle

New tokens expire after one year and include `status:read`, `events:read`, and `token:rotate`. Rotate with `POST /wp-json/codewp-shield-monitor/v1/token/rotate`; the old token becomes invalid immediately and the replacement is returned once. Legacy tokens receive standard scopes and a 90-day expiry on next use.

Core checksum verification contacts the official WordPress.org checksum API at most once every 12 hours when enabled. The request includes WordPress version, locale, and CodeWP Shield Monitor version user-agent; WordPress.org can observe the source IP like any network service. No site content or credentials are included, and the setting can be disabled in wp-admin.

## Recommended Web Dashboard Pages

### Website List

Show:

* Online/offline state
* Security score
* Response time
* WordPress/PHP versions
* Pending updates
* CPU, RAM, and disk percentages
* Login or file-change alert badge
* Last successful synchronization

### Website Detail

Recommended tabs:

* Overview
* Uptime
* Security
* Updates
* System Resources
* Content Inventory
* Security Events
* Notifications
* Connection Settings

### Add Website

Support:

* QR scanning
* Manual website URL and pairing code
* HTTPS validation
* Immediate connection test
* Clear errors for expired code, invalid token, blocked REST API, timeout, and SSL failure

## Suggested Backend Data Model

Minimum entities:

```text
users
teams
team_members
sites
site_credentials
site_status_snapshots
site_inventory_snapshots
site_resource_metrics
site_security_events
uptime_checks
alerts
notification_channels
```

Important fields for `sites`:

```text
id
team_id
name
url
status_url
events_url
plan
connection_status
last_seen_at
last_sync_at
created_at
updated_at
```

Store the API token separately in `site_credentials`, encrypted using an application encryption key or managed KMS. Never store it as plain text in the `sites` table.

## Polling Recommendations

* External uptime check: every 1–5 minutes, depending on plan
* `/status`: every 5 minutes for active sites
* `/events`: every 1–5 minutes or after an alert signal
* Update inventory/history tables only when values change
* Mark a site disconnected after repeated authenticated API failures
* Treat one timeout as unknown, not immediately offline
* Apply exponential backoff after `429`, `5xx`, DNS, or network failures

The website should use `generated_at` and its own `received_at` timestamp to detect stale data and clock differences.

## CodeWP Shield Monitor Web Security Requirements

* All WordPress API requests must originate from the CodeWP Shield Monitor backend, never directly from browser JavaScript.
* Require HTTPS for production pairing and monitoring.
* Encrypt API tokens at rest with authenticated encryption such as AES-256-GCM or a managed KMS.
* Redact tokens and pairing codes from logs, error trackers, analytics, traces, and support exports.
* Enforce tenant/team authorization on every site query and action.
* Validate site URLs and protect backend fetchers against SSRF and DNS rebinding.
* For public SaaS, reject loopback, link-local, private, reserved, and metadata-service IP ranges unless private-site monitoring is an intentional isolated feature.
* Re-resolve and validate DNS before connections and after redirects.
* Set strict timeouts, response-size limits, and redirect limits.
* Provide a disconnect action that removes the encrypted credential and asks the user to revoke the client in WordPress.
* Record security-sensitive dashboard actions in an immutable audit log.
* Never interpret `site_ip` or `server_ip` as proof of ownership; successful one-time pairing is the ownership proof.

## Current Plugin Contract

Current connector version documented here:

```text
CodeWP Shield Monitor Plugin 1.3.0
API v1
```

API changes within v1 should remain additive. The Web dashboard must ignore unknown fields so newer plugin versions remain backward compatible.
