import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, Checkbox, FormGrid, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';
import { TONES } from '../shared/kinds';
import { NETWORKS } from '../shared/social';

const KEYS = [ 'default_tone', 'default_language', 'networks', 'hashtags', 'faq_display', 'faq_schema', 'seo_output', 'email_from_name', 'email_reply_to', 'auto_reminder', 'reminder_hours', 'import_status', 'import_featured', 'delete_on_uninstall' ];

/**
 * Plugin settings (keys registered in includes/Settings.php).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );
	const nets = form.networks || [];

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => {
			patch[ k ] = form[ k ];
		} );
		patch.reminder_hours = form.reminder_hours === '' ? 24 : form.reminder_hours;
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-events-calendar' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<form onSubmit={ submit }>
			<PageHeader
				title={ __( 'Settings', 'dev3-ai-events-calendar' ) }
				description={ __( 'Defaults for the writer, what shows on event pages, emails and flyer imports.', 'dev3-ai-events-calendar' ) }
				actions={
					<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
						{ __( 'Save settings', 'dev3-ai-events-calendar' ) }
					</Button>
				}
			/>
			<Stack gap={ 16 }>
				<Card title={ __( 'Writing defaults', 'dev3-ai-events-calendar' ) }>
					<Stack gap={ 16 }>
						<FormGrid>
							<Select label={ __( 'Default tone', 'dev3-ai-events-calendar' ) } value={ form.default_tone } onChange={ set( 'default_tone' ) } options={ TONES } />
							<TextField label={ __( 'Default language', 'dev3-ai-events-calendar' ) } value={ form.default_language } onChange={ set( 'default_language' ) } placeholder={ __( 'Site language', 'dev3-ai-events-calendar' ) } help={ __( 'e.g. English, Deutsch, es_ES. Empty = site language.', 'dev3-ai-events-calendar' ) } />
							<TextField label={ __( 'Brand hashtags', 'dev3-ai-events-calendar' ) } value={ form.hashtags } onChange={ set( 'hashtags' ) } placeholder="#citylive #weekend" help={ __( 'Added to template social posts.', 'dev3-ai-events-calendar' ) } />
						</FormGrid>
						<fieldset className="dev3ec-fieldset">
							<legend>{ __( 'Default social networks', 'dev3-ai-events-calendar' ) }</legend>
							<div className="dev3ec-checks">
								{ Object.keys( NETWORKS ).map( ( n ) => (
									<Checkbox key={ n } label={ NETWORKS[ n ].label } checked={ nets.includes( n ) } onChange={ ( on ) => set( 'networks' )( on ? [ ...nets, n ] : nets.filter( ( x ) => x !== n ) ) } />
								) ) }
							</div>
						</fieldset>
					</Stack>
				</Card>
				<Card title={ __( 'Event pages', 'dev3-ai-events-calendar' ) }>
					<FormGrid>
						<Select
							label={ __( 'Show the FAQ', 'dev3-ai-events-calendar' ) }
							value={ form.faq_display }
							onChange={ set( 'faq_display' ) }
							options={ [
								{ value: 'append', label: __( 'Below the event description', 'dev3-ai-events-calendar' ) },
								{ value: 'shortcode', label: __( 'Only where I add the block or [dev3ec_faq]', 'dev3-ai-events-calendar' ) },
							] }
						/>
						<Select
							label={ __( 'SEO meta output', 'dev3-ai-events-calendar' ) }
							value={ form.seo_output }
							onChange={ set( 'seo_output' ) }
							help={ __( 'With Yoast SEO or Rank Math active, values go into their fields instead.', 'dev3-ai-events-calendar' ) }
							options={ [
								{ value: 'auto', label: __( 'Print meta tags when no SEO plugin is active', 'dev3-ai-events-calendar' ) },
								{ value: 'off', label: __( 'Never print meta tags', 'dev3-ai-events-calendar' ) },
							] }
						/>
					</FormGrid>
					<Toggle label={ __( 'Add FAQ schema (FAQPage JSON-LD)', 'dev3-ai-events-calendar' ) } checked={ !! form.faq_schema } onChange={ set( 'faq_schema' ) } />
				</Card>
				<Card title={ __( 'Emails', 'dev3-ai-events-calendar' ) }>
					<Stack gap={ 16 }>
						<FormGrid>
							<TextField label={ __( 'From name', 'dev3-ai-events-calendar' ) } value={ form.email_from_name } onChange={ set( 'email_from_name' ) } placeholder={ __( 'Site name', 'dev3-ai-events-calendar' ) } />
							<TextField label={ __( 'Reply-to address', 'dev3-ai-events-calendar' ) } type="email" value={ form.email_reply_to } onChange={ set( 'email_reply_to' ) } />
						</FormGrid>
						<Toggle label={ __( 'Send the saved reminder email automatically', 'dev3-ai-events-calendar' ) } checked={ !! form.auto_reminder } onChange={ set( 'auto_reminder' ) } help={ __( 'Once per event, to Event Tickets attendees, before the event starts.', 'dev3-ai-events-calendar' ) } />
						{ form.auto_reminder && <NumberField label={ __( 'Hours before the start', 'dev3-ai-events-calendar' ) } value={ form.reminder_hours } onChange={ set( 'reminder_hours' ) } min={ 1 } max={ 168 } /> }
					</Stack>
				</Card>
				<Card title={ __( 'Flyer imports', 'dev3-ai-events-calendar' ) }>
					<FormGrid>
						<Select
							label={ __( 'Imported events are saved as', 'dev3-ai-events-calendar' ) }
							value={ form.import_status }
							onChange={ set( 'import_status' ) }
							options={ [
								{ value: 'draft', label: __( 'Draft', 'dev3-ai-events-calendar' ) },
								{ value: 'pending', label: __( 'Pending review', 'dev3-ai-events-calendar' ) },
							] }
						/>
					</FormGrid>
					<Toggle label={ __( 'Use the flyer as the featured image by default', 'dev3-ai-events-calendar' ) } checked={ !! form.import_featured } onChange={ set( 'import_featured' ) } />
				</Card>
				<Card title={ __( 'Uninstall', 'dev3-ai-events-calendar' ) }>
					<Toggle label={ __( 'Delete DEV3 data when the plugin is deleted', 'dev3-ai-events-calendar' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } help={ __( 'Drafts, imports, saved social posts, emails, FAQs and settings. Events stay.', 'dev3-ai-events-calendar' ) } />
				</Card>
			</Stack>
		</form>
	);
}
