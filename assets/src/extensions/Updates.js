// Solo nel branch con-updater: gli aggiornamenti di Barrio Suite e delle estensioni dalle release
// GitHub (src/Extensions.php), nella pagina Impostazioni e nella messa online. Barrio Suite si aggiorna
// come un'estensione, e gli aggiornamenti automatici sono quelli di WordPress (GET e PUT
// /barrio/v1/extensions). Extensions.js li passa alle due pagine.
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Card, Icon, Notice, Toggle, errorMessage } from '../admin/ui.js';

// Più recente di quella installata: le versioni sono x.y.z.
export const newer = ( available, installed ) =>
	available.localeCompare( installed, undefined, { numeric: true } ) > 0;

const autoUpdate = {
	id: 'updates',
	load: () => apiFetch( { path: '/barrio/v1/extensions' } ),
	value: ( data ) => data.auto_update,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/extensions',
			method: 'PUT',
			data: { auto_update: draft },
		} ),
};

const AutoUpdate = ( { draft, set, data, help } ) => (
	<Toggle
		label={ __( 'Update automatically every night', 'barrio-suite' ) }
		help={
			data.can_auto_update
				? help
				: __(
						'Automatic updates of plugins are not available on this site.',
						'barrio-suite'
					)
		}
		disabled={ ! data.can_auto_update }
		checked={ draft }
		onChange={ set }
	/>
);

export const updatesStep = {
	...autoUpdate,
	icon: 'update',
	title: __( 'Automatic updates', 'barrio-suite' ),
	description: __(
		'Barrio Suite and its extensions get fixes and new features by themselves.',
		'barrio-suite'
	),
	Fields: ( props ) => (
		<AutoUpdate
			{ ...props }
			help={ __(
				'Every night after midnight (site time) Barrio Suite and every installed extension are updated to the latest version.',
				'barrio-suite'
			) }
		/>
	),
};

function UpdatesCard( props ) {
	const { data, onData, onNotice } = props;
	// "refresh" o "update".
	const [ busy, setBusy ] = useState( null );

	const { version, installed } = data.suite;
	const available = version && newer( version, installed );
	let status = sprintf(
		/* translators: %s: installed version. */
		__( 'Barrio Suite %s is installed.', 'barrio-suite' ),
		installed
	);
	if ( available ) {
		status = sprintf(
			/* translators: 1: installed version; 2: available version. */
			__(
				'Barrio Suite %1$s is installed. Version %2$s is available.',
				'barrio-suite'
			),
			installed,
			version
		);
	} else if ( version ) {
		status = sprintf(
			/* translators: %s: installed version. */
			__(
				'Barrio Suite %s is installed, the latest version.',
				'barrio-suite'
			),
			installed
		);
	}
	const fail = ( error ) => {
		onNotice( { status: 'error', message: errorMessage( error ) } );
		setBusy( null );
	};
	// Senza la copia del catalogo dell'ultima ora; la scelta non salvata resta.
	const refresh = () => {
		setBusy( 'refresh' );
		onNotice( null );
		apiFetch( { path: '/barrio/v1/extensions?refresh=1' } )
			.then( ( response ) => {
				onData( response );
				setBusy( null );
			} )
			.catch( fail );
	};
	// Dopo l'aggiornamento la pagina si ricarica, così gira il codice nuovo.
	const update = () => {
		setBusy( 'update' );
		onNotice( null );
		apiFetch( {
			path: '/barrio/v1/extensions/barrio-suite',
			method: 'POST',
			data: { action: 'update' },
		} )
			.then( () => window.location.reload() )
			.catch( fail );
	};

	return (
		<Card
			className="barrio-wide"
			icon="update"
			title={ __( 'Updates', 'barrio-suite' ) }
			description={ __(
				'New versions of Barrio Suite and of its extensions are published on GitHub. They also appear in Dashboard → Updates, like those of the other plugins.',
				'barrio-suite'
			) }
			actions={
				<>
					<button
						type="button"
						className="barrio-button"
						disabled={ !! busy }
						onClick={ refresh }
					>
						<Icon name="update" />
						<span className="barrio-button__text">
							{ 'refresh' === busy
								? __( 'Checking…', 'barrio-suite' )
								: __( 'Check for updates', 'barrio-suite' ) }
						</span>
					</button>
					{ available && data.can_update && (
						<button
							type="button"
							className="barrio-button is-primary"
							disabled={ !! busy }
							onClick={ update }
						>
							{ 'update' === busy
								? __( 'Updating…', 'barrio-suite' )
								: sprintf(
										/* translators: %s: available version. */
										__( 'Update to %s', 'barrio-suite' ),
										version
									) }
						</button>
					) }
				</>
			}
		>
			<Notice
				notice={
					data.error && { status: 'error', message: data.error }
				}
			/>
			<p className="barrio-field__help">{ status }</p>
			<AutoUpdate
				{ ...props }
				help={ __(
					'Every night after midnight (site time) Barrio Suite and every installed extension are updated to the latest version. Each one can also be turned on or off in Plugins.',
					'barrio-suite'
				) }
			/>
		</Card>
	);
}

export const updatesSection = { ...autoUpdate, Cards: UpdatesCard };
