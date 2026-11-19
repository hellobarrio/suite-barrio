<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Seo\RedirectCode;
use WP_UnitTestCase;

// Ogni vincolo dello schema ha qui almeno una scrittura che il database deve rifiutare.
// Le righe si scrivono in SQL, senza Repository: si verifica il database da solo.
// Nel SQL {tabella} diventa il nome completo, {nome} l'id creato in set_up, {p} il prefisso.
final class SchemaTest extends WP_UnitTestCase {

	private const TABLES = [ 'forms', 'form_i18n', 'form_notifications', 'form_notifications_i18n', 'form_integrations', 'form_integrations_i18n', 'form_versions', 'form_fields', 'form_fields_i18n', 'entries', 'entry_values', 'entry_files', 'entry_notes', 'redirects', 'banners', 'banners_i18n' ];

	/** @var array<string, int> */
	private array $id = [];

	public function set_up(): void {
		parent::set_up();
		$this->id['form']           = $this->insert( 'forms', [ 'title' => 'Contact' ] );
		$this->id['other_form']     = $this->insert( 'forms', [ 'title' => 'Other' ] );
		$this->id['notification']   = $this->settings( $this->id['form'] );
		$this->id['integration']    = $this->integration( $this->id['form'] );
		$this->id['other_integ']    = $this->integration( $this->id['other_form'] );
		$this->id['other_notif']    = $this->settings( $this->id['other_form'] );
		$this->id['version']        = $this->version( $this->id['form'], 1 );
		$this->id['unused_version'] = $this->version( $this->id['form'], 2 );
		$this->id['bare_version']   = $this->version( $this->id['form'], 3 );
		$this->id['other_version']  = $this->version( $this->id['other_form'], 1 );
		$this->id['email']          = $this->field( $this->id['version'], 'email', 0 );
		$this->id['cv']             = $this->field( $this->id['version'], 'cv', 1 );
		$this->id['unused_field']   = $this->field( $this->id['unused_version'], 'email', 0 );
		$this->field( $this->id['other_version'], 'name', 0 );

		$this->id['entry']          = $this->entry( $this->id['version'] );
		$this->id['entry_no_files'] = $this->entry( $this->id['version'] );
		$this->id['bare_entry']     = $this->entry( $this->id['bare_version'], false );
		$this->insert(
			'entry_files',
			[
				'entry_id'      => $this->id['entry'],
				'field_id'      => $this->id['cv'],
				'stored_path'   => 'a1b2c3',
				'original_name' => 'cv.pdf',
				'mime_type'     => 'application/pdf',
				'size_bytes'    => 1024,
			]
		);
		$this->id['redirect'] = $this->insert(
			'redirects',
			[
				'source' => '/old',
				'target' => '/new',
				'code'   => 301,
			]
		);
		$this->id['banner']   = $this->insert(
			'banners',
			[
				'is_enabled' => 1,
				'settings'   => '{}',
			]
		);
		$this->insert(
			'banners_i18n',
			[
				'banner_id' => $this->id['banner'],
				'lang'      => 'it',
				'text'      => 'Saldi',
			]
		);
	}

