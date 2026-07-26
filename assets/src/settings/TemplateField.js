/**
 * The chip editing surface for one Titles & Templates field.
 *
 * The stored value never changes shape: the server-rendered <input> stays on
 * the page and stays the thing that submits. This component hides it, renders
 * an editable surface over it, and writes the canonical %%token%% text back
 * into it on every change. With this script absent the input is simply
 * visible and editable, which is the whole degradation story.
 *
 * WHY A CONTENTEDITABLE AND NOT block-editor's RichText
 * -----------------------------------------------------
 * RichText renders and types fine outside a block, but @wordpress/components'
 * Autocomplete can never open inside it: block-editor's DEFAULT_BLOCK_EDIT_CONTEXT
 * is { name: '', isSelected: false }, so RichTextWrapper's selection selector
 * short-circuits to isSelected: false, the selectionStart/selectionEnd props
 * stay undefined, and useRichText resets the record's start/end to undefined on
 * every render. Autocomplete gates its popover on `record.start !== undefined`,
 * so the %% menu never appears. Spiked against WordPress 7.0 and confirmed in a
 * browser: RichText fired six onChange events while typing and rendered chips
 * correctly, and the autocomplete popover count stayed at zero. The DOM binding
 * is therefore owned here, with @wordpress/rich-text still owning the model.
 *
 * WHY contentEditable: false AND NOT object: true
 * ------------------------------------------------
 * The chip has to carry a human label, and `object: true` cannot: create()
 * only turns an element into a replacement when it has no text of its own
 * (that is how <img> works), so a labelled `object: true` span is parsed as an
 * ordinary format spanning its label and toHTMLString() then drops the label
 * entirely — verified in the same spike. `contentEditable: false` is the
 * setting core built for this: create() turns the element into an atomic
 * replacement AND preserves its innerHTML, which is exactly a labelled chip.
 */

import { Autocomplete } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	create,
	insert,
	registerFormatType,
	toHTMLString,
} from '@wordpress/rich-text';

import { parseTemplate, serializeSegments } from './template-value';

export const CHIP_FORMAT = 'taseo/template-variable';

/**
 * The character rich-text puts in the text where a replacement sits.
 *
 * @type {string}
 */
const OBJECT_REPLACEMENT_CHARACTER = '\uFFFC';

/**
 * Zero-width non-breaking space, core's own padding character.
 *
 * A contenteditable offers no caret position beside an uneditable child that
 * has no text node next to it, so a template that starts or ends with a chip
 * would be impossible to type in front of or after. rich-text pads its own
 * editable trees with this character for exactly that reason; create() strips
 * it back out (removeReservedCharacters), so it never reaches a stored value.
 *
 * @type {string}
 */
const ZWNBSP = '\uFEFF';

/**
 * The %% that opens a variable, shared with the completer's trigger.
 *
 * @type {string}
 */
const TOKEN_DELIMITER = '%%';

/**
 * wp-admin styles text fields by element selector (input[type="text"] in
 * wp-admin/css/forms.css), which a contenteditable div can never match. These
 * declarations are that rule's own values, applied inline so the surface still
 * reads as a form field without this feature shipping a stylesheet or minting
 * a class that would need one.
 *
 * @type {Object}
 */
const SURFACE_STYLE = {
	boxSizing: 'border-box',
	width: '99%',
	margin: '0 1px',
	padding: '9px 12px',
	minHeight: '40px',
	border: '1px solid #949494',
	borderRadius: '2px',
	boxShadow: '0 0 0 transparent',
	backgroundColor: '#fff',
	color: '#1e1e1e',
	fontSize: '14px',
	lineHeight: '1.42857143',
};

