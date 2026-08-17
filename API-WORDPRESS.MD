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

## License Management

ShieldPress uses a hybrid license model (Option 3):

- **SaaS users**: License is auto-generated when a site is connected to the dashboard. No manual key entry needed.
- **Standalone users**: Purchase a license key directly (via LemonSqueezy/Paddle) and enter it in the plugin settings.

### License Pricing (Standalone)

| Plan | Annual | Lifetime | Sites |
|------|--------|----------|-------|
| Pro | $49/yr | $149 | 1 site |
| Business | $99/yr | $299 | 5 sites |
| Agency | $199/yr | $499 | Unlimited |

SaaS users get plugin licenses **included** with their monitoring plan:

| SaaS Plan | Plugin License | Site Limit |
|-----------|---------------|------------|
| FREE | No license (free plugin features only) | — |
| STARTER ($4.99/mo) | Pro | 5 |
| PRO ($9.99/mo) | Pro | 30 |
| BUSINESS ($29.99/mo) | Business | 100 |
| AGENCY ($49.99/mo) | Agency | 300 |
| ENTERPRISE ($99.99/mo) | Agency | 1000 |

### License Key Format

```
SPMP-A1B2-C3D4-E5F6-G7H8
```

32 hex characters, grouped in 4-char blocks, prefixed with `SPMP-`.

### How the Plugin Should Handle Licenses

#### 1. Check if license exists on activation

When the plugin is activated (or on the settings page), check if a license key is stored in `wp_options`:

```php
$license_key = get_option('shieldpress_license_key', '');
$license_status = get_option('shieldpress_license_status', 'inactive');
```

#### 2. Verify license on load (daily)

Call the verify endpoint once per day (cache result in a transient):

```http
POST https://api.shieldpress.net/api/licenses/verify
Content-Type: application/json

{
  "license_key": "SPMP-A1B2-C3D4-E5F6-G7H8",
  "site_url": "https://example.com"
}
```

Response (valid):

```json
{
  "valid": true,
  "activated": true,
  "plan": "PRO",
  "billing_type": "ANNUAL",
  "site_limit": 30,
  "activated_count": 5,
  "expires_at": "2027-07-12T00:00:00.000Z",
  "days_until_expiry": 365,
  "source": "saas",
  "features": [
    "Vulnerability scanning (CVE)",
    "Malware detection",
    "File integrity monitoring",
    "Login security alerts",
    "Backup overdue alerts",
    "Auto-updates management"
  ]
}
```

Response (invalid):

```json
{
  "valid": false,
  "error": "License expired or inactive",
  "status": "EXPIRED"
}
```

#### 3. Activate license on a site

Called when the user enters a license key for the first time, or when the SaaS dashboard auto-provides one during pairing:

```http
POST https://api.shieldpress.net/api/licenses/activate
Content-Type: application/json

{
  "license_key": "SPMP-A1B2-C3D4-E5F6-G7H8",
  "site_url": "https://example.com",
  "site_name": "My Blog",
  "wp_version": "6.7.1",
  "plugin_version": "1.2.0"
}
```

Response (success):

```json
{
  "activated": true,
  "plan": "PRO",
  "site_limit": 30,
  "activated_count": 6,
  "features": ["Vulnerability scanning (CVE)", "..."]
}
```

Response (site limit reached):

```json
{
  "activated": false,
  "error": "Site limit reached (1). Deactivate a site or upgrade your plan.",
  "activated_count": 1,
  "site_limit": 1
}
```

#### 4. Deactivate license from a site

Called when the user removes the license key, or when the plugin is deactivated:

```http
POST https://api.shieldpress.net/api/licenses/deactivate
Content-Type: application/json

{
  "license_key": "SPMP-A1B2-C3D4-E5F6-G7H8",
  "site_url": "https://example.com"
}
```

Response:

```json
{
  "deactivated": true,
  "message": "Site deactivated"
}
```

### Auto-License Flow (SaaS Users)

When a SaaS user connects a site to the ShieldPress dashboard via pairing:

```
1. User enters pairing code in ShieldPress app/dashboard
2. App calls POST /api/sites/pair
3. API pairs with WordPress plugin
4. API auto-generates license (or finds existing SaaS license)
5. API activates license for the paired site URL
6. API returns license key in the pairing response:

   {
     "site": { "id": "...", "name": "My Site" },
     "license": {
       "licenseKey": "SPMP-A1B2-C3D4-E5F6-G7H8",
       "plan": "PRO",
       "activated": true
     },
     "message": "Website connected successfully"
   }

7. The ShieldPress app/dashboard can display the license key to the user
8. The WordPress plugin should call POST /api/licenses/verify to confirm
```

The plugin can auto-receive the license key in two ways:

**Option A — Plugin checks after pairing:**

After pairing completes, the plugin calls the ShieldPress API to check if a license was auto-assigned:

```http
GET https://api.shieldpress.net/api/licenses/verify
Content-Type: application/json

{
  "license_key": "",
  "site_url": "https://example.com"
}
```

