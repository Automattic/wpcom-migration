<?php
/**
 * Thrown when the exporter could not store a credential.
 *
 * @package wpcom-migration
 */

namespace Automattic\WPCOM_Migration\Reprint;

use RuntimeException;

/**
 * The credential was not written, or did not read back as written.
 */
class Credential_Storage_Exception extends RuntimeException {
}
