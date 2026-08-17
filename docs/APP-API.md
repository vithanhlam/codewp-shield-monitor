# CodeWP Shield Monitor App API v1

Base namespace: `/wp-json/codewp-shield-monitor/v1`

All responses use JSON over HTTPS. The App should reject pairing on plain HTTP in production.

## Pairing QR payload

The QR contains a JSON object:

```json
{
  "type": "codewp_shield_monitor_pairing",
  "version": 1,
  "site_name": "Example Site",
  "site_url": "https://example.com/",
  "pair_url": "https://example.com/wp-json/codewp-shield-monitor/v1/pair",
  "code": "ABCDE-23456",
  "expires_at": "2026-06-22T12:00:00Z"
}
```

The code expires after 10 minutes and can only be exchanged once.

## Exchange the pairing code

`POST /pair`

```json
{"code":"ABCDE-23456","client_type":"app","client_name":"Lam's iPhone"}
```

The response returns the API token once. Store it using the mobile operating system's secure credential storage.

```json
{
  "token": "secret-token",
  "token_type": "Bearer",
  "status_url": "https://example.com/wp-json/codewp-shield-monitor/v1/status",
  "events_url": "https://example.com/wp-json/codewp-shield-monitor/v1/events",
  "rotate_url": "https://example.com/wp-json/codewp-shield-monitor/v1/token/rotate",
  "quick_login_url": "https://example.com/wp-json/codewp-shield-monitor/v1/quick-login",
  "expires_at": "2027-06-22T12:00:00Z",
  "scopes": ["status:read", "events:read", "token:rotate"],
  "site_url": "https://example.com/"
}
```

Pairing is limited to 10 attempts per IP address per 15 minutes.

## Authentication

Send the token using one of these headers:

```http
Authorization: Bearer secret-token
```

If the web server strips `Authorization`, use:

```http
X-CodeWP-Shield-Monitor-Token: secret-token
```

Authenticated API access is limited to 120 requests per token per minute.
The legacy `X-Shield-Monitor-Token` header is still accepted for already-integrated clients, but new App/Web builds should send `X-CodeWP-Shield-Monitor-Token`.

New tokens expire after one year and carry explicit scopes. Quick-login scope is not granted to newly paired clients by default; an administrator can enable it per connected client in wp-admin. Existing legacy tokens receive the standard scopes and a 90-day expiry on their next use.

## REST hardening behavior

CodeWP Shield Monitor can restrict anonymous WordPress REST API traffic when the admin enables **Restrict public REST API** in wp-admin. That hardening blocks public access to other WordPress REST routes, but CodeWP Shield Monitor's own namespace remains reachable so the App/Web client can authenticate with its API token.

Allowed CodeWP Shield Monitor routes:

| Route | Public? | Purpose |
| --- | --- | --- |
| `POST /pair` | Yes | Exchanges a short-lived pairing code for a token. |
| `GET /public-score` | Yes | Returns only the public security score summary. |
| `GET /public/scan` | Yes | Returns the public score plus a small non-sensitive warning list. |
| `GET /status` | No | Requires `status:read` token scope. |
| `GET /events` | No | Requires `events:read` token scope. |
| `POST /token/rotate` | No | Requires `token:rotate` token scope. |
| `POST /quick-login` | No | Requires `quick-login:create` token scope. Creates a one-time admin login URL. |

If a client receives this response:

```json
{
  "code": "shield_monitor_rest_restricted",
  "message": "Public REST API access is restricted by CodeWP Shield Monitor."
}
```

then the request is being treated as an anonymous request to a blocked route. Check that the URL uses the CodeWP Shield Monitor namespace (`/wp-json/codewp-shield-monitor/v1/...`) and that authenticated routes include either `Authorization: Bearer ...` or `X-CodeWP-Shield-Monitor-Token: ...`.

## Rotate an API token

`POST /token/rotate`

Requires the `token:rotate` scope. The current token is invalidated immediately and the replacement token is returned once. The client must atomically replace its encrypted credential.

## Create a Quick-Login URL

`POST /quick-login`

Requires the `quick-login:create` scope and the wp-admin quick-login setting must be enabled. New paired clients do not receive this scope automatically; an administrator must allow quick login for that client from the connected clients panel. The response returns a one-use login URL for the configured administrator account. The URL expires after 60 seconds, bypasses the normal WordPress password form, CAPTCHA, and admin PIN, and is rate limited to 5 created links per token per minute.

