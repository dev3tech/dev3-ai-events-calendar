/**
 * Output kinds: labels and review fields.
 */
import { __ } from '@wordpress/i18n';

export const KIND_LABELS = {
	description: __( 'Description', 'dev3-ai-events-calendar' ),
	social: __( 'Social posts', 'dev3-ai-events-calendar' ),
	email_promo: __( 'Promo email', 'dev3-ai-events-calendar' ),
	email_reminder: __( 'Reminder email', 'dev3-ai-events-calendar' ),
	email_recap: __( 'Recap email', 'dev3-ai-events-calendar' ),
	faq: __( 'FAQ', 'dev3-ai-events-calendar' ),
	seo: __( 'SEO meta', 'dev3-ai-events-calendar' ),
};

export const KINDS = Object.keys( KIND_LABELS );

export const EMAIL_KINDS = [ 'email_promo', 'email_reminder', 'email_recap' ];

const EMAIL_FIELDS = [
	{ key: 'subject', label: __( 'Subject', 'dev3-ai-events-calendar' ), maxLength: 120 },
	{ key: 'preheader', label: __( 'Preheader (inbox preview)', 'dev3-ai-events-calendar' ), maxLength: 160 },
	{ key: 'body', label: __( 'Body', 'dev3-ai-events-calendar' ), multiline: true, maxLength: 8000 },
];

/**
 * Review fields of a kind.
 *
 * @param {string} kind    Kind.
 * @param {Object} payload Draft payload (social: network keys).
 * @return {Array} Fields.
 */
export function fieldsFor( kind, payload = {} ) {
	switch ( kind ) {
		case 'description':
			return [
				{ key: 'excerpt', label: __( 'Excerpt (event listings)', 'dev3-ai-events-calendar' ), multiline: true, maxLength: 400 },
				{ key: 'body', label: __( 'Description (headings start with ##)', 'dev3-ai-events-calendar' ), multiline: true, maxLength: 8000 },
			];
		case 'faq':
			return [ { key: 'faq', label: __( 'Questions and answers (Q: / A:)', 'dev3-ai-events-calendar' ), multiline: true, maxLength: 8000 } ];
		case 'seo':
			return [
				{ key: 'title', label: __( 'SEO title', 'dev3-ai-events-calendar' ), maxLength: 70 },
				{ key: 'description', label: __( 'Meta description', 'dev3-ai-events-calendar' ), multiline: true, maxLength: 170 },
			];
		case 'social':
			return Object.keys( payload || {} ).map( ( k ) => ( { key: k, label: k, multiline: true } ) );
	}
	return EMAIL_KIND_SET.has( kind ) ? EMAIL_FIELDS : [];
}

const EMAIL_KIND_SET = new Set( EMAIL_KINDS );

/**
 * Parses the FAQ text format into items.
 *
 * @param {string} text Text.
 * @return {Array} [ { q, a } ].
 */
export function parseFaq( text ) {
	const items = [];
	let cur = null;
	String( text || '' )
		.split( /\r?\n/ )
		.forEach( ( line ) => {
			const t = line.trim();
			const q = t.match( /^(?:Q|Question)\s*[:.)]\s*(.+)$/i );
			const a = t.match( /^(?:A|Answer)\s*[:.)]\s*(.*)$/i );
			if ( q ) {
				if ( cur && cur.q ) {
					items.push( cur );
				}
				cur = { q: q[ 1 ], a: '' };
			} else if ( cur && a ) {
				cur.a = `${ cur.a } ${ a[ 1 ] }`.trim();
			} else if ( cur && t ) {
				cur.a = `${ cur.a } ${ t }`.trim();
			}
		} );
	if ( cur && cur.q ) {
		items.push( cur );
	}
	return items.filter( ( it ) => it.q && it.a );
}

/**
 * Whether a fields object is empty.
 *
 * @param {Object} v Fields.
 * @return {boolean} Empty.
 */
export const isEmpty = ( v ) => ! v || Object.values( v ).every( ( s ) => ! String( s || '' ).trim() );

export const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-events-calendar' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-events-calendar' ) },
	{ value: 'exciting', label: __( 'Exciting', 'dev3-ai-events-calendar' ) },
	{ value: 'warm', label: __( 'Warm', 'dev3-ai-events-calendar' ) },
	{ value: 'concise', label: __( 'Concise', 'dev3-ai-events-calendar' ) },
	{ value: 'formal', label: __( 'Formal', 'dev3-ai-events-calendar' ) },
	{ value: 'playful', label: __( 'Playful', 'dev3-ai-events-calendar' ) },
];
