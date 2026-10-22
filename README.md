# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (the shared secret and public key, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (the Reprint migration screen), `Manual_Page` (the by-hand screen), `Key_Page` (the migration key screen), `REST_Controller` (provisioning routes), `bootstrap.php`.
- `plugin/connection/` — `Connection` (package setup, connect, disconnect), `Connect_Page` (the connection section of the screen), `bootstrap.php`.
- `bin/build.sh` — writes `build/wpcom-migration/` and `build/wpcom-migration.zip`.
- `bin/check-autoload-manifest.php` — asserts which classes the ZIP publishes through the Jetpack autoloader.
- `bin/*release*`, `bin/check-tag-on-trunk.sh`, `tests/release/` — the release tooling the workflows run, and its tests.
- `tests/e2e/` — Playground blueprints and request scripts: one scenario per credential state, two that drive the screens (with a public key, and with a shared secret), two that provision through the REST routes (with a public key, and with a shared secret on a host made to look like it lacks OpenSSL), one that drives the WordPress.com connection, one that checks the admin menu, and one that checks the menu on a network.

## Build

Needs PHP 8.2+, Composer, `rsync`, `zip`.

```sh
composer install      # PHPCS, WPCS
bin/build.sh
```

The build runs `composer install` in `plugin/`, copies the tree to a staging directory, checks the autoload manifest there, then writes `build/`. The plugin needs PHP 7.4 or newer (`Requires PHP: 7.4`), the floor of the Jetpack packages it uses.

To activate a source checkout directly (without a build), run `composer install --no-dev --working-dir=plugin` first; without `plugin/vendor/` the plugin activates but the exporter and the WordPress.com connection are absent.

## The screens

`wp-admin/admin.php?page=wpcom-migration-status` is the Reprint migration screen. It tells the administrator where the site stands in the migration and offers the next step: connect the site to WordPress.com or continue on WordPress.com. Viewing it needs `manage_options`. Connecting, disconnecting, and provisioning through the routes need the `administrator` role. It works on single sites only, and shows one mode at a time:

| Mode | When | Shows |
|---|---|---|
| The exporter runs on single sites only | Multisite | Nothing |
| The export credential no longer matches this site | The site's salts changed | *Connect WordPress.com*, or *Go to WordPress.com* and *Disconnect* when connected |
| Pill: *Site ready to migrate* | A credential is stored, so the exporter is on | *Go to WordPress.com* |
| Pill: *Connected to WordPress.com* | Logged in; no credential yet | *Go to WordPress.com*, a *Disconnect* link |
| Log in with your WordPress.com account to connect this site | Fresh site | *Connect WordPress.com* |

`Settings_Page::mode()` picks the first that fits, top to bottom. *Go to WordPress.com* links to `https://my.wordpress.com/sites`, and the `wpcom_migration_continue_url` filter changes that.

When the site has a Jetpack connection, `Continue_Url` hooks that filter to link straight to the WordPress.com site this one is migrating into. It asks `GET https://public-api.wordpress.com/wpcom/v2/migration-plugin/destination?source_url=<home_url()>` for `{ "matched", "url", "ttl" }`, signed with the current user's Jetpack token, or with the Jetpack blog token when only the site is connected. The answer is kept in the `wpcom_migration_continue_url` option until the `ttl` runs out. A failed fetch keeps the stored answer and retries after five minutes. Disconnecting, deactivating or authorizing a user deletes the stored answer. A site with a credential but no Jetpack connection keeps the default link.

After authorizing, WordPress.com sends the user back to the screen with `wpcom_migration_connected=1`. When that user is connected and WordPress.com matched a destination, `Connect_Page::handle_return()` redirects them straight there. Otherwise the screen renders as usual, and wp-admin drops the marker from the address bar.

