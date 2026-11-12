<?php

namespace BarrioSuite\Tests\Unit;

use BarrioSuite\Forms\Conditions;
use BarrioSuite\Forms\Model\Field;
use PHPUnit\Framework\TestCase;

// Le stesse fixture di tests/js/conditions.test.js: le due implementazioni non possono divergere.
final class ConditionsTest extends TestCase {

	/**
	 * @dataProvider cases
	 * @param list<array{key: string, conditions: array<string, mixed>|null}> $fields
	 * @param array<string, list<string>>                                      $values
	 * @param array<string, bool>                                              $visible
	 */
	public function test_fixture( array $fields, array $values, array $visible ): void {
		$fields = array_map( static fn( array $f ): Field => new Field( $f['key'], 'text', [], conditions: $f['conditions'] ), $fields );
		$this->assertSame( $visible, Conditions::visibility( $fields, $values ) );
	}

	/** @return array<string, array{0: mixed, 1: mixed, 2: mixed}> */
	public static function cases(): array {
		$cases = [];
		foreach ( json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/conditions.json' ), true ) as $case ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$cases[ $case['name'] ] = [ $case['fields'], $case['values'], $case['visible'] ];
		}
		return $cases;
	}

	public function test_schema_accepts_the_documented_format_only(): void {
		$rule = [
			'field'    => 'type',
			'operator' => '=',
			'value'    => 'quote',
		];
		$this->assertNull( Conditions::check( null ) );
		$this->assertNull(
			Conditions::check(
				[
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => [ $rule ],
				]
			)
		);

		foreach ( [
			'no rules'         => [
				'action' => 'show',
				'logic'  => 'all',
				'rules'  => [],
			],
			'unknown action'   => [
				'action' => 'toggle',
				'logic'  => 'all',
				'rules'  => [ $rule ],
			],
			'unknown operator' => [
				'action' => 'show',
				'logic'  => 'all',
				'rules'  => [ [ 'operator' => 'is' ] + $rule ],
			],
			'invalid key'      => [
				'action' => 'show',
				'logic'  => 'all',
				'rules'  => [ [ 'field' => 'Type' ] + $rule ],
			],
			'missing logic'    => [
				'action' => 'show',
				'rules'  => [ $rule ],
			],
		] as $what => $conditions ) {
			$this->assertNotNull( Conditions::check( $conditions ), $what );
		}
	}
}
