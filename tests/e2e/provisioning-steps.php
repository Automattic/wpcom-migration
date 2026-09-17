<?php
/**
 * Prepares a Playground site for the provisioning scenario: application
 * passwords with values request.php knows, for an administrator and for an
 * editor.
 *
 * Core generates the password through wp_generate_password(), which applies
 * the `random_password` filter; the filter pins the value for one call.
 *
 * @package wpcom-migration
 */

/**
 * The administrator's pinned application password.
 *
 * @var string
 */
const WPCOM_MIGRATION_E2E_ADMIN_APP_PASSWORD = 'e2eAdminAppPassword00001';

/**
 * The editor's pinned application password.
 *
 * @var string
 */
const WPCOM_MIGRATION_E2E_EDITOR_APP_PASSWORD = 'e2eEditorAppPassword0001';

/**
 * Runs one provisioning step.
 *
 * @param string $step Step name.
 * @throws RuntimeException When an expectation fails.
 */
function wpcom_migration_e2e_provisioning_step( $step ) {
	switch ( $step ) {
		case 'create-application-passwords':
			if ( ! wp_is_application_passwords_available() ) {
				throw new RuntimeException( 'Application passwords are unavailable; the blueprint must define WP_ENVIRONMENT_TYPE=local.' );
			}

			wpcom_migration_e2e_create_application_password( 1, WPCOM_MIGRATION_E2E_ADMIN_APP_PASSWORD );

			$editor_id = wp_insert_user(
				array(
					'user_login' => 'e2e-editor',
					'user_pass'  => wp_generate_password( 24, false ),
					'role'       => 'editor',
				)
			);
			if ( is_wp_error( $editor_id ) ) {
				throw new RuntimeException( 'Could not create the editor: ' . $editor_id->get_error_message() );
			}
			wpcom_migration_e2e_create_application_password( $editor_id, WPCOM_MIGRATION_E2E_EDITOR_APP_PASSWORD );
			break;

		default:
			throw new RuntimeException( 'Unknown provisioning step: ' . $step );
	}
}

/**
 * Creates an application password with a known value.
 *
 * @param int    $user_id  The user.
 * @param string $password The value to pin.
 * @throws RuntimeException When creation fails or the value was not pinned.
 */
function wpcom_migration_e2e_create_application_password( $user_id, $password ) {
	$pin = function () use ( $password ) {
		return $password;
	};

	add_filter( 'random_password', $pin );
	$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'e2e' ) );
	remove_filter( 'random_password', $pin );

	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( 'Could not create the application password: ' . $created->get_error_message() );
	}
	if ( $password !== $created[0] ) {
		throw new RuntimeException( 'The application password was not pinned to the known value.' );
	}
}
