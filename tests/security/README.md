# Security regression checks

Run the offline version-boundary tests without a site or network access:

```sh
php tests/security/check-known-vulnerabilities.php
```

The HTTP integration run used a disposable WordPress 7.1.3 installation with SQLite, UpdraftPlus Free 1.26.11, and a copy of ShieldPress in `/tmp/shieldpress-udrpc-audit`. Only `127.0.0.1:18773` was exposed. External WordPress HTTP requests and WP-Cron were disabled. All RSA keys were generated locally for the lab.

The uploaded plugin only registers an activation hook that appends its slug to a lab option. It contains no backdoor, account creation or executable command interface. Each HTTP test uses a fresh plugin slug to avoid previously installed files producing false positives.

Verification results are stored in `docs/verification-1.5.0/`. Lab-only drivers are in `tests/security/lab/`; they expect the disposable layout described in that directory. Never point them at an existing website. They change lab settings, create authorized lab keys, install marker plugins and create a network in the lab database.

See `docs/UDRPC-SECURITY-CHECK-2026-10-19.md` for the distinction between tested transport/installation behavior and the unverified October incident reports. No original incident payload or Premium package was available. No generic JSON inspection or exhaustive authentication-bypass protection is claimed.
