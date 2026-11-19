<?php

namespace BarrioSuite\Tests\Unit;

use BarrioSuite\Hours\Render;
use PHPUnit\Framework\TestCase;

// Le stesse fixture di tests/js/hours.test.js: lo stato scritto dal server e quello del browser non possono divergere.
final class HoursStatusTest extends TestCase {

	/**
	 * @dataProvider cases
	 * @param array{days: list<array{at: int, name: string}>, open: list<array{0: int, 1: int, 2: string, 3: string}>, text: array<string, string>} $data
	 * @param array{open: bool, text: string}|null                                                                                                 $status
	 */
	public function test_fixture( array $data, int $now, ?array $status ): void {
		$this->assertSame( $status, Render::status( $data, $now ) );
	}

	/** @return array<string, array{0: mixed, 1: mixed, 2: mixed}> */
	public static function cases(): array {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/hours-status.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cases   = [];
		foreach ( $fixture['cases'] as $case ) {
			$data                   = [
				'days' => $fixture['days'],
				'open' => $case['open'],
				'text' => $fixture['text'],
			];
			$cases[ $case['name'] ] = [ $data, $case['now'], $case['status'] ];
		}
		return $cases;
	}
}