Important: `quick_login_url` is the REST API endpoint, not the browser login URL. App/Web clients must call `POST quick_login_url` from their backend or trusted native client with `Authorization: Bearer ...` or `X-CodeWP-Shield-Monitor-Token: ...`, then open the returned `login_url` in the browser. Opening `quick_login_url` directly with `GET` will not log in.

```json
{
  "api_version": 1,
  "schema_version": "1.8",
  "login_url": "https://example.com/wp-admin/admin-post.php?action=shield_monitor_quick_login&shield_monitor_quick_login_token=...",
  "expires_at": "2026-06-26T12:01:00Z",
  "user_id": 1,
  "username": "admin"
}
```

The URL is consumed once. Clients should open it immediately in a trusted browser context and must never store or log it.

## Read status

`GET /status`

Returns the site URL, environment versions, inventory counts, update information, security settings, the latest recorded login user, 30-day login statistics, recent 30-day file changes, recent content/admin/software audit activity, file-integrity status, and security score. Responses use `Cache-Control: no-store, private`.

Additional production fields include `schema_version`, `capabilities`, `endpoints`, `event_catalog`, `event_sync.latest_id`, `issues`, multisite IDs, WordPress hardening and auto-update configuration, WP-Cron health, heuristic 2FA/backup detection, file baseline metadata, content/software audit capability flags, quick-login status, suspicious-code scan status, threat intelligence status, and WordPress core checksum status.

2FA and backup detection is heuristic: `detected` means a known provider appears active, while `verified_configuration` remains `false` until a provider-specific integration confirms its configuration.

Core checksum verification sends the installed WordPress version, locale, and CodeWP Shield Monitor version user-agent to the official WordPress.org checksum API at most once every 12 hours. No stored site data or credentials are included; WordPress.org can observe the network request source IP.

Important top-level fields:

```json
{
  "api_version": 1,
  "schema_version": "1.8",
  "endpoints": {
    "pair_url": "https://example.com/wp-json/codewp-shield-monitor/v1/pair",
    "status_url": "https://example.com/wp-json/codewp-shield-monitor/v1/status",
    "events_url": "https://example.com/wp-json/codewp-shield-monitor/v1/events",
    "public_score_url": "https://example.com/wp-json/codewp-shield-monitor/v1/public-score",
    "public_scan_url": "https://example.com/wp-json/codewp-shield-monitor/v1/public/scan",
    "rotate_url": "https://example.com/wp-json/codewp-shield-monitor/v1/token/rotate",
    "quick_login_url": "https://example.com/wp-json/codewp-shield-monitor/v1/quick-login",
    "quick_login_method": "POST",
    "quick_login_flow": "POST quick_login_url with a Bearer token, then open the returned login_url."
  },
  "quick_login": {
    "enabled": true,
    "user_id": 1,
    "url": "https://example.com/wp-json/codewp-shield-monitor/v1/quick-login",
    "method": "POST",
    "expires_in_seconds": 60,
    "flow": "POST url with a Bearer token, then open the returned login_url.",
    "bypasses": ["password", "captcha", "admin_pin"]
  },
  "event_catalog": {
    "login": ["admin_login", "login_success", "login_failed", "login_lockout", "quick_login_used"],
    "admin": ["admin_user_created", "admin_password_changed"],
    "files": ["file_added", "file_modified", "file_deleted"]
  },
  "issues": {
    "generated_at": "2026-06-26T12:00:00+00:00",
    "total": 3,
    "by_severity": {"critical": 0, "high": 1, "medium": 2, "low": 0},
    "highest_severity": "high",
    "items": [
      {
        "id": "file_changes_detected",
        "severity": "high",
        "category": "files",
        "title": "Important files changed recently.",
        "description": "Review changed files and trust the baseline only after confirming the changes are legitimate.",
        "evidence": {
          "count": "1",
          "items": [
            {
              "event_id": "124",
              "event_type": "file_modified",
              "path": "active-theme/functions.php",
              "change": "modified",
              "changed_at": "2026-06-22T12:35:00+00:00"
            }
          ]
        },
        "actions": [
          {
            "type": "wp_admin",
            "label": "Open in WordPress admin",
            "url": "https://example.com/wp-admin/admin.php?page=codewp-shield-monitor#integrity",
            "quick_login_supported": true
          }
        ]
      }
    ]
  },
  "site": {
    "name": "Example Site",
    "url": "https://example.com/",
    "wordpress_version": "7.0",
    "php_version": "8.3.0",
    "database_version": "8.0.36",
    "shield_monitor_version": "1.3.2",
    "locale": "vi",
    "timezone": "Asia/Bangkok",
    "is_multisite": false
  },
  "inventory": {
    "users": {"total": 12, "administrators": 2},
    "posts": {"total": 40, "published": 35, "draft": 5, "pending": 0, "private": 0, "future": 0},
    "pages": {"total": 8, "published": 8, "draft": 0, "pending": 0, "private": 0, "future": 0},
    "products": {"available": true, "total": 120, "published": 110, "draft": 10, "pending": 0, "private": 0, "future": 0},
    "plugins": {
      "total": 18,
      "active": 14,
      "inactive": 4,
      "items": [
        {
          "file": "example-plugin/example-plugin.php",
          "name": "Example Plugin",
          "active": true,
          "current_version": "1.0.0",
          "latest_version": "1.1.0",
          "update_available": true
        }
      ]
    },
    "themes": {
      "total": 4,
      "active": {
        "name": "Example Theme",
        "stylesheet": "example-theme",
        "template": "example-theme",
        "version": "2.0.0",
        "current_version": "2.0.0",
        "latest_version": "2.1.0",
        "update_available": true
      },
      "items": [
        {
          "slug": "example-theme",
          "name": "Example Theme",
          "stylesheet": "example-theme",
          "template": "example-theme",
          "active": true,
          "current_version": "2.0.0",
          "latest_version": "2.1.0",
          "update_available": true
        }
      ]
    }
  }
}
```

