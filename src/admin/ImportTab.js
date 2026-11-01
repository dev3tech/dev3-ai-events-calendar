import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, Columns, EmptyState, ErrorNotice, FormGrid, Icon, JobProgress, Notice, PageHeader, ResponsiveTable, Select, Stack, TextField, Textarea, Toggle, useToast } from '../core3/ui';
import { importStatus, when } from './util';

const VENUE_FIELDS = [
	[ 'name', __( 'Venue name', 'dev3-ai-events-calendar' ) ],
	[ 'address', __( 'Address', 'dev3-ai-events-calendar' ) ],
	[ 'city', __( 'City', 'dev3-ai-events-calendar' ) ],
	[ 'region', __( 'State / region', 'dev3-ai-events-calendar' ) ],
	[ 'zip', __( 'Postal code', 'dev3-ai-events-calendar' ) ],
	[ 'country', __( 'Country', 'dev3-ai-events-calendar' ) ],
	[ 'phone', __( 'Phone', 'dev3-ai-events-calendar' ) ],
];
const ORG_FIELDS = [
	[ 'name', __( 'Organizer name', 'dev3-ai-events-calendar' ) ],
	[ 'email', __( 'Email', 'dev3-ai-events-calendar' ) ],
	[ 'phone', __( 'Phone', 'dev3-ai-events-calendar' ) ],
	[ 'website', __( 'Website', 'dev3-ai-events-calendar' ) ],
];

/**
 * Form state from an import draft.
 *
 * @param {Object} d Draft.
 * @return {Object} Form.
 */
export function formFromDraft( d = {} ) {
	const vm = d.venue_match || { suggestion: 'none', id: 0, candidates: [] };
	const om = d.organizer_match || { suggestion: 'none', id: 0, candidates: [] };
	return {
		title: d.title || '',
		start_date: d.start_date || '',
		start_time: d.start_time || '',
		end_date: d.end_date || '',
		end_time: d.end_time || '',
		all_day: !! d.all_day,
		timezone: d.timezone || '',
		cost: d.cost || '',
		currency: d.currency || '',
		website: d.website || '',
		description: d.description || '',
		category_ids: d.category_ids || [],
		featured: true,
		venue: { ...( d.venue || {} ), mode: vm.suggestion, id: vm.id || 0 },
		organizer: { ...( d.organizer || {} ), mode: om.suggestion, id: om.id || 0 },
	};
}

/**
 * Match picker for a venue or organizer: use an existing record, create a new one, or none.
 *
 * @param {Object}   props        Props.
 * @param {string}   props.type   venue|organizer.
 * @param {Object}   props.value  Form part.
 * @param {Object}   props.match  Match result.
 * @param {Array}    props.fields Field defs.
 * @param {Function} props.onChange Change.
 * @return {Element} Picker.
 */
