<?php
/**
 * Makes Reprint treat this Playground site as a host without OpenSSL, so the
 * shared-secret path can be exercised where OpenSSL is present.
 *
 * @package wpcom-migration
 */

require_once WP_PLUGIN_DIR . '/wpcom-migration/vendor/wp-php-toolkit/reprint-server/src/class-utils.php';

\WordPress\Reprint\Server\Utils::override_key_auth_required_for_tests( false );
