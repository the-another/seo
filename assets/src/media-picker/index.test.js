/**
 * Regression coverage for the media picker script. Finding 1 of the final
 * review slipped through five green CI jobs with zero coverage on this
 * file: wp.media() was called with an explicit `title`/`button.text` of
 * '', which permanently wins over core's own (translated) defaults because
 * Underscore's _.defaults() only fills in a genuinely undefined property,
 * never an empty string. The result was a media modal with a blank heading
 * and a blank primary button on all four image fields.
 *
 * index.js has no exports — production code binds a single delegated click
 * listener as a side effect of being loaded — so these tests drive it the
 * same way a browser does: build the markup ImageField::render() emits,
 * dispatch real clicks, and assert on the DOM and on what was passed to a
 * stubbed window.wp.media().
 */

import './index.js';

/**
 * Build one field's markup, matching ImageField::render(), and attach it to
 * the document.
 *
 * @param {Object}  options
 * @param {number}  [options.id]      Initial attachment ID.
 * @param {boolean} [options.preview] Whether to seed an existing preview image.
 * @return {Element} The field wrapper, already in the document.
 */
function createField( { id = 0, preview = false } = {} ) {
	const field = document.createElement( 'div' );
	field.setAttribute( 'data-taseo-image-field', '' );

	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.setAttribute( 'data-taseo-image-id', '' );
	input.value = String( id );
	field.appendChild( input );

	if ( preview ) {
		const img = document.createElement( 'img' );
		img.setAttribute( 'data-taseo-image-preview', '' );
		img.src = 'https://example.com/existing.jpg';
		field.appendChild( img );
	}

	const select = document.createElement( 'button' );
	select.type = 'button';
	select.setAttribute( 'data-taseo-image-select', '' );
	select.textContent = 'Select image';
	field.appendChild( select );

	const remove = document.createElement( 'button' );
	remove.type = 'button';
	remove.setAttribute( 'data-taseo-image-remove', '' );
	remove.textContent = 'Remove';
	field.appendChild( remove );

	document.body.appendChild( field );

	return field;
}

/**
 * A wp.media() stand-in whose 'select' handler can be fired manually,
 * standing in for a person picking an attachment in the real modal.
 *
 * @param {Object} attachment The attachment JSON the fake selection resolves to.
 * @return {{frame: Object, triggerSelect: Function}} The fake frame and a way to fire its selection.
 */
function fakeFrame( attachment ) {
	let selectHandler = null;

	const frame = {
		on: jest.fn( ( event, handler ) => {
			if ( 'select' === event ) {
				selectHandler = handler;
			}
		} ),
		open: jest.fn(),
		state: jest.fn( () => ( {
			get: () => ( {
				first: () => ( {
					toJSON: () => attachment,
				} ),
			} ),
		} ) ),
	};

	return {
		frame,
		triggerSelect: () => selectHandler(),
	};
}

describe( 'media picker', () => {
	beforeEach( () => {
		window.wp = { media: jest.fn() };
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		delete window.wp;
	} );

	it( 'does not pass a title or a button to wp.media(), so core supplies its own translated defaults', () => {
		const { frame } = fakeFrame( { id: 1 } );
		window.wp.media.mockReturnValue( frame );

		createField().querySelector( '[data-taseo-image-select]' ).click();

		expect( window.wp.media ).toHaveBeenCalledTimes( 1 );

		const options = window.wp.media.mock.calls[ 0 ][ 0 ];

		expect( options ).not.toHaveProperty( 'title' );
		expect( options ).not.toHaveProperty( 'button' );
		expect( options ).toEqual( {
			library: { type: 'image' },
			multiple: false,
		} );
	} );

	it( 'opens the frame', () => {
		const { frame } = fakeFrame( { id: 1 } );
		window.wp.media.mockReturnValue( frame );

		createField().querySelector( '[data-taseo-image-select]' ).click();

		expect( frame.open ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'selecting an attachment writes its id and shows a thumbnail preview', () => {
		const { frame, triggerSelect } = fakeFrame( {
			id: 77,
			sizes: { thumbnail: { url: 'https://example.com/thumb.jpg' } },
			url: 'https://example.com/full.jpg',
		} );
		window.wp.media.mockReturnValue( frame );

		const field = createField();
		field.querySelector( '[data-taseo-image-select]' ).click();
		triggerSelect();

		expect( field.querySelector( '[data-taseo-image-id]' ).value ).toBe(
			'77'
		);

		const preview = field.querySelector( '[data-taseo-image-preview]' );

		expect( preview ).not.toBeNull();
		expect( preview.src ).toBe( 'https://example.com/thumb.jpg' );
	} );

	it( 'falls back to the full url when the attachment has no thumbnail size', () => {
		const { frame, triggerSelect } = fakeFrame( {
			id: 78,
			url: 'https://example.com/full-only.jpg',
		} );
		window.wp.media.mockReturnValue( frame );

		const field = createField();
		field.querySelector( '[data-taseo-image-select]' ).click();
		triggerSelect();

		expect(
			field.querySelector( '[data-taseo-image-preview]' ).src
		).toBe( 'https://example.com/full-only.jpg' );
	} );

	it( 'leaves no preview when the attachment has neither a thumbnail size nor a url', () => {
		const { frame, triggerSelect } = fakeFrame( { id: 79 } );
		window.wp.media.mockReturnValue( frame );

		const field = createField();
		field.querySelector( '[data-taseo-image-select]' ).click();
		triggerSelect();

		expect(
			field.querySelector( '[data-taseo-image-preview]' )
		).toBeNull();
	} );

	it( 'replaces an existing preview rather than adding a second one', () => {
		const { frame, triggerSelect } = fakeFrame( {
			id: 80,
			sizes: { thumbnail: { url: 'https://example.com/new-thumb.jpg' } },
		} );
		window.wp.media.mockReturnValue( frame );

		const field = createField( { id: 42, preview: true } );
		field.querySelector( '[data-taseo-image-select]' ).click();
		triggerSelect();

		const previews = field.querySelectorAll(
			'[data-taseo-image-preview]'
		);

		expect( previews ).toHaveLength( 1 );
		expect( previews[ 0 ].src ).toBe(
			'https://example.com/new-thumb.jpg'
		);
	} );

	it( 'clicking Remove clears the id to 0 and removes the preview', () => {
		const field = createField( { id: 42, preview: true } );

		field.querySelector( '[data-taseo-image-remove]' ).click();

		expect( field.querySelector( '[data-taseo-image-id]' ).value ).toBe(
			'0'
		);
		expect(
			field.querySelector( '[data-taseo-image-preview]' )
		).toBeNull();
		expect( window.wp.media ).not.toHaveBeenCalled();
	} );

	it( 'degrades to a no-op when wp.media is unavailable, leaving the stored value untouched', () => {
		delete window.wp;

		const field = createField( { id: 5 } );

		expect( () =>
			field.querySelector( '[data-taseo-image-select]' ).click()
		).not.toThrow();
		expect( field.querySelector( '[data-taseo-image-id]' ).value ).toBe(
			'5'
		);
	} );
} );
