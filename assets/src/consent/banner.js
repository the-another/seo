/**
 * The default consent banner.
 *
 * Shadow DOM, so a theme's stylesheet cannot reach in and a brand's CSS reset
 * cannot flatten the controls. Custom properties inherit through the shadow
 * boundary, and they are the whole theming surface: a brand restyles this from
 * its own stylesheet by setting --taseo-consent-* on :root, and a brand that
 * wants something else entirely renders its own UI against window.taseoConsent
 * and never loads this bundle.
 */

const STYLE = `
:host { all: initial; }
.bar {
	position: fixed;
	inset: auto 0 0 0;
	z-index: var( --taseo-consent-z, 2147483647 );
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	align-items: center;
	justify-content: space-between;
	padding: 1rem 1.25rem;
	font: 400 14px/1.5 var( --taseo-consent-font, system-ui, -apple-system, sans-serif );
	color: var( --taseo-consent-text, #1a1a1a );
	background: var( --taseo-consent-surface, #fff );
	border-top: 1px solid var( --taseo-consent-border, rgba( 0, 0, 0, .15 ) );
	box-shadow: 0 -2px 16px var( --taseo-consent-shadow, rgba( 0, 0, 0, .08 ) );
}
.text { flex: 1 1 20rem; }
.title { margin: 0 0 .25rem; font-size: 15px; font-weight: 600; }
.body { margin: 0; }
.actions { display: flex; flex-wrap: wrap; gap: .5rem; }
.action {
	font: inherit;
	padding: .55em 1.1em;
	border-radius: var( --taseo-consent-radius, 6px );
	border: 1px solid var( --taseo-consent-border, rgba( 0, 0, 0, .25 ) );
	background: var( --taseo-consent-accent, var( --wp--preset--color--accent-1, var( --wp--preset--color--primary, #1a1a1a ) ) );
	color: var( --taseo-consent-accent-text, #fff );
	cursor: pointer;
}
.action:focus-visible { outline: 2px solid var( --taseo-consent-accent, var( --wp--preset--color--accent-1, var( --wp--preset--color--primary, #1a1a1a ) ) ); outline-offset: 2px; }
a { color: inherit; }
.panel { flex: 1 1 100%; padding-top: .75rem; border-top: 1px solid var( --taseo-consent-border, rgba( 0, 0, 0, .15 ) ); }
.panel[hidden] { display: none; }
.category { display: block; margin: 0 0 .75rem; }
.category span { font-weight: 600; }
.category p { margin: .15rem 0 0 1.6rem; }
.category[data-category] > p { margin-left: 0; }
.always { font-style: normal; font-weight: 400; opacity: .7; }
`;

/**
 * A policy URL that is safe to put in an href.
 *
 * esc_url_raw() guards the value saved in settings, but taseo_consent_config
 * can replace it and never passes through that path, so the scheme is checked
 * here too: only http and https link, everything else (javascript:, data:) is
 * dropped along with the link.
 *
 * @param {*} value Candidate URL.
 * @return {string} The URL to link, or '' when there is none to trust.
 */
function safeUrl( value ) {
	if ( typeof value !== 'string' || ! value ) {
		return '';
	}

	try {
		const url = new URL( value, window.location.href );

		return url.protocol === 'http:' || url.protocol === 'https:'
			? url.href
			: '';
	} catch ( e ) {
		return '';
	}
}

/**
 * A readable label for a category whose registrant supplied no copy.
 *
 * @param {string} slug Category slug.
 * @return {string} Label.
 */
function humanise( slug ) {
	const text = String( slug ).replace( /[-_]+/g, ' ' ).trim();

	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}

/**
 * @param {string} label  Button text.
 * @param {string} action data-action value.
 * @return {HTMLButtonElement} Button.
 */
function button( label, action ) {
	const element = document.createElement( 'button' );
	element.type = 'button';
	element.className = 'action';
	element.setAttribute( 'data-action', action );
	element.textContent = label || '';

	return element;
}

/**
 * Text colour that reads on a given background.
 *
 * The banner adopts the brand's own accent, and the brands differ enough that no
 * fixed text colour works on all of them: white on a mid teal is 3.8:1 and near
 * black on a dark rust is 2.3:1, both short of WCAG AA. So the colour is chosen
 * from the accent that actually resolved, by comparing the contrast each
 * candidate achieves against it.
 *
 * @param {string} color Resolved CSS colour, as getComputedStyle returns it.
 * @return {string|null} '#ffffff', '#111111', or null when the colour is not one
 *                       this can parse — in which case the stylesheet default stands.
 */
export function accentTextFor( color ) {
	const channels = String( color ).match( /rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i );

	if ( ! channels ) {
		return null;
	}

	const srgb = ( value ) => {
		const c = Number( value ) / 255;

		return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	};

	const luminance =
		0.2126 * srgb( channels[ 1 ] ) +
		0.7152 * srgb( channels[ 2 ] ) +
		0.0722 * srgb( channels[ 3 ] );

	const contrast = ( against ) => {
		const [ hi, lo ] = luminance > against ? [ luminance, against ] : [ against, luminance ];

		return ( hi + 0.05 ) / ( lo + 0.05 );
	};

	// 1 is white's relative luminance; 0.0056 is #111111's.
	return contrast( 1 ) >= contrast( 0.0056 ) ? '#ffffff' : '#111111';
}

