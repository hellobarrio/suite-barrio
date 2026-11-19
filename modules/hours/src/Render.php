<?php

namespace BarrioSuite\Hours;

use BarrioSuite\I18n;
use DateTimeImmutable;

/**
 * Gli orari nel sito: il blocco barrio/hours e lo shortcode [barrio_hours], con lo stesso HTML, in tre forme: la
 * settimana, oggi e lo stato ("Aperto · chiude alle 19:00"). Nomi dei giorni e ore nella lingua della pagina
 * (WP_Locale, time_format). Oggi e lo stato dipendono dall'ora: il server li scrive per la sua, e lo script
 * (assets/frontend/hours.js) li riscrive nel browser con i dati dei prossimi giorni (window()), così restano giusti con
 * la cache di pagina.
 *
 * @phpstan-import-type Settings from Module
 * @phpstan-import-type Slot from Module
 * @phpstan-import-type Special from Module
 * @phpstan-type Window array{days: list<array{at: int, name: string, today: string}>, open: list<array{0: int, 1: int, 2: string, 3: string}>, text: array<string, string>}
 */
final class Render {

	// I giorni che lo script conosce. ponytail: una pagina in cache da più giorni resta con il testo del server.
	private const DAYS = 15;

	public static function register(): void {
		wp_register_style( 'barrio-hours', plugins_url( 'assets/frontend/hours.css', __DIR__ ), [], BARRIO_SUITE_VERSION );
		wp_register_script_module( '@barrio/hours', plugins_url( 'assets/frontend/hours.js', __DIR__ ), [], BARRIO_SUITE_VERSION );
		add_shortcode( 'barrio_hours', static fn( mixed $atts ): string => self::output( (array) $atts ) );
		$block = register_block_type( dirname( __DIR__ ) . '/assets/block', [ 'render_callback' => [ self::class, 'output' ] ] );
		foreach ( $block ? $block->editor_script_handles : [] as $handle ) {
			wp_set_script_translations( $handle, 'barrio-suite', dirname( __DIR__, 3 ) . '/languages' );
		}
	}

	/** @param array<mixed> $atts Del blocco o dello shortcode: show (week, today o status) e group. */
	public static function output( array $atts ): string {
		$html = self::html( Module::settings(), (string) ( $atts['show'] ?? 'week' ), filter_var( $atts['group'] ?? false, FILTER_VALIDATE_BOOLEAN ) );
		// Gli asset solo nelle pagine con gli orari.
		if ( '' !== $html ) {
			wp_enqueue_style( 'barrio-hours' );
			wp_enqueue_script_module( '@barrio/hours' );
		}
		return $html;
	}

	/**
	 * Senza orari niente: un blocco vuoto non dice "chiuso" tutti i giorni.
	 *
	 * @param Settings $settings
	 * @param string   $show     week, today o status.
	 * @param bool     $group    Nella settimana, i giorni di seguito con le stesse fasce in una riga.
	 */
	public static function html( array $settings, string $show, bool $group = false ): string {
		if ( ! array_filter( $settings['week'] ) && ! $settings['exceptions'] ) {
			return '';
		}
		$today = current_datetime()->setTime( 0, 0 );
		if ( ! in_array( $show, [ 'today', 'status' ], true ) ) {
			return self::week( $settings, $group );
		}
		$data = self::window( $settings, $today );
		if ( 'today' === $show ) {
			return sprintf( '<p class="barrio-hours is-today" data-hours="%s">%s</p>', esc_attr( (string) wp_json_encode( [ 'days' => $data['days'] ] ) ), esc_html( $data['days'][0]['today'] ) );
		}
		$status = self::status( $data, time() ) ?? [
			'open' => false,
			'text' => '',
		];
		// Lo stile è sullo span: il paragrafo resta largo come il contenuto, e i temi non lo centrano.
		return sprintf( '<p class="barrio-hours is-status %s" data-hours="%s"><span>%s</span></p>', $status['open'] ? 'is-open' : 'is-closed', esc_attr( (string) wp_json_encode( $data ) ), esc_html( $status['text'] ) );
	}

