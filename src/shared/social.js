/**
 * Social networks and counting rules (mirror of includes/Content/Social.php).
 */
import { __ } from '@wordpress/i18n';

export const NETWORKS = {
	x: { label: 'X (Twitter)', limit: 280, ideal: 240, url23: true, links: true },
	facebook: { label: 'Facebook', limit: 2000, ideal: 500, url23: false, links: true },
	instagram: { label: 'Instagram', limit: 2200, ideal: 1000, url23: false, links: false },
	linkedin: { label: 'LinkedIn', limit: 3000, ideal: 1300, url23: false, links: true },
	threads: { label: 'Threads', limit: 500, ideal: 400, url23: false, links: true },
	bluesky: { label: 'Bluesky', limit: 300, ideal: 280, url23: false, links: true },
	mastodon: { label: 'Mastodon', limit: 500, ideal: 400, url23: true, links: true },
};

const URL_RE = /https?:\/\/[^\s<>"']+/gi;

/**
 * Characters as the network counts them (graphemes approximated by code points).
 *
 * @param {string} text    Text.
 * @param {string} network Network id.
 * @return {number} Length.
 */
export function socialLength( text, network ) {
	let t = String( text || '' );
	const n = NETWORKS[ network ];
	if ( n && n.url23 ) {
		t = t.replace( URL_RE, 'x'.repeat( 23 ) );
	}
	return Array.from( t ).length;
}

/**
 * Counter state for a post.
 *
 * @param {string} text    Text.
 * @param {string} network Network id.
 * @return {Object} { length, limit, ideal, tone: success|warning|danger, label }
 */
export function counter( text, network ) {
	const n = NETWORKS[ network ] || { limit: 280, ideal: 240 };
	const length = socialLength( text, network );
	let tone = 'success';
	if ( length > n.limit ) {
		tone = 'danger';
	} else if ( length > n.ideal ) {
		tone = 'warning';
	}
	return { length, limit: n.limit, ideal: n.ideal, tone, label: `${ length } / ${ n.limit }` };
}

/**
 * Share-intent URL for a post (networks with a public share endpoint).
 *
 * @param {string} network Network.
 * @param {string} text    Text.
 * @param {string} url     Event URL.
 * @return {string} URL or ''.
 */
export function shareUrl( network, text, url ) {
	const t = encodeURIComponent( text || '' );
	const u = encodeURIComponent( url || '' );
	switch ( network ) {
		case 'x':
			return `https://x.com/intent/post?text=${ t }`;
		case 'facebook':
			return `https://www.facebook.com/sharer/sharer.php?u=${ u }`;
		case 'linkedin':
			return `https://www.linkedin.com/sharing/share-offsite/?url=${ u }`;
		case 'threads':
			return `https://www.threads.net/intent/post?text=${ t }`;
		case 'bluesky':
			return `https://bsky.app/intent/compose?text=${ t }`;
	}
	return '';
}

export const networkLabel = ( id ) => ( NETWORKS[ id ] ? NETWORKS[ id ].label : id );

export const overLimitText = __( 'Over the limit: shorten it before posting.', 'dev3-ai-events-calendar' );
