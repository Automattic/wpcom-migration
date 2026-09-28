<?php
/**
 * Drives Main_Screen inside Playground, one step per runPHP call, against
 * the endpoint mock in flag-mock.php. Each step throws on a failed
 * expectation.
 *
 * @package wpcom-migration
 */

use Automattic\WPCOM_Migration\Reprint\Main_Screen;

set_exception_handler(
	function ( $exception ) {
		file_put_contents( 'php://stderr', get_class( $exception ) . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}
);

/**
 * Resets the mock and the stored answer.
 *
 * @param string     $response Mock body, or 'fail'.
 * @param array|null $stored   Value to store in Main_Screen::OPTION, or null for none.
 * @param int        $status   Mock HTTP status.
 */
function wpcom_migration_e2e_flag_setup( $response, $stored = null, $status = 200 ) {
	Main_Screen::forget();
	update_option( 'wpcom_migration_e2e_flag_response', $response, false );
	update_option( 'wpcom_migration_e2e_flag_status', $status, false );
	update_option( 'wpcom_migration_e2e_flag_requests', 0, false );
	delete_option( 'wpcom_migration_e2e_flag_last' );
	if ( null !== $stored ) {
		update_option( Main_Screen::OPTION, $stored, false );
	}
}

/**
 * Asserts what is stored and how many requests were made.
 *
 * @param string $main_screen Expected stored main_screen.
 * @param int    $ttl         Expected seconds from $before to expires_at.
 * @param int    $before      time() taken before the call under test.
 * @param int    $requests    Expected request count.
 * @param string $label       Case label for messages.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_flag_assert_stored( $main_screen, $ttl, $before, $requests, $label ) {
	$stored = get_option( Main_Screen::OPTION );
	if ( ! is_array( $stored ) || ! isset( $stored['main_screen'], $stored['expires_at'] ) ) {
		throw new RuntimeException( "$label: nothing stored: " . wp_json_encode( $stored ) );
	}
	if ( $main_screen !== $stored['main_screen'] ) {
		throw new RuntimeException( "$label: stored " . wp_json_encode( $stored['main_screen'] ) . ", expected $main_screen." );
	}
	$delta = (int) $stored['expires_at'] - $before;
	if ( $delta < $ttl || $delta > $ttl + 1 ) {
		throw new RuntimeException( "$label: expires $delta s out, expected $ttl." );
	}
	$seen = (int) get_option( 'wpcom_migration_e2e_flag_requests' );
	if ( $requests !== $seen ) {
		throw new RuntimeException( "$label: $seen requests, expected $requests." );
	}
}

/**
 * Runs one flag step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_flag_step( $step ) {
	$reprint = '{"main_screen":"reprint","ttl":300}';

	switch ( $step ) {
		case 'flag-fetch':
			// Nothing stored: fetches, stores, answers; the second call is memoized.
			wpcom_migration_e2e_flag_setup( $reprint );
			$before = time();
			if ( true !== Main_Screen::is_reprint() || true !== Main_Screen::is_reprint() ) {
				throw new RuntimeException( 'A reprint answer should make is_reprint() true.' );
			}
			wpcom_migration_e2e_flag_assert_stored( 'reprint', 300, $before, 1, 'fetch' );

			$last    = get_option( 'wpcom_migration_e2e_flag_last' );
			$data    = get_file_data( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php', array( 'Version' => 'Version' ) );
			$expects = Main_Screen::ENDPOINT . '?plugin_version=' . rawurlencode( $data['Version'] );
			if ( $expects !== $last['url'] ) {
				throw new RuntimeException( 'Requested ' . $last['url'] . ", expected $expects." );
			}
			if ( 2 !== $last['timeout'] ) {
				throw new RuntimeException( 'The fetch should time out after 2 s, got ' . wp_json_encode( $last['timeout'] ) . '.' );
			}
			break;

		case 'flag-cached':
			// An unexpired answer is used without a request.
			wpcom_migration_e2e_flag_setup(
				$reprint,
				array(
					'main_screen' => 'blogvault',
					'expires_at'  => time() + 100,
				)
			);
			if ( false !== Main_Screen::is_reprint() ) {
				throw new RuntimeException( 'An unexpired blogvault answer should be used as is.' );
			}
			if ( 0 !== (int) get_option( 'wpcom_migration_e2e_flag_requests' ) ) {
				throw new RuntimeException( 'An unexpired answer should not be fetched again.' );
			}
			break;

		case 'flag-expired':
			// An expired answer is fetched again and replaced.
			wpcom_migration_e2e_flag_setup(
				$reprint,
				array(
					'main_screen' => 'blogvault',
					'expires_at'  => time() - 1,
				)
			);
			$before = time();
			if ( true !== Main_Screen::is_reprint() ) {
				throw new RuntimeException( 'An expired blogvault answer should be replaced by the fetched reprint.' );
			}
			wpcom_migration_e2e_flag_assert_stored( 'reprint', 300, $before, 1, 'expired' );
			break;

		case 'flag-ttl':
			$cases = array(
				'{"main_screen":"reprint","ttl":120}'      => 120,
				'{"main_screen":"reprint","ttl":5}'        => 60,
				'{"main_screen":"reprint","ttl":10000000}' => 86400,
				'{"main_screen":"reprint"}'                => 300,
				'{"main_screen":"reprint","ttl":"120"}'    => 300,
				'{"main_screen":"reprint","ttl":1.5}'      => 300,
			);
			foreach ( $cases as $body => $ttl ) {
				wpcom_migration_e2e_flag_setup( $body );
				$before = time();
				Main_Screen::is_reprint();
				wpcom_migration_e2e_flag_assert_stored( 'reprint', $ttl, $before, 1, $body );
			}
			break;

		case 'flag-bad-answers':
			// Every one of these is a failed fetch: nothing stored becomes
			// blogvault for RETRY_AFTER.
			$cases = array(
				array( 'fail', 200 ),
				array( $reprint, 500 ),
				array( $reprint, 404 ),
				array( '<!DOCTYPE html><html><body>Sign in to the network</body></html>', 200 ),
				array( '', 200 ),
				array( 'null', 200 ),
				array( '["reprint"]', 200 ),
				array( '{"ttl":300}', 200 ),
				array( '{"main_screen":"sideways","ttl":300}', 200 ),
				array( '{"main_screen":true,"ttl":300}', 200 ),
			);
			foreach ( $cases as $case ) {
				$label = $case[1] . ' ' . $case[0];
				wpcom_migration_e2e_flag_setup( $case[0], null, $case[1] );
				$before = time();
				if ( false !== Main_Screen::is_reprint() ) {
					throw new RuntimeException( "$label: a failed fetch with nothing stored should be blogvault." );
				}
				wpcom_migration_e2e_flag_assert_stored( 'blogvault', Main_Screen::RETRY_AFTER, $before, 1, $label );
			}
			break;

		case 'flag-failure-keeps':
			// A failed fetch keeps a stored answer and retries later.
			wpcom_migration_e2e_flag_setup(
				'fail',
				array(
					'main_screen' => 'reprint',
					'expires_at'  => time() - 1,
				)
			);
			$before = time();
			if ( true !== Main_Screen::is_reprint() ) {
				throw new RuntimeException( 'A failed fetch should keep the stored reprint answer.' );
			}
			wpcom_migration_e2e_flag_assert_stored( 'reprint', Main_Screen::RETRY_AFTER, $before, 1, 'failure-keeps' );
			break;

		case 'flag-stored-unknown':
			// A value this version doesn't know reads as blogvault, and is
			// still honoured as unexpired.
			wpcom_migration_e2e_flag_setup(
				$reprint,
				array(
					'main_screen' => 'sideways',
					'expires_at'  => time() + 100,
				)
			);
			if ( false !== Main_Screen::is_reprint() ) {
				throw new RuntimeException( 'An unknown stored main_screen should read as blogvault.' );
			}
			foreach ( array( 'garbage', array( 'main_screen' => 'reprint' ) ) as $stored ) {
				wpcom_migration_e2e_flag_setup( '{"main_screen":"blogvault","ttl":300}', null );
				update_option( Main_Screen::OPTION, $stored, false );
				if ( false !== Main_Screen::is_reprint() ) {
					throw new RuntimeException( 'A malformed stored value should be refetched: ' . wp_json_encode( $stored ) );
				}
				if ( 1 !== (int) get_option( 'wpcom_migration_e2e_flag_requests' ) ) {
					throw new RuntimeException( 'A malformed stored value should cause one fetch: ' . wp_json_encode( $stored ) );
				}
			}
			break;

		case 'flag-forget':
			wpcom_migration_e2e_flag_setup( $reprint );
			Main_Screen::is_reprint();
			Main_Screen::forget();
			if ( false !== get_option( Main_Screen::OPTION ) ) {
				throw new RuntimeException( 'forget() should delete the stored answer.' );
			}
			$hook = 'deactivate_' . plugin_basename( WP_PLUGIN_DIR . '/wpcom-migration/wpcom_migration.php' );
			if ( false === has_action( $hook, array( Main_Screen::class, 'forget' ) ) ) {
				throw new RuntimeException( "forget() is not hooked to $hook." );
			}
			break;

		default:
			throw new RuntimeException( 'Unknown flag step: ' . $step );
	}
}
