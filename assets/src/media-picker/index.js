/**
 * Binds core's media modal to every image field on the page.
 *
 * The hidden input is the source of truth: the modal writes an attachment ID
 * into it and the form submits exactly what it always submitted. With this
 * script absent the input still holds and submits its stored value, so the
 * field degrades to a plain (if unhelpful) control rather than losing data.
 */

const FIELD = '[data-taseo-image-field]';

/**
 * Open the media modal for one field.
 *
 * @param {Element} field The field wrapper.
 */
function openPicker( field ) {
	const input = field.querySelector( '[data-taseo-image-id]' );

	if ( ! input || ! window.wp || ! window.wp.media ) {
		return;
	}

	const frame = window.wp.media( {
		title: field.dataset.taseoImageTitle || '',
		button: { text: field.dataset.taseoImageButton || '' },
		library: { type: 'image' },
		multiple: false,
	} );

	frame.on( 'select', () => {
		const attachment = frame.state().get( 'selection' ).first().toJSON();

		input.value = attachment.id;
		setPreview( field, previewUrl( attachment ) );
	} );

	frame.open();
}

/**
 * Smallest usable preview URL for an attachment.
 *
 * @param {Object} attachment The selected attachment.
 * @return {string} URL, or '' when the attachment has none.
 */
function previewUrl( attachment ) {
	if ( attachment.sizes && attachment.sizes.thumbnail ) {
		return attachment.sizes.thumbnail.url;
	}

	return attachment.url || '';
}

/**
 * Show, replace, or drop a field's preview image.
 *
 * @param {Element} field The field wrapper.
 * @param {string}  url   Preview URL, or '' to remove it.
 */
function setPreview( field, url ) {
	let img = field.querySelector( '[data-taseo-image-preview]' );

	if ( '' === url ) {
		if ( img ) {
			img.remove();
		}

		return;
	}

	if ( ! img ) {
		img = document.createElement( 'img' );
		img.width = 80;
		img.height = 80;
		img.alt = '';
		img.setAttribute( 'data-taseo-image-preview', '' );
		field.insertBefore(
			img,
			field.querySelector( '[data-taseo-image-select]' )
		);
	}

	img.src = url;
}

// One delegated listener rather than one per field: the term edit screen adds
// rows without reloading, and metaboxes can be reordered after load.
document.addEventListener( 'click', ( event ) => {
	const select = event.target.closest(
		`${ FIELD } [data-taseo-image-select]`
	);

	if ( select ) {
		event.preventDefault();
		openPicker( select.closest( FIELD ) );

		return;
	}

	const remove = event.target.closest(
		`${ FIELD } [data-taseo-image-remove]`
	);

	if ( remove ) {
		event.preventDefault();

		const field = remove.closest( FIELD );
		const input = field.querySelector( '[data-taseo-image-id]' );

		if ( input ) {
			input.value = '0';
		}

		setPreview( field, '' );
	}
} );
