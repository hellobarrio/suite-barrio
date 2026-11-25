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
use BarrioSuite\Period;
use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Validator\DocumentValidator;
use WP_Error;
use WP_UnitTestCase;

// L'estensione barrio-suite-wpgraphql (caricata da tests/bootstrap.php). Gira solo con WPGraphQL, nella
// riga WPGraphQL della CI (WP_GRAPHQL=1); nelle altre la suite prova che senza WPGraphQL non dà errori.
final class WpGraphqlTest extends WP_UnitTestCase {

	// Le query di un frontend Next.js (hello_standard), con i nomi del foglio di porting.
	private const GET_FORM = 'query getForm($id: Int!, $language: String = "it") {
		hbForm(id: $id, language: $language) {
			databaseId
			title
			createdAt
			updatedAt
			steps {
				fields {
					name type label placeholder isRequired isMultiple pattern errorMessage min max format
					options { value label }
					conditionalLogic { action logicType rules { fieldName operator value } }
				}
			}
			settings { submitText successMessage afterSubmit redirectUrl }
		}
	}';

	private const SUBMIT = 'mutation HBSubmitForm($input: HbSubmitFormInput!) {
		hbSubmitForm(input: $input) { success message entryId errors { name message } }
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
					new Field(
						'areas',
						'select',
						[
							'en' => [
								'label'   => 'Areas',
								'options' => [
									'to' => 'Turin',
									'mi' => 'Milan',
								],
							],
						],
						false,
						[
							'multiple' => true,
							'choices'  => [ 'to', 'mi' ],
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

	// La fotografia dello schema, stampata con sdl(): ogni cambiamento si vede nel diff.
	public function test_the_schema_matches_the_snapshot(): void {
		$this->assertStringEqualsFile( dirname( __DIR__ ) . '/fixtures/wpgraphql-schema.txt', self::sdl() );
	}

	// Il foglio di porting (docs/graphql-porting.md) non resta indietro: ogni sua query è valida per lo schema.
	public function test_the_queries_of_the_porting_sheet_are_valid(): void {
		preg_match_all( '/```graphql\n(.+?)```/s', (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/graphql-porting.md' ), $queries ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotEmpty( $queries[1] );
		\WPGraphQL::clear_schema();
		foreach ( $queries[1] as $query ) {
			$errors = DocumentValidator::validate( \WPGraphQL::get_schema(), Parser::parse( $query ) );
			$this->assertSame( [], array_map( static fn( Error $error ): string => $error->getMessage(), $errors ), $query );
		}
	}

	public function test_a_multistep_form_reads_with_its_steps_and_fields(): void {
		$form = graphql(
			[
				'query'     => self::GET_FORM,
				'variables' => [ 'id' => $this->form->id ],
			]
		)['data']['hbForm'];

		$this->assertSame( $this->form->id, $form['databaseId'] );
		// Il titolo è interno: non agli anonimi.
		$this->assertNull( $form['title'] );
		$this->assertSame( Period::iso( $this->form->created_at ), $form['createdAt'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $form['updatedAt'] );
		$this->assertSame( [ [ 'name', 'email', 'topics' ], [ 'date', 'cv', 'areas' ] ], array_map( static fn( array $step ): array => array_column( $step['fields'], 'name' ), $form['steps'] ) );
		[ $name, $email, $topics ] = $form['steps'][0]['fields'];
		[ $date, $cv, $areas ]     = $form['steps'][1]['fields'];
		$this->assertSame(
			[
				'name'             => 'name',
				'type'             => 'text',
				'label'            => 'Name',
				'placeholder'      => 'Your name',
				'isRequired'       => true,
				'isMultiple'       => false,
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
		$this->assertSame( [ false, true ], [ $topics['isMultiple'], $areas['isMultiple'] ] );
		$this->assertSame( 'dd/MM/yyyy', $date['format'] );
		$this->assertSame(
			[
				'action'    => 'SHOW',
				'logicType' => 'ALL',
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
				'submitText'     => 'Send',
				'successMessage' => '',
				'afterSubmit'    => 'REDIRECT',
				'redirectUrl'    => '/thanks',
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
	public function test_a_submission_with_checkboxes_a_date_and_a_file_is_saved(): void {
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
		$entry = EntryRepository::get( $payload['entryId'] );
		$this->assertSame( 'https://example.org/contatti/', $entry->source_url );
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
				'areas'  => null,
			],
			$values
		);
		$this->assertSame( 'cv.txt', $entry->files[0]['original_name'] );
		$this->assertStringEqualsFile( FileStore::dir() . '/' . $entry->files[0]['stored_path'], 'Hello' );
	}

	// I frontend headless non hanno il consenso: lo ricevono come una checkbox della privacy.
	public function test_a_consent_is_a_checkbox_with_one_choice(): void {
		$label      = 'I accept the <a href="/privacy/">privacy policy</a>';
		$this->form = FormRepository::create(
			'Newsletter',
			[ 'en' => [ 'submit_label' => 'Send' ] ],
			[],
			new FormVersion( [ new Field( 'privacy', 'consent', [ 'en' => [ 'label' => $label ] ], true ) ] )
		);

		$form  = graphql(
			[
				'query'     => self::GET_FORM,
				'variables' => [ 'id' => $this->form->id ],
			]
		)['data']['hbForm'];
		$field = $form['steps'][0]['fields'][0];
		$this->assertSame(
			[
				'checkbox',
				'',
				[
					[
						'value' => '1',
						'label' => $label,
					],
				],
			],
			[ $field['type'], $field['label'], $field['options'] ]
		);
		$this->assertSame( 'MESSAGE', $form['settings']['afterSubmit'] );

		$this->assertSame(
			[
				[
					'name'    => 'privacy',
					'message' => 'This field is required.',
				],
			],
			$this->submit( [ 'privacy' => "{$label}: false" ] )['errors']
		);
		$payload = $this->submit( [ 'privacy' => "{$label}: true" ] );
		$this->assertTrue( $payload['success'] );
		$this->assertSame( [ '1' ], EntryRepository::get( $payload['entryId'] )->values[ (int) $this->form->current->fields[0]->id ] );
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
		$this->assertSame( [ 'news', 'events' ], EntryRepository::get( $payload['entryId'] )->values[ (int) $topics->id ] );
	}

	public function test_a_multiple_select_arrives_like_a_checkbox(): void {
		$payload = $this->submit(
			[
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'areas' => 'Milan,to',
			]
		);

		$areas = $this->form->current->fields[6];
		$this->assertSame( [ 'mi', 'to' ], EntryRepository::get( $payload['entryId'] )->values[ (int) $areas->id ] );
		$errors = array_column( $this->submit( [ 'areas' => 'to,cn' ] )['errors'], 'message', 'name' );
		$this->assertSame( 'Please choose one of the available options.', $errors['areas'] );
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

		// Un errore per campo, senza l'etichetta; il messaggio è il primo, con l'etichetta.
		$this->assertSame(
			[
				'success' => false,
				'message' => 'Name: This field is required.',
				'entryId' => null,
				'errors'  => [
					[
						'name'    => 'name',
						'message' => 'This field is required.',
					],
					[
						'name'    => 'email',
						'message' => 'Please enter a valid email address.',
					],
					[
						'name'    => 'cv',
						'message' => 'This file type is not allowed. Accepted formats: TXT.',
					],
				],
			],
			$payload
		);
		$this->assertSame( $entries, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'entries' ) ) ) );
		$this->assertSame( $temp, glob( get_temp_dir() . '*' ) );
		$this->assertSame( [], glob( wp_upload_dir()['basedir'] . '/*shell*' ) );
	}

	public function test_an_error_on_a_name_that_is_not_a_field_comes_without_a_label(): void {
		add_filter( 'barrio_forms_spam_check', static fn(): WP_Error => new WP_Error( 'captcha', 'No.', [ 'fields' => [ '_barrio_recaptcha' => 'Confirm you are not a robot.' ] ] ) );

		$payload = $this->submit( [ 'name' => 'Ada' ] );

		$this->assertSame( [ false, 'Confirm you are not a robot.' ], [ $payload['success'], $payload['message'] ] );
	}

	public function test_a_repeated_file_field_leaves_no_temporary_files(): void {
		$temp = glob( get_temp_dir() . '*' );
		$cv   = (string) wp_json_encode(
			[
				'content'  => base64_encode( 'Hello' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'filename' => 'cv.txt',
			]
		);

		barrio_wpgraphql_submit(
			[
				'formId' => $this->form->id,
				'fields' => [
					[
						'name'  => 'cv',
						'value' => $cv,
					],
					[
						'name'  => 'cv',
						'value' => $cv,
					],
				],
			]
		);

		$this->assertSame( $temp, glob( get_temp_dir() . '*' ) );
	}

	public function test_a_closed_form_is_rejected_in_the_payload(): void {
		FormRepository::set_status( $this->form->id, FormStatus::Closed );

		$payload = $this->submit( [ 'name' => 'Ada' ] );

		// Un errore del form, non di un campo: name è null.
		$this->assertSame(
			[
				'success' => false,
				'message' => 'This form is no longer accepting submissions.',
				'entryId' => null,
				'errors'  => [
					[
						'name'    => null,
						'message' => 'This form is no longer accepting submissions.',
					],
				],
			],
			$payload
		);
	}

	// Un frontend con l'application password invia dal suo server: il suo IP è lo stesso per tutti.
	public function test_submissions_are_limited_per_ip_unless_the_user_can_edit_posts(): void {
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
			$this->assertSame( 'Too many submissions. Please try again in a few minutes.', $this->submit( $valid )['errors'][0]['message'] );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$this->assertFalse( $this->submit( $valid )['success'], 'Any registered user would skip the limit.' );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
			$this->assertTrue( $this->submit( $valid )['success'] );
		} finally {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		}
	}

	public function test_the_list_needs_a_user_who_can_see_the_forms(): void {
		$query = '{ hbForms { databaseId title entryCount createdAt } }';
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
					'databaseId' => $this->form->id,
					'title'      => 'Contact',
					'entryCount' => 1,
					'createdAt'  => Period::iso( $this->form->created_at ),
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
						'formId'   => $this->form->id,
						'fields'   => $list,
						'pageUrl'  => 'https://example.org/contatti/?utm_source=x#form',
						'language' => 'it',
					],
				],
			]
		);
		$this->assertArrayNotHasKey( 'errors', $response );
		return $response['data']['hbSubmitForm'];
	}

	/**
	 * I campi e i tipi raggiungibili da loro, senza descrizioni e con i campi in ordine alfabetico: la
	 * fotografia di nomi, tipi, argomenti ed enum. Anche per SeoGraphqlTest e ModulesGraphqlTest.
	 *
	 * @param array<string, list<string>> $fields I campi per tipo.
	 */
	public static function sdl( array $fields = [
		'RootQuery'    => [ 'hbForm', 'hbForms' ],
		'RootMutation' => [ 'hbSubmitForm' ],
	] ): string {
		$schema = \WPGraphQL::get_schema();
		$args   = static fn( array $arguments ): string => $arguments ? '(' . implode( ', ', array_map( static fn( $arg ): string => "{$arg->name}: {$arg->getType()}", $arguments ) ) . ')' : '';
		$lines  = [];
		$queue  = [];
		foreach ( $fields as $root => $names ) {
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
			if ( $type instanceof EnumType && ! isset( $types[ $type->name ] ) ) {
				$types[ $type->name ] = "enum {$type->name} {\n" . implode( "\n", array_map( static fn( $value ): string => "  {$value->name}", $type->getValues() ) ) . "\n}";
			}
			// I tipi di WPGraphQL, come MediaItem, sono nodi con le loro interfacce: restano col nome.
			if ( isset( $types[ $type->name ] ) || ! ( $type instanceof ObjectType || $type instanceof InputObjectType ) || ( $type instanceof ObjectType && $type->getInterfaces() ) ) {
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
