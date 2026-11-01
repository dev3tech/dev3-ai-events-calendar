/**
 * Keeps Yoast SEO / Rank Math editor state in sync with SEO values DEV3 saved on the server, so a
 * later "Update" does not overwrite them with the old values.
 */
import { dispatch, select } from '@wordpress/data';

const call = ( obj, name, ...args ) => {
	if ( obj && typeof obj[ name ] === 'function' ) {
		obj[ name ]( ...args );
		return true;
	}
	return false;
};

const store = ( name ) => {
	try {
		return select( name ) ? dispatch( name ) : null;
	} catch ( e ) {
		return null;
	}
};

/**
 * Pushes { title, description } into the SEO plugin's store and hidden form fields.
 *
 * @param {Object} saved { title, description }.
 * @param {Document} doc Document (tests).
 * @return {boolean} Whether anything was updated.
 */
export function syncSeo( saved, doc = typeof document !== 'undefined' ? document : null ) {
	const title = ( saved && saved.title ) || '';
	const description = ( saved && saved.description ) || '';
	let done = false;
	const yoast = store( 'yoast-seo/editor' );
	if ( yoast ) {
		done = call( yoast, 'updateData', { title, description } ) || done;
	}
	const rm = store( 'rank-math' );
	if ( rm ) {
		call( rm, 'updateSerpTitle', title );
		call( rm, 'updateSerpDescription', description );
		done = call( rm, 'updateTitle', title ) || done;
		done = call( rm, 'updateDescription', description ) || done;
	}
	if ( doc ) {
		[
			[ 'yoast_wpseo_title', title ],
			[ 'yoast_wpseo_metadesc', description ],
			[ 'rank_math_title', title ],
			[ 'rank_math_description', description ],
		].forEach( ( [ name, value ] ) => {
			doc.querySelectorAll( `input[name="${ name }"], textarea[name="${ name }"], #${ name }` ).forEach( ( el ) => {
				el.value = value;
				done = true;
			} );
		} );
	}
	return done;
}
