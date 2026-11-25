// Funzioni pure della pagina Dati aziendali, senza WordPress: le prova tests/js/company.test.js.

// Una sede, come Module::OFFICE.
export const OFFICE = {
	name: '',
	street: '',
	postal_code: '',
	locality: '',
	region: '',
	country: '',
	maps_url: '',
	phones: [],
	emails: [],
};

const EMAILS = [ 'email', 'certified_email', 'privacy_email' ];

const isEmail = ( text ) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( text );

// Un testo o una lista di contatti non vuoti.
export const filled = ( office ) =>
	Object.values( office ).some( ( value ) => value.length > 0 );

// Il titolo di una sede operativa nella pagina: il nome, o l'indirizzo.
export const officeTitle = ( office ) =>
	office.name.trim() ||
	[ office.street, office.locality ]
		.map( ( text ) => text.trim() )
		.filter( Boolean )
		.join( ', ' );

// I Permessi sono della scheda Impostazioni.
export const editable = ( { roles, role_names: names, ...settings } ) =>
	settings;

// Un indirizzo incollato senza protocollo ("instagram.com/x") è l'errore più comune: il server vuole http(s)://.
const url = ( text ) => {
	const address = text.trim();
	return ! address || /^https?:\/\//i.test( address )
		? address
		: `https://${ address }`;
};

// I contatti senza valore non si salvano.
const contacts = ( list ) =>
	list
		.map( ( { label, value } ) => ( {
			label: label.trim(),
			value: value.trim(),
		} ) )
		.filter( ( { value } ) => value );

const place = ( office ) => ( {
	...Object.fromEntries(
		Object.entries( office ).map( ( [ key, value ] ) => [
			key,
			'string' === typeof value ? value.trim() : value,
		] )
	),
	maps_url: url( office.maps_url ),
	phones: contacts( office.phones ),
	emails: contacts( office.emails ),
} );

// Ciò che la pagina manda con PUT /barrio/v1/company: le sedi operative, i profili e i contatti vuoti non si salvano,
// email, contatti e indirizzi perdono gli spazi intorno.
export const body = ( draft ) => ( {
	...draft,
	...Object.fromEntries(
		EMAILS.map( ( field ) => [ field, draft[ field ].trim() ] )
	),
	address: place( draft.address ),
	offices: draft.offices.map( place ).filter( filled ),
	profiles: draft.profiles.map( url ).filter( Boolean ),
} );

// Il primo dato che il server rifiuterebbe con le regole di Module::schema(), come [ campo, 'email' | 'country' ], con
// la posizione della sede operativa: la pagina lo dice prima di salvare, al posto di "address[country] non corrisponde
// al pattern ^([A-Z]{2})?$".
export function problem( data ) {
	for ( const field of EMAILS ) {
		if ( data[ field ] && ! isEmail( data[ field ] ) ) {
			return [ field, 'email' ];
		}
	}
	const offices = [
		[ 'address', data.address ],
		...data.offices.map( ( office, index ) => [
			'offices',
			office,
			index,
		] ),
	];
	for ( const [ field, office, ...at ] of offices ) {
		if ( office.emails.some( ( { value } ) => ! isEmail( value ) ) ) {
			return [ field, 'email', ...at ];
		}
		if ( ! /^([A-Z]{2})?$/.test( office.country ) ) {
			return [ field, 'country', ...at ];
		}
	}
	return null;
}
