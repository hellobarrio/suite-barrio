// Funzioni pure della pagina Dati aziendali, senza WordPress: le prova tests/js/company.test.js.

export const ADDRESS = {
	street: '',
	postal_code: '',
	locality: '',
	region: '',
	country: '',
	maps_url: '',
};

const EMAILS = [ 'email', 'certified_email', 'privacy_email' ];

export const filled = ( address ) => Object.values( address ).some( Boolean );

// operating è l'interruttore della sede operativa: non si salva.
export const editable = ( { roles, role_names: names, ...settings } ) => ( {
	...settings,
	operating: filled( settings.operating_address ),
} );

// Un indirizzo incollato senza protocollo ("instagram.com/x") è l'errore più comune: il server vuole http(s)://.
const url = ( text ) => {
	const address = text.trim();
	return ! address || /^https?:\/\//i.test( address )
		? address
		: `https://${ address }`;
};

const place = ( address ) => ( {
	...address,
	maps_url: url( address.maps_url ),
} );

// Ciò che la pagina manda con PUT /barrio/v1/company: la sede operativa spenta si svuota, i profili vuoti non si
// salvano, email e indirizzi perdono gli spazi intorno.
export const body = ( { operating, ...draft } ) => ( {
	...draft,
	...Object.fromEntries(
		EMAILS.map( ( field ) => [ field, draft[ field ].trim() ] )
	),
	address: place( draft.address ),
	operating_address: operating ? place( draft.operating_address ) : ADDRESS,
	profiles: draft.profiles.map( url ).filter( Boolean ),
} );

// Il primo dato che il server rifiuterebbe con le regole di Module::schema(), come [ campo, 'email' | 'country' ]:
// la pagina lo dice prima di salvare, al posto di "address[country] non corrisponde al pattern ^([A-Z]{2})?$".
export function problem( data ) {
	for ( const field of EMAILS ) {
		if (
			data[ field ] &&
			! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( data[ field ] )
		) {
			return [ field, 'email' ];
		}
	}
	for ( const field of [ 'address', 'operating_address' ] ) {
		if ( ! /^([A-Z]{2})?$/.test( data[ field ].country ) ) {
			return [ field, 'country' ];
		}
	}
	return null;
}
