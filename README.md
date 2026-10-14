# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (the shared secret and public key, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (the Reprint migration screen), `Manual_Page` (the by-hand screen), `REST_Controller` (provisioning routes), `bootstrap.php`.
- `plugin/connection/` — `Connection` (package setup, connect, disconnect), `Connect_Page` (the connection section of the screen), `bootstrap.php`.
- `bin/build.sh` — writes `build/wpcom-migration/` and `build/wpcom-migration.zip`.
- `bin/check-autoload-manifest.php` — asserts which classes the ZIP publishes through the Jetpack autoloader.
- `bin/stamp-release.php`, `bin/check-release.php`, `bin/release-notes.php`, `bin/release-lib.php` — write, check and read the release version and changelog. `wpcom_migration_release_locations()` in `bin/release-lib.php` lists every place the plugin carries its version.
- `bin/latest-release.sh`, `bin/next-release-version.sh`, `bin/release-changelog.sh` — the latest release, the next version, and the changelog since a release.
- `tests/release/run.sh` — tests the release scripts against throwaway git repositories.
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

`Settings_Page::mode()` picks the first that fits, top to bottom. *Go to WordPress.com* links to `https://my.wordpress.com/sites`; the `wpcom_migration_continue_url` filter changes that.

`wp-admin/admin.php?page=wpcom-migration-manual` sets up Reprint by hand; see [Setting up Reprint manually](#setting-up-reprint-manually).

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

Versions are two-part (`6.73`, `7.0`), and tags carry no `v`.

Run *Tag release* from the Actions tab, on trunk, with `minor` (6.72 → 6.73), `major` (6.72 → 7.0), or `custom` and a version. It lists the pull requests squash-merged since the last release, writes the version and that list into `plugin/`, commits `Release X.Y` to trunk, and tags the commit. It refuses when nothing was merged, when the tag or the WordPress.org tag already exists, or when *Build* hasn't passed on trunk. Pull request titles become the changelog, so write them for the people who install the plugin.

*Release* follows on its own. It runs *Build* against the tag, checks the ZIP with `bin/check-release.php`, and creates the GitHub release with the ZIP and the changelog entry. *Deploy to WordPress.org* follows that. It checks the release ZIP again and commits it to SVN `trunk/` and `tags/X.Y/` with [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy). The update is live once SVN accepts the commit.

To retry a step, dispatch it from trunk with the tag. A dispatched *Deploy to WordPress.org* is a dry run that prints what it would commit. Uncheck `dry_run` to deploy. To redo a release whose code is broken, delete the tag, fix trunk, and run *Tag release* again. It computes the same version and replaces that version's changelog entry.

### Setup

Done once, by hand:

- Trunk has no classic branch protection, so *Tag release* can push with `GITHUB_TOKEN`. The `trunk` ruleset blocks deletion and force-push. Don't add ruleset rules that require pull requests, status checks or signed commits, or restrict updates. Each of them would block the release commit.
- A WordPress.org account that doesn't own the plugin is a committer on it (the plugin's Advanced view → Committers). It has two-factor authentication on, and an SVN password from Profile → Account & Security.
- The `wordpress.org` environment (Settings → Environments) allows deployments from `trunk` only. It holds that account's `SVN_USERNAME` and `SVN_PASSWORD`, and no other job can read them.

## Known limitations

- The reprint client appends `&reprint-api` to any URL that lacks it. If `reprint-server-wp` is active and loads first, it answers on `reprint-api` before this plugin runs.
- Sites on placeholder salts get the write veto but not the salt binding: `wp_salt()` stores its own salt in `wp_options`, where whoever can write the credential can read it.
- The Jetpack connection package (`automattic/jetpack-connection`) requires PHP 7.4, so the plugin does too.
- `wp/v2/plugins` installs from wordpress.org by slug only, so the version with the exporter must be published there before WordPress.com can install it.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
