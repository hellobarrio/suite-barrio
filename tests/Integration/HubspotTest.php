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

// L'estensione barrio-suite-hubspot (caricata da tests/bootstrap.php), con le risposte di HubSpot simulate su
// pre_http_request.
final class HubspotTest extends WP_UnitTestCase {

	private const HUTK = '0123456789abcdef0123456789abcdef';

	private const VALID = [
		'name'        => 'Ada',
		'email'       => 'ada@example.com',
		'topics'      => [ 'quote', 'news' ],
		'privacy'     => '1',
		'newsletter'  => '1',
		'_barrio_url' => 'https://example.com/contatti/',
	];

	/** @var list<array{url: string, body: array<string, mixed>}> */
	private array $requests = [];

	/** @var list<array{code: int, body: array<string, mixed>}|WP_Error> Le risposte, in ordine; poi 200. */
	private array $responses = [];

	public function set_up(): void {
		parent::set_up();
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		Integrations::save_settings( [ 'hubspot' => [ 'portal_id' => '1234567' ] ] );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array|WP_Error {
				$this->requests[] = [
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				];
				$response         = array_shift( $this->responses ) ?? [
					'code' => 200,
					'body' => [ 'inlineMessage' => 'Grazie' ],
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

	public function tear_down(): void {
		unset( $_COOKIE['hubspotutk'] );
		parent::tear_down();
	}

	public function test_the_entry_goes_to_the_form_of_its_language_with_consent_and_subscription(): void {
		$this->post( $this->form(), 'en', [ '_barrio_hutk' => self::HUTK ] );

		$this->assertSame(
			[
				[
					'url'  => 'https://api.hsforms.com/submissions/v3/integration/submit/1234567/guid-en',
					'body' => [
						'fields'              => [
							[
								'objectTypeId' => '0-1',
								'name'         => 'firstname',
								'value'        => 'Ada',
							],
							[
								'objectTypeId' => '0-1',
								'name'         => 'email',
								'value'        => 'ada@example.com',
							],
							// Le scelte con il valore, separate da ; come le checkbox di HubSpot.
							[
								'objectTypeId' => '0-1',
								'name'         => 'interests',
								'value'        => 'quote;news',
							],
						],
						'context'             => [
							'hutk'    => self::HUTK,
							'pageUri' => 'https://example.com/contatti/',
						],
						// I testi dei campi, nella lingua di chi ha compilato e senza il link.
						'legalConsentOptions' => [
							'consent' => [
								'consentToProcess' => true,
								'text'             => 'I accept the privacy policy',
								'communications'   => [
									[
										'value' => true,
										'subscriptionTypeId' => 987654321,
										'text'  => 'I want the newsletter',
									],
								],
							],
						],
					],
				],
			],
			$this->requests
		);
		$this->assertSame( [ [ 'hubspot', 'success', 'Sent to HubSpot.' ] ], $this->notes() );
	}

	public function test_the_subscription_needs_its_field_and_the_privacy_consent(): void {
		$_COOKIE['hubspotutk'] = self::HUTK;

		$this->post( $this->form(), 'it', [ 'newsletter' => '' ] );
		// Il cookie della richiesta, se il frontend non lo manda.
		$this->assertSame( self::HUTK, $this->requests[0]['body']['context']['hutk'] );
		$this->assertSame(
			[
				'consentToProcess' => true,
				'text'             => 'Accetto la privacy',
			],
			$this->requests[0]['body']['legalConsentOptions']['consent']
		);

		$this->post( $this->form( [ 'consent_field' => '' ] ), 'it', [ '_barrio_hutk' => 'not-a-cookie' ] );
		$this->assertArrayNotHasKey( 'legalConsentOptions', $this->requests[1]['body'] );
		$this->assertSame( [ 'pageUri' => 'https://example.com/contatti/' ], $this->requests[1]['body']['context'] );

		$this->post( $this->form( [ 'subscription_field' => '' ] ) );
		$this->assertArrayNotHasKey( 'communications', $this->requests[2]['body']['legalConsentOptions']['consent'] );
	}

	public function test_errors_end_up_in_the_notes(): void {
		$this->responses = [
			[
				'code' => 400,
				'body' => [
					'status'  => 'error',
					'message' => 'The request is not valid',
					'errors'  => [
						[
							'message'   => "Error in 'fields.email'. Invalid email address",
							'errorType' => 'INVALID_EMAIL',
						],
					],
				],
			],
			[
				'code' => 404,
				'body' => [
					'status'  => 'error',
					'message' => 'Form not found',
				],
			],
			new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
		];
		$form            = $this->form();

		$this->post( $form );
		$this->assertSame( [ [ 'hubspot', 'error', "Could not send to HubSpot: HTTP 400 Error in 'fields.email'. Invalid email address" ] ], $this->notes() );
		$this->post( $form );
		$this->assertSame( [ [ 'hubspot', 'error', 'Could not send to HubSpot: HTTP 404 Form not found' ] ], $this->notes() );
		$this->post( $form );
		$this->assertSame( [ [ 'hubspot', 'error', 'Could not send to HubSpot: cURL error 28: Operation timed out' ] ], $this->notes() );

		Integrations::save_settings( [ 'hubspot' => [ 'portal_id' => '' ] ] );
		$this->requests = [];
		$this->post( $form );
		$this->assertSame( [], $this->requests );
		$this->assertSame( [ [ 'hubspot', 'error', 'Could not send to HubSpot: the account ID is missing (Settings).' ] ], $this->notes() );
	}

	/** @param array<string, string> $settings */
	private function form( array $settings = [] ): Form {
		$text = static fn( string $it, string $en, array $options = [] ): array => [
			'it' => [ 'label' => $it ] + ( $options ? [ 'options' => $options[0] ] : [] ),
			'en' => [ 'label' => $en ] + ( $options ? [ 'options' => $options[1] ] : [] ),
		];
		return FormRepository::create(
			'Contatti',
			[ 'it' => [ 'submit_label' => 'Invia' ] ],
			[],
			new FormVersion(
				[
					new Field( 'name', 'text', $text( 'Nome', 'Name' ) ),
					new Field( 'email', 'email', $text( 'Email', 'Email' ), true ),
					new Field(
						'topics',
						'checkbox',
						$text(
							'Argomenti',
							'Topics',
							[
								[
									'quote' => 'Un preventivo',
									'news'  => 'Le novità',
								],
								[
									'quote' => 'A quote',
									'news'  => 'News',
								],
							]
						),
						false,
						[ 'choices' => [ 'quote', 'news' ] ]
					),
					new Field( 'privacy', 'consent', $text( 'Accetto la <a href="/privacy">privacy</a>', 'I accept the <a href="/privacy">privacy policy</a>' ), true ),
					new Field( 'newsletter', 'consent', $text( 'Voglio la newsletter', 'I want the newsletter' ) ),
				]
			),
			[
				new Integration(
					'hubspot',
					$settings + [
						'fields'             => [ [ 'firstname', 'name' ], [ 'email', 'email' ], [ 'interests', 'topics' ], [ 'phone', 'phone' ] ],
						'consent_field'      => 'privacy',
						'subscription_id'    => '987654321',
						'subscription_field' => 'newsletter',
					],
					[
						'it' => [ 'form_id' => 'guid-it' ],
						'en' => [ 'form_id' => 'guid-en' ],
					]
				),
			]
		);
	}

	/** @param array<string, mixed> $values */
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
