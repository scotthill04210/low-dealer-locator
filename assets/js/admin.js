( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var input = document.getElementById( 'low-dl-post-type-filter' );
		var list = document.getElementById( 'low-dl-post-type-list' );

		if ( ! input || ! list ) {
			return;
		}

		var items = list.querySelectorAll( '.low-dl-post-type' );

		input.addEventListener( 'input', function () {
			var query = input.value.toLowerCase();
			var index;

			for ( index = 0; index < items.length; index++ ) {
				var item = items[ index ];
				var haystack = ( item.getAttribute( 'data-search' ) || '' ).toLowerCase();
				var matches = '' === query || haystack.indexOf( query ) !== -1;

				if ( matches ) {
					item.removeAttribute( 'hidden' );
				} else {
					item.setAttribute( 'hidden', 'hidden' );
				}
			}
		} );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		var rows = document.querySelectorAll( '.low-dl-mapping-row' );

		if ( ! rows.length ) {
			return;
		}

		var syncRow = function ( row ) {
			var select = row.querySelector( '.low-dl-mapping-source' );

			if ( ! select ) {
				return;
			}

			var source = select.value;
			var controls = row.querySelectorAll( '.low-dl-mapping-control' );
			var index;

			for ( index = 0; index < controls.length; index++ ) {
				var control = controls[ index ];
				var active = control.getAttribute( 'data-source' ) === source;
				var field = control.querySelector( 'input, select' );
				var fieldName = field ? ( field.getAttribute( 'data-name' ) || '' ) : '';

				if ( active ) {
					control.removeAttribute( 'hidden' );
				} else {
					control.setAttribute( 'hidden', 'hidden' );
				}

				if ( ! field ) {
					continue;
				}

				if ( active ) {
					field.disabled = false;

					if ( fieldName ) {
						field.setAttribute( 'name', fieldName );
					}
				} else {
					field.disabled = true;
					field.removeAttribute( 'name' );
				}
			}
		};

		var rowIndex;

		for ( rowIndex = 0; rowIndex < rows.length; rowIndex++ ) {
			( function ( row ) {
				var select = row.querySelector( '.low-dl-mapping-source' );

				if ( ! select ) {
					return;
				}

				select.addEventListener( 'change', function () {
					syncRow( row );
				} );

				syncRow( row );
			}( rows[ rowIndex ] ) );
		}
	} );
}() );
