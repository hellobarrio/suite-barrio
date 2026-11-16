<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use WP_UnitTestCase;

// L'estensione barrio-suite-wpgraphql (caricata da tests/bootstrap.php). Gira solo con WPGraphQL, nella
// riga WPGraphQL della CI (WP_GRAPHQL=1); nelle altre la suite prova che senza WPGraphQL non dà errori.
final class WpGraphqlTest extends WP_UnitTestCase {

	// Le query di un frontend Next.js del prototipo (hello_standard), così come le invia.
	private const GET_FORM = 'query getForm($id: Int, $lang: String = "it") {
		hbForm(id: $id, lang: $lang) {
			id
			title
			steps {
				fields {
					id name type label placeholder required pattern errorMessage min max format
					options { value label }
					conditionalLogic { action enabled logicType rules { fieldName operator value } }
				}
			}
			settings { submitText successMessage errorMessage afterSubmit redirectUrl displayPageInfo }
		}
	}';

	private const SUBMIT = 'mutation HBSubmitForm($input: HbSubmitFormInput!) {
		hbSubmitForm(input: $input) { success message submissionId errors }
	}';

	private Form $form;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WPGraphQL' ) ) {
			$this->markTestSkipped( 'Needs WPGraphQL (WP_GRAPHQL=1).' );
		}
		$this->form = FormRepository::create(
			'Contact',
			[
				'en' => [
					'submit_label' => 'Send',
					'success_url'  => '/thanks',
				],
			],
			[],
			new FormVersion(
				[
					new Field(
						'name',
						'text',
						[
							'en' => [
								'label'       => 'Name',
								'placeholder' => 'Your name',
							],
						],
						true
					),
					new Field( 'email', 'email', [ 'en' => [ 'label' => 'Email' ] ], true ),
					new Field(
						'topics',
						'checkbox',
						[
							'en' => [
								'label'   => 'Topics',
								'options' => [
									'news'   => 'News',
									'events' => 'Events, talks',
								],
							],
						],
						false,
						[ 'choices' => [ 'news', 'events' ] ]
					),
					new Field( 'next', 'page_break', [ 'en' => [ 'label' => 'Next' ] ] ),
					new Field( 'date', 'datepicker', [ 'en' => [ 'label' => 'Date' ] ] ),
					new Field(
						'cv',
						'file',
						[ 'en' => [ 'label' => 'CV' ] ],
						false,
						[ 'extensions' => [ 'txt' ] ],
						[
							'action' => 'show',
							'logic'  => 'all',
							'rules'  => [
								[
									'field'    => 'topics',
									'operator' => '=',
									'value'    => 'events',
								],
							],
						]
					),
				]
			)
		);
	}

	public function tear_down(): void {
		global $wpdb;
		// I file sul disco non tornano indietro con la transazione del test.
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT entry_id FROM %i', Db::table( 'entry_files' ) ) ) as $entry ) {
			EntryRepository::delete( (int) $entry );
		}
		parent::tear_down();
	}

	// La fixture viene dal prototipo con WPGraphQL 2.x, stampata con sdl().
	public function test_the_schema_is_the_prototype_one(): void {
		$this->assertStringEqualsFile( dirname( __DIR__ ) . '/fixtures/wpgraphql-schema.txt', self::sdl() );
	}

	public function test_a_form_reads_like_in_the_prototype(): void {
		$form = graphql(
			[
				'query'     => self::GET_FORM,
				'variables' => [ 'id' => $this->form->id ],
			]
		)['data']['hbForm'];

		$this->assertSame( [ [ 'name', 'email', 'topics' ], [ 'date', 'cv' ] ], array_map( static fn( array $step ): array => array_column( $step['fields'], 'name' ), $form['steps'] ) );
		[ $name, $email, $topics ] = $form['steps'][0]['fields'];
		[ $date, $cv ]             = $form['steps'][1]['fields'];
		$this->assertSame(
			[
				'id'               => 'name',
				'name'             => 'name',
				'type'             => 'text',
				'label'            => 'Name',
				'placeholder'      => 'Your name',
				'required'         => true,
				'pattern'          => '',
				'errorMessage'     => '',
				'min'              => null,
				'max'              => null,
				'format'           => '',
				'options'          => [],
				'conditionalLogic' => null,
			],
			$name
		);
		$this->assertSame( 1, preg_match( ';' . $email['pattern'] . ';', 'Ada.Lovelace+form@Example.co.uk' ) );
		$this->assertSame( 0, preg_match( ';' . $email['pattern'] . ';', 'ada@example' ) );
		$this->assertSame(
			[
				[
					'value' => 'news',
					'label' => 'News',
				],
				[
					'value' => 'events',
					'label' => 'Events, talks',
				],
			],
			$topics['options']
		);
		$this->assertSame( 'dd/MM/yyyy', $date['format'] );
		$this->assertSame(
			[
				'action'    => 'show',
				'enabled'   => true,
				'logicType' => 'all',
				'rules'     => [
					[
						'fieldName' => 'topics',
						'operator'  => '=',
						'value'     => 'events',
					],
				],
			],
			$cv['conditionalLogic']
		);
		$this->assertSame(
			[
				'submitText'      => 'Send',
				'successMessage'  => '',
				'errorMessage'    => '',
				'afterSubmit'     => 'redirect',
				'redirectUrl'     => '/thanks',
				'displayPageInfo' => true,
			],
			$form['settings']
		);
	}

	public function test_missing_and_trashed_forms_are_null(): void {
		FormRepository::set_status( $this->form->id, FormStatus::Trash );

		foreach ( [ $this->form->id, 999999 ] as $id ) {
			$response = graphql(
				[
					'query'     => self::GET_FORM,
					'variables' => [ 'id' => $id ],
				]
			);
			$this->assertNull( $response['data']['hbForm'] );
			$this->assertArrayNotHasKey( 'errors', $response );
		}
	}

	// Come le compone hello_standard: checkbox "Etichetta: true | …", file in base64, la sua "currentpage".
	public function test_a_submission_from_the_prototype_frontend_is_saved(): void {
		$payload = $this->submit(
			[
				'name'        => 'Ada',
				'email'       => 'ada@example.com',
				'topics'      => 'News: false | Events, talks: true',
				'date'        => '16/11/2026',
				'cv'          => (string) wp_json_encode(
					[
						'content'  => base64_encode( 'Hello' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'filename' => 'cv.txt',
						'type'     => 'text/plain',
					]
				),
				'currentpage' => 'Contacts|https://example.org/contatti/?utm_source=x',
			]
		);

		$this->assertTrue( $payload['success'] );
		$this->assertSame( 'Thank you, your submission has been received.', $payload['message'] );
		$this->assertSame( [], $payload['errors'] );
		$entry = EntryRepository::get( $payload['submissionId'] );
		$this->assertSame( 'https://example.org/contatti/', $entry->source_url );
		$this->assertNull( $entry->user_id );
		$values = [];
		foreach ( $this->form->current->fields as $field ) {
			$values[ $field->key ] = $entry->values[ (int) $field->id ] ?? null;
		}
		$this->assertSame(
			[
				'name'   => [ 'Ada' ],
				'email'  => [ 'ada@example.com' ],
				'topics' => [ 'events' ],
				'next'   => null,
				'date'   => [ '2026-11-16' ],
				'cv'     => null,
			],
			$values
		);
		$this->assertSame( 'cv.txt', $entry->files[0]['original_name'] );
		$this->assertStringEqualsFile( FileStore::dir() . '/' . $entry->files[0]['stored_path'], 'Hello' );
	}

	public function test_checkboxes_also_arrive_as_values_separated_by_commas(): void {
		$payload = $this->submit(
			[
				'name'   => 'Ada',
				'email'  => 'ada@example.com',
				'topics' => 'News, events',
			]
		);

		$topics = $this->form->current->fields[2];
		$this->assertSame( [ 'news', 'events' ], EntryRepository::get( $payload['submissionId'] )->values[ (int) $topics->id ] );
	}

	// Il problema del prototipo: un .php in base64 finiva in /uploads con il nome scelto dal client.
	public function test_a_rejected_submission_comes_back_in_the_payload_and_saves_nothing(): void {
		global $wpdb;
		$entries = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'entries' ) ) );
		$temp    = glob( get_temp_dir() . '*' );

		$payload = $this->submit(
			[
				'name'   => '',
				'email'  => 'ada@example',
				'topics' => 'Events, talks: true',
				'cv'     => (string) wp_json_encode(
					[
						'content'  => base64_encode( '<?php echo "pwned";' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'filename' => 'shell.php',
					]
				),
			]
		);

		$this->assertSame(
			[
				'success'      => false,
				'message'      => 'Name: This field is required.',
				'submissionId' => null,
				'errors'       => [
					'Name: This field is required.',
					'Email: Please enter a valid email address.',
					'CV: This file type is not allowed. Accepted formats: TXT.',
				],
			],
			$payload
		);
		$this->assertSame( $entries, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'entries' ) ) ) );
		$this->assertSame( $temp, glob( get_temp_dir() . '*' ) );
		$this->assertSame( [], glob( wp_upload_dir()['basedir'] . '/*shell*' ) );
	}

	public function test_a_closed_form_is_rejected_in_the_payload(): void {
		FormRepository::set_status( $this->form->id, FormStatus::Closed );

		$payload = $this->submit( [ 'name' => 'Ada' ] );

		$this->assertFalse( $payload['success'] );
		$this->assertSame( [ 'This form is no longer accepting submissions.' ], $payload['errors'] );
	}

	// Un frontend con l'application password invia dal suo server: il suo IP è lo stesso per tutti.
	public function test_only_anonymous_submissions_are_limited_per_ip(): void {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		$valid       = [
			'name'  => 'Ada',
			'email' => 'ada@example.com',
		];
		try {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
			for ( $i = 0; $i < 10; $i++ ) {
				$this->assertTrue( $this->submit( $valid )['success'] );
			}
			$this->assertSame( [ 'Too many submissions. Please try again in a few minutes.' ], $this->submit( $valid )['errors'] );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$this->assertTrue( $this->submit( $valid )['success'] );
		} finally {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		}
	}

	public function test_the_list_needs_a_user_who_can_see_the_forms(): void {
		$query = '{ hbForms { id title submissions createdAt } }';
		$this->submit(
			[
				'name'  => 'Ada',
				'email' => 'ada@example.com',
			]
		);
		$trashed = FormRepository::create( 'Old', [ 'en' => [ 'submit_label' => 'Send' ] ], [], new FormVersion( [ new Field( 'name', 'text', [ 'en' => [ 'label' => 'Name' ] ] ) ] ) );
		FormRepository::set_status( $trashed->id, FormStatus::Trash );

		$anonymous = graphql( [ 'query' => $query ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$editor = graphql( [ 'query' => $query ] );

		$this->assertNull( $anonymous['data']['hbForms'] );
		$this->assertSame( 'Sorry, you are not allowed to list the forms.', $anonymous['errors'][0]['message'] );
		$this->assertSame(
			[
				[
					'id'          => $this->form->id,
					'title'       => 'Contact',
					'submissions' => 1,
					'createdAt'   => get_date_from_gmt( $this->form->created_at ),
				],
			],
			$editor['data']['hbForms']
		);
	}

	/**
	 * Il body della mutation come lo costruisce l'API route di hello_standard.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private function submit( array $fields ): array {
		$list = [];
		foreach ( $fields as $name => $value ) {
			$list[] = [
				'name'  => $name,
				'value' => $value,
			];
		}
		$response = graphql(
			[
				'query'     => self::SUBMIT,
				'variables' => [
					'input' => [
						'formId'    => $this->form->id,
						'fields'    => $list,
						'pageUrl'   => 'https://example.org/contatti/?utm_source=x#form',
						'pageTitle' => 'Contacts',
						'lang'      => 'it',
					],
				],
			]
		);
		$this->assertArrayNotHasKey( 'errors', $response );
		return $response['data']['hbSubmitForm'];
	}

	// I tipi raggiungibili da hbForm, hbForms e hbSubmitForm, senza descrizioni e con i campi in ordine
	// alfabetico: conta che nomi, tipi e argomenti siano quelli del prototipo.
	private static function sdl(): string {
		$schema = \WPGraphQL::get_schema();
		$args   = static fn( array $arguments ): string => $arguments ? '(' . implode( ', ', array_map( static fn( $arg ): string => "{$arg->name}: {$arg->getType()}", $arguments ) ) . ')' : '';
		$lines  = [];
		$queue  = [];
		foreach ( [
			'RootQuery'    => [ 'hbForm', 'hbForms' ],
			'RootMutation' => [ 'hbSubmitForm' ],
		] as $root => $names ) {
			foreach ( $names as $name ) {
				$field   = $schema->getType( $root )->getField( $name );
				$lines[] = "{$root}.{$name}{$args( $field->args )}: {$field->getType()}";
				$queue[] = Type::getNamedType( $field->getType() );
				foreach ( $field->args as $arg ) {
					$queue[] = Type::getNamedType( $arg->getType() );
				}
			}
		}
		$types = [];
		while ( $queue ) {
			$type = array_shift( $queue );
			if ( isset( $types[ $type->name ] ) || ! ( $type instanceof ObjectType || $type instanceof InputObjectType ) ) {
				continue;
			}
			$fields = $type->getFields();
			ksort( $fields );
			$body = [];
			foreach ( $fields as $field ) {
				$body[]  = "  {$field->name}" . ( $field instanceof FieldDefinition ? $args( $field->args ) : '' ) . ": {$field->getType()}";
				$queue[] = Type::getNamedType( $field->getType() );
			}
			$types[ $type->name ] = ( $type instanceof InputObjectType ? 'input' : 'type' ) . " {$type->name} {\n" . implode( "\n", $body ) . "\n}";
		}
		ksort( $types );
		return implode( "\n", $lines ) . "\n\n" . implode( "\n\n", $types ) . "\n";
	}
}
