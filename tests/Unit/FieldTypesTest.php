<?php

namespace BarrioSuite\Tests\Unit;

use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Model\Field;
use PHPUnit\Framework\TestCase;

final class FieldTypesTest extends TestCase {

	// Il form d'esempio usa tutti i tipi core: resta valido finché lo sono le loro config.
	public function test_the_example_form_uses_every_type_with_a_valid_config(): void {
		$example = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/dev/example-form.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$types   = [];
		foreach ( $example['fields'] as $f ) {
			$this->assertNull( FieldTypes::check( new Field( $f['key'], $f['type'], $f['i18n'], $f['required'] ?? false, $f['config'] ?? [], $f['conditions'] ?? null ) ), $f['key'] );
			$types[] = $f['type'];
		}
		$this->assertEqualsCanonicalizing( [ 'text', 'email', 'textarea', 'tel', 'number', 'datepicker', 'select', 'radio', 'checkbox', 'file', 'consent', 'hidden', 'page_break' ], $types );
	}

	public function test_invalid_definitions_are_rejected(): void {
		$show_if = [
			'action' => 'show',
			'logic'  => 'all',
			'rules'  => [
				[
					'field'    => 'a',
					'operator' => 'is',
				],
			],
		];
		foreach ( [
			'unknown type'          => [ 'signature', [] ],
			'choices missing'       => [ 'select', [] ],
			'no choices'            => [ 'radio', [ 'choices' => [] ] ],
			'duplicate choices'     => [ 'checkbox', [ 'choices' => [ 'a', 'a' ] ] ],
			'extensions missing'    => [ 'file', [ 'max_size_mb' => 5 ] ],
			'uppercase extension'   => [ 'file', [ 'extensions' => [ 'PDF' ] ] ],
			'zero size'             => [
				'file',
				[
					'extensions'  => [ 'pdf' ],
					'max_size_mb' => 0,
				],
			],
			'unknown property'      => [ 'email', [ 'pattern' => '.+' ] ],
			'invalid pattern'       => [ 'text', [ 'pattern' => '(' ] ],
			// Il valore inviato arriva su una riga: una scelta così non si potrebbe mai inviare.
			'choice with spaces'    => [ 'select', [ 'choices' => [ 'Corso  avanzato ' ] ] ],
			'extension WP refuses'  => [ 'file', [ 'extensions' => [ 'pdf', 'exe' ] ] ],
			'number min not number' => [ 'number', [ 'min' => 'five' ] ],
			'invalid conditions'    => [ 'text', [], $show_if ],
			'multiple not on radio' => [
				'radio',
				[
					'choices'  => [ 'a' ],
					'multiple' => true,
				],
			],
			'multiple not boolean'  => [
				'select',
				[
					'choices'  => [ 'a' ],
					'multiple' => 'yes',
				],
			],
		] as $what => $definition ) {
			$this->assertIsString( FieldTypes::check( new Field( 'a', $definition[0], [], config: $definition[1], conditions: $definition[2] ?? null ) ), $what );
		}
	}

	/** @dataProvider normalized */
	public function test_normalize( string $type, array $config, mixed $raw, array $expected ): void {
		$this->assertSame( $expected, FieldTypes::normalize( new Field( 'a', $type, [], config: $config ), $raw ) );
	}

	/** @return array<string, array{string, array<string, mixed>, mixed, list<string>}> */
	public static function normalized(): array {
		return [
			'text on one line'           => [ 'text', [], "  Ada \n\t Lovelace\x07 ", [ 'Ada Lovelace' ] ],
			'empty text'                 => [ 'text', [], '   ', [] ],
			'array to a single field'    => [ 'text', [], [ 'a', 'b' ], [] ],
			'textarea keeps lines'       => [ 'textarea', [], "Uno\r\n\tDue\r\n", [ "Uno\n\tDue" ] ],
			'checkbox values'            => [ 'checkbox', [], [ 'news', '', 'news', [ 'x' ], 'events' ], [ 'news', 'events' ] ],
			'checkbox single value'      => [ 'checkbox', [], 'news', [ 'news' ] ],
			'multiple select values'     => [ 'select', [ 'multiple' => true ], [ 'to', ' to ', 'mi' ], [ 'to', 'mi' ] ],
			'array to a single select'   => [ 'select', [], [ 'to', 'mi' ], [] ],
			'consent given'              => [ 'consent', [], 'on', [ '1' ] ],
			'consent from JSON'          => [ 'consent', [], true, [ '1' ] ],
			'consent refused'            => [ 'consent', [], 'no', [] ],
			'hidden fixed value'         => [ 'hidden', [ 'value' => 'landing' ], 'tampered', [ 'landing' ] ],
			'hidden from the URL'        => [ 'hidden', [ 'param' => 'ref' ], 'spring', [ 'spring' ] ],
			'hidden from the URL is cut' => [ 'hidden', [ 'param' => 'ref' ], str_repeat( 'è', 300 ), [ str_repeat( 'è', 255 ) ] ],
			// Come trim() e \s in form.js: il browser e il server devono vedere lo stesso valore.
			'unicode spaces on a line'   => [ 'text', [], "\u{00A0}San\u{00A0}\u{2003}Remo\u{3000}", [ 'San Remo' ] ],
			'unicode spaces at the ends' => [ 'textarea', [], "\u{00A0}Uno\u{00A0}due\u{2003}\n", [ "Uno\u{00A0}due" ] ],
			'file name'                  => [
				'file',
				[],
				[
					'name'  => 'cv.pdf',
					'error' => UPLOAD_ERR_OK,
				],
				[ 'cv.pdf' ],
			],
			'no file'                    => [
				'file',
				[],
				[
					'name'  => '',
					'error' => UPLOAD_ERR_NO_FILE,
				],
				[],
			],
			'page break has no value'    => [ 'page_break', [], 'x', [] ],
			'number keeps what was sent' => [ 'number', [], ' 1.5 ', [ '1.5' ] ],
		];
	}

