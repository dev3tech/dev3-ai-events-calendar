import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

registerBlockType( 'dev3ec/event-faq', {
	apiVersion: 2,
	title: __( 'Event FAQ', 'dev3-ai-events-calendar' ),
	description: __( 'Questions and answers of an event (written in DEV3 AI Event Writer), with FAQ schema.', 'dev3-ai-events-calendar' ),
	category: 'widgets',
	icon: 'editor-help',
	keywords: [ 'faq', 'event', 'questions' ],
	attributes: {
		eventId: { type: 'number', default: 0 },
		heading: { type: 'string', default: '' },
	},
	supports: { align: [ 'wide', 'full' ], spacing: { margin: true } },
	edit: function Edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		const current = useSelect( ( s ) => ( s( 'core/editor' ) ? s( 'core/editor' ).getCurrentPostId() : 0 ), [] );
		const id = attributes.eventId || current;
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'FAQ', 'dev3-ai-events-calendar' ) }>
						<TextControl
							label={ __( 'Event ID (empty = this event)', 'dev3-ai-events-calendar' ) }
							type="number"
							value={ attributes.eventId || '' }
							onChange={ ( v ) => setAttributes( { eventId: Number( v ) || 0 } ) }
						/>
						<TextControl label={ __( 'Heading', 'dev3-ai-events-calendar' ) } value={ attributes.heading } onChange={ ( v ) => setAttributes( { heading: v } ) } />
					</PanelBody>
				</InspectorControls>
				<ServerSideRender
					block="dev3ec/event-faq"
					attributes={ { ...attributes, eventId: id } }
					EmptyResponsePlaceholder={ () => <p className="dev3ec-faq-empty">{ __( 'No FAQ saved for this event yet. Write one in the DEV3 AI Event Writer sidebar.', 'dev3-ai-events-calendar' ) }</p> }
				/>
			</div>
		);
	},
	save: () => null,
} );
