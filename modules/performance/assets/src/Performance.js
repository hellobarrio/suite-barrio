// La card Prestazioni in Barrio → Impostazioni (modules/performance/src/Module.php). Con il modulo spento la
// rotta non c'è e la card non compare.
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { Card, Select, Toggle } from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli, dove il modulo può essere spento.
export const PERFORMANCE = {
	title: __( 'Performance', 'barrio-suite' ),
	description: __(
		'Modern image formats, faster navigation and a lighter page head, with what WordPress already provides.',
		'barrio-suite'
	),
};

const FORMATS = {
	'image/webp': 'WebP',
	'image/avif': 'AVIF',
};

const AUTO = {
	label: __( 'WordPress default', 'barrio-suite' ),
	value: 'auto',
};

const HEAD = {
	emoji: __( 'Remove the emoji script and styles', 'barrio-suite' ),
	generator: __( 'Remove the WordPress version (generator)', 'barrio-suite' ),
	rsd: __( 'Remove the RSD link', 'barrio-suite' ),
	shortlink: __( 'Remove the shortlink', 'barrio-suite' ),
};

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
			<Card
				className="barrio-wide"
				icon="performance"
				title={ PERFORMANCE.title }
				description={ __(
					'Everything happens on this server, without external services. Changes apply from the next page load.',
					'barrio-suite'
				) }
			>
				<Select
					label={ __( 'Image format', 'barrio-suite' ) }
					help={ __(
						'The sizes WordPress creates from new JPEG images are saved in this format; the uploaded file stays as it is. For images already in the media library, regenerate the sizes, for example with wp media regenerate.',
						'barrio-suite'
					) }
					value={ draft.image_format }
					options={ [
						{
							label: __( 'As uploaded', 'barrio-suite' ),
							value: '',
						},
						...formats.map( ( mime ) => ( {
							label: FORMATS[ mime ],
							value: mime,
						} ) ),
					] }
					onChange={ setKey( 'image_format' ) }
				/>
				<Select
					label={ __( 'Speculative loading', 'barrio-suite' ) }
					help={ __(
						'The browser loads the next page before the click, for visitors who are not logged in. Prerender also runs the JavaScript of the page: check that forms, analytics and slideshows still work.',
						'barrio-suite'
					) }
					value={ draft.speculation_mode }
					options={ [
						AUTO,
						{
							label: __( 'Prefetch', 'barrio-suite' ),
							value: 'prefetch',
						},
						{
							label: __( 'Prerender', 'barrio-suite' ),
							value: 'prerender',
						},
					] }
					onChange={ setKey( 'speculation_mode' ) }
				/>
				<Select
					label={ __( 'Eagerness', 'barrio-suite' ) }
					value={ draft.speculation_eagerness }
					options={ [
						AUTO,
						{
							label: __(
								'Conservative: when the link is pressed',
								'barrio-suite'
							),
							value: 'conservative',
						},
						{
							label: __(
								'Moderate: when the pointer rests on the link',
								'barrio-suite'
							),
							value: 'moderate',
						},
						{
							label: __(
								'Eager: as soon as the pointer is on the link',
								'barrio-suite'
							),
							value: 'eager',
						},
					] }
					onChange={ setKey( 'speculation_eagerness' ) }
				/>
				{ Object.entries( HEAD ).map( ( [ key, label ] ) => (
					<Toggle
						key={ key }
						label={ label }
						checked={ draft[ key ] }
						onChange={ setKey( key ) }
					/>
				) ) }
				<Toggle
					label={ __(
						'Remove Dashicons for visitors',
						'barrio-suite'
					) }
					help={ __(
						'Only for those who do not see the admin bar. Leave it off if the theme uses Dashicons icons.',
						'barrio-suite'
					) }
					checked={ draft.dashicons }
					onChange={ setKey( 'dashicons' ) }
				/>
			</Card>
		);
	},
};
