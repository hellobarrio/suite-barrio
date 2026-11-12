<?php

namespace BarrioSuite\Tests\Unit;

use BarrioSuite\Requirements;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase {

	/** @return array<string, array{string, bool}> */
	public static function versions(): array {
		return [
			'MySQL 8.0.15' => [ '8.0.15', false ],
			'MySQL 8.0.16' => [ '8.0.16', true ],
			'MySQL 8.4'    => [ '8.4.3', true ],
			'MySQL 5.7'    => [ '5.7.44-log', false ],
			'Percona 8.0'  => [ '8.0.36-28', true ],
			'MariaDB 10.4' => [ '10.4.32-MariaDB', false ],
			'MariaDB 10.5' => [ '10.5.23-MariaDB-1:10.5.23+maria~ubu2004', true ],
			'MariaDB 11.4' => [ '11.4.2-MariaDB-log', true ],
		];
	}

	/** @dataProvider versions */
	public function test_database_version( string $version, bool $supported ): void {
		$this->assertCount( $supported ? 0 : 1, Requirements::database_errors( $version, 'YES' ) );
	}

	public function test_innodb(): void {
		$this->assertSame( [], Requirements::database_errors( '8.0.36', 'DEFAULT' ) );
		$this->assertCount( 1, Requirements::database_errors( '8.0.36', 'DISABLED' ) );
		$this->assertCount( 1, Requirements::database_errors( '8.0.36', null ) );
	}
}
