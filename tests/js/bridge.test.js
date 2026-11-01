/* Editor bridges with the WordPress data/blocks packages mocked (they are externals at runtime). */
const store = { blocks: [], removed: [], inserted: [], edits: [], yoast: [] };
jest.mock(
	'@wordpress/data',
	() => ( {
		select: ( name ) => {
			if ( name === 'core/block-editor' ) {
				return { getBlocks: () => store.blocks };
			}
			return name === 'yoast-seo/editor' ? {} : null;
		},
		dispatch: ( name ) => {
			if ( name === 'core/block-editor' ) {
				return {
					removeBlocks: ( ids ) => store.removed.push( ...ids ),
					insertBlocks: ( blocks, index ) => store.inserted.push( { blocks, index } ),
				};
			}
			if ( name === 'core/editor' ) {
				return { editPost: ( e ) => store.edits.push( e ) };
			}
			if ( name === 'yoast-seo/editor' ) {
				return { updateData: ( d ) => store.yoast.push( d ) };
			}
			return {};
		},
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/blocks',
	() => ( {
		parse: ( html ) => ( html ? [ { name: 'core/heading' }, { name: 'core/paragraph' } ] : [] ),
		createBlock: ( name, attrs, inner ) => ( { name, attrs, inner } ),
	} ),
	{ virtual: true }
);

import { applyDescription, insertFaq, planDescription } from '../../src/editor/bridge';
import classic from '../../src/classic/bridge';
import { syncSeo } from '../../src/shared/seoSync';

const tec = [
	{ name: 'tribe/event-datetime', clientId: 'a' },
	{ name: 'core/paragraph', clientId: 'b' },
	{ name: 'tribe/event-price', clientId: 'c' },
	{ name: 'core/heading', clientId: 'd' },
	{ name: 'core/image', clientId: 'e' },
	{ name: 'tribe/event-venue', clientId: 'f' },
];

beforeEach( () => {
	store.blocks = tec;
	store.removed = [];
	store.inserted = [];
	store.edits = [];
	store.yoast = [];
} );

test( 'replace keeps TEC and media blocks and inserts where the text was', () => {
	expect( planDescription( tec, 'replace' ) ).toEqual( { remove: [ 'b', 'd' ], index: 1 } );
} );

test( 'append goes after the last text block', () => {
	expect( planDescription( tec, 'append' ) ).toEqual( { remove: [], index: 4 } );
} );

test( 'empty event: after the date block', () => {
	expect( planDescription( [ { name: 'tribe/event-datetime', clientId: 'a' }, { name: 'tribe/event-venue', clientId: 'b' } ] ) ).toEqual( { remove: [], index: 1 } );
} );

test( 'applyDescription removes old text, inserts blocks and sets the excerpt', () => {
	applyDescription( { blocks: '<!-- wp:heading -->', excerpt: 'Short.' }, 'replace' );
	expect( store.removed ).toEqual( [ 'b', 'd' ] );
	expect( store.inserted[ 0 ].index ).toBe( 1 );
	expect( store.inserted[ 0 ].blocks ).toHaveLength( 2 );
	expect( store.edits ).toEqual( [ { excerpt: 'Short.' } ] );
} );

test( 'insertFaq adds a heading and details blocks after the description', () => {
	insertFaq( [ { q: 'When?', a: 'Friday <b>' } ] );
	const { blocks, index } = store.inserted[ 0 ];
	expect( index ).toBe( 4 );
	expect( blocks[ 1 ].name ).toBe( 'core/details' );
	expect( blocks[ 1 ].inner[ 0 ].attrs.content ).toBe( 'Friday &lt;b&gt;' );
} );

test( 'classic bridge writes the Text tab and the excerpt box', () => {
	document.body.innerHTML = '<textarea id="content">old</textarea><textarea id="excerpt"></textarea>';
	expect( classic.applyDescription( { html: '<p>New</p>', excerpt: 'Ex' }, 'replace', window ) ).toBe( true );
	expect( document.getElementById( 'content' ).value ).toBe( '<p>New</p>' );
	expect( document.getElementById( 'excerpt' ).value ).toBe( 'Ex' );
	classic.applyDescription( { html: '<p>More</p>' }, 'append', window );
	expect( document.getElementById( 'content' ).value ).toBe( '<p>New</p>\n\n<p>More</p>' );
} );

test( 'classic bridge uses TinyMCE when the visual tab is active', () => {
	const ed = { html: '<p>A</p>', isHidden: () => false, getContent() { return this.html; }, setContent( h ) { this.html = h; }, save: jest.fn() };
	window.tinymce = { get: () => ed };
	document.body.innerHTML = '<textarea id="content"></textarea>';
	classic.applyDescription( { html: '<p>B</p>' }, 'append', window );
	expect( ed.html ).toBe( '<p>A</p>\n<p>B</p>' );
	expect( ed.save ).toHaveBeenCalled();
	delete window.tinymce;
} );

test( 'syncSeo updates the Yoast store and hidden fields', () => {
	document.body.innerHTML = '<input id="yoast_wpseo_title" name="yoast_wpseo_title" value="old"><input name="yoast_wpseo_metadesc" value="">';
	expect( syncSeo( { title: 'T', description: 'D' } ) ).toBe( true );
	expect( store.yoast ).toEqual( [ { title: 'T', description: 'D' } ] );
	expect( document.querySelector( '[name="yoast_wpseo_title"]' ).value ).toBe( 'T' );
	expect( document.querySelector( '[name="yoast_wpseo_metadesc"]' ).value ).toBe( 'D' );
} );