function MatchPicker( { type, value, match, fields, onChange } ) {
	const [ all, setAll ] = useState( [] );
	useEffect( () => {
		request( `places?type=${ type }` )
			.then( setAll )
			.catch( () => setAll( [] ) );
	}, [ type ] );
	const cands = ( match && match.candidates ) || [];
	const name = `dev3ec-${ type }-mode`;
	const title = type === 'venue' ? __( 'Venue', 'dev3-ai-events-calendar' ) : __( 'Organizer', 'dev3-ai-events-calendar' );
	const options = [ { value: '', label: __( 'Choose…', 'dev3-ai-events-calendar' ) } ];
	cands.forEach( ( c ) => options.push( { value: String( c.id ), label: sprintf( /* translators: 1: name, 2: score */ __( '%1$s (%2$d%% match)', 'dev3-ai-events-calendar' ), c.name, c.score ) } ) );
	all.filter( ( p ) => ! cands.some( ( c ) => c.id === p.id ) ).forEach( ( p ) => options.push( { value: String( p.id ), label: p.name } ) );
	return (
		<fieldset className="dev3ec-fieldset dev3ec-match">
			<legend>{ title }</legend>
			{ cands.length > 0 && (
				<p className="dev3ec-help">
					<Icon name="check" size={ 14 } /> { sprintf( /* translators: 1: name, 2: score */ __( 'Best match: %1$s (%2$d%%)', 'dev3-ai-events-calendar' ), cands[ 0 ].name, cands[ 0 ].score ) }
				</p>
			) }
			<div className="dev3ec-radios" role="radiogroup" aria-label={ title }>
				{ [
					[ 'existing', __( 'Use existing', 'dev3-ai-events-calendar' ) ],
					[ 'new', __( 'Create new', 'dev3-ai-events-calendar' ) ],
					[ 'none', __( 'None', 'dev3-ai-events-calendar' ) ],
				].map( ( [ v, l ] ) => (
					<label key={ v }>
						<input type="radio" name={ name } value={ v } checked={ value.mode === v } onChange={ () => onChange( { ...value, mode: v } ) } /> { l }
					</label>
				) ) }
			</div>
			{ value.mode === 'existing' && <Select label={ sprintf( /* translators: %s: venue/organizer */ __( 'Existing %s', 'dev3-ai-events-calendar' ), title.toLowerCase() ) } value={ String( value.id || '' ) } onChange={ ( v ) => onChange( { ...value, id: Number( v ) || 0 } ) } options={ options } /> }
			{ value.mode === 'new' && (
				<FormGrid>
					{ fields.map( ( [ k, l ] ) => (
						<TextField key={ k } label={ l } value={ value[ k ] || '' } onChange={ ( v ) => onChange( { ...value, [ k ]: v } ) } />
					) ) }
				</FormGrid>
			) }
		</fieldset>
	);
}

/**
 * Review form for an import, beside the flyer.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.item      Import.
 * @param {Object}   props.status    Status.
 * @param {Function} props.onCreated After create.
 * @return {Element} Form.
 */
