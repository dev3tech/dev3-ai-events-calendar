import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Checkbox, FormGrid, Icon, NumberField, Select, TextField, Textarea, cx, useUid } from '../core3/ui';
import { NETWORKS } from './social';
import { TONES } from './kinds';

/**
 * Default options for a kind.
 *
 * @param {string} kind   Kind.
 * @param {Object} status Status payload.
 * @return {Object} Options.
 */
export function defaultOptions( kind, status ) {
	const d = ( status && status.defaults ) || {};
	return {
		tone: d.tone || 'friendly',
		language: d.language || '',
		notes: '',
		length: 'medium',
		audience: '',
		networks: d.networks && d.networks.length ? d.networks : [ 'x', 'facebook', 'instagram', 'linkedin' ],
		count: 6,
		focus: '',
	};
}

/**
 * Writer options (tone, language, notes + per-kind extras), with "More options" on narrow screens.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.kind     Kind.
 * @param {Object}   props.value    Options.
 * @param {Function} props.onChange ( next ) => void.
 * @param {boolean}  props.compact  Sidebar layout.
 * @return {Element} Options form.
 */
export default function Options( { kind, value, onChange, compact = false } ) {
	const [ more, setMore ] = useState( ! compact );
	const id = useUid( 'dev3ec-opts' );
	const set = ( key ) => ( v ) => onChange( { ...value, [ key ]: v } );
	const notesLabel = {
		description: __( 'Notes for DEV3 AI (programme, speakers, what makes it special)', 'dev3-ai-events-calendar' ),
		email_recap: __( 'Highlights to mention (optional)', 'dev3-ai-events-calendar' ),
	};
	const toggleNetwork = ( n ) => ( on ) => {
		const next = on ? [ ...new Set( [ ...value.networks, n ] ) ] : value.networks.filter( ( x ) => x !== n );
		onChange( { ...value, networks: next } );
	};
	return (
		<div className={ cx( 'dev3ec-opts', compact && 'is-compact' ) }>
			<Textarea label={ notesLabel[ kind ] || __( 'Notes for DEV3 AI (optional)', 'dev3-ai-events-calendar' ) } value={ value.notes } onChange={ set( 'notes' ) } rows={ compact ? 3 : 3 } maxLength={ 1500 } />
			{ kind === 'social' && (
				<fieldset className="dev3ec-opts__nets">
					<legend>{ __( 'Networks', 'dev3-ai-events-calendar' ) }</legend>
					{ Object.keys( NETWORKS ).map( ( n ) => (
						<Checkbox key={ n } label={ NETWORKS[ n ].label } checked={ value.networks.includes( n ) } onChange={ toggleNetwork( n ) } />
					) ) }
				</fieldset>
			) }
			{ compact && (
				<button type="button" className="dev3ec-opts__more" aria-expanded={ more } aria-controls={ id } onClick={ () => setMore( ! more ) }>
					<Icon name="settings" size={ 14 } />
					{ more ? __( 'Fewer options', 'dev3-ai-events-calendar' ) : __( 'More options', 'dev3-ai-events-calendar' ) }
				</button>
			) }
			{ more && (
				<div id={ id }>
					<FormGrid>
						<Select label={ __( 'Tone', 'dev3-ai-events-calendar' ) } value={ value.tone } onChange={ set( 'tone' ) } options={ TONES } />
						<TextField label={ __( 'Language', 'dev3-ai-events-calendar' ) } value={ value.language } onChange={ set( 'language' ) } placeholder={ __( 'Site language', 'dev3-ai-events-calendar' ) } maxLength={ 40 } />
						{ kind === 'description' && (
							<>
								<Select
									label={ __( 'Length', 'dev3-ai-events-calendar' ) }
									value={ value.length }
									onChange={ set( 'length' ) }
									options={ [
										{ value: 'short', label: __( 'Short', 'dev3-ai-events-calendar' ) },
										{ value: 'medium', label: __( 'Medium', 'dev3-ai-events-calendar' ) },
										{ value: 'long', label: __( 'Long', 'dev3-ai-events-calendar' ) },
									] }
								/>
								<TextField label={ __( 'Audience', 'dev3-ai-events-calendar' ) } value={ value.audience } onChange={ set( 'audience' ) } placeholder={ __( 'e.g. families, developers', 'dev3-ai-events-calendar' ) } maxLength={ 120 } />
							</>
						) }
						{ kind === 'faq' && <NumberField label={ __( 'Questions', 'dev3-ai-events-calendar' ) } value={ value.count } onChange={ ( v ) => set( 'count' )( v === '' ? 6 : v ) } min={ 3 } max={ 10 } /> }
						{ kind === 'seo' && <TextField label={ __( 'Focus phrase', 'dev3-ai-events-calendar' ) } value={ value.focus } onChange={ set( 'focus' ) } placeholder={ __( 'e.g. jazz night Leeds', 'dev3-ai-events-calendar' ) } maxLength={ 80 } /> }
					</FormGrid>
				</div>
			) }
		</div>
	);
}
