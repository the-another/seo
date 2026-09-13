import { activate } from './activate.js';

function blocked( html ) {
	document.head.innerHTML = html;
}

describe( 'activation', () => {
	beforeEach( () => {
		document.head.innerHTML = '';
	} );

	it( 'leaves a refused block inert', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="marketing">window.fired=1;</script>'
		);

		expect( activate( [ 'analytics' ] ) ).toBe( 0 );
		expect(
			document.querySelector( 'script[type="text/plain"]' )
		).not.toBeNull();
	} );

	it( 'activates an accepted block by replacing the node', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="analytics">window.fired=1;</script>'
		);

		expect( activate( [ 'analytics' ] ) ).toBe( 1 );

		const live = document.head.querySelector( 'script' );
		expect( live.getAttribute( 'type' ) ).toBeNull();
		expect( live.hasAttribute( 'data-taseo-consent' ) ).toBe( false );
		expect( live.text ).toBe( 'window.fired=1;' );
	} );

	it( 'activates a block listing any accepted category', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="analytics marketing">0;</script>'
		);

		expect( activate( [ 'marketing' ] ) ).toBe( 1 );
	} );

	it( 'runs at most one member of a group and removes the rest', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="analytics" data-taseo-consent-group="gtag" src="https://example.test/a"></script>' +
				'<script type="text/plain" data-taseo-consent="marketing" data-taseo-consent-group="gtag" src="https://example.test/m"></script>'
		);

		expect( activate( [ 'analytics', 'marketing' ] ) ).toBe( 1 );

		const scripts = [ ...document.head.querySelectorAll( 'script' ) ];
		expect( scripts ).toHaveLength( 1 );
		expect( scripts[ 0 ].getAttribute( 'src' ) ).toBe(
			'https://example.test/a'
		);
	} );

	it( 'picks the accepted member when the first is refused', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="analytics" data-taseo-consent-group="gtag" src="https://example.test/a"></script>' +
				'<script type="text/plain" data-taseo-consent="marketing" data-taseo-consent-group="gtag" src="https://example.test/m"></script>'
		);

		activate( [ 'marketing' ] );

		const scripts = [ ...document.head.querySelectorAll( 'script' ) ];
		expect( scripts ).toHaveLength( 1 );
		expect( scripts[ 0 ].getAttribute( 'src' ) ).toBe(
			'https://example.test/m'
		);
	} );

	it( 'carries a CSP nonce across replacement', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="analytics" nonce="">0;</script>'
		);

		// What a browser does to an element already in a document: the nonce
		// content attribute reads empty and the real value lives in an
		// internal slot, so copying attributes alone hands the live element
		// nonce="" and a nonce-based CSP refuses to run it.
		const block = document.head.querySelector( 'script' );
		Object.defineProperty( block, 'nonce', {
			configurable: true,
			get: () => 'n0nce',
		} );

		expect( activate( [ 'analytics' ] ) ).toBe( 1 );
		expect( document.head.querySelector( 'script' ).nonce ).toBe(
			'n0nce'
		);
	} );

	it( 'is idempotent', () => {
		blocked(
			'<script type="text/plain" data-taseo-consent="analytics">0;</script>'
		);

		expect( activate( [ 'analytics' ] ) ).toBe( 1 );
		expect( activate( [ 'analytics' ] ) ).toBe( 0 );
	} );
} );
