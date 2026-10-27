# Agent notes

## Where code goes

Put new code in `plugin/reprint/` or `plugin/connection/`. The rest of `plugin/` is upstream BlogVault code that the linters skip, so change it only as far as a fix needs.

Code in `plugin/reprint/`, `plugin/connection/`, `bin/` and `tests/` runs on PHP 7.4, the floor of the Jetpack packages. `composer lint:php:compat` checks it.

## Testing

`composer test:e2e` boots the built plugin in `build/wpcom-migration`, not the source, so run `bin/build.sh` after each change and before the tests. `E2E_SCENARIOS="connection" tests/e2e/run.sh build/wpcom-migration` runs a single scenario. `tests/e2e/run.sh` lists their names.

## Pull request titles are changelog entries

Each pull request title becomes one line of the plugin's changelog, word for word, under `== Changelog ==` in `plugin/readme.txt` (`bin/release-changelog.sh` collects them). Site owners read that changelog on WordPress.org and in wp-admin.

Write the title for them: say what changes for a site owner, in plain words, as an imperative sentence.

- "Keep the migration working when the site's security keys change", not "Stop binding export credentials to the site's salts"
- "Send the user directly to the WordPress.com dashboard after connecting"

Mention internal details, such as class names, options, query arguments and tests, in the pull request body. Write a change site owners never see, such as tooling or CI, in the same plain style, since it lands in the changelog too.
