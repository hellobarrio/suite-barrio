<?php
if (!defined('ABSPATH')) exit;

class SUITE_BARRIO_Service_Registry {

    const CATEGORIES_OPTION = 'wpb_cb_categories';
    const SERVICES_OPTION   = 'wpb_cb_services';

    /* ── Categorie ── */

    private static function default_categories(): array {
        return [
            'necessary'  => ['id' => 'necessary',  'name' => 'Cookie necessari',      'description' => 'Indispensabili per il funzionamento del sito. Non richiedono consenso.',              'always_active' => true],
            'functional' => ['id' => 'functional', 'name' => 'Cookie funzionali',     'description' => 'Memorizzano preferenze come lingua e impostazioni personali.',                         'always_active' => false],
            'analytics'  => ['id' => 'analytics',  'name' => 'Cookie analitici',      'description' => "Misurano l'utilizzo del sito in forma anonima o aggregata.",                          'always_active' => false],
            'marketing'  => ['id' => 'marketing',  'name' => 'Cookie di marketing',   'description' => 'Utilizzati per mostrare pubblicità pertinente agli interessi dell\'utente.',           'always_active' => false],
        ];
    }

    public static function get_categories(): array {
        $saved    = (array) get_option(self::CATEGORIES_OPTION, []);
        $defaults = self::default_categories();
        foreach ($defaults as $id => $cat) {
            if (!isset($saved[$id])) $saved[$id] = $cat;
        }
        return $saved;
    }

    private static function save_categories(array $categories): void {
        update_option(self::CATEGORIES_OPTION, $categories, false);
    }

    /* ── Servizi ── */

    public static function get_services(): array {
        return (array) get_option(self::SERVICES_OPTION, []);
    }

    public static function get_active_services(): array {
        return array_values(array_filter(self::get_services(), fn($s) => !empty($s['active'])));
    }

    public static function get_services_by_category(string $category): array {
        return array_values(array_filter(
            self::get_active_services(),
            fn($s) => ($s['category'] ?? '') === $category
        ));
    }

    public static function add_service(array $service): string {
        $services = self::get_services();
        $base_id  = sanitize_key($service['id'] ?? ($service['name'] ?? 'service'));
        $id       = $base_id;
        $existing = array_column($services, 'id');
        $i = 1;
        while (in_array($id, $existing, true)) {
            $id = $base_id . '-' . $i++;
        }
        $service['id'] = $id;
        $services[] = self::sanitize_service($service);
        self::save_services($services);
        return $id;
    }

    public static function update_service(string $id, array $data): bool {
        $services = self::get_services();
        foreach ($services as &$s) {
            if ($s['id'] === $id) {
                $data['id'] = $id;
                $s = self::sanitize_service(array_merge($s, $data));
                unset($s);
                self::save_services($services);
                return true;
            }
        }
        unset($s);
        return false;
    }

    public static function delete_service(string $id): bool {
        $services = self::get_services();
        $filtered = array_values(array_filter($services, fn($s) => $s['id'] !== $id));
        if (count($filtered) === count($services)) return false;
        self::save_services($filtered);
        return true;
    }

    public static function toggle_service(string $id, bool $active): bool {
        return self::update_service($id, ['active' => $active]);
    }

    private static function save_services(array $services): void {
        update_option(self::SERVICES_OPTION, array_values($services), false);
    }

    private static function sanitize_service(array $s): array {
        return [
            'id'          => sanitize_key($s['id'] ?? ''),
            'name'        => sanitize_text_field($s['name'] ?? ''),
            'category'    => sanitize_key($s['category'] ?? 'necessary'),
            'provider'    => sanitize_text_field($s['provider'] ?? ''),
            'privacy_url' => esc_url_raw($s['privacy_url'] ?? ''),
            'cookies'     => array_map('sanitize_text_field', (array)($s['cookies'] ?? [])),
            'script'      => (string)($s['script'] ?? ''),
            'active'      => !empty($s['active']),
            'source'      => in_array($s['source'] ?? '', ['manual', 'scanner'], true) ? $s['source'] : 'manual',
        ];
    }

    /* ── Prompt AI ── */

    public static function get_services_for_prompt(): string {
        $cats  = self::get_categories();
        $lines = [];
        foreach (['functional', 'analytics', 'marketing'] as $cat_id) {
            $services = self::get_services_by_category($cat_id);
            if (empty($services)) continue;
            $cat_name = $cats[$cat_id]['name'] ?? ucfirst($cat_id);
            foreach ($services as $s) {
                $cookies = implode(', ', $s['cookies']);
                $line    = "- {$cat_name}: {$s['name']} ({$s['provider']})";
                if ($cookies) $line .= " — cookie: $cookies";
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }
}