registerFormatType( CHIP_FORMAT, {
	title: __( 'Template variable', 'the-another-seo' ),
	tagName: 'span',
	// Single class only: registerFormatType rejects anything that is not one
	// CSS identifier ("A class name must begin with a letter, followed by any
	// number of hyphens, underscores, letters, or numbers"), so the pair
	// "button button-small" cannot be the match key. Matching on `button`
	// still keeps `button-small` — toFormat() strips only the registered
	// class and carries the rest through as unregistered attributes, so the
	// chip renders with core's full `button button-small` pair either way.
	className: 'button',
	// Makes create() treat the chip as one atomic replacement and keep its
	// label as innerHTML; see the file header.
	contentEditable: false,
	attributes: {
		token: 'data-taseo-token',
		// Carried as a real attribute so the chip is uneditable in our own
		// DOM too, not only inside rich-text's editable tree.
		contentEditable: 'contenteditable',
	},
	edit: () => null,
} );

/**
 * Slug => human label for one row, read from that row's rendered pills.
 *
 * The pills are the only serialisation of the registry on the page; there
 * is deliberately no wp_localize_script copy, because a second copy can
 * drift from what the pills show.
 *
 * @param {Element} input The template input.
 * @return {Object} Slug => label.
 */
export function rowLabels( input ) {
	const row = input.closest( 'tr' );
	const labels = {};

	if ( ! row ) {
		return labels;
	}

	row.querySelectorAll( '[data-taseo-template-var]' ).forEach( ( pill ) => {
		const token = pill.getAttribute( 'data-taseo-template-var' );
		const slug = token.slice( 2, -2 ).toLowerCase();

		labels[ slug ] =
			pill.getAttribute( 'data-taseo-template-label' ) || slug;
	} );

	return labels;
}

/**
 * Escape text for use as HTML, using rich-text's own serialiser.
 *
 * A chip's label is arbitrary translated text and goes into a replacement's
 * innerHTML, which is injected raw.
 *
 * @param {string} text Plain text.
 * @return {string} HTML.
 */
function escapeText( text ) {
	return toHTMLString( { value: create( { text } ) } );
}

/**
 * Build a rich-text value from template segments.
 *
 * Token segments become atomic chip replacements labelled from the row's
 * pills; a slug the row does not offer keeps its own slug as the label and
 * takes core's .form-invalid class, which is what makes an unresolvable
 * variable different from a working one.
 *
 * @param {Array<Object>} segments Segments.
 * @param {Object}        labels   Slug => label.
 * @return {Object} Rich-text value.
 */
export function segmentsToValue( segments, labels ) {
	const formats = [];
	const replacements = [];
	let text = '';

	segments.forEach( ( segment ) => {
		if ( 'token' !== segment.type ) {
			text += segment.value;
			return;
		}

		const label = labels[ segment.slug ];

		replacements[ text.length ] = {
			type: CHIP_FORMAT,
			attributes: {
				token: segment.raw,
				contentEditable: 'false',
			},
			unregisteredAttributes: {
				class: label ? 'button-small' : 'button-small form-invalid',
			},
			innerHTML: escapeText( label || segment.slug ),
		};

		text += OBJECT_REPLACEMENT_CHARACTER;
	} );

	formats.length = text.length;
	replacements.length = text.length;

	return { formats, replacements, text };
}

/**
 * Read template segments back out of a rich-text value.
 *
 * Two characters a contenteditable produces on its own are normalised away
 * here, because a template is plain text and the old plain <input> could
 * never have produced either:
 *
 * - A non-breaking space. Typing a space in a contenteditable inserts U+00A0
 *   rather than U+0020 whenever a plain space would collapse — after a chip,
 *   or at the end of the content — so "%%title%% %%sep%%" typed by hand would
 *   otherwise be stored with two characters that merely look like spaces, and
 *   the resolver would emit them into the page title verbatim.
 * - A zero-width non-breaking space, the padding segmentsToHTML() writes
 *   beside a leading or trailing chip. create() already strips these, so this
 *   only covers one the browser moved into a text node of its own.
 *
 * Newlines are dropped rather than kept for the same reason: these are
 * single-line fields, Enter is suppressed, and the only way one can appear is
 * a browser leaving a <br> behind when the field is emptied — which must
 * serialise to an empty template (the "leave it empty for the default"
 * behaviour), not to "\n".
 *
 * @param {Object} value Rich-text value.
 * @return {Array<Object>} Segments.
 */
