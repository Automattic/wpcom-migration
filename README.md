# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (credentials, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (admin screen), `bootstrap.php`.
- `bin/build.sh` — writes `build/wpcom-migration/` and `build/wpcom-migration.zip`.
- `bin/check-autoload-manifest.php` — asserts which classes the ZIP publishes through the Jetpack autoloader.
- `tests/e2e/` — Playground blueprints and request scripts: one scenario per credential state, one that drives the settings screen.

## Build

Needs PHP 8.2+, Composer, `rsync`, `zip`.

```sh
composer install      # PHPCS, WPCS
bin/build.sh
```

The build runs `composer install` in `plugin/`, copies the tree to a staging directory, checks the autoload manifest there, then writes `build/`. The plugin needs PHP 7.4 or newer (`Requires PHP: 7.4`), the floor of the Jetpack packages it uses.

To activate a source checkout directly (without a build), run `composer install --no-dev --working-dir=plugin` first; without `plugin/vendor/` the plugin activates but the exporter and the WordPress.com connection are absent.

## The export screen

`wp-admin/admin.php?page=wpcom-migration-reprint` (no menu entry; `manage_options`; single-site only). Paste the shared secret WordPress.com hands out, then tick *Enable the exporter*. The window stays open for an hour after the last export request. The remote API URL is `home_url( '?reprint-api-wpcom-migration' )`. Activating or deactivating the plugin discards the stored secret and window; the hooks are registered from `plugin/reprint/bootstrap.php`.

Each state change and every served or refused request fires `wpcom_migration_reprint_export_event` with an event name and context; none carries the secret, a hash or a signature.

## Checks

```sh
composer lint                     # PHPCS, WordPress Coding Standards
composer lint:php:compat          # PHPCompatibility, testVersion 7.4-
composer test:e2e                 # Playground e2e against build/wpcom-migration
```

`lint:php:compat` checks the repository's own PHP against the 7.4 floor, including functions `php -l` cannot see; `vendor/` is each package's own job. PHPCompatibility 10 is pinned at a pre-release; move the constraint to `^10.0` when it ships.

`.github/workflows/build.yml` runs on every push and pull request: build and upload the ZIP; `php -l` the built tree on PHP 7.4 and 8.4; PHPCS and the compatibility lint; the Playground e2e scenarios against the ZIP.

Publishing to wp.org is not automated.

## Known limitations

- Running this plugin next to `reprint-server-wp` is unsupported. Both ship the same package; a request that loads classes from both copies can fatal on the package's path-required function files.
- The reprint client appends `&reprint-api` to any URL that lacks it. If `reprint-server-wp` is active and loads first, it answers on `reprint-api` before this plugin runs.
- Sites on placeholder salts get the write veto but not the salt binding: `wp_salt()` stores its own salt in `wp_options`, where whoever can write the credential can read it.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