	/**
	 * I dati dello stato e di oggi per i prossimi giorni: l'inizio di ogni giorno (l'ultimo segna la fine), il nome e il
	 * testo di oggi; i periodi aperti uniti, dalla fascia di ieri che passa la mezzanotte, con le ore già scritte; i
	 * testi dello stato.
	 *
	 * @param Settings $settings
	 * @return Window
	 */
	public static function window( array $settings, DateTimeImmutable $today ): array {
		global $wp_locale;
		$days = [];
		$open = [];
		for ( $i = -1; $i <= self::DAYS; $i++ ) {
			$date = $today->modify( "{$i} days" );
			if ( $i >= 0 ) {
				$days[] = [
					'at'    => $date->getTimestamp(),
					'name'  => $wp_locale->get_weekday( (int) $date->format( 'w' ) ),
					/* translators: %s: today's opening hours, like 9:00–13:00, or Closed. */
					'today' => sprintf( __( 'Today: %s', 'barrio-suite' ), self::day_text( $settings, $date ) ),
				];
			}
			foreach ( $i < self::DAYS ? self::slots( $settings, $date )[0] : [] as $slot ) {
				$start  = self::at( $date, $slot['open'] );
				$end    = self::at( $date, $slot['close'] );
				$open[] = [ $start, $end > $start ? $end : self::at( $date->modify( '+1 day' ), $slot['close'] ) ];
			}
		}
		sort( $open );
		$merged = [];
		foreach ( $open as [ $start, $end ] ) {
			$last = array_key_last( $merged );
			if ( null !== $last && $start <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $end );
			} else {
				$merged[] = [ $start, $end ];
			}
		}
		$time = (string) get_option( 'time_format' );
		return [
			'days' => $days,
			'open' => array_map( static fn( array $period ): array => [ $period[0], $period[1], (string) wp_date( $time, $period[0] ), (string) wp_date( $time, $period[1] ) ], $merged ),
			'text' => [
				'always'        => __( 'Open 24 hours', 'barrio-suite' ),
				/* translators: %s: closing time. */
				'closes'        => __( 'Open · closes at %s', 'barrio-suite' ),
				/* translators: 1: day of the week; 2: closing time. */
				'closesOn'      => __( 'Open · closes %1$s at %2$s', 'barrio-suite' ),
				'closed'        => __( 'Closed', 'barrio-suite' ),
				/* translators: %s: opening time. */
				'opens'         => __( 'Closed · opens at %s', 'barrio-suite' ),
				/* translators: %s: opening time. */
				'opensTomorrow' => __( 'Closed · opens tomorrow at %s', 'barrio-suite' ),
				/* translators: 1: day of the week; 2: opening time. */
				'opensOn'       => __( 'Closed · opens %1$s at %2$s', 'barrio-suite' ),
			],
		];
	}

	/**
	 * Aperto o chiuso a $now, con il testo: lo stesso calcolo di status() in assets/frontend/hours.js, sugli stessi dati
	 * (tests/fixtures/hours-status.json). Null fuori dai giorni dei dati.
	 *
	 * @param array{days: list<array{at: int, name: string}>, open: list<array{0: int, 1: int, 2: string, 3: string}>, text: array<string, string>} $data Da window().
	 * @return array{open: bool, text: string}|null
	 */
	public static function status( array $data, int $now ): ?array {
		[ 'days' => $days, 'text' => $text ] = $data;
		$day                                 = static function ( int $time ) use ( $days ): int {
			$found = -1;
			foreach ( $days as $index => $item ) {
				$found = $item['at'] <= $time ? $index : $found;
			}
			return $found;
		};
		$today                               = $day( $now );
		$last                                = count( $days ) - 1;
		if ( $today < 0 || $today >= $last ) {
			return null;
		}
		foreach ( $data['open'] as [ $start, $end, $opens, $closes ] ) {
			if ( $start <= $now && $now < $end ) {
				$on      = $day( $end );
				$message = sprintf( $text['closesOn'], $days[ $on ]['name'], $closes );
				if ( $start <= $days[0]['at'] && $end >= $days[ $last ]['at'] ) {
					$message = $text['always'];
				} elseif ( $on <= $today + 1 ) {
					$message = sprintf( $text['closes'], $closes );
				}
				return [
					'open' => true,
					'text' => $message,
				];
			}
			if ( $start > $now ) {
				$on = $day( $start );
				return [
					'open' => false,
					'text' => match ( $on ) {
						$today     => sprintf( $text['opens'], $opens ),
						$today + 1 => sprintf( $text['opensTomorrow'], $opens ),
						default    => sprintf( $text['opensOn'], $days[ $on ]['name'], $opens ),
					},
				];
			}
		}
		return [
			'open' => false,
			'text' => $text['closed'],
		];
	}

	/**
	 * Le fasce di un giorno, quelle del giorno speciale che lo comprende o della settimana, e il giorno speciale.
	 *
	 * @param Settings $settings
	 * @return array{0: list<Slot>, 1: Special|null}
	 */
	public static function slots( array $settings, DateTimeImmutable $date ): array {
		$ymd = $date->format( 'Y-m-d' );
		foreach ( $settings['exceptions'] as $special ) {
			if ( $special['from'] <= $ymd && $ymd <= $special['to'] ) {
				return [ $special['hours'], $special ];
			}
		}
		return [ $settings['week'][ (int) $date->format( 'N' ) ], null ];
	}

	/**
	 * Gli orari di un giorno: "9:00–13:00, 15:00–19:00", Chiuso o Aperto 24 ore, con la nota di un giorno speciale.
	 *
	 * @param Settings $settings
	 */
	public static function day_text( array $settings, DateTimeImmutable $date ): string {
		[ $slots, $special ] = self::slots( $settings, $date );
		return $special ? self::special_text( $special ) : self::slots_text( $slots );
	}

	/**
	 * Le date di un giorno speciale: una, o la prima e l'ultima. Nell'anno in corso in $short, senza l'anno; negli altri
	 * nel formato del sito.
	 *
	 * @param Special $special
	 */
	public static function dates( array $special, string $short ): string {
		$year   = current_datetime()->format( 'Y' );
		$format = static fn( string $date ): string => (string) mysql2date( str_starts_with( $date, $year ) ? $short : (string) get_option( 'date_format' ), $date );
		return $special['from'] === $special['to'] ? $format( $special['from'] ) : $format( $special['from'] ) . ' – ' . $format( $special['to'] );
	}

	/**
	 * Una riga per giorno dal primo della settimana del sito, o i giorni di seguito uguali in una ("Lun–Ven"), e sotto i
	 * giorni speciali in una tabella uguale: lo script toglie quelli passati. Ogni fascia è uno span, che va a capo
	 * intero.
	 *
	 * @param Settings $settings
	 */
	private static function week( array $settings, bool $group ): string {
		global $wp_locale;
		$rows  = [];
		$first = (int) get_option( 'start_of_week', 1 );
		for ( $i = 0; $i < 7; $i++ ) {
			// Come WP_Locale: 0 = domenica.
			$day  = ( $first + $i ) % 7;
			$name = $wp_locale->get_weekday( $day );
			$name = $group ? $wp_locale->get_weekday_abbrev( $name ) : $name;
			$text = self::spans( self::ranges( $settings['week'][ $day ?: 7 ] ) );
			$last = array_key_last( $rows );
			if ( $group && null !== $last && $rows[ $last ][2] === $text ) {
				$rows[ $last ][1] = $name;
			} else {
				$rows[] = [ $name, '', $text ];
			}
		}
		$html = '';
		foreach ( $rows as [ $from, $to, $text ] ) {
			$html .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( '' === $to ? $from : "{$from}–{$to}" ), $text );
		}
		$html     = '<table class="barrio-hours__week"><tbody>' . $html . '</tbody></table>';
		$specials = '';
		foreach ( $settings['exceptions'] as $special ) {
			$until     = ( new DateTimeImmutable( $special['to'], wp_timezone() ) )->modify( '+1 day' )->getTimestamp();
			$note      = self::note( $special );
			$specials .= sprintf(
				'<tr data-until="%d"><th scope="row">%s</th><td>%s%s</td></tr>',
				$until,
				esc_html( self::dates( $special, self::short_date() ) ),
				self::spans( self::ranges( $special['hours'] ) ),
				'' === $note ? '' : '<span class="barrio-hours__note">' . esc_html( $note ) . '</span>'
			);
		}
		if ( '' !== $specials ) {
			$html .= sprintf( '<table class="barrio-hours__week barrio-hours__special"><caption>%s</caption><tbody>%s</tbody></table>', esc_html__( 'Special opening hours', 'barrio-suite' ), $specials );
		}
		return '<div class="barrio-hours is-week">' . $html . '</div>';
	}

	// La data breve dei giorni speciali di quest'anno, nel sito e nella Bacheca: sta in una riga anche su un telefono.
	public static function short_date(): string {
		/* translators: short date format without the year, for the special days of this year: see https://www.php.net/manual/datetime.format.php */
		return __( 'M j', 'barrio-suite' );
	}

	/** @param list<string> $texts */
	private static function spans( array $texts ): string {
		return implode( '', array_map( static fn( string $text ): string => '<span>' . esc_html( $text ) . '</span>', $texts ) );
	}

	/**
	 * La nota di un giorno speciale nella lingua della pagina, o in quella principale.
	 *
	 * @param Special $special
	 */
	private static function note( array $special ): string {
		$note = $special['note'];
		return (string) ( ( $note[ I18n::current() ] ?? '' ) ?: ( $note[ I18n::languages()[0] ] ?? '' ) );
	}

	/** @param Special $special */
	private static function special_text( array $special ): string {
		$note = self::note( $special );
		return self::slots_text( $special['hours'] ) . ( '' === $note ? '' : " · {$note}" );
	}

	/** @param list<Slot> $slots */
	private static function slots_text( array $slots ): string {
		return implode( ', ', self::ranges( $slots ) );
	}

	/**
	 * Le fasce scritte con il formato dell'ora del sito ("9:00–13:00"), o Chiuso, o Aperto 24 ore.
	 *
	 * @param list<Slot> $slots
	 * @return list<string>
	 */
	private static function ranges( array $slots ): array {
		if ( ! $slots ) {
			return [ __( 'Closed', 'barrio-suite' ) ];
		}
		if ( [ Module::ALL_DAY ] === $slots ) {
			return [ __( 'Open 24 hours', 'barrio-suite' ) ];
		}
		$time   = (string) get_option( 'time_format' );
		$format = static fn( string $hm ): string => (string) mysql2date( $time, "2000-01-03 {$hm}:00" );
		return array_map( static fn( array $slot ): string => $format( $slot['open'] ) . '–' . $format( $slot['close'] ), $slots );
	}

	// Il momento di un'ora (HH:MM) di un giorno, nel fuso del sito.
	private static function at( DateTimeImmutable $date, string $time ): int {
		return $date->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ) )->getTimestamp();
	}
}
