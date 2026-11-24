// Pagina Estensioni (Barrio → Extensions): le estensioni pubblicate nelle release GitHub
// (src/Extensions.php), ognuna un plugin separato, con installa, attiva, disattiva e aggiorna. Lo zip
// di un'estensione si può anche caricare da WordPress, e gli aggiornamenti compaiono anche in
// Bacheca → Aggiornamenti. Questo file dipende dal branch, come src/Extensions.php: in con-updater porta
// anche gli aggiornamenti (Updates.js) nella messa online e nelle Impostazioni.
import apiFetch from '@wordpress/api-fetch';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { pageUrl } from '../../../modules/forms/assets/src/ui.js';
import {
	Bar,
	Card,
	Icon,
	Loading,
	Notice,
	Search,
	errorMessage,
} from '../admin/ui.js';
import { newer, updatesSection, updatesStep } from './Updates.js';

// Passaggi della messa online (assets/src/launch) e sezioni delle Impostazioni (assets/src/general).
export const launchSteps = [ updatesStep ];
export const settingsSections = [ updatesSection ];

const DONE = {
	install: ( name ) =>
		/* translators: %s: extension name. */
		sprintf( __( '%s installed and activated.', 'barrio-suite' ), name ),
	update: ( name ) =>
		/* translators: %s: extension name. */
		sprintf( __( '%s updated.', 'barrio-suite' ), name ),
	activate: ( name ) =>
		/* translators: %s: extension name. */
		sprintf( __( '%s activated.', 'barrio-suite' ), name ),
	deactivate: ( name ) =>
		/* translators: %s: extension name. */
		sprintf( __( '%s deactivated.', 'barrio-suite' ), name ),
	delete: ( name ) =>
		/* translators: %s: extension name. */
		sprintf( __( '%s deleted.', 'barrio-suite' ), name ),
};

// "Mailchimp" e non "Barrio Suite Mailchimp", come nelle Impostazioni e nel builder: la pagina è già di Barrio Suite.
const short = ( name ) => name.replace( /^Barrio Suite /, '' );

export function Extensions() {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	// L'azione in corso, come "mailchimp-install", o "refresh".
	const [ busy, setBusy ] = useState( null );
	const [ search, setSearch ] = useState( '' );

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
	// Elimina chiede conferma: non si annulla.
	const confirmed = ( extension, action ) =>
		'delete' !== action ||
		// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
		window.confirm(
			sprintf(
				/* translators: %s: extension name. */
				__(
					'Delete %s? Its files are removed from the site; you can install it again from this page.',
					'barrio-suite'
				),
				short( extension.name )
			)
		);
	const button = ( extension, action, label, style = '' ) => {
		const text =
			`${ extension.slug }-${ action }` === busy
				? __( 'Please wait…', 'barrio-suite' )
				: label;
		return (
			<button
				type="button"
				className={ `barrio-button ${ style }` }
				// Con il nome dell'estensione: i bottoni delle card sono uguali.
				aria-label={ `${ text } ${ short( extension.name ) }` }
				disabled={ !! busy }
				onClick={ () =>
					confirmed( extension, action ) && act( extension, action )
				}
			>
				{ text }
			</button>
		);
	};
	const term = search.trim().toLowerCase();
	const found = data.extensions.filter( ( extension ) =>
		`${ extension.name } ${ extension.description }`
			.toLowerCase()
			.includes( term )
	);

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Extensions', 'barrio-suite' ) }>
				<div className="barrio-bar__tools">
					<Search
						label={ __( 'Search extensions', 'barrio-suite' ) }
						value={ search }
						onChange={ setSearch }
					/>
				</div>
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
				{ __(
					'Every extension is a separate plugin: it can also be deactivated or deleted from Plugins.',
					'barrio-suite'
				) }
				{ window.barrioAdmin.forms && ' ' }
				{ window.barrioAdmin.forms &&
					createInterpolateElement(
						__(
							'The account settings of the forms integrations, like API keys, are in <a>Forms → Settings</a>; each form chooses in its Integrations tab whether to use one and how.',
							'barrio-suite'
						),
						// eslint-disable-next-line jsx-a11y/anchor-has-content -- il testo arriva da createInterpolateElement.
						{ a: <a href={ pageUrl( { tab: 'settings' } ) } /> }
					) }
			</p>
			{ found.map( ( extension ) => {
				const update =
					extension.installed &&
					extension.version &&
					newer( extension.version, extension.installed );
				let status = sprintf(
					/* translators: %s: version number, for example 0.2.0. */
					__( 'Not installed. Latest version: %s.', 'barrio-suite' ),
					extension.version
				);
				if ( extension.installed ) {
					status = update
						? sprintf(
								/* translators: 1: installed version; 2: available version. */
								__(
									'Version %1$s installed. Version %2$s is available.',
									'barrio-suite'
								),
								extension.installed,
								extension.version
							)
						: sprintf(
								/* translators: %s: installed version. */
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
										'is-primary'
									) }
								{ extension.active &&
									button(
										extension,
										'deactivate',
										__( 'Deactivate', 'barrio-suite' )
									) }
								{ /* Prima delle altre: la principale resta per ultima. */ }
								{ extension.installed &&
									! extension.active &&
									data.can_delete &&
									button(
										extension,
										'delete',
										__( 'Delete', 'barrio-suite' ),
										'is-danger'
									) }
								{ extension.installed &&
									! extension.active &&
									button(
										extension,
										'activate',
										__( 'Activate', 'barrio-suite' ),
										update ? '' : 'is-primary'
									) }
								{ update &&
									data.can_update &&
									button(
										extension,
										'update',
										__( 'Update', 'barrio-suite' ),
										'is-primary'
									) }
							</>
						}
					>
						<p className="barrio-field__help">{ status }</p>
					</Card>
				);
			} ) }
			{ ! found.length && (
				<Card
					description={
						data.extensions.length
							? __( 'No extensions found.', 'barrio-suite' )
							: __(
									'No extensions are available yet.',
									'barrio-suite'
								)
					}
				/>
			) }
		</div>
	);
}