`wp-admin/admin.php?page=wpcom-migration-manual` sets up Reprint by hand; see [Setting up Reprint manually](#setting-up-reprint-manually).

`wp-admin/admin.php?page=wpcom-migration-key` is where an administrator pastes the migration key WordPress.com shows when the connection fails. The fresh-site mode links to it, and a pasted key leads back to the status screen as *Site ready to migrate*. On a host without OpenSSL it asks the administrator to contact support.

`wp-admin/admin.php?page=wpcom-migration` is the BlogVault screen the plugin had before Reprint.

Until the WordPress.com side of Reprint ships, WordPress.com decides which of the Reprint and BlogVault screens is the main one: `Main_Screen` asks `GET https://public-api.wordpress.com/wpcom/v2/migration-plugin-config` for `{ "main_screen": "reprint" | "blogvault", "ttl": <seconds> }` on the first wp-admin page load after its stored answer (`wpcom_migration_main_screen`) expires. A failed fetch keeps the stored answer, or uses `blogvault` when there is none, and retries after five minutes. The main screen gets the plugin's one sidebar row, the *Settings* link on the plugin list, and the activation redirect: every activation sends the administrator to it on their next wp-admin page load, except a bulk activation, which stays on the plugin list. The other screen stays reachable at its URL, as does the manual one. A brand whitelabelled with `hide` or `hide_from_menu` gets no sidebar row for either screen.

Networks never ask. On a network the BlogVault screen keeps its row in the network admin, and the *Settings* link and the activation redirect lead there; subsites get no row.

## Setting up Reprint manually

`wp-admin/admin.php?page=wpcom-migration-manual` (`administrator` role, single-site only). It is a debugging screen, and nothing links to it. It holds the credential the host verifies: on a host with `openssl_verify()`, a public key textarea (*Enroll key*, PEM or one line, replacing any enrolled key) and a table with the enrolled key's id and a *Remove* button; elsewhere, the export secret form and a *Remove secret* button. Below it, the export URL and the WordPress.com blog ID. Deactivating the plugin disconnects and discards both credentials.

## How WordPress.com provisions the exporter

WordPress.com reaches the site in one of two ways, described below: with an application password, or through a Jetpack connection. Either way it calls one of two routes: `POST /wp-json/wpcom-migration/v1/reprint/install-public-key {"public_key"}` → `{ "key_id", "export_url" }` or `POST …/rotate-export-secret` → `{ "secret", "export_url" }`. Both require an administrator, authenticated by core (an application password) or by a Jetpack user token, and both answer 501 on a network.

Which credential WordPress.com sets up depends on the host. Where `openssl_verify()` exists, Reprint signs with keys: WordPress.com installs its public key (PEM or one line of base64, RSA, 3072 bits or more; anything else is a 400 `wpcom_migration_invalid_public_key`), and `rotate-export-secret` answers 501 `wpcom_migration_secret_auth_unsupported`. Elsewhere it rotates the secret, and `install-public-key` answers 501 `wpcom_migration_key_auth_unsupported`. The site holds one key at a time; installing another replaces it. The exporter is on while either credential is stored and valid; there is no separate switch and no time limit, since the plugin is removed once the migration is done. Export requests are verified by Reprint's `RequestAuthenticator`, which accepts whichever of the two the host supports.

**Application password** — where `GET /wp-json/` lists `authentication.application-passwords` (core: `is_ssl()` true and no plugin has switched them off). WordPress.com sends the administrator once to `wp-admin/authorize-application.php?app_name=Migrate+to+WordPress.com&app_id=<uuid>&success_url=…&reject_url=…`; core redirects to `success_url` with `site_url`, `user_login` and `password`. WordPress.com then checks the password with `GET /wp-json/wp/v2/users/me` (a 401 here means the server strips the `Authorization` header — Apache CGI without the rewrite rule WordPress 5.6 added; saving Permalinks regenerates it), installs and activates the plugin (`POST /wp-json/wp/v2/plugins {"slug":"wpcom-migration","status":"active"}`; an older copy is replaced by `PUT …/plugins/wpcom-migration/wpcom_migration {"status":"inactive"}`, `DELETE`, then the `POST`, since core has no update route), calls the route for the host's credential, exports, and revokes the password (`GET …/users/me/application-passwords/introspect`, then `DELETE …/application-passwords/<uuid>`). No login on the site.

**Jetpack connection** — everywhere else, including sites where Wordfence or another security plugin has disabled application passwords. The administrator installs the plugin from the plugin directory and presses *Connect WordPress.com* on the screen; the connection package registers the site and sends them to WordPress.com to authorize, then back. A site that already runs Jetpack is connected at once for the account that connected Jetpack — the connection is shared. WordPress.com then calls the route signed with the user token.

## Checks

```sh
composer lint                     # PHPCS, WordPress Coding Standards
composer lint:php:compat          # PHPCompatibility, testVersion 7.4-
composer test:e2e                 # Playground e2e against build/wpcom-migration
composer test:release             # the release scripts
```

`lint:php:compat` checks the repository's own PHP against the 7.4 floor, including functions `php -l` cannot see; `vendor/` is each package's own job. PHPCompatibility 10 is pinned at a pre-release; move the constraint to `^10.0` when it ships.

`.github/workflows/build.yml` runs on every push and pull request: build and upload the ZIP. `php -l` the built tree on PHP 7.4 and 8.4. PHPCS and the compatibility lint. The Playground e2e scenarios against the ZIP. The release script tests. *Release* also runs it against each release tag.

## Releasing

Run *Tag release* from the Actions tab with `minor`, `major` or a custom version. It commits the new version and its changelog to trunk and tags the commit. *Release* then builds and tests the tag and publishes a GitHub release, and *Deploy to WordPress.org* commits that release to the plugin directory. Each step can be retried by dispatching it with the tag.

The changelog is made from the titles of the pull requests merged since the last release, so write them for the people who install the plugin.

## Known limitations

- The reprint client appends `&reprint-api` to any URL that lacks it. If `reprint-server-wp` is active and loads first, it answers on `reprint-api` before this plugin runs.
- Sites on placeholder salts get the write veto but not the salt binding: `wp_salt()` stores its own salt in `wp_options`, where whoever can write the credential can read it.
- The Jetpack connection package (`automattic/jetpack-connection`) requires PHP 7.4, so the plugin does too.
- `wp/v2/plugins` installs from wordpress.org by slug only, so the version with the exporter must be published there before WordPress.com can install it.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
