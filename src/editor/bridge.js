/**
 * Block editor bridge: inserts accepted descriptions and FAQs as native blocks, keeping The Events
 * Calendar's own blocks (date, venue, organizer, tickets…) in place.
 */
import { createBlock, parse } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { syncSeo } from '../shared/seoSync';

export const TEXT_BLOCKS = [ 'core/paragraph', 'core/heading', 'core/list', 'core/quote' ];

/**
 * Plan for placing new description blocks among the current top-level blocks.
 *
 * @param {Array}  blocks Current blocks ({ name, clientId }).
 * @param {string} mode   replace|append.
 * @return {Object} { remove: clientId[], index }
 */
export function planDescription( blocks, mode = 'replace' ) {
	const remove = [];
	let first = -1;
	let kept = 0;
	let anchor = 0;
	let lastText = -1;
	blocks.forEach( ( b ) => {
		const isText = TEXT_BLOCKS.includes( b.name );
		if ( isText && mode === 'replace' ) {
			remove.push( b.clientId );
			if ( first < 0 ) {
				first = kept;
			}
			return;
		}
		kept++;
		if ( b.name === 'tribe/event-datetime' || b.name === 'tribe/featured-image' ) {
			anchor = kept;
		}
		if ( isText ) {
			lastText = kept;
		}
	} );
	let index = first >= 0 ? first : anchor;
	if ( mode === 'append' && lastText >= 0 ) {
		index = lastText;
	}
	return { remove, index };
}

/**
 * Applies an accepted description ({ blocks, excerpt }) to the editor.
 *
 * @param {Object} result Accept response.
 * @param {string} mode   replace|append.
 * @return {boolean} Applied.
 */
export function applyDescription( result, mode = 'replace' ) {
	const be = dispatch( 'core/block-editor' );
	const current = select( 'core/block-editor' ).getBlocks();
	const plan = planDescription( current, mode );
	const blocks = parse( result.blocks || '' ).filter( ( b ) => b && b.name );
	if ( plan.remove.length ) {
		be.removeBlocks( plan.remove, false );
	}
	if ( blocks.length ) {
		be.insertBlocks( blocks, plan.index );
	}
	if ( typeof result.excerpt === 'string' && result.excerpt ) {
		dispatch( 'core/editor' ).editPost( { excerpt: result.excerpt } );
	}
	return true;
}

/**
 * Inserts the FAQ as a heading + core/details blocks after the description.
 *
 * @param {Array} items [ { q, a } ].
 * @return {boolean} Inserted.
 */
export function insertFaq( items ) {
	const esc = ( s ) => String( s || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	const blocks = [ createBlock( 'core/heading', { level: 2, content: esc( __( 'Frequently asked questions', 'dev3-ai-events-calendar' ) ) } ) ];
	items.forEach( ( it ) => {
		blocks.push( createBlock( 'core/details', { summary: esc( it.q ), className: 'dev3ec-faq-item' }, [ createBlock( 'core/paragraph', { content: esc( it.a ) } ) ] ) );
	} );
	const current = select( 'core/block-editor' ).getBlocks();
	let index = current.length;
	current.forEach( ( b, i ) => {
		if ( TEXT_BLOCKS.includes( b.name ) || b.name === 'core/details' ) {
			index = i + 1;
		}
	} );
	dispatch( 'core/block-editor' ).insertBlocks( blocks, index );
	return true;
}

export default { mode: 'block', applyDescription, insertFaq, syncSeo };
