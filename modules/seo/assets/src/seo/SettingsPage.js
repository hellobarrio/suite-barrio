// Le schede delle impostazioni SEO (Organizzazione e Impostazioni, manage_options): leggono e salvano le
// sezioni di barrio_seo con GET/PUT /seo/settings, e nome e icona del sito, opzioni di WordPress, con
// /wp/v2/settings. Un solo Salva nella barra, che manda solo le sezioni cambiate: due amministratori su due
// schede non si sovrascrivono. Con più lingue la barra
// sceglie quella dei testi (SeoText): nelle altre il resto è disattivato (Fixed), come nel builder dei form.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Bar,
	LangNotice,
	LangPicker,
	Loading,
	Lucide,
	Notice,
	Text,
	errorMessage,
	useUnsavedWarning,
} from '../../../../../assets/src/admin/ui.js';

export const main = window.barrioAdmin.languages[ 0 ];

const TEXTS = {
	default_description: '',
	organization_description: '',
	llms_intro: '',
};

/**
 * Le sezioni che le pagine modificano. Le mappe vuote arrivano dal PHP come liste: diventano oggetti, così
 * la pagina appena caricata non risulta modificata. La lingua principale ha sempre i suoi testi.
 *
 * @param {Object} data Da GET /seo/settings.
 */
export const editable = ( data ) => ( {
	title: data.title,
	default_image: data.default_image,
	index: {
		...data.index,
		post_types: { ...data.index.post_types },
		taxonomies: { ...data.index.taxonomies },
	},
	organization: data.organization,
	llms: {
		post_types: { ...data.llms.post_types },
		taxonomies: { ...data.llms.taxonomies },
	},
	auto_redirects: data.auto_redirects,
	texts: { ...data.texts, [ main ]: { ...TEXTS, ...data.texts[ main ] } },
	roles: data.roles,
} );

// Le righe ancora vuote non si salvano: il server le rifiuterebbe.
export const cleanOrganization = ( o ) => ( {
	...o,
	area_served: o.area_served.filter( ( area ) => area.name.trim() ),
	knows_about: o.knows_about.filter( ( topic ) => topic.trim() ),
	same_as: o.same_as.filter( ( url ) => url.trim() ),
	offers: {
		...o.offers,
		items: o.offers.items.filter( ( item ) => item.name.trim() ),
	},
} );

const site = ( data ) => ( { title: data.title, site_icon: data.site_icon } );

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/**
 * Un testo di barrio_seo (texts) nella lingua scelta nella barra. Nelle altre lingue con l'originale sotto:
 * vuoto vale quello della lingua principale (Seo\Settings::text()).
 *
 * @param {Object} props
 * @param {Object} props.form Da SettingsPage.
 * @param {string} props.name La chiave in texts.
 * @param {string} props.help Nella lingua principale.
 */
export function SeoText( { form, name, help, ...props } ) {
	const { lang, seo, setText } = form;
	const original = seo.texts[ main ][ name ];
	return (
		<Text
			multiline
			{ ...props }
			help={
				lang === main ? (
					help
				) : (
					<span className="barrio-original">
						{ `${ main.toUpperCase() }: ${ original || '—' }` }
					</span>
				)
			}
			value={ seo.texts[ lang ]?.[ name ] ?? '' }
			onChange={ setText( name ) }
		/>
	);
}

/**
 * @param {Object}                   props
 * @param {string}                   props.title  Della barra.
 * @param {(form: Object) => Object} props.render Le card, con saved, seo, site, lang, texts (nella lingua, per le
 *                                                anteprime), setSeo, setSite, setText e onNotice.
 */
export function SettingsPage( { title, render } ) {
	const [ saved, setSaved ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ lang, setLang ] = useState( main );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/barrio/v1/seo/settings' } ),
			apiFetch( { path: '/wp/v2/settings' } ),
		] )
			.then( ( [ seo, wp ] ) => {
				setSaved( { seo, site: site( wp ) } );
				setDraft( { seo: editable( seo ), site: site( wp ) } );
			} )
			.catch( fail );
	}, [] );

	const seoChanged = !! saved && ! same( draft.seo, editable( saved.seo ) );
	const siteChanged = !! saved && ! same( draft.site, saved.site );
	useUnsavedWarning( seoChanged || siteChanged );

	if ( ! saved ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const { seo } = draft;
	const texts = Object.fromEntries(
		Object.keys( TEXTS ).map( ( name ) => [
			name,
			seo.texts[ lang ]?.[ name ] || seo.texts[ main ][ name ],
		] )
	);
	const setSeo = ( name ) => ( value ) =>
		setDraft( ( now ) => ( {
			...now,
			seo: { ...now.seo, [ name ]: value },
		} ) );
	const setSite = ( name ) => ( value ) =>
		setDraft( ( now ) => ( {
			...now,
			site: { ...now.site, [ name ]: value },
		} ) );
	// Una traduzione tutta vuota non si salva: vale la lingua principale.
	const setText = ( name ) => ( value ) => {
		const next = {
			...seo.texts,
			[ lang ]: { ...TEXTS, ...seo.texts[ lang ], [ name ]: value },
		};
		if (
			lang !== main &&
			! Object.values( next[ lang ] ).some( Boolean )
		) {
			delete next[ lang ];
		}
		setSeo( 'texts' )( next );
	};

	// Ciò che si scrive durante il salvataggio resta, da salvare.
	const save = () => {
		setBusy( true );
		setNotice( null );
		const before = editable( saved.seo );
		const sections = Object.fromEntries(
			Object.entries( {
				...seo,
				organization: cleanOrganization( seo.organization ),
			} ).filter( ( [ name, value ] ) => ! same( value, before[ name ] ) )
		);
		Promise.all( [
			seoChanged
				? apiFetch( {
						path: '/barrio/v1/seo/settings',
						method: 'PUT',
						data: sections,
					} )
				: saved.seo,
			siteChanged
				? apiFetch( {
						path: '/wp/v2/settings',
						method: 'POST',
						data: draft.site,
					} ).then( site )
				: saved.site,
		] )
			.then( ( [ nextSeo, nextSite ] ) => {
				setSaved( { seo: nextSeo, site: nextSite } );
				setDraft( ( now ) =>
					now === draft
						? { seo: editable( nextSeo ), site: nextSite }
						: now
				);
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
				<LangPicker value={ lang } onChange={ setLang } />
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ! ( seoChanged || siteChanged ) }
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
			<LangNotice lang={ lang } />
			{ render( {
				saved,
				seo,
				site: draft.site,
				lang,
				texts,
				setSeo,
				setSite,
				setText,
				onNotice: setNotice,
			} ) }
		</div>
	);
}
