<?php
/**
 * Answers the main screen flag endpoint and the destination endpoint inside
 * Playground, so no scenario calls public-api.wordpress.com.
 * install-flag-mock.php loads it as a must-use plugin.
 *
 * The answer is {"main_screen":"reprint","ttl":300} with a 200 unless a step
 * sets wpcom_migration_e2e_flag_response (a raw body, or "fail" for a
 * transport error) or wpcom_migration_e2e_flag_status. Every request bumps
 * wpcom_migration_e2e_flag_requests and is recorded in
 * wpcom_migration_e2e_flag_last.
 *
 * The destination endpoint answers the same way through the
 * wpcom_migration_e2e_destination_* options, with an unmatched answer by
 * default.
 *
 * @package wpcom-migration
 */

/**
 * Records a mocked request and builds its response.
 *
 * @param string $prefix       Option prefix, e.g. wpcom_migration_e2e_flag.
 * @param string $default_body Body when the step set none.
 * @param string $url          Requested URL.
 * @param array  $args         Request arguments.
 * @return array|WP_Error
 */
function wpcom_migration_e2e_mock_response( $prefix, $default_body, $url, $args ) {
	update_option( $prefix . '_requests', (int) get_option( $prefix . '_requests', 0 ) + 1, false );
	update_option(
		$prefix . '_last',
		array(
			'url'         => $url,
			'timeout'     => isset( $args['timeout'] ) ? $args['timeout'] : null,
			'redirection' => isset( $args['redirection'] ) ? $args['redirection'] : null,
		),
		false
	);

	$body = get_option( $prefix . '_response', $default_body );
	if ( 'fail' === $body ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 2001 milliseconds' );
	}

	$status = (int) get_option( $prefix . '_status', 200 );

	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => $status,
			'message' => get_status_header_desc( $status ),
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://public-api.wordpress.com/wpcom/v2/migration-plugin/destination' ) ) {
			return $response;
		}

		return wpcom_migration_e2e_mock_response( 'wpcom_migration_e2e_destination', '{"matched":false,"site_slug":null,"url":"https://my.wordpress.com/sites","ttl":60}', $url, $args );
	},
	10,
	3
);

add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		// Without the class (plugin inactive, or not built yet) nothing asks.
		if ( ! class_exists( \Automattic\WPCOM_Migration\Reprint\Main_Screen::class ) || 0 !== strpos( $url, \Automattic\WPCOM_Migration\Reprint\Main_Screen::ENDPOINT ) ) {
			return $response;
		}

		return wpcom_migration_e2e_mock_response( 'wpcom_migration_e2e_flag', '{"main_screen":"reprint","ttl":300}', $url, $args );
	},
	10,
	3
);
