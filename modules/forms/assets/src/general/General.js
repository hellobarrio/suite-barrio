// Pagina Impostazioni (Barrio → Settings): le impostazioni di tutto il plugin, per ora i dati alla
// disinstallazione (GET e PUT /barrio/v1/uninstall, src/Uninstall.php).
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Check,
	Loading,
	Lucide,
	Notice,
	useUnsavedWarning,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';

export function General() {
	const [ saved, setSaved ] = useState( null );
	const [ deleteData, setDeleteData ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = ( response ) => {
		setSaved( response.delete_data );
		setDeleteData( response.delete_data );
	};
	useEffect( () => {
		apiFetch( { path: '/barrio/v1/uninstall' } )
			.then( load )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			);
	}, [] );

	const dirty = null !== saved && deleteData !== saved;
	useUnsavedWarning( dirty );

	if ( null === saved ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const save = () => {
		setBusy( true );
		setNotice( null );
		apiFetch( {
			path: '/barrio/v1/uninstall',
			method: 'PUT',
			data: { delete_data: deleteData },
		} )
			.then( ( response ) => {
				load( response );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'barrio-suite' ),
				} );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Settings', 'barrio-suite' ) }>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ! dirty }
					onClick={ save }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Card
				className="barrio-wide"
				icon="trash"
				title={ __( 'Uninstall', 'barrio-suite' ) }
				description={ __(
					'Deactivating Barrio Suite never deletes anything. Deleting it from Plugins keeps the data too, unless you choose otherwise here.',
					'barrio-suite'
				) }
			>
				<Check
					label={ __(
						'Delete all data when Barrio Suite is deleted',
						'barrio-suite'
					) }
					help={ __(
						'Forms, entries with their files, settings and permissions of this site. It cannot be undone: export the entries you need first.',
						'barrio-suite'
					) }
					checked={ deleteData }
					onChange={ setDeleteData }
				/>
			</Card>
		</div>
	);
}