Inventory contains aggregate counts and public software metadata only. It does not expose usernames, emails, post content, product/customer details, or plugin configuration.

Use `quick_login.enabled` as the canonical App/Web switch for the quick-login feature. If it is `false`, hide or disable the quick-login action in the client. If it is `true`, create a one-time link by sending `POST` to `quick_login.url` or `endpoints.quick_login_url`, then open the returned `login_url`.

Security activity includes the most recent recorded login, 30-day counters, the latest file-change events from the last 30 days, and grouped audit lists for the 30 most recent post updates, post creations, administrator creations, and system/file changes:

```json
{
  "security": {
    "latest_login": {
      "event_id": 123,
      "event_type": "admin_login",
      "user_id": 1,
      "username": "admin",
      "display_name": "Site Admin",
      "roles": ["administrator"],
      "logged_in_at": "2026-06-22T12:30:00+00:00"
    },
    "admin_logins_30_days": 8,
    "new_admin_users_30_days": 1,
    "failed_logins_30_days": 3,
    "quick_login_enabled": true,
    "quick_login_user_id": 1,
    "post_updates_30_days": 4,
    "post_creations_30_days": 2,
    "recent_file_changes_30_days": [
      {
        "event_id": 124,
        "event_type": "file_modified",
        "path": "active-theme/functions.php",
        "change": "modified",
        "changed_at": "2026-06-22T12:35:00+00:00"
      }
    ],
    "recent_activity_30_days": {
      "recent_post_updates": [
        {
          "id": 130,
          "type": "post_updated",
          "user_id": 1,
          "username": "admin",
          "details": {
            "post_id": "42",
            "post_type": "post",
            "title": "Homepage",
            "status": "publish",
            "changed_fields": ["title", "content"],
            "old_status": "draft"
          },
          "created_at": "2026-06-22T12:45:00+00:00"
        }
      ],
      "recent_post_creations": [
        {
          "id": 129,
          "type": "post_created",
          "user_id": 1,
          "username": "admin",
          "details": {
            "post_id": "41",
            "post_type": "page",
            "title": "Landing Page",
            "status": "draft"
          },
          "created_at": "2026-06-22T12:40:00+00:00"
        }
      ],
      "recent_admin_creations": [
        {
          "id": 128,
          "type": "admin_user_created",
          "user_id": 7,
          "username": "new-admin",
          "details": {
            "username": "new-admin",
            "source": "user_registered"
          },
          "created_at": "2026-06-22T12:38:00+00:00"
        }
      ],
      "recent_system_db_changes": [
        {
          "id": 127,
          "type": "software_updated",
          "user_id": 1,
          "username": "admin",
          "details": {
            "type": "plugin",
            "action": "update",
            "items": ["example-plugin/example-plugin.php"]
          },
          "created_at": "2026-06-22T12:36:00+00:00"
        }
      ]
    },
    "malware_scan": {
      "enabled": true,
      "last_scan_at": "2026-06-22T13:00:00+00:00",
      "last_mode": "scheduled",
      "elapsed_ms": 820,
      "frequency": "daily",
      "scheduled_time": "03:15",
      "summary": {
        "total": 2,
        "critical": 0,
        "high": 1,
        "medium": 1,
        "low": 0,
        "risk_level": "high"
      },
      "findings": [
        {
          "source": "file",
          "severity": "high",
          "signature": "dangerous_execution_chain",
          "message": "Obfuscated execution chain detected.",
          "location": "plugins/example/file.php",
          "record": null,
          "title": "",
          "evidence": "eval(base64_decode(..."
        },
        {
          "source": "database",
          "severity": "medium",
          "signature": "javascript_uri",
          "message": "JavaScript URI found in stored content.",
          "location": "posts",
          "record": "ID:42 post",
          "title": "Homepage",
          "evidence": "javascript:"
        }
      ],
      "download_url": "https://example.com/wp-admin/admin-post.php?action=shield_monitor_download_malware_report&_wpnonce=...",
      "next_scan_at": "2026-06-23T20:15:00+00:00"
    }
  }
}
```