function ReviewForm( { item, status, onCreated } ) {
	const toast = useToast();
	const [ f, setF ] = useState( () => formFromDraft( item.draft ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useEffect( () => setF( formFromDraft( item.draft ) ), [ item.id ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const set = ( k ) => ( v ) => setF( { ...f, [ k ]: v } );
	const flags = ( k ) => {
		const d = item.draft || {};
		if ( ( d.uncertain || [] ).includes( k ) ) {
			return __( 'DEV3 AI is not sure: check this against the flyer.', 'dev3-ai-events-calendar' );
		}
		if ( ( d.missing || [] ).includes( k ) && item.mode === 'ai' ) {
			return __( 'Not on the flyer.', 'dev3-ai-events-calendar' );
		}
		return undefined;
	};
	const create = () => {
		setBusy( true );
		setError( null );
		request( `imports/${ item.id }/create`, { method: 'POST', data: { fields: f } } )
			.then( ( r ) => {
				toast.success( __( 'Draft event created.', 'dev3-ai-events-calendar' ) );
				onCreated( r );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const isImage = ( item.mime || '' ).indexOf( 'image/' ) === 0;
	return (
		<Columns min={ 300 }>
			<Card title={ __( 'Flyer', 'dev3-ai-events-calendar' ) }>
				{ isImage && item.preview_url ? (
					<img className="dev3ec-flyer" src={ item.preview_url } alt={ sprintf( /* translators: %s: file */ __( 'Flyer: %s', 'dev3-ai-events-calendar' ), item.file_name ) } />
				) : (
					<EmptyState compact icon="file" title={ item.file_name } action={ item.file_url ? <Button href={ item.file_url } target="_blank" rel="noopener noreferrer" icon="external">{ __( 'Open PDF', 'dev3-ai-events-calendar' ) }</Button> : null } />
				) }
				{ item.draft && item.draft.model && <p className="dev3ec-help">{ sprintf( /* translators: %s: model */ __( 'Read by %s', 'dev3-ai-events-calendar' ), item.draft.model ) }</p> }
			</Card>
			<Card
				tone={ item.mode === 'ai' ? 'ai' : undefined }
				title={ item.mode === 'ai' ? __( 'DEV3 AI draft event', 'dev3-ai-events-calendar' ) : __( 'Event details', 'dev3-ai-events-calendar' ) }
				description={ __( 'Check every field against the flyer. The event is created as a draft; nothing is published.', 'dev3-ai-events-calendar' ) }
				actions={ <Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-events-calendar' ) }</Badge> }
				footer={
					<Button variant="primary" icon="check" onClick={ create } loading={ busy } disabled={ ! f.title || ! f.start_date }>
						{ __( 'Create draft event', 'dev3-ai-events-calendar' ) }
					</Button>
				}
			>
				<Stack gap={ 16 }>
					<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
					<TextField label={ __( 'Title', 'dev3-ai-events-calendar' ) } value={ f.title } onChange={ set( 'title' ) } required help={ flags( 'title' ) } />
					<FormGrid>
						<TextField label={ __( 'Start date', 'dev3-ai-events-calendar' ) } type="date" value={ f.start_date } onChange={ set( 'start_date' ) } required help={ flags( 'start_date' ) } />
						<TextField label={ __( 'Start time', 'dev3-ai-events-calendar' ) } type="time" value={ f.start_time } onChange={ set( 'start_time' ) } disabled={ f.all_day } help={ flags( 'start_time' ) } />
						<TextField label={ __( 'End date', 'dev3-ai-events-calendar' ) } type="date" value={ f.end_date } onChange={ set( 'end_date' ) } help={ flags( 'end_date' ) } />
						<TextField label={ __( 'End time', 'dev3-ai-events-calendar' ) } type="time" value={ f.end_time } onChange={ set( 'end_time' ) } disabled={ f.all_day } help={ flags( 'end_time' ) } />
					</FormGrid>
					<Toggle label={ __( 'All-day event', 'dev3-ai-events-calendar' ) } checked={ f.all_day } onChange={ set( 'all_day' ) } />
					<FormGrid>
						<TextField label={ __( 'Time zone', 'dev3-ai-events-calendar' ) } value={ f.timezone } onChange={ set( 'timezone' ) } placeholder="Europe/London" />
						<TextField label={ __( 'Cost', 'dev3-ai-events-calendar' ) } value={ f.cost } onChange={ set( 'cost' ) } help={ flags( 'cost' ) } />
						<TextField label={ __( 'Currency symbol', 'dev3-ai-events-calendar' ) } value={ f.currency } onChange={ set( 'currency' ) } maxLength={ 8 } />
						<TextField label={ __( 'Event website', 'dev3-ai-events-calendar' ) } type="url" value={ f.website } onChange={ set( 'website' ) } help={ flags( 'website' ) } />
					</FormGrid>
					<MatchPicker type="venue" value={ f.venue } match={ item.draft && item.draft.venue_match } fields={ VENUE_FIELDS } onChange={ set( 'venue' ) } />
					<MatchPicker type="organizer" value={ f.organizer } match={ item.draft && item.draft.organizer_match } fields={ ORG_FIELDS } onChange={ set( 'organizer' ) } />
					{ ( status.categories || [] ).length > 0 && (
						<fieldset className="dev3ec-fieldset">
							<legend>{ __( 'Event categories', 'dev3-ai-events-calendar' ) }</legend>
							{ ( item.draft && item.draft.categories && item.draft.categories.length > 0 ) && <p className="dev3ec-help">{ sprintf( /* translators: %s: categories */ __( 'On the flyer: %s', 'dev3-ai-events-calendar' ), item.draft.categories.join( ', ' ) ) }</p> }
							<div className="dev3ec-checks">
								{ status.categories.map( ( c ) => (
									<Checkbox key={ c.value } label={ c.label } checked={ f.category_ids.includes( c.value ) } onChange={ ( on ) => set( 'category_ids' )( on ? [ ...f.category_ids, c.value ] : f.category_ids.filter( ( x ) => x !== c.value ) ) } />
								) ) }
							</div>
						</fieldset>
					) }
					<Textarea label={ __( 'Description (headings start with ##)', 'dev3-ai-events-calendar' ) } value={ f.description } onChange={ set( 'description' ) } rows={ 8 } />
					{ isImage && <Toggle label={ __( 'Use the flyer as the featured image', 'dev3-ai-events-calendar' ) } checked={ f.featured } onChange={ set( 'featured' ) } /> }
				</Stack>
			</Card>
		</Columns>
	);
}

/**
 * Flyer import: upload → DEV3 AI reads it (or fill in by hand) → review → draft event.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.status    Status.
 * @param {Function} props.onUsage   After AI.
 * @param {Function} props.onChanged Refresh status.
 * @return {Element} Screen.
 */
export default function ImportTab( { status, onUsage, onChanged } ) {
	const toast = useToast();
	const input = useRef( null );
	const [ file, setFile ] = useState( null );
	const [ notes, setNotes ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ item, setItem ] = useState( null );
	const [ created, setCreated ] = useState( null );
	const [ recent, setRecent ] = useState( null );
	const [ drag, setDrag ] = useState( false );
	const aiReady = status.ai && status.ai.enabled && status.ai.key_set && ! status.ai.cap_reached;

	const loadRecent = () =>
		request( 'imports' )
			.then( setRecent )
			.catch( () => setRecent( [] ) );
	useEffect( () => {
		loadRecent();
	}, [] );

	const pick = ( f ) => {
		if ( ! f ) {
			return;
		}
		if ( ! /^(image\/(jpeg|png|webp)|application\/pdf)$/.test( f.type ) ) {
			toast.error( __( 'Use a JPG, PNG or WebP image, or a PDF.', 'dev3-ai-events-calendar' ) );
			return;
		}
		if ( f.size > 15 * 1024 * 1024 ) {
			toast.error( __( 'The file is too large (15 MB max).', 'dev3-ai-events-calendar' ) );
			return;
		}
		setFile( f );
	};
	const upload = ( mode ) => {
		const body = new window.FormData();
		body.append( 'file', file );
		body.append( 'mode', mode );
		body.append( 'notes', notes );
		setBusy( mode );
		setError( null );
		setCreated( null );
		request( 'imports', { method: 'POST', body } )
			.then( ( r ) => {
				setItem( r );
				setFile( null );
				loadRecent();
			} )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};
	const refresh = ( id ) =>
		request( `imports/${ id }` )
			.then( ( r ) => {
				setItem( r );
				loadRecent();
				if ( onUsage ) {
					onUsage();
				}
			} )
			.catch( setError );

	const recentCols = [
		{ key: 'file_name', label: __( 'Flyer', 'dev3-ai-events-calendar' ), primary: true, render: ( r ) => ( r.draft && r.draft.title ) || r.file_name },
		{
			key: 'status',
			label: __( 'Status', 'dev3-ai-events-calendar' ),
			render: ( r ) => {
				const s = importStatus( r.status );
				return <Badge tone={ s.tone }>{ s.label }</Badge>;
			},
		},
		{ key: 'created_at', label: __( 'Uploaded', 'dev3-ai-events-calendar' ), hideOnMobile: true, render: ( r ) => when( r.created_at ) },
	];

	return (
		<>
			<PageHeader title={ __( 'Import from a flyer', 'dev3-ai-events-calendar' ) } description={ __( 'Upload a flyer or poster (image or PDF). DEV3 AI reads the event and matches the venue and organizer; you check it and create a draft event.', 'dev3-ai-events-calendar' ) } />
			{ created && (
				<Notice tone="success" title={ __( 'Draft event created', 'dev3-ai-events-calendar' ) } actions={ <Button size="sm" variant="primary" href={ created.edit_url }>{ __( 'Edit event', 'dev3-ai-events-calendar' ) }</Button> } onDismiss={ () => setCreated( null ) }>
					{ __( 'Open it to check the details, then publish it from The Events Calendar.', 'dev3-ai-events-calendar' ) }
				</Notice>
			) }
			{ ! item || item.status === 'created' ? (
				<Card title={ __( 'Upload', 'dev3-ai-events-calendar' ) }>
					<Stack gap={ 16 }>
						<div
							className={ `dev3ec-drop${ drag ? ' is-drag' : '' }${ file ? ' has-file' : '' }` }
							onDragOver={ ( e ) => {
								e.preventDefault();
								setDrag( true );
							} }
							onDragLeave={ () => setDrag( false ) }
							onDrop={ ( e ) => {
								e.preventDefault();
								setDrag( false );
								pick( e.dataTransfer.files && e.dataTransfer.files[ 0 ] );
							} }
						>
							<Icon name="file" size={ 28 } />
							<p className="dev3ec-drop__title">{ file ? file.name : __( 'Drop a flyer here', 'dev3-ai-events-calendar' ) }</p>
							<p className="dev3ec-help">{ __( 'JPG, PNG, WebP or PDF, up to 15 MB.', 'dev3-ai-events-calendar' ) }</p>
							<input ref={ input } id="dev3ec-flyer" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className="dev3ec-sr" onChange={ ( e ) => pick( e.target.files[ 0 ] ) } />
							<Button size="sm" onClick={ () => input.current && input.current.click() }>
								{ file ? __( 'Choose another file', 'dev3-ai-events-calendar' ) : __( 'Choose a file', 'dev3-ai-events-calendar' ) }
							</Button>
						</div>
						<Textarea label={ __( 'Notes for DEV3 AI (optional)', 'dev3-ai-events-calendar' ) } value={ notes } onChange={ setNotes } rows={ 2 } maxLength={ 500 } placeholder={ __( 'e.g. the year is 2027; ignore the sponsor logos', 'dev3-ai-events-calendar' ) } />
						{ ! aiReady && <Notice tone="ai">{ __( 'Connect DEV3 AI to read flyers automatically. You can still upload one and fill in the details by hand.', 'dev3-ai-events-calendar' ) }</Notice> }
						<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
						<div className="dev3ec-actions">
							<AiButton onClick={ () => upload( 'ai' ) } loading={ busy === 'ai' } disabled={ ! file || !! busy || ! aiReady }>
								{ __( 'Read with DEV3 AI', 'dev3-ai-events-calendar' ) }
							</AiButton>
							<Button onClick={ () => upload( 'manual' ) } loading={ busy === 'manual' } disabled={ ! file || !! busy }>
								{ __( 'Fill in by hand', 'dev3-ai-events-calendar' ) }
							</Button>
						</div>
					</Stack>
				</Card>
			) : null }
			{ item && item.status === 'queued' && (
				<Card tone="ai" title={ __( 'DEV3 AI is reading the flyer', 'dev3-ai-events-calendar' ) }>
					<JobProgress jobId={ item.job_id } title={ item.file_name } onDone={ () => refresh( item.id ) } />
				</Card>
			) }
			{ item && item.status === 'failed' && (
				<Notice tone="error" title={ __( 'The flyer could not be read', 'dev3-ai-events-calendar' ) } actions={ <Button size="sm" onClick={ () => setItem( null ) }>{ __( 'Try another file', 'dev3-ai-events-calendar' ) }</Button> }>
					{ item.error }
				</Notice>
			) }
			{ item && item.status === 'ready' && (
				<>
					<div className="dev3ec-actions">
						<Button variant="ghost" icon="chevronLeft" onClick={ () => setItem( null ) }>
							{ __( 'Back to upload', 'dev3-ai-events-calendar' ) }
						</Button>
					</div>
					<ReviewForm
						item={ item }
						status={ status }
						onCreated={ ( r ) => {
							setCreated( r );
							setItem( null );
							loadRecent();
							onChanged();
						} }
					/>
				</>
			) }
			<Card title={ __( 'Recent imports', 'dev3-ai-events-calendar' ) } flush>
				<ResponsiveTable
					columns={ recentCols }
					rows={ recent || [] }
					loading={ recent === null }
					onRowClick={ ( r ) => ( r.status === 'created' && r.event_edit ? ( window.location.href = r.event_edit ) : setItem( r ) ) }
					empty={ { icon: 'file', title: __( 'No imports yet', 'dev3-ai-events-calendar' ), description: __( 'Your uploaded flyers show up here.', 'dev3-ai-events-calendar' ) } }
				/>
			</Card>
		</>
	);
}
