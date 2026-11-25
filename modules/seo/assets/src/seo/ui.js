// I dati e i link della pagina SEO, e la scelta di un'immagine, usati dalle sue schede, dalla Bacheca e dalla
// messa online.
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

import { Group, Icon } from '../../../../../assets/src/admin/ui.js';

// Seo\Admin::data(). Con il modulo spento manca, e le sue pagine non si aprono.
export const seoData = window.barrioAdmin.seo ?? { types: [] };

export const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-seo', ...params } );

/**
 * Un'immagine della libreria dei media, salvata come ID dell'allegato: 0 = nessuna.
 *
 * @param {Object}               props
 * @param {string}               props.label
 * @param {string}               props.help
 * @param {number}               props.value
 * @param {(id: number) => void} props.onChange
 */
export function MediaField( { label, help, value, onChange } ) {
	const media = useSelect(
		( select ) => ( value ? select( coreStore ).getMedia( value ) : null ),
		[ value ]
	);
	const src =
		media?.media_details?.sizes?.medium?.source_url ?? media?.source_url;
	return (
		<Group label={ label } help={ help }>
			{ src && <img className="barrio-logo" src={ src } alt="" /> }
			<div className="barrio-row is-fill">
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					value={ value || undefined }
					onSelect={ ( selected ) => onChange( selected.id ) }
					render={ ( { open } ) => (
						<button
							type="button"
							className="barrio-button"
							onClick={ open }
						>
							<Icon name="format-image" />
							<span className="barrio-button__text">
								{ value
									? __( 'Replace image', 'barrio-suite' )
									: __( 'Choose image', 'barrio-suite' ) }
							</span>
						</button>
					) }
				/>
				{ !! value && (
					<button
						type="button"
						className="barrio-button is-danger"
						onClick={ () => onChange( 0 ) }
					>
						<Icon name="trash" />
						<span className="barrio-button__text">
							{ __( 'Remove', 'barrio-suite' ) }
						</span>
					</button>
				) }
			</div>
		</Group>
	);
}
