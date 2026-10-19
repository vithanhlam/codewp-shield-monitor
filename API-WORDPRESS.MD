# ShieldPress WordPress API Notes

This file explains how the App/API server should read Contact Form 7 submissions, WooCommerce orders, and other notification events from the WordPress plugin.

Base namespace:

```http
/wp-json/codewp-shield-monitor/v1
```

## Authentication

After pairing, WordPress returns an API token and endpoint URLs. Store the token securely on the App/API server.

Send the token on every authenticated request:

```http
Authorization: Bearer YOUR_TOKEN
```

If the host strips the `Authorization` header, use:

```http
X-CodeWP-Shield-Monitor-Token: YOUR_TOKEN
```

The token must include the `events:read` scope.

## Check New Order Notifications

WooCommerce order notifications are returned through the existing events API. There is no separate order-notification endpoint.

```http
GET https://example.com/wp-json/codewp-shield-monitor/v1/events?limit=50&after_id=LAST_EVENT_ID
Authorization: Bearer YOUR_TOKEN
```

Important fields:

```json
{
  "events": [],
  "next_cursor": 211,
  "latest_id": 211,
  "has_more": false
}
```

Store `next_cursor` as the new `LAST_EVENT_ID` after processing the response. On the next check, call:

```http
GET /wp-json/codewp-shield-monitor/v1/events?limit=50&after_id=211
```

WooCommerce order events have this type:

