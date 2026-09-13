import { createBanner, accentTextFor } from './banner.js';

const config = {
	categories: [ 'analytics', 'marketing' ],
	policyUrl: 'https://example.test/privacy',
	copy: {
		title: 'Before we load tracking',
		body: 'We load analytics and marketing tags only if you agree.',
		acceptAll: 'Accept all',
		rejectAll: 'Reject all',
		preferences: 'Preferences',
		save: 'Save choices',
		manage: 'Cookie settings',
		policy: 'Privacy policy',
		categories: {
			analytics: { label: 'Analytics', body: 'Measuring how the site is used.' },
			marketing: { label: 'Marketing', body: 'Advertising and remarketing.' },
		},
	},
};

function mount() {
	const api = { set: jest.fn() };
	const host = createBanner( config, api );
	document.body.appendChild( host );

	return { host, api, shadow: host.shadowRoot };
}

describe( 'the consent banner', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'renders inside a shadow root so theme CSS cannot reach it', () => {
		const { shadow } = mount();

		expect( shadow ).not.toBeNull();
		expect( shadow.querySelector( '[role="dialog"]' ) ).not.toBeNull();
	} );

	it( 'offers rejection as a button beside acceptance, not a lesser control', () => {
		const { shadow } = mount();
		const accept = shadow.querySelector( '[data-action="accept-all"]' );
		const reject = shadow.querySelector( '[data-action="reject-all"]' );

		expect( accept.tagName ).toBe( 'BUTTON' );
		expect( reject.tagName ).toBe( 'BUTTON' );
		expect( reject.className ).toBe( accept.className );
	} );

	it( 'accepts every category', () => {
		const { shadow, api } = mount();
		shadow.querySelector( '[data-action="accept-all"]' ).click();

		expect( api.set ).toHaveBeenCalledWith( { analytics: true, marketing: true } );
	} );

	it( 'rejects every category', () => {
		const { shadow, api } = mount();
		shadow.querySelector( '[data-action="reject-all"]' ).click();

		expect( api.set ).toHaveBeenCalledWith( { analytics: false, marketing: false } );
	} );

	it( 'saves one category from preferences', () => {
		const { shadow, api } = mount();
		shadow.querySelector( '[data-action="preferences"]' ).click();
		shadow.querySelector( 'input[data-category="analytics"]' ).checked = true;
		shadow.querySelector( '[data-action="save"]' ).click();

		expect( api.set ).toHaveBeenCalledWith( { analytics: true, marketing: false } );
	} );

	it( 'starts every category unchecked, so doing nothing grants nothing', () => {
		const { shadow } = mount();
		shadow.querySelector( '[data-action="preferences"]' ).click();

		expect( shadow.querySelector( 'input[data-category="analytics"]' ).checked ).toBe( false );
		expect( shadow.querySelector( 'input[data-category="marketing"]' ).checked ).toBe( false );
	} );

	it( 'links the privacy policy when one is configured', () => {
		const { shadow } = mount();

		expect( shadow.querySelector( 'a[href="https://example.test/privacy"]' ) ).not.toBeNull();
	} );

	it( 'refuses to link a policy URL that is not http or https', () => {
		// esc_url_raw() guards the settings path, but taseo_consent_config can
		// replace this value and never passes through it.
		const host = createBanner(
			// eslint-disable-next-line no-script-url
			{ ...config, policyUrl: 'javascript:alert(1)' },
			{ set: jest.fn() }
		);
		document.body.appendChild( host );

		expect( host.shadowRoot.querySelector( 'a' ) ).toBeNull();
	} );

	it( 'links a site-relative policy URL', () => {
		const host = createBanner(
			{ ...config, policyUrl: '/privacy' },
			{ set: jest.fn() }
		);
		document.body.appendChild( host );

		const link = host.shadowRoot.querySelector( 'a' );

		expect( link ).not.toBeNull();
		expect( link.getAttribute( 'href' ) ).toBe(
			new URL( '/privacy', window.location.href ).href
		);
	} );

	it( 'removes itself once a choice is made', () => {
		const { host, shadow } = mount();
		shadow.querySelector( '[data-action="reject-all"]' ).click();

		expect( host.isConnected ).toBe( false );
	} );
} );

describe( 'brand accent contrast', () => {
	// The two brands this plugin actually serves. Neither a fixed white nor a
	// fixed near-black passes WCAG AA on both: white on the teal is 3.8:1, and
	// near-black on the rust is 2.3:1. The text colour has to be chosen from the
	// accent that is actually resolved, which is why this exists at all.
	it( 'uses dark text on a light brand accent', () => {
		expect( accentTextFor( 'rgb(11, 146, 143)' ) ).toBe( '#111111' );
	} );

	it( 'uses light text on a dark brand accent', () => {
		expect( accentTextFor( 'rgb(145, 44, 31)' ) ).toBe( '#ffffff' );
	} );

	it( 'keeps light text on the unbranded default', () => {
		expect( accentTextFor( 'rgb(26, 26, 26)' ) ).toBe( '#ffffff' );
	} );

	it( 'declines to choose when the colour cannot be parsed', () => {
		expect( accentTextFor( 'oklch(0.7 0.1 200)' ) ).toBeNull();
	} );

	it( 'sets the text colour on the host from the resolved accent', () => {
		const original = window.getComputedStyle;
		window.getComputedStyle = ( el ) =>
			el.classList && el.classList.contains( 'action' )
				? { backgroundColor: 'rgb(11, 146, 143)' }
				: original( el );

		try {
			const host = createBanner( config, { set: jest.fn() } );
			document.body.appendChild( host );
			host.taseoSyncAccent();

			expect( host.style.getPropertyValue( '--taseo-consent-accent-text' ) ).toBe( '#111111' );
		} finally {
			window.getComputedStyle = original;
		}
	} );
} );
