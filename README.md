# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (credentials, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (the Reprint migration screen), `REST_Controller` (provisioning routes), `bootstrap.php`.
- `rector.php` — Rector's own downgrade set, applied at build time to a copy of `vendor/` and `reprint/` so the ZIP runs on PHP 7.1 (`Requires PHP: 7.1`).
- `bin/build.sh` — writes `build/wpcom-migration/` and `build/wpcom-migration.zip`.
- `bin/check-autoload-manifest.php` — asserts which classes the ZIP publishes through the Jetpack autoloader.
- `tests/e2e/` — Playground blueprints and request scripts: one scenario per credential state, one that drives the settings screen, one that provisions through the REST routes.

## Build

Needs PHP 8.2+, Composer, `rsync`, `zip`.

```sh
composer install      # PHPCS, WPCS, Rector
bin/build.sh
```

The build runs `composer install` in `plugin/`, copies the tree to a staging directory, downgrades `vendor/` and `reprint/` there to PHP 7.1 syntax, checks the autoload manifest, then writes `build/`. `plugin/` itself is never rewritten.

To activate a source checkout directly (without a build), run `composer install --no-dev --working-dir=plugin` first; without `plugin/vendor/` the plugin activates but the exporter is absent. That un-downgraded `plugin/vendor/` needs PHP 7.2 or newer; only the built ZIP runs on 7.1.

## The Reprint migration screen

`wp-admin/admin.php?page=wpcom-migration-status` (under the plugin's menu; `manage_options`; single-site only). A table shows the export secret and the exporter window, with one line of advice: a site WordPress.com provisioned reads "nothing to do here", a fresh one reads "start on WordPress.com, or set up by hand". Under *Set up by hand*: the secret form, the enable toggle and the export URL. The window stays open for an hour after the last export request. Activating or deactivating the plugin discards the stored secret and window.

Each state change and every served or refused request fires `wpcom_migration_reprint_export_event` with an event name and context; none carries the secret, a hash or a signature.

## How WordPress.com provisions the exporter

Reprint transfers over https only, so WordPress.com first checks `authentication.application-passwords` in `GET /wp-json/` (core lists it only where `is_ssl()` is true or `WP_ENVIRONMENT_TYPE` is `local`); a site without it is told to turn on HTTPS. It then sends the administrator once to `wp-admin/authorize-application.php?app_name=Migrate+to+WordPress.com&app_id=<uuid>&success_url=…&reject_url=…`; on approval core redirects to `success_url` with `site_url`, `user_login` and `password`. With that application password and basic auth, WordPress.com:

1. installs and activates the plugin: `POST /wp-json/wp/v2/plugins` with `{"slug":"wpcom-migration","status":"active"}`. Core has no update route, so an older copy is replaced with `PUT …/plugins/wpcom-migration/wpcom_migration {"status":"inactive"}`, `DELETE` the same path, then the `POST` above;
2. `POST /wp-json/wpcom-migration/v1/reprint/rotate-export-secret` → `{ "secret", "export_url" }`;
3. `POST /wp-json/wpcom-migration/v1/reprint/enable-export` → `{ "enabled_at", "export_url" }`;
4. exports through `export_url` with Reprint's signed requests;
5. afterwards revokes the password: `GET /wp-json/wp/v2/users/me/application-passwords/introspect` for its uuid, then `DELETE …/application-passwords/<uuid>`.

Both routes require an authenticated user with the `administrator` role; how the user authenticated is core's business. On a network they refuse with 501: the exporter is single-site only. WordPress.com never holds a login on the site; the administrator logs in once to approve the application password.

## Checks

```sh
composer lint                     # PHPCS, WordPress Coding Standards
composer lint:php:compat          # PHPCompatibility, testVersion 7.1-
composer test:e2e                 # Playground e2e against build/wpcom-migration
```

`lint:php:compat` checks the repository's own PHP against the 7.1 floor, including functions `php -l` cannot see; `vendor/` is each package's own job. PHPCompatibility 10 is pinned at a pre-release; move the constraint to `^10.0` when it ships.

`.github/workflows/build.yml` runs on every push and pull request: build and upload the ZIP; `php -l` the built tree on PHP 7.1, 7.4 and 8.4; PHPCS and the compatibility lint; the Playground e2e scenarios against the ZIP.

Publishing to wp.org is not automated.

## Known limitations

- Running this plugin next to `reprint-server-wp` is unsupported. Both ship the same package; a request that loads classes from both copies can fatal on the package's path-required function files.
- The reprint client appends `&reprint-api` to any URL that lacks it. If `reprint-server-wp` is active and loads first, it answers on `reprint-api` before this plugin runs.
- Sites on placeholder salts get the write veto but not the salt binding: `wp_salt()` stores its own salt in `wp_options`, where whoever can write the credential can read it.
- Reprint transfers over https only; a site that cannot serve https cannot be migrated this way.
- `wp/v2/plugins` installs from wordpress.org by slug only, so the version with the exporter must be published there before WordPress.com can install it.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
