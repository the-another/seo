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
	background: var( --taseo-consent-accent, #1a1a1a );
	color: var( --taseo-consent-accent-text, #fff );
	cursor: pointer;
}
.action:focus-visible { outline: 2px solid var( --taseo-consent-accent, #1a1a1a ); outline-offset: 2px; }
a { color: inherit; }
.panel { flex: 1 1 100%; padding-top: .75rem; border-top: 1px solid var( --taseo-consent-border, rgba( 0, 0, 0, .15 ) ); }
.panel[hidden] { display: none; }
.category { display: block; margin: 0 0 .75rem; }
.category span { font-weight: 600; }
.category p { margin: .15rem 0 0 1.6rem; }
`;

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

	if ( config.policyUrl ) {
		const link = document.createElement( 'a' );
		link.href = config.policyUrl;
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
		name.textContent = ' ' + ( meta.label || slug );

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

	if ( options.open ) {
		host.taseoOpenPreferences();
	}

	return host;
}
