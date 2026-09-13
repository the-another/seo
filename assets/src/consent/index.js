import { createBanner } from './banner.js';

let host = null;

/**
 * @param {Object} options { open } to land on the preferences panel.
 */
function render( options = {} ) {
	if ( host && host.isConnected ) {
		if ( options.open ) {
			host.taseoOpenPreferences();
		}

		return;
	}

	host = createBanner( window.taseoConsentConfig || {}, window.taseoConsent, options );
	document.body.appendChild( host );
	host.taseoSyncAccent();
	host.taseoFocus();
}

window.taseoConsentUi = { render };

render( window.taseoConsentUiRequest || { open: false } );