If the API returns a valid license for this `site_url`, store it automatically.

**Option B — Dashboard sends license key during sync:**

The `/status` endpoint response includes the license key when available. The plugin reads it from the sync response and stores it:

```json
{
  "site": { "wordpress_version": "6.7.1" },
  "system": { "cpu": {}, "memory": {}, "disk": {} },
  "license": {
    "key": "SPMP-A1B2-C3D4-E5F6-G7H8",
    "plan": "PRO",
    "source": "saas"
  }
}
```

### Feature Gating in the Plugin

Use the `features` array from the verify response to enable/disable Pro features:

```php
$license = get_transient('shieldpress_license_data');

// Free features (always available)
// - Basic uptime monitoring
// - SSL/domain expiry checks
// - Email alerts
// - Health score

// Pro features (require valid license)
if ($license && $license['valid']) {
    $features = $license['features'] ?? [];

    // CVE vulnerability scanning
    if (in_array('Vulnerability scanning (CVE)', $features)) {
        // Enable local vulnerability checks
        // Report to /wp-json/codewp-shield-monitor/v1/vulnerabilities
    }

    // Malware detection
    if (in_array('Malware detection', $features)) {
        // Enable malware scanning
    }

    // File integrity monitoring
    if (in_array('File integrity monitoring', $features)) {
        // Enable file change detection
    }
}
```

### License Expiry & Grace Period

- Annual licenses expire after 1 year from purchase/renewal date.
- A **7-day grace period** is applied after expiry — plugin Pro features remain active.
- After grace period, the API returns `valid: false` and the plugin should fall back to free features.
- The `days_until_expiry` field helps show warnings in the plugin UI:

```text
days_until_expiry > 30  → No warning
days_until_expiry ≤ 30  → Yellow notice: "License expires in X days"
days_until_expiry ≤ 7   → Red warning: "License expiring soon"
days_until_expiry ≤ 0   → "License expired — grace period active"
days_until_expiry < -7  → "License expired — Pro features disabled"
```

### License Status Values

| Status | Meaning |
|--------|---------|
| `ACTIVE` | Valid and working |
| `EXPIRED` | Past expiry + grace period |
| `REVOKED` | Manually revoked by admin |
| `REFUNDED` | Payment refunded |

### Source Values

| Source | Meaning |
|--------|---------|
| `saas` | Auto-generated from SaaS monitoring plan (free, included) |
| `standalone` | Purchased separately via LemonSqueezy/Paddle |

### License Security — Anti-Piracy Mechanisms

The API uses multiple layers to prevent license key sharing and abuse:

#### 1. Domain Binding

Each license key is locked to the sites where it was activated. The plugin must send `site_url` with every request. The server compares it against `license_activations.site_url`.

```text
Key activated on: https://example.com
Request from: https://other-site.com → 403 Rejected
```

#### 2. Site Fingerprint

On activation, the plugin should generate a unique fingerprint and send it as `site_hash`:

```php
// Generate in WordPress plugin:
$site_hash = hash('sha256', $site_url . '|' . $table_prefix . '|' . AUTH_KEY);
```

The server stores this hash. If the same key is used with a different hash, the server logs it as suspicious (possible key sharing). This prevents someone from spoofing the `site_url`.

#### 3. Callback Verification

After activation, the server calls back to the WordPress site to confirm the plugin is really installed:

```http
POST https://example.com/wp-json/codewp-shield-monitor/v1/license-verify

{
  "challenge": "random_hex_string"
}
```

The plugin should respond:

```json
{
  "response_hash": "sha256(license_key + challenge)",
  "server_ip": "203.0.113.42",
  "plugin_version": "1.3.5"
}
```

The plugin must implement this endpoint:

```php
register_rest_route('codewp-shield-monitor/v1', '/license-verify', [
    'methods' => 'POST',
    'callback' => function ($request) {
        $challenge = $request->get_param('challenge');
        $license_key = get_option('shieldpress_license_key', '');

        return [
            'response_hash' => hash('sha256', $license_key . $challenge),
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
            'plugin_version' => SHIELDPRESS_VERSION,
        ];
    },
    'permission_callback' => '__return_true',
]);
```

#### 4. Rate Limiting Per Key

Each license key has request limits:

| Endpoint | Limit | Window |
|----------|-------|--------|
| verify | 200 requests | per day |
| activate | 10 requests | per hour |
| deactivate | 10 requests | per hour |
| download (addons) | 20 requests | per day |
| data (threat feeds) | 100 requests | per day |

Exceeded → HTTP 429 with `retry_after` timestamp.

#### 5. Localhost Blocking

Only public domains are allowed for activation. The server rejects:

```text
localhost, 127.0.0.1, *.local, *.test, *.invalid
Private IPs: 10.x.x.x, 172.16-31.x.x, 192.168.x.x
Domains without dots (e.g. "mysite" without TLD)
```

#### 6. Anomaly Detection

The server tracks IP addresses for each verify request. If a single license key is used from more than 10 unique IPs, it is flagged as suspicious in the admin dashboard.

#### 7. What the Plugin Should Send on Every Request

