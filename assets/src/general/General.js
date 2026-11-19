// Pagina Impostazioni (Barrio → Settings, manage_options): le impostazioni di tutta la suite. Ogni
// sezione legge e salva con la sua rotta (load, save) e mostra le sue card; un solo Salva nella barra
// salva le sezioni cambiate. Le sezioni di extensions/Extensions.js dipendono dal branch: in
// con-updater gli aggiornamenti da GitHub, in senza-updater nessuna.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ALT } from '../../../modules/alt/assets/src/Alt.js';
import { BANNERS } from '../../../modules/banners/assets/src/Banners.js';
import { BLOCKS } from '../../../modules/blocks/assets/src/Blocks.js';
import { DUPLICATE } from '../../../modules/duplicate/assets/src/Duplicate.js';
import { FORMS } from '../../../modules/forms/assets/src/forms/FormsCard.js';
import { HOURS } from '../../../modules/hours/assets/src/Hours.js';
import { ORDERING } from '../../../modules/ordering/assets/src/Ordering.js';
import { PERFORMANCE } from '../../../modules/performance/assets/src/Performance.js';
import { SEO } from '../../../modules/seo/assets/src/seo/SeoCard.js';
import {
	Bar,
	Card,
	Loading,
	Lucide,
	Notice,
	Toggle,
	errorMessage,
	useUnsavedWarning,
} from '../admin/ui.js';
import { settingsSections } from '../extensions/Extensions.js';
import { googleSection } from './Google.js';
import { smtpSection } from './Smtp.js';

// I nomi dei moduli di Modules::ALL (src/Modules.php).
const MODULES = {
	forms: FORMS,
	seo: {
		title: SEO.title,
		description: __(
			'Titles, descriptions, sitemap and redirects. Turn it off if the site uses another SEO plugin, such as Yoast or Rank Math.',
			'barrio-suite'
		),
	},
	alt: ALT,
	banners: BANNERS,
	hours: HOURS,
	blocks: BLOCKS,
	ordering: ORDERING,
	duplicate: DUPLICATE,
	performance: {
		title: PERFORMANCE.title,
		description: __(
			'Modern image formats, faster navigation and a lighter page head, with what WordPress already provides.',
			'barrio-suite'
		),
	},
};

// GET e PUT /barrio/v1/modules: per modulo, acceso o no. Cambiano il menu: dopo il salvataggio la
// pagina si ricarica.
const modulesSection = {
	id: 'modules',
	reload: true,
	load: () => apiFetch( { path: '/barrio/v1/modules' } ),
	value: ( data ) => data,
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/modules', method: 'PUT', data: draft } ),
	Cards: ( { draft, set } ) => (
		<Card
			className="barrio-wide"
			icon="screenoptions"
			title={ __( 'Modules', 'barrio-suite' ) }
			description={ __(
				'A module that is turned off disappears from the menu and from the site, but its data is kept: turn it on again to find everything as it was.',
				'barrio-suite'
			) }
		>
			{ Object.entries( draft ).map( ( [ module, on ] ) => (
				<Toggle
					key={ module }
					label={ MODULES[ module ]?.title ?? module }
					help={ MODULES[ module ]?.description }
					checked={ on }
					onChange={ ( value ) =>
						set( { ...draft, [ module ]: value } )
					}
				/>
			) ) }
		</Card>
	),
};

// GET e PUT /barrio/v1/uninstall (src/Uninstall.php).
const uninstallSection = {
	id: 'uninstall',
	load: () => apiFetch( { path: '/barrio/v1/uninstall' } ),
	value: ( data ) => data.delete_data,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/uninstall',
			method: 'PUT',
			data: { delete_data: draft },
		} ),
	Cards: ( { draft, set } ) => (
		<Card
			className="barrio-wide"
			icon="trash"
			title={ __( 'Uninstall', 'barrio-suite' ) }
			description={ __(
				'Deactivating Barrio Suite never deletes anything. Deleting it from Plugins keeps the data too, unless you choose otherwise here.',
				'barrio-suite'
			) }
		>
			<Toggle
				label={ __(
					'Delete all data when Barrio Suite is deleted',
					'barrio-suite'
				) }
				help={ __(
					'Forms, entries with their files, banners, redirects, the SEO data of the contents, settings and permissions of this site. It cannot be undone: export the entries you need first.',
					'barrio-suite'
				) }
				checked={ draft }
				onChange={ set }
			/>
		</Card>
	),
};

