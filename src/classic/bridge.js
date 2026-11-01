/**
 * Classic editor bridge: writes accepted descriptions into TinyMCE (or the Text tab) and the
 * excerpt box, and keeps SEO plugin fields in sync.
 */
import { syncSeo } from '../shared/seoSync';

/**
 * Active TinyMCE editor for the post content (visual tab), if any.
 *
 * @param {Window} win Window.
 * @return {Object|null} Editor.
 */
export function visualEditor( win = window ) {
	const ed = win.tinymce && win.tinymce.get ? win.tinymce.get( 'content' ) : null;
	return ed && ! ed.isHidden() ? ed : null;
}

/**
 * Applies an accepted description ({ html, excerpt }).
 *
 * @param {Object} result Accept response.
 * @param {string} mode   replace|append.
 * @param {Window} win    Window (tests).
 * @return {boolean} Applied.
 */
export function applyDescription( result, mode = 'replace', win = window ) {
	const html = result.html || '';
	const ed = visualEditor( win );
	const area = win.document.getElementById( 'content' );
	if ( ed ) {
		ed.setContent( mode === 'append' ? `${ ed.getContent() }\n${ html }` : html );
		ed.save();
	} else if ( area ) {
		area.value = mode === 'append' && area.value.trim() ? `${ area.value }\n\n${ html }` : html;
	} else {
		return false;
	}
	const excerpt = win.document.getElementById( 'excerpt' );
	if ( excerpt && typeof result.excerpt === 'string' && result.excerpt ) {
		excerpt.value = result.excerpt;
	}
	return true;
}

export default { mode: 'classic', applyDescription, insertFaq: null, syncSeo };
