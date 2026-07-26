/**
 * The parse/serialise pair is where every correctness risk in the chips
 * feature lives: it stands between what an administrator stored and what
 * the editing surface shows. These tests pin the round trip.
 */

import { parseTemplate, serializeSegments } from './template-value';

describe( 'parseTemplate', () => {
	it( 'splits text and tokens in order', () => {
		expect( parseTemplate( '%%title%% — Shop' ) ).toEqual( [
			{ type: 'token', slug: 'title', raw: '%%title%%' },
			{ type: 'text', value: ' — Shop' },
		] );
	} );

	it( 'keeps literal text between adjacent tokens', () => {
		expect( parseTemplate( '%%title%% %%sep%% %%sitename%%' ) ).toHaveLength( 5 );
	} );

	it( 'handles adjacent tokens with nothing between them', () => {
		expect( parseTemplate( '%%title%%%%sep%%' ) ).toEqual( [
			{ type: 'token', slug: 'title', raw: '%%title%%' },
			{ type: 'token', slug: 'sep', raw: '%%sep%%' },
		] );
	} );

	it( 'treats an unpaired delimiter as literal text', () => {
		expect( parseTemplate( '%%oops and %%title%%' ) ).toEqual( [
			{ type: 'text', value: '%%oops and ' },
			{ type: 'token', slug: 'title', raw: '%%title%%' },
		] );
	} );

	it( 'lowercases the slug but preserves the original token', () => {
		expect( parseTemplate( '%%TITLE%%' ) ).toEqual( [
			{ type: 'token', slug: 'title', raw: '%%TITLE%%' },
		] );
	} );

	it( 'returns a single text segment when there are no tokens', () => {
		expect( parseTemplate( 'Just a static title' ) ).toEqual( [
			{ type: 'text', value: 'Just a static title' },
		] );
	} );

	it( 'returns nothing for an empty template', () => {
		expect( parseTemplate( '' ) ).toEqual( [] );
	} );
} );

describe( 'round trip', () => {
	it.each( [
		'%%title%% %%sep%% %%sitename%%',
		'%%title%% — Shop',
		'%%title%%%%sep%%',
		'%%oops and %%title%%',
		'%%TITLE%% %%Sep%%',
		'%%not_a_registered_variable%%',
		'Just a static title',
		'',
		'  leading and trailing  ',
	] )( 'serializeSegments( parseTemplate( %j ) ) returns the input unchanged', ( template ) => {
		expect( serializeSegments( parseTemplate( template ) ) ).toBe( template );
	} );
} );
