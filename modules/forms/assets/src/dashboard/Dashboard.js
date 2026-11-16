// Bacheca (Barrio → Dashboard), come quella del prototipo: la messa online del sito (agli
// amministratori) e una card per modulo con i suoi numeri e i collegamenti. Oggi c'è solo Forms; ogni
// nuovo modulo aggiunge la sua card.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Icon, Loading, Notice, entriesUrl, pageUrl } from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';
import { LaunchBanner, launchUrl } from '../launch/Launch.js';

const { canManageForms, canManageEntries, canManageOptions } =
	window.barrioAdmin;

// stats: [ etichetta, numero ]; actions: [ testo, link ], la prima è la principale.
const Module = ( { icon, title, description, color, stats, actions } ) => (
	<section className="barrio-card barrio-module">
		<header className="barrio-module__head" style={ { background: color } }>
			<span className="barrio-module__icon">
				<Icon name={ icon } />
			</span>
			<div>
				<h2 className="barrio-module__title">{ title }</h2>
				<p>{ description }</p>
			</div>
		</header>
		<div className="barrio-module__body">
			<dl className="barrio-module__stats">
				{ stats.map( ( [ label, value ] ) => (
					<div key={ label }>
						<dt>{ label }</dt>
						<dd>{ value.toLocaleString() }</dd>
					</div>
				) ) }
			</dl>
			<div className="barrio-row is-fill">
				{ actions.map( ( [ text, href ], index ) => (
					<a
						key={ href }
						className={ `barrio-button${
							index ? '' : ' is-primary'
						}` }
						href={ href }
					>
						{ text }
					</a>
				) ) }
			</div>
		</div>
	</section>
);

export function Dashboard() {
	const [ forms, setForms ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ launch, setLaunch ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/barrio/v1/forms' } )
			.then( setForms )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			);
		// Senza la messa online la Bacheca resta utile: un suo errore non si mostra.
		if ( canManageOptions ) {
			apiFetch( { path: '/barrio/v1/launch' } )
				.then( setLaunch )
				.catch( () => {} );
		}
	}, [] );

	if ( ! forms ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const sum = ( key ) => forms.reduce( ( n, form ) => n + form[ key ], 0 );

	// Senza barra: la Bacheca non ha azioni sue, e gli avvisi di WordPress stanno sopra l'intestazione.
	return (
		<div className="barrio-stack">
			{ launch && (
				<LaunchBanner launch={ launch } href={ launchUrl() } />
			) }
			<div className="barrio-modules">
				<Module
					icon="feedback"
					title={ __( 'Forms', 'barrio-suite' ) }
					description={ __(
						'Contact forms with a drag & drop builder',
						'barrio-suite'
					) }
					color="linear-gradient(135deg, #059669, #10b981)"
					stats={ [
						[
							__( 'Active forms', 'barrio-suite' ),
							forms.filter( ( form ) => 'active' === form.status )
								.length,
						],
						[ __( 'Entries', 'barrio-suite' ), sum( 'entries' ) ],
						[
							__( 'Unread entries', 'barrio-suite' ),
							sum( 'unread' ),
						],
					] }
					actions={ [
						canManageForms && [
							__( 'Manage forms', 'barrio-suite' ),
							pageUrl(),
						],
						canManageEntries && [
							__( 'Entries', 'barrio-suite' ),
							entriesUrl(),
						],
					].filter( Boolean ) }
				/>
			</div>
		</div>
	);
}
