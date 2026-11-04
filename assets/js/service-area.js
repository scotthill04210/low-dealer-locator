( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var roots = document.querySelectorAll( '.low-dl-area' );
		var index;

		for ( index = 0; index < roots.length; index++ ) {
			bindArea( roots[ index ] );
		}
	} );

	function bindArea( root ) {
		var dialog = root.querySelector( 'dialog' );
		var open = root.querySelector( '.low-dl-area-open' );
		var close = root.querySelector( '.low-dl-area-close' );

		if ( ! dialog || ! open ) {
			return;
		}

		if ( typeof dialog.showModal !== 'function' ) {
			dialog.setAttribute( 'open', 'open' );
			open.hidden = true;
			return;
		}

		open.addEventListener( 'click', function () {
			if ( ! dialog.open ) {
				dialog.showModal();
			}
		} );

		if ( close ) {
			close.addEventListener( 'click', function () {
				dialog.close();
			} );
		}

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );

		if ( '1' === root.getAttribute( 'data-open' ) && ! dialog.open ) {
			dialog.showModal();
		}
	}
}() );
