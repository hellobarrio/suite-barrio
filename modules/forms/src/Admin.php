<?php

namespace BarrioSuite\Forms;

/**
 * La pagina Form nel menu di Barrio (src/Admin.php), con le schede Form (elenco e builder),
 * Registrazioni, che basta agli editor, e Impostazioni, e i dati che le servono nel JavaScript.
 */
final class Admin {

	// Si apre con una delle due capability, che i ruoli hanno anche separate (Form → Impostazioni →
	// Permessi): ognuno ci trova solo ciò che può fare (assets/src/forms/Forms.js).
	public static function menu(): void {
		\BarrioSuite\Admin::add_page( 'barrio-forms', __( 'Forms', 'barrio-suite' ), current_user_can( 'barrio_manage_entries' ) ? 'barrio_manage_entries' : 'barrio_manage_forms' );
	}

	/**
	 * Scelte pronte per select, radio e checkbox. Un'estensione ne aggiunge con il filtro
	 * barrio_forms_presets: 'id' => [ 'label' => 'Nome', 'choices' => [ [ 'valore', 'Etichetta' ], … ] ].
	 * Coppie e non { valore: etichetta }: in JavaScript le chiavi numeriche cambierebbero ordine.
	 * Con intl (region, language, weekday, month) l'etichetta è vuota: la scrive il builder con Intl.
	 *
	 * @return array<string, array{label: string, choices: list<array{string, string}>, intl?: string}>
	 */
	public static function presets(): array {
		$data  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/presets.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file del plugin.
		$pairs = static fn( array $labels ): array => array_map( null, array_keys( $labels ), array_values( $labels ) );
		$intl  = static fn( array $values ): array => array_map( static fn( string $value ): array => [ $value, '' ], $values );
		return apply_filters(
			'barrio_forms_presets',
			[
				'countries'      => [
					'label'   => __( 'Countries', 'barrio-suite' ),
					'intl'    => 'region',
					'choices' => $intl( $data['countries'] ),
				],
				'regions_it'     => [
					'label'   => __( 'Italian regions', 'barrio-suite' ),
					'choices' => $data['regions_it'],
				],
				'provinces_it'   => [
					'label'   => __( 'Italian provinces', 'barrio-suite' ),
					'choices' => $data['provinces_it'],
				],
				'us_states'      => [
					'label'   => __( 'US states', 'barrio-suite' ),
					'choices' => $data['us_states'],
				],
				'continents'     => [
					'label'   => __( 'Continents', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'africa'        => __( 'Africa', 'barrio-suite' ),
							'antarctica'    => __( 'Antarctica', 'barrio-suite' ),
							'asia'          => __( 'Asia', 'barrio-suite' ),
							'europe'        => __( 'Europe', 'barrio-suite' ),
							'north_america' => __( 'North America', 'barrio-suite' ),
							'oceania'       => __( 'Oceania', 'barrio-suite' ),
							'south_america' => __( 'South America', 'barrio-suite' ),
						]
					),
				],
				'days'           => [
					'label'   => __( 'Days of the week', 'barrio-suite' ),
					'intl'    => 'weekday',
					'choices' => $intl( [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ] ),
				],
				'months'         => [
					'label'   => __( 'Months', 'barrio-suite' ),
					'intl'    => 'month',
					'choices' => $intl( [ 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december' ] ),
				],
				'languages'      => [
					'label'   => __( 'Languages', 'barrio-suite' ),
					'intl'    => 'language',
					'choices' => $intl( $data['languages'] ),
				],
				// I titoli di studio italiani.
				'education'      => [
					'label'   => __( 'Education', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'primary'             => __( 'Primary school certificate', 'barrio-suite' ),
							'lower_secondary'     => __( 'Lower secondary school certificate', 'barrio-suite' ),
							'upper_secondary'     => __( 'Upper secondary school diploma', 'barrio-suite' ),
							'bachelor'            => __( 'Bachelor\'s degree', 'barrio-suite' ),
							'master'              => __( 'Master\'s degree', 'barrio-suite' ),
							'first_level_master'  => __( 'First-level master', 'barrio-suite' ),
							'second_level_master' => __( 'Second-level master', 'barrio-suite' ),
							'phd'                 => __( 'PhD', 'barrio-suite' ),
						]
					),
				],
				'marital_status' => [
					'label'   => __( 'Marital status', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'single'     => __( 'Single', 'barrio-suite' ),
							'married'    => __( 'Married', 'barrio-suite' ),
							'separated'  => __( 'Separated', 'barrio-suite' ),
							'divorced'   => __( 'Divorced', 'barrio-suite' ),
							'widowed'    => __( 'Widowed', 'barrio-suite' ),
							'cohabiting' => __( 'Cohabiting', 'barrio-suite' ),
						]
					),
				],
				'gender'         => [
					'label'   => __( 'Gender', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'man'               => __( 'Man', 'barrio-suite' ),
							'woman'             => __( 'Woman', 'barrio-suite' ),
							'other'             => __( 'Other', 'barrio-suite' ),
							'prefer_not_to_say' => __( 'Prefer not to say', 'barrio-suite' ),
						]
					),
				],
			]
		);
	}

	/**
	 * Su barrio_admin_data: ciò che serve alle pagine dei form, in window.barrioAdmin.forms. Senza, il
	 * JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function data( array $data ): array {
		$types = [];
		foreach ( FieldTypes::labels() as $type => $label ) {
			$types[ $type ] = [
				'label'  => $label,
				'schema' => FieldTypes::schema( $type ),
			];
		}
		return $data + [
			'forms' => [
				'types'            => $types,
				'presets'          => self::presets(),
				// Un oggetto anche se vuoto: [] in JSON sarebbe una lista.
				'integrations'     => (object) Integrations::definitions(),
				'canManageForms'   => current_user_can( 'barrio_manage_forms' ),
				'canManageEntries' => current_user_can( 'barrio_manage_entries' ),
			],
		];
	}
}
