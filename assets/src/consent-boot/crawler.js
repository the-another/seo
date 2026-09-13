/**
 * Whether there is a person here to ask.
 *
 * A crawler is never shown the banner and never activates anything: it has no
 * decision to give, and a banner in a rendered crawl is noise at best. Refusal
 * is what a bot gets, which is also what it should get.
 */

export const DEFAULT_CRAWLERS =
	'bot|crawl|spider|slurp|mediapartners|bingpreview|facebookexternalhit|' +
	'headlesschrome|phantomjs|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|preview';

/**
 * @param {Navigator} [nav]     Navigator to inspect.
 * @param {string}    [pattern] Crawler pattern source.
 * @return {boolean} Whether to treat this as a real visitor.
 */
export function isRealUser(
	nav = window.navigator,
	pattern = DEFAULT_CRAWLERS
) {
	if ( ! nav || nav.webdriver === true ) {
		return false;
	}

	const ua = String( nav.userAgent || '' ).toLowerCase();

	if ( ! ua ) {
		return false;
	}

	// An empty pattern means "use the built-in one" — that is what the PHP
	// config's 'crawlers' key documents and what it defaults to, and a default
	// parameter cannot catch it because '' is a value, not a missing argument.
	// Handing '' to RegExp would build //, which matches every string, so
	// every visitor would be classified as a crawler and nobody would ever be
	// asked for consent.
	return ! new RegExp( pattern || DEFAULT_CRAWLERS ).test( ua );
}
