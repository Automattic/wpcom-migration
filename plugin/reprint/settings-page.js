( function () {
	'use strict';

	var secret = document.getElementById( 'wpcom-migration-reprint-secret' );
	var toggle = document.querySelector( '.wpcom-migration-reprint-toggle-secret' );
	if ( secret && toggle ) {
		toggle.addEventListener( 'click', function () {
			var showing = secret.type === 'text';
			secret.type = showing ? 'password' : 'text';
			toggle.setAttribute( 'aria-pressed', showing ? 'false' : 'true' );
			toggle.setAttribute(
				'aria-label',
				showing ? toggle.dataset.showLabel : toggle.dataset.hideLabel
			);
		} );
	}

	var apiUrl = document.getElementById( 'wpcom-migration-reprint-api-url' );
	var copy = document.querySelector( '.wpcom-migration-reprint-copy-url' );
	if ( apiUrl && copy ) {
		copy.addEventListener( 'click', function () {
			var copied;
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				copied = navigator.clipboard.writeText( apiUrl.value );
			} else {
				apiUrl.select();
				copied = Promise.resolve( document.execCommand( 'copy' ) );
			}

			copied.then( function () {
				if ( window.wp && wp.a11y ) {
					wp.a11y.speak( copy.dataset.copiedMessage );
				}
			} );
		} );
	}
}() );
