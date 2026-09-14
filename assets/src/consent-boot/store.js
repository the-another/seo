/**
 * The visitor's stored consent decision.
 *
 * localStorage, not a cookie: the record never travels over HTTP, so it cannot
 * enter any cache key, cannot be varied on by a host's cache rules by accident,
 * and adds nothing to every request on a site serving millions of pages. The
 * server can never read it, which is the point — a server-side answer behind a
 * full-page cache would be whoever warmed the cache's answer.
 */

export const STORAGE_KEY = 'taseo_consent';
export const RECORD_VERSION = 1;

const DAY_MS = 86400000;

/**
 * @return {Storage|null} localStorage, or null where it is unavailable.
 */
function defaultStorage() {
	try {
		return window.localStorage;
	} catch ( e ) {
		return null;
	}
}

/**
 * @param {Storage} [storage] Storage to read.
 * @return {Object|null} The record, or null when there is no usable one.
 */
export function readRecord( storage = defaultStorage() ) {
	if ( ! storage ) {
		return null;
	}

	let raw;

	try {
		raw = storage.getItem( STORAGE_KEY );
	} catch ( e ) {
		return null;
	}

	if ( ! raw ) {
		return null;
	}

	let parsed;

	try {
		parsed = JSON.parse( raw );
	} catch ( e ) {
		return null;
	}

	if ( ! parsed || typeof parsed !== 'object' ) {
		return null;
	}

	if ( parsed.v !== RECORD_VERSION || typeof parsed.t !== 'number' ) {
		return null;
	}

	if ( ! parsed.cats || typeof parsed.cats !== 'object' ) {
		return null;
	}

	return parsed;
}

/**
 * @param {Object}  cats      Category slug => accepted.
 * @param {Storage} [storage] Storage to write.
 * @param {number}  [now]     Milliseconds.
 * @return {Object} The record written.
 */
export function writeRecord(
	cats,
	storage = defaultStorage(),
	now = Date.now()
) {
	const record = { v: RECORD_VERSION, cats, t: Math.floor( now / 1000 ) };

	if ( storage ) {
		try {
			storage.setItem( STORAGE_KEY, JSON.stringify( record ) );
		} catch ( e ) {
			// A full or blocked store means the choice cannot be remembered.
			// Refusal on the next page load is the right failure.
		}
	}

	return record;
}

/**
 * Whether a record still answers for every category the site offers.
 *
 * A category the record does not mention makes it unusable, which is what
 * re-asks every visitor when a site adds a tracking vendor in a new category —
 * no stored version to remember to bump.
 *
 * A lifetime that is not a positive number makes every record unusable rather
 * than immortal. PHP normalizes the config before it gets here, but this half
 * must not depend on the other half behaving: `x > NaN` is false, so trusting
 * an undefined lifetime would mean no stored decision ever expires and no
 * visitor is ever re-asked — the one place in this feature that would fail
 * open, and exactly what the lifetime setting exists to prevent.
 *
 * @param {Object|null} record       The record.
 * @param {string[]}    categories   Category slugs offered now.
 * @param {number}      lifetimeDays How long a decision lasts.
 * @param {number}      [now]        Milliseconds.
 * @return {boolean} Usable.
 */
export function isUsable( record, categories, lifetimeDays, now = Date.now() ) {
	if ( ! record ) {
		return false;
	}

	const days = Number( lifetimeDays );

	if ( ! Number.isFinite( days ) || days <= 0 ) {
		return false;
	}

	if ( now - record.t * 1000 > days * DAY_MS ) {
		return false;
	}

	return categories.every(
		( slug ) => typeof record.cats[ slug ] === 'boolean'
	);
}

/**
 * @param {Object|null} record     The record.
 * @param {string[]}    categories Category slugs offered now.
 * @return {string[]} Accepted slugs.
 */
export function acceptedFrom( record, categories ) {
	if ( ! record ) {
		return [];
	}

	return categories.filter( ( slug ) => record.cats[ slug ] === true );
}