`latest_login` is `null` until a login is recorded. File paths are relative monitoring labels only; absolute server paths and file contents are never returned. Content audit details include metadata only: post ID, public post type, title, status, changed field names, the local WordPress user ID, and username when available. They do not include post body text.

`recent_system_db_changes` contains file-integrity events (`file_added`, `file_modified`, `file_deleted`, `core_checksum_mismatch`) and WordPress updater events (`software_updated`). File-integrity events can show that a JavaScript, CSS, PHP, or JSON file changed in an active theme or active plugin, but filesystem changes cannot always identify the human actor. `software_updated` includes the authenticated WordPress user when the update is performed through wp-admin.

`malware_scan` is a lightweight heuristic scanner for suspicious files and database records. It runs by WP-Cron on the configured cadence (`daily`, `weekly`, or `monthly`) at the configured local site time, and schedules a small background scan after administrator logins with a six-hour cooldown. Administrators can also run a manual scan from wp-admin. Findings are signals for review, not a definitive malware verdict. The scanner stores a local hash cache for clean files and skips those files on later scans while their SHA-256 hash is unchanged. It also flags external JavaScript and external URLs in files or database content when the destination host is outside the current site domain. The report includes severity, source, relative location, database table/record labels, signatures, and short sanitized evidence snippets. Full file contents, full database values, credentials, API tokens, and plain-text IP addresses are never stored in the report. `download_url` is only populated in wp-admin for logged-in administrators; token-only API clients receive the findings directly.

`threat_intelligence` adds local review signals for WordPress compromise patterns: administrator anomalies, plugin/theme risk, executable files in uploads, suspicious autoloaded options, suspicious cron hooks, MU-plugin patterns, fake CAPTCHA/ClickFix content, external script domains outside the default allowlist, SEO cloaking signals, file-integrity diff risk, and hardening gaps. It returns a summary, bounded findings, and an incident-response summary. These findings are available only through authenticated status responses and wp-admin; `/public-score` does not expose them or trigger these checks.

System and network metrics are returned in the authenticated `system` object:

```json
{
  "system": {
    "cpu": {
      "cores": 4,
      "load_1m": 0.42,
      "load_5m": 0.31,
      "load_15m": 0.28,
      "usage_percent_estimate": 10.5
    },
    "memory": {
      "total_bytes": 8589934592,
      "used_bytes": 4294967296,
      "free_bytes": 4294967296,
      "usage_percent": 50,
      "total_human": "8 GB",
      "used_human": "4 GB",
      "free_human": "4 GB"
    },
    "php_memory": {
      "used_bytes": 20971520,
      "peak_bytes": 25165824,
      "limit_bytes": 268435456,
      "used_human": "20 MB",
      "peak_human": "24 MB",
      "limit_human": "256 MB"
    },
    "disk": {
      "total_bytes": 107374182400,
      "used_bytes": 53687091200,
      "free_bytes": 53687091200,
      "usage_percent": 50,
      "total_human": "100 GB",
      "used_human": "50 GB",
      "free_human": "50 GB"
    },
    "network": {
      "site_host": "example.com",
      "site_ip": "203.0.113.10",
      "server_ip": "10.0.0.5"
    }
  }
}
```

