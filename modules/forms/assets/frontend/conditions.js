// Stessa logica di Conditions.php: tests/fixtures/conditions.json le verifica entrambe.

const NUMBER = /^-?\d+(\.\d+)?$/;
const numeric = ( ...values ) => values.every( ( v ) => NUMBER.test( v ) );

// Una checkbox ha più valori: basta che uno soddisfi la regola (per != nessuno deve essere uguale).
function test( values, operator, expected ) {
	switch ( operator ) {
		case '=':
			return values.includes( expected );
		case '!=':
			return ! values.includes( expected );
		case '>':
			return values.some(
				( v ) =>
					numeric( v, expected ) && Number( v ) > Number( expected )
			);
		case '<':
			return values.some(
				( v ) =>
					numeric( v, expected ) && Number( v ) < Number( expected )
			);
		case 'contains':
			return values.some( ( v ) => v.includes( expected ) );
		case 'starts_with':
			return values.some( ( v ) => v.startsWith( expected ) );
		case 'ends_with':
			return values.some( ( v ) => v.endsWith( expected ) );
		case 'empty':
			return values.length === 0;
		case 'not_empty':
			return values.length > 0;
		default:
			return false;
	}
}

// Senza prototipo: una chiave come "constructor" non deve trovare Object.prototype.constructor.
const get = ( values, key ) =>
	Object.hasOwn( values, key ) ? values[ key ] : [];

export function passes( conditions, values ) {
	if ( ! conditions ) {
		return true;
	}
	const results = conditions.rules.map( ( rule ) =>
		test(
			get( values, rule.field ),
			rule.operator,
			String( rule.value ?? '' )
		)
	);
	const met =
		conditions.logic === 'any'
			? results.includes( true )
			: ! results.includes( false );
	return conditions.action === 'hide' ? ! met : met;
}

/**
 * Visibilità dei campi, valutati nell'ordine del form: un campo nascosto vale come vuoto per le
 * regole che lo usano, e una regola su un campo successivo o inesistente lo vede vuoto.
 *
 * @param {Array<{key: string, conditions: ?Object}>} fields
 * @param {Object<string, string[]>}                  values Per chiave, senza stringhe vuote.
 * @return {Object<string, boolean>} Per chiave.
 */
export function visibility( fields, values ) {
	const visible = {};
	const seen = {};
	for ( const { key, conditions } of fields ) {
		visible[ key ] = passes( conditions, seen );
		seen[ key ] = visible[ key ] ? get( values, key ) : [];
	}
	return visible;
}
