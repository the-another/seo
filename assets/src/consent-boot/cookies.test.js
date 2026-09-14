import { clearVendorCookies, VENDOR_COOKIES } from './cookies.js';

describe( 'vendor cookie clearing', () => {
	it.each( [
		'_ga',
		'_ga_ABC123',
		'_gid',
		'_gcl_au',
		'_fbp',
		'_fbc',
		'_uetsid',
		'_uetvid',
	] )( 'matches %s', ( name ) => {
		expect( VENDOR_COOKIES.test( name ) ).toBe( true );
	} );

	it.each( [
		'wordpress_logged_in_x',
		'woocommerce_cart_hash',
		'PHPSESSID',
	] )( 'leaves %s alone', ( name ) => {
		expect( VENDOR_COOKIES.test( name ) ).toBe( false );
	} );

	it( 'expires each vendor cookie on the bare path, the host and its parent domain', () => {
		const written = [];
		const doc = {
			get cookie() {
				return '_ga=GA1.1; PHPSESSID=abc; _fbp=fb.1';
			},
			set cookie( value ) {
				written.push( value );
			},
		};

		clearVendorCookies( doc, 'shop.example.test' );

		expect(
			written.filter( ( v ) => v.startsWith( 'PHPSESSID' ) )
		).toHaveLength( 0 );
		expect(
			written.filter( ( v ) => v.startsWith( '_ga=' ) )
		).toHaveLength( 3 );
		expect(
			written.some( ( v ) => v.includes( 'domain=.example.test' ) )
		).toBe( true );
		expect(
			written.every( ( v ) => v.includes( 'expires=Thu, 01 Jan 1970' ) )
		).toBe( true );
	} );

	it( 'returns without throwing when reading document.cookie throws', () => {
		const doc = {
			get cookie() {
				throw new Error( 'blocked' );
			},
			set cookie( value ) {
				throw new Error( 'should never be reached' );
			},
		};

		expect( () =>
			clearVendorCookies( doc, 'shop.example.test' )
		).not.toThrow();
	} );

	it( 'keeps attempting the remaining cookies when a write throws', () => {
		const written = [];
		let calls = 0;
		const doc = {
			get cookie() {
				return '_ga=GA1.1; _fbp=fb.1';
			},
			set cookie( value ) {
				calls++;

				if ( calls === 1 ) {
					throw new Error( 'blocked' );
				}

				written.push( value );
			},
		};

		expect( () =>
			clearVendorCookies( doc, 'shop.example.test' )
		).not.toThrow();

		// 3 domain candidates each for _ga and _fbp = 6 attempts; the first
		// throws and is swallowed, the other 5 (including the other cookie
		// name) still land.
		expect( calls ).toBe( 6 );
		expect( written ).toHaveLength( 5 );
		expect( written.some( ( v ) => v.startsWith( '_fbp=' ) ) ).toBe( true );
	} );
} );
