// Il pannello SEO, uguale nella sidebar dell'editor a blocchi, nella metabox dell'editor classico e nei
// termini (src/Editor.php): anteprime, campi, indicizzazione e controlli del punteggio. I meta sono quelli
// della REST del core; null vuol dire "eredita".
import {
	Button,
	FormTokenField,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

import { level, score } from '../score.js';
import { documentTitle, excerpt, text } from '../title.js';

/**
 * Ciò che esce nella pagina, come lo calcola Seo\Meta, e il punteggio.
 *
 * @param {Object} meta    I meta _barrio_seo_*.
 * @param {Object} page    Titolo, URL, immagine in evidenza e testo del contenuto (content è null nei termini).
 * @param {Object} context window.barrioSeo: sito, valori predefiniti, robots del tipo.
 */
export function analyze( meta, page, context ) {
	const title = meta._barrio_seo_title || text( page.title );
	const fullTitle = documentTitle( title, context.site );
	let fallback = excerpt( page.description );
	if ( page.content !== null ) {
		// Il contenuto di un post protetto da password non esce mai.
		fallback = page.password ? '' : excerpt( page.excerpt || page.content );
	}
	const description =
		meta._barrio_seo_description || fallback || context.description;
	return {
		title,
		fullTitle,
		fallback,
		description,
		image: meta._barrio_seo_image || page.featured || context.image,
		result: score( {
			title,
			fullTitle,
			description,
			keywords: meta._barrio_seo_keywords ?? [],
			url: page.url,
			home: context.site.home,
			image: Boolean( meta._barrio_seo_image || page.featured ),
			content: page.content,
		} ),
	};
}

export const Badge = ( { value } ) => (
	<span className={ `barrio-seo-badge is-${ level( value ) }` }>
		{ value }
	</span>
);

// "esempio.it › blog › articolo", come lo mostra Google.
function breadcrumb( url ) {
	try {
		const { host, pathname } = new URL( url );
		return [ host, ...pathname.split( '/' ).filter( Boolean ) ]
			.map( decodeURIComponent )
			.join( ' › ' );
	} catch {
		return url;
	}
}

const Counter = ( { length, max, min = 0 } ) => (
	<span
		className={
			length > max || length < min ? 'barrio-seo-counter is-off' : ''
		}
	>
		{ sprintf(
			/* translators: 1: characters used, 2: maximum. */
			__( '%1$d of %2$d characters', 'barrio-suite' ),
			length,
			max
		) }
	</span>
);

function Robot( { label, value, inherited, yes, no, onChange } ) {
	return (
		<SelectControl
			label={ label }
			value={ value ?? '' }
			options={ [
				{
					label: sprintf(
						/* translators: %s: Yes or No, the value of the content type or taxonomy. */
						__( 'Default (%s)', 'barrio-suite' ),
						inherited
							? __( 'Yes', 'barrio-suite' )
							: __( 'No', 'barrio-suite' )
					),
					value: '',
				},
				{ label: __( 'Yes', 'barrio-suite' ), value: yes },
				{ label: __( 'No', 'barrio-suite' ), value: no },
			] }
			onChange={ onChange }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

function checkLabel( { id, value } ) {
	switch ( id ) {
		case 'keyword':
			return __( 'Focus keyword set', 'barrio-suite' );
		case 'title_keyword':
			return __( 'Focus keyword in the SEO title', 'barrio-suite' );
		case 'title_start':
			return __(
				'Focus keyword in the first half of the SEO title',
				'barrio-suite'
			);
		case 'title_length':
			return sprintf(
				/* translators: %d: characters. */
				__( 'Title up to 60 characters (%d)', 'barrio-suite' ),
				value
			);
		case 'description_keyword':
			return __(
				'Focus keyword in the meta description',
				'barrio-suite'
			);
		case 'description_length':
			return sprintf(
				/* translators: %d: characters. */
				__(
					'Description of 120 to 160 characters (%d)',
					'barrio-suite'
				),
				value
			);
		case 'url_keyword':
			return __( 'Focus keyword in the URL', 'barrio-suite' );
		case 'url_length':
			return sprintf(
				/* translators: %d: characters. */
				__( 'URL up to 75 characters (%d)', 'barrio-suite' ),
				value
			);
		case 'image':
			return __( 'An image for sharing', 'barrio-suite' );
		case 'content_length':
			return sprintf(
				/* translators: %d: words. */
				__( 'At least 600 words (%d)', 'barrio-suite' ),
				value
			);
		case 'content_keyword':
			return __( 'Focus keyword in the content', 'barrio-suite' );
		case 'content_start':
			return __(
				'Focus keyword at the beginning of the content (first 10%)',
				'barrio-suite'
			);
		case 'density':
			return sprintf(
				/* translators: %s: percentage. */
				__(
					'Focus keyword density from 0.5%% to 2.5%% (%s%%)',
					'barrio-suite'
				),
				value
			);
		case 'subheading_keyword':
			return __( 'Focus keyword in a subheading', 'barrio-suite' );
		case 'image_alt_keyword':
			return __(
				'Focus keyword in the alt text of an image',
				'barrio-suite'
			);
		case 'internal_links':
			return __( 'Links to other pages of the site', 'barrio-suite' );
		case 'external_links':
			return __( 'Links to other sites', 'barrio-suite' );
		case 'short_paragraphs':
			return __( 'Paragraphs of 120 words at most', 'barrio-suite' );
		case 'media':
			return __( 'Images or videos in the content', 'barrio-suite' );
	}
}

function Checks( { checks } ) {
	const groups = [
		[ __( 'Search result', 'barrio-suite' ), checks.slice( 0, 9 ) ],
		[ __( 'Content', 'barrio-suite' ), checks.slice( 9 ) ],
	].filter( ( [ , list ] ) => list.length );
	return groups.map( ( [ title, list ] ) => (
		<div key={ title }>
			<h3 className="barrio-seo-checks__title">{ title }</h3>
			<ul className="barrio-seo-checks">
				{ list.map( ( check ) => (
					<li
						key={ check.id }
						className={ check.pass ? 'is-pass' : 'is-fail' }
					>
						<span
							className={ `dashicons dashicons-${
								check.pass ? 'yes-alt' : 'dismiss'
							}` }
							aria-hidden="true"
						/>
						<span className="screen-reader-text">
							{ check.pass
								? __( 'Passed:', 'barrio-suite' )
								: __( 'Failed:', 'barrio-suite' ) }
						</span>
						{ checkLabel( check ) }
					</li>
				) ) }
			</ul>
		</div>
	) );
}

export function Panel( { meta, page, context, seo, onChange } ) {
	// Un testo o una lista vuoti tornano a ereditare.
	const set = ( key ) => ( value ) =>
		onChange( {
			[ `_barrio_seo_${ key }` ]:
				value === '' || value?.length === 0 ? null : value,
		} );
	const image = useSelect(
		( select ) => seo.image && select( coreStore ).getMedia( seo.image ),
		[ seo.image ]
	);
	let host = '';
	try {
		host = new URL( page.url || context.site.home ).host;
	} catch {}

	return (
		<div className="barrio-seo-panel">
			<PanelBody title={ __( 'Search result', 'barrio-suite' ) }>
				<div className="barrio-seo-google" aria-hidden="true">
					<span className="barrio-seo-google__site">
						{ context.site.name }
					</span>
					<span className="barrio-seo-google__url">
						{ breadcrumb( page.url || context.site.home ) }
					</span>
					<span className="barrio-seo-google__title">
						{ seo.fullTitle }
					</span>
					<span className="barrio-seo-google__description">
						{ seo.description }
					</span>
				</div>
				<TextControl
					label={ __( 'SEO title', 'barrio-suite' ) }
					value={ meta._barrio_seo_title ?? '' }
					placeholder={ text( page.title ) }
					onChange={ set( 'title' ) }
					help={
						<Counter length={ seo.fullTitle.length } max={ 60 } />
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextareaControl
					label={ __( 'Meta description', 'barrio-suite' ) }
					value={ meta._barrio_seo_description ?? '' }
					placeholder={ seo.fallback || context.description }
					onChange={ set( 'description' ) }
					help={
						<Counter
							length={ seo.description.length }
							min={ 120 }
							max={ 160 }
						/>
					}
					rows={ 3 }
					__nextHasNoMarginBottom
				/>
				<FormTokenField
					label={ __( 'Focus keywords', 'barrio-suite' ) }
					value={ meta._barrio_seo_keywords ?? [] }
					onChange={ set( 'keywords' ) }
					__experimentalShowHowTo={ false }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<p className="components-base-control__help">
					{ __(
						'Press Enter after each one. The first is the main one, used by the checks.',
						'barrio-suite'
					) }
				</p>
			</PanelBody>

			<PanelBody
				title={ __( 'Social', 'barrio-suite' ) }
				initialOpen={ false }
			>
				<div className="barrio-seo-social" aria-hidden="true">
					{ image?.source_url ? (
						<img src={ image.source_url } alt="" />
					) : (
						<span className="barrio-seo-social__empty" />
					) }
					<span className="barrio-seo-social__host">{ host }</span>
					<span className="barrio-seo-social__title">
						{ seo.title }
					</span>
					<span className="barrio-seo-social__description">
						{ seo.description }
					</span>
				</div>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ meta._barrio_seo_image }
					onSelect={ ( media ) => set( 'image' )( media.id ) }
					render={ ( { open } ) => (
						<div className="barrio-seo-row">
							<Button variant="secondary" onClick={ open }>
								{ meta._barrio_seo_image
									? __( 'Replace image', 'barrio-suite' )
									: __( 'Choose image', 'barrio-suite' ) }
							</Button>
							{ meta._barrio_seo_image && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => set( 'image' )( null ) }
								>
									{ __( 'Remove', 'barrio-suite' ) }
								</Button>
							) }
						</div>
					) }
				/>
				<p className="components-base-control__help">
					{ __(
						'For Facebook, LinkedIn, WhatsApp and X. Without an image, the featured image or the default one of the site is used.',
						'barrio-suite'
					) }
				</p>
			</PanelBody>

			<PanelBody
				title={ __( 'Indexing', 'barrio-suite' ) }
				initialOpen={ false }
			>
				<Robot
					label={ __( 'Show in search results', 'barrio-suite' ) }
					value={ meta._barrio_seo_index }
					inherited={ context.robots.index }
					yes="index"
					no="noindex"
					onChange={ set( 'index' ) }
				/>
				<Robot
					label={ __( 'Follow the links', 'barrio-suite' ) }
					value={ meta._barrio_seo_follow }
					inherited={ context.robots.follow }
					yes="follow"
					no="nofollow"
					onChange={ set( 'follow' ) }
				/>
				{ context.collections && (
					<SelectControl
						label={ __( 'Archive page of', 'barrio-suite' ) }
						help={ __(
							'The structured data of the page list the contents of this type.',
							'barrio-suite'
						) }
						value={ meta._barrio_seo_collection ?? '' }
						options={ [
							{ label: __( 'None', 'barrio-suite' ), value: '' },
							...context.collections,
						] }
						onChange={ set( 'collection' ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
			</PanelBody>

			<PanelBody
				title={ sprintf(
					/* translators: %d: SEO score from 0 to 100. */
					__( 'Checks: %d/100', 'barrio-suite' ),
					seo.result.score
				) }
			>
				<Checks checks={ seo.result.checks } />
			</PanelBody>
		</div>
	);
}
