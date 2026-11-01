import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Button, ErrorNotice, Inline, Modal, Notice, Stack, useToast } from '../core3/ui';
import Options, { defaultOptions } from './Options';
import SocialReview from './SocialReview';
import { KIND_LABELS, fieldsFor } from './kinds';
import { SavedDescription, SavedEmail, SavedFaq, SavedSeo, SavedSocial } from './saved';

/**
 * One output kind: options → generate (DEV3 AI or template) → review with diff → accept/reject,
 * plus the saved value with manual editing.
 *
 * @param {Object}   props         Props.
 * @param {string}   props.kind    Kind.
 * @param {Object}   props.snap    Event snapshot (GET events/<id>).
 * @param {Object}   props.status  Status payload.
 * @param {Object}   props.bridge  Editor bridge (null in the admin app).
 * @param {boolean}  props.compact Sidebar layout.
 * @param {Function} props.reload  Reloads the snapshot.
 * @param {Function} props.onUsage After an AI call.
 * @return {Element} Panel.
 */
export default function KindPanel( { kind, snap, status, bridge = null, compact = false, reload, onUsage } ) {
	const toast = useToast();
	const eventId = snap.event.id;
	const pending = ( snap.pending || [] ).find( ( d ) => d.kind === kind ) || null;
	const [ opts, setOpts ] = useState( () => defaultOptions( kind, status ) );
	const [ draft, setDraft ] = useState( pending );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ stale, setStale ] = useState( null );
	const [ applyMode, setApplyMode ] = useState( 'replace' );
	const aiReady = status && status.ai && status.ai.enabled && status.ai.key_set && ! status.ai.cap_reached;

	useEffect( () => {
		setDraft( pending );
	}, [ pending && pending.id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const generate = ( source = 'ai' ) => {
		setBusy( source );
		setError( null );
		request( `events/${ eventId }/${ source === 'ai' ? 'generate' : 'template' }`, { method: 'POST', data: { kind, options: opts } } )
			.then( ( d ) => setDraft( d ) )
			.catch( setError )
			.finally( () => {
				setBusy( '' );
				if ( source === 'ai' && onUsage ) {
					onUsage();
				}
			} );
	};

	const finish = ( r ) => {
		setDraft( null );
		setStale( null );
		if ( kind === 'seo' && bridge && bridge.syncSeo ) {
			bridge.syncSeo( r.saved );
		}
		reload();
	};

	const accept = ( value, force = false ) => {
		setBusy( 'accept' );
		setError( null );
		const client = kind === 'description' && bridge && bridge.applyDescription;
		request( `drafts/${ draft.id }/accept`, { method: 'POST', data: { value, mode: client ? 'client' : 'server', apply: applyMode, force } } )
			.then( ( r ) => {
				if ( client ) {
					bridge.applyDescription( r, applyMode );
					toast.success( __( 'Inserted into the editor. Update the event to save it.', 'dev3-ai-events-calendar' ) );
				} else {
					toast.success( sprintf( /* translators: %s: output */ __( '%s saved.', 'dev3-ai-events-calendar' ), KIND_LABELS[ kind ] ) );
				}
				finish( r );
			} )
			.catch( ( e ) => {
				if ( e.code === 'dev3ec_stale' ) {
					setStale( value );
				} else {
					setError( e );
				}
			} )
			.finally( () => setBusy( '' ) );
	};

	const reject = () => {
		request( `drafts/${ draft.id }/reject`, { method: 'POST' } )
			.then( () => {
				setDraft( null );
				reload();
			} )
			.catch( ( e ) => toast.error( e ) );
	};

	const meta = draft ? { model: draft.model, tokens: draft.tokens, cached: draft.cached } : null;
	const title = draft && draft.source === 'template' ? __( 'Template draft', 'dev3-ai-events-calendar' ) : __( 'DEV3 AI draft', 'dev3-ai-events-calendar' );
	const saved = ( snap.assets || {} )[ kind ];

	return (
		<Stack gap={ 16 } className="dev3ec-kind">
			{ ! draft && (
				<>
					<Options kind={ kind } value={ opts } onChange={ setOpts } compact={ compact } />
					{ ! aiReady && (
						<Notice tone="ai">
							{ status && status.ai && status.ai.cap_reached ? __( 'AI paused: the monthly AI budget is reached. Templates still work.', 'dev3-ai-events-calendar' ) : __( 'Connect DEV3 AI (API Config) to generate drafts. Templates work without a key.', 'dev3-ai-events-calendar' ) }
						</Notice>
					) }
					<Inline gap={ 8 } className="dev3ec-gen">
						<AiButton onClick={ () => generate( 'ai' ) } loading={ busy === 'ai' } disabled={ !! busy || ! aiReady || ( kind === 'social' && ! opts.networks.length ) } />
						<Button icon="file" onClick={ () => generate( 'template' ) } loading={ busy === 'template' } disabled={ !! busy }>
							{ __( 'Use template', 'dev3-ai-events-calendar' ) }
						</Button>
					</Inline>
				</>
			) }
			<ErrorNotice error={ error } onRetry={ error && error.retryable ? () => generate( 'ai' ) : undefined } onDismiss={ () => setError( null ) } settingsHref={ `${ ( window.dev3ecConfig || {} ).appUrl || '' }#/api` } />
			{ draft && kind === 'description' && (
				<fieldset className="dev3ec-apply">
					<legend>{ __( 'When accepted', 'dev3-ai-events-calendar' ) }</legend>
					<label>
						<input type="radio" name={ `dev3ec-apply-${ eventId }` } value="replace" checked={ applyMode === 'replace' } onChange={ () => setApplyMode( 'replace' ) } /> { __( 'Replace the text blocks (TEC blocks stay)', 'dev3-ai-events-calendar' ) }
					</label>
					<label>
						<input type="radio" name={ `dev3ec-apply-${ eventId }` } value="append" checked={ applyMode === 'append' } onChange={ () => setApplyMode( 'append' ) } /> { __( 'Add after the current text', 'dev3-ai-events-calendar' ) }
					</label>
				</fieldset>
			) }
			{ draft && kind === 'social' && (
				<SocialReview title={ title } draft={ draft.payload } original={ draft.original } meta={ meta } onAccept={ accept } onReject={ reject } onRegenerate={ () => generate( draft.source === 'template' ? 'template' : 'ai' ) } regenerating={ busy === 'ai' || busy === 'template' } accepting={ busy === 'accept' } />
			) }
			{ draft && kind !== 'social' && (
				<AiDraftReview
					title={ title }
					fields={ fieldsFor( kind, draft.payload ) }
					draft={ draft.payload }
					original={ draft.original }
					meta={ meta }
					onAccept={ accept }
					onReject={ reject }
					onRegenerate={ () => generate( draft.source === 'template' ? 'template' : 'ai' ) }
					regenerating={ busy === 'ai' || busy === 'template' }
					accepting={ busy === 'accept' }
					acceptLabel={ kind === 'description' && bridge ? __( 'Accept & insert', 'dev3-ai-events-calendar' ) : __( 'Accept', 'dev3-ai-events-calendar' ) }
				/>
			) }
			{ ! draft && kind === 'description' && <SavedDescription value={ saved } event={ snap.event } bridge={ bridge } /> }
			{ ! draft && kind === 'social' && <SavedSocial eventId={ eventId } value={ saved } networks={ opts.networks } url={ snap.event.url } onSaved={ reload } /> }
			{ ! draft && kind.indexOf( 'email_' ) === 0 && (
				<SavedEmail eventId={ eventId } kind={ kind } value={ saved } recipients={ snap.recipients } tickets={ !! ( status && status.tickets ) } canManage={ !! ( status && status.canManage ) } tags={ ( status && status.tags ) || [] } onSaved={ reload } />
			) }
			{ ! draft && kind === 'faq' && <SavedFaq eventId={ eventId } value={ saved } bridge={ bridge } onSaved={ reload } /> }
			{ ! draft && kind === 'seo' && <SavedSeo eventId={ eventId } value={ saved } event={ snap.event } target={ ( snap.seo && snap.seo.label ) || '' } bridge={ bridge } onSaved={ reload } /> }
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
						<Button onClick={ () => {
								setStale( null );
								generate( 'ai' );
							} } disabled={ ! aiReady }>
							{ __( 'Regenerate', 'dev3-ai-events-calendar' ) }
						</Button>
						<Button variant="primary" onClick={ () => accept( stale, true ) } loading={ busy === 'accept' } data-autofocus>
							{ __( 'Accept anyway', 'dev3-ai-events-calendar' ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'The event was edited after this draft was written, so the draft may describe old details.', 'dev3-ai-events-calendar' ) }</p>
			</Modal>
		</Stack>
	);
}
