# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (credentials, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (admin screen), `bootstrap.php`.
- `plugin/connection/` — the WordPress.com connection: `Connection` (package setup, connect, disconnect), `REST_Controller` (`wpcom-migration/v1/reprint/rotate-export-secret`, `…/enable-export`), `Connect_Page` (admin screen), `bootstrap.php`.
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

## The WordPress.com account screen

`wp-admin/admin.php?page=wpcom-migration-connect` (under the plugin's menu; `manage_options` to view, the `administrator` role to act; single-site only). *Log in with WordPress.com* registers the site with WordPress.com through `automattic/jetpack-connection` and sends the user to WordPress.com to authorize; WordPress.com's authorize page sends them back to this screen (`redirect_after_auth`, with `skip_pricing` so it skips the Jetpack plans page). Once connected the screen shows the WordPress.com login, the blog ID, a *Continue on WordPress.com* link (filter `wpcom_migration_continue_url`) and *Disconnect*. Deactivating the plugin disconnects.

With a user connection in place, WordPress.com provisions the exporter through two routes, both `POST`, both signed with the user token of an administrator: `wpcom-migration/v1/reprint/rotate-export-secret` returns a new secret, `wpcom-migration/v1/reprint/enable-export` opens the export window; both answers carry `export_url`. Connection events fire `wpcom_migration_connection_event` with an event name and context; none carries a token or secret.

On a site where Jetpack is already connected, the screen reads as connected at once: the connection is shared, and this plugin is one more plugin using it.

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
- The connection package requires PHP 7.4, so the plugin does too.
- `tests/e2e/` plants tokens instead of logging in; it checks the authorize URL the screen sends the user to, but not WordPress.com's side of registration or authorization.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
