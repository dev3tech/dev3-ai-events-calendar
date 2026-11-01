import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, EmptyState, Inline, JobProgress, Modal, Notice, Stack, TextField, Textarea, useToast } from '../core3/ui';
import { Counter } from './SocialReview';
import { NETWORKS, networkLabel, shareUrl } from './social';
import { parseFaq } from './kinds';

/**
 * Copies text to the clipboard (with a textarea fallback).
 *
 * @param {string} text Text.
 * @return {Promise} Done.
 */
export function copyText( text ) {
	if ( typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText ) {
		return navigator.clipboard.writeText( text );
	}
	const ta = document.createElement( 'textarea' );
	ta.value = text;
	ta.setAttribute( 'readonly', '' );
	ta.style.position = 'fixed';
	ta.style.opacity = '0';
	document.body.appendChild( ta );
	ta.select();
	try {
		document.execCommand( 'copy' );
	} finally {
		ta.remove();
	}
	return Promise.resolve();
}

/**
 * Hook: saves a kind manually.
 *
 * @param {number}   eventId Event.
 * @param {string}   kind    Kind.
 * @param {Function} onSaved After save.
 * @return {Array} [ save( value ), saving ]
 */
function useSave( eventId, kind, onSaved ) {
	const toast = useToast();
	const [ saving, setSaving ] = useState( false );
	const save = ( value ) => {
		setSaving( true );
		return request( `events/${ eventId }/assets`, { method: 'POST', data: { kind, value } } )
			.then( ( r ) => {
				toast.success( __( 'Saved.', 'dev3-ai-events-calendar' ) );
				if ( onSaved ) {
					onSaved( r );
				}
				return r;
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( false ) );
	};
	return [ save, saving ];
}

/**
 * Current description (read-only; edited in the event editor).
 *
 * @param {Object} props       Props.
 * @param {Object} props.value { excerpt, body }.
 * @param {Object} props.event Event.
 * @param {Object} props.bridge Editor bridge (null in the app).
 * @return {Element} Preview.
 */
export function SavedDescription( { value, event, bridge } ) {
	if ( ! value || ( ! value.body && ! value.excerpt ) ) {
		return <EmptyState compact icon="file" title={ __( 'No description yet', 'dev3-ai-events-calendar' ) } description={ __( 'Generate one with DEV3 AI or start from the template.', 'dev3-ai-events-calendar' ) } />;
	}
	return (
		<div className="dev3ec-saved">
			<p className="dev3ec-label">{ __( 'Current description', 'dev3-ai-events-calendar' ) }</p>
			{ value.excerpt && <p className="dev3ec-excerpt">{ value.excerpt }</p> }
			<div className="dev3ec-preview">{ value.body }</div>
			{ ! bridge && event && event.edit_url && (
				<Button size="sm" icon="edit" href={ event.edit_url }>
					{ __( 'Open in the event editor', 'dev3-ai-events-calendar' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Saved social posts: manual edit, live counters, copy and share links.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.eventId  Event.
 * @param {Object}   props.value    { network: text }.
 * @param {Array}    props.networks Networks to show.
 * @param {string}   props.url      Event URL.
 * @param {Function} props.onSaved  After save.
 * @return {Element} Posts.
 */
export function SavedSocial( { eventId, value, networks, url, onSaved } ) {
	const toast = useToast();
	const [ posts, setPosts ] = useState( { ...value } );
	const [ save, saving ] = useSave( eventId, 'social', onSaved );
	useEffect( () => setPosts( { ...value } ), [ value ] );
	const shown = [ ...new Set( [ ...Object.keys( value || {} ), ...( networks || [] ) ] ) ].filter( ( n ) => NETWORKS[ n ] );
	const dirty = shown.some( ( n ) => ( posts[ n ] || '' ) !== ( ( value || {} )[ n ] || '' ) );
	return (
		<div className="dev3ec-saved">
			<p className="dev3ec-label">{ __( 'Saved posts', 'dev3-ai-events-calendar' ) }</p>
			<ul className="dev3ec-netlist">
				{ shown.map( ( n ) => {
					const text = posts[ n ] || '';
					const share = shareUrl( n, text, url );
					return (
						<li key={ n } className="dev3ec-net">
							<div className="dev3ec-net__head">
								<strong>{ networkLabel( n ) }</strong>
								<Counter text={ text } network={ n } />
							</div>
							<Textarea label={ sprintf( /* translators: %s: network */ __( '%s post', 'dev3-ai-events-calendar' ), networkLabel( n ) ) } hideLabel value={ text } rows={ 3 } onChange={ ( v ) => setPosts( { ...posts, [ n ]: v } ) } placeholder={ __( 'Write a post or generate one…', 'dev3-ai-events-calendar' ) } />
							{ text && (
								<Inline gap={ 8 }>
									<Button size="sm" icon="copy" onClick={ () => copyText( text ).then( () => toast.success( __( 'Copied.', 'dev3-ai-events-calendar' ) ) ) }>
										{ __( 'Copy', 'dev3-ai-events-calendar' ) }
									</Button>
									{ share && (
										<Button size="sm" variant="ghost" icon="external" href={ share } target="_blank" rel="noopener noreferrer">
											{ __( 'Share', 'dev3-ai-events-calendar' ) }
											<span className="dev3ec-sr"> { __( '(opens in a new tab)', 'dev3-ai-events-calendar' ) }</span>
										</Button>
									) }
								</Inline>
							) }
						</li>
					);
				} ) }
			</ul>
			<Inline align="end">
				<Button variant="primary" onClick={ () => save( posts ) } loading={ saving } disabled={ ! dirty }>
					{ __( 'Save posts', 'dev3-ai-events-calendar' ) }
				</Button>
			</Inline>
		</div>
	);
}

/**
 * Saved email: manual edit, merge tags, test send and send to attendees.
 *
 * @param {Object}   props            Props.
 * @param {number}   props.eventId    Event.
 * @param {string}   props.kind       Email kind.
 * @param {Object}   props.value      { subject, preheader, body }.
 * @param {number}   props.recipients Attendee recipients.
 * @param {boolean}  props.tickets    Event Tickets active.
 * @param {boolean}  props.canManage  Can send to attendees.
 * @param {Array}    props.tags       Merge tags.
 * @param {Function} props.onSaved    After save.
 * @return {Element} Email.
 */
export function SavedEmail( { eventId, kind, value, recipients = 0, tickets = false, canManage = false, tags = [], onSaved } ) {
	const toast = useToast();
	const [ email, setEmail ] = useState( { subject: '', preheader: '', body: '', ...value } );
	const [ save, saving ] = useSave( eventId, kind, onSaved );
	const [ testing, setTesting ] = useState( false );
	const [ confirm, setConfirm ] = useState( false );
	const [ job, setJob ] = useState( null );
	const [ sending, setSending ] = useState( false );
	useEffect( () => setEmail( { subject: '', preheader: '', body: '', ...value } ), [ value ] );
	const set = ( k ) => ( v ) => setEmail( { ...email, [ k ]: v } );
	const dirty = [ 'subject', 'preheader', 'body' ].some( ( k ) => ( email[ k ] || '' ) !== ( ( value || {} )[ k ] || '' ) );
	const saved = value && value.subject && value.body;
	const test = () => {
		setTesting( true );
		request( `events/${ eventId }/send`, { method: 'POST', data: { kind, target: 'test', value: email } } )
			.then( ( r ) => toast.success( sprintf( /* translators: %s: email */ __( 'Test sent to %s.', 'dev3-ai-events-calendar' ), r.to ) ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setTesting( false ) );
	};
	const sendAll = () => {
		setSending( true );
		request( `events/${ eventId }/send`, { method: 'POST', data: { kind, target: 'attendees' } } )
			.then( ( r ) => {
				setJob( r.job );
				setConfirm( false );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSending( false ) );
	};
	return (
		<div className="dev3ec-saved">
			<p className="dev3ec-label">{ __( 'Saved email', 'dev3-ai-events-calendar' ) }</p>
			<Stack gap={ 12 }>
				<TextField label={ __( 'Subject', 'dev3-ai-events-calendar' ) } value={ email.subject } onChange={ set( 'subject' ) } maxLength={ 120 } />
				<TextField label={ __( 'Preheader', 'dev3-ai-events-calendar' ) } value={ email.preheader } onChange={ set( 'preheader' ) } maxLength={ 160 } help={ __( 'Shown after the subject in most inboxes.', 'dev3-ai-events-calendar' ) } />
				<Textarea label={ __( 'Body', 'dev3-ai-events-calendar' ) } value={ email.body } onChange={ set( 'body' ) } rows={ 9 } help={ sprintf( /* translators: %s: merge tags */ __( 'Merge tags: %s', 'dev3-ai-events-calendar' ), tags.join( ' ' ) ) } />
			</Stack>
			<div className="dev3ec-actions">
				<Button variant="primary" onClick={ () => save( email ) } loading={ saving } disabled={ ! dirty }>
					{ __( 'Save email', 'dev3-ai-events-calendar' ) }
				</Button>
				<Button icon="external" onClick={ test } loading={ testing } disabled={ ! email.subject || ! email.body }>
					{ __( 'Send me a test', 'dev3-ai-events-calendar' ) }
				</Button>
				{ tickets && canManage && kind !== 'email_promo' && (
					<Button icon="users" onClick={ () => setConfirm( true ) } disabled={ ! saved || dirty || recipients < 1 }>
						{ sprintf( /* translators: %d: attendees */ _n( 'Send to %d attendee', 'Send to %d attendees', recipients, 'dev3-ai-events-calendar' ), recipients ) }
					</Button>
				) }
			</div>
			{ kind === 'email_promo' && <p className="dev3ec-help">{ __( 'Copy the promo email into your newsletter tool, or send yourself a test to forward it.', 'dev3-ai-events-calendar' ) }</p> }
			{ tickets && kind !== 'email_promo' && recipients < 1 && <p className="dev3ec-help">{ __( 'No attendees with an email address yet (Event Tickets).', 'dev3-ai-events-calendar' ) }</p> }
			{ job && <JobProgress jobId={ job.id } job={ job } title={ __( 'Sending emails', 'dev3-ai-events-calendar' ) } onDone={ () => toast.success( __( 'Emails sent.', 'dev3-ai-events-calendar' ) ) } /> }
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Email all attendees?', 'dev3-ai-events-calendar' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) }>
							{ __( 'Cancel', 'dev3-ai-events-calendar' ) }
						</Button>
						<Button variant="primary" onClick={ sendAll } loading={ sending } data-autofocus>
							{ __( 'Send now', 'dev3-ai-events-calendar' ) }
						</Button>
					</>
				}
			>
				<p>{ sprintf( /* translators: 1: count, 2: subject */ _n( 'One email goes to %1$d attendee with the subject “%2$s”. This cannot be undone.', 'One email each goes to %1$d attendees with the subject “%2$s”. This cannot be undone.', recipients, 'dev3-ai-events-calendar' ), recipients, email.subject ) }</p>
			</Modal>
		</div>
	);
}

/**
 * Saved FAQ: manual edit and "insert as blocks" in the block editor.
 *
 * @param {Object}   props         Props.
 * @param {number}   props.eventId Event.
 * @param {Object}   props.value   { faq }.
 * @param {Object}   props.bridge  Editor bridge.
 * @param {Function} props.onSaved After save.
 * @return {Element} FAQ.
 */
export function SavedFaq( { eventId, value, bridge, onSaved } ) {
	const toast = useToast();
	const [ text, setText ] = useState( ( value && value.faq ) || '' );
	const [ save, saving ] = useSave( eventId, 'faq', onSaved );
	useEffect( () => setText( ( value && value.faq ) || '' ), [ value ] );
	const items = parseFaq( text );
	return (
		<div className="dev3ec-saved">
			<Inline align="between">
				<p className="dev3ec-label">{ __( 'Saved FAQ', 'dev3-ai-events-calendar' ) }</p>
				<Badge>{ sprintf( /* translators: %d: questions */ _n( '%d question', '%d questions', items.length, 'dev3-ai-events-calendar' ), items.length ) }</Badge>
			</Inline>
			<Textarea label={ __( 'Questions and answers', 'dev3-ai-events-calendar' ) } hideLabel value={ text } onChange={ setText } rows={ 8 } placeholder={ 'Q: …\nA: …' } help={ __( 'One “Q:” line and one “A:” line per question. Shown on the event page below the description.', 'dev3-ai-events-calendar' ) } />
			<div className="dev3ec-actions">
				<Button variant="primary" onClick={ () => save( { faq: text } ) } loading={ saving } disabled={ text === ( ( value && value.faq ) || '' ) }>
					{ __( 'Save FAQ', 'dev3-ai-events-calendar' ) }
				</Button>
				{ bridge && bridge.insertFaq && items.length > 0 && (
					<Button
						icon="plus"
						onClick={ () => {
							if ( bridge.insertFaq( items ) ) {
								toast.success( __( 'FAQ blocks inserted. Update the event to save them.', 'dev3-ai-events-calendar' ) );
							}
						} }
					>
						{ __( 'Insert as blocks', 'dev3-ai-events-calendar' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

/**
 * Saved SEO meta with length checks and a search preview.
 *
 * @param {Object}   props         Props.
 * @param {number}   props.eventId Event.
 * @param {Object}   props.value   { title, description }.
 * @param {Object}   props.event   Event.
 * @param {string}   props.target  Where values go (SEO plugin label).
 * @param {Object}   props.bridge  Editor bridge.
 * @param {Function} props.onSaved After save.
 * @return {Element} SEO.
 */
export function SavedSeo( { eventId, value, event, target, bridge, onSaved } ) {
	const [ seo, setSeo ] = useState( { title: '', description: '', ...value } );
	const [ save, saving ] = useSave( eventId, 'seo', ( r ) => {
		if ( bridge && bridge.syncSeo ) {
			bridge.syncSeo( r.saved );
		}
		if ( onSaved ) {
			onSaved( r );
		}
	} );
	useEffect( () => setSeo( { title: '', description: '', ...value } ), [ value ] );
	const tl = Array.from( seo.title || '' ).length;
	const dl = Array.from( seo.description || '' ).length;
	const tone = ( n, lo, hi ) => {
		if ( ! n ) {
			return 'neutral';
		}
		return n >= lo && n <= hi ? 'success' : 'warning';
	};
	const dirty = seo.title !== ( ( value && value.title ) || '' ) || seo.description !== ( ( value && value.description ) || '' );
	let host = '';
	try {
		host = event && event.url ? new URL( event.url ).host : '';
	} catch ( e ) {
		host = '';
	}
	return (
		<div className="dev3ec-saved">
			<p className="dev3ec-label">{ sprintf( /* translators: %s: SEO plugin */ __( 'Saved SEO meta · writes into %s', 'dev3-ai-events-calendar' ), target ) }</p>
			<div className="dev3ec-serp" aria-label={ __( 'Search result preview', 'dev3-ai-events-calendar' ) }>
				<span className="dev3ec-serp__url">{ host }</span>
				<span className="dev3ec-serp__title">{ seo.title || ( event && event.title ) }</span>
				<span className="dev3ec-serp__desc">{ seo.description || __( 'Search engines will pick text from the page.', 'dev3-ai-events-calendar' ) }</span>
			</div>
			<Stack gap={ 12 }>
				<div>
					<TextField label={ __( 'SEO title', 'dev3-ai-events-calendar' ) } value={ seo.title } onChange={ ( v ) => setSeo( { ...seo, title: v } ) } maxLength={ 90 } />
					<Badge tone={ tone( tl, 30, 60 ) }>{ sprintf( /* translators: %d: characters */ __( '%d / 60 characters', 'dev3-ai-events-calendar' ), tl ) }</Badge>
				</div>
				<div>
					<Textarea label={ __( 'Meta description', 'dev3-ai-events-calendar' ) } value={ seo.description } onChange={ ( v ) => setSeo( { ...seo, description: v } ) } rows={ 3 } />
					<Badge tone={ tone( dl, 110, 155 ) }>{ sprintf( /* translators: %d: characters */ __( '%d / 155 characters', 'dev3-ai-events-calendar' ), dl ) }</Badge>
				</div>
			</Stack>
			<div className="dev3ec-actions">
				<Button variant="primary" onClick={ () => save( seo ) } loading={ saving } disabled={ ! dirty }>
					{ __( 'Save SEO meta', 'dev3-ai-events-calendar' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Notice shown when the event has no date saved yet.
 *
 * @param {Object} props       Props.
 * @param {Object} props.event Event.
 * @return {Element|null} Notice.
 */
export function SaveFirst( { event } ) {
	if ( ! event || event.start ) {
		return null;
	}
	return <Notice tone="info">{ __( 'Save the event with its date and venue first, so DEV3 AI can use them.', 'dev3-ai-events-calendar' ) }</Notice>;
}
