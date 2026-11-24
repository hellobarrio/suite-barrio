<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Popups\PopupRepository;
use WP_Locale_Switcher;
use WP_UnitTestCase;

// Popup, banner e orari in barrio-suite-wpgraphql. Gira solo con WPGraphQL, come WpGraphqlTest.
final class ModulesGraphqlTest extends WP_UnitTestCase {

	// Le query di hello_standard (lib/data/queries, lib/fragments.ts), così come le invia.
	private const GET_POPUP = 'query hbPopup($lang: String = "it") {
		hbPopup(lang: $lang) {
			id title content
			link { url title target }
			image { node { ...MediaItemFragment } }
		}
	}
	fragment MediaItemFragment on MediaItem { altText title sourceUrl mediaDetails { width height } }';

	private const GET_BANNER = 'query hbBanner($lang: String = "it") {
		hbBanner(lang: $lang) { title link { url target } }
	}';

	private const GET_ORARI = 'query getOrari($lang: String) {
		hbOrari(lang: $lang) { ultimoAggiornamento displayName settimana { giorno slots chiuso } }
	}';

	// I campi che il prototipo non aveva.
	private const NEW_POPUP = '{ hbPopup { content days startsAt endsAt } }';

	private const NEW_BANNER = '{ hbBanner { startsAt endsAt } }';

	private const NEW_ORARI = 'query ($lang: String) {
		hbOrari(lang: $lang) {
			fonte
			settimana { fasce { apertura chiusura } }
			settimanaNormale { giorno slots fasce { apertura chiusura } chiuso }
			giorniSpeciali { dal al nota slots fasce { apertura chiusura } chiuso }
		}
	}';

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WPGraphQL' ) ) {
			$this->markTestSkipped( 'Needs WPGraphQL (WP_GRAPHQL=1).' );
		}
		update_option( 'timezone_string', 'Europe/Rome' );
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		\WPGraphQL::clear_schema();
	}

	public function tear_down(): void {
		if ( class_exists( 'WPGraphQL' ) ) {
			\WPGraphQL::clear_schema();
		}
		parent::tear_down();
	}

	// La fixture è lo schema del prototipo con WPGraphQL 2.x (wp-env, sito di sviluppo), stampato con
	// WpGraphqlTest::sdl(): ogni suo campo c'è ancora, nello stesso tipo e con lo stesso tipo. Gli altri si aggiungono.
	public function test_the_schema_keeps_every_field_of_the_prototype(): void {
		$lines     = static function ( string $sdl ): array {
			$type  = '';
			$lines = [];
			foreach ( explode( "\n", $sdl ) as $line ) {
				$type    = preg_match( '/^type (\w+)/', $line, $match ) ? $match[1] : ( '}' === $line ? '' : $type );
				$lines[] = "{$type} {$line}";
			}
			return $lines;
		};
		$prototype = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/wpgraphql-modules-schema.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$schema    = WpGraphqlTest::sdl( [ 'RootQuery' => [ 'hbBanner', 'hbOrari', 'hbPopup' ] ] );
		$this->assertSame( [], array_values( array_diff( $lines( $prototype ), $lines( $schema ) ) ) );
		$this->assertStringContainsString( "type HBPopup {\n  content: String\n  days: Int\n  endsAt: String\n", $schema, 'The new fields are there too.' );
	}

	public function test_without_the_modules_nothing_is_registered(): void {
		// barrio_<modulo>_loaded non parte con il modulo spento (ModulesTest). Tutti e tre prima del primo schema:
		// WPGraphQL ricorda i campi registrati, in graphql_register_types_late, fino alla fine del test.
		$fields = [
			'popups'  => 'hbPopup',
			'banners' => 'hbBanner',
			'hours'   => 'hbOrari',
		];
		foreach ( array_keys( $fields ) as $module ) {
			remove_action( 'graphql_register_types', "barrio_wpgraphql_register_{$module}", 9 );
		}
		$root = \WPGraphQL::get_schema()->getType( 'RootQuery' );
		$this->assertSame( [], array_filter( $fields, [ $root, 'hasField' ] ) );

		foreach ( array_keys( $fields ) as $module ) {
			do_action( "barrio_{$module}_loaded" );
		}
		\WPGraphQL::clear_schema();
		$root = \WPGraphQL::get_schema()->getType( 'RootQuery' );
		$this->assertSame( $fields, array_filter( $fields, [ $root, 'hasField' ] ) );
	}

	public function test_the_popup_is_the_one_of_the_site_in_the_requested_language(): void {
		$this->assertNull( $this->query( self::GET_POPUP )['hbPopup'] );

		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Una canola' );
		$older = $this->popup( 'Sempre', null );
		$id    = $this->popup( 'Newsletter', $image, null, '2030-01-31 23:00:00' );
		// Programmato: non è ancora quello del sito.
		$this->popup( 'Domani', null, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
		$this->popup( 'Scaduto', null, '2020-01-01 00:00:00', '2020-02-01 00:00:00' );

		$popup = $this->query( self::GET_POPUP )['hbPopup'];
		$this->assertSame( $id, $popup['id'] );
		$this->assertSame( 'Newsletter', $popup['title'] );
		// Gli shortcode li sa mostrare solo WordPress, tranne il form, che separa il frontend (senza il paragrafo).
		$this->assertSame( '<p>Iscriviti alla <strong>newsletter</strong>.</p>[barrio_form id="1"]', $popup['content'] );
		$this->assertSame(
			[
				'url'    => 'https://example.org/newsletter/',
				'title'  => 'Iscriviti',
				'target' => '_blank',
			],
			$popup['link']
		);
		$this->assertSame( 'Una canola', $popup['image']['node']['altText'] );
		$this->assertSame( wp_get_attachment_url( $image ), $popup['image']['node']['sourceUrl'] );
		$this->assertSame(
			[
				'content'  => $popup['content'],
				'days'     => 7,
				'startsAt' => null,
				'endsAt'   => '2030-01-31T23:00:00+00:00',
			],
			$this->query( self::NEW_POPUP )['hbPopup']
		);

		// Senza il modulo Form lo shortcode è vuoto (Plugin::boot()) e dal testo sparisce, come nel sito.
		$shortcode = $GLOBALS['shortcode_tags']['barrio_form'];
		add_shortcode( 'barrio_form', '__return_empty_string' );
		$content = $this->query( self::NEW_POPUP )['hbPopup']['content'];
		add_shortcode( 'barrio_form', $shortcode );
		$this->assertSame( '<p>Iscriviti alla <strong>newsletter</strong>.</p>', $content );

		// In inglese: il pulsante senza testo ha quello standard della lingua.
		$english = $this->query( self::GET_POPUP, [ 'lang' => 'en' ] )['hbPopup'];
		$this->assertSame( 'Newsletter (en)', $english['title'] );
		$this->assertSame( 'Learn more', $english['link']['title'] );
		// Una lingua non attiva è la principale.
		$this->assertSame( 'Newsletter', $this->query( self::GET_POPUP, [ 'lang' => 'fr' ] )['hbPopup']['title'] );

		// Un'immagine eliminata non c'è più; spento il più recente, torna quello di prima.
		wp_delete_attachment( $image, true );
		$this->assertNull( $this->query( self::GET_POPUP )['hbPopup']['image'] );
		PopupRepository::set_enabled( false, $id );
		$popup = $this->query( self::GET_POPUP )['hbPopup'];
		$this->assertSame( [ $older, null, null ], [ $popup['id'], $popup['link'], $popup['image'] ] );
	}

	public function test_the_banner_is_the_one_of_the_site_in_the_requested_language(): void {
		$this->assertNull( $this->query( self::GET_BANNER )['hbBanner'] );
		$banner = static fn( string $text, bool $new_tab, ?string $starts_at = null ): int|\WP_Error => BannerRepository::save(
			null,
			[
				'is_enabled' => true,
				'new_tab'    => $new_tab,
				'starts_at'  => $starts_at,
				'ends_at'    => null,
				'i18n'       => [
					'it' => [
						'text'     => $text,
						'link_url' => null,
					],
					'en' => [
						'text'     => "{$text} (en)",
						'link_url' => '/en/sale/',
					],
				],
			]
		);
		$banner( 'Spedizione gratuita', false );
		$banner( 'Saldi', true, '2026-01-10 08:30:00' );
		$off = $banner( 'Spento', false );
		$this->assertIsInt( $off );
		BannerRepository::set_enabled( false, $off );
		// Programmato: è il più recente, ma non è ancora quello del sito.
		$banner( 'Domani', false, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$this->assertSame(
			[
				'title' => 'Saldi',
				'link'  => null,
			],
			$this->query( self::GET_BANNER )['hbBanner']
		);
		$this->assertSame(
			[
				'title' => 'Saldi (en)',
				'link'  => [
					'url'    => '/en/sale/',
					'target' => '_blank',
				],
			],
			$this->query( self::GET_BANNER, [ 'lang' => 'en' ] )['hbBanner']
		);
		$this->assertSame(
			[
				'startsAt' => '2026-01-10T08:30:00+00:00',
				'endsAt'   => null,
			],
			$this->query( self::NEW_BANNER )['hbBanner']
		);
	}

	public function test_the_week_starts_on_monday_with_the_special_days_of_the_next_7_days(): void {
		$empty = [
			'ultimoAggiornamento' => null,
			'displayName'         => null,
			'settimana'           => [],
		];
		$this->assertSame( $empty, $this->query( self::GET_ORARI )['hbOrari'], 'Without hours, no week of closed days.' );
		$this->assertSame(
			[ [], [] ],
			array_values(
				array_intersect_key(
					$this->query( self::NEW_ORARI )['hbOrari'],
					[
						'settimanaNormale' => 0,
						'giorniSpeciali'   => 0,
					]
				)
			)
		);

		$slot     = static fn( string $open, string $close ): array => [
			'open'  => $open,
			'close' => $close,
		];
		$week     = array_fill( 1, 5, [ $slot( '09:00', '13:00' ), $slot( '15:30', '19:00' ) ] );
		$week[6]  = [ $slot( '20:00', '02:00' ) ];
		$week[7]  = [];
		$tomorrow = current_datetime()->modify( '+1 day' );
		$later    = current_datetime()->modify( '+8 days' );
		$special  = static fn( \DateTimeImmutable $date, array $hours ): array => [
			'from'  => $date->format( 'Y-m-d' ),
			'to'    => $date->format( 'Y-m-d' ),
			'hours' => $hours,
			'note'  => [ 'it' => 'Festa' ],
		];
		update_option(
			'barrio_hours',
			[
				'source'     => 'google',
				'place_id'   => 'ChIJ-trattoria',
				'week'       => $week,
				// Domani chiuso; fra 8 giorni è fuori dalla settimana.
				'exceptions' => [ $special( $tomorrow, [] ), $special( $later, [ $slot( '00:00', '00:00' ) ] ) ],
				'google'     => [
					'synced_at' => strtotime( '2026-03-02 02:05:00 UTC' ),
					'name'      => 'Trattoria Mario',
					'error'     => '',
				],
			]
		);

		$hours = $this->query( self::GET_ORARI, [ 'lang' => 'it' ] )['hbOrari'];
		$this->assertSame( '02/03/2026 03:05', $hours['ultimoAggiornamento'], 'In the site timezone.' );
		$this->assertSame( 'Trattoria Mario', $hours['displayName'] );
		$this->assertSame( [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ], array_column( $hours['settimana'], 'giorno' ) );
		$expected                                   = [
			1 => [ '09:00 - 13:00', '15:30 - 19:00' ],
			2 => [ '09:00 - 13:00', '15:30 - 19:00' ],
			3 => [ '09:00 - 13:00', '15:30 - 19:00' ],
			4 => [ '09:00 - 13:00', '15:30 - 19:00' ],
			5 => [ '09:00 - 13:00', '15:30 - 19:00' ],
			6 => [ '20:00 - 02:00' ],
			7 => [],
		];
		$expected[ (int) $tomorrow->format( 'N' ) ] = [];
		$this->assertSame( array_values( $expected ), array_column( $hours['settimana'], 'slots' ) );
		$this->assertSame( array_map( static fn( array $slots ): bool => ! $slots, array_values( $expected ) ), array_column( $hours['settimana'], 'chiuso' ) );

		// In inglese le ore sono di 12: un giorno feriale che non sia domani, e il sabato.
		$english = $this->query( self::GET_ORARI, [ 'lang' => 'en' ] )['hbOrari']['settimana'];
		$weekday = 1 === (int) $tomorrow->format( 'N' ) ? 2 : 1;
		$this->assertSame( [ '9:00 AM - 1:00 PM', '3:30 PM - 7:00 PM' ], $english[ $weekday - 1 ]['slots'] );
		if ( 6 !== (int) $tomorrow->format( 'N' ) ) {
			$this->assertSame( [ '8:00 PM - 2:00 AM' ], $english[5]['slots'] );
		}

		// I campi nuovi: le ore da leggere, la settimana senza i giorni speciali, i giorni speciali con le date e le note.
		$hours  = $this->query( self::NEW_ORARI, [ 'lang' => 'en' ] )['hbOrari'];
		$fasce  = static fn( array $slots ): array => array_map(
			static fn( array $slot ): array => [
				'apertura' => $slot['open'],
				'chiusura' => $slot['close'],
			],
			$slots
		);
		$normal = array_column( $hours['settimanaNormale'], 'fasce' );
		$this->assertSame( 'google', $hours['fonte'] );
		$this->assertSame( array_values( array_map( $fasce, $week ) ), $normal, 'Tomorrow too, with its usual hours.' );
		$this->assertSame( [ '8:00 PM - 2:00 AM' ], $hours['settimanaNormale'][5]['slots'] );
		$this->assertSame( [], $hours['settimana'][ (int) $tomorrow->format( 'N' ) - 1 ]['fasce'] );
		$this->assertSame(
			[
				[
					'dal'    => $tomorrow->format( 'Y-m-d' ),
					'al'     => $tomorrow->format( 'Y-m-d' ),
					'nota'   => 'Festa',
					'slots'  => [],
					'fasce'  => [],
					'chiuso' => true,
				],
				[
					'dal'    => $later->format( 'Y-m-d' ),
					'al'     => $later->format( 'Y-m-d' ),
					'nota'   => 'Festa',
					'slots'  => [ '12:00 AM - 12:00 AM' ],
					'fasce'  => $fasce( [ $slot( '00:00', '00:00' ) ] ),
					'chiuso' => false,
				],
			],
			$hours['giorniSpeciali'],
			'The note of the main language when English has none.'
		);

		// Scritti a mano: nessun aggiornamento da Google.
		update_option( 'barrio_hours', [ 'source' => 'manual' ] + get_option( 'barrio_hours' ) );
		$this->assertNull( $this->query( self::GET_ORARI )['hbOrari']['ultimoAggiornamento'] );
		$this->assertSame( 'manual', $this->query( self::NEW_ORARI )['hbOrari']['fonte'] );
	}

	public function test_the_days_are_in_the_requested_language_with_a_capital_letter(): void {
		update_option(
			'barrio_hours',
			[
				'week' => array_fill(
					1,
					7,
					[
						[
							'open'  => '00:00',
							'close' => '00:00',
						],
					]
				),
			]
		);
		// WordPress passa solo ai locale installati: qui si finge che ci sia l'italiano, con i giorni minuscoli.
		$switcher = $GLOBALS['wp_locale_switcher'];
		add_filter( 'get_available_languages', static fn(): array => [ 'it_IT' ] );
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- globale di WordPress.
		$GLOBALS['wp_locale_switcher']->init();
		add_filter( 'barrio_locale', static fn( string $locale, string $lang ): string => 'it' === $lang ? 'it_IT' : 'en_US', 10, 2 );
		add_filter( 'gettext', static fn( string $translation, string $text, string $domain ): string => 'it_IT' === get_locale() && 'default' === $domain && 'Monday' === $text ? 'lunedì' : $translation, 10, 3 );
		try {
			$monday = $this->query( self::GET_ORARI, [ 'lang' => 'it' ] )['hbOrari']['settimana'][0];
			$this->assertSame(
				[
					'giorno' => 'Lunedì',
					'slots'  => [ '00:00 - 00:00' ],
					'chiuso' => false,
				],
				$monday
			);
			$this->assertSame( 'Monday', $this->query( self::GET_ORARI, [ 'lang' => 'en' ] )['hbOrari']['settimana'][0]['giorno'] );
			$this->assertSame( 'en_US', get_locale() );
		} finally {
			$GLOBALS['wp_locale_switcher'] = $switcher; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- globale di WordPress.
		}
	}

	/**
	 * @param array<string, mixed> $variables
	 * @return array<string, mixed>
	 */
	private function query( string $query, array $variables = [] ): array {
		$response = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);
		$this->assertArrayNotHasKey( 'errors', $response );
		return $response['data'];
	}

	private function popup( string $title, ?int $image, ?string $starts_at = null, ?string $ends_at = null ): int {
		$id = PopupRepository::save(
			null,
			[
				'is_enabled' => true,
				'starts_at'  => $starts_at,
				'ends_at'    => $ends_at,
				'image_id'   => $image,
				'settings'   => [
					'new_tab' => true,
					'days'    => 7,
				],
				'i18n'       => [
					'it' => [
						'title'        => $title,
						'content'      => '<p>Iscriviti alla <strong>newsletter</strong>.</p><p>[barrio_form id="1"]</p>[gallery ids="1"]',
						'button_label' => $image ? 'Iscriviti' : null,
						'link_url'     => $image ? 'https://example.org/newsletter/' : null,
					],
					'en' => [
						'title'        => "{$title} (en)",
						'content'      => null,
						'button_label' => null,
						'link_url'     => $image ? '/en/newsletter/' : null,
					],
				],
			]
		);
		$this->assertIsInt( $id );
		return $id;
	}
}
