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
} );
