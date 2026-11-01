import { counter, shareUrl, socialLength } from '../../src/shared/social';
import { fieldsFor, isEmpty, parseFaq } from '../../src/shared/kinds';
import { formFromDraft } from '../../src/admin/ImportTab';
import { preselected } from '../../src/admin/util';

describe( 'social counting', () => {
	test( 'X counts every link as 23 characters', () => {
		const text = 'Jazz night https://example.com/events/a-very-long-event-slug-that-goes-on-and-on/';
		expect( socialLength( text, 'x' ) ).toBe( 'Jazz night '.length + 23 );
		expect( socialLength( text, 'facebook' ) ).toBe( text.length );
	} );
	test( 'counts emoji as one character', () => {
		expect( socialLength( '🎷🎺', 'bluesky' ) ).toBe( 2 );
	} );
	test( 'counter tones: ok, above ideal, over the limit', () => {
		expect( counter( 'a'.repeat( 100 ), 'x' ).tone ).toBe( 'success' );
		expect( counter( 'a'.repeat( 260 ), 'x' ).tone ).toBe( 'warning' );
		const over = counter( 'a'.repeat( 281 ), 'x' );
		expect( over.tone ).toBe( 'danger' );
		expect( over.label ).toBe( '281 / 280' );
		expect( counter( 'a'.repeat( 301 ), 'bluesky' ).tone ).toBe( 'danger' );
	} );
	test( 'share URLs', () => {
		expect( shareUrl( 'x', 'Hi & bye', 'https://s.test/e' ) ).toBe( 'https://x.com/intent/post?text=Hi%20%26%20bye' );
		expect( shareUrl( 'facebook', 'x', 'https://s.test/e' ) ).toContain( encodeURIComponent( 'https://s.test/e' ) );
		expect( shareUrl( 'instagram', 'x', 'y' ) ).toBe( '' );
	} );
} );

describe( 'kinds', () => {
	test( 'parses the FAQ text format', () => {
		const items = parseFaq( 'Q: When?\nA: Friday 7pm.\n\nQ: Where?\nA: Town hall,\nmain entrance.\n\nQ: Empty?' );
		expect( items ).toEqual( [
			{ q: 'When?', a: 'Friday 7pm.' },
			{ q: 'Where?', a: 'Town hall, main entrance.' },
		] );
	} );
	test( 'review fields per kind', () => {
		expect( fieldsFor( 'description' ).map( ( f ) => f.key ) ).toEqual( [ 'excerpt', 'body' ] );
		expect( fieldsFor( 'email_recap' ).map( ( f ) => f.key ) ).toEqual( [ 'subject', 'preheader', 'body' ] );
		expect( fieldsFor( 'seo' ).map( ( f ) => f.key ) ).toEqual( [ 'title', 'description' ] );
		expect( fieldsFor( 'social', { x: 'a', linkedin: 'b' } ).map( ( f ) => f.key ) ).toEqual( [ 'x', 'linkedin' ] );
	} );
	test( 'isEmpty', () => {
		expect( isEmpty( { a: ' ', b: '' } ) ).toBe( true );
		expect( isEmpty( { a: 'x' } ) ).toBe( false );
		expect( isEmpty( null ) ).toBe( true );
	} );
} );

describe( 'import form', () => {
	test( 'uses the match suggestions', () => {
		const f = formFromDraft( {
			title: 'Spring Fair',
			start_date: '2027-04-03',
			venue: { name: 'Town Hall', city: 'Leeds' },
			venue_match: { suggestion: 'existing', id: 12, candidates: [ { id: 12, name: 'Town Hall', score: 100 } ] },
			organizer: { name: 'New Org' },
			organizer_match: { suggestion: 'new', id: 0, candidates: [] },
			category_ids: [ 3 ],
		} );
		expect( f.venue ).toMatchObject( { mode: 'existing', id: 12, name: 'Town Hall' } );
		expect( f.organizer ).toMatchObject( { mode: 'new', name: 'New Org' } );
		expect( f.category_ids ).toEqual( [ 3 ] );
		expect( f.featured ).toBe( true );
	} );
	test( 'preselected ids from the TEC bulk action', () => {
		expect( preselected( '?page=x&dev3ec_ids=4,9,0' ) ).toEqual( [ 4, 9 ] );
		expect( preselected( '?page=x' ) ).toEqual( [] );
	} );
} );
