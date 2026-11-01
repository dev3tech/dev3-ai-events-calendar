import { createElement, render } from '@wordpress/element';
import * as element from '@wordpress/element';
import { ToastProvider } from '../core3/ui';
import Writer from '../shared/Writer';
import bridge from '../classic/bridge';
import '../core3/admin-kit.css';
import '../shared/writer.css';

const node = document.getElementById( 'dev3ec-metabox-root' );
if ( node ) {
	const app = createElement( ToastProvider, null, createElement( Writer, { eventId: Number( node.dataset.event ), bridge } ) );
	if ( element.createRoot ) {
		element.createRoot( node ).render( app );
	} else {
		render( app, node );
	}
}
