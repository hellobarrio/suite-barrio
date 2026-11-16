<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Integrations;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Integration;
use BarrioSuite\Forms\Repository\FormRepository;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

// L'estensione barrio-suite-mailchimp (caricata da tests/bootstrap.php), con le risposte di Mailchimp
// simulate su pre_http_request.
final class MailchimpTest extends WP_UnitTestCase {

	private const VALID = [
		'name'       => 'Ada',
		'email'      => 'Ada@Example.com',
		'topic'      => 'quote',
		'newsletter' => '1',
	];

	/** @var list<array{method: string, url: string, auth: string, body: array<string, mixed>}> */
	private array $requests = [];

	/** @var list<array{code: int, body: array<string, mixed>}|WP_Error> Le risposte, in ordine; poi 200. */
	private array $responses = [];

	public function set_up(): void {
		parent::set_up();
		add_filter( 'barrio_forms_languages', static fn(): array => [ 'it', 'en' ] );
		Integrations::save_settings( [ 'mailchimp' => [ 'api_key' => 'abc123-us21' ] ] );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array|WP_Error {
				$this->requests[] = [
					'method' => $args['method'],
					'url'    => $url,
					'auth'   => $args['headers']['Authorization'],
					'body'   => json_decode( $args['body'], true ),
				];
				$response         = array_shift( $this->responses ) ?? [
					'code' => 200,
					'body' => [],
				];
				return $response instanceof WP_Error ? $response : [
					'headers'  => [],
					'body'     => (string) wp_json_encode( $response['body'] ),
					'response' => [ 'code' => $response['code'] ],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	public function test_the_contact_is_added_to_the_audience_of_its_language_with_the_tags(): void {
		$form = $this->form();

		$this->post( $form, 'en' );

		$member = 'https://us21.api.mailchimp.com/3.0/lists/list-en/members/' . md5( 'ada@example.com' );
		$this->assertSame(
			[
				[
					'method' => 'PUT',
					'url'    => $member,
					'auth'   => 'Basic ' . base64_encode( 'barrio:abc123-us21' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'body'   => [
						'email_address' => 'Ada@Example.com',
						'status_if_new' => 'subscribed',
						// Le scelte con l'etichetta, nella lingua di chi ha compilato.
						'merge_fields'  => [
							'FNAME' => 'Ada',
							'TOPIC' => 'A quote',
						],
					],
				],
				[
					'method' => 'POST',
					'url'    => "{$member}/tags",
					'auth'   => 'Basic ' . base64_encode( 'barrio:abc123-us21' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'body'   => [
						'tags' => [
							[
								'name'   => 'Website',
								'status' => 'active',
							],
							[
								'name'   => 'Newsletter EN',
								'status' => 'active',
							],
						],
					],
				],
			],
			$this->requests
		);
		$this->assertSame( [ [ 'mailchimp', 'success', 'Sent to Mailchimp.' ] ], $this->notes() );
	}

	public function test_without_the_consent_the_contact_is_added_without_the_consent_tags(): void {
		$form = $this->form();

		$this->post( $form, 'it', [ 'newsletter' => '' ] );

		$this->assertStringContainsString( '/lists/list-it/', $this->requests[0]['url'] );
		$this->assertSame( [ 'Website', 'Sito' ], array_column( $this->requests[1]['body']['tags'], 'name' ) );
	}

	public function test_without_a_consent_field_the_consent_tags_are_always_added(): void {
		$form = $this->form( [ 'consent_field' => '' ] );

		$this->post( $form, 'it', [ 'newsletter' => '' ] );

		$this->assertSame( [ 'Website', 'Sito', 'Newsletter IT' ], array_column( $this->requests[1]['body']['tags'], 'name' ) );
	}

	public function test_errors_end_up_in_the_notes(): void {
		$this->responses = [
			[
				'code' => 400,
				'body' => [
					'title'  => 'Invalid Resource',
					'detail' => 'The resource submitted could not be validated.',
					'errors' => [
						[
							'field'   => 'TOPIC',
							'message' => 'This value is not valid.',
						],
					],
				],
			],
		];
		$this->post( $this->form() );
		$this->assertSame( [ [ 'mailchimp', 'error', 'Could not send to Mailchimp: HTTP 400 The resource submitted could not be validated. TOPIC: This value is not valid.' ] ], $this->notes() );

		$this->responses = [
			[
				'code' => 200,
				'body' => [],
			],
			new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
		];
		$this->post( $this->form() );
		$this->assertSame( [ [ 'mailchimp', 'error', 'Could not send to Mailchimp: the contact was added, but not the tags: cURL error 28: Operation timed out' ] ], $this->notes() );

		Integrations::save_settings( [ 'mailchimp' => [ 'api_key' => 'abc123' ] ] );
		$this->requests = [];
		$this->post( $this->form() );
		$this->assertSame( [], $this->requests );
		$this->assertSame( [ [ 'mailchimp', 'error', 'Could not send to Mailchimp: the API key is missing or not valid (Settings).' ] ], $this->notes() );
	}

	/** @param array<string, string> $settings */
	private function form( array $settings = [] ): Form {
		$text = static fn( string $it, string $en, array $options = [] ): array => [
			'it' => [ 'label' => $it ] + ( $options ? [ 'options' => $options[0] ] : [] ),
			'en' => [ 'label' => $en ] + ( $options ? [ 'options' => $options[1] ] : [] ),
		];
		return FormRepository::create(
			'Newsletter',
			[ 'it' => [ 'submit_label' => 'Iscriviti' ] ],
			[],
			new FormVersion(
				[
					new Field( 'name', 'text', $text( 'Nome', 'Name' ) ),
					new Field( 'email', 'email', $text( 'Email', 'Email' ), true ),
					new Field(
						'topic',
						'radio',
						$text(
							'Argomento',
							'Topic',
							[
								[ 'quote' => 'Un preventivo' ],
								[ 'quote' => 'A quote' ],
							]
						),
						false,
						[ 'choices' => [ 'quote' ] ]
					),
					new Field( 'newsletter', 'consent', $text( 'Voglio la newsletter', 'I want the newsletter' ) ),
				]
			),
			[
				new Integration(
					'mailchimp',
					$settings + [
						'email_field'   => 'email',
						'merge_fields'  => [ [ 'FNAME', 'name' ], [ 'TOPIC', 'topic' ], [ 'PHONE', 'phone' ] ],
						'consent_field' => 'newsletter',
					],
					[
						'it' => [
							'list_id'      => 'list-it',
							'tags'         => 'Website, Sito',
							'consent_tags' => 'Newsletter IT',
						],
						'en' => [
							'list_id'      => 'list-en',
							'tags'         => 'Website,,',
							'consent_tags' => 'Newsletter EN, Website',
						],
					]
				),
			]
		);
	}

	/** @param array<string, string> $values */
	private function post( Form $form, string $lang = 'it', array $values = [] ): void {
		$request = new WP_REST_Request( 'POST', "/barrio/v1/forms/{$form->id}/entries" );
		$request->set_body_params(
			$values + self::VALID + [
				'_barrio_js'   => '1',
				'_barrio_lang' => $lang,
			]
		);
		$this->assertSame( 201, rest_do_request( $request )->get_status() );
	}

	/** @return list<list<string>> source, level e messaggio, dell'ultima registrazione. */
	private function notes(): array {
		global $wpdb;
		return array_map(
			'array_values',
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT source, level, message FROM %i WHERE entry_id = (SELECT MAX(id) FROM %i) ORDER BY id', Db::table( 'entry_notes' ), Db::table( 'entries' ) ),
				ARRAY_A
			)
		);
	}
}
