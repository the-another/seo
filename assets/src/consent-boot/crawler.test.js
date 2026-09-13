import { isRealUser } from './crawler.js';

describe( 'real-user detection', () => {
	it( 'accepts an ordinary browser', () => {
		expect(
			isRealUser( { userAgent: 'Mozilla/5.0 (Macintosh) Safari/605' } )
		).toBe( true );
	} );

	it( 'rejects an automated browser', () => {
		expect(
			isRealUser( { userAgent: 'Mozilla/5.0', webdriver: true } )
		).toBe( false );
	} );

	it.each( [
		'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		'Mozilla/5.0 (compatible; bingbot/2.0)',
		'facebookexternalhit/1.1',
		'Mozilla/5.0 HeadlessChrome/120',
	] )( 'rejects %s', ( userAgent ) => {
		expect( isRealUser( { userAgent } ) ).toBe( false );
	} );

	it( 'rejects a blank user agent', () => {
		expect( isRealUser( { userAgent: '' } ) ).toBe( false );
	} );
} );