export function valueToSegments( value ) {
	const segments = [];
	let text = '';

	const flush = () => {
		if ( '' !== text ) {
			segments.push( { type: 'text', value: text } );
			text = '';
		}
	};

	for ( let index = 0; index < value.text.length; index++ ) {
		const character = value.text[ index ];
		const replacement = value.replacements[ index ];

		if ( OBJECT_REPLACEMENT_CHARACTER === character ) {
			if ( replacement && CHIP_FORMAT === replacement.type ) {
				const raw = replacement.attributes.token;

				flush();
				segments.push( {
					type: 'token',
					slug: raw.slice( 2, -2 ).toLowerCase(),
					raw,
				} );
			}

			continue;
		}

		if (
			'\n' === character ||
			'\r' === character ||
			ZWNBSP === character
		) {
			continue;
		}

		text += '\u00A0' === character ? ' ' : character;
	}

	flush();

	return segments;
}

/**
 * The editable HTML for a set of segments.
 *
 * @param {Array<Object>} segments Segments.
 * @param {Object}        labels   Slug => label.
 * @return {string} HTML.
 */
function segmentsToHTML( segments, labels ) {
	const html = toHTMLString( { value: segmentsToValue( segments, labels ) } );
	const first = segments[ 0 ];
	const last = segments[ segments.length - 1 ];
	const prefix = first && 'token' === first.type ? ZWNBSP : '';
	const suffix = last && 'token' === last.type ? ZWNBSP : '';

	return prefix + html + suffix;
}

/**
 * Put the caret at a rich-text offset inside the surface.
 *
 * Offsets are counted the way rich-text counts them: a chip is one character,
 * and the padding characters written by segmentsToHTML() are not characters at
 * all (create() removes them), so they are skipped here too.
 *
 * @param {Element} element The surface.
 * @param {number}  offset  Rich-text offset.
 * @return {void}
 */
function placeCaret( element, offset ) {
	const { ownerDocument } = element;
	const range = ownerDocument.createRange();
	const nodes = Array.from( element.childNodes );
	let remaining = 'number' === typeof offset ? offset : Infinity;
	let placed = false;

	for ( const node of nodes ) {
		if ( node.nodeType === node.TEXT_NODE ) {
			let position = 0;

			while ( position < node.data.length && 0 !== remaining ) {
				if ( ZWNBSP !== node.data[ position ] ) {
					remaining -= 1;
				}

				position += 1;
			}

			if ( 0 === remaining ) {
				range.setStart( node, position );
				placed = true;
				break;
			}

			continue;
		}

		if ( 0 === remaining ) {
			range.setStartBefore( node );
			placed = true;
			break;
		}

		remaining -= 1;
	}

	if ( ! placed ) {
		range.selectNodeContents( element );
		range.collapse( false );
	}

	range.collapse( true );

	const selection = ownerDocument.defaultView.getSelection();

	selection.removeAllRanges();
	selection.addRange( range );
	element.focus();
}

/**
 * Whether a pill click in this row belongs to this field: the last input in
 * the row to have been focused, falling back to the row's first input.
 *
 * @param {Element} input The template input.
 * @return {boolean} True when this field owns the row's pill clicks.
 */
function ownsRowPills( input ) {
	const row = input.closest( 'tr' );

	if ( ! row ) {
		return false;
	}

	const inputs = Array.from(
		row.querySelectorAll( '[data-taseo-template-input]' )
	);
	const lastFocused = inputs.find(
		( other ) => '1' === other.dataset.taseoLastFocused
	);

	return ( lastFocused || inputs[ 0 ] ) === input;
}

/**
 * The accessible name for the surface, taken from the input's own label.
 *
 * @param {Element} input The template input.
 * @return {string} Label text.
 */
