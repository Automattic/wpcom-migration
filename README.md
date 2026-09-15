# Migrate to WordPress.com

Source for the [Migrate to WordPress.com](https://wordpress.org/plugins/wpcom-migration/) plugin. This repository is the source of record; the tree that ships is built from it.

## Layout

- `plugin/` — plugin source. The BlogVault tree plus `reprint/`, the Reprint export glue.
- `plugin/reprint/` — `Exporter` (credentials, write veto, `?reprint-api-wpcom-migration`), `Settings_Page` (admin screen), `bootstrap.php`.
- `tools/php56-build/` — Rector rules and syntax validator that downgrade a copy of `vendor/` and `reprint/` to PHP 7.0 syntax so the ZIP keeps `Requires PHP: 7.0`. Copied from `reprint/tools/php56-build`; the name is upstream's, the 7.0 target lives in `rector-php70.php`.
- `bin/build.sh` — writes `build/wpcom-migration/` and `build/wpcom-migration.zip`.
- `bin/check-autoload-manifest.php` — asserts which classes the ZIP publishes through the Jetpack autoloader.
- `tests/smoke/` — Playground blueprints and a signed-request script that exercise the endpoint in each credential state.

## Build

Needs PHP 8.1+, Composer, `rsync`, `zip`.

```sh
composer install                                     # PHPCS + WPCS
composer install --working-dir=tools/php56-build     # Rector
bin/build.sh
```

The build runs `composer install` in `plugin/`, copies the tree to a staging directory, downgrades `vendor/` and `reprint/` there, checks the autoload manifest, then writes `build/`. `plugin/` itself is never rewritten.

To activate a source checkout directly (without a build), run `composer install --no-dev --working-dir=plugin` first; without `plugin/vendor/` the plugin activates but the exporter is absent.

## The export screen

`wp-admin/admin.php?page=wpcom-migration-reprint` (no menu entry; `manage_options`; single-site only). Paste the shared secret WordPress.com hands out, then tick *Enable the exporter*. The window stays open for an hour after the last export request. The remote API URL is `home_url( '?reprint-api-wpcom-migration' )`. Activating or deactivating the plugin discards the stored secret and window; the hooks are registered from `plugin/reprint/bootstrap.php`.

Each state change and every served or refused request fires `wpcom_migration_reprint_export_event` with an event name and context; none carries the secret, a hash or a signature.

## Checks

```sh
composer lint                     # PHPCS: WordPress on plugin/reprint, bin, tests; PSR-12 on tools/php56-build
composer test:build-tool          # Rector fixture tests
composer smoke                    # Playground smoke test against build/wpcom-migration
```

`.github/workflows/build.yml` runs on every push and pull request: build and upload the ZIP; `php -l` the built tree on PHP 7.0, 7.4 and 8.4; PHPCS; the build-tool fixtures; the Playground smoke test against the ZIP.

Publishing to wp.org is not automated.

## Known limitations

See section 7 of `docs/superpowers/specs/2026-09-15-reprint-server-integration-design.md`. In short: running this plugin next to `reprint-server-wp` is unsupported, and sites on placeholder salts get the write veto but not the salt binding.

## Security

Need to report a security vulnerability? Go to [https://automattic.com/security/](https://automattic.com/security/) or directly to our security bug bounty site [https://hackerone.com/automattic](https://hackerone.com/automattic).
