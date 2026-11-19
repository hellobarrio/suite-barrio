// Popup e banner (src/Period.php): lo stato e il periodo, nell'ora del sito, e i testi per lingua, tradotti come nel
// builder dei form (TextIn): nelle altre lingue un testo vuoto vale quello della lingua principale.
import { getSettings, gmdateI18n } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import {
	setTranslation,
	textIn,
} from '../../../modules/forms/assets/src/builder/model.js';
import { Card, Text, Toggle } from './ui.js';

const [ main, ...others ] = window.barrioAdmin.languages;

// Period::status(): il nome e il colore del badge.
export const STATUS = {
	active: [ __( 'Active', 'barrio-suite' ), 'is-success' ],
	scheduled: [ __( 'Scheduled', 'barrio-suite' ), 'is-warning' ],
	expired: [ __( 'Expired', 'barrio-suite' ), '' ],
	off: [ __( 'Off', 'barrio-suite' ), '' ],
};

// Una data del campo datetime-local (ora del sito) nel formato della lingua, senza passare dal fuso del browser.
const dateText = ( local ) =>
	gmdateI18n( getSettings().formats.datetimeAbbreviated, `${ local }:00Z` );

export function periodText( start, end ) {
	if ( start && end ) {
		return `${ dateText( start ) } – ${ dateText( end ) }`;
	}
	if ( start ) {
		/* translators: %s: date and time. */
		return sprintf( __( 'From %s', 'barrio-suite' ), dateText( start ) );
	}
	if ( end ) {
		/* translators: %s: date and time. */
		return sprintf( __( 'Until %s', 'barrio-suite' ), dateText( end ) );
	}
	return __( 'Always', 'barrio-suite' );
}

/**
 * Acceso, inizio e fine, nell'ora del sito; vuoti, da subito e per sempre.
 *
 * @param {Object}                    props
 * @param {Object}                    props.item        Il popup o il banner.
 * @param {string}                    props.description
 * @param {(changes: Object) => void} props.onChange    { is_enabled }, { starts_at } o { ends_at }.
 */
export const PeriodCard = ( { item, description, onChange } ) => (
	<Card
		icon="calendar-alt"
		title={ __( 'Period', 'barrio-suite' ) }
		description={ description }
	>
		<Toggle
			label={ __( 'On', 'barrio-suite' ) }
			help={
				! item.is_enabled &&
				__( 'Turned off, it never shows up.', 'barrio-suite' )
			}
			checked={ item.is_enabled }
			onChange={ ( on ) => onChange( { is_enabled: on } ) }
		/>
		<Text
			type="datetime-local"
			label={ __( 'Start', 'barrio-suite' ) }
			value={ item.starts_at }
			onChange={ ( value ) => onChange( { starts_at: value || null } ) }
		/>
		<Text
			type="datetime-local"
			label={ __( 'End', 'barrio-suite' ) }
			min={ item.starts_at ?? undefined }
			value={ item.ends_at }
			onChange={ ( value ) => onChange( { ends_at: value || null } ) }
		/>
	</Card>
);

// Al caricamento le altre lingue tengono solo i testi diversi da quelli della lingua principale.
export const trimTexts = ( record ) =>
	others.reduce(
		( item, lang ) =>
			Object.keys( item.i18n[ lang ] ?? {} ).reduce(
				( next, key ) =>
					next.i18n[ lang ]?.[ key ] === next.i18n[ main ]?.[ key ]
						? setTranslation( next, lang, key, '' )
						: next,
				item
			),
		record
	);

// Al salvataggio ogni lingua tradotta ha la riga completa, come vuole il database.
export const fillTexts = ( record ) => ( {
	...record,
	i18n: Object.fromEntries(
		Object.keys( record.i18n ).map( ( lang ) => [
			lang,
			textIn( record, lang, main ),
		] )
	),
} );
