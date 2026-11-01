import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import { loadStatus } from '../shared/Writer';
import Dashboard from './Dashboard';
import EventsTab from './EventsTab';
import EventWorkspace from './EventWorkspace';
import ImportTab from './ImportTab';
import ReviewTab from './ReviewTab';
import SettingsTab from './SettingsTab';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-events-calendar' ), icon: 'dashboard' },
	{ id: 'events', label: __( 'Events', 'dev3-ai-events-calendar' ), icon: 'list' },
	{ id: 'review', label: __( 'Review', 'dev3-ai-events-calendar' ), icon: 'diff' },
	{ id: 'import', label: __( 'Import flyer', 'dev3-ai-events-calendar' ), icon: 'file' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-events-calendar' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-events-calendar' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-events-calendar' ), icon: 'chart' },
];

const ROUTES = [ ...NAV.map( ( n ) => n.id ), 'event' ];

export default function App() {
	const [ route, navigate ] = useHashRoute( ROUTES, 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const load = () => {
		setError( null );
		Promise.all( [ request( 'settings' ), loadStatus( true ) ] )
			.then( ( [ s, st ] ) => {
				setSettings( s );
				setStatus( st );
			} )
			.catch( setError );
	};
	useEffect( load, [] );
	const refreshStatus = () => loadStatus( true ).then( setStatus ).catch( () => {} );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			refreshStatus();
			return next;
		} );
	const usage = () => setMeter( ( n ) => n + 1 );
	const pending = status && status.counts ? status.counts.drafts.pending : 0;
	const nav = NAV.map( ( n ) => ( n.id === 'review' && pending ? { ...n, badge: pending } : n ) );

	const screens = {
		dashboard: () => <Dashboard status={ status } navigate={ navigate } />,
		events: () => <EventsTab status={ status } navigate={ navigate } onUsage={ usage } onChanged={ refreshStatus } />,
		event: () => <EventWorkspace eventId={ Number( route.params[ 0 ] ) || 0 } onUsage={ usage } />,
		review: () => <ReviewTab status={ status } onChanged={ refreshStatus } />,
		import: () => <ImportTab status={ status } onUsage={ usage } onChanged={ refreshStatus } />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-events-calendar' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-events-calendar' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-events-calendar' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-events-calendar' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Event Writer for The Events Calendar' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id === 'event' ? 'events' : route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-events-calendar' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && status && screens[ route.id ]() }
		</AppShell>
	);
}
