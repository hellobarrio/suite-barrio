// Il service account di Google (src/Google.php) e l'AI (src/Ai.php), in Barrio → Impostazioni. Una sezione sola,
// così dopo aver salvato il service account la card dell'AI si accende senza ricaricare la pagina. La chiave del
// service account non torna mai dal server: se ne conoscono solo email e progetto.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	Details,
	Icon,
	SECRET_HELP,
	Select,
	Text,
	errorMessage,
} from '../admin/ui.js';

/**
 * Il service account collegato, o quello appena scelto e da salvare, con il bottone per caricare il file JSON
 * della chiave: il file si legge nel browser e parte con il Salva della pagina.
 *
 * @param {Object}                  props
 * @param {Object}                  props.google   Lo stato salvato: status, email, project.
 * @param {string}                  props.value    Il file scelto e non ancora salvato.
 * @param {(value: string) => void} props.onChange
 */
function Account( { google, value, onChange } ) {
	const input = useRef();
	const [ name, setName ] = useState( '' );
	const connected = 'saved' === google.status;
	let title = __( 'No service account yet', 'barrio-suite' );
	let detail = __(
		'Upload the JSON key of a Google Cloud service account.',
		'barrio-suite'
	);
	if ( value ) {
		title = name;
		detail = __( 'Save to connect it.', 'barrio-suite' );
	} else if ( connected ) {
		title = google.email;
		detail = sprintf(
			/* translators: %s: Google Cloud project ID. */
			__( 'Project %s', 'barrio-suite' ),
			google.project
		);
	} else if ( SECRET_HELP[ google.status ] ) {
		detail = SECRET_HELP[ google.status ];
	}
	return (
		<div className={ `barrio-account${ connected ? ' is-on' : '' }` }>
			<span className="barrio-account__icon">
				<Icon
					name={ connected && ! value ? 'yes-alt' : 'admin-network' }
				/>
			</span>
			<div className="barrio-account__text">
				<strong>{ title }</strong>
				<span>{ detail }</span>
			</div>
			{ value && (
				<span className="barrio-badge is-warning">
					{ __( 'Not saved', 'barrio-suite' ) }
				</span>
			) }
			{ connected && ! value && (
				<span className="barrio-badge is-success">
					{ __( 'Connected', 'barrio-suite' ) }
				</span>
			) }
			<input
				ref={ input }
				type="file"
				accept=".json,application/json"
				hidden
				onChange={ ( event ) => {
					const [ file ] = event.target.files;
					event.target.value = '';
					if ( file ) {
						setName( file.name );
						file.text().then( onChange );
					}
				} }
			/>
			{ value ? (
				<button
					type="button"
					className="barrio-button"
					onClick={ () => onChange( '' ) }
				>
					{ __( 'Cancel', 'barrio-suite' ) }
				</button>
			) : (
				<button
					type="button"
					className="barrio-button"
					onClick={ () => input.current.click() }
				>
					<Icon name="upload" />
					<span className="barrio-button__text">
						{ connected
							? __( 'Replace key', 'barrio-suite' )
							: __( 'Upload JSON key', 'barrio-suite' ) }
					</span>
				</button>
			) }
		</div>
	);
}

// Regioni e modelli si leggono da Google appena c'è un service account: prima resta solo la scelta salvata.
function useChoices( connected, onNotice ) {
	const [ choices, setChoices ] = useState( null );
	useEffect( () => {
		if ( ! connected ) {
			return;
		}
		let current = true;
		Promise.all( [
			apiFetch( { path: '/barrio/v1/ai/locations' } ),
			apiFetch( { path: '/barrio/v1/ai/models' } ),
		] )
			.then(
				( [ locations, models ] ) =>
					current && setChoices( { locations, models } )
			)
			.catch( ( error ) =>
				onNotice( { status: 'error', message: errorMessage( error ) } )
			);
		return () => {
			current = false;
		};
	}, [ connected ] ); // eslint-disable-line react-hooks/exhaustive-deps -- onNotice cambia a ogni render della pagina.
	return choices;
}

// Le scelte di Google, più quella salvata se Google non la elenca (o non le ha ancora date).
const withSaved = ( options = [], value, label ) =>
	value && ! options.some( ( option ) => option.value === value )
		? [ { value, label }, ...options ]
		: options;

