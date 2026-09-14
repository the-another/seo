/**
 * Turning inert blocks into running ones.
 *
 * A script element's type cannot be changed into executing after it is parsed,
 * so each accepted block is replaced by a fresh element carrying the same
 * attributes and body. Replacement is also what makes this idempotent: an
 * activated block no longer matches the selector.
 */

export const BLOCK_SELECTOR = 'script[type="text/plain"][data-taseo-consent]';

const CONSENT_ATTR = 'data-taseo-consent';
const GROUP_ATTR = 'data-taseo-consent-group';

/**
 * @param {string[]}         accepted Accepted category slugs.
 * @param {Document|Element} [root]   Where to look.
 * @return {number} How many blocks were activated.
 */
export function activate( accepted, root = document ) {
	const blocks = Array.from( root.querySelectorAll( BLOCK_SELECTOR ) );
	let activated = 0;

	for ( const block of blocks ) {
		if ( ! block.isConnected ) {
			continue;
		}

		const categories = ( block.getAttribute( CONSENT_ATTR ) || '' )
			.split( /\s+/ )
			.filter( Boolean );

		if ( ! categories.some( ( slug ) => accepted.includes( slug ) ) ) {
			continue;
		}

		const group = block.getAttribute( GROUP_ATTR ) || '';

		if ( group ) {
			// One member wins and the others are removed rather than left
			// inert: a category granted later must not start a second copy of
			// a library the first member already loaded.
			for ( const other of blocks ) {
				if (
					other !== block &&
					other.isConnected &&
					other.getAttribute( GROUP_ATTR ) === group
				) {
					other.remove();
				}
			}
		}

		replace( block );
		activated++;
	}

	return activated;
}

/**
 * @param {Element} block Inert script element.
 */
function replace( block ) {
	const live = block.ownerDocument.createElement( 'script' );

	for ( const attribute of Array.from( block.attributes ) ) {
		if (
			attribute.name === 'type' ||
			attribute.name === CONSENT_ATTR ||
			attribute.name === GROUP_ATTR
		) {
			continue;
		}

		live.setAttribute( attribute.name, attribute.value );
	}

	// Not covered by the attribute loop: once an element is in a document,
	// browsers empty its nonce content attribute and keep the value in an
	// internal slot, so the copy above reads ''. Without this line every
	// activated block is refused by a nonce-based CSP and tracking dies
	// silently for the visitors who consented to it.
	live.nonce = block.nonce;

	if ( ! block.hasAttribute( 'src' ) ) {
		live.text = block.text;
	}

	block.parentNode.replaceChild( live, block );
}