const SECTIONS = [
	modulesSection,
	smtpSection,
	googleSection,
	...settingsSections,
	uninstallSection,
];

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/**
 * Anche per la pagina di un modulo che ha solo impostazioni, come Prestazioni: le sue sezioni con la stessa barra.
 * Tools di una sezione va nella barra, prima di Salva; view è ciò che Tools e Cards scelgono e non si salva (il ruolo
 * di Blocchi).
 *
 * @param {Object}   props
 * @param {string}   props.title
 * @param {Object[]} props.sections
 */
export function General( {
	title = __( 'Settings', 'barrio-suite' ),
	sections = SECTIONS,
} ) {
	// Per sezione: i dati salvati (null se non si sono potuti leggere) e quelli della pagina.
	const [ data, setData ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ view, setView ] = useState( {} );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	useEffect( () => {
		// Una sezione che chi guarda non può leggere (gli aggiornamenti senza activate_plugins) non compare.
		Promise.all(
			sections.map( ( section ) =>
				section.load().catch( ( error ) => {
					if ( 'rest_forbidden' !== error.code ) {
						fail( error );
					}
					return null;
				} )
			)
		).then( ( loaded ) => {
			const next = Object.fromEntries(
				sections.map( ( { id }, index ) => [ id, loaded[ index ] ] )
			);
			setData( next );
			setDraft(
				Object.fromEntries(
					sections.map( ( { id, value } ) => [
						id,
						next[ id ] && value( next[ id ] ),
					] )
				)
			);
		} );
		// sections va passato costante: un array nuovo a ogni render ricaricherebbe le sezioni all'infinito.
	}, [ sections ] );

	const changed = data
		? sections.filter(
				( { id, value } ) =>
					data[ id ] && ! same( draft[ id ], value( data[ id ] ) )
			)
		: [];
	useUnsavedWarning( changed.length > 0 );

	if ( ! data ) {
		return <Loading />;
	}

	const save = () => {
		setBusy( true );
		setNotice( null );
		Promise.all(
			changed.map( ( section ) => section.save( draft[ section.id ] ) )
		)
			.then( ( responses ) => {
				if ( changed.some( ( section ) => section.reload ) ) {
					window.location.reload();
					return;
				}
				const saved = Object.fromEntries(
					changed.map( ( { id }, index ) => [
						id,
						responses[ index ],
					] )
				);
				setData( { ...data, ...saved } );
				setDraft( {
					...draft,
					...Object.fromEntries(
						changed.map( ( { id, value } ) => [
							id,
							value( saved[ id ] ),
						] )
					),
				} );
				setNotice( {
					status: 'success',
					message: __( 'Settings saved.', 'barrio-suite' ),
				} );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="barrio-stack">
			<Bar title={ title }>
				{ sections.map(
					( { id, Tools } ) =>
						Tools &&
						data[ id ] && (
							<Tools
								key={ id }
								data={ data[ id ] }
								view={ view }
								setView={ setView }
							/>
						)
				) }
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ! changed.length }
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
			{ sections.map(
				( { id, Cards } ) =>
					data[ id ] && (
						<Cards
							key={ id }
							data={ data[ id ] }
							draft={ draft[ id ] }
							set={ ( value ) =>
								setDraft( ( now ) => ( {
									...now,
									[ id ]: value,
								} ) )
							}
							dirty={ changed.some(
								( section ) => section.id === id
							) }
							onData={ ( value ) =>
								setData( ( now ) => ( {
									...now,
									[ id ]: value,
								} ) )
							}
							onNotice={ setNotice }
							view={ view }
							setView={ setView }
						/>
					)
			) }
		</div>
	);
}
