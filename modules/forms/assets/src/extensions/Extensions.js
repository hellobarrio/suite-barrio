// Pagina Estensioni (Barrio → Extensions): le estensioni della directory di WordPress.org e quelle
// installate (src/Extensions.php), ognuna un plugin separato. Le azioni sono quelle della REST di
// WordPress (/wp/v2/plugins); l'aggiornamento è la schermata di WordPress.
import apiFetch from '@wordpress/api-fetch';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Icon,
	Loading,
	Notice,
	Search,
	pageUrl,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';

const DONE = {
	install: ( name ) =>
		/* translators: %s: extension name. */
		sprintf( __( '%s installed and activated.', 'barrio-suite' ), name ),
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

const ACTIONS = {
	install: ( extension ) => ( {
		path: '/wp/v2/plugins',
		method: 'POST',
		data: { slug: extension.slug, status: 'active' },
	} ),
	activate: ( extension ) => ( {
		path: `/wp/v2/plugins/${ extension.plugin }`,
		method: 'POST',
		data: { status: 'active' },
	} ),
	deactivate: ( extension ) => ( {
		path: `/wp/v2/plugins/${ extension.plugin }`,
		method: 'POST',
		data: { status: 'inactive' },
	} ),
	delete: ( extension ) => ( {
		path: `/wp/v2/plugins/${ extension.plugin }`,
		method: 'DELETE',
	} ),
};

export function Extensions() {
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	// L'azione in corso, come "mailchimp-install", o "refresh".
	const [ busy, setBusy ] = useState( null );
	const [ search, setSearch ] = useState( '' );

	// Dopo un'azione di WordPress si rilegge l'elenco.
	const request = ( kind, options, message ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( ( response ) =>
				'load' === kind
					? response
					: apiFetch( { path: '/barrio/v1/extensions' } )
			)
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
			ACTIONS[ action ]( extension ),
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
	const button = ( extension, action, label, style = '' ) => (
		<button
			type="button"
			className={ `barrio-button ${ style }` }
			disabled={ !! busy }
			onClick={ () =>
				confirmed( extension, action ) && act( extension, action )
			}
		>
			{ `${ extension.slug }-${ action }` === busy
				? __( 'Please wait…', 'barrio-suite' )
				: label }
		</button>
	);
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
			{ found.map( ( extension ) => {
				const update = extension.update_url;
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
								{ update && (
									<a
										className="barrio-button is-primary"
										href={ update }
									>
										{ __( 'Update', 'barrio-suite' ) }
									</a>
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