```json
{
  "license_key": "SPMP-A1B2-C3D4-E5F6-G7H8",
  "site_url": "https://example.com",
  "site_hash": "sha256_fingerprint",
  "plugin_version": "1.3.5",
  "wp_version": "6.7.1",
  "php_version": "8.2.0"
}
```

---

### Pro Addons — License-Gated Downloads

Pro features are delivered as downloadable addon modules. Each addon requires a valid license and minimum plan level. Addons are NOT bundled in the free plugin — they are downloaded on-demand when the user activates a license.

#### Available Addons (Example)

| Addon Slug | Name | Min Plan | Description |
|------------|------|----------|-------------|
| `malware-scanner` | Malware Scanner | PRO | Deep file scanning for malicious code |
| `vulnerability-db` | Vulnerability Database | PRO | Local CVE database with daily updates |
| `file-integrity` | File Integrity Monitor | PRO | Tracks file changes and alerts on modifications |
| `security-hardening` | Security Hardening | BUSINESS | One-click security hardening rules |
| `white-label` | White Label | AGENCY | Remove ShieldPress branding |

#### List Available Addons

```http
GET https://api.shieldpress.net/api/licenses/addons
```

Response:

```json
[
  {
    "id": "addon_123",
    "slug": "malware-scanner",
    "name": "Malware Scanner",
    "description": "Deep file scanning for malicious code patterns",
    "version": "1.2.0",
    "minPlan": "PRO",
    "fileSize": 245760
  }
]
```

#### Download an Addon

```http
POST https://api.shieldpress.net/api/licenses/addons/download
Content-Type: application/json

{
  "license_key": "SPMP-A1B2-C3D4-E5F6-G7H8",
  "site_url": "https://example.com",
  "addon_slug": "malware-scanner"
}
```

Response (success):

```json
{
  "addon": { "slug": "malware-scanner", "name": "Malware Scanner", "version": "1.2.0" },
  "download_url": "https://api.shieldpress.net/api/licenses/addons/file/malware-scanner?token=...",
  "checksum": "sha256_of_zip_file",
  "expires_in": 300
}
```

The `download_url` contains a signed token that expires in 5 minutes. The plugin should:

1. Request the download URL
2. Download the zip file from the signed URL
3. Verify the `checksum` (sha256) matches
4. Extract to `wp-content/plugins/shieldpress-monitor-pro/addons/`
5. Activate the addon module

Response (plan insufficient):

```json
{
  "error": "This addon requires BUSINESS plan or higher. Your plan: PRO",
  "required_plan": "BUSINESS",
  "current_plan": "PRO"
}
```

#### Plugin Addon Loading Pattern

```php
// In main plugin file:
$addons_dir = plugin_dir_path(__FILE__) . 'addons/';
$active_addons = get_option('shieldpress_active_addons', []);

foreach ($active_addons as $addon_slug) {
    $addon_file = $addons_dir . $addon_slug . '/init.php';
    if (file_exists($addon_file)) {
        require_once $addon_file;
    }
}
```

#### Auto-Update Addons

When the plugin verifies the license daily, it should also check addon versions:

```php
// Compare installed addon versions with API versions
$installed = get_option('shieldpress_addon_versions', []);
$api_addons = fetch_available_addons(); // GET /api/licenses/addons

foreach ($api_addons as $addon) {
    if (isset($installed[$addon['slug']]) && version_compare($installed[$addon['slug']], $addon['version'], '<')) {
        // Newer version available — offer update or auto-download
        download_and_install_addon($addon['slug']);
    }
}
```

---

### WordPress Admin UI Requirements

The plugin should show a License section in Settings:

```text
CodeWP Shield Monitor → Settings → License

┌─────────────────────────────────────────────────┐
│ License Key: [SPMP-A1B2-C3D4-E5F6-G7H8    ] [✓]│
│                                                 │
│ Status: ● Active                                │
│ Plan: Pro                                       │
│ Source: Included with SaaS plan                 │
│ Expires: July 12, 2027 (365 days remaining)     │
│ Sites: 5 / 30 activated                         │
│                                                 │
│ [Deactivate License]                            │
└─────────────────────────────────────────────────┘
```

If the license was auto-provided via SaaS pairing, show:

```text
"This license is included with your ShieldPress monitoring plan.
 Manage your subscription at app.shieldpress.net"
```

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

If a license is not working:

```text
1. Call POST /api/licenses/verify with the license key and site URL.
2. Check the "valid", "activated", and "source" fields in the response.
3. If source is "saas" — confirm the site is connected in the ShieldPress dashboard.
4. If "activated" is false — call POST /api/licenses/activate.
5. If "error" is "Site limit reached" — deactivate another site or upgrade plan.
6. If "error" is "License expired" — check expires_at and grace period (7 days).
7. For SaaS licenses: confirm the user's SaaS plan is active (not FREE/expired).
8. Check wp_options for shieldpress_license_key and shieldpress_license_status.
9. Clear the transient: delete_transient('shieldpress_license_data') and reload.
```
