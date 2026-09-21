# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (credentials, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (the Reprint migration screen), `REST_Controller` (provisioning routes), `bootstrap.php`.
- `plugin/connection/` — `Connection` (package setup, connect, disconnect), `Connect_Page` (the connection section of the screen), `bootstrap.php`.
- `bin/build.sh` — writes `build/wpcom-migration/` and `build/wpcom-migration.zip`.
- `bin/check-autoload-manifest.php` — asserts which classes the ZIP publishes through the Jetpack autoloader.
- `tests/e2e/` — Playground blueprints and request scripts: one scenario per credential state, one that drives the Reprint migration screen, one that provisions through the REST routes, one that drives the WordPress.com connection.

## Build

Needs PHP 8.2+, Composer, `rsync`, `zip`.

```sh
composer install      # PHPCS, WPCS
bin/build.sh
```

The build runs `composer install` in `plugin/`, copies the tree to a staging directory, checks the autoload manifest there, then writes `build/`. The plugin needs PHP 7.4 or newer (`Requires PHP: 7.4`), the floor of the Jetpack packages it uses.

To activate a source checkout directly (without a build), run `composer install --no-dev --working-dir=plugin` first; without `plugin/vendor/` the plugin activates but the exporter and the WordPress.com connection are absent.

## The Reprint migration screen

`wp-admin/admin.php?page=wpcom-migration-status` (under the plugin's menu; `manage_options` to view and to set up by hand; the `administrator` role to connect, disconnect, or provision through the routes; single-site only). The screen shows one mode at a time — a heading, one sentence, at most one button, and the links that fit:

| Mode | When | Shows |
|---|---|---|
| Not available on networks | Multisite | Nothing |
| The export secret no longer matches this site | The site's salts changed | *Log in with WordPress.com*, or *Continue on WordPress.com* when connected |
| Exporter on until *time* | A migration is running | *Continue on WordPress.com* and *Disconnect*, when connected |
| Connected as *login* | Logged in; the migration has not started | *Continue on WordPress.com*, a *Disconnect* link |
| Set up by WordPress.com | Provisioned with an application password | A *Log in with WordPress.com* link |
| Connect this site to WordPress.com | Fresh site | *Log in with WordPress.com* |

`Settings_Page::mode()` picks the first that fits, top to bottom. The *Continue* link's target is filtered by `wpcom_migration_continue_url`. Under every mode but the first, a collapsed *Set up by hand* section holds the export secret form, the exporter toggle, the export URL and the WordPress.com blog ID. Deactivating the plugin disconnects and discards the secret and the exporter state.

## How WordPress.com provisions the exporter

Two lanes end at the same two routes, `POST /wp-json/wpcom-migration/v1/reprint/rotate-export-secret` → `{ "secret", "export_url" }` and `POST …/enable-export` → `{ "enabled_at", "export_url" }`. Both require an administrator, authenticated either by core (an application password) or by a Jetpack user token; `enable-export` answers 409 until a valid secret is stored, and both answer 501 on a network. Reprint transfers over https only.

**Application password** — where `GET /wp-json/` lists `authentication.application-passwords` (core: `is_ssl()` true and no plugin has switched them off). WordPress.com sends the administrator once to `wp-admin/authorize-application.php?app_name=Migrate+to+WordPress.com&app_id=<uuid>&success_url=…&reject_url=…`; core redirects to `success_url` with `site_url`, `user_login` and `password`. WordPress.com then checks the password with `GET /wp-json/wp/v2/users/me` (a 401 here means the server strips the `Authorization` header — Apache CGI without the rewrite rule WordPress 5.6 added; saving Permalinks regenerates it), installs and activates the plugin (`POST /wp-json/wp/v2/plugins {"slug":"wpcom-migration","status":"active"}`; an older copy is replaced by `PUT …/plugins/wpcom-migration/wpcom_migration {"status":"inactive"}`, `DELETE`, then the `POST`, since core has no update route), calls the two routes, exports, and revokes the password (`GET …/users/me/application-passwords/introspect`, then `DELETE …/application-passwords/<uuid>`). No login on the site.

**Jetpack connection** — everywhere else, including sites where Wordfence or another security plugin has disabled application passwords. The administrator installs the plugin from the plugin directory and presses *Log in with WordPress.com* on the screen; the connection package registers the site and sends them to WordPress.com to authorize, then back. A site that already runs Jetpack is connected at once for the account that connected Jetpack — the connection is shared. WordPress.com then calls the routes signed with the user token.

Connection events fire `wpcom_migration_connection_event`, exporter events `wpcom_migration_reprint_export_event`; none carries a token, secret or signature.

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
- Reprint transfers over https only; a site that cannot serve https cannot be migrated this way.
- `wp/v2/plugins` installs from wordpress.org by slug only, so the version with the exporter must be published there before WordPress.com can install it.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
