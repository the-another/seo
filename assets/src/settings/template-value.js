/**
 * Convert between a stored template string and an ordered list of segments.
 *
 * The pattern matches PHP's TemplateResolver::TOKEN_PATTERN — that constant
 * is the single definition of what a token looks like on the server, and
 * this is its client-side mirror. If one changes, so must the other, or a
 * template could validate one way and render another.
 */
const TOKEN_PATTERN = /%%([a-z0-9_]+)%%/gi;

/**
 * Split a template into text and token segments, in order.
 *
 * Each token segment keeps both a lowercased `slug`, for looking a label
 * up, and the `raw` token exactly as written. The raw form is what makes
 * the round trip exact: %%TITLE%% is a legitimate stored value, and
 * re-serialising from the slug alone would rewrite it to %%title%% —
 * silently editing what an administrator stored, just because they opened
 * the tab.
 *
 * @param {string} template Stored template.
 * @return {Array<Object>} Segments.
 */
export function parseTemplate( template ) {
	const segments = [];
	let lastIndex = 0;
	let match;

	// Not load-bearing today: exec() with the g flag already resets
	// lastIndex to 0 whenever it returns null, and null is the only way
	// this loop exits, so a leftover lastIndex from a prior call can never
	// reach this point. Kept as a guard against a future refactor that
	// exits the loop some other way (e.g. an early `break`), which would
	// otherwise leave lastIndex stranded mid-string for the next call.
	TOKEN_PATTERN.lastIndex = 0;

	while ( ( match = TOKEN_PATTERN.exec( template ) ) !== null ) {
		if ( match.index > lastIndex ) {
			segments.push( {
				type: 'text',
				value: template.slice( lastIndex, match.index ),
			} );
		}

		segments.push( {
			type: 'token',
			slug: match[ 1 ].toLowerCase(),
			raw: match[ 0 ],
		} );

		lastIndex = TOKEN_PATTERN.lastIndex;
	}

	if ( lastIndex < template.length ) {
		segments.push( { type: 'text', value: template.slice( lastIndex ) } );
	}

	return segments;
}

/**
 * Rebuild the stored template string from its segments.
 *
 * @param {Array<Object>} segments Segments.
 * @return {string} Template.
 */
export function serializeSegments( segments ) {
	return segments
		.map( ( segment ) =>
			'token' === segment.type ? segment.raw : segment.value
		)
		.join( '' );
}
