( function () {
	'use strict';

	var config = window.wpcomMigrationProxiedNotice;
	var content = document.querySelector( '.wpcom-migration-content' );
	if ( ! config || ! content || ! window.fetch ) {
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
			if ( ! body || 'reprint' !== body.main_screen ) {
				return;
			}

			var notice = document.createElement( 'div' );
			var paragraph = document.createElement( 'p' );
			var link = document.createElement( 'a' );
			notice.className = 'notice notice-info';
			// Above the heading, without wp-admin's notice margins, in the
			// screen's own colors so it stands out from the white page.
			notice.style.margin = '0 0 24px';
			notice.style.background = 'var( --wpcom-migration-secondary-bg )';
			notice.style.borderLeftColor = 'var( --wpcom-migration-primary-bg )';
			link.href = config.linkUrl;
			link.textContent = config.linkText;
			paragraph.append( config.message + ' ', link );
			notice.append( paragraph );
			content.prepend( notice );
		} )
		.catch( function () {} );
}() );