function AiFields( { connected, ai, setAi, onNotice } ) {
	const choices = useChoices( connected, onNotice );
	const loading =
		connected && ! choices
			? __( 'Loading from Google…', 'barrio-suite' )
			: '';
	return (
		<fieldset className="barrio-fixed" disabled={ ! connected }>
			<Select
				label={ __( 'Region', 'barrio-suite' ) }
				help={
					loading ||
					__(
						'Where Google processes the requests. Automatic picks the region with free capacity; choose a European one to keep the data in Europe.',
						'barrio-suite'
					)
				}
				value={ ai.location }
				options={ [
					{
						value: '',
						label: __( 'Automatic (global)', 'barrio-suite' ),
					},
					...withSaved(
						choices?.locations,
						ai.location,
						ai.location
					),
				] }
				onChange={ setAi( 'location' ) }
			/>
			<Select
				label={ __( 'Model', 'barrio-suite' ) }
				help={
					loading ||
					__(
						'Choose a Gemini model: the Flash ones are fast and cheap, and enough for titles and descriptions. Not every model is in every region.',
						'barrio-suite'
					)
				}
				value={ ai.model }
				options={ [
					{ value: '', label: '—' },
					...withSaved(
						choices?.models,
						ai.model,
						ai.model.split( '/' ).pop()
					),
				] }
				onChange={ setAi( 'model' ) }
			/>
			<Text
				multiline
				rows={ 4 }
				label={ __( 'About the site', 'barrio-suite' ) }
				help={ __(
					'What the site is, who it is for, the tone and the words to prefer. The AI reads it every time it writes.',
					'barrio-suite'
				) }
				value={ ai.instructions }
				onChange={ setAi( 'instructions' ) }
			/>
		</fieldset>
	);
}

export const googleSection = {
	id: 'google',
	load: () =>
		Promise.all( [
			apiFetch( { path: '/barrio/v1/google' } ),
			apiFetch( { path: '/barrio/v1/ai' } ),
		] ).then( ( [ google, ai ] ) => ( { google, ai } ) ),
	value: ( data ) => ( { service_account: '', ai: data.ai } ),
	// Prima il service account, poi l'AI; la risposta ha la forma di load().
	save: async ( draft ) => ( {
		google: await apiFetch(
			draft.service_account
				? {
						path: '/barrio/v1/google',
						method: 'PUT',
						data: { service_account: draft.service_account },
					}
				: { path: '/barrio/v1/google' }
		),
		ai: await apiFetch( {
			path: '/barrio/v1/ai',
			method: 'PUT',
			data: draft.ai,
		} ),
	} ),
	Cards: ( { data, draft, set, onNotice } ) => {
		const connected = 'saved' === data.google.status;
		const setAi = ( key ) => ( value ) =>
			set( { ...draft, ai: { ...draft.ai, [ key ]: value } } );
		return (
			<>
				<Card
					className="barrio-wide"
					icon="google"
					title="Google"
					description={ __(
						'The Google Cloud service account the site uses for the services of Google: the AI and, later, Analytics.',
						'barrio-suite'
					) }
				>
					<Account
						google={ data.google }
						value={ draft.service_account }
						onChange={ ( value ) =>
							set( { ...draft, service_account: value } )
						}
					/>
					<Details title={ __( 'How to create it', 'barrio-suite' ) }>
						<ol className="barrio-howto">
							<li>
								{ __(
									'In the Google Cloud console, choose or create a project with billing and turn on the Vertex AI API.',
									'barrio-suite'
								) }
							</li>
							<li>
								{ __(
									'In IAM → Service accounts, create a service account with the Vertex AI User role.',
									'barrio-suite'
								) }
							</li>
							<li>
								{ __(
									'In the service account, open Keys → Add key → Create new key → JSON, and upload the file here.',
									'barrio-suite'
								) }
							</li>
						</ol>
					</Details>
				</Card>
				<Card
					className="barrio-wide"
					icon="lightbulb"
					title={ __( 'AI', 'barrio-suite' ) }
					description={
						connected
							? __(
									'Gemini, on Vertex AI, writes the SEO titles, descriptions and keywords and the introduction of llms.txt. Google bills the use to the project of the service account.',
									'barrio-suite'
								)
							: __(
									'To use the AI, connect the Google service account first.',
									'barrio-suite'
								)
					}
				>
					<AiFields
						connected={ connected }
						ai={ draft.ai }
						setAi={ setAi }
						onNotice={ onNotice }
					/>
				</Card>
			</>
		);
	},
};
