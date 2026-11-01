import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, EmptyState, ErrorNotice, PageHeader, SkeletonCard } from '../core3/ui';
import Writer from '../shared/Writer';

/**
 * One event in the admin app: the same writer panels as in the editor; descriptions are saved
 * into the event server side on Accept.
 *
 * @param {Object}   props         Props.
 * @param {number}   props.eventId Event.
 * @param {Function} props.onUsage After AI.
 * @return {Element} Workspace.
 */
export default function EventWorkspace( { eventId, onUsage } ) {
	const [ event, setEvent ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		if ( ! eventId ) {
			return;
		}
		setError( null );
		setEvent( null );
		request( `events/${ eventId }` )
			.then( ( r ) => setEvent( r.event ) )
			.catch( setError );
	}, [ eventId ] );
	if ( ! eventId ) {
		return <EmptyState icon="inbox" title={ __( 'Pick an event', 'dev3-ai-events-calendar' ) } action={ <Button href="#/events">{ __( 'All events', 'dev3-ai-events-calendar' ) }</Button> } />;
	}
	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! event ) {
		return <SkeletonCard lines={ 6 } />;
	}
	return (
		<>
			<PageHeader
				back={ { href: '#/events', label: __( 'Events', 'dev3-ai-events-calendar' ) } }
				eyebrow={ __( 'Event', 'dev3-ai-events-calendar' ) }
				title={ event.title }
				actions={
					<>
						<Button icon="external" href={ event.url } target="_blank" rel="noopener noreferrer">
							{ __( 'View', 'dev3-ai-events-calendar' ) }
						</Button>
						<Button icon="edit" href={ event.edit_url }>
							{ __( 'Edit in TEC', 'dev3-ai-events-calendar' ) }
						</Button>
					</>
				}
			/>
			<Card>
				<Writer eventId={ eventId } onUsage={ onUsage } />
			</Card>
		</>
	);
}
