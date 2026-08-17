# ShieldPress Addons

Place premium addons in this directory. Each addon must be in its own subdirectory with an `addon.php` entry file.

## Addon Structure

```
addons/
├── my-addon/
│   ├── addon.php        ← Entry file (required)
│   └── ...              ← Other addon files
└── another-addon/
    ├── addon.php
    └── ...
```

## Addon Header Format

The `addon.php` file must include a header comment block:

```php
<?php
/**
 * Addon Name: Advanced Firewall
 * Addon Slug: advanced-firewall
 * Requires Plan: pro
 * Description: Advanced firewall rules with real-time threat blocking.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Addon code here...
```

## Required Fields

| Field          | Description                                      |
|----------------|--------------------------------------------------|
| Addon Name     | Display name in the admin UI                     |
| Addon Slug     | Unique identifier (used for license feature check) |
| Requires Plan  | Minimum plan required: `pro`, `business`, `agency` |
| Description    | Short description shown in admin                 |
| Version        | Addon version number                             |

## How It Works

1. The license system checks the user's active plan.
2. Only addons whose `Requires Plan` matches or is below the user's plan are loaded.
3. Addons without a valid license will appear as "Requires license" in the admin.

## Available Plans

- `free` — No addons (default)
- `pro` — Basic premium addons
- `business` — All pro addons + business features
- `agency` — All features + multi-site management
