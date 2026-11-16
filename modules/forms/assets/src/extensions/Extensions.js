// Pagina Estensioni (Barrio → Extensions): le estensioni pubblicate nelle release GitHub
// (src/Extensions.php), ognuna un plugin separato, con installa, attiva, disattiva e aggiorna. Lo zip
// di un'estensione si può anche caricare da WordPress, e gli aggiornamenti compaiono anche in
// Bacheca → Aggiornamenti.
import apiFetch from '@wordpress/api-fetch';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Bar, Card, Icon, Loading, Notice, pageUrl } from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';

const DONE = {
	install: ( name ) =>
		/* translators: %s: nome dell'estensione. */
		sprintf( __( '%s installed and activated.', 'barrio-suite' ), name ),
	update: ( name ) =>
		/* translators: %s: nome dell'estensione. */
		sprintf( __( '%s updated.', 'barrio-suite' ), name ),
	activate: ( name ) =>
		/* translators: %s: nome dell'estensione. */
		sprintf( __( '%s activated.', 'barrio-suite' ), name ),
	deactivate: ( name ) =>
		/* translators: %s: nome dell'estensione. */
		sprintf( __( '%s deactivated.', 'barrio-suite' ), name ),
};

// "Mailchimp" e non "Barrio Suite Mailchimp", come nelle Impostazioni e nel builder: la pagina è già di Barrio Suite.
const short = ( name ) => name.replace( /^Barrio Suite /, '' );

// Più recente di quella installata: le versioni sono x.y.z.
const newer = ( available, installed ) =>
	available.localeCompare( installed, undefined, { numeric: true } ) > 0;

export function Extensions() {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	// L'azione in corso, come "mailchimp-install", o "refresh".
	const [ busy, setBusy ] = useState( null );

	const request = ( kind, options, message ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( ( response ) => {
				setData( response );
				if ( message ) {
					setNotice( { status: 'success', message } );
				}
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( null ) );
	};
	useEffect( () => {
		request( 'load', { path: '/barrio/v1/extensions' } );
	}, [] );

	if ( ! data ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const act = ( extension, action ) =>
		request(
			`${ extension.slug }-${ action }`,
			{
				path: `/barrio/v1/extensions/${ extension.slug }`,
				method: 'POST',
				data: { action },
			},
			DONE[ action ]( short( extension.name ) )
		);
	const button = ( extension, action, label, primary ) => (
		<button
			type="button"
			className={ `barrio-button${ primary ? ' is-primary' : '' }` }
			disabled={ !! busy }
			onClick={ () => act( extension, action ) }
		>
			{ `${ extension.slug }-${ action }` === busy
				? __( 'Please wait…', 'barrio-suite' )
				: label }
		</button>
	);

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Extensions', 'barrio-suite' ) }>
				{ data.can_install && (
					<a className="barrio-button" href={ data.upload_url }>
						<Icon name="upload" />
						<span className="barrio-button__text">
							{ __( 'Upload a zip', 'barrio-suite' ) }
						</span>
					</a>
				) }
				<button
					type="button"
					className="barrio-button"
					disabled={ !! busy }
					onClick={ () =>
						request( 'refresh', {
							path: '/barrio/v1/extensions?refresh=1',
						} )
					}
				>
					<Icon name="update" />
					<span className="barrio-button__text">
						{ 'refresh' === busy
							? __( 'Checking…', 'barrio-suite' )
							: __( 'Check for updates', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Notice
				notice={
					data.error && { status: 'error', message: data.error }
				}
			/>
			<p className="barrio-field__help">
				{ createInterpolateElement(
					__(
						'Every extension is a separate plugin: it can also be deactivated or deleted from Plugins. Account settings, like API keys, are in <a>Forms → Settings</a>; each form chooses in its Integrations tab whether to use an extension and how.',
						'barrio-suite'
					),
					// eslint-disable-next-line jsx-a11y/anchor-has-content -- il testo arriva da createInterpolateElement.
					{ a: <a href={ pageUrl( { tab: 'settings' } ) } /> }
				) }
			</p>
			{ data.extensions.map( ( extension ) => {
				const update =
					extension.installed &&
					extension.version &&
					newer( extension.version, extension.installed );
				let status = sprintf(
					/* translators: %s: versione, per esempio 0.2.0. */
					__( 'Not installed. Latest version: %s.', 'barrio-suite' ),
					extension.version
				);
				if ( extension.installed ) {
					status = update
						? sprintf(
								/* translators: 1: versione installata; 2: versione disponibile. */
								__(
									'Version %1$s installed. Version %2$s is available.',
									'barrio-suite'
								),
								extension.installed,
								extension.version
							)
						: sprintf(
								/* translators: %s: versione installata. */
								__( 'Version %s installed.', 'barrio-suite' ),
								extension.installed
							);
				}
				return (
					<Card
						key={ extension.slug }
						className="barrio-wide"
						icon="admin-plugins"
						title={ short( extension.name ) }
						description={ extension.description }
						actions={
							<>
								{ extension.active && (
									<span className="barrio-badge is-success">
										{ __( 'Active', 'barrio-suite' ) }
									</span>
								) }
								{ ! extension.installed &&
									data.can_install &&
									button(
										extension,
										'install',
										__( 'Install', 'barrio-suite' ),
										true
									) }
								{ extension.active &&
									button(
										extension,
										'deactivate',
										__( 'Deactivate', 'barrio-suite' )
									) }
								{ extension.installed &&
									! extension.active &&
									button(
										extension,
										'activate',
										__( 'Activate', 'barrio-suite' ),
										! update
									) }
								{ update &&
									data.can_update &&
									button(
										extension,
										'update',
										__( 'Update', 'barrio-suite' ),
										true
									) }
							</>
						}
					>
						<p className="barrio-field__help">{ status }</p>
					</Card>
				);
			} ) }
			{ ! data.extensions.length && (
				<Card
					description={ __(
						'No extensions are available yet.',
						'barrio-suite'
					) }
				/>
			) }
		</div>
	);
}
