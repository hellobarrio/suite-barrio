<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Model\Field;

/**
 * Logica condizionale di campi, notifiche e integrazioni. La stessa logica è in
 * assets/frontend/conditions.js: tests/fixtures/conditions.json le verifica entrambe.
 *
 *     { "action": "show"|"hide", "logic": "all"|"any", "rules": [ { "field": "chiave", "operator": "=", "value": "x" } ] }
 *
 * Null vuol dire nessuna regola: il campo è sempre visibile, la notifica parte sempre.
 */
final class Conditions {

	public const OPERATORS = [ '=', '!=', '>', '<', 'contains', 'starts_with', 'ends_with', 'empty', 'not_empty' ];

	/** @return array<string, mixed> */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'required'             => [ 'action', 'logic', 'rules' ],
			'additionalProperties' => false,
			'properties'           => [
				'action' => [
					'type' => 'string',
					'enum' => [ 'show', 'hide' ],
				],
				'logic'  => [
					'type' => 'string',
					'enum' => [ 'all', 'any' ],
				],
				'rules'  => [
					'type'     => 'array',
					'minItems' => 1,
					'items'    => [
						'type'                 => 'object',
						'required'             => [ 'field', 'operator' ],
						'additionalProperties' => false,
						'properties'           => [
							'field'    => [
								'type'    => 'string',
								'pattern' => '^[a-z][a-z0-9_]*$',
							],
							'operator' => [
								'type' => 'string',
								'enum' => self::OPERATORS,
							],
							'value'    => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	/** @param array<string, mixed>|null $conditions */
	public static function check( ?array $conditions ): ?string {
		$valid = null === $conditions ? true : rest_validate_value_from_schema( $conditions, self::schema(), 'conditions' );
		return is_wp_error( $valid ) ? $valid->get_error_message() : null;
	}

	/**
	 * Visibilità dei campi, valutati nell'ordine del form. Un campo nascosto vale come vuoto per le
	 * regole che lo usano; una regola su un campo successivo o inesistente lo vede vuoto. Così il
	 * risultato non dipende da come si valuta, ed è lo stesso nel browser e sul server.
	 *
	 * @param iterable<Field>             $fields
	 * @param array<string, list<string>> $values Per field_key, senza stringhe vuote.
	 * @return array<string, bool>
	 */
	public static function visibility( iterable $fields, array $values ): array {
		$visible = [];
		$seen    = [];
		foreach ( $fields as $field ) {
			$visible[ $field->key ] = self::passes( $field->conditions, $seen );
			$seen[ $field->key ]    = $visible[ $field->key ] ? $values[ $field->key ] ?? [] : [];
		}
		return $visible;
	}

	/**
	 * @param array<string, mixed>|null   $conditions Già validate con schema().
	 * @param array<string, list<string>> $values
	 */
	public static function passes( ?array $conditions, array $values ): bool {
		if ( null === $conditions ) {
			return true;
		}
		$results = [];
		foreach ( (array) $conditions['rules'] as $rule ) {
			$results[] = self::test( $values[ $rule['field'] ] ?? [], $rule['operator'], (string) ( $rule['value'] ?? '' ) );
		}
		$met = 'any' === $conditions['logic'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		return 'hide' === $conditions['action'] ? ! $met : $met;
	}

	// Una checkbox o una select multipla ha più valori: basta che uno soddisfi la regola (per != nessuno deve essere uguale).
	/** @param list<string> $values */
	private static function test( array $values, string $operator, string $expected ): bool {
		$some = static fn( callable $test ): bool => [] !== array_filter( $values, $test );
		return match ( $operator ) {
			'='           => in_array( $expected, $values, true ),
			'!='          => ! in_array( $expected, $values, true ),
			'>'           => $some( static fn( string $v ): bool => self::numeric( $v, $expected ) && (float) $v > (float) $expected ),
			'<'           => $some( static fn( string $v ): bool => self::numeric( $v, $expected ) && (float) $v < (float) $expected ),
			'contains'    => $some( static fn( string $v ): bool => str_contains( $v, $expected ) ),
			'starts_with' => $some( static fn( string $v ): bool => str_starts_with( $v, $expected ) ),
			'ends_with'   => $some( static fn( string $v ): bool => str_ends_with( $v, $expected ) ),
			'empty'       => [] === $values,
			'not_empty'   => [] !== $values,
			default       => false,
		};
	}

	// Stessa espressione in JS: is_numeric() e parseFloat() accettano formati diversi.
	private static function numeric( string ...$values ): bool {
		foreach ( $values as $value ) {
			if ( 1 !== preg_match( '/^-?\d+(\.\d+)?\z/', $value ) ) {
				return false;
			}
		}
		return true;
	}
}
