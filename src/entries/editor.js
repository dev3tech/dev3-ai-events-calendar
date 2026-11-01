import * as editorPkg from '@wordpress/editor';
import * as editPost from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import Sidebar from '../editor/Sidebar';
import '../core3/admin-kit.css';
import '../shared/writer.css';

// WP 6.6+ exports the sidebar slots from @wordpress/editor; older versions from @wordpress/edit-post.
const PluginSidebar = editorPkg.PluginSidebar || editPost.PluginSidebar;
const PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem || editPost.PluginSidebarMoreMenuItem;

const Mark = () => (
	<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
		<path fill="currentColor" d="M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h7v-2H5V9h14v3h2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7Zm11 12-1.1 2.4L14.5 17.5l2.4 1.1L18 21l1.1-2.4 2.4-1.1-2.4-1.1L18 14Z" />
	</svg>
);

registerPlugin( 'dev3-ai-events-calendar', {
	icon: <Mark />,
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="dev3ec-sidebar" icon={ <Mark /> }>
				{ __( 'DEV3 AI Event Writer', 'dev3-ai-events-calendar' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="dev3ec-sidebar" title={ __( 'DEV3 AI Event Writer', 'dev3-ai-events-calendar' ) } icon={ <Mark /> }>
				<Sidebar />
			</PluginSidebar>
		</>
	),
} );
