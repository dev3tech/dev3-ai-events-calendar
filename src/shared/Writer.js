import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { ErrorNotice, Icon, SkeletonCard, Tabs, cx } from '../core3/ui';
import KindPanel from './KindPanel';
import { SaveFirst } from './saved';

let statusPromise = null;

/**
 * Status payload, fetched once per page.
 *
 * @param {boolean} fresh Refetch.
 * @return {Promise<Object>} Status.
 */
export function loadStatus( fresh = false ) {
	if ( ! statusPromise || fresh ) {
		statusPromise = request( 'status' ).catch( ( e ) => {
			statusPromise = null;
			throw e;
		} );
	}
	return statusPromise;
}

/** Test helper. */
export const __resetStatus = () => {
	statusPromise = null;
};

const GROUPS = [
	{ id: 'description', label: __( 'Description', 'dev3-ai-events-calendar' ), kinds: [ 'description' ] },
	{ id: 'social', label: __( 'Social', 'dev3-ai-events-calendar' ), kinds: [ 'social' ] },
	{ id: 'emails', label: __( 'Emails', 'dev3-ai-events-calendar' ), kinds: [ 'email_promo', 'email_reminder', 'email_recap' ] },
	{ id: 'faq', label: __( 'FAQ', 'dev3-ai-events-calendar' ), kinds: [ 'faq' ] },
	{ id: 'seo', label: __( 'SEO', 'dev3-ai-events-calendar' ), kinds: [ 'seo' ] },
];

const EMAIL_TABS = [
	{ id: 'email_promo', label: __( 'Promo', 'dev3-ai-events-calendar' ) },
	{ id: 'email_reminder', label: __( 'Reminder', 'dev3-ai-events-calendar' ) },
	{ id: 'email_recap', label: __( 'Recap', 'dev3-ai-events-calendar' ) },
];

/**
 * Short facts line of an event.
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.snap    Snapshot.
 * @param {boolean} props.compact Compact.
 * @return {Element} Facts.
 */
export function EventFacts( { snap, compact } ) {
	const e = snap.event;
	const a = snap.attendees || {};
	return (
		<ul className={ cx( 'dev3ec-facts', compact && 'is-compact' ) } aria-label={ __( 'Event details DEV3 AI uses', 'dev3-ai-events-calendar' ) }>
			<li>
				<Icon name="clock" size={ 14 } />
				<span>{ e.date_label ? `${ e.date_label } · ${ e.time_label }` : __( 'No date saved yet', 'dev3-ai-events-calendar' ) }</span>
			</li>
			<li>
				<Icon name="box" size={ 14 } />
				<span>{ e.venue ? e.venue.name : __( 'No venue', 'dev3-ai-events-calendar' ) }</span>
			</li>
			{ e.organizers && e.organizers.length > 0 && (
				<li>
					<Icon name="users" size={ 14 } />
					<span>{ e.organizers.map( ( o ) => o.name ).join( ', ' ) }</span>
				</li>
			) }
			{ e.cost && (
				<li>
					<Icon name="bolt" size={ 14 } />
					<span>{ e.cost }</span>
				</li>
			) }
			{ a.source === 'event-tickets' && (
				<li>
					<Icon name="check" size={ 14 } />
					<span>{ sprintf( /* translators: 1: going, 2: checked in */ __( '%1$d attending · %2$d checked in', 'dev3-ai-events-calendar' ), a.going || 0, a.checked_in || 0 ) }</span>
				</li>
			) }
		</ul>
	);
}

/**
 * The writer panels for one event (used by the block editor sidebar, the classic metabox and the
 * admin app's event workspace).
 *
 * @param {Object}   props         Props.
 * @param {number}   props.eventId Event.
 * @param {Object}   props.bridge  Editor bridge or null.
 * @param {boolean}  props.compact Sidebar layout.
 * @param {number}   props.refreshKey Reload trigger (e.g. after the editor saved).
 * @param {Function} props.onUsage After AI calls.
 * @return {Element} Writer.
 */
export default function Writer( { eventId, bridge = null, compact = false, refreshKey = 0, onUsage } ) {
	const [ snap, setSnap ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ group, setGroup ] = useState( 'description' );
	const [ email, setEmail ] = useState( 'email_promo' );

	const load = () => {
		if ( ! eventId ) {
			return;
		}
		setError( null );
		request( `events/${ eventId }` ).then( setSnap ).catch( setError );
	};
	useEffect( load, [ eventId, refreshKey ] ); // eslint-disable-line react-hooks/exhaustive-deps
	useEffect( () => {
		loadStatus()
			.then( setStatus )
			.catch( () => setStatus( { ai: { enabled: false, key_set: false }, defaults: {} } ) );
	}, [] );

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! snap || ! status ) {
		return <SkeletonCard lines={ 4 } />;
	}
	const pendingBy = ( kinds ) => ( snap.pending || [] ).filter( ( d ) => kinds.includes( d.kind ) ).length;
	const tabs = GROUPS.map( ( g ) => {
		const n = pendingBy( g.kinds );
		return { id: g.id, label: g.label, badge: n || null };
	} );
	const kind = group === 'emails' ? email : GROUPS.find( ( g ) => g.id === group ).kinds[ 0 ];
	const total = ( snap.pending || [] ).length;

	return (
		<div className={ cx( 'dev3ec-writer', compact && 'is-compact' ) }>
			<EventFacts snap={ snap } compact={ compact } />
			<SaveFirst event={ snap.event } />
			{ total > 0 && compact && <p className="dev3ec-help">{ sprintf( /* translators: %d: drafts */ _n( '%d draft waits for review.', '%d drafts wait for review.', total, 'dev3-ai-events-calendar' ), total ) }</p> }
			<Tabs className="dev3ec-tabs-scroll" tabs={ tabs } value={ group } onChange={ setGroup } label={ __( 'Output', 'dev3-ai-events-calendar' ) } variant={ compact ? 'pills' : 'line' }>
				<div className="dev3ec-writer__panel">
				{ group === 'emails' && (
					<Tabs
						className="dev3ec-tabs-scroll dev3ec-subtabs"
						tabs={ EMAIL_TABS.map( ( t ) => ( { ...t, badge: pendingBy( [ t.id ] ) || null } ) ) }
						value={ email }
						onChange={ setEmail }
						variant="pills"
						label={ __( 'Email', 'dev3-ai-events-calendar' ) }
					/>
				) }
				{ kind === 'email_recap' && snap.attendees && snap.attendees.source !== 'event-tickets' && (
					<p className="dev3ec-help">{ __( 'Install Event Tickets (free) to include attendance numbers in the recap and email attendees.', 'dev3-ai-events-calendar' ) }</p>
				) }
					<KindPanel key={ kind } kind={ kind } snap={ snap } status={ status } bridge={ bridge } compact={ compact } reload={ load } onUsage={ onUsage } />
				</div>
			</Tabs>
		</div>
	);
}
