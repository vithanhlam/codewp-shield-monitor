# Disposable lab drivers

These drivers expect `SHIELDPRESS_LAB_DIR/wordpress` to be a newly installed WordPress 7.1.3 site named **ShieldPress isolated lab**, with an administrator named `lab_admin` (ID 1), UpdraftPlus Free 1.26.11 and this plugin installed under `wp-content/plugins/shieldpress`. They are development tools, excluded from the plugin ZIP.

Use a separate SQLite database with the official SQLite Database Integration drop-in. Configure `WP_ENVIRONMENT_TYPE=local`, `WP_HTTP_BLOCK_EXTERNAL=true`, `DISABLE_WP_CRON=true`, `FS_METHOD=direct`, and site/home URL `http://127.0.0.1:18773`. Configure disposable authentication salts and admin credentials, never production credentials. Keep the PHP server bound to `127.0.0.1` only.

After installing and activating the lab plugins, run:

```sh
export SHIELDPRESS_LAB_DIR=/tmp/shieldpress-udrpc-audit
php tests/security/lab/control.php generate
php -S 127.0.0.1:18773 -t "$SHIELDPRESS_LAB_DIR/wordpress"
```

In another terminal with the same environment variable:

```sh
python3 tests/security/lab/run-http.py after
php tests/security/lab/check-release.php
php tests/security/lab/setup-network.php
php tests/security/lab/check-multisite.php
```

`setup-network.php` creates network tables in the disposable database and is intended to run once. `check-multisite.php` boots that database as a real network. This checks super-admin capabilities, not a multisite HTTP RPC upload.

Run the uninstall regression **last**. It deletes ShieldPress data in the lab:

```sh
php tests/security/lab/check-uninstall.php
```

The HTTP runner only contacts the fixed localhost URL. It installs and activates marker plugins, toggles protection settings and creates/removes lab RPC keys. Keys and JSON reports stay in the lab directory. Stop the server after testing. Do not deploy lab drivers, marker plugins, keys or the lab WordPress directory to a real website.
