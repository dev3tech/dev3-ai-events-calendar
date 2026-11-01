import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import { __resetThrottle } from '../../src/core3/api';
import Writer, { __resetStatus } from '../../src/shared/Writer';
import ReviewTab from '../../src/admin/ReviewTab';
import { change, click, render } from './render';

const STATUS = {
	ai: { enabled: true, key_set: true, cap_reached: false },
	tickets: true,
	canManage: true,
	defaults: { tone: 'friendly', networks: [ 'x', 'linkedin' ] },
	tags: [ '{attendee_name}' ],
	counts: { drafts: { pending: 0 } },
};

const SNAP = {
	event: { id: 5, title: 'Jazz Night', start: '2026-11-14 19:00:00', date_label: 'Saturday 14 November 2026', time_label: '7:00 pm – 10:00 pm', venue: { name: 'Blue Room' }, organizers: [], cost: '$15', url: 'https://s.test/event/jazz', edit_url: '/wp-admin/post.php?post=5' },
	assets: { description: { excerpt: '', body: 'Old text.' }, social: {}, email_promo: {}, email_reminder: {}, email_recap: {}, faq: { faq: '' }, seo: { title: '', description: '' } },
	attendees: { source: 'event-tickets', going: 3, checked_in: 2 },
	recipients: 3,
	pending: [],
	seo: { label: 'Yoast SEO' },
};

const DRAFT = { id: 77, kind: 'description', status: 'pending', source: 'ai', payload: { excerpt: 'A night of jazz.', body: '## What to expect\n\nLive jazz.' }, original: { excerpt: '', body: 'Old text.' }, model: 'gemini-2.5-pro', tokens: 900 };

function routes( extra = {} ) {
	return ( { url, method = 'GET', data } ) => {
		const path = url.replace( '/wp-json/dev3ec/v1/', '' );
		const key = `${ method } ${ path.split( '?' )[ 0 ] }`;
		if ( extra[ key ] ) {
			return Promise.resolve( extra[ key ]( data, path ) );
		}
		if ( key === 'GET status' ) {
			return Promise.resolve( STATUS );
		}
		if ( key === 'GET events/5' ) {
			return Promise.resolve( SNAP );
		}
		return new Promise( () => {} );
	};
}

beforeEach( () => {
	apiFetch.mockReset();
	__resetStatus();
	__resetThrottle();
	window.location.hash = '';
} );

test( 'shows event facts and the description panel', async () => {
	apiFetch.mockImplementation( routes() );
	const view = await render( <ToastProvider><Writer eventId={ 5 } /></ToastProvider> );
	await view.flush();
	expect( view.queryByText( /Saturday 14 November 2026/ ) ).not.toBeNull();
	expect( view.queryByText( '3 attending · 2 checked in' ) ).not.toBeNull();
	expect( view.queryByText( 'Generate with DEV3 AI' ) ).not.toBeNull();
	expect( view.queryByText( 'Old text.' ) ).not.toBeNull();
	view.unmount();
} );

test( 'generate → draft with diff → accept on the server (admin app)', async () => {
	const calls = [];
	apiFetch.mockImplementation(
		routes( {
			'POST events/5/generate': ( data ) => {
				calls.push( [ 'generate', data ] );
				return DRAFT;
			},
			'POST drafts/77/accept': ( data ) => {
				calls.push( [ 'accept', data ] );
				return { draft: { ...DRAFT, status: 'accepted' }, saved: DRAFT.payload };
			},
		} )
	);
	const view = await render( <ToastProvider><Writer eventId={ 5 } /></ToastProvider> );
	await view.flush();
	const notes = view.$( 'textarea' );
	await change( notes, 'Trio from Leeds' );
	await click( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ) );
	await view.flush();
	expect( calls[ 0 ][ 1 ] ).toMatchObject( { kind: 'description', options: { notes: 'Trio from Leeds', tone: 'friendly' } } );
	expect( view.queryByText( 'Not saved yet' ) ).not.toBeNull();
	expect( view.$( 'ins' ) ).not.toBeNull();
	await click( view.getByText( 'Accept' ).closest( 'button' ) );
	await view.flush();
	expect( calls[ 1 ] ).toEqual( [ 'accept', expect.objectContaining( { mode: 'server', apply: 'replace', force: false } ) ] );
	view.unmount();
} );