`usage_percent_estimate` is derived from the one-minute load average divided by detected CPU cores; it is not an instantaneous hardware CPU reading. Server RAM values require Linux `/proc/meminfo`; unsupported hosts return `null` while PHP process memory remains available. `site_ip` is resolved from the website hostname, while `server_ip` may be private or represent a reverse proxy/container.

## Read Public Score

`GET /public-score`

This endpoint does not require an API token. It is intended for public website/app badges or a public "scan score" button that only displays the site's current high-level security score. It does not start a malware scan, file-integrity scan, or any other server-intensive job.

The response is intentionally limited to non-sensitive public summary fields. It does not return incomplete tasks, hardening recommendations, inventory, update counts, system metrics, event history, file paths, database records, usernames, scan findings, token metadata, or plugin/theme configuration.

```json
{
  "api_version": 1,
  "schema_version": "1.0",
  "generated_at": "2026-06-22T13:00:00+00:00",
  "score": 72,
  "status": "needs_review"
}
```

`status` is one of `protected`, `needs_review`, or `at_risk`. Detailed diagnostics are available only through authenticated token endpoints or wp-admin.

If `/public-score` returns `shield_monitor_rest_restricted`, the client is likely calling the wrong route or an old plugin build is active. The expected URL is `/wp-json/codewp-shield-monitor/v1/public-score`; do not call `/wp-json/wp/v2/...` or another plugin namespace for the public score button.

## Read Public Scan Summary

`GET /public/scan`

This endpoint does not require an API token. It returns the same high-level public score plus a small, non-sensitive warning list for public scan buttons. It does not start a malware scan, file-integrity scan, or any other server-intensive job.

The response is limited to summary warning IDs, severity, category, title, and description. It does not return file paths, database records, usernames, event history, inventory, token metadata, or detailed scan findings.

```json
{
  "api_version": 1,
  "schema_version": "1.0",
  "generated_at": "2026-06-22T13:00:00+00:00",
  "score": 72,
  "status": "needs_review",
  "scan_type": "public_summary",
  "scan_started": false,
  "warnings": [
    {
      "id": "login_protection_disabled",
      "severity": "high",
      "category": "login",
      "title": "Login protection is disabled.",
      "description": "Enable failed-login lockouts to slow brute-force attempts."
    }
  ]
}
```

## Read security events

`GET /events?limit=20&after_id=1234`

`limit` accepts 1–100. With `after_id`, events are returned in ascending ID order. The response includes `next_cursor`, `latest_id`, and `has_more`; continue requesting with `after_id=next_cursor` while `has_more` is true. Without `after_id`, the endpoint preserves the legacy newest-first behavior.

Events contain the local event ID, type, WordPress user ID when applicable, safe event details such as a relative changed-file path, new administrator source, and UTC timestamp. `admin_user_created` is recorded when a new user receives administrator access. Events do not include IP addresses, browser data, API tokens, file contents, or absolute server paths.

Each event also includes `title`, `category`, and `severity` so App/Web clients can render timelines, filters, colors, and notifications without duplicating the plugin's mapping logic.

The response also includes an `activity` object with the same grouped 30-item audit lists exposed at `security.recent_activity_30_days` in `/status`. This lets clients render the security dashboard without re-filtering the raw event stream:

```json
{
  "api_version": 1,
  "schema_version": "1.8",
  "generated_at": "2026-06-22T13:00:00+00:00",
  "events": [
    {
      "id": 130,
      "type": "post_updated",
      "title": "Post updated",
      "category": "content",
      "severity": "low",
      "user_id": 1,
      "username": "admin",
      "details": {
        "post_id": "42",
        "post_type": "post",
        "title": "Homepage",
        "status": "publish",
        "changed_fields": ["title", "content"],
        "old_status": "draft"
      },
      "created_at": "2026-06-22T12:45:00+00:00"
    }
  ],
  "activity": {
    "recent_post_updates": [],
    "recent_post_creations": [],
    "recent_notifications": [],
    "recent_admin_creations": [],
    "recent_system_db_changes": []
  },
  "next_cursor": 130,
  "latest_id": 130,
  "has_more": false
}
```

