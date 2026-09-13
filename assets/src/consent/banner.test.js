import { createBanner } from './banner.js';

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

	it( 'removes itself once a choice is made', () => {
		const { host, shadow } = mount();
		shadow.querySelector( '[data-action="reject-all"]' ).click();

		expect( host.isConnected ).toBe( false );
	} );
} );
