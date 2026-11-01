import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, Inline, Modal, Notice, PageHeader, ResponsiveTable, Select, useHashRoute, useToast } from '../core3/ui';
import SocialReview from '../shared/SocialReview';
import { KINDS, KIND_LABELS, fieldsFor } from '../shared/kinds';
import { draftStatus, when } from './util';

/**
 * Review queue: every draft (bulk runs, editor generations) with diff, edit, accept and reject.
 *
 * @param {Object}   props           Props.
 * @param {Function} props.onChanged Refresh status counts.
 * @return {Element} Screen.
 */
export default function ReviewTab( { onChanged } ) {
	const toast = useToast();
	const [ route, navigate ] = useHashRoute( [ 'review' ], 'review' );
	const jobId = Number( route.params[ 0 ] ) || 0;
	const [ q, setQ ] = useState( { status: 'pending', kind: '', page: 1 } );
	const [ data, setData ] = useState( { items: [], total: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ stale, setStale ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const perPage = 20;

	const load = () => {
		setLoading( true );
		setError( null );
		const params = new URLSearchParams( { status: q.status, kind: q.kind, page: String( q.page ), per_page: String( perPage ), job_id: String( jobId ) } );
		request( `drafts?${ params.toString() }` )
			.then( ( r ) => {
				setData( r );
				setSelected( [] );
			} )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( load, [ q, jobId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const done = ( msg ) => {
		toast.success( msg );
		setOpen( null );
		setStale( null );
		load();
		onChanged();
	};
	const accept = ( value, force = false ) => {
		setBusy( 'accept' );
		request( `drafts/${ open.id }/accept`, { method: 'POST', data: { value, mode: 'server', force } } )
			.then( () => done( sprintf( /* translators: %s: output */ __( '%s saved to the event.', 'dev3-ai-events-calendar' ), KIND_LABELS[ open.kind ] ) ) )
			.catch( ( e ) => ( e.code === 'dev3ec_stale' ? setStale( value ) : toast.error( e ) ) )
			.finally( () => setBusy( '' ) );
	};
	const reject = () =>
		request( `drafts/${ open.id }/reject`, { method: 'POST' } )
			.then( () => done( __( 'Draft rejected.', 'dev3-ai-events-calendar' ) ) )
			.catch( ( e ) => toast.error( e ) );
	const bulk = ( action ) => {
		setBusy( action );
		request( 'drafts/bulk', { method: 'POST', data: { ids: selected, action } } )
			.then( ( r ) => {
				toast.success( action === 'accept' ? sprintf( /* translators: %d: drafts */ _n( '%d draft accepted.', '%d drafts accepted.', r.done, 'dev3-ai-events-calendar' ), r.done ) : sprintf( /* translators: %d: drafts */ _n( '%d draft rejected.', '%d drafts rejected.', r.done, 'dev3-ai-events-calendar' ), r.done ) );
				if ( r.errors && r.errors.length ) {
					toast.warning( r.errors[ 0 ].message );
				}
				load();
				onChanged();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const pending = q.status === 'pending';
	const columns = [
		...( pending
			? [
					{
						key: 'select',
						label: __( 'Select', 'dev3-ai-events-calendar' ),
						width: '56px',
						render: ( r ) => (
							// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
							<span onClick={ ( e ) => e.stopPropagation() }>
								<Checkbox
									className="dev3ec-hide-label"
									label={ sprintf( /* translators: 1: output, 2: event */ __( 'Select %1$s for %2$s', 'dev3-ai-events-calendar' ), KIND_LABELS[ r.kind ], r.event_title ) }
									checked={ selected.includes( r.id ) }
									onChange={ ( on ) => setSelected( on ? [ ...selected, r.id ] : selected.filter( ( x ) => x !== r.id ) ) }
								/>
							</span>
						),
					},
			  ]
			: [] ),
		{ key: 'event_title', label: __( 'Event', 'dev3-ai-events-calendar' ), primary: true },
		{ key: 'kind', label: __( 'Output', 'dev3-ai-events-calendar' ), render: ( r ) => KIND_LABELS[ r.kind ] || r.kind },
		{ key: 'source', label: __( 'By', 'dev3-ai-events-calendar' ), hideOnMobile: true, render: ( r ) => ( r.source === 'template' ? __( 'Template', 'dev3-ai-events-calendar' ) : __( 'DEV3 AI', 'dev3-ai-events-calendar' ) ) },
		{
			key: 'status',
			label: __( 'Status', 'dev3-ai-events-calendar' ),
			render: ( r ) => {
				const s = draftStatus( r.status );
				return <Badge tone={ s.tone }>{ s.label }</Badge>;
			},
		},
		{ key: 'created_at', label: __( 'Created', 'dev3-ai-events-calendar' ), hideOnMobile: true, render: ( r ) => when( r.created_at ) },
	];
	const pageIds = data.items.map( ( r ) => r.id );

	return (
		<>
			<PageHeader title={ __( 'Review', 'dev3-ai-events-calendar' ) } description={ __( 'Nothing changes on your events until you accept a draft. Open a draft to compare it with the current text and edit it.', 'dev3-ai-events-calendar' ) } />
			{ jobId > 0 && (
				<Notice tone="info" actions={ <Button size="sm" variant="ghost" onClick={ () => navigate( 'review' ) }>{ __( 'Show all drafts', 'dev3-ai-events-calendar' ) }</Button> }>
					{ sprintf( /* translators: %d: job id */ __( 'Showing drafts of bulk run #%d.', 'dev3-ai-events-calendar' ), jobId ) }
				</Notice>
			) }
			<Card>
				<FormGrid>
					<Select
						label={ __( 'Status', 'dev3-ai-events-calendar' ) }
						value={ q.status }
						onChange={ ( v ) => setQ( { ...q, status: v, page: 1 } ) }
						options={ [
							{ value: 'pending', label: __( 'Pending', 'dev3-ai-events-calendar' ) },
							{ value: 'accepted', label: __( 'Accepted', 'dev3-ai-events-calendar' ) },
							{ value: 'rejected', label: __( 'Rejected', 'dev3-ai-events-calendar' ) },
							{ value: 'all', label: __( 'All', 'dev3-ai-events-calendar' ) },
						] }
					/>
					<Select label={ __( 'Output', 'dev3-ai-events-calendar' ) } value={ q.kind } onChange={ ( v ) => setQ( { ...q, kind: v, page: 1 } ) } options={ [ { value: '', label: __( 'All outputs', 'dev3-ai-events-calendar' ) }, ...KINDS.map( ( k ) => ( { value: k, label: KIND_LABELS[ k ] } ) ) ] } />
				</FormGrid>
			</Card>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card
				flush
				title={ sprintf( /* translators: %d: drafts */ _n( '%d draft', '%d drafts', data.total, 'dev3-ai-events-calendar' ), data.total ) }
				actions={
					pending && (
						<Inline gap={ 8 }>
							<Checkbox label={ __( 'Select page', 'dev3-ai-events-calendar' ) } checked={ pageIds.length > 0 && pageIds.every( ( id ) => selected.includes( id ) ) } onChange={ ( on ) => setSelected( on ? pageIds : [] ) } />
							<Button size="sm" variant="ghost" icon="x" onClick={ () => bulk( 'reject' ) } disabled={ ! selected.length } loading={ busy === 'reject' }>
								{ __( 'Reject', 'dev3-ai-events-calendar' ) }
							</Button>
							<Button size="sm" variant="primary" icon="check" onClick={ () => bulk( 'accept' ) } disabled={ ! selected.length } loading={ busy === 'accept' }>
								{ selected.length ? sprintf( /* translators: %d: drafts */ __( 'Accept %d', 'dev3-ai-events-calendar' ), selected.length ) : __( 'Accept', 'dev3-ai-events-calendar' ) }
							</Button>
						</Inline>
					)
				}
			>
				<ResponsiveTable
					columns={ columns }
					rows={ data.items }
					loading={ loading }
					onRowClick={ ( r ) => setOpen( r ) }
					pagination={ { page: q.page, perPage, total: data.total, onPageChange: ( p ) => setQ( { ...q, page: p } ) } }
					empty={ { icon: 'check', title: pending ? __( 'No drafts waiting', 'dev3-ai-events-calendar' ) : __( 'No drafts', 'dev3-ai-events-calendar' ), description: __( 'Generate content from Events or in the event editor.', 'dev3-ai-events-calendar' ) } }
				/>
			</Card>
			<Modal open={ !! open } onClose={ () => setOpen( null ) } size="lg" title={ open ? `${ KIND_LABELS[ open.kind ] } · ${ open.event_title }` : '' }>
				{ open && open.status !== 'pending' && <Notice tone="info">{ sprintf( /* translators: %s: status */ __( 'This draft was %s.', 'dev3-ai-events-calendar' ), draftStatus( open.status ).label.toLowerCase() ) }</Notice> }
				{ open && open.kind === 'social' && (
					<SocialReview title={ __( 'Draft', 'dev3-ai-events-calendar' ) } draft={ open.payload } original={ open.original } meta={ open } onAccept={ open.status === 'pending' ? accept : () => {} } onReject={ open.status === 'pending' ? reject : null } accepting={ busy === 'accept' } />
				) }
				{ open && open.kind !== 'social' && (
					<AiDraftReview
						title={ open.source === 'template' ? __( 'Template draft', 'dev3-ai-events-calendar' ) : __( 'DEV3 AI draft', 'dev3-ai-events-calendar' ) }
						fields={ fieldsFor( open.kind, open.payload ) }
						draft={ open.payload }
						original={ open.original }
						meta={ { model: open.model, tokens: open.tokens } }
						onAccept={ open.status === 'pending' ? accept : () => setOpen( null ) }
						onReject={ open.status === 'pending' ? reject : null }
						accepting={ busy === 'accept' }
						editable={ open.status === 'pending' }
						acceptLabel={ open.status === 'pending' ? __( 'Accept', 'dev3-ai-events-calendar' ) : __( 'Close', 'dev3-ai-events-calendar' ) }
					/>
				) }
			</Modal>
			<Modal
				open={ !! stale }
				onClose={ () => setStale( null ) }
				size="sm"
				title={ __( 'The event changed', 'dev3-ai-events-calendar' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setStale( null ) }>
							{ __( 'Cancel', 'dev3-ai-events-calendar' ) }
						</Button>
						<Button variant="primary" onClick={ () => accept( stale, true ) } loading={ busy === 'accept' } data-autofocus>
							{ __( 'Accept anyway', 'dev3-ai-events-calendar' ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'The event was edited after this draft was written, so the draft may describe old details.', 'dev3-ai-events-calendar' ) }</p>
			</Modal>
		</>
	);
}
