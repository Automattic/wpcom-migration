# Agent notes

## Pull request titles are changelog entries

Each pull request title becomes one line of the plugin's changelog, word for word, under `== Changelog ==` in `plugin/readme.txt` (`bin/release-changelog.sh` collects them). Site owners read that changelog on WordPress.org and in wp-admin.

Write the title for them: say what changes for a site owner, in plain words, as an imperative sentence.

- "Keep the migration working when the site's security keys change", not "Stop binding export credentials to the site's salts"
- "Send the user directly to the WordPress.com dashboard after connecting"

Mention internal details, such as class names, options, query arguments and tests, in the pull request body. Write a change site owners never see, such as tooling or CI, in the same plain style, since it lands in the changelog too.
