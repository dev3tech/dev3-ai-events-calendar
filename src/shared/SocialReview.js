import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, DiffView, Icon, Textarea, diffWords, formatNumber } from '../core3/ui';
import { counter, networkLabel, overLimitText } from './social';

/**
 * Live character counter badge.
 *
 * @param {Object} props         Props.
 * @param {string} props.text    Text.
 * @param {string} props.network Network.
 * @return {Element} Badge.
 */
export function Counter( { text, network } ) {
	const c = counter( text, network );
	return (
		<Badge tone={ c.tone } className="dev3ec-counter">
			<span className="dev3ec-sr">{ __( 'Characters:', 'dev3-ai-events-calendar' ) } </span>
			{ c.label }
		</Badge>
	);
}

/**
 * Review of social drafts: one card per network with diff against the saved post, live counters
 * (network counting rules) and inline edit. Nothing is saved until Accept.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.draft        { network: text }.
 * @param {Object}   props.original     Saved posts.
 * @param {Function} props.onAccept     ( values ) => void.
 * @param {Function} props.onReject     () => void.
 * @param {Function} props.onRegenerate () => void.
 * @param {boolean}  props.regenerating Busy.
 * @param {boolean}  props.accepting    Busy.
 * @param {Object}   props.meta         { model, tokens, cached }.
 * @param {string}   props.title        Title.
 * @return {Element} Review.
 */
export default function SocialReview( { draft, original = {}, onAccept, onReject, onRegenerate, regenerating, accepting, meta, title } ) {
	const [ values, setValues ] = useState( { ...draft } );
	const [ editing, setEditing ] = useState( false );
	useEffect( () => {
		setValues( { ...draft } );
		setEditing( false );
	}, [ draft ] );
	const busy = regenerating || accepting;
	const over = Object.keys( values ).filter( ( n ) => counter( values[ n ], n ).tone === 'danger' );
	return (
		<section className="dev3ec-ui-draft dev3ec-social-review" aria-label={ title }>
			<header className="dev3ec-ui-draft__head">
				<div className="dev3ec-ui-draft__title">
					<span className="dev3ec-ui-draft__spark" aria-hidden="true">
						<Icon name="sparkles" size={ 16 } />
					</span>
					<h3>{ title }</h3>
					<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-events-calendar' ) }</Badge>
				</div>
			</header>
			<div className="dev3ec-ui-draft__body">
				<ul className="dev3ec-netlist">
					{ Object.keys( values ).map( ( n ) => {
						const before = String( original[ n ] || '' );
						const after = String( values[ n ] || '' );
						return (
							<li key={ n } className="dev3ec-net">
								<div className="dev3ec-net__head">
									<strong>{ networkLabel( n ) }</strong>
									<Counter text={ after } network={ n } />
								</div>
								{ editing ? (
									<Textarea label={ sprintf( /* translators: %s: network */ __( '%s post', 'dev3-ai-events-calendar' ), networkLabel( n ) ) } hideLabel value={ after } rows={ 4 } onChange={ ( v ) => setValues( { ...values, [ n ]: v } ) } />
								) : (
									<div className="dev3ec-net__text">{ before && before !== after ? <DiffView parts={ diffWords( before, after ) } /> : after }</div>
								) }
								{ counter( after, n ).tone === 'danger' && <p className="dev3ec-help is-danger">{ overLimitText }</p> }
							</li>
						);
					} ) }
				</ul>
			</div>
			<footer className="dev3ec-ui-draft__foot">
				<div className="dev3ec-ui-draft__meta">
					{ meta && meta.model && <span>{ meta.model }</span> }
					{ meta && meta.tokens > 0 && <span>{ sprintf( /* translators: %s: tokens */ __( '%s tokens', 'dev3-ai-events-calendar' ), formatNumber( meta.tokens ) ) }</span> }
					{ meta && meta.cached && <Badge tone="info">{ __( 'Cached', 'dev3-ai-events-calendar' ) }</Badge> }
				</div>
				<div className="dev3ec-ui-draft__actions">
					{ onReject && (
						<Button variant="ghost" icon="x" onClick={ onReject } disabled={ busy }>
							{ __( 'Reject', 'dev3-ai-events-calendar' ) }
						</Button>
					) }
					{ onRegenerate && (
						<Button icon="refresh" onClick={ onRegenerate } loading={ regenerating } disabled={ busy }>
							{ __( 'Regenerate', 'dev3-ai-events-calendar' ) }
						</Button>
					) }
					<Button icon={ editing ? 'check' : 'edit' } onClick={ () => setEditing( ! editing ) } disabled={ busy } aria-pressed={ editing }>
						{ editing ? __( 'Done editing', 'dev3-ai-events-calendar' ) : __( 'Edit', 'dev3-ai-events-calendar' ) }
					</Button>
					<Button variant="primary" icon="check" onClick={ () => onAccept( values ) } loading={ accepting } disabled={ busy || over.length > 0 }>
						{ __( 'Accept', 'dev3-ai-events-calendar' ) }
					</Button>
				</div>
			</footer>
		</section>
	);
}
