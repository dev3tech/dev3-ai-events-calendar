import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, Drawer, ErrorNotice, FormGrid, Inline, JobProgress, Notice, PageHeader, ResponsiveTable, Select, Stack, TextField, Textarea, Toggle, useToast } from '../core3/ui';
import { KINDS, KIND_LABELS, TONES } from '../shared/kinds';
import { NETWORKS } from '../shared/social';
import { preselected } from './util';

const SHORT = {
	description: __( 'Desc', 'dev3-ai-events-calendar' ),
	social: __( 'Social', 'dev3-ai-events-calendar' ),
	email_promo: __( 'Promo', 'dev3-ai-events-calendar' ),
	email_reminder: __( 'Remind', 'dev3-ai-events-calendar' ),
	email_recap: __( 'Recap', 'dev3-ai-events-calendar' ),
	faq: __( 'FAQ', 'dev3-ai-events-calendar' ),
	seo: __( 'SEO', 'dev3-ai-events-calendar' ),
};

/**
 * Coverage chips: which outputs an event already has.
 *
 * @param {Object} props     Props.
 * @param {Object} props.has kind => bool.
 * @return {Element} Chips.
 */
function Coverage( { has } ) {
	return (
		<ul className="dev3ec-chips" aria-label={ __( 'Content coverage', 'dev3-ai-events-calendar' ) }>
			{ KINDS.map( ( k ) => (
				<li key={ k } className={ has && has[ k ] ? 'is-on' : '' }>
					<span aria-hidden="true">{ SHORT[ k ] }</span>
					<span className="dev3ec-sr">{ `${ KIND_LABELS[ k ] }: ${ has && has[ k ] ? __( 'done', 'dev3-ai-events-calendar' ) : __( 'missing', 'dev3-ai-events-calendar' ) }` }</span>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Bulk screen: TEC events with filters, selection and a background "Generate with DEV3 AI" run.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.status    Status.
 * @param {Function} props.navigate  Router.
 * @param {Function} props.onUsage   After AI.
 * @param {Function} props.onChanged Refresh status.
 * @return {Element} Screen.
 */
export default function EventsTab( { status, navigate, onUsage, onChanged } ) {
	const toast = useToast();
	const initial = preselected();
	const [ q, setQ ] = useState( { scope: initial.length ? 'all' : 'upcoming', search: '', category: 0, missing: '', page: 1 } );
	const [ data, setData ] = useState( { items: [], total: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( initial );
	const [ open, setOpen ] = useState( initial.length > 0 );
	const [ run, setRun ] = useState( { kinds: [ 'description', 'social', 'seo' ], tone: ( status.defaults && status.defaults.tone ) || 'friendly', language: '', notes: '', only_missing: true, source: 'ai', networks: ( status.defaults && status.defaults.networks ) || [ 'x', 'facebook' ] } );
	const [ job, setJob ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const perPage = 20;
	const aiReady = status.ai && status.ai.enabled && status.ai.key_set && ! status.ai.cap_reached;

	const load = () => {
		setLoading( true );
		setError( null );
		const params = new URLSearchParams( { scope: q.scope, search: q.search, category: String( q.category || 0 ), missing: q.missing, page: String( q.page ), per_page: String( perPage ) } );
		request( `events?${ params.toString() }` )
			.then( setData )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( load, [ q ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const set = ( k ) => ( v ) => setQ( { ...q, [ k ]: v, page: 1 } );
	const toggle = ( id ) => ( on ) => setSelected( on ? [ ...new Set( [ ...selected, id ] ) ] : selected.filter( ( x ) => x !== id ) );
	const pageIds = data.items.map( ( r ) => r.id );
	const allOnPage = pageIds.length > 0 && pageIds.every( ( id ) => selected.includes( id ) );

	const start = () => {
		setStarting( true );
		request( 'bulk', {
			method: 'POST',
			data: {
				ids: selected,
				kinds: run.kinds,
				only_missing: run.only_missing,
				source: run.source,
				options: { tone: run.tone, language: run.language, notes: run.notes, networks: run.networks },
			},
		} )
			.then( setJob )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setStarting( false ) );
	};

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-events-calendar' ),
			width: '56px',
			render: ( r ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span onClick={ ( e ) => e.stopPropagation() }>
					<Checkbox label={ sprintf( /* translators: %s: event */ __( 'Select %s', 'dev3-ai-events-calendar' ), r.title ) } checked={ selected.includes( r.id ) } onChange={ toggle( r.id ) } className="dev3ec-hide-label" />
				</span>
			),
		},
		{
			key: 'title',
			label: __( 'Event', 'dev3-ai-events-calendar' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3ec-evt">
					<span className="dev3ec-evt__title">{ r.title }</span>
					{ r.status !== 'publish' && <Badge>{ r.status }</Badge> }
					{ r.pending > 0 && <Badge tone="warning">{ sprintf( /* translators: %d: drafts */ _n( '%d draft', '%d drafts', r.pending, 'dev3-ai-events-calendar' ), r.pending ) }</Badge> }
				</span>
			),
		},
		{ key: 'date_label', label: __( 'Date', 'dev3-ai-events-calendar' ), render: ( r ) => r.date_label || '—' },
		{ key: 'venue', label: __( 'Venue', 'dev3-ai-events-calendar' ), hideOnMobile: true, render: ( r ) => r.venue || '—' },
		{ key: 'has', label: __( 'Content', 'dev3-ai-events-calendar' ), render: ( r ) => <Coverage has={ r.has } /> },
	];

	const missingOptions = [ { value: '', label: __( 'Any content', 'dev3-ai-events-calendar' ) }, ...KINDS.map( ( k ) => ( { value: k, label: sprintf( /* translators: %s: output */ __( 'Missing: %s', 'dev3-ai-events-calendar' ), KIND_LABELS[ k ] ) } ) ) ];

	return (
		<>
			<PageHeader
				title={ __( 'Events', 'dev3-ai-events-calendar' ) }
				description={ __( 'Pick events and let DEV3 AI write the missing pieces in the background. Every result lands in Review as a draft.', 'dev3-ai-events-calendar' ) }
				actions={
					<AiButton onClick={ () => setOpen( true ) } disabled={ ! selected.length }>
						{ selected.length ? sprintf( /* translators: %d: events */ _n( 'Generate for %d event', 'Generate for %d events', selected.length, 'dev3-ai-events-calendar' ), selected.length ) : __( 'Select events to generate', 'dev3-ai-events-calendar' ) }
					</AiButton>
				}
			/>
			<Card>
				<FormGrid>
					<Select
						label={ __( 'Show', 'dev3-ai-events-calendar' ) }
						value={ q.scope }
						onChange={ set( 'scope' ) }
						options={ [
							{ value: 'upcoming', label: __( 'Upcoming', 'dev3-ai-events-calendar' ) },
							{ value: 'past', label: __( 'Past', 'dev3-ai-events-calendar' ) },
							{ value: 'all', label: __( 'All events', 'dev3-ai-events-calendar' ) },
						] }
					/>
					<Select label={ __( 'Category', 'dev3-ai-events-calendar' ) } value={ String( q.category || '' ) } onChange={ ( v ) => set( 'category' )( Number( v ) || 0 ) } options={ [ { value: '', label: __( 'All categories', 'dev3-ai-events-calendar' ) }, ...( status.categories || [] ).map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) ] } />
					<Select label={ __( 'Content', 'dev3-ai-events-calendar' ) } value={ q.missing } onChange={ set( 'missing' ) } options={ missingOptions } />
					<form
						role="search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							set( 'search' )( search );
						} }
					>
						<TextField label={ __( 'Search events', 'dev3-ai-events-calendar' ) } type="search" value={ search } onChange={ setSearch } onBlur={ () => search !== q.search && set( 'search' )( search ) } />
					</form>
				</FormGrid>
			</Card>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card
				flush
				title={ sprintf( /* translators: %d: events */ _n( '%d event', '%d events', data.total, 'dev3-ai-events-calendar' ), data.total ) }
				actions={
					<Inline gap={ 8 }>
						<Checkbox label={ __( 'Select page', 'dev3-ai-events-calendar' ) } checked={ allOnPage } onChange={ ( on ) => setSelected( on ? [ ...new Set( [ ...selected, ...pageIds ] ) ] : selected.filter( ( id ) => ! pageIds.includes( id ) ) ) } />
						{ selected.length > 0 && (
							<Button size="sm" variant="ghost" onClick={ () => setSelected( [] ) }>
								{ sprintf( /* translators: %d: selected */ __( 'Clear (%d)', 'dev3-ai-events-calendar' ), selected.length ) }
							</Button>
						) }
					</Inline>
				}
			>
				<ResponsiveTable
					columns={ columns }
					rows={ data.items }
					loading={ loading }
					onRowClick={ ( r ) => navigate( `event/${ r.id }` ) }
					pagination={ { page: q.page, perPage, total: data.total, onPageChange: ( p ) => setQ( { ...q, page: p } ) } }
					empty={ { icon: 'inbox', title: __( 'No events found', 'dev3-ai-events-calendar' ), description: __( 'Change the filters, or create events in The Events Calendar.', 'dev3-ai-events-calendar' ) } }
				/>
			</Card>
			<Drawer
				open={ open }
				onClose={ () => setOpen( false ) }
				size="lg"
				title={ __( 'Generate with DEV3 AI', 'dev3-ai-events-calendar' ) }
				description={ sprintf( /* translators: %d: events */ _n( '%d event selected', '%d events selected', selected.length, 'dev3-ai-events-calendar' ), selected.length ) }
				footer={
					job ? (
						<Button
							variant="primary"
							onClick={ () => {
								setOpen( false );
								navigate( `review/${ job.id }` );
							} }
						>
							{ __( 'Open Review', 'dev3-ai-events-calendar' ) }
						</Button>
					) : (
						<>
							<Button variant="ghost" onClick={ () => setOpen( false ) }>
								{ __( 'Cancel', 'dev3-ai-events-calendar' ) }
							</Button>
							<AiButton onClick={ start } loading={ starting } disabled={ ! selected.length || ! run.kinds.length || ( run.source === 'ai' && ! aiReady ) }>
								{ run.source === 'ai' ? __( 'Start DEV3 AI run', 'dev3-ai-events-calendar' ) : __( 'Create template drafts', 'dev3-ai-events-calendar' ) }
							</AiButton>
						</>
					)
				}
			>
				{ job ? (
					<JobProgress
						jobId={ job.id }
						job={ job }
						title={ __( 'Writing drafts', 'dev3-ai-events-calendar' ) }
						onDone={ () => {
							toast.success( __( 'Drafts are ready in Review.', 'dev3-ai-events-calendar' ) );
							onChanged();
							load();
							if ( onUsage ) {
								onUsage();
							}
						} }
					/>
				) : (
					<Stack gap={ 16 }>
						{ run.source === 'ai' && ! aiReady && <Notice tone="ai">{ __( 'Connect DEV3 AI in API Config, or switch to templates.', 'dev3-ai-events-calendar' ) }</Notice> }
						<fieldset className="dev3ec-fieldset">
							<legend>{ __( 'Outputs', 'dev3-ai-events-calendar' ) }</legend>
							{ KINDS.map( ( k ) => (
								<Checkbox key={ k } label={ KIND_LABELS[ k ] } checked={ run.kinds.includes( k ) } onChange={ ( on ) => setRun( { ...run, kinds: on ? [ ...run.kinds, k ] : run.kinds.filter( ( x ) => x !== k ) } ) } />
							) ) }
						</fieldset>
						{ run.kinds.includes( 'social' ) && (
							<fieldset className="dev3ec-fieldset">
								<legend>{ __( 'Networks', 'dev3-ai-events-calendar' ) }</legend>
								{ Object.keys( NETWORKS ).map( ( n ) => (
									<Checkbox key={ n } label={ NETWORKS[ n ].label } checked={ run.networks.includes( n ) } onChange={ ( on ) => setRun( { ...run, networks: on ? [ ...run.networks, n ] : run.networks.filter( ( x ) => x !== n ) } ) } />
								) ) }
							</fieldset>
						) }
						<Select
							label={ __( 'Written by', 'dev3-ai-events-calendar' ) }
							value={ run.source }
							onChange={ ( v ) => setRun( { ...run, source: v } ) }
							options={ [
								{ value: 'ai', label: __( 'DEV3 AI', 'dev3-ai-events-calendar' ) },
								{ value: 'template', label: __( 'Templates (no AI)', 'dev3-ai-events-calendar' ) },
							] }
						/>
						<FormGrid>
							<Select label={ __( 'Tone', 'dev3-ai-events-calendar' ) } value={ run.tone } onChange={ ( v ) => setRun( { ...run, tone: v } ) } options={ TONES } />
							<TextField label={ __( 'Language', 'dev3-ai-events-calendar' ) } value={ run.language } onChange={ ( v ) => setRun( { ...run, language: v } ) } placeholder={ __( 'Site language', 'dev3-ai-events-calendar' ) } />
						</FormGrid>
						<Textarea label={ __( 'Notes for every event (optional)', 'dev3-ai-events-calendar' ) } value={ run.notes } onChange={ ( v ) => setRun( { ...run, notes: v } ) } rows={ 3 } maxLength={ 1500 } />
						<Toggle label={ __( 'Only fill what is missing', 'dev3-ai-events-calendar' ) } checked={ run.only_missing } onChange={ ( v ) => setRun( { ...run, only_missing: v } ) } help={ __( 'Skips outputs an event already has.', 'dev3-ai-events-calendar' ) } />
					</Stack>
				) }
			</Drawer>
		</>
	);
}