	// WordPress toglie i byte non validi o, dalle versioni recenti, li sostituisce con U+FFFD.
	public function test_invalid_utf8_never_reaches_the_database(): void {
		$value = FieldTypes::normalize( new Field( 'a', 'text', [] ), "caf\xC3 au lait" )[0];

		$this->assertTrue( mb_check_encoding( $value, 'UTF-8' ) );
		$this->assertStringStartsWith( 'caf', $value );
	}

	/** @dataProvider validated */
	public function test_validate( string $type, array $config, array $values, ?string $expected ): void {
		$this->assertSame( $expected, FieldTypes::validate( new Field( 'a', $type, [], true, $config ), $values ) );
	}

	/** @return array<string, array{string, array<string, mixed>, list<string>, string|null}> */
	public static function validated(): array {
		$range = [
			'min' => 1,
			'max' => 10,
		];
		return [
			'required'                    => [ 'text', [], [], 'This field is required.' ],
			'too long'                    => [ 'text', [], [ str_repeat( 'è', 256 ) ], 'Please use at most 255 characters.' ],
			'textarea is long'            => [ 'textarea', [], [ str_repeat( 'è', 10000 ) ], null ],
			'email'                       => [ 'email', [], [ 'ada@example' ], 'Please enter a valid email address.' ],
			'valid email'                 => [ 'email', [], [ 'ada@example.com' ], null ],
			'not a number'                => [ 'number', [], [ '12abc' ], 'Please enter a number.' ],
			'below min'                   => [ 'number', $range, [ '0.5' ], 'Please enter a number greater than or equal to 1.' ],
			'above max'                   => [ 'number', $range, [ '11' ], 'Please enter a number less than or equal to 10.' ],
			'in range'                    => [ 'number', $range, [ '10' ], null ],
			'impossible date'             => [ 'datepicker', [], [ '2025-02-30' ], 'Please enter a valid date.' ],
			'not ISO date'                => [ 'datepicker', [], [ '30/01/2025' ], 'Please enter a valid date.' ],
			'valid date'                  => [ 'datepicker', [], [ '2028-02-29' ], null ],
			'unknown choice'              => [ 'select', [ 'choices' => [ 'to', 'cn' ] ], [ 'mi' ], 'Please choose one of the available options.' ],
			'one bad choice'              => [ 'checkbox', [ 'choices' => [ 'a', 'b' ] ], [ 'a', 'c' ], 'Please choose one of the available options.' ],
			'one bad multiple choice'     => [
				'select',
				[
					'choices'  => [ 'to', 'mi' ],
					'multiple' => true,
				],
				[ 'to', 'cn' ],
				'Please choose one of the available options.',
			],
			'pattern'                     => [ 'tel', [ 'pattern' => '\+?[0-9 ]{6,}' ], [ '+39 011 1234' ], null ],
			// Come l'attributo HTML: deve corrispondere tutto il valore.
			'partial pattern'             => [ 'tel', [ 'pattern' => '[0-9]+' ], [ '123abc' ], 'Please match the requested format.' ],
			'pattern with hash'           => [ 'text', [ 'pattern' => '#[0-9]+' ], [ '#12' ], null ],
			'file size checked elsewhere' => [ 'file', [], [ str_repeat( 'x', 300 ) . '.pdf' ], null ],
		];
	}
}
