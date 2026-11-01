import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Card, Columns, EmptyState, Notice, PageHeader, ResponsiveTable, Stat, StatGrid } from '../core3/ui';
import { importStatus } from './util';

/**
 * Dashboard: KPIs, events that need content, recent flyer imports and quick actions.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.status   Status payload.
 * @param {Function} props.navigate Router.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { status, navigate } ) {
	const c = status.counts || {};
	const d = c.drafts || {};
	const ai = status.ai || {};
	const columns = [
		{
			key: 'title',
			label: __( 'Event', 'dev3-ai-events-calendar' ),
			primary: true,
			render: ( r ) => (
				<a
					href={ `#/event/${ r.id }` }
					onClick={ ( e ) => {
						e.stopPropagation();
					} }
				>
					{ r.title }
				</a>
			),
		},
		{ key: 'date_label', label: __( 'Date', 'dev3-ai-events-calendar' ) },
		{ key: 'venue', label: __( 'Venue', 'dev3-ai-events-calendar' ), hideOnMobile: true, render: ( r ) => r.venue || '—' },
	];
	const imports = status.imports || [];
	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-events-calendar' ) }
				description={ __( 'Descriptions, social posts, emails, FAQs and SEO for The Events Calendar, written as drafts you review.', 'dev3-ai-events-calendar' ) }
				actions={
					<>
						<Button icon="file" onClick={ () => navigate( 'import' ) }>
							{ __( 'Import a flyer', 'dev3-ai-events-calendar' ) }
						</Button>
						<Button variant="primary" icon="sparkles" onClick={ () => navigate( 'events' ) }>
							{ __( 'Write for events', 'dev3-ai-events-calendar' ) }
						</Button>
					</>
				}
			/>
			{ ! ai.key_set && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-events-calendar' ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-events-calendar' ) }</Button> }>
					{ __( 'Add a Google Gemini API key to generate drafts and read flyers. Templates, manual editing and emails work without it.', 'dev3-ai-events-calendar' ) }
				</Notice>
			) }
			{ ! status.tickets && (
				<Notice tone="info" title={ __( 'Tip: Event Tickets (free)', 'dev3-ai-events-calendar' ) }>
					{ __( 'With Event Tickets, recap emails use real attendance numbers and you can email attendees right from the writer.', 'dev3-ai-events-calendar' ) }
				</Notice>
			) }
			<StatGrid>
				<Stat label={ __( 'Upcoming events', 'dev3-ai-events-calendar' ) } value={ String( c.upcoming || 0 ) } icon="clock" tone="brand" />
				<Stat label={ __( 'Need a description', 'dev3-ai-events-calendar' ) } value={ String( c.missing_description || 0 ) } icon="file" tone={ c.missing_description ? 'warning' : 'success' } hint={ __( 'Upcoming, under 300 characters', 'dev3-ai-events-calendar' ) } />
				<Stat label={ __( 'Drafts to review', 'dev3-ai-events-calendar' ) } value={ String( d.pending || 0 ) } icon="diff" tone={ d.pending ? 'warning' : 'neutral' } />
				<Stat label={ __( 'Accepted this month', 'dev3-ai-events-calendar' ) } value={ String( d.accepted_month || 0 ) } icon="check" tone="success" />
			</StatGrid>
			<Columns min={ 320 }>
				<Card
					title={ __( 'Upcoming events that need content', 'dev3-ai-events-calendar' ) }
					flush
					actions={
						<Button size="sm" variant="ghost" iconRight="chevronRight" onClick={ () => navigate( 'events' ) }>
							{ __( 'All events', 'dev3-ai-events-calendar' ) }
						</Button>
					}
				>
					<ResponsiveTable
						columns={ columns }
						rows={ status.needs_content || [] }
						onRowClick={ ( r ) => navigate( `event/${ r.id }` ) }
						empty={ { icon: 'check', title: __( 'All caught up', 'dev3-ai-events-calendar' ), description: __( 'Every upcoming event has a description.', 'dev3-ai-events-calendar' ) } }
					/>
				</Card>
				<Card
					title={ __( 'Recent flyer imports', 'dev3-ai-events-calendar' ) }
					actions={
						<Button size="sm" variant="ghost" iconRight="chevronRight" onClick={ () => navigate( 'import' ) }>
							{ __( 'Import', 'dev3-ai-events-calendar' ) }
						</Button>
					}
				>
					{ imports.length ? (
						<ul className="dev3ec-list">
							{ imports.map( ( im ) => {
								const s = importStatus( im.status );
								return (
									<li key={ im.id }>
										<span className="dev3ec-list__main">{ ( im.draft && im.draft.title ) || im.file_name || sprintf( /* translators: %d: id */ __( 'Import #%d', 'dev3-ai-events-calendar' ), im.id ) }</span>
										<Badge tone={ s.tone }>{ s.label }</Badge>
										{ im.event_edit && (
											<a href={ im.event_edit } className="dev3ec-list__link">
												{ __( 'Edit event', 'dev3-ai-events-calendar' ) }
											</a>
										) }
									</li>
								);
							} ) }
						</ul>
					) : (
						<EmptyState compact icon="file" title={ __( 'No imports yet', 'dev3-ai-events-calendar' ) } description={ __( 'Upload a flyer or poster and DEV3 AI turns it into a draft event.', 'dev3-ai-events-calendar' ) } action={ <Button onClick={ () => navigate( 'import' ) }>{ __( 'Import a flyer', 'dev3-ai-events-calendar' ) }</Button> } />
					) }
				</Card>
			</Columns>
			<Card title={ __( 'Where to find DEV3 AI', 'dev3-ai-events-calendar' ) }>
				<ul className="dev3ec-where">
					<li>
						<strong>{ __( 'Event editor', 'dev3-ai-events-calendar' ) }</strong> { status.blocks ? __( 'Open an event and click the DEV3 AI Event Writer icon in the top bar (block editor).', 'dev3-ai-events-calendar' ) : __( 'Open an event: the DEV3 AI Event Writer box sits under the editor (classic editor).', 'dev3-ai-events-calendar' ) }
					</li>
					<li>
						<strong>{ __( 'Events list', 'dev3-ai-events-calendar' ) }</strong> { __( 'Use the “Write with DEV3 AI” row link, or select events and choose “Generate with DEV3 AI” from Bulk actions.', 'dev3-ai-events-calendar' ) }
					</li>
					<li>
						<strong>{ __( 'SEO', 'dev3-ai-events-calendar' ) }</strong> { sprintf( /* translators: %s: SEO plugin */ __( 'SEO meta is written into %s.', 'dev3-ai-events-calendar' ), ( status.seo && status.seo.label ) || '' ) }
					</li>
				</ul>
			</Card>
		</>
	);
}
