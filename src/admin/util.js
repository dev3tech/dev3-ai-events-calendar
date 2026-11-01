import { __ } from '@wordpress/i18n';

/**
 * Badge for an import status.
 *
 * @param {string} s Status.
 * @return {Object} { tone, label }
 */
export function importStatus( s ) {
	const map = {
		queued: { tone: 'info', label: __( 'Reading…', 'dev3-ai-events-calendar' ) },
		ready: { tone: 'warning', label: __( 'Ready to review', 'dev3-ai-events-calendar' ) },
		failed: { tone: 'danger', label: __( 'Failed', 'dev3-ai-events-calendar' ) },
		created: { tone: 'success', label: __( 'Event created', 'dev3-ai-events-calendar' ) },
	};
	return map[ s ] || { tone: 'neutral', label: s };
}

/**
 * Badge for a draft status.
 *
 * @param {string} s Status.
 * @return {Object} { tone, label }
 */
export function draftStatus( s ) {
	const map = {
		pending: { tone: 'warning', label: __( 'Pending', 'dev3-ai-events-calendar' ) },
		accepted: { tone: 'success', label: __( 'Accepted', 'dev3-ai-events-calendar' ) },
		rejected: { tone: 'neutral', label: __( 'Rejected', 'dev3-ai-events-calendar' ) },
	};
	return map[ s ] || { tone: 'neutral', label: s };
}

/**
 * Short local date-time of an ISO string.
 *
 * @param {string} iso ISO date.
 * @return {string} Label.
 */
export function when( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? '' : d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
}

/**
 * Event ids preselected through the TEC bulk action (?dev3ec_ids=1,2).
 *
 * @param {string} search location.search.
 * @return {number[]} Ids.
 */
export function preselected( search = typeof window !== 'undefined' ? window.location.search : '' ) {
	const m = /[?&]dev3ec_ids=([0-9,]+)/.exec( search );
	return m ? m[ 1 ].split( ',' ).map( Number ).filter( Boolean ) : [];
}
