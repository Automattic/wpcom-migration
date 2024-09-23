# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 3.0.0-alpha - unreleased

This is an alpha version! The changes listed here are not final.

### Added
- Add Woocommerce event remove_order_items to Jetpack Sync
- New setting in /sties/$site/settings that is not relevant to this plugin.
- Packages: add version tracking for identity-crisis package.
- React 19 compatibility: Making sure useRef includes an argument.
- Real time backups: Add endpoints orders to be used in real-time backups jetpack
- Trigger red bubble notification when bad install is detected

### Changed
- Connection: Update connection ToS messaging slightly
- General: indicate compatibility with the upcoming version of WordPress, 6.5.
- General: indicate compatibility with the upcoming version of WordPress - 6.6.
- General: update WordPress version requirements to WordPress 6.4.
- General: use wp_admin_notice function introduced in WP 6.4 to display notices.
- Only show installation errors on plugins page
- Remove explicit Plugin Install package dependency.
- Remove the 'jetpack-identity-crisis' dependency.
- Update composer lock file
- Update package lock

### Removed
- Connection: Removed deprecated method features_available
- General: update WordPress version requirements to WordPress 6.5.

### Fixed
- Backup: change some error messages to not trigger security scanners
- Lossless image optimization of images in projects/plugins [subdirectories from a* through social]
- Remove unnecessary trailing space from i18n message.
- Revert recent SVG image optimizations.
- Updated package dependencies.
- Write helper script to ABSPATH by default, just like we did before

## 2.0.0 - 2024-02-07
### Changed
- General: updated PHP requirement to PHP 7.0+ [#34126]
- General: update WordPress version requirements to WordPress 6.3. [#34127]
- Updated package dependencies.

### Fixed
- Backup: add namespace versioning to Helper_Script_Manager and other classes. [#34739]
- Improved helper script installer logging. [#34297]

## 1.0.1 - 2023-11-03
### Added
- Updated composer.lock. [#31978]

### Changed
- General: Indicate full compatibility with the latest version of WordPress, 6.4. [#33776]
- General: Remove WP 6.1 backwards compatibility checks. [#32772]
- Updated package dependencies. [#33826]
- Update lockfile. [#33607]
- Use the new method to render Connection initial state. [#32499]

## 1.0.0 - 2023-06-15
### Added
- Adding Jetpack Backup package in the plugin [#30480]
- Add video section to backup connect page [#31260]

### Changed
- General: indicate full compatibility with the latest version of WordPress, 6.2. [#29341]
- Remove conditional rendering from zendesk chat widget component due to it being handled by an api endpoint now [#29942]
- Update connection module to have an RNA option that updates the design [#31201]
- Update WordPress version requirements. Now requires version 6.1. [#30120]

## 0.1.0 - 2023-03-01
### Added
- Add migration progress screen and integrate into admin page [#28807]
- Adds a REST route for Migration plugin to fetch status from source site. [#28546]
- Add track events logic for "Get started" and "Check your migration progress" buttons [#28974]
- Create Migration component [#28770]
- General: enable automated plugin releases. [#29014]
- Introduce Jetpack Migration plugin structure [#28209]
- Rename Jetpack Migration to Move to WordPress.com and wpcom-migration [#28936]
- Update readme.txt for migration plugin [#29040]

### Changed
- Adds a top-level menu entry for Move to WordPress.com [#28463]
- Change initial screen wording with new copy [#28993]
- Update doc link for migration plugin [#29129]
- Updated package dependencies. [#28440]
- Updated package dependencies. [#28682]
- Updated package dependencies. [#28910]
- Updated package dependencies. [#28927]
- Update to React 18. [#28710]

### Fixed
- Fix styles for mobile screens [#29081]
