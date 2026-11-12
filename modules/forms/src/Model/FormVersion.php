<?php

namespace BarrioSuite\Forms\Model;

// Versione immutabile di un form: i campi, cioè ciò che il visitatore legge prima di inviare.
// Per salvarne una nuova bastano i campi; gli altri valori li assegna il database.
final readonly class FormVersion {

	/** @param list<Field> $fields Nell'ordine in cui compaiono nel form. */
	public function __construct(
		public array $fields,
		public ?int $id = null,
		public int $version = 0,
		public ?string $created_at = null,
		public ?int $created_by = null,
	) {}
}