/**
 * @param {Object} config  Boot config.
 * @param {Object} api     window.taseoConsent.
 * @param {Object} options { open } to start on the preferences panel.
 * @return {HTMLElement} The host element, not yet in the document.
 */
export function createBanner( config, api, options = {} ) {
	const copy = config.copy || {};
	const categories = Array.isArray( config.categories ) ? config.categories : [];
	const host = document.createElement( 'div' );
	host.className = 'taseo-consent';

	const shadow = host.attachShadow( { mode: 'open' } );
	const style = document.createElement( 'style' );
	style.textContent = STYLE;
	shadow.appendChild( style );

	const bar = document.createElement( 'div' );
	bar.className = 'bar';
	bar.setAttribute( 'role', 'dialog' );
	bar.setAttribute( 'aria-modal', 'false' );
	bar.setAttribute( 'aria-labelledby', 'taseo-consent-title' );
	bar.tabIndex = -1;

	const text = document.createElement( 'div' );
	text.className = 'text';

	const title = document.createElement( 'p' );
	title.className = 'title';
	title.id = 'taseo-consent-title';
	title.textContent = copy.title || '';

	const body = document.createElement( 'p' );
	body.className = 'body';
	body.textContent = copy.body || '';

	const policyUrl = safeUrl( config.policyUrl );

	if ( policyUrl ) {
		const link = document.createElement( 'a' );
		link.href = policyUrl;
		link.rel = 'noreferrer';
		link.textContent = copy.policy || '';
		body.append( ' ', link );
	}

	text.append( title, body );

	const actions = document.createElement( 'div' );
	actions.className = 'actions';

	// One class for all three: rejecting must never be the quieter control.
	const accept = button( copy.acceptAll, 'accept-all' );
	const reject = button( copy.rejectAll, 'reject-all' );
	const preferences = button( copy.preferences, 'preferences' );
	actions.append( accept, reject, preferences );

	const panel = document.createElement( 'div' );
	panel.className = 'panel';
	panel.hidden = true;

	const inputs = {};

	if ( copy.necessary ) {
		const row = document.createElement( 'div' );
		row.className = 'category';
		row.setAttribute( 'data-category', 'necessary' );

		const necessaryName = document.createElement( 'span' );
		necessaryName.textContent = copy.necessary.label || '';

		const state = document.createElement( 'em' );
		state.className = 'always';
		state.textContent = copy.necessary.state || '';

		const necessaryNote = document.createElement( 'p' );
		necessaryNote.textContent = copy.necessary.body || '';

		row.append( necessaryName, ' ', state, necessaryNote );
		panel.appendChild( row );
	}

	categories.forEach( ( slug ) => {
		const meta = ( copy.categories || {} )[ slug ] || {};
		const label = document.createElement( 'label' );
		label.className = 'category';

		const input = document.createElement( 'input' );
		input.type = 'checkbox';
		// Unchecked, always: doing nothing in this panel must grant nothing.
		input.checked = false;
		input.setAttribute( 'data-category', slug );

		const name = document.createElement( 'span' );
		name.textContent = ' ' + ( meta.label || humanise( slug ) );

		const note = document.createElement( 'p' );
		note.textContent = meta.body || '';

		label.append( input, name, note );
		panel.appendChild( label );
		inputs[ slug ] = input;
	} );

	const save = button( copy.save, 'save' );
	panel.appendChild( save );

	bar.append( text, actions, panel );
	shadow.appendChild( bar );

	/**
	 * @param {boolean|Function} value Granted state, or a per-slug resolver.
	 */
	function decide( value ) {
		const cats = {};

		categories.forEach( ( slug ) => {
			cats[ slug ] = typeof value === 'function' ? value( slug ) : value;
		} );

		api.set( cats );
		host.remove();
	}

	accept.addEventListener( 'click', () => decide( true ) );
	reject.addEventListener( 'click', () => decide( false ) );
	save.addEventListener( 'click', () => decide( ( slug ) => inputs[ slug ].checked === true ) );

	preferences.addEventListener( 'click', () => {
		panel.hidden = false;
		preferences.hidden = true;

		const first = panel.querySelector( 'input' );

		if ( first ) {
			first.focus();
		}
	} );

	bar.addEventListener( 'keydown', ( event ) => {
		// Escape closes the preferences panel and never the banner. Dismissing
		// without an answer would look like a decision the visitor never made,
		// and the way out of the banner is the Reject button beside Accept.
		if ( event.key === 'Escape' && ! panel.hidden ) {
			panel.hidden = true;
			preferences.hidden = false;
			preferences.focus();
		}
	} );

	host.taseoOpenPreferences = () => {
		if ( panel.hidden ) {
			preferences.click();
		}
	};

	host.taseoFocus = () => bar.focus();

	// Runs after the host is in the document: a custom property chain only
	// resolves once the element is connected, so this cannot happen at build time.
	host.taseoSyncAccent = () => {
		const probe = shadow.querySelector( '.action' );

		if ( ! probe ) {
			return;
		}

		const text = accentTextFor( window.getComputedStyle( probe ).backgroundColor );

		if ( null !== text ) {
			host.style.setProperty( '--taseo-consent-accent-text', text );
		}
	};

	if ( options.open ) {
		host.taseoOpenPreferences();
	}

	return host;
}
