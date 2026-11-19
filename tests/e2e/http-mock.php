<?php
/**
 * Answers the destination endpoint inside Playground, so no scenario calls
 * public-api.wordpress.com. install-http-mock.php loads it as a must-use
 * plugin.
 *
 * The answer is unmatched with a 200 unless a step sets
 * wpcom_migration_e2e_destination_response (a raw body, or "fail" for a
 * transport error) or wpcom_migration_e2e_destination_status. Every request
 * bumps wpcom_migration_e2e_destination_requests and is recorded in
 * wpcom_migration_e2e_destination_last.
 *
 * @package wpcom-migration
 */

/**
 * Records a mocked request and builds its response.
 *
 * @param string $prefix       Option prefix, e.g. wpcom_migration_e2e_destination.
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
