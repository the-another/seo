/**
 * Titles & Templates helpers: click a variable pill to insert its token, or
 * type %% for an autocomplete of that row's variables.
 *
 * The variable list is never shipped to this file as data. It is read from
 * the pills the server rendered (their data-taseo-template-var attributes),
 * so there is exactly one serialisation of the registry on the page and the
 * suggestions can never disagree with the pills sitting next to them.
 *
 * Completing a fragment inside a larger value follows core's own
 * wp-admin/js/user-suggest.js, which completes the last entry of a
 * comma-separated field; here the delimiter is %% instead of a comma.
 */
( function ( $ ) {
	'use strict';

	var TOKEN = '%%';

	/**
	 * The template inputs belonging to the same row as an element.
	 *
	 * @param {Element} element Element inside a row.
	 * @return {Array} Inputs.
	 */
	function rowInputs( element ) {
		var row = element.closest( 'tr' );

		return row
			? Array.prototype.slice.call(
					row.querySelectorAll( '[data-taseo-template-input]' )
			  )
			: [];
	}

	/**
	 * The tokens this row's pills offer.
	 *
	 * @param {Element} element Element inside a row.
	 * @return {Array} Tokens, e.g. ['%%title%%'].
	 */
	function rowTokens( element ) {
		var row = element.closest( 'tr' );

		return row
			? Array.prototype.map.call(
					row.querySelectorAll( '[data-taseo-template-var]' ),
					function ( pill ) {
						return pill.getAttribute( 'data-taseo-template-var' );
					}
			  )
			: [];
	}

	/**
	 * Which input a pill click should target: the last one in this row to
	 * have been focused, falling back to the row's first input.
	 *
	 * @param {Element} element Element inside a row.
	 * @return {Element|null} Input.
	 */
	function targetInput( element ) {
		var inputs = rowInputs( element );
		var i;

		for ( i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].dataset.taseoLastFocused === '1' ) {
				return inputs[ i ];
			}
		}

		return inputs.length ? inputs[ 0 ] : null;
	}

	/**
	 * Replace the range [start, end) of an input's value and place the
	 * caret after the inserted text.
	 *
	 * @param {Element} input Input.
	 * @param {number}  start Start offset.
	 * @param {number}  end   End offset.
	 * @param {string}  text  Replacement.
	 * @return {void}
	 */
	function replaceRange( input, start, end, text ) {
		input.value =
			input.value.slice( 0, start ) + text + input.value.slice( end );

		var caret = start + text.length;

		input.focus();
		input.setSelectionRange( caret, caret );
	}

	/**
	 * The open, incomplete token immediately before the caret, if any.
	 *
	 * PHP's TemplateResolver matches only complete %%variable%% pairs (the
	 * same [a-z0-9_]+ character class used below) and leaves anything else
	 * — including a stray, unpaired %% — as literal text (see
	 * TemplateResolver::extract_variables()). This must find the same thing
	 * PHP would leave open: strip every complete %%…%% pair out of the text
	 * before the caret, then check whether what remains still ends in an
	 * unclosed %%.
	 *
	 * Do not go back to counting %% occurrences and checking odd/even
	 * parity. That assumes every %% pairs up strictly left to right, which
	 * breaks the moment an earlier %% in the field is itself unpaired
	 * (e.g. "%%oops %%title%%"): parity flips, "start" ends up anchored on
	 * the delimiter that actually closes %%title%%, and accepting a
	 * suggestion there splices a new token onto the end of an existing one
	 * — %%title%%sep%% — instead of leaving no open token at all, silently
	 * corrupting the saved template.
	 *
	 * @param {Element} input Input.
	 * @return {Object|null} { start, term } or null.
	 */
	function openToken( input ) {
		var before = input.value.slice( 0, input.selectionStart );
		var stripped = before.replace( /%%[a-z0-9_]+%%/gi, '' );

		if ( ! /%%[a-z0-9_]*$/i.test( stripped ) ) {
			return null;
		}

		var start = before.lastIndexOf( TOKEN );

		return {
			start: start,
			term: before.slice( start + TOKEN.length ).toLowerCase(),
		};
	}

	document.addEventListener( 'focusin', function ( event ) {
		var input = event.target.closest( '[data-taseo-template-input]' );

		if ( ! input ) {
			return;
		}

		rowInputs( input ).forEach( function ( other ) {
			delete other.dataset.taseoLastFocused;
		} );

		input.dataset.taseoLastFocused = '1';
	} );

	document.addEventListener( 'click', function ( event ) {
		var pill = event.target.closest( '[data-taseo-template-var]' );

		if ( ! pill ) {
			return;
		}

		var input = targetInput( pill );

		if ( ! input ) {
			return;
		}

		replaceRange(
			input,
			input.selectionStart,
			input.selectionEnd,
			pill.getAttribute( 'data-taseo-template-var' )
		);
	} );

	$( function () {
		$( '[data-taseo-template-input]' ).autocomplete( {
			minLength: 0,
			source: function ( request, response ) {
				var input = this.element[ 0 ];
				var open = openToken( input );

				if ( ! open ) {
					response( [] );
					return;
				}

				response(
					rowTokens( input ).filter( function ( token ) {
						return (
							token
								.slice( TOKEN.length )
								.toLowerCase()
								.indexOf( open.term ) === 0
						);
					} )
				);
			},
			focus: function () {
				// Keep the typed fragment in the field while arrowing the list.
				return false;
			},
			select: function ( event, ui ) {
				var input = this.element[ 0 ];
				var open = openToken( input );

				if ( open ) {
					replaceRange(
						input,
						open.start,
						input.selectionStart,
						ui.item.value
					);
				}

				return false;
			},
		} );
	} );
} )( jQuery );
