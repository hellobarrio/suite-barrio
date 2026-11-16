// Pagina Impostazioni (Barrio → Settings): le impostazioni di tutto il plugin. Gli aggiornamenti:
// Barrio Suite si aggiorna come un'estensione, dal catalogo delle release GitHub, e gli aggiornamenti
// automatici sono quelli di WordPress (GET e PUT /barrio/v1/extensions, src/Extensions.php). I dati alla
// disinstallazione (GET e PUT /barrio/v1/uninstall, src/Uninstall.php), solo per gli amministratori.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Check,
	Icon,
	Loading,
	Lucide,
	Notice,
	useUnsavedWarning,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';
import { newer } from '../extensions/Extensions.js';

const { canManageOptions } = window.barrioAdmin;

export function General() {
	const [ data, setData ] = useState( null );
	const [ autoUpdate, setAutoUpdate ] = useState( false );
	// null finché non si sa, o per chi non può sceglierlo.
	const [ savedDelete, setSavedDelete ] = useState( null );
	const [ deleteData, setDeleteData ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	// L'azione in corso: "refresh", "update" o "save".
	const [ busy, setBusy ] = useState( null );

	// keep: lascia la scelta non salvata, quando si cercano aggiornamenti.
	const request = ( kind, options, keep ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( ( response ) => {
				setData( response );
				if ( ! keep ) {
					setAutoUpdate( response.auto_update );
				}
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( null ) );
	};
	const setDelete = ( response ) => {
		setSavedDelete( response.delete_data );
		setDeleteData( response.delete_data );
	};
	useEffect( () => {
		request( 'load', { path: '/barrio/v1/extensions' } );
		if ( canManageOptions ) {
			apiFetch( { path: '/barrio/v1/uninstall' } )
				.then( setDelete )
				.catch( ( error ) =>
					setNotice( {
						status: 'error',
						message: errorMessage( error ),
					} )
				);
		}
	}, [] );

	const dirty =
		( !! data && autoUpdate !== data.auto_update ) ||
		( null !== savedDelete && deleteData !== savedDelete );
	useUnsavedWarning( dirty );

	if ( ! data ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const { version, installed } = data.suite;
	const available = version && newer( version, installed );
	let status = sprintf(
		/* translators: %s: versione installata. */
		__( 'Barrio Suite %s is installed.', 'barrio-suite' ),
		installed
	);
	if ( available ) {
		status = sprintf(
			/* translators: 1: versione installata; 2: versione disponibile. */
			__(
				'Barrio Suite %1$s is installed. Version %2$s is available.',
				'barrio-suite'
			),
			installed,
			version
		);
	} else if ( version ) {
		status = sprintf(
			/* translators: %s: versione installata. */
			__(
				'Barrio Suite %s is installed, the latest version.',
				'barrio-suite'
			),
			installed
		);
	}
	// Dopo l'aggiornamento la pagina si ricarica, così gira il codice nuovo.
	const update = () => {
		setBusy( 'update' );
		setNotice( null );
		apiFetch( {
			path: '/barrio/v1/extensions/barrio-suite',
			method: 'POST',
			data: { action: 'update' },
		} )
			.then( () => window.location.reload() )
			.catch( ( error ) => {
				setNotice( {
					status: 'error',
					message: errorMessage( error ),
				} );
				setBusy( null );
			} );
	};

	// Solo le parti cambiate, ognuna con la sua rotta.
	const save = () => {
		setBusy( 'save' );
		setNotice( null );
		Promise.all( [
			autoUpdate !== data.auto_update &&
				apiFetch( {
					path: '/barrio/v1/extensions',
					method: 'PUT',
					data: { auto_update: autoUpdate },
				} ).then( ( response ) => {
					setData( response );
					setAutoUpdate( response.auto_update );
				} ),
			null !== savedDelete &&
				deleteData !== savedDelete &&
				apiFetch( {
					path: '/barrio/v1/uninstall',
					method: 'PUT',
					data: { delete_data: deleteData },
				} ).then( setDelete ),
		] )
			.then( () =>
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'barrio-suite' ),
				} )
			)
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( null ) );
	};

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Settings', 'barrio-suite' ) }>
				<button
					type="button"
					className="barrio-button"
					disabled={ !! busy }
					onClick={ () =>
						request(
							'refresh',
							{ path: '/barrio/v1/extensions?refresh=1' },
							true
						)
					}
				>
					<Icon name="update" />
					<span className="barrio-button__text">
						{ 'refresh' === busy
							? __( 'Checking…', 'barrio-suite' )
							: __( 'Check for updates', 'barrio-suite' ) }
					</span>
				</button>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ !! busy || ! dirty }
					onClick={ save }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ 'save' === busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Notice
				notice={
					data.error && { status: 'error', message: data.error }
				}
			/>
			<Card
				className="barrio-wide"
				icon="update"
				title={ __( 'Updates', 'barrio-suite' ) }
				description={ __(
					'New versions of Barrio Suite and of its extensions are published on GitHub. They also appear in Dashboard → Updates, like those of the other plugins.',
					'barrio-suite'
				) }
				actions={
					available &&
					data.can_update && (
						<button
							type="button"
							className="barrio-button is-primary"
							disabled={ !! busy }
							onClick={ update }
						>
							{ 'update' === busy
								? __( 'Updating…', 'barrio-suite' )
								: sprintf(
										/* translators: %s: versione disponibile. */
										__( 'Update to %s', 'barrio-suite' ),
										version
									) }
						</button>
					)
				}
			>
				<p className="barrio-field__help">{ status }</p>
				<Check
					label={ __(
						'Update automatically every night',
						'barrio-suite'
					) }
					help={
						data.can_auto_update
							? __(
									'Every night after midnight (site time) Barrio Suite and every installed extension are updated to the latest version. Each one can also be turned on or off in Plugins.',
									'barrio-suite'
								)
							: __(
									'Automatic updates of plugins are not available on this site.',
									'barrio-suite'
								)
					}
					disabled={ ! data.can_auto_update }
					checked={ autoUpdate }
					onChange={ setAutoUpdate }
				/>
			</Card>
			{ null !== savedDelete && (
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
			) }
		</div>
	);
}
