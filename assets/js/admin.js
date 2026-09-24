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

	document.addEventListener( 'DOMContentLoaded', function () {
		var style = document.getElementById( 'low-dl-marker-style' );
		var colorRow = document.getElementById( 'low-dl-marker-color-row' );
		var imageRow = document.getElementById( 'low-dl-marker-image-row' );
		var imageId = document.getElementById( 'low-dl-marker-image-id' );
		var preview = document.getElementById( 'low-dl-marker-image-preview' );
		var selectButton = document.getElementById( 'low-dl-marker-image-select' );
		var removeButton = document.getElementById( 'low-dl-marker-image-remove' );
		var frame = null;

		if ( ! style || ! colorRow || ! imageRow ) {
			return;
		}

		var syncStyle = function () {
			if ( 'circle' === style.value ) {
				colorRow.removeAttribute( 'hidden' );
			} else {
				colorRow.setAttribute( 'hidden', 'hidden' );
			}

			if ( 'image' === style.value ) {
				imageRow.removeAttribute( 'hidden' );
			} else {
				imageRow.setAttribute( 'hidden', 'hidden' );
			}
		};

		var imageUrl = function ( attachment ) {
			if ( ! attachment ) {
				return '';
			}

			if ( attachment.sizes && attachment.sizes.thumbnail && typeof attachment.sizes.thumbnail.url === 'string' ) {
				return attachment.sizes.thumbnail.url;
			}

			return typeof attachment.url === 'string' ? attachment.url : '';
		};

		var safeUrl = function ( url ) {
			return /^https?:\/\//i.test( url ) ? url : '';
		};

		style.addEventListener( 'change', syncStyle );
		syncStyle();

		if ( selectButton ) {
			selectButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( ! window.wp || ! window.wp.media ) {
					return;
				}

				if ( ! frame ) {
					frame = window.wp.media( {
						title: selectButton.getAttribute( 'data-title' ) || 'Dealer marker',
						button: {
							text: selectButton.getAttribute( 'data-button' ) || 'Use this image'
						},
						library: {
							type: 'image'
						},
						multiple: false
					} );

					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						var url = safeUrl( imageUrl( attachment ) );

						if ( imageId ) {
							imageId.value = attachment.id ? String( attachment.id ) : '0';
						}

						if ( preview && url ) {
							preview.setAttribute( 'src', url );
							preview.removeAttribute( 'hidden' );
						}

						if ( removeButton ) {
							removeButton.removeAttribute( 'hidden' );
						}
					} );
				}

				frame.open();
			} );
		}

		if ( removeButton ) {
			removeButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( imageId ) {
					imageId.value = '0';
				}

				if ( preview ) {
					preview.removeAttribute( 'src' );
					preview.setAttribute( 'hidden', 'hidden' );
				}

				removeButton.setAttribute( 'hidden', 'hidden' );
			} );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		if ( ! target.closest( '#low-dl-missing-coords .notice-dismiss' ) ) {
			return;
		}

		var notice = document.getElementById( 'low-dl-missing-coords' );

		if ( ! notice || notice.getAttribute( 'data-dismissed' ) === '1' ) {
			return;
		}

		notice.setAttribute( 'data-dismissed', '1' );

		var body = new window.FormData();

		body.append( 'action', 'low_dl_dismiss_missing_notice' );
		body.append( 'nonce', notice.getAttribute( 'data-nonce' ) || '' );
		body.append( 'signature', notice.getAttribute( 'data-signature' ) || '' );

		window.fetch( notice.getAttribute( 'data-ajax' ) || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} );
	} );
}() );
