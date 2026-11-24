// I testi di form, campi, notifiche, popup e banner nella lingua scelta nella barra. Nella lingua
// principale si scrive il testo; nelle altre la traduzione, con l'originale sotto: vuota vale
// l'originale (withTranslation in i18n.js). Le impostazioni uguali in tutte le lingue restano
// visibili ma disattivate (Fixed); lo spiega l'avviso sotto la barra.
import { __, sprintf } from '@wordpress/i18n';

import { setTranslation } from './i18n.js';
import { Text } from './ui.js';

const [ main ] = window.barrioAdmin.languages;
const MAIN = main.toUpperCase();

// Un testo facoltativo vuoto è null: il valore standard (docs/database.md).
export const orNull = ( optional, value ) =>
	optional && ! value ? null : value;

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
								[ name ]: orNull( optional, value ),
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
								/* translators: %s: code of the main language, like IT. */
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