	public function test_all_tables_exist_as_innodb(): void {
		global $wpdb;
		$engines = $wpdb->get_results(
			$wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'barrio_' ) . '%' ),
			OBJECT_K
		);

		$this->assertEqualsCanonicalizing( array_map( [ Db::class, 'table' ], self::TABLES ), array_keys( $engines ) );
		$this->assertSame( [ 'InnoDB' ], array_values( array_unique( array_column( $engines, 'ENGINE' ) ) ) );
	}

	/** @return array<string, array{string, string}> */
	public static function rejected_writes(): array {
		$file = "INSERT INTO {entry_files} (entry_id, field_id, stored_path, original_name, mime_type, size_bytes, created_at) VALUES (%s, %s, '%s', 'cv.pdf', 'application/pdf', 1, '2026-01-01')";
		$fk   = 'a foreign key constraint fails';

		return [
			// CHECK.
			'form status outside the enum'           => [ "UPDATE {forms} SET status = 'Active' WHERE id = {form}", '{p}bf_status_chk' ],
			'form text lang uppercase'               => [ "UPDATE {form_i18n} SET lang = 'IT' WHERE form_id = {form}", '{p}bfi_lang_chk' ],
			'success url without a scheme'           => [ "UPDATE {form_i18n} SET success_url = 'example.com/grazie' WHERE form_id = {form}", '{p}bfi_url_chk' ],
			'success url with javascript'            => [ "UPDATE {form_i18n} SET success_url = 'javascript:alert(1)' WHERE form_id = {form}", '{p}bfi_url_chk' ],
			'is_enabled not 0 or 1'                  => [ 'UPDATE {form_notifications} SET is_enabled = 2 WHERE id = {notification}', '{p}bfn_enabled_chk' ],
			'attach_files not 0 or 1'                => [ 'UPDATE {form_notifications} SET attach_files = 2 WHERE id = {notification}', '{p}bfn_attach_chk' ],
			'notification conditions a list'         => [ "UPDATE {form_notifications} SET conditions = '[]' WHERE id = {notification}", '{p}bfn_cond_chk' ],
			'notification text lang a locale'        => [ "UPDATE {form_notifications_i18n} SET lang = 'en_US' WHERE notification_id = {notification}", '{p}bfni_lang_chk' ],
			'integration slug with uppercase'        => [ "UPDATE {form_integrations} SET slug = 'Mailchimp' WHERE id = {integration}", '{p}bfg_slug_chk' ],
			'integration is_enabled not 0 or 1'      => [ 'UPDATE {form_integrations} SET is_enabled = 2 WHERE id = {integration}', '{p}bfg_enabled_chk' ],
			'integration settings a list'            => [ "UPDATE {form_integrations} SET settings = '[]' WHERE id = {integration}", '{p}bfg_settings_chk' ],
			'integration conditions a string'        => [ "UPDATE {form_integrations} SET conditions = '\"x\"' WHERE id = {integration}", '{p}bfg_cond_chk' ],
			'integration text lang uppercase'        => [ "UPDATE {form_integrations_i18n} SET lang = 'EN' WHERE integration_id = {integration}", '{p}bfgi_lang_chk' ],
			'integration texts a list'               => [ "UPDATE {form_integrations_i18n} SET settings = '[]' WHERE integration_id = {integration}", '{p}bfgi_json_chk' ],
			'field key with uppercase'               => [ "UPDATE {form_fields} SET field_key = 'Email' WHERE id = {email}", '{p}bff_key_chk' ],
			'field key starting with a digit'        => [ "UPDATE {form_fields} SET field_key = '2email' WHERE id = {email}", '{p}bff_key_chk' ],
			'is_required not 0 or 1'                 => [ 'UPDATE {form_fields} SET is_required = 2 WHERE id = {email}', '{p}bff_required_chk' ],
			'field config not an object'             => [ "UPDATE {form_fields} SET config = '[]' WHERE id = {email}", '{p}bff_config_chk' ],
			'field conditions a string'              => [ "UPDATE {form_fields} SET conditions = '\"x\"' WHERE id = {email}", '{p}bff_cond_chk' ],
			'field text lang uppercase'              => [ "UPDATE {form_fields_i18n} SET lang = 'PT-BR' WHERE field_id = {email}", '{p}bffi_lang_chk' ],
			'field options a list'                   => [ "UPDATE {form_fields_i18n} SET options = '[\"a\"]' WHERE field_id = {email}", '{p}bffi_options_chk' ],
			'is_read not 0 or 1'                     => [ 'UPDATE {entries} SET is_read = 2 WHERE id = {entry}', '{p}be_read_chk' ],
			'entry lang as a word'                   => [ "UPDATE {entries} SET lang = 'italian' WHERE id = {entry}", '{p}be_lang_chk' ],
			'entry without lang'                     => [ 'UPDATE {entries} SET lang = NULL WHERE id = {entry}', '{p}be_lang_chk' ],
			'source url with a query string'         => [ "UPDATE {entries} SET source_url = 'https://example.com/?gclid=x' WHERE id = {entry}", '{p}be_url_chk' ],
			'source url with a fragment'             => [ "UPDATE {entries} SET source_url = 'https://example.com/#form' WHERE id = {entry}", '{p}be_url_chk' ],
			'empty file'                             => [ 'UPDATE {entry_files} SET size_bytes = 0 WHERE entry_id = {entry}', '{p}befi_size_chk' ],
			'note level outside the enum'            => [ "UPDATE {entry_notes} SET level = 'warning' WHERE entry_id = {entry}", '{p}ben_level_chk' ],
			'redirect source without a slash'        => [ "UPDATE {redirects} SET source = 'old' WHERE id = {redirect}", '{p}br_source_chk' ],
			'redirect source with the domain'        => [ "UPDATE {redirects} SET source = 'https://example.com/old' WHERE id = {redirect}", '{p}br_source_chk' ],
			'redirect source with a final slash'     => [ "UPDATE {redirects} SET source = '/old/' WHERE id = {redirect}", '{p}br_source_chk' ],
			'final slash before the query string'    => [ "UPDATE {redirects} SET source = '/old/?page=2' WHERE id = {redirect}", '{p}br_source_chk' ],
			'redirect code outside the enum'         => [ 'UPDATE {redirects} SET code = 303 WHERE id = {redirect}', '{p}br_code_chk' ],
			'410 with a target'                      => [ 'UPDATE {redirects} SET code = 410 WHERE id = {redirect}', '{p}br_target_chk' ],
			'301 without a target'                   => [ 'UPDATE {redirects} SET target = NULL WHERE id = {redirect}', '{p}br_target_chk' ],
			'redirect target with javascript'        => [ "UPDATE {redirects} SET target = 'javascript:alert(1)' WHERE id = {redirect}", '{p}br_url_chk' ],
			'redirect is_auto not 0 or 1'            => [ 'UPDATE {redirects} SET is_auto = 2 WHERE id = {redirect}', '{p}br_auto_chk' ],
			'banner is_enabled not 0 or 1'           => [ 'UPDATE {banners} SET is_enabled = 2 WHERE id = {banner}', '{p}bb_enabled_chk' ],
			'banner ending before it starts'         => [ "UPDATE {banners} SET starts_at = '2030-02-01', ends_at = '2030-01-01' WHERE id = {banner}", '{p}bb_period_chk' ],
			'banner ending when it starts'           => [ "UPDATE {banners} SET starts_at = '2030-01-01', ends_at = '2030-01-01' WHERE id = {banner}", '{p}bb_period_chk' ],
			'banner settings a list'                 => [ "UPDATE {banners} SET settings = '[]' WHERE id = {banner}", '{p}bb_settings_chk' ],
			'banner text lang uppercase'             => [ "UPDATE {banners_i18n} SET lang = 'IT' WHERE banner_id = {banner}", '{p}bbi_lang_chk' ],
			'empty banner text'                      => [ "UPDATE {banners_i18n} SET text = '' WHERE banner_id = {banner}", '{p}bbi_text_chk' ],
			'banner link without a scheme'           => [ "UPDATE {banners_i18n} SET link_url = 'example.com/saldi' WHERE banner_id = {banner}", '{p}bbi_url_chk' ],
			'banner link with javascript'            => [ "UPDATE {banners_i18n} SET link_url = 'javascript:alert(1)' WHERE banner_id = {banner}", '{p}bbi_url_chk' ],

			// FOREIGN KEY: righe figlie senza padre.
			'texts of a missing form'                => [ "INSERT INTO {form_i18n} (form_id, lang, submit_label) VALUES (999999, 'it', 'Invia')", '{p}bfi_form_fk' ],
			'notification of a missing form'         => [ "INSERT INTO {form_notifications} (form_id, name, recipients, position) VALUES (999999, 'Admin', 'a@example.com', 0)", '{p}bfn_form_fk' ],
			'texts of a missing notification'        => [ "INSERT INTO {form_notifications_i18n} (notification_id, lang, subject, body) VALUES (999999, 'it', 'S', 'B')", '{p}bfni_notif_fk' ],
			'integration of a missing form'          => [ "INSERT INTO {form_integrations} (form_id, slug, settings) VALUES (999999, 'mailchimp', '{}')", '{p}bfg_form_fk' ],
			'texts of a missing integration'         => [ "INSERT INTO {form_integrations_i18n} (integration_id, lang, settings) VALUES (999999, 'it', '{}')", '{p}bfgi_integ_fk' ],
			'version of a missing form'              => [ "INSERT INTO {form_versions} (form_id, version, created_at) VALUES (999999, 1, '2026-01-01')", '{p}bfv_form_fk' ],
			'field of a missing version'             => [ "INSERT INTO {form_fields} (version_id, field_key, type, position, config) VALUES (999999, 'x', 'text', 0, '{}')", '{p}bff_version_fk' ],
			'texts of a missing field'               => [ "INSERT INTO {form_fields_i18n} (field_id, lang, label) VALUES (999999, 'en', 'X')", '{p}bffi_field_fk' ],
			'entry on a version of another form'     => [ "INSERT INTO {entries} (form_id, version_id, lang, created_at) VALUES ({form}, {other_version}, 'it', '2026-01-01')", '{p}be_version_fk' ],
			'value of a missing entry'               => [ "INSERT INTO {entry_values} (entry_id, field_id, value) VALUES (999999, {email}, 'x')", '{p}bev_entry_fk' ],
			'value of a missing field'               => [ "INSERT INTO {entry_values} (entry_id, field_id, value) VALUES ({entry}, 999999, 'x')", '{p}bev_field_fk' ],
			'file of a missing entry'                => [ sprintf( $file, 999999, '{cv}', 'new1' ), '{p}befi_entry_fk' ],
			'file of a missing field'                => [ sprintf( $file, '{entry}', 999999, 'new2' ), '{p}befi_field_fk' ],
			'texts of a missing banner'              => [ "INSERT INTO {banners_i18n} (banner_id, lang, text) VALUES (999999, 'it', 'Saldi')", '{p}bbi_banner_fk' ],
			'note of a missing entry'                => [ "INSERT INTO {entry_notes} (entry_id, source, level, message, created_at) VALUES (999999, 'admin', 'info', 'x', '2026-01-01')", '{p}ben_entry_fk' ],

			// FOREIGN KEY RESTRICT: i padri con dati storici o file non si cancellano.
			'form with entries'                      => [ 'DELETE FROM {forms} WHERE id = {form}', $fk ],
			'version used by an entry'               => [ 'DELETE FROM {form_versions} WHERE id = {bare_version}', '{p}be_version_fk' ],
			'field with values'                      => [ 'DELETE FROM {form_fields} WHERE id = {email}', '{p}bev_field_fk' ],
			'field with files'                       => [ 'DELETE FROM {form_fields} WHERE id = {cv}', '{p}befi_field_fk' ],
			'entry with files'                       => [ 'DELETE FROM {entries} WHERE id = {entry}', '{p}befi_entry_fk' ],

			// UNIQUE e PRIMARY KEY composte. form_id_id non si può violare (contiene la PK): serve alla FK di entries.
			'two form texts, same lang'              => [ "INSERT INTO {form_i18n} (form_id, lang, submit_label) VALUES ({form}, 'it', 'Invia')", 'PRIMARY' ],
			'two notifications in the same position' => [ "INSERT INTO {form_notifications} (form_id, name, recipients, position) VALUES ({form}, 'Copy', 'a@example.com', 0)", 'form_position' ],
			'two notification texts, same lang'      => [ "INSERT INTO {form_notifications_i18n} (notification_id, lang, subject, body) VALUES ({notification}, 'it', 'S', 'B')", 'PRIMARY' ],
			'the same integration twice in a form'   => [ "INSERT INTO {form_integrations} (form_id, slug, settings) VALUES ({form}, 'mailchimp', '{}')", 'form_slug' ],
			'two integration texts, same lang'       => [ "INSERT INTO {form_integrations_i18n} (integration_id, lang, settings) VALUES ({integration}, 'it', '{}')", 'PRIMARY' ],
			'two versions with the same number'      => [ "INSERT INTO {form_versions} (form_id, version, created_at) VALUES ({form}, 1, '2026-01-01')", 'form_version' ],
			'two fields with the same key'           => [ "INSERT INTO {form_fields} (version_id, field_key, type, position, config) VALUES ({version}, 'email', 'text', 9, '{}')", 'version_key' ],
			'two fields in the same position'        => [ "INSERT INTO {form_fields} (version_id, field_key, type, position, config) VALUES ({version}, 'other', 'text', 0, '{}')", 'version_position' ],
			'two field texts, same lang'             => [ "INSERT INTO {form_fields_i18n} (field_id, lang, label) VALUES ({email}, 'pt-br', 'X')", 'PRIMARY' ],
			'two values in the same position'        => [ "INSERT INTO {entry_values} (entry_id, field_id, position, value) VALUES ({entry}, {email}, 0, 'b')", 'PRIMARY' ],
			'two files with the same path'           => [ sprintf( $file, '{entry}', '{cv}', 'a1b2c3' ), 'stored_path' ],
			'two banner texts, same lang'            => [ "INSERT INTO {banners_i18n} (banner_id, lang, text) VALUES ({banner}, 'it', 'Saldi')", 'PRIMARY' ],
			'two redirects from the same source'     => [ "INSERT INTO {redirects} (source, target, code, created_at, updated_at) VALUES ('/old', '/other', 302, '2026-01-01', '2026-01-01')", "source'" ],
		];
	}

	/** @dataProvider rejected_writes */
	public function test_rejects( string $sql, string $error ): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		$result   = $wpdb->query( $this->sql( $sql ) );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $result, 'The database accepted: ' . $this->sql( $sql ) );
		$this->assertStringContainsString( $this->sql( $error ), $wpdb->last_error );
	}

	// I vincoli non devono essere più severi del necessario.
	/** @return array<string, array{string}> */
	public static function accepted_writes(): array {
		return [
			'closed form'                => [ "UPDATE {forms} SET status = 'closed' WHERE id = {form}" ],
			'relative success url'       => [ "UPDATE {form_i18n} SET success_url = '/grazie/' WHERE form_id = {form}" ],
			'success url with a query'   => [ "UPDATE {form_i18n} SET success_url = 'https://example.com/grazie?ok=1' WHERE form_id = {form}" ],
			'standard success'           => [ 'UPDATE {form_i18n} SET success_message = NULL, success_url = NULL WHERE form_id = {form}' ],
			'conditional notification'   => [ "UPDATE {form_notifications} SET conditions = '{\"all\": []}' WHERE id = {notification}" ],
			'integration slug with dash' => [ "UPDATE {form_integrations} SET slug = 'mail-up_2' WHERE id = {integration}" ],
			'field key with digits'      => [ "UPDATE {form_fields} SET field_key = 'email_2' WHERE id = {email}" ],
			'always visible field'       => [ 'UPDATE {form_fields} SET conditions = NULL WHERE id = {email}' ],
			'options as value: label'    => [ "UPDATE {form_fields_i18n} SET options = '{\"to\": \"Torino\"}' WHERE field_id = {email}" ],
			'lang with script subtag'    => [ "UPDATE {form_fields_i18n} SET lang = 'zh-hans' WHERE field_id = {email}" ],
			'three-letter lang'          => [ "UPDATE {entries} SET lang = 'fur' WHERE id = {entry}" ],
			'source url without a query' => [ "UPDATE {entries} SET source_url = 'https://example.com/contatti/' WHERE id = {entry}" ],
			'redirect from the home'     => [ "UPDATE {redirects} SET source = '/' WHERE id = {redirect}" ],
			'source with a query string' => [ "UPDATE {redirects} SET source = '/old?next=/a/' WHERE id = {redirect}" ],
			'sources differ by case'     => [ "INSERT INTO {redirects} (source, target, code, created_at, updated_at) VALUES ('/Old', '/new', 301, '2026-01-01', '2026-01-01')" ],
			'410 without a target'       => [ 'UPDATE {redirects} SET code = 410, target = NULL WHERE id = {redirect}' ],
			'absolute redirect target'   => [ "UPDATE {redirects} SET code = 308, target = 'https://example.com/new?a=1' WHERE id = {redirect}" ],
			'banner with only an end'    => [ "UPDATE {banners} SET starts_at = NULL, ends_at = '2030-01-01' WHERE id = {banner}" ],
			'banner with a period'       => [ "UPDATE {banners} SET starts_at = '2030-01-01', ends_at = '2030-01-01 00:00:01' WHERE id = {banner}" ],
			'relative banner link'       => [ "UPDATE {banners_i18n} SET link_url = '/saldi/' WHERE banner_id = {banner}" ],
			'banner text in emoji'       => [ "UPDATE {banners_i18n} SET text = '🎉' WHERE banner_id = {banner}" ],
		];
	}

	/** @dataProvider accepted_writes */
	public function test_accepts( string $sql ): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( $this->sql( $sql ) ), $wpdb->last_error );
	}

	public function test_deleting_an_entry_cascades_to_values_and_notes(): void {
		$this->query( 'DELETE FROM {entries} WHERE id = {entry_no_files}' );

		$this->assertSame( 0, $this->rows( 'entry_values', 'entry_id', $this->id['entry_no_files'] ) );
		$this->assertSame( 0, $this->rows( 'entry_notes', 'entry_id', $this->id['entry_no_files'] ) );
	}

	public function test_deleting_an_unused_version_cascades_to_fields_and_texts(): void {
		$this->query( 'DELETE FROM {form_versions} WHERE id = {unused_version}' );

		$this->assertSame( 0, $this->rows( 'form_fields', 'version_id', $this->id['unused_version'] ) );
		$this->assertSame( 0, $this->rows( 'form_fields_i18n', 'field_id', $this->id['unused_field'] ) );
	}

	public function test_deleting_a_form_without_entries_cascades_to_everything_it_owns(): void {
		$this->query( 'DELETE FROM {forms} WHERE id = {other_form}' );

		$this->assertSame( 0, $this->rows( 'form_versions', 'form_id', $this->id['other_form'] ) );
		$this->assertSame( 0, $this->rows( 'form_i18n', 'form_id', $this->id['other_form'] ) );
		$this->assertSame( 0, $this->rows( 'form_notifications', 'form_id', $this->id['other_form'] ) );
		$this->assertSame( 0, $this->rows( 'form_notifications_i18n', 'notification_id', $this->id['other_notif'] ) );
		$this->assertSame( 0, $this->rows( 'form_integrations', 'form_id', $this->id['other_form'] ) );
		$this->assertSame( 0, $this->rows( 'form_integrations_i18n', 'integration_id', $this->id['other_integ'] ) );
	}

	public function test_deleting_a_banner_cascades_to_its_texts(): void {
		$this->query( 'DELETE FROM {banners} WHERE id = {banner}' );

		$this->assertSame( 0, $this->rows( 'banners_i18n', 'banner_id', $this->id['banner'] ) );
	}

	/** @return array<string, array{string, list<string>}> */
	public static function enums(): array {
		return [
			'FormStatus'   => [ 'bf_status_chk', array_column( FormStatus::cases(), 'value' ) ],
			'NoteLevel'    => [ 'ben_level_chk', array_column( NoteLevel::cases(), 'value' ) ],
			'RedirectCode' => [ 'br_code_chk', array_map( 'strval', array_column( RedirectCode::cases(), 'value' ) ) ],
		];
	}

	/**
	 * @dataProvider enums
	 * @param list<string> $values
	 */
	public function test_enum_matches_check_constraint( string $constraint, array $values ): void {
		global $wpdb;
		$clause = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s',
				$wpdb->prefix . $constraint
			)
		);
		// MariaDB: `status` in ('active',…); MySQL: (`status` in (_utf8mb4\'active\',…)). I numeri senza apici.
		preg_match_all( "/'([^']*)'/", stripslashes( (string) $clause ), $quoted );
		preg_match_all( '/\b\d+\b/', (string) $clause, $numbers );

		$this->assertEqualsCanonicalizing( $values, $quoted[1] ?: $numbers[0], (string) $clause );
	}

	private function sql( string $template ): string {
		global $wpdb;
		return (string) preg_replace_callback(
			'/\{(\w+)\}/',
			fn( array $m ): string => match ( true ) {
				'p' === $m[1] => $wpdb->prefix,
				isset( $this->id[ $m[1] ] ) => (string) $this->id[ $m[1] ],
				default => Db::table( $m[1] ),
			},
			$template
		);
	}

	private function query( string $sql ): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( $this->sql( $sql ) ), $wpdb->last_error );
	}

	private function rows( string $table, string $column, int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', Db::table( $table ), $column, $id ) );
	}

	/** @param array<string, scalar> $row */
	private function insert( string $table, array $row ): int {
		$dates = [
			'forms'         => [ 'created_at', 'updated_at' ],
			'form_versions' => [ 'created_at' ],
			'entries'       => [ 'created_at' ],
			'entry_files'   => [ 'created_at' ],
			'entry_notes'   => [ 'created_at' ],
			'redirects'     => [ 'created_at', 'updated_at' ],
			'banners'       => [ 'created_at', 'updated_at' ],
		];
		foreach ( $dates[ $table ] ?? [] as $column ) {
			$row[ $column ] = '2026-01-01 00:00:00';
		}
		return Db::insert( Db::table( $table ), $row );
	}

	// Testi e una notifica (con i suoi testi) per il form; restituisce l'id della notifica.
	private function settings( int $form_id ): int {
		$this->insert(
			'form_i18n',
			[
				'form_id'      => $form_id,
				'lang'         => 'it',
				'submit_label' => 'Invia',
			]
		);
		$id = $this->insert(
			'form_notifications',
			[
				'form_id'    => $form_id,
				'name'       => 'Admin',
				'recipients' => 'admin@example.com',
				'position'   => 0,
			]
		);
		$this->insert(
			'form_notifications_i18n',
			[
				'notification_id' => $id,
				'lang'            => 'it',
				'subject'         => 'Nuova richiesta',
				'body'            => '{{all_fields}}',
			]
		);
		return $id;
	}

	// Un'integrazione con le impostazioni in italiano; restituisce il suo id.
	private function integration( int $form_id ): int {
		$id = $this->insert(
			'form_integrations',
			[
				'form_id'  => $form_id,
				'slug'     => 'mailchimp',
				'settings' => '{}',
			]
		);
		$this->insert(
			'form_integrations_i18n',
			[
				'integration_id' => $id,
				'lang'           => 'it',
				'settings'       => '{"list_id": "abc"}',
			]
		);
		return $id;
	}

	private function version( int $form_id, int $number ): int {
		return $this->insert(
			'form_versions',
			[
				'form_id' => $form_id,
				'version' => $number,
			]
		);
	}

	private function field( int $version_id, string $key, int $position ): int {
		$id = $this->insert(
			'form_fields',
			[
				'version_id' => $version_id,
				'field_key'  => $key,
				'type'       => 'text',
				'position'   => $position,
				'config'     => '{}',
			]
		);
		$this->insert(
			'form_fields_i18n',
			[
				'field_id' => $id,
				'lang'     => 'pt-br',
				'label'    => ucfirst( $key ),
			]
		);
		return $id;
	}

	private function entry( int $version_id, bool $with_children = true ): int {
		$id = $this->insert(
			'entries',
			[
				'form_id'    => $this->id['form'],
				'version_id' => $version_id,
				'lang'       => 'it',
			]
		);
		if ( $with_children ) {
			$this->insert(
				'entry_values',
				[
					'entry_id' => $id,
					'field_id' => $this->id['email'],
					'value'    => 'a@example.com',
				]
			);
			$this->insert(
				'entry_notes',
				[
					'entry_id' => $id,
					'source'   => 'admin',
					'level'    => 'info',
					'message'  => 'Note',
				]
			);
		}
		return $id;
	}
}
