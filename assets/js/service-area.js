( function () {
	'use strict';

	function bindArea( root ) {
		var dialog = root.querySelector( 'dialog' );
		var open = root.querySelector( '.low-dl-area-open' );
		var close = root.querySelector( '.low-dl-area-close' );

		if ( ! dialog || ! open || '1' === root.getAttribute( 'data-ready' ) ) {
			return;
		}

		root.setAttribute( 'data-ready', '1' );

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

	function scan( scope ) {
		var context = scope && scope.querySelectorAll ? scope : document;
		var roots = context.querySelectorAll( '.low-dl-area:not([data-ready])' );
		var index;

		if ( context.matches && context.matches( '.low-dl-area:not([data-ready])' ) ) {
			bindArea( context );
		}

		for ( index = 0; index < roots.length; index++ ) {
			bindArea( roots[ index ] );
		}
	}

	function start() {
		scan( document );

		if ( ! window.MutationObserver || ! document.body ) {
			return;
		}

		var observer = new MutationObserver( function ( records ) {
			var recordIndex;
			var nodeIndex;

			for ( recordIndex = 0; recordIndex < records.length; recordIndex++ ) {
				var nodes = records[ recordIndex ].addedNodes;

				for ( nodeIndex = 0; nodeIndex < nodes.length; nodeIndex++ ) {
					if ( nodes[ nodeIndex ].nodeType === 1 ) {
						scan( nodes[ nodeIndex ] );
					}
				}
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