test( 'in an editor the accepted description goes through the bridge (client mode)', async () => {
	const bridge = { mode: 'block', applyDescription: jest.fn( () => true ), insertFaq: jest.fn(), syncSeo: jest.fn() };
	let sent = null;
	apiFetch.mockImplementation(
		routes( {
			'GET events/5': () => ( { ...SNAP, pending: [ DRAFT ] } ),
			'POST drafts/77/accept': ( data ) => {
				sent = data;
				return { blocks: '<!-- wp:paragraph -->', html: '<p>x</p>', excerpt: 'A night of jazz.', draft: DRAFT, saved: DRAFT.payload };
			},
		} )
	);
	const view = await render( <ToastProvider><Writer eventId={ 5 } bridge={ bridge } compact /></ToastProvider> );
	await view.flush();
	await click( view.getByText( 'Add after the current text' ).closest( 'label' ).querySelector( 'input' ) );
	await click( view.getByText( 'Accept & insert' ).closest( 'button' ) );
	await view.flush();
	expect( sent ).toMatchObject( { mode: 'client', apply: 'append' } );
	expect( bridge.applyDescription ).toHaveBeenCalledWith( expect.objectContaining( { blocks: '<!-- wp:paragraph -->' } ), 'append' );
	view.unmount();
} );

test( 'a stale draft asks before accepting, then forces', async () => {
	let n = 0;
	apiFetch.mockImplementation(
		routes( {
			'GET events/5': () => ( { ...SNAP, pending: [ DRAFT ] } ),
			'POST drafts/77/accept': ( data ) => {
				n++;
				if ( ! data.force ) {
					return Promise.reject( { code: 'dev3ec_stale', message: 'changed' } );
				}
				return { draft: DRAFT, saved: {} };
			},
		} )
	);
	const view = await render( <ToastProvider><Writer eventId={ 5 } /></ToastProvider> );
	await view.flush();
	await click( view.getByText( 'Accept' ).closest( 'button' ) );
	await view.flush();
	expect( view.queryByText( 'The event changed' ) ).not.toBeNull();
	await click( view.getByText( 'Accept anyway' ).closest( 'button' ) );
	await view.flush();
	expect( n ).toBe( 2 );
	view.unmount();
} );

test( 'social drafts show live counters and block accept when over the limit', async () => {
	const social = { id: 80, kind: 'social', status: 'pending', source: 'ai', payload: { x: 'a'.repeat( 290 ), linkedin: 'Join us' }, original: {} };
	apiFetch.mockImplementation( routes( { 'GET events/5': () => ( { ...SNAP, pending: [ social ] } ) } ) );
	const view = await render( <ToastProvider><Writer eventId={ 5 } /></ToastProvider> );
	await view.flush();
	await click( view.getByText( 'Social' ).closest( 'button' ) );
	await view.flush();
	expect( view.queryByText( '290 / 280' ) ).not.toBeNull();
	expect( view.queryByText( '7 / 3000' ) ).not.toBeNull();
	expect( view.getByText( 'Accept' ).closest( 'button' ).disabled ).toBe( true );
	view.unmount();
} );

test( 'without a key the AI button is off but templates work', async () => {
	let asked = null;
	apiFetch.mockImplementation(
		routes( {
			'GET status': () => ( { ...STATUS, ai: { enabled: true, key_set: false } } ),
			'POST events/5/template': ( data ) => {
				asked = data;
				return { ...DRAFT, source: 'template' };
			},
		} )
	);
	const view = await render( <ToastProvider><Writer eventId={ 5 } /></ToastProvider> );
	await view.flush();
	expect( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ).disabled ).toBe( true );
	await click( view.getByText( 'Use template' ).closest( 'button' ) );
	await view.flush();
	expect( asked.kind ).toBe( 'description' );
	expect( view.queryByText( 'Template draft' ) ).not.toBeNull();
	view.unmount();
} );

test( 'review queue lists drafts and bulk-accepts the selection', async () => {
	let bulk = null;
	apiFetch.mockImplementation(
		routes( {
			'GET drafts': () => ( { items: [ { ...DRAFT, event_title: 'Jazz Night', created_at: '2026-10-25T10:00:00Z' } ], total: 1 } ),
			'POST drafts/bulk': ( data ) => {
				bulk = data;
				return { done: 1, errors: [] };
			},
		} )
	);
	const view = await render( <ToastProvider><ReviewTab onChanged={ () => {} } /></ToastProvider> );
	await view.flush();
	expect( view.queryByText( 'Jazz Night' ) ).not.toBeNull();
	await click( view.getByText( 'Select page' ).closest( '.dev3ec-ui-check' ).querySelector( 'input' ) );
	await click( view.getByText( 'Accept 1' ).closest( 'button' ) );
	await view.flush();
	expect( bulk ).toEqual( { ids: [ 77 ], action: 'accept' } );
	view.unmount();
} );