```json
{
  "id": 211,
  "type": "woocommerce_order_created",
  "title": "WooCommerce order",
  "category": "notifications",
  "severity": "low",
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

Contact Form 7 events have this type:

```json
{
  "id": 210,
  "type": "contact_form_7_submission",
  "category": "notifications",
  "details": {
    "notification": true,
    "source": "contact_form_7",
    "form_id": 12,
    "form_title": "Contact form",
    "posted_fields": {
      "your-name": "Lam",
      "your-email": "lam@example.com",
      "your-subject": "Need support"
    }
  }
}
```

## Recommended Sync Flow

1. After pairing, call `/status` once and read `event_sync.latest_id`.
2. Save that value as the site's initial cursor if you only want new events.
3. Poll `/events?limit=50&after_id={cursor}` every 30-60 seconds from the backend.
4. Process events where `type` is `woocommerce_order_created`, `contact_form_7_submission`, or `custom_post_type_created`.
5. Send mobile push notifications from your App/API server.
6. Save `next_cursor` only after events are processed successfully.
7. If `has_more` is `true`, call `/events` again immediately with the returned `next_cursor`.

## Is A New API Needed?

No separate API is required just to check order notifications continuously. The current `/events` endpoint already supports cursor-based sync through `after_id`, `next_cursor`, `latest_id`, and `has_more`.

For the first production version, backend polling is the simplest and most reliable option:

```text
App/API server -> poll WordPress /events every 30-60 seconds -> send mobile push
```

This avoids requiring every WordPress site to make outbound webhook requests, handle retry queues, or expose extra configuration.

## Polling vs Webhook/Ping

Polling is recommended when:

```text
- The app/server can tolerate a 30-60 second delay.
- You want fewer WordPress settings.
- You want easier debugging.
- You want predictable behavior on shared hosting.
```

Webhook/ping is recommended when:

```text
- Notifications must be near real-time.
- The API server is stable and publicly reachable.
- You can implement HMAC signatures, retries, and duplicate-event handling.
```

Most efficient long-term setup:

```text
WordPress creates event -> WordPress pings App/API server with event ID -> App/API server calls /events?after_id={cursor} -> App/API server sends push notification
```

In that model, the ping is only a wake-up signal. The App/API server should still fetch `/events` to get the canonical payload and avoid trusting an unauthenticated or partially delivered webhook body.

## WordPress Admin Requirements

Notification events are opt-in in the current plugin code. The site admin must enable:

```text
CodeWP Shield Monitor -> Notifications -> WooCommerce orders
CodeWP Shield Monitor -> Notifications -> Contact Form 7 submissions
```

Also keep local audit logs enabled, because notification events are stored in the local event table before `/events` can return them.

## Duplicate Handling

The App/API server should deduplicate by event `id`.

WooCommerce order events also include `details.order_id`, but the event `id` is the safest cursor key for API sync.

## Backup Overdue Alerts

The plugin monitors when the last backup was performed and sends alerts at three thresholds:

| Days without backup | Alert level | Severity |
|---------------------|-------------|----------|
| 3 days              | notice      | low      |
| 7 days              | warning     | medium   |
| 30 days (1 month)   | critical    | high     |

### Endpoint

```http
GET /wp-json/codewp-shield-monitor/v1/backup-status
```

No authentication required.

### Response

```json
{
  "api_version": 1,
  "generated_at": "2026-07-12T10:00:00+00:00",
  "last_backup_at": "2026-07-01T03:15:00+00:00",
  "days_since": 11,
  "thresholds": { "notice": 3, "warning": 7, "critical": 30 },
  "alert_level": "warning",
  "alert_message": "WARNING: No backup detected for 11 days. It is recommended to back up your website at least weekly."
}
```

If no backup has ever been recorded, `last_backup_at` and `days_since` will be `null`.

### Events via `/events`

Backup overdue events appear with type `backup_overdue_alert`:

```json
{
  "id": 250,
  "type": "backup_overdue_alert",
  "category": "backup",
  "details": {
    "alert_level": "warning",
    "days_since": 11,
    "last_backup": "2026-07-01T03:15:00+00:00"
  },
  "created_at": "2026-07-12T04:00:00+00:00"
}
```

### Status API

The `/status` response includes a `backup_alert` object:

```json
{
  "backup_alert": {
    "enabled": true,
    "last_backup_at": "2026-07-01T03:15:00+00:00",
    "days_since": 11,
    "alert_level": "warning",
    "thresholds": { "notice": 3, "warning": 7, "critical": 30 }
  }
}
```

### Action Hook

The plugin fires `shield_monitor_backup_overdue` when an overdue alert is triggered. The App/API server can listen via the events API or implement a webhook handler for this action.

### WordPress Admin Requirements

```text
CodeWP Shield Monitor -> Alerts -> Backup overdue alerts (enabled by default)
```

---

## Vulnerability Alerts (CVE)

As of 1.5.0, detailed results require an authenticated administrator or paired API token with `status:read` scope. A reviewed offline advisory list supplements available remote metadata; it is not a complete feed and does not claim to block the listed exploits.

The plugin scans all installed plugins for known security vulnerabilities (CVE) using the WordPress.org vulnerability database. It checks twice daily and immediately after plugin updates.

### Endpoint

```http
GET /wp-json/codewp-shield-monitor/v1/vulnerabilities
```

No authentication required.

### Response

```json
{
  "api_version": 1,
  "generated_at": "2026-07-12T10:00:00+00:00",
  "last_checked_at": "2026-07-12T06:00:00+00:00",
  "vulnerable_count": 2,
  "vulnerabilities": [
    {
      "plugin_file": "contact-form-7/wp-contact-form-7.php",
      "plugin_name": "Contact Form 7",
      "plugin_version": "5.8.0",
      "vulnerability": "Unrestricted File Upload",
      "cve_id": "CVE-2024-12345",
      "severity": "critical",
      "fixed_in": "5.8.1",
      "published_date": "2024-12-01",
      "references": [
        "https://nvd.nist.gov/vuln/detail/CVE-2024-12345"
      ]
    },
    {
      "plugin_file": "elementor/elementor.php",
      "plugin_name": "Elementor",
      "plugin_version": "3.18.0",
      "vulnerability": "Stored Cross-Site Scripting via Widget",
      "cve_id": "CVE-2024-67890",
      "severity": "high",
      "fixed_in": "3.18.2",
      "published_date": "2024-11-15",
      "references": [
        "https://nvd.nist.gov/vuln/detail/CVE-2024-67890"
      ]
    }
  ]
}
```

### Severity Levels

Mapped from CVSS score when available:

| CVSS Score | Severity |
|------------|----------|
| ≥ 9.0     | critical |
| ≥ 7.0     | high     |
| ≥ 4.0     | medium   |
| < 4.0     | low      |

### Events via `/events`

Vulnerability events appear with type `vulnerability_detected`:

```json
{
  "id": 260,
  "type": "vulnerability_detected",
  "category": "vulnerability",
  "details": {
    "count": 2,
    "total_active": 2,
    "plugins_affected": ["Contact Form 7", "Elementor"],
    "cve_ids": ["CVE-2024-12345", "CVE-2024-67890"]
  },
  "created_at": "2026-07-12T06:00:00+00:00"
}
```

### Status API

The `/status` response includes a `vulnerabilities` object:

```json
{
  "vulnerabilities": {
    "enabled": true,
    "last_checked_at": "2026-07-12T06:00:00+00:00",
    "total_count": 2,
    "critical_count": 1,
    "high_count": 1,
    "plugins_affected": ["Contact Form 7", "Elementor"]
  }
}
```

### Action Hook

The plugin fires `shield_monitor_vulnerability_detected` when new vulnerabilities are found. Payload:

```json
{
  "vulnerabilities": [ /* array of new vulns */ ],
  "total_count": 2,
  "site_url": "https://example.com/",
  "site_name": "My Site"
}
```

### Recommended App/API Server Behavior

1. Poll `/events` as usual. Filter for `type = "vulnerability_detected"`.
2. Or poll `/vulnerabilities` periodically (every 6-12 hours) to get the full list.
3. Send push notification to the app user when `vulnerable_count > 0`.
4. Show critical/high vulnerabilities prominently in the app dashboard.
5. After the user updates the plugin, the next scan will clear the resolved vulnerability automatically.

### WordPress Admin Requirements

```text
CodeWP Shield Monitor -> Alerts -> Vulnerability alerts (enabled by default)
```

---

## Licensing and paid addons

Version 1.5.0 removes the paid plans, license activation/verification endpoints, premium addon loader, and license-authenticated metrics push job. App pairing and scoped site API tokens remain available without a paid plugin plan.

---

## Debug Checklist

If a new order does not appear in the app:

```text
1. Confirm WooCommerce orders notification is enabled in wp-admin.
2. Confirm local audit logs are enabled.
3. Confirm the app token has events:read scope.
4. Call GET /events?limit=20 without after_id to see recent events.
5. Place a new test order and call GET /events?after_id={old_latest_id}.
6. Confirm the app saves next_cursor after processing.
7. Confirm mobile push is sent by the App/API server after receiving the event.
```
