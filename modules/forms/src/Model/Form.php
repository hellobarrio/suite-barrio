<?php

namespace BarrioSuite\Forms\Model;

final readonly class Form {

	/**
	 * @param array<string, array{submit_label: string, success_message?: string|null, success_url?: string|null}> $i18n
	 *        Per lingua; success_message null = messaggio standard, success_url null = resta sulla pagina.
	 * @param list<Notification> $notifications
	 * @param list<Integration>  $integrations
	 */
	public function __construct(
		public int $id,
		public string $title,
		public FormStatus $status,
		public array $i18n,
		public array $notifications,
		public FormVersion $current,
		public string $created_at,
		public string $updated_at,
		public array $integrations = [],
	) {}
}
