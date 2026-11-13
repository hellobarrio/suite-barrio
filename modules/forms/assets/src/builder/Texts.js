// I testi di form, campi e notifiche nella lingua scelta nella barra del builder. Nella lingua
// principale si scrive il testo; nelle altre la traduzione, con l'originale sotto: vuota vale
// l'originale (trimTranslations in model.js). Le impostazioni uguali in tutte le lingue restano
// visibili ma disattivate (Fixed); lo spiega l'avviso sotto la barra.
import { __, sprintf } from '@wordpress/i18n';

import { Text } from '../admin/ui.js';
import { setTranslation } from './model.js';

const [ main ] = window.barrioAdmin.languages;
const MAIN = main.toUpperCase();

// optional: nella lingua principale un testo vuoto è null, il valore standard (docs/database.md).
export function TextIn( {
	item,
	lang,
	name,
	optional,
	help,
	onChange,
	...props
} ) {
	const original = item.i18n[ main ]?.[ name ];
	if ( lang === main ) {
		return (
			<Text
				{ ...props }
				help={ help }
				value={ original }
				onChange={ ( value ) =>
					onChange( {
						...item,
						i18n: {
							...item.i18n,
							[ main ]: {
								...item.i18n[ main ],
								[ name ]: optional && ! value ? null : value,
							},
						},
					} )
				}
			/>
		);
	}
	const value = item.i18n[ lang ]?.[ name ];
	// Un testo che nella lingua principale non c'è (per esempio il messaggio standard) non si traduce.
	const untranslatable = ! original && ! value;
	return (
		<Text
			{ ...props }
			disabled={ untranslatable }
			help={
				<span className="barrio-original">
					{ untranslatable
						? sprintf(
								/* translators: %s: lingua principale (IT). */
								__(
									'Empty in %s, so there is nothing to translate.',
									'barrio-suite'
								),
								MAIN
							)
						: `${ MAIN }: ${ original }` }
				</span>
			}
			value={ value }
			onChange={ ( text ) =>
				onChange( setTranslation( item, lang, name, text ) )
			}
		/>
	);
}

// Impostazioni uguali in tutte le lingue: nelle altre lingue il fieldset le disattiva tutte.
export const Fixed = ( { lang, children } ) => (
	<fieldset className="barrio-fixed" disabled={ lang !== main }>
		{ children }
	</fieldset>
);
