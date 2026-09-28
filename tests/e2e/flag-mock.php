<?php
/**
 * Answers the main screen flag endpoint inside Playground, so no scenario
 * calls public-api.wordpress.com. install-flag-mock.php loads it as a
 * must-use plugin.
 *
 * The answer is {"main_screen":"reprint","ttl":300} with a 200 unless a step
 * sets wpcom_migration_e2e_flag_response (a raw body, or "fail" for a
 * transport error) or wpcom_migration_e2e_flag_status. Every request bumps
 * wpcom_migration_e2e_flag_requests and is recorded in
 * wpcom_migration_e2e_flag_last.
 *
 * @package wpcom-migration
 */

add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		// Without the class (plugin inactive, or not built yet) nothing asks.
		if ( ! class_exists( \Automattic\WPCOM_Migration\Reprint\Main_Screen::class ) || 0 !== strpos( $url, \Automattic\WPCOM_Migration\Reprint\Main_Screen::ENDPOINT ) ) {
			return $response;
		}

		update_option( 'wpcom_migration_e2e_flag_requests', (int) get_option( 'wpcom_migration_e2e_flag_requests', 0 ) + 1, false );
		update_option(
			'wpcom_migration_e2e_flag_last',
			array(
				'url'     => $url,
				'timeout' => isset( $args['timeout'] ) ? $args['timeout'] : null,
			),
			false
		);

		$body = get_option( 'wpcom_migration_e2e_flag_response', '{"main_screen":"reprint","ttl":300}' );
		if ( 'fail' === $body ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 2001 milliseconds' );
		}

		$status = (int) get_option( 'wpcom_migration_e2e_flag_status', 200 );

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
	},
	10,
	3
);
