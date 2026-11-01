import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, ToastProvider } from '../core3/ui';
import Writer from '../shared/Writer';
import bridge from './bridge';

/**
 * "DEV3 AI Event Writer" sidebar in the TEC block editor.
 *
 * @return {Element} Sidebar.
 */
export default function Sidebar() {
	const { postId, saving, dirty } = useSelect( ( s ) => {
		const ed = s( 'core/editor' );
		return {
			postId: ed.getCurrentPostId(),
			saving: ed.isSavingPost() && ! ed.isAutosavingPost(),
			dirty: ed.isEditedPostDirty(),
		};
	}, [] );
	const [ refresh, setRefresh ] = useState( 0 );
	const wasSaving = useRef( false );
	// Re-read the event after each manual save (dates, venue and content may have changed).
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			setRefresh( ( n ) => n + 1 );
		}
		wasSaving.current = saving;
	}, [ saving ] );
	return (
		<ToastProvider>
			<div className="dev3ec-root dev3ec-sidebar">
				{ dirty && <Notice tone="info">{ __( 'DEV3 AI reads the last saved version of the event. Save to include your latest changes.', 'dev3-ai-events-calendar' ) }</Notice> }
				<Writer eventId={ postId } bridge={ bridge } compact refreshKey={ refresh } />
			</div>
		</ToastProvider>
	);
}
