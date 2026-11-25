// Il frontend headless in Barrio → Impostazioni: indirizzo, token e cache da svuotare a mano. Dati e rotte
// (/barrio/v1/frontend) sono di un'estensione, barrio-suite-wpgraphql, che accende la sezione con barrioAdmin.frontend.
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	Details,
	Icon,
	Notice,
	Text,
	errorMessage,
} from '../admin/ui.js';

/**
 * Con l'indirizzo e il token salvati: con modifiche in sospeso è spento.
 *
 * @param {Object}                   props
 * @param {boolean}                  props.dirty    Ci sono modifiche non salvate.
 * @param {(notice: Object) => void} props.onNotice L'esito, come avviso della pagina.
 * @param {() => void}               props.onDone   Il frontend ha risposto: l'ultimo errore non vale più.
 */
function Clear( { dirty, onNotice, onDone } ) {
	const [ tags, setTags ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const clear = () => {
		setBusy( true );
		onNotice( null );
		apiFetch( {
			path: '/barrio/v1/frontend/clear',
			method: 'POST',
			data: { tags },
		} )
			.then( ( data ) => {
				onNotice( { status: 'success', message: data.message } );
				onDone();
			} )
			.catch( ( error ) =>
				onNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( false ) );
	};
	return (
		<Text
			label={ __( 'Cache tags', 'barrio-suite' ) }
			help={
				dirty
					? __(
							'Save your changes first: the frontend is called with the saved address and token.',
							'barrio-suite'
						)
					: __(
							'Separated by commas. A page is wp:uri: and its path with _ in place of /, like wp:uri:about_team; the home page is wp:uri:.',
							'barrio-suite'
						)
			}
			placeholder="wp:menu, wp:seo, form_3"
			value={ tags }
			onChange={ setTags }
			addon={
				<button
					type="button"
					className="barrio-button"
					disabled={ busy || dirty || ! tags.trim() }
					onClick={ clear }
				>
					<Icon name="update" />
					<span className="barrio-button__text">
						{ busy
							? __( 'Clearing…', 'barrio-suite' )
							: __( 'Clear cache', 'barrio-suite' ) }
					</span>
				</button>
			}
		/>
	);
}

export const frontendSection = {
	id: 'frontend',
	load: () => apiFetch( { path: '/barrio/v1/frontend' } ),
	// error è l'ultima revalidation non riuscita: si mostra, non si salva.
	value: ( { url, token } ) => ( { url, token } ),
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/frontend', method: 'PUT', data: draft } ),
	Cards: ( { data, draft, set, dirty, onData, onNotice } ) => (
		<Card
			className="barrio-wide"
			icon="cloud"
			title={ __( 'Headless frontend', 'barrio-suite' ) }
			description={ __(
				'The site that shows the contents of WordPress through GraphQL. When something it shows changes, WordPress asks it to clear its cache at /api/revalidate/.',
				'barrio-suite'
			) }
		>
			<Text
				label={ __( 'Address', 'barrio-suite' ) }
				help={ __(
					'Like https://www.example.com. In GraphQL the links of the SEO (canonical, JSON-LD, llms.txt) point here; images stay on WordPress.',
					'barrio-suite'
				) }
				type="url"
				value={ draft.url }
				onChange={ ( url ) => set( { ...draft, url } ) }
			/>
			<Text
				label={ __( 'Revalidation token', 'barrio-suite' ) }
				help={ __(
					'Sent with each request to clear the cache. Write the same in the REVALIDATE_TOKEN variable of the frontend: then it refuses the requests without it.',
					'barrio-suite'
				) }
				value={ draft.token }
				onChange={ ( token ) => set( { ...draft, token } ) }
			/>
			{ data.error && (
				<Notice
					notice={ {
						status: 'error',
						message: sprintf(
							/* translators: %s: error message, for example "The frontend answered with the error 401". */
							__(
								'The last request to clear the cache of the frontend failed. %s',
								'barrio-suite'
							),
							data.error
						),
					} }
				/>
			) }
			<Details title={ __( 'Clear the cache', 'barrio-suite' ) }>
				<Clear
					dirty={ dirty }
					onNotice={ onNotice }
					onDone={ () => onData( { ...data, error: '' } ) }
				/>
			</Details>
		</Card>
	),
};
