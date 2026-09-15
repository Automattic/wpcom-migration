<?php
/**
 * Rector configuration for the build.
 *
 * The build (bin/build.sh) runs this over a staged copy of vendor/ and reprint/ so the
 * ZIP runs on PHP 7.1, the plugin's floor. Rector's own downgrade sets do
 * the rewriting; the source tree is never touched.
 *
 * @package wpcom-migration
 */

use Rector\Config\RectorConfig;

return RectorConfig::configure()
	->withoutParallel()
	->withDowngradeSets( php71: true );