function inputLabel( input ) {
	const label = input.id
		? input.ownerDocument.querySelector( `label[for="${ input.id }"]` )
		: null;

	return label ? label.textContent.trim() : '';
}

/**
 * The chip surface for one template input.
 *
 * @param {Object}  props       Props.
 * @param {Element} props.input The template input this surface stands for.
 * @return {JSX.Element} Element.
 */
export default function TemplateField( { input } ) {
	const labels = useMemo( () => rowLabels( input ), [ input ] );
	const label = useMemo( () => inputLabel( input ), [ input ] );
	const surface = useRef( null );

	// Read once: from here on the surface owns its DOM, and the input is a
	// write target rather than a source. Re-reading it would fight the caret.
	const initialHTML = useMemo(
		() => segmentsToHTML( parseTemplate( input.value ), labels ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);

	const [ record, setRecord ] = useState( () =>
		create( { html: initialHTML } )
	);
	const [ isFocused, setIsFocused ] = useState( false );

	// Hiding here rather than at mount time is deliberate: an effect only runs
	// after this component has actually rendered, so a surface that failed to
	// render can never leave the administrator with a hidden input and nothing
	// to type into. Hidden inputs still submit, so the server-rendered field
	// stays the one source of truth and the surface is a view over it.
	useEffect( () => {
		input.hidden = true;

		return () => {
			input.hidden = false;
		};
	}, [ input ] );

	/**
	 * The value currently in the surface, with the caret's offsets.
	 *
	 * @return {Object} Rich-text value.
	 */
	function readSurface() {
		const { ownerDocument } = surface.current;
		const selection = ownerDocument.defaultView.getSelection();
		const range =
			selection && selection.rangeCount
				? selection.getRangeAt( 0 )
				: null;

		return create( { element: surface.current, range } );
	}

	/**
	 * Record a value and write its template text into the input.
	 *
	 * A complete %%variable%% typed by hand and sitting immediately before the
	 * caret is turned into its chip on the spot, so a hand-typed variable ends
	 * up as the same atomic thing a pill click or the autocomplete produces
	 * rather than staying raw text until the next page load. The pattern is
	 * TemplateResolver's own, anchored at the caret.
	 *
	 * @param {Object} value Rich-text value.
	 * @return {void}
	 */
	function store( value ) {
		const caret = value.start;
		const typed =
			'number' === typeof caret
				? /%%([a-z0-9_]+)%%$/i.exec( value.text.slice( 0, caret ) )
				: null;

		if ( typed ) {
			applyValue(
				insert(
					value,
					segmentsToValue(
						[
							{
								type: 'token',
								slug: typed[ 1 ].toLowerCase(),
								raw: typed[ 0 ],
							},
						],
						labels
					),
					caret - typed[ 0 ].length,
					caret
				)
			);

			return;
		}

		setRecord( value );
		input.value = serializeSegments( valueToSegments( value ) );
	}

	/**
	 * Adopt a value the surface did not produce itself: rewrite its DOM from
	 * that value and put the caret back where the value says it is.
	 *
	 * @param {Object} value Rich-text value.
	 * @return {void}
	 */
	function applyValue( value ) {
		const segments = valueToSegments( value );

		surface.current.innerHTML = segmentsToHTML( segments, labels );
		setRecord( value );
		input.value = serializeSegments( segments );
		placeCaret( surface.current, value.start );
	}

	/**
	 * Splice a value in at the caret.
	 *
	 * The offsets are passed explicitly rather than left to insert()'s own
	 * defaults: those default to value.start/value.end, and a field that has
	 * never been focused has neither, which slice()s the whole text on both
	 * sides and would duplicate it.
	 *
	 * @param {Object} valueToInsert Rich-text value to insert.
	 * @return {void}
	 */
	function insertAtCaret( valueToInsert ) {
		const start = record.start ?? record.text.length;
		const end = record.end ?? start;

		applyValue( insert( record, valueToInsert, start, end ) );
	}

	// Clicking one of this row's variable pills inserts that variable, the
	// same targeting rule the field has always used: the last input in the row
	// to have been focused, or the row's first input when none has been.
	useEffect( () => {
		const row = input.closest( 'tr' );

		if ( ! row ) {
			return undefined;
		}

		const onClick = ( event ) => {
			const pill = event.target.closest( '[data-taseo-template-var]' );

			if ( ! pill || ! ownsRowPills( input ) ) {
				return;
			}

			const raw = pill.getAttribute( 'data-taseo-template-var' );

			insertAtCaret(
				segmentsToValue(
					[
						{
							type: 'token',
							slug: raw.slice( 2, -2 ).toLowerCase(),
							raw,
						},
					],
					labels
				)
			);
		};

		row.addEventListener( 'click', onClick );

		return () => row.removeEventListener( 'click', onClick );
	} );

	const completer = useMemo(
		() => ( {
			name: 'taseo/template-variables',
			triggerPrefix: TOKEN_DELIMITER,
			options: () =>
				Object.keys( labels ).map( ( slug ) => ( {
					slug,
					label: labels[ slug ],
					token: `${ TOKEN_DELIMITER }${ slug }${ TOKEN_DELIMITER }`,
				} ) ),
			getOptionKeywords: ( option ) => [ option.slug ],
			getOptionLabel: ( option ) => option.token,
			getOptionCompletion: ( option ) => (
				<span
					className="button button-small"
					contentEditable={ false }
					data-taseo-token={ option.token }
				>
					{ option.label }
				</span>
			),
		} ),
		[ labels ]
	);

	return (
		<Autocomplete
			record={ record }
			onChange={ applyValue }
			completers={ [ completer ] }
			contentRef={ surface }
			isSelected={ isFocused }
		>
			{ ( { listBoxId, activeId, onKeyDown } ) => (
				<div
					ref={ surface }
					role="textbox"
					tabIndex={ 0 }
					contentEditable
					suppressContentEditableWarning
					aria-multiline="false"
					aria-label={ label }
					aria-autocomplete={ listBoxId ? 'list' : undefined }
					aria-owns={ listBoxId }
					aria-activedescendant={ activeId }
					data-taseo-template-surface={ input.name }
					style={ SURFACE_STYLE }
					onKeyDown={ ( event ) => {
						onKeyDown( event );

						// These are one-line fields; a newline could only
						// ever reach the stored template as noise.
						if (
							! event.defaultPrevented &&
							'Enter' === event.key
						) {
							event.preventDefault();
						}
					} }
					onInput={ () => store( readSurface() ) }
					onKeyUp={ () => store( readSurface() ) }
					onMouseUp={ () => store( readSurface() ) }
					onPaste={ ( event ) => {
						// Paste plain text only: anything else would put
						// markup this field has no meaning for into the
						// surface, and the stored template is plain text.
						event.preventDefault();

						const text = event.clipboardData
							.getData( 'text/plain' )
							.replace( /[\r\n]+/g, ' ' );
						const current = readSurface();
						const start = current.start ?? current.text.length;

						// Pasted text goes through the same parser as a
						// stored template, so pasting one lands its variables
						// as chips rather than as text that only becomes
						// chips on the next page load.
						applyValue(
							insert(
								current,
								segmentsToValue(
									parseTemplate( text ),
									labels
								),
								start,
								current.end ?? start
							)
						);
					} }
					onFocus={ () => {
						const row = input.closest( 'tr' );

						if ( row ) {
							row.querySelectorAll(
								'[data-taseo-template-input]'
							).forEach( ( other ) => {
								delete other.dataset.taseoLastFocused;
							} );
						}

						input.dataset.taseoLastFocused = '1';
						setIsFocused( true );
						store( readSurface() );
					} }
					onBlur={ () => setIsFocused( false ) }
					dangerouslySetInnerHTML={ { __html: initialHTML } }
				/>
			) }
		</Autocomplete>
	);
}
