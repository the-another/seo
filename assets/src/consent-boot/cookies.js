/**
 * Best-effort removal of the cookies a vendor set while it was allowed.
 *
 * Withdrawal cannot un-run code, so the page reloads afterwards; clearing what
 * the vendor left behind is the part that can be done here. Only cookies this
 * plugin's own vendors are known to set are touched.
 */

export const VENDOR_COOKIES = /^(_ga|_gid|_gat|_gcl_|_fbp|_fbc|_uet)/;

/**
 * @param {Document} [doc]  Document to read and write cookies on.
 * @param {string}   [host] Hostname, for the parent-domain attempt.
 */
export function clearVendorCookies(
	doc = document,
	host = window.location.hostname
) {
	const parts = host.split( '.' );
	const domains = [ '', host ];

	if ( parts.length > 2 ) {
		domains.push( '.' + parts.slice( -2 ).join( '.' ) );
	}

	let cookieString;

	try {
		cookieString = String( doc.cookie || '' );
	} catch ( e ) {
		// A blocked or throwing cookie store leaves nothing to clear.
		return;
	}

	for ( const pair of cookieString.split( ';' ) ) {
		const name = pair.split( '=' )[ 0 ].trim();

		if ( ! name || ! VENDOR_COOKIES.test( name ) ) {
			continue;
		}

		for ( const domain of domains ) {
			try {
				doc.cookie =
					name +
					'=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' +
					( domain ? '; domain=' + domain : '' );
			} catch ( e ) {
				// One domain candidate refusing the write should not stop
				// the remaining domain and cookie attempts.
			}
		}
	}
}
