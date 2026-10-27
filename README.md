# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record. The tree that ships is built from it.

## Layout

- `plugin/` — the plugin. Most of it is the upstream BlogVault tree.
- `plugin/reprint/` — the Reprint exporter, its screens, and the REST routes WordPress.com sets it up through.
- `plugin/connection/` — the Jetpack connection to WordPress.com and its part of the screen.
- `bin/` — the build and the release tooling.
- `tests/e2e/` — Playground scenarios that boot the built plugin and drive the exporter, the screens, the routes and the connection.
- `tests/release/` — tests for the release tooling.

## Build

Needs PHP 8.2+, Composer, `rsync`, `zip`.

```sh
composer install      # PHPCS, WPCS
bin/build.sh
```

The build writes `build/wpcom-migration/` and `build/wpcom-migration.zip`. The plugin itself runs on PHP 7.4 or newer, the floor of the Jetpack packages it uses. To activate a source checkout without building, run `composer install --no-dev --working-dir=plugin` first.

## How a migration works

WordPress.com copies sites with [Reprint](https://github.com/WordPress/reprint). This plugin implements Reprint's server module to spec, which is what WordPress.com exports the site through. It also adds REST API endpoints that set up the credential Reprint signs its requests with: a public key where the host has OpenSSL, a shared HMAC secret elsewhere. WordPress.com calls those endpoints with an application password or a Jetpack user token.

Where application passwords are unavailable, the administrator connects the site to WordPress.com from the *Connection* screen, or the plugin shares the connection of a site that already runs Jetpack. As a manual fallback, the administrator can paste the migration key WordPress.com shows into the *Migration Key* screen.

Deactivating the plugin disconnects it and discards the credentials.

## The screens

- *Connection* (`admin.php?page=wpcom-migration-status`) — connects the site to WordPress.com, then sends the user on to the migration there.
- *Migration Key* (`admin.php?page=wpcom-migration-key`) — where an administrator pastes the migration key.
- `admin.php?page=wpcom-migration-manual` — a debugging screen for setting up the credential by hand. Nothing links to it.
- `admin.php?page=wpcom-migration` — the BlogVault screen the plugin had before Reprint.

Until the WordPress.com side of Reprint ships, WordPress.com decides whether the Reprint or the BlogVault screen is the main one, which gets the sidebar row and the activation redirect. The other stays reachable at its URL. Reprint can't migrate a network, so on multisite only the BlogVault screen is offered.

## Checks

```sh
composer lint                     # PHPCS, WordPress Coding Standards
composer lint:php:compat          # PHPCompatibility, testVersion 7.4-
composer test:e2e                 # Playground e2e against build/wpcom-migration
composer test:release             # the release scripts
```

CI runs all of these on every push and pull request, and `php -l` on the built tree under PHP 7.4 and 8.4.

## Releasing

Run *Tag release* from the Actions tab with `minor`, `major` or a custom version. It commits the new version and its changelog to trunk and tags the commit. *Release* then builds and tests the tag and publishes a GitHub release, and *Deploy to WordPress.org* commits that release to the plugin directory. Each step can be retried by dispatching it with the tag.

The changelog is made from the titles of the pull requests merged since the last release. [AGENTS.md](AGENTS.md) says how to write them.

## Known limitations

- The reprint client appends `&reprint-api` to any URL that lacks it. If `reprint-server-wp` is active and loads first, it answers on `reprint-api` before this plugin runs.
- WordPress.com installs the plugin from wordpress.org by slug, so the version with the exporter must be published there before WordPress.com can install it.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