Known event types include `admin_login`, `login_success`, `login_failed`, `login_lockout`, `login_ip_blocked`, `login_ip_unlocked`, `quick_login_link_created`, `quick_login_used`, `quick_login_failed`, `user_registered`, `role_changed`, `admin_user_created`, `admin_password_changed`, `post_created`, `post_updated`, `contact_form_7_submission`, `woocommerce_order_created`, `custom_post_type_created`, `file_added`, `file_modified`, `file_deleted`, `file_baseline_trusted`, `core_checksum_mismatch`, `table_prefix_changed`, `software_updated`, `plugin_installed`, `plugin_updated`, `plugin_activated`, `plugin_deactivated`, `theme_installed`, `theme_updated`, `theme_activated`, `malware_scan_completed`, `malware_scan_suspicious`, `malware_report_cleared`, `app_paired`, `app_revoked`, `token_rotated`, and `token_scope_changed`.

## Notification events

The App/Web client should treat `/events` as the notification feed. Store `latest_id` locally and poll with `GET /events?after_id={latest_id}`. New notification events are returned in ascending ID order, so the client can display or push them once and then save the returned `next_cursor`.

Notification settings are managed in wp-admin. Contact Form 7 and WooCommerce notification events are opt-in in the current plugin code. Custom post type notifications are opt-in per post type.

Contact Form 7 submissions use `contact_form_7_submission`:

```json
{
  "id": 210,
  "type": "contact_form_7_submission",
  "user_id": 0,
  "username": null,
  "details": {
    "notification": true,
    "source": "contact_form_7",
    "form_id": 12,
    "form_title": "Contact form",
    "submitted_at": "2026-06-25 08:30:00",
    "posted_fields": {
      "your-name": "Lam",
      "your-email": "lam@example.com",
      "your-subject": "Need support"
    }
  },
  "created_at": "2026-06-25T08:30:00+00:00"
}
```

WooCommerce orders use `woocommerce_order_created`:

```json
{
  "id": 211,
  "type": "woocommerce_order_created",
  "user_id": 45,
  "details": {
    "notification": true,
    "source": "woocommerce",
    "order_id": 1005,
    "order_number": "1005",
    "status": "pending",
    "total": "250000",
    "currency": "VND",
    "item_count": 2,
    "payment_method": "Bank transfer",
    "customer_id": 45,
    "billing": {
      "name": "Lam Nguyen",
      "email": "lam@example.com",
      "phone": "0900000000",
      "city": "Ho Chi Minh City",
      "state": "",
      "country": "VN",
      "postcode": "700000"
    },
    "created_at": "2026-06-25T08:31:00+00:00"
  },
  "created_at": "2026-06-25T08:31:00+00:00"
}
```

Selected post types use `custom_post_type_created`:

```json
{
  "id": 212,
  "type": "custom_post_type_created",
  "user_id": 1,
  "details": {
    "notification": true,
    "source": "post_type",
    "post_id": 88,
    "post_type": "lien-he",
    "title": "New contact request",
    "status": "publish",
    "permalink": "https://example.com/lien-he/new-contact-request/",
    "created_at": "2026-06-25T08:32:00+00:00"
  },
  "created_at": "2026-06-25T08:32:00+00:00"
}
```

Notification payloads are sanitized and bounded. File uploads, CAPTCHA tokens, nonces, full file contents, API tokens, and raw IP addresses are not included.

## File integrity scope

When file monitoring is enabled, CodeWP Shield Monitor scans cryptographic hashes every five minutes. The monitored scope includes WordPress bootstrap/login files, `.htaccess`, `wp-config.php`, the active theme, and active plugin code/assets with these extensions: `.php`, `.js`, `.css`, and `.json`.

To limit server load, CodeWP Shield Monitor skips common heavy directories (`node_modules`, `vendor`, `.git`, `cache`, `uploads`, `dist`, `build`), skips files larger than 1 MB, and caps discovery at 300 active-theme files plus 250 files per active plugin directory with a 1,200-file total budget across active plugins. Only relative labels and hashes are stored. File contents are never stored or returned.

The first scan creates a database baseline in `wp_shield_monitor_file_baseline`. Each baseline row stores the relative path, file group, size, MD5, SHA-256, readability/writability flags, first seen time, last seen time, last changed time, and status (`normal`, `added`, `modified`, or `deleted`). Later scans compare against this baseline and emit `file_added`, `file_modified`, or `file_deleted` events when important files change. `/status` exposes `security.file_integrity.baseline_storage`, `hash_algorithms`, and `baseline_summary`; it does not expose file contents.

## Revocation

An administrator can revoke any App token from **wp-admin → CodeWP Shield Monitor → Connect App**. Revocation takes effect immediately.
