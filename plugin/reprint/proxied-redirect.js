( function () {
	'use strict';

	var config = window.wpcomMigrationProxiedRedirect;
	if ( ! config || ! window.fetch ) {
		return;
	}

	// The answer depends on the network the request comes from, so neither
	// cookies nor a cached answer may stand in for it.
	window
		.fetch( config.endpoint, { credentials: 'omit', cache: 'no-store' } )
		.then( function ( response ) {
			return response.ok ? response.json() : null;
		} )
		.then( function ( body ) {
			if ( body && 'reprint' === body.main_screen ) {
				window.location.replace( config.pinUrl );
			}
		} )
		.catch( function () {} );
}() );
