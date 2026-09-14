import {
	STORAGE_KEY,
	readRecord,
	writeRecord,
	isUsable,
	acceptedFrom,
} from './store.js';

function fakeStorage( initial = {} ) {
	const data = { ...initial };
	return {
		getItem: ( k ) => ( k in data ? data[ k ] : null ),
		setItem: ( k, v ) => {
			data[ k ] = String( v );
		},
		data,
	};
}

describe( 'the consent record', () => {
	it( 'reads nothing when storage is empty', () => {
		expect( readRecord( fakeStorage() ) ).toBeNull();
	} );

	it( 'reads nothing when storage throws', () => {
		const hostile = {
			getItem: () => {
				throw new Error( 'blocked' );
			},
		};
		expect( readRecord( hostile ) ).toBeNull();
	} );

	it( 'rejects a record of an unknown version', () => {
		const storage = fakeStorage( {
			[ STORAGE_KEY ]: JSON.stringify( { v: 99, cats: {}, t: 1 } ),
		} );
		expect( readRecord( storage ) ).toBeNull();
	} );

	it( 'rejects malformed JSON', () => {
		expect(
			readRecord( fakeStorage( { [ STORAGE_KEY ]: '{' } ) )
		).toBeNull();
	} );

	it( 'round-trips a decision', () => {
		const storage = fakeStorage();
		writeRecord(
			{ analytics: true, marketing: false },
			storage,
			1757721600000
		);

		expect( readRecord( storage ) ).toEqual( {
			v: 1,
			cats: { analytics: true, marketing: false },
			t: 1757721600,
		} );
	} );

	it( 'treats an expired decision as unusable', () => {
		const record = { v: 1, cats: { analytics: true }, t: 1000000 };
		const oneDayLater = ( 1000000 + 86400 * 2 ) * 1000;

		expect( isUsable( record, [ 'analytics' ], 1, oneDayLater ) ).toBe(
			false
		);
		expect( isUsable( record, [ 'analytics' ], 30, oneDayLater ) ).toBe(
			true
		);
	} );

	it( 'treats a lifetime that is not a positive number as unusable', () => {
		const record = {
			v: 1,
			cats: { analytics: true },
			t: Math.floor( Date.now() / 1000 ),
		};

		// A filtered config that omits lifetimeDays used to reach here as
		// undefined and be compared against NaN, which is false for every
		// operand: the record never expired and the visitor was never asked
		// again. Fail closed instead — the only alternative is a decision
		// that outlives the setting meant to end it.
		expect( isUsable( record, [ 'analytics' ], undefined ) ).toBe( false );
		expect( isUsable( record, [ 'analytics' ], NaN ) ).toBe( false );
		expect( isUsable( record, [ 'analytics' ], 0 ) ).toBe( false );
		expect( isUsable( record, [ 'analytics' ], -1 ) ).toBe( false );
		expect( isUsable( record, [ 'analytics' ], 'later' ) ).toBe( false );
		expect( isUsable( record, [ 'analytics' ], Infinity ) ).toBe( false );
	} );

	it( 'treats a decision that never mentioned a category as unusable', () => {
		const record = {
			v: 1,
			cats: { analytics: true },
			t: Math.floor( Date.now() / 1000 ),
		};

		expect( isUsable( record, [ 'analytics', 'marketing' ], 180 ) ).toBe(
			false
		);
	} );

	it( 'accepts only what was explicitly granted', () => {
		const record = {
			v: 1,
			cats: { analytics: true, marketing: false },
			t: 1,
		};

		expect( acceptedFrom( record, [ 'analytics', 'marketing' ] ) ).toEqual(
			[ 'analytics' ]
		);
		expect( acceptedFrom( null, [ 'analytics' ] ) ).toEqual( [] );
	} );
} );
