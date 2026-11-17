<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Mail\Smtp;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use WP_Locale_Switcher;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Le notifiche dopo un invio dalla REST pubblica: merge tag, lingua, condizioni, esito nelle note e rinvio.
final class NotificationsTest extends WP_UnitTestCase {

	private const VALID = [
		'name'    => 'Ada',
		'email'   => 'ada@example.com',
		'topic'   => 'info',
		'privacy' => '1',
	];

	private Form $form;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		// Sito in italiano e inglese: la lingua della registrazione decide testi ed etichette.
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		$this->form = FormRepository::create( 'Contact', [ 'it' => [ 'submit_label' => 'Invia' ] ], self::notifications(), new FormVersion( self::fields() ) );
	}

	public function tear_down(): void {
		global $wpdb;
		// I file sul disco non tornano indietro con la transazione del test.
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT entry_id FROM %i', Db::table( 'entry_files' ) ) ) as $entry ) {
			EntryRepository::delete( (int) $entry );
		}
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_active_notifications_are_sent_in_the_entry_language(): void {
		$this->post(
			[
				'message'     => "Riga 1\n<b>Riga 2</b>",
				'_barrio_url' => 'https://example.org/contatti/?utm_source=x',
			] + self::VALID
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertFalse( $mailer->get_sent( 1 ), 'Only the admin notification: the confirmation needs a quote, the third is disabled.' );
		$sent = $mailer->get_sent();
		$this->assertSame( [ 'team@example.org', 'admin@example.org' ], array_column( $sent->to, 0 ) );
		$this->assertSame( 'Nuova richiesta da Ada', $sent->subject );
		$this->assertStringContainsString( 'Reply-To: ada@example.com', $sent->header );
		$body = self::body( $sent );
		$this->assertStringContainsString( '<html lang="it">', $body );
		$this->assertStringContainsString( '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;">Nuova richiesta da Ada</h1>', $body );
		// Etichette e scelte in italiano, valori con l'escape, a capo come <br>, consenso senza HTML.
		$this->assertStringContainsString( '<strong>Argomento</strong><br>Informazioni</td>', $body );
		$this->assertStringContainsString( '<strong>Messaggio</strong><br>Riga 1<br>&lt;b&gt;Riga 2&lt;/b&gt;</td>', $body );
		$this->assertStringContainsString( '<strong>Accetto la privacy</strong><br>Yes</td>', $body );
		// Senza la query string, come nella registrazione salvata.
		$this->assertStringContainsString( 'Pagina: https://example.org/contatti/</p>', $body );
		// La tabella prende il posto del paragrafo di {{all_fields}}.
		$this->assertStringNotContainsString( '<p><table', $body );
		$this->assertSame( [ [ 'notification', 'success', 'Notification "Admin" sent to team@example.org, admin@example.org.', null ] ], $this->notes() );
	}

	public function test_emails_are_in_the_language_of_the_entry_and_notes_in_that_of_the_site(): void {
		// WordPress passa solo ai locale installati: qui si finge che ci sia l'italiano.
		$switcher = $GLOBALS['wp_locale_switcher'];
		add_filter( 'get_available_languages', static fn(): array => [ 'it_IT' ] );
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- globale di WordPress.
		$GLOBALS['wp_locale_switcher']->init();
		add_filter( 'barrio_locale', static fn( string $locale, string $lang ): string => 'it' === $lang ? 'it_IT' : 'en_US', 10, 2 );
		// Ogni stringa del plugin dice in quale locale è nata, con il testo originale (non la traduzione italiana).
		add_filter( 'gettext_barrio-suite', static fn( string $translation, string $text ): string => get_locale() . ":{$text}", 10, 2 );
		$yes = static fn(): string => self::body( tests_retrieve_phpmailer_instance()->get_sent() );
		try {
			// La richiesta REST è in inglese: la nota passa all'italiano, la lingua principale.
			$this->post( [ '_barrio_lang' => 'en' ] + self::VALID );
			$this->assertStringContainsString( '<strong>Accetto la privacy (en)</strong><br>en_US:Yes</td>', $yes() );
			$this->assertStringStartsWith( 'it_IT:Notification "Admin" sent', $this->notes()[0][2] );

			// Un visitatore italiano: errori e messaggi standard in italiano.
			$this->assertSame( 'it_IT:This field is required.', $this->post( [ '_barrio_lang' => 'it' ] )->get_data()['data']['fields']['name'] );
			reset_phpmailer_instance();
			$this->post( [ '_barrio_lang' => 'it' ] + self::VALID );
			$this->assertStringContainsString( 'it_IT:Yes', $yes() );

			// Il rinvio dall'admin (in inglese) resta nella lingua della registrazione.
			reset_phpmailer_instance();
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
			$this->resend( $this->form->notifications[0]->id );
			$this->assertStringContainsString( '<strong>Accetto la privacy</strong><br>it_IT:Yes</td>', $yes() );
			$this->assertSame( 'en_US', get_locale() );
		} finally {
			$GLOBALS['wp_locale_switcher'] = $switcher; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- globale di WordPress.
		}
	}

	public function test_conditional_notifications_follow_the_entry_values(): void {
		$this->post( [ 'topic' => 'quote' ] + self::VALID );

		$sent = tests_retrieve_phpmailer_instance()->get_sent( 1 );
		$this->assertSame( [ [ 'ada@example.com', '' ] ], $sent->to );
		$this->assertSame( 'Grazie Ada', $sent->subject );
		// heading diverso dall'oggetto.
		$this->assertStringContainsString( '>Abbiamo ricevuto la tua richiesta</h1>', self::body( $sent ) );
		$this->assertCount( 2, $this->notes() );
	}

	public function test_submitted_values_cannot_add_recipients_or_headers(): void {
		$notification = static fn( string $name, string $recipients ): Notification => new Notification(
			$name,
			$recipients,
			[
				'it' => [
					'subject' => '{{message}} {{unknown}}',
					'body'    => 'x',
				],
			]
		);
		FormRepository::save(
			$this->form->id,
			1,
			'Contact',
			[],
			[
				// Il campo admin_email non sostituisce il tag fisso {{admin_email}}.
				$notification( 'Relay', '{{name}}, {{admin_email}}' ),
				$notification( 'Nobody', '{{name}}' ),
			],
			new FormVersion( self::fields() )
		);

		$this->post(
			[
				'name'        => 'a@evil.test, b@evil.test',
				'admin_email' => 'eve@evil.test',
				'message'     => "Ciao\r\nBcc: x@evil.test",
			] + self::VALID
		);

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( [ [ 'admin@example.org', '' ] ], $sent->to );
		$this->assertSame( 'Ciao Bcc: x@evil.test', $sent->subject );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $sent->header );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 1 ) );
		$this->assertSame( [ 'error', 'Notification "Nobody" could not be sent: no valid recipient.' ], array_slice( $this->notes()[1], 1, 2 ) );
	}

	public function test_files_are_attached_with_their_original_name(): void {
		$tmp = wp_tempnam( 'cv.txt' );
		file_put_contents( $tmp, 'curriculum' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->post(
			self::VALID,
			[
				'cv' => [
					'name'     => 'Il mio CV.txt',
					'type'     => 'text/plain',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => 10,
				],
			]
		);

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'multipart/mixed', $sent->header );
		$this->assertMatchesRegularExpression( '/filename="?Il-mio-CV\.txt"?/', $sent->body );
		$this->assertStringContainsString( base64_encode( 'curriculum' ), $sent->body ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_a_failed_notification_becomes_a_note_and_can_be_resent(): void {
		$this->fail_smtp();
		$this->assertSame( 201, $this->post( self::VALID )->get_status() );
		[ [ $source, $level, $message ] ] = $this->notes();
		$this->assertSame( [ 'notification', 'error' ], [ $source, $level ] );
		$this->assertStringStartsWith( 'Notification "Admin" could not be sent: SMTP', $message );

		// SMTP sistemato: un editor rinvia.
		reset_phpmailer_instance();
		Smtp::save( [ 'host' => '' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$response = $this->resend( $this->form->notifications[0]->id );

		$this->assertSame( [ 200, [ 'sent' => true ] ], [ $response->get_status(), $response->get_data() ] );
		$this->assertSame( [ 'team@example.org', 'admin@example.org' ], array_column( tests_retrieve_phpmailer_instance()->get_sent()->to, 0 ) );
		$this->assertSame( [ 'notification', 'success', 'Notification "Admin" sent to team@example.org, admin@example.org.', (string) $editor ], $this->notes()[1] );
		// Il rinvio è una richiesta esplicita: vale anche per una notifica disattivata.
		$this->assertSame( 200, $this->resend( $this->form->notifications[2]->id )->get_status() );
		$this->assertSame( 404, $this->resend( 999999 )->get_status() );

		$this->fail_smtp();
		$this->assertSame( 502, $this->resend( $this->form->notifications[0]->id )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->resend( $this->form->notifications[0]->id )->get_status() );
	}

	public function test_an_exception_in_one_notification_does_not_stop_the_others(): void {
		add_filter(
			'barrio_forms_notification',
			static function ( array $email, Notification $notification ): array {
				if ( 'Admin' === $notification->name ) {
					throw new RuntimeException( 'Boom.' );
				}
				return $email;
			},
			10,
			2
		);

		$this->assertSame( 201, $this->post( [ 'topic' => 'quote' ] + self::VALID )->get_status() );

		$this->assertSame( 'Grazie Ada', tests_retrieve_phpmailer_instance()->get_sent()->subject );
		$this->assertSame(
			[ [ 'error', 'Notification "Admin" could not be sent: Boom.' ], [ 'success', 'Notification "Confirmation" sent to ada@example.com.' ] ],
			array_map( static fn( array $note ): array => array_slice( $note, 1, 2 ), $this->notes() )
		);
	}

	// Il criterio della fase 3: email ricevute via SMTP. Gira se c'è un Mailpit (in CI sempre).
	public function test_a_notification_reaches_a_real_smtp_server(): void {
		$host = (string) getenv( 'MAILPIT' );
		if ( '' === $host ) {
			$this->markTestSkipped( 'Set MAILPIT to the host of a Mailpit server (SMTP on 1025, API on 8025).' );
		}
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		$GLOBALS['phpmailer'] = new PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- PHPMailer vero al posto del mock dei test.
		Smtp::save(
			[
				'host'       => $host,
				'port'       => 1025,
				'encryption' => 'none',
				'username'   => 'barrio',
				'from_email' => 'forms@example.org',
				'from_name'  => 'Barrio forms',
			]
		);
		Smtp::save_password( 'secret' );
		$name = 'Ada ' . wp_generate_password( 12, false );

		$this->post( [ 'name' => $name ] + self::VALID );

		$this->assertSame( 'success', $this->notes()[0][1], $this->notes()[0][2] );
		$found = json_decode( wp_remote_retrieve_body( wp_remote_get( "http://{$host}:8025/api/v1/search?query=" . rawurlencode( "subject:\"{$name}\"" ) ) ), true );
		$this->assertSame( 1, $found['messages_count'] ?? null );
		$message = $found['messages'][0];
		$this->assertSame( [ 'forms@example.org', 'Barrio forms' ], [ $message['From']['Address'], $message['From']['Name'] ] );
		$this->assertSame( [ 'team@example.org', 'admin@example.org' ], array_column( $message['To'], 'Address' ) );
		$this->assertSame( [ 'ada@example.com' ], array_column( $message['ReplyTo'], 'Address' ) );
	}

	/** @return list<Field> */
	private static function fields(): array {
		$text = static fn( string $it, array $options = [] ): array => [
			'it' => [ 'label' => $it ] + ( $options ? [ 'options' => $options ] : [] ),
			'en' => [ 'label' => "{$it} (en)" ],
		];
		return [
			new Field( 'name', 'text', $text( 'Nome' ), true ),
			new Field( 'email', 'email', $text( 'Email' ), true ),
			new Field(
				'topic',
				'radio',
				$text(
					'Argomento',
					[
						'info'  => 'Informazioni',
						'quote' => 'Preventivo',
					]
				),
				true,
				[ 'choices' => [ 'info', 'quote' ] ]
			),
			new Field( 'message', 'textarea', $text( 'Messaggio' ) ),
			new Field( 'cv', 'file', $text( 'CV' ), false, [ 'extensions' => [ 'txt' ] ] ),
			new Field( 'privacy', 'consent', $text( 'Accetto la <a href="/privacy">privacy</a>' ), true ),
			new Field( 'admin_email', 'text', $text( 'Campo con il nome di un tag fisso' ) ),
		];
	}

	/** @return list<Notification> */
	private static function notifications(): array {
		return [
			new Notification(
				'Admin',
				'team@example.org, {{admin_email}}',
				[
					'it' => [
						'subject' => 'Nuova richiesta da {{name}}',
						'body'    => "Ecco la richiesta:\n\n{{all_fields}}\n\nPagina: {{source_url}}",
					],
					'en' => [
						'subject' => 'New request from {{name}}',
						'body'    => '{{all_fields}}',
					],
				],
				'{{email}}',
				attach_files: true
			),
			new Notification(
				'Confirmation',
				'{{email}}',
				[
					'it' => [
						'subject' => 'Grazie {{ name }}',
						'heading' => 'Abbiamo ricevuto la tua richiesta',
						'body'    => 'Ti risponderemo presto.',
					],
				],
				conditions: [
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => [
						[
							'field'    => 'topic',
							'operator' => '=',
							'value'    => 'quote',
						],
					],
				]
			),
			new Notification(
				'Disabled',
				'team@example.org',
				[
					'it' => [
						'subject' => 'Mai',
						'body'    => 'x',
					],
				],
				enabled: false
			),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 */
	private function post( array $input, array $files = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/barrio/v1/forms/{$this->form->id}/entries" );
		$request->set_body_params( $input + [ '_barrio_js' => '1' ] );
		$request->set_file_params( $files );
		return rest_do_request( $request );
	}

	private function resend( ?int $notification ): WP_REST_Response {
		global $wpdb;
		$entry = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Db::table( 'entries' ) ) );
		return rest_do_request( new WP_REST_Request( 'POST', "/barrio/v1/entries/{$entry}/notifications/{$notification}/resend" ) );
	}

	// Le note dell'ultima registrazione: source, level, message, user_id.
	/** @return list<list<string|null>> */
	private function notes(): array {
		global $wpdb;
		return array_map(
			'array_values',
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT source, level, message, user_id FROM %i WHERE entry_id = (SELECT MAX(id) FROM %i) ORDER BY id', Db::table( 'entry_notes' ), Db::table( 'entries' ) ),
				ARRAY_A
			)
		);
	}

	// PHPMailer vero verso una porta chiusa: il server rifiuta subito la connessione.
	private function fail_smtp(): void {
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		$GLOBALS['phpmailer'] = new PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- PHPMailer vero al posto del mock dei test.
		Smtp::save(
			[
				'host'       => '127.0.0.1',
				'port'       => 1,
				'encryption' => 'none',
			]
		);
	}

	// PHPMailer passa al quoted-printable quando una riga supera i 998 caratteri.
	private static function body( object $sent ): string {
		return str_contains( $sent->header, 'quoted-printable' ) ? quoted_printable_decode( $sent->body ) : $sent->body;
	}
}
