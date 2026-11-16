<?php

namespace BarrioSuite;

/**
 * Cosa può fare ogni ruolo in un modulo: le capability del modulo assegnate ai ruoli di WordPress,
 * scelte nelle impostazioni del modulo (Form → Impostazioni → Permessi). Le capability restano nei
 * ruoli, come quelle di WordPress, quindi le vedono anche gli altri plugin dei ruoli. Gli
 * amministratori le hanno sempre, così nessuno si chiude fuori.
 */
final class Roles {

	/**
	 * @param list<string> $caps
	 * @return array{roles: array<string, list<string>>, role_names: array<string, string>} Per ruolo
	 *         le capability che ha tra $caps, amministratori esclusi; i nomi tradotti di tutti i ruoli.
	 */
	public static function get( array $caps ): array {
		$roles = [];
		$names = [];
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			$names[ $slug ] = translate_user_role( wp_roles()->role_names[ $slug ] ?? $slug );
			if ( 'administrator' !== $slug ) {
				$roles[ $slug ] = array_values( array_filter( $caps, [ $role, 'has_cap' ] ) );
			}
		}
		return [
			'roles'      => $roles,
			'role_names' => $names,
		];
	}

	/**
	 * Solo i ruoli presenti in $roles e solo le capability di $caps: le altre restano come sono.
	 *
	 * @param list<string>                $caps
	 * @param array<string, list<string>> $roles
	 */
	public static function save( array $caps, array $roles ): void {
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( 'administrator' === $slug || ! isset( $roles[ $slug ] ) ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$want = in_array( $cap, $roles[ $slug ], true );
				if ( $want !== $role->has_cap( $cap ) ) {
					$want ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * @param list<string> $caps
	 * @return array<string, mixed>
	 */
	public static function schema( array $caps ): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => [
				'type'        => 'array',
				'items'       => [
					'type' => 'string',
					'enum' => $caps,
				],
				'uniqueItems' => true,
			],
		];
	}
}
