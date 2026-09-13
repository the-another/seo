/**
 * The consent boot script, inlined into <head> after every inert block.
 *
 * It runs synchronously so that a returning visitor who already accepted has
 * their tags activated during head parsing, where those tags would have been
 * anyway. Everything that can wait — deciding whether to ask, loading the UI —
 * waits for DOMContentLoaded.
 */

import { readRecord, writeRecord, isUsable, acceptedFrom } from './store.js';
import { isRealUser } from './crawler.js';
import { activate } from './activate.js';
import { clearVendorCookies } from './cookies.js';

const config = window.taseoConsentConfig || {};
const categories = Array.isArray( config.categories ) ? config.categories : [];
const listeners = [];

let record = readRecord();
let refusedByBrowser =
	window.navigator && window.navigator.globalPrivacyControl === true;
let uiRequested = false;

function accepted() {
	return refusedByBrowser ? [] : acceptedFrom( record, categories );
}

activate( accepted() );

function loadUi( open ) {
	window.taseoConsentUiRequest = { open };

	if ( uiRequested ) {
		if ( window.taseoConsentUi ) {
			window.taseoConsentUi.render( { open } );
		}

		return;
	}

	uiRequested = true;

	const script = document.createElement( 'script' );
	script.src = config.uiUrl;
	script.defer = true;
	document.head.appendChild( script );
}

window.taseoConsent = {
	get() {
		return record && ! refusedByBrowser ? { ...record.cats } : null;
	},
	categories() {
		return categories.slice();
	},
	set( cats ) {
		const clean = {};
		let revoked = false;

		categories.forEach( ( slug ) => {
			clean[ slug ] = cats[ slug ] === true;

			if ( record && record.cats[ slug ] === true && ! clean[ slug ] ) {
				revoked = true;
			}
		} );

		record = writeRecord( clean );
		refusedByBrowser = false;

		if ( revoked ) {
			clearVendorCookies();
			window.location.reload();

			return;
		}

		activate( accepted() );
		listeners.forEach( ( fn ) => fn( { ...clean } ) );
	},
	open() {
		loadUi( true );
	},
	on( event, fn ) {
		if ( event === 'change' && typeof fn === 'function' ) {
			listeners.push( fn );
		}
	},
};

document.addEventListener( 'DOMContentLoaded', () => {
	document.addEventListener( 'click', ( event ) => {
		const opener =
			event.target.closest &&
			event.target.closest( '[data-taseo-consent-open]' );

		if ( opener ) {
			event.preventDefault();
			window.taseoConsent.open();
		}
	} );

	if ( refusedByBrowser ) {
		return;
	}

	if ( ! isUsable( record, categories, config.lifetimeDays, Date.now() ) ) {
		if ( isRealUser( window.navigator, config.crawlers ) ) {
			loadUi( false );
		}

		return;
	}

	// A decision exists, so the only thing left is a way back to it. A theme
	// that placed its own control gets no second one.
	if (
		! document.querySelector( '[data-taseo-consent-open]' ) &&
		isRealUser( window.navigator, config.crawlers )
	) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'taseo-consent-reopen';
		button.setAttribute( 'data-taseo-consent-open', '' );
		button.textContent =
			( config.copy && config.copy.manage ) || 'Cookie settings';
		button.style.cssText =
			'position:fixed;left:1rem;bottom:1rem;z-index:2147483646;font:inherit;font-size:12px;' +
			'padding:.4em .8em;border-radius:999px;border:1px solid rgba(0,0,0,.2);' +
			'background:var(--taseo-consent-surface,#fff);color:var(--taseo-consent-text,#1a1a1a);cursor:pointer';
		document.body.appendChild( button );
	}
} );
