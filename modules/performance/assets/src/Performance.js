// La pagina Barrio → Prestazioni (modules/performance/src/Module.php), con le impostazioni del modulo, e la sua
// card nella Bacheca.
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import {
	Card,
	ModuleCard,
	Select,
	Toggle,
} from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni, dove il modulo può essere spento.
export const PERFORMANCE = {
	icon: 'performance',
	title: __( 'Performance', 'barrio-suite' ),
	description: __(
		'Image formats, speculative loading and page head',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #7c2d12, #c2410c)',
};

const FORMATS = {
	'image/webp': 'WebP',
	'image/avif': 'AVIF',
};

const AS_UPLOADED = __( 'As uploaded', 'barrio-suite' );

const MODES = {
	auto: __( 'WordPress default', 'barrio-suite' ),
	prefetch: __( 'Prefetch', 'barrio-suite' ),
	prerender: __( 'Prerender', 'barrio-suite' ),
};

const HEAD = {
	emoji: __( 'Remove the emoji script and styles', 'barrio-suite' ),
	generator: __( 'Remove the WordPress version (generator)', 'barrio-suite' ),
	rsd: __( 'Remove the RSD link', 'barrio-suite' ),
	shortlink: __( 'Remove the shortlink', 'barrio-suite' ),
	dashicons: __( 'Remove Dashicons for visitors', 'barrio-suite' ),
};

const options = ( labels ) =>
	Object.entries( labels ).map( ( [ value, label ] ) => ( {
		label,
		value,
	} ) );

// Per la pagina, che salva con il Salva della barra come Barrio → Impostazioni (general/General.js).
export const performanceSection = {
	id: 'performance',
	load: () => apiFetch( { path: '/barrio/v1/performance' } ),
	value: ( { formats, ...settings } ) => settings,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/performance',
			method: 'PUT',
			data: draft,
		} ),
	Cards: ( { data, draft, set } ) => {
		const setKey = ( key ) => ( value ) =>
			set( { ...draft, [ key ]: value } );
		// Un formato salvato che il server non sa più scrivere resta tra le scelte: il core tiene quello del file.
		const formats = Object.keys( FORMATS ).filter(
			( mime ) =>
				data.formats.includes( mime ) || mime === draft.image_format
		);
		return (
			<>
				<Card
					className="barrio-wide"
					icon="format-image"
					title={ __( 'Images', 'barrio-suite' ) }
				>
					<Select
						label={ __( 'Image format', 'barrio-suite' ) }
						help={ __(
							'The sizes WordPress creates from new JPEG images are saved in this format; the uploaded file stays as it is. For images already in the media library, regenerate the sizes, for example with wp media regenerate.',
							'barrio-suite'
						) }
						value={ draft.image_format }
						options={ [
							{ label: AS_UPLOADED, value: '' },
							...formats.map( ( mime ) => ( {
								label: FORMATS[ mime ],
								value: mime,
							} ) ),
						] }
						onChange={ setKey( 'image_format' ) }
					/>
				</Card>
				<Card
					className="barrio-wide"
					icon="performance"
					title={ __( 'Speculative loading', 'barrio-suite' ) }
					description={ __(
						'The browser loads the next page before the click, for visitors who are not logged in. Prerender also runs the JavaScript of the page: check that forms, analytics and slideshows still work.',
						'barrio-suite'
					) }
				>
					<Select
						label={ __( 'Mode', 'barrio-suite' ) }
						value={ draft.speculation_mode }
						options={ options( MODES ) }
						onChange={ setKey( 'speculation_mode' ) }
					/>
					<Select
						label={ __( 'Eagerness', 'barrio-suite' ) }
						value={ draft.speculation_eagerness }
						options={ options( {
							auto: MODES.auto,
							conservative: __(
								'Conservative: when the link is pressed',
								'barrio-suite'
							),
							moderate: __(
								'Moderate: when the pointer rests on the link',
								'barrio-suite'
							),
							eager: __(
								'Eager: as soon as the pointer is on the link',
								'barrio-suite'
							),
						} ) }
						onChange={ setKey( 'speculation_eagerness' ) }
					/>
				</Card>
				<Card
					className="barrio-wide"
					icon="editor-code"
					title={ __( 'Page head', 'barrio-suite' ) }
					description={ __(
						'Tags and files that WordPress adds to every page and that most sites do not need.',
						'barrio-suite'
					) }
				>
					{ Object.entries( HEAD ).map( ( [ key, label ] ) => (
						<Toggle
							key={ key }
							label={ label }
							help={
								'dashicons' === key &&
								__(
									'Only for those who do not see the admin bar. Leave it off if the theme uses Dashicons icons.',
									'barrio-suite'
								)
							}
							checked={ draft[ key ] }
							onChange={ setKey( key ) }
						/>
					) ) }
				</Card>
			</>
		);
	},
};

// Le impostazioni arrivano con la pagina (barrio_admin_data): con il modulo spento non ci sono.
const settings = window.barrioAdmin.performance;

export function PerformanceCard() {
	if ( ! settings || ! window.barrioAdmin.canManageOptions ) {
		return null;
	}
	return (
		<ModuleCard
			module={ PERFORMANCE }
			stats={ [
				[
					__( 'Image format', 'barrio-suite' ),
					FORMATS[ settings.image_format ] ?? AS_UPLOADED,
				],
				[
					__( 'Speculative loading', 'barrio-suite' ),
					MODES[ settings.speculation_mode ],
				],
				[
					__( 'Removed from the head', 'barrio-suite' ),
					Object.keys( HEAD ).filter( ( key ) => settings[ key ] )
						.length,
				],
			] }
			actions={ [
				[
					__( 'Settings', 'barrio-suite' ),
					'?page=barrio-performance',
				],
			] }
		/>
	);
}
