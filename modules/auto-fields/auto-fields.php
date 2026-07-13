<?php
/**
 * Suite Barrio — Auto Fields
 * Analizza una pagina web e genera automaticamente campi ACF + query GraphQL.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ═══════════════════════════════════════════════════════════════
   ADMIN MENU
   ═══════════════════════════════════════════════════════════════ */
add_action('admin_menu', function () {
    add_submenu_page(
        'suite-barrio',
        'Auto Fields — Suite Barrio',
        'Auto Fields',
        'manage_options',
        'suite-barrio-auto-fields',
        'suite_barrio_auto_fields_page'
    );
}, 11);

/* ═══════════════════════════════════════════════════════════════
   AJAX ENDPOINTS
   ═══════════════════════════════════════════════════════════════ */
add_action('wp_ajax_suite_barrio_auto_fields_analyze', 'suite_barrio_af_ajax_analyze');
add_action('wp_ajax_suite_barrio_auto_fields_generate', 'suite_barrio_af_ajax_generate');

/* ── Analizza URL ──────────────────────────────────────────── */
function suite_barrio_af_ajax_analyze()
{
    check_ajax_referer('suite_barrio_auto_fields_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permessi insufficienti.');
    }

    $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error('URL non valido.');
    }

    $scheme = wp_parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        wp_send_json_error('Solo URL http/https sono supportati.');
    }

    $response = wp_remote_get($url, [
        'timeout' => 30,
        'sslverify' => true,
        'user-agent' => 'SuiteBarrio AutoFields/1.0',
        'redirection' => 3,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Errore: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        wp_send_json_error("La pagina ha restituito HTTP $code.");
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        wp_send_json_error('La pagina non contiene contenuto.');
    }

    // Gestione encoding
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    if (preg_match('/charset=([^\s;]+)/i', $content_type, $m)) {
        $charset = strtoupper(trim($m[1]));
        if ($charset && $charset !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $charset);
        }
    }

    $sections = suite_barrio_af_parse_html($html);
    if (empty($sections)) {
        wp_send_json_error('Nessuna sezione rilevata nella pagina.');
    }

    wp_send_json_success(['sections' => $sections]);
}

/* ── Genera gruppo campi ACF ───────────────────────────────── */
function suite_barrio_af_ajax_generate()
{
    check_ajax_referer('suite_barrio_auto_fields_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permessi insufficienti.');
    }
    if (!function_exists('acf_import_field_group')) {
        wp_send_json_error('ACF non è attivo. Installa e attiva Advanced Custom Fields.');
    }

    $title = sanitize_text_field(wp_unslash($_POST['group_title'] ?? ''));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON array di sezioni/campi generato dal wizard JS, decodificato subito dopo con json_decode(); una sanitizzazione testuale corromperebbe la struttura dati.
    $sections = json_decode(wp_unslash($_POST['sections'] ?? '[]'), true);
    $loc_type = sanitize_text_field(wp_unslash($_POST['location_type'] ?? 'post_type'));
    $loc_val = sanitize_text_field(wp_unslash($_POST['location_value'] ?? 'page'));

    if (empty($title))
        wp_send_json_error('Inserisci un nome per il gruppo.');
    if (!is_array($sections) || empty($sections))
        wp_send_json_error('Nessun campo da generare.');

    $result = suite_barrio_af_create_acf_group($title, $sections, $loc_type, $loc_val);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    $graphql = suite_barrio_af_build_graphql($title, $sections);

    wp_send_json_success([
        'message' => 'Gruppo di campi ACF creato con successo!',
        'group_key' => $result['key'],
        'field_count' => $result['count'],
        'edit_url' => $result['edit_url'],
        'graphql' => $graphql,
    ]);
}

/* ═══════════════════════════════════════════════════════════════
   HTML PARSER
   ═══════════════════════════════════════════════════════════════ */

function suite_barrio_af_parse_html(string $html): array
{
    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    // Rimuovi elementi inutili
    foreach (['script', 'style', 'svg', 'noscript', 'link', 'meta'] as $tag) {
        foreach (iterator_to_array($xp->query('//' . $tag)) as $n) {
            if ($n->parentNode)
                $n->parentNode->removeChild($n);
        }
    }

    // Rimuovi form (ma estrai prima eventuali heading come titoli di sezione)
    foreach (iterator_to_array($xp->query('//form')) as $form) {
        if (!$form->parentNode)
            continue;
        // Sposta heading prima del form nel DOM, così vengono analizzati
        $headings_in_form = $xp->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $form);
        foreach (iterator_to_array($headings_in_form) as $h) {
            $form->parentNode->insertBefore($h->cloneNode(true), $form);
        }
        $form->parentNode->removeChild($form);
    }

    // Rimuovi label isolate rimaste
    foreach (iterator_to_array($xp->query('//label')) as $lbl) {
        if ($lbl->parentNode)
            $lbl->parentNode->removeChild($lbl);
    }

    // Contenuto principale
    $root = null;
    foreach (['//main', '//body'] as $q) {
        $nodes = $xp->query($q);
        if ($nodes->length) {
            $root = $nodes->item(0);
            break;
        }
    }
    if (!$root)
        $root = $doc->documentElement;

    // Cerca <section>
    $section_nodes = $xp->query('.//section', $root);

    if ($section_nodes->length === 0) {
        $section_nodes = $xp->query(
            './*[not(self::nav) and not(self::header) and not(self::footer) and not(self::script)]',
            $root
        );
    }

    $sections = [];
    $counter = 1;

    if ($section_nodes->length > 0) {
        foreach ($section_nodes as $sn) {
            if ($sn->nodeType !== XML_ELEMENT_NODE)
                continue;
            $label = suite_barrio_af_section_label($sn, $xp, $counter);
            $id = 'sezione' . $counter;
            $fields = [];
            $fc = 1;
            suite_barrio_af_extract($sn, $xp, $fields, $fc, $id);
            if (!empty($fields)) {
                $sections[] = ['id' => $id, 'label' => $label, 'fields' => $fields];
            }
            $counter++;
        }
    }

    // Fallback: intera pagina come una sezione
    if (empty($sections)) {
        $fields = [];
        $fc = 1;
        suite_barrio_af_extract($root, $xp, $fields, $fc, 'sezione1');
        if (!empty($fields)) {
            $sections[] = ['id' => 'sezione1', 'label' => 'Sezione 1', 'fields' => $fields];
        }
    }

    return $sections;
}

/* ── Genera ID sezione ──────────────────────────────────────── */
function suite_barrio_af_section_id(DOMElement $node, int $i): string
{
    $id = $node->getAttribute('id');
    if (!$id) {
        $cls = $node->getAttribute('class');
        $parts = $cls ? preg_split('/\s+/', trim($cls)) : [];
        $id = $parts[0] ?? '';
    }
    $id = $id ? sanitize_key($id) : 'sezione_' . $i;
    return preg_replace('/[^a-z0-9_]/', '_', strtolower($id));
}

/* ── Label → snake_case (per ID sezione da etichetta) ────────── */
function suite_barrio_af_label_to_snake(string $label): string
{
    // Transliterazione base accenti → ascii
    $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9]+/', '_', $str);
    $str = trim($str, '_');
    $str = preg_replace('/_+/', '_', $str);
    return $str ?: 'sezione';
}

/* ── Etichetta sezione (dal primo heading) ──────────────────── */
function suite_barrio_af_section_label(DOMElement $node, DOMXPath $xp, int $i): string
{
    $headings = $xp->query('.//h1|.//h2|.//h3', $node);
    if ($headings->length) {
        $text = trim($headings->item(0)->textContent);
        if (mb_strlen($text) > 50)
            $text = mb_substr($text, 0, 47) . '…';
        if ($text !== '')
            return $text;
    }
    return "Sezione $i";
}

/* ── Estrai campi da un nodo DOM ────────────────────────────── */
function suite_barrio_af_extract(DOMNode $node, DOMXPath $xp, array &$fields, int &$c, string $sid, int $depth = 0): void
{
    if ($depth > 6)
        return;

    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE)
            continue;
        $tag = strtolower($child->nodeName);

        if ($depth === 0 && in_array($tag, ['nav', 'header', 'footer'], true))
            continue;

        // Ignora elementi form e input residui
        if (in_array($tag, ['form', 'input', 'select', 'textarea', 'button', 'fieldset', 'legend', 'label', 'datalist', 'output'], true))
            continue;

        // ── Headings ──
        if (preg_match('/^h[1-6]$/', $tag)) {
            $text = trim($child->textContent);
            if ($text !== '') {
                $suffix = "titolo_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => 'Titolo (' . strtoupper($tag) . ')',
                    'type' => 'text',
                    'preview' => mb_substr($text, 0, 120),
                    'suffix' => $suffix,
                ];
                $c++;
            }
            continue;
        }

        // ── Immagini ──
        if ($tag === 'img') {
            suite_barrio_af_add_image($child, $fields, $c, $sid);
            continue;
        }

        // ── Picture ──
        if ($tag === 'picture') {
            $imgs = $xp->query('.//img', $child);
            if ($imgs->length)
                suite_barrio_af_add_image($imgs->item(0), $fields, $c, $sid);
            continue;
        }

        // ── Figure ──
        if ($tag === 'figure') {
            $img = $xp->query('.//img', $child);
            $cap = $xp->query('.//figcaption', $child);
            if ($img->length)
                suite_barrio_af_add_image($img->item(0), $fields, $c, $sid);
            if ($cap->length) {
                $text = trim($cap->item(0)->textContent);
                if ($text !== '') {
                    $suffix = "didascalia_{$c}";
                    $fields[] = [
                        'name' => "{$sid}_{$suffix}",
                        'label' => 'Didascalia',
                        'type' => 'text',
                        'preview' => mb_substr($text, 0, 120),
                        'suffix' => $suffix,
                    ];
                    $c++;
                }
            }
            continue;
        }

        // ── Liste (ripetitore solo con >3 voci) ──
        if ($tag === 'ul' || $tag === 'ol') {
            $items = $xp->query('./li', $child);
            if ($items->length > 3) {
                $sub = suite_barrio_af_list_sub_fields($items, $xp);
                $suffix = "elenco_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => 'Elenco (' . $items->length . ' voci)',
                    'type' => 'repeater',
                    'sub_fields' => $sub,
                    'preview' => mb_substr(trim($items->item(0)->textContent), 0, 80),
                    'suffix' => $suffix,
                ];
                $c++;
            } else {
                // Poche voci: estrai contenuto di ogni <li> come campi singoli
                foreach ($items as $li) {
                    suite_barrio_af_extract($li, $xp, $fields, $c, $sid, $depth + 1);
                }
            }
            continue;
        }

        // ── Paragrafi ──
        if ($tag === 'p') {
            $text = trim($child->textContent);
            if (mb_strlen($text) < 2)
                continue;
            if (suite_barrio_af_has_rich($child)) {
                $type = 'wysiwyg';
                $label = 'Testo formattato';
            } elseif (mb_strlen($text) > 200) {
                $type = 'textarea';
                $label = 'Testo lungo';
            } else {
                $type = 'text';
                $label = 'Testo';
            }
            $suffix = "testo_{$c}";
            $fields[] = [
                'name' => "{$sid}_{$suffix}",
                'label' => $label,
                'type' => $type,
                'preview' => mb_substr($text, 0, 120),
                'suffix' => $suffix,
            ];
            $c++;
            continue;
        }

        // ── Blockquote ──
        if ($tag === 'blockquote') {
            $text = trim($child->textContent);
            if ($text !== '') {
                $suffix = "citazione_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => 'Citazione',
                    'type' => 'textarea',
                    'preview' => mb_substr($text, 0, 120),
                    'suffix' => $suffix,
                ];
                $c++;
            }
            continue;
        }

        // ── Video ──
        if ($tag === 'video') {
            $src = '';
            $sources = $xp->query('.//source', $child);
            if ($sources->length) {
                $src = $sources->item(0)->getAttribute('src');
            }
            if (!$src)
                $src = $child->getAttribute('src');
            $suffix = "video_{$c}";
            $fields[] = [
                'name' => "{$sid}_{$suffix}",
                'label' => 'Video (MP4)',
                'type' => 'file',
                'preview' => $src ? mb_substr($src, 0, 80) : '',
                'suffix' => $suffix,
            ];
            $c++;
            continue;
        }

        // ── Iframe (embed) ──
        if ($tag === 'iframe') {
            $src = $child->getAttribute('src');
            $suffix = "video_{$c}";
            $fields[] = [
                'name' => "{$sid}_{$suffix}",
                'label' => 'Video embed',
                'type' => 'oembed',
                'preview' => $src ? mb_substr($src, 0, 80) : '',
                'suffix' => $suffix,
            ];
            $c++;
            continue;
        }

        // ── Link fancybox con immagine → campo immagine ──
        if ($tag === 'a' && suite_barrio_af_is_fancybox($child)) {
            $inner_img = $xp->query('.//img', $child);
            if ($inner_img->length) {
                suite_barrio_af_add_image($inner_img->item(0), $fields, $c, $sid);
                continue;
            }
        }

        // ── Link/bottone standalone ──
        if ($tag === 'a' && suite_barrio_af_is_button($child)) {
            $text = trim($child->textContent);
            $href = $child->getAttribute('href');
            if ($text !== '') {
                $suffix = "link_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => 'Link / Bottone',
                    'type' => 'link',
                    'preview' => $text . ($href ? ' → ' . mb_substr($href, 0, 60) : ''),
                    'suffix' => $suffix,
                ];
                $c++;
            }
            continue;
        }

        // ── Swiper / Slider ──
        if (suite_barrio_af_is_swiper($child)) {
            $slides = suite_barrio_af_get_swiper_slides($child, $xp);
            if (!empty($slides)) {
                // Se ogni slide contiene solo un'immagine → galleria
                if (suite_barrio_af_slides_are_images_only($slides, $xp)) {
                    $suffix = "galleria_{$c}";
                    $fields[] = [
                        'name' => "{$sid}_{$suffix}",
                        'label' => 'Galleria (' . count($slides) . ' immagini)',
                        'type' => 'gallery',
                        'preview' => 'Slider con ' . count($slides) . ' immagini',
                        'suffix' => $suffix,
                    ];
                    $c++;
                } else {
                    // Slide con contenuto misto → ripetitore
                    $sub = suite_barrio_af_repeater_sub_fields($slides[0], $xp);
                    $suffix = "slider_{$c}";
                    $fields[] = [
                        'name' => "{$sid}_{$suffix}",
                        'label' => 'Slider (' . count($slides) . ' slide)',
                        'type' => 'repeater',
                        'sub_fields' => $sub,
                        'preview' => mb_substr(trim($slides[0]->textContent), 0, 80),
                        'suffix' => $suffix,
                    ];
                    $c++;
                }
                continue;
            }
        }

        // ── Container con pattern ripetuto (solo flex/grid con >3 figli) ──
        if (in_array($tag, ['div', 'article', 'aside', 'section', 'ul', 'ol'], true)) {
            if (suite_barrio_af_is_flex_grid($child) && suite_barrio_af_is_repeating($child, $xp)) {
                $rep_items = suite_barrio_af_get_repeating_children($child, $xp);
                $sub = suite_barrio_af_repeater_sub_fields($rep_items[0], $xp);
                $suffix = "ripetitore_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => 'Ripetitore (' . count($rep_items) . ' elementi)',
                    'type' => 'repeater',
                    'sub_fields' => $sub,
                    'preview' => mb_substr(trim($rep_items[0]->textContent), 0, 80),
                    'suffix' => $suffix,
                ];
                $c++;
                continue;
            }
        }

        // ── Div con solo un <a> dentro → campo link ──
        if (in_array($tag, ['div', 'span', 'li'], true) && suite_barrio_af_has_sole_link($child, $xp)) {
            $link = $xp->query('./a', $child)->item(0);
            $text = trim($link->textContent);
            $href = $link->getAttribute('href');
            if ($text !== '' || $href !== '') {
                $suffix = "link_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => 'Link / Bottone',
                    'type' => 'link',
                    'preview' => ($text ?: '(senza testo)') . ($href ? ' → ' . mb_substr($href, 0, 60) : ''),
                    'suffix' => $suffix,
                ];
                $c++;
            }
            continue;
        }

        // ── Wrapper di solo testo ──
        if (suite_barrio_af_is_text_wrapper($child)) {
            $text = trim($child->textContent);
            if (mb_strlen($text) >= 2) {
                if (suite_barrio_af_has_rich($child)) {
                    $type = 'wysiwyg';
                    $label = 'Testo formattato';
                } elseif (mb_strlen($text) > 200) {
                    $type = 'textarea';
                    $label = 'Testo lungo';
                } else {
                    $type = 'text';
                    $label = 'Testo';
                }
                $suffix = "testo_{$c}";
                $fields[] = [
                    'name' => "{$sid}_{$suffix}",
                    'label' => $label,
                    'type' => $type,
                    'preview' => mb_substr($text, 0, 120),
                    'suffix' => $suffix,
                ];
                $c++;
                continue;
            }
        }

        // ── Container generico → ricorsione ──
        if ($child->childNodes->length > 0) {
            suite_barrio_af_extract($child, $xp, $fields, $c, $sid, $depth + 1);
        }
    }
}

/* ── Helper: aggiungi campo immagine ────────────────────────── */
function suite_barrio_af_add_image(DOMElement $img, array &$fields, int &$c, string $sid): void
{
    $alt = $img->getAttribute('alt');
    $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
    $suffix = "immagine_{$c}";
    $fields[] = [
        'name' => "{$sid}_{$suffix}",
        'label' => 'Immagine' . ($alt ? ' (' . mb_substr($alt, 0, 40) . ')' : ''),
        'type' => 'image',
        'preview' => $src ? mb_substr($src, 0, 80) : '',
        'suffix' => $suffix,
    ];
    $c++;
}

/* ── Helper: è un container swiper/slider? ───────────────────── */
function suite_barrio_af_is_swiper(DOMElement $node): bool
{
    $cls = strtolower($node->getAttribute('class') ?: '');
    $patterns = ['swiper', 'splide', 'slick-slider', 'glide', 'flickity', 'owl-carousel', 'carousel'];
    foreach ($patterns as $p) {
        if (strpos($cls, $p) !== false)
            return true;
    }
    return false;
}

/* ── Helper: trova le slide dentro un container swiper ────────── */
function suite_barrio_af_get_swiper_slides(DOMElement $node, DOMXPath $xp): array
{
    // Cerca .swiper-slide, .splide__slide, .slick-slide, .glide__slide, figli diretti
    $slide_classes = ['swiper-slide', 'splide__slide', 'slick-slide', 'glide__slide', 'carousel-item', 'owl-item'];
    foreach ($slide_classes as $cls) {
        $slides = $xp->query('.//*[contains(@class, "' . $cls . '")]', $node);
        if ($slides->length > 0) {
            return iterator_to_array($slides);
        }
    }
    // Fallback: figli diretti del wrapper
    $wrapper_classes = ['swiper-wrapper', 'splide__list', 'slick-track', 'glide__slides'];
    foreach ($wrapper_classes as $wcls) {
        $wrappers = $xp->query('.//*[contains(@class, "' . $wcls . '")]', $node);
        if ($wrappers->length) {
            $children = [];
            foreach ($wrappers->item(0)->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE)
                    $children[] = $child;
            }
            if (!empty($children))
                return $children;
        }
    }
    return [];
}

/* ── Helper: ogni slide contiene solo un'immagine? ───────────── */
function suite_barrio_af_slides_are_images_only(array $slides, DOMXPath $xp): bool
{
    foreach ($slides as $slide) {
        $imgs = $xp->query('.//img', $slide);
        if ($imgs->length === 0)
            return false;
        // Verifica che non ci sia testo significativo oltre all'immagine
        $text = trim($slide->textContent);
        if (mb_strlen($text) > 5)
            return false;
    }
    return true;
}

/* ── Helper: link con attributi fancybox? ────────────────────── */
function suite_barrio_af_is_fancybox(DOMElement $a): bool
{
    $attrs_to_check = ['data-fancybox', 'data-lightbox', 'data-gallery', 'data-glightbox', 'data-featherlight'];
    foreach ($attrs_to_check as $attr) {
        if ($a->hasAttribute($attr))
            return true;
    }
    $cls = strtolower($a->getAttribute('class') ?: '');
    if (preg_match('/fancybox|lightbox|glightbox|featherlight/', $cls))
        return true;
    return false;
}

/* ── Helper: container con un solo <a> come figlio significativo ── */
function suite_barrio_af_has_sole_link(DOMElement $node, DOMXPath $xp): bool
{
    $elements = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE)
            $elements[] = $child;
        elseif ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) !== '')
            return false;
    }
    if (count($elements) !== 1)
        return false;
    return strtolower($elements[0]->nodeName) === 'a';
}

/* ── Helper: contenuto rich (formattato)? ───────────────────── */
function suite_barrio_af_has_rich(DOMElement $node): bool
{
    $rich = ['strong', 'b', 'em', 'i', 'a', 'br', 'sub', 'sup', 'mark', 'u'];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE && in_array(strtolower($child->nodeName), $rich, true)) {
            return true;
        }
    }
    return false;
}

/* ── Helper: è un bottone/link standalone? ──────────────────── */
function suite_barrio_af_is_button(DOMElement $a): bool
{
    $cls = strtolower($a->getAttribute('class'));
    if (preg_match('/btn|button|cta/', $cls))
        return true;
    $parent = $a->parentNode;
    if ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
        $pt = strtolower($parent->nodeName);
        if (in_array($pt, ['div', 'section', 'article', 'main', 'aside', 'li', 'figure'], true)) {
            return true;
        }
    }
    return false;
}

/* ── Helper: container con pattern ripetuto? ─────────────────── */
function suite_barrio_af_is_repeating(DOMElement $node, DOMXPath $xp): bool
{
    $children = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE)
            $children[] = $child;
    }
    // Richiede più di 3 figli
    if (count($children) <= 3)
        return false;

    $tags = [];
    foreach ($children as $child) {
        $tags[strtolower($child->nodeName)] = ($tags[strtolower($child->nodeName)] ?? 0) + 1;
    }

    arsort($tags);
    $most = array_values($tags)[0];
    $total = count($children);

    if ($most < 3 || $most / $total < 0.6)
        return false;

    // Verifica che i figli ripetuti abbiano sotto-elementi
    $dominant = array_keys($tags)[0];
    $with_ch = 0;
    foreach ($children as $child) {
        if (strtolower($child->nodeName) !== $dominant)
            continue;
        foreach ($child->childNodes as $gc) {
            if ($gc->nodeType === XML_ELEMENT_NODE) {
                $with_ch++;
                break;
            }
        }
    }
    return $with_ch >= 2;
}

/* ── Helper: container flex, grid o con figli absolute? ──────── */
function suite_barrio_af_is_flex_grid(DOMElement $node): bool
{
    // 1. Controlla style inline
    $style = strtolower($node->getAttribute('style') ?: '');
    if (preg_match('/display\s*:\s*(flex|grid|inline-flex|inline-grid)/', $style)) {
        return true;
    }
    if (preg_match('/position\s*:\s*(relative|absolute)/', $style)) {
        return true;
    }

    // 2. Controlla classi CSS comuni (Tailwind, Bootstrap, pattern generici)
    $cls = strtolower($node->getAttribute('class') ?: '');
    $patterns = [
        // Tailwind flex/grid
        'flex',
        'grid',
        'inline-flex',
        'inline-grid',
        // Tailwind position
        'relative',
        // Bootstrap
        'd-flex',
        'd-grid',
        'row',
        'position-relative',
        // Pattern generici
        'grid-cols',
        'grid-rows',
        'flex-wrap',
        'flex-row',
        'flex-col',
        'columns',
        'cards',
        'card-list',
        'card-grid',
        'items-grid',
        'items-list',
        'swiper-wrapper',
        'slick-track',
        'splide__list',
    ];
    foreach ($patterns as $pattern) {
        if (strpos($cls, $pattern) !== false) {
            return true;
        }
    }

    // 3. Figli con position: absolute (slider, stack di elementi sovrapposti)
    $abs_count = 0;
    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE)
            continue;
        $cs = strtolower($child->getAttribute('style') ?: '');
        $cc = strtolower($child->getAttribute('class') ?: '');
        if (preg_match('/position\s*:\s*absolute/', $cs) || strpos($cc, 'absolute') !== false) {
            $abs_count++;
        }
    }
    if ($abs_count >= 3) {
        return true;
    }

    // 4. Per <ul>/<ol> sono intrinsecamente liste → trattali come repeater-ready
    $tag = strtolower($node->nodeName);
    if ($tag === 'ul' || $tag === 'ol') {
        return true;
    }

    return false;
}

/* ── Helper: figli ripetuti (tag dominante) ──────────────────── */
function suite_barrio_af_get_repeating_children(DOMElement $node, DOMXPath $xp): array
{
    $children = [];
    $tags = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE)
            continue;
        $t = strtolower($child->nodeName);
        $tags[$t] = ($tags[$t] ?? 0) + 1;
        $children[] = $child;
    }
    arsort($tags);
    $dominant = array_keys($tags)[0];
    return array_values(array_filter($children, fn($c) => strtolower($c->nodeName) === $dominant));
}

/* ── Helper: wrapper di solo testo? ──────────────────────────── */
function suite_barrio_af_is_text_wrapper(DOMElement $node): bool
{
    $inline = ['span', 'strong', 'b', 'em', 'i', 'a', 'br', 'sub', 'sup', 'mark', 'u', 'small', 'abbr', 'time'];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE)
            continue;
        if ($child->nodeType !== XML_ELEMENT_NODE)
            continue;
        if (!in_array(strtolower($child->nodeName), $inline, true))
            return false;
    }
    return $node->childNodes->length > 0;
}

/* ── Sub-fields da una lista <li> ────────────────────────────── */
function suite_barrio_af_list_sub_fields(DOMNodeList $items, DOMXPath $xp): array
{
    $first = $items->item(0);
    $has_elements = false;
    foreach ($first->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $has_elements = true;
            break;
        }
    }
    if (!$has_elements) {
        return [['name' => 'voce', 'label' => 'Voce', 'type' => suite_barrio_af_has_rich($first) ? 'wysiwyg' : 'text']];
    }
    return suite_barrio_af_repeater_sub_fields($first, $xp);
}

/* ── Sub-fields da un elemento ripetuto ──────────────────────── */
function suite_barrio_af_repeater_sub_fields(DOMElement $item, DOMXPath $xp): array
{
    $sub = [];
    $sc = 1;

    foreach ($item->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE)
            continue;
        $tag = strtolower($child->nodeName);

        if (preg_match('/^h[1-6]$/', $tag)) {
            $sub[] = ['name' => 'titolo', 'label' => 'Titolo', 'type' => 'text'];
        } elseif ($tag === 'img' || $tag === 'picture' || $tag === 'figure') {
            $sub[] = ['name' => 'immagine', 'label' => 'Immagine', 'type' => 'image'];
            if ($tag === 'figure') {
                $cap = $xp->query('.//figcaption', $child);
                if ($cap->length && trim($cap->item(0)->textContent) !== '') {
                    $sub[] = ['name' => 'didascalia', 'label' => 'Didascalia', 'type' => 'text'];
                }
            }
        } elseif ($tag === 'video') {
            $sub[] = ['name' => 'video', 'label' => 'Video (MP4)', 'type' => 'file'];
        } elseif (in_array($tag, ['form', 'input', 'select', 'button', 'fieldset', 'legend', 'label', 'datalist', 'output'], true)) {
            continue; // Ignora elementi form nei sub-fields
        } elseif ($tag === 'p' || suite_barrio_af_is_text_wrapper($child)) {
            $text = trim($child->textContent);
            if (mb_strlen($text) < 2)
                continue;
            $type = suite_barrio_af_has_rich($child) ? 'wysiwyg' : (mb_strlen($text) > 200 ? 'textarea' : 'text');
            $existing = array_column($sub, 'name');
            $name = in_array('testo', $existing, true) ? 'testo_' . $sc : 'testo';
            $sub[] = ['name' => $name, 'label' => 'Testo', 'type' => $type];
            $sc++;
        } elseif ($tag === 'a') {
            $sub[] = ['name' => 'link', 'label' => 'Link', 'type' => 'link'];
        } elseif ($tag === 'ul' || $tag === 'ol') {
            $sub[] = ['name' => 'elenco', 'label' => 'Elenco', 'type' => 'textarea'];
        } else {
            // Ricorsione in container annidati
            $inner = suite_barrio_af_repeater_sub_fields($child, $xp);
            $existing_names = array_column($sub, 'name');
            foreach ($inner as $is) {
                if (!in_array($is['name'], $existing_names, true)) {
                    $sub[] = $is;
                    $existing_names[] = $is['name'];
                }
            }
        }
    }

    // Deduplica per nome
    $unique = [];
    $seen = [];
    foreach ($sub as $s) {
        if (!in_array($s['name'], $seen, true)) {
            $unique[] = $s;
            $seen[] = $s['name'];
        }
    }

    return $unique ?: [['name' => 'voce', 'label' => 'Voce', 'type' => 'text']];
}

/* ═══════════════════════════════════════════════════════════════
   ACF FIELD GROUP BUILDER
   ═══════════════════════════════════════════════════════════════ */

function suite_barrio_af_create_acf_group(string $title, array $sections, string $loc_type, string $loc_val)
{
    $group_key = 'group_af_' . substr(md5($title . microtime(true)), 0, 13);
    $acf_fields = [];
    $count = 0;

    $allowed_types = ['text', 'textarea', 'wysiwyg', 'image', 'gallery', 'link', 'oembed', 'repeater', 'url', 'file'];

    foreach ($sections as $section) {
        $sid = str_replace('-', '_', sanitize_key($section['id'] ?? 'sezione'));
        $slabel = sanitize_text_field($section['label'] ?? 'Sezione');

        // Tab orizzontale
        $acf_fields[] = [
            'key' => 'field_af_' . substr(md5($group_key . '_tab_' . $sid), 0, 13),
            'label' => $slabel,
            'name' => '',
            'type' => 'tab',
            'placement' => 'left',
        ];

        foreach (($section['fields'] ?? []) as $field) {
            $fname = str_replace('-', '_', sanitize_key($field['name'] ?? 'campo_' . $count));
            $flabel = sanitize_text_field($field['label'] ?? 'Campo');
            $ftype = sanitize_key($field['type'] ?? 'text');
            if (!in_array($ftype, $allowed_types, true))
                $ftype = 'text';

            $fkey = 'field_af_' . substr(md5($group_key . '_' . $fname), 0, 13);

            $acf_field = [
                'key' => $fkey,
                'label' => $flabel,
                'name' => $fname,
                'type' => $ftype,
            ];

            switch ($ftype) {
                case 'image':
                    $acf_field['return_format'] = 'array';
                    $acf_field['preview_size'] = 'medium';
                    $acf_field['library'] = 'all';
                    break;
                case 'textarea':
                    $acf_field['rows'] = 4;
                    $acf_field['new_lines'] = 'br';
                    break;
                case 'wysiwyg':
                    $acf_field['tabs'] = 'all';
                    $acf_field['toolbar'] = 'full';
                    $acf_field['media_upload'] = 1;
                    break;
                case 'link':
                    $acf_field['return_format'] = 'array';
                    break;
                case 'file':
                    $acf_field['return_format'] = 'array';
                    $acf_field['library'] = 'all';
                    $acf_field['mime_types'] = 'mp4';
                    break;
                case 'gallery':
                    $acf_field['return_format'] = 'array';
                    $acf_field['library'] = 'all';
                    $acf_field['insert'] = 'append';
                    break;
                case 'repeater':
                    $acf_field['layout'] = 'block';
                    $acf_field['button_label'] = 'Aggiungi elemento';
                    $acf_field['sub_fields'] = [];

                    foreach (($field['sub_fields'] ?? []) as $sf) {
                        $sfname = str_replace('-', '_', sanitize_key($sf['name'] ?? 'sub'));
                        $sftype = sanitize_key($sf['type'] ?? 'text');
                        if (!in_array($sftype, $allowed_types, true))
                            $sftype = 'text';
                        $sfkey = 'field_af_' . substr(md5($group_key . '_' . $fname . '_' . $sfname), 0, 13);

                        $sub_field = [
                            'key' => $sfkey,
                            'label' => sanitize_text_field($sf['label'] ?? 'Campo'),
                            'name' => $sfname,
                            'type' => $sftype,
                        ];

                        if ($sftype === 'image') {
                            $sub_field['return_format'] = 'array';
                            $sub_field['preview_size'] = 'medium';
                        } elseif ($sftype === 'link') {
                            $sub_field['return_format'] = 'array';
                        } elseif ($sftype === 'file') {
                            $sub_field['return_format'] = 'array';
                            $sub_field['mime_types'] = 'mp4';
                        } elseif ($sftype === 'textarea') {
                            $sub_field['rows'] = 3;
                            $sub_field['new_lines'] = 'br';
                        } elseif ($sftype === 'wysiwyg') {
                            $sub_field['tabs'] = 'all';
                            $sub_field['toolbar'] = 'basic';
                        }

                        $acf_field['sub_fields'][] = $sub_field;
                        $count++;
                    }
                    break;
            }

            $acf_fields[] = $acf_field;
            $count++;
        }
    }

    // Location rules
    $valid_loc_types = ['post_type', 'page_template', 'page'];
    if (!in_array($loc_type, $valid_loc_types, true))
        $loc_type = 'post_type';

    $location = [
        [
            ['param' => $loc_type, 'operator' => '==', 'value' => $loc_val ?: 'page'],
        ]
    ];

    $group = [
        'key' => $group_key,
        'title' => $title,
        'fields' => $acf_fields,
        'location' => $location,
        'style' => 'default',
        'position' => 'normal',
        'label_placement' => 'top',
        'active' => true,
        'show_in_rest' => 1,
        'show_in_graphql' => 1,
        'graphql_field_name' => suite_barrio_af_to_camel($title),
    ];

    $imported = acf_import_field_group($group);

    if (!$imported || empty($imported['ID'])) {
        return new WP_Error('acf_import_failed', 'Errore nella creazione del gruppo ACF.');
    }

    return [
        'key' => $group_key,
        'count' => $count,
        'edit_url' => admin_url('post.php?post=' . $imported['ID'] . '&action=edit'),
    ];
}

/* ═══════════════════════════════════════════════════════════════
   GRAPHQL QUERY BUILDER
   ═══════════════════════════════════════════════════════════════ */

function suite_barrio_af_build_graphql(string $group_title, array $sections): string
{
    $gql = suite_barrio_af_to_camel($group_title);
    $lines = [];
    $lines[] = 'query GetPage($uri: String!) {';
    $lines[] = '  pageBy(uri: $uri) {';
    $lines[] = '    title';
    $lines[] = "    $gql {";

    foreach ($sections as $section) {
        foreach (($section['fields'] ?? []) as $field) {
            $fname = suite_barrio_af_to_camel($field['name'] ?? '');
            $ftype = $field['type'] ?? 'text';

            switch ($ftype) {
                case 'image':
                    $lines[] = "      $fname {";
                    $lines[] = '        node {';
                    $lines[] = '          sourceUrl';
                    $lines[] = '          altText';
                    $lines[] = '          mediaDetails {';
                    $lines[] = '            width';
                    $lines[] = '            height';
                    $lines[] = '          }';
                    $lines[] = '        }';
                    $lines[] = '      }';
                    break;
                case 'file':
                    $lines[] = "      $fname {";
                    $lines[] = '        node {';
                    $lines[] = '          sourceUrl';
                    $lines[] = '          mimeType';
                    $lines[] = '          mediaDetails {';
                    $lines[] = '            width';
                    $lines[] = '            height';
                    $lines[] = '          }';
                    $lines[] = '        }';
                    $lines[] = '      }';
                    break;
                case 'gallery':
                    $lines[] = "      $fname {";
                    $lines[] = '        nodes {';
                    $lines[] = '          sourceUrl';
                    $lines[] = '          altText';
                    $lines[] = '          mediaDetails {';
                    $lines[] = '            width';
                    $lines[] = '            height';
                    $lines[] = '          }';
                    $lines[] = '        }';
                    $lines[] = '      }';
                    break;
                case 'link':
                    $lines[] = "      $fname {";
                    $lines[] = '        url';
                    $lines[] = '        title';
                    $lines[] = '        target';
                    $lines[] = '      }';
                    break;
                case 'repeater':
                    $lines[] = "      $fname {";
                    foreach (($field['sub_fields'] ?? []) as $sf) {
                        $sfname = suite_barrio_af_to_camel($sf['name'] ?? '');
                        $sftype = $sf['type'] ?? 'text';
                        if ($sftype === 'image') {
                            $lines[] = "        $sfname {";
                            $lines[] = '          node {';
                            $lines[] = '            sourceUrl';
                            $lines[] = '            altText';
                            $lines[] = '          }';
                            $lines[] = "        }";
                        } elseif ($sftype === 'file') {
                            $lines[] = "        $sfname {";
                            $lines[] = '          node {';
                            $lines[] = '            sourceUrl';
                            $lines[] = '            mimeType';
                            $lines[] = '          }';
                            $lines[] = "        }";
                        } elseif ($sftype === 'link') {
                            $lines[] = "        $sfname {";
                            $lines[] = '          url';
                            $lines[] = '          title';
                            $lines[] = '          target';
                            $lines[] = "        }";
                        } else {
                            $lines[] = "        $sfname";
                        }
                    }
                    $lines[] = '      }';
                    break;
                default:
                    $lines[] = "      $fname";
                    break;
            }
        }
    }

    $lines[] = '    }';
    $lines[] = '  }';
    $lines[] = '}';

    return implode("\n", $lines);
}

/* ── Helper: stringa → camelCase ─────────────────────────────── */
function suite_barrio_af_to_camel(string $str): string
{
    $str = preg_replace('/[^a-zA-Z0-9]+/', ' ', $str);
    $str = ucwords(strtolower(trim($str)));
    $str = str_replace(' ', '', $str);
    return lcfirst($str);
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN PAGE
   ═══════════════════════════════════════════════════════════════ */

function suite_barrio_auto_fields_page()
{
    $acf_active = function_exists('acf_import_field_group');

    $post_types = get_post_types(['public' => true], 'objects');
    ?>
<div id="suite-barrio-af-wrap" class="wrap suite-barrio-wrap">

            <!-- Header -->
            <div class="suite-barrio-header">
                <div class="suite-barrio-header-icon"><span class="dashicons dashicons-layout"></span></div>
                <div>
                    <h1>Suite Barrio <span class="suite-barrio-header-breadcrumb">/ Auto Fields</span></h1>
                    <p class="suite-barrio-header-sub">Analizza una pagina web e genera automaticamente i campi ACF con query GraphQL</p>
                </div>
            </div>
            <hr class="suite-barrio-divider">

            <?php if (!$acf_active): ?>
                    <div class="suite-barrio-notice suite-barrio-notice-error">
                        <span class="dashicons dashicons-warning"></span>
                        <div>
                            <strong>ACF non rilevato.</strong>
                            Installa e attiva <a
                                href="<?php echo esc_url(admin_url('plugin-install.php?s=advanced+custom+fields&tab=search')); ?>">Advanced
                                Custom Fields</a> per utilizzare questa funzionalità.
                        </div>
                    </div>
            <?php endif; ?>

            <!-- Step 1: URL -->
            <div class="suite-barrio-af-step">
                <div class="suite-barrio-af-step-head">
                    <div class="suite-barrio-af-step-num">1</div>
                    <h2 class="suite-barrio-af-step-title">Analizza pagina</h2>
                </div>
                <p class="suite-barrio-af-step-desc">Inserisci l'URL della pagina web da analizzare. Verranno rilevate automaticamente
                    sezioni, testi, immagini e repeater.</p>
                <div class="suite-barrio-url-row">
                    <input type="url" id="suite-barrio-af-url" class="suite-barrio-input suite-barrio-input-url"
                        placeholder="https://www.example.com/pagina">
                    <button id="suite-barrio-af-analyze" class="suite-barrio-btn suite-barrio-btn-primary" <?php echo $acf_active ? '' : 'disabled'; ?>>
                        <span class="dashicons dashicons-search"></span>
                        Analizza
                    </button>
                </div>
                <div id="suite-barrio-af-analyze-status" class="suite-barrio-af-status"></div>
            </div>

            <!-- Step 2: Preview campi -->
            <div id="suite-barrio-af-preview" class="suite-barrio-af-step" style="display:none;">
                <div class="suite-barrio-af-step-head">
                    <div class="suite-barrio-af-step-num">2</div>
                    <h2 class="suite-barrio-af-step-title">Anteprima campi rilevati</h2>
                </div>
                <p class="suite-barrio-af-step-desc">Modifica nomi, tipi ed etichette. Rimuovi i campi non necessari. I campi sono
                    raggruppati per sezione (= scheda ACF).</p>
                <div id="suite-barrio-af-sections"></div>
            </div>

            <!-- Step 3: Genera -->
            <div id="suite-barrio-af-generate-section" class="suite-barrio-af-step" style="display:none;">
                <div class="suite-barrio-af-step-head">
                    <div class="suite-barrio-af-step-num">3</div>
                    <h2 class="suite-barrio-af-step-title">Genera gruppo campi ACF</h2>
                </div>
                <p class="suite-barrio-af-step-desc">Assegna un nome al gruppo e scegli a quale contenuto applicarlo.</p>

                <div class="suite-barrio-af-gen-grid">
                    <div>
                        <label class="suite-barrio-field-label" for="suite-barrio-af-group-title">Nome gruppo ACF</label>
                        <input type="text" id="suite-barrio-af-group-title" class="suite-barrio-input" style="width:100%;"
                            placeholder="Es. Homepage, Chi Siamo, Servizi…">
                    </div>
                    <div>
                        <label class="suite-barrio-field-label">Assegna a</label>
                        <div class="suite-barrio-loc-row">
                            <select id="suite-barrio-af-loc-type" class="suite-barrio-select">
                                <option value="post_type">Tipo di contenuto</option>
                                <option value="page_template">Template pagina</option>
                                <option value="page">Pagina specifica</option>
                            </select>
                            <select id="suite-barrio-af-loc-value" class="suite-barrio-select">
                                <?php foreach ($post_types as $pt): ?>
                                        <option value="<?php echo esc_attr($pt->name); ?>"><?php echo esc_html($pt->label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <button id="suite-barrio-af-generate" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-btn-lg">
                    <span class="dashicons dashicons-hammer"></span>
                    Genera campi ACF + Query GraphQL
                </button>
                <div id="suite-barrio-af-generate-status" class="suite-barrio-af-status"></div>
            </div>

            <!-- Step 4: Risultato -->
            <div id="suite-barrio-af-result" class="suite-barrio-af-step" style="display:none;">
                <div class="suite-barrio-af-step-head">
                    <div class="suite-barrio-af-step-num" style="background:#059669;">✓</div>
                    <h2 class="suite-barrio-af-step-title">Risultato</h2>
                </div>
                <div id="suite-barrio-af-result-info" style="margin-top:4px;"></div>
                <div class="suite-barrio-gql-wrap">
                    <label class="suite-barrio-field-label" style="margin-top:16px;display:block;">Query GraphQL</label>
                    <pre id="suite-barrio-af-graphql" class="suite-barrio-gql-pre"></pre>
                    <button id="suite-barrio-af-copy" class="suite-barrio-gql-copy">Copia</button>
                </div>
            </div>

        </div><!-- #suite-barrio-af-wrap -->

        <?php
}

/* ═══════════════════════════════════════════════════════════════
   ENQUEUE — JS pagina Auto Fields
   ═══════════════════════════════════════════════════════════════ */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'suite-barrio_page_suite-barrio-auto-fields')
        return;

    $post_types = get_post_types(['public' => true], 'objects');
    $templates = wp_get_theme()->get_page_templates();
    $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);

    $loc_options = [
        'post_type' => [],
        'page_template' => [['value' => 'default', 'label' => 'Default']],
        'page' => [],
    ];
    foreach ($post_types as $pt)
        $loc_options['post_type'][] = ['value' => $pt->name, 'label' => $pt->label];
    foreach ($templates as $f => $n)
        $loc_options['page_template'][] = ['value' => $f, 'label' => $n];
    foreach ($pages as $p)
        $loc_options['page'][] = ['value' => (string) $p->ID, 'label' => $p->post_title];

    wp_register_script('suite-barrio-auto-fields-admin', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-auto-fields-admin');

    $suite_barrio_af_js = '            (function () {
                \'use strict\';

                var NONCE = SUITE_BARRIO_AF_NONCE;
                var AJAX_URL = SUITE_BARRIO_AF_AJAX_URL;
                var LOC_OPTS = SUITE_BARRIO_AF_LOC_OPTS;

                var state = { sections: [] };

                var $url = document.getElementById(\'suite-barrio-af-url\');
                var $analyze = document.getElementById(\'suite-barrio-af-analyze\');
                var $preview = document.getElementById(\'suite-barrio-af-preview\');
                var $sections = document.getElementById(\'suite-barrio-af-sections\');
                var $genSec = document.getElementById(\'suite-barrio-af-generate-section\');
                var $generate = document.getElementById(\'suite-barrio-af-generate\');
                var $result = document.getElementById(\'suite-barrio-af-result\');
                var $locType = document.getElementById(\'suite-barrio-af-loc-type\');
                var $locVal = document.getElementById(\'suite-barrio-af-loc-value\');

                var TYPES = [
                    [\'text\', \'Testo\'],
                    [\'textarea\', \'Area di testo\'],
                    [\'wysiwyg\', \'Editor WYSIWYG\'],
                    [\'image\', \'Immagine\'],
                    [\'gallery\', \'Galleria\'],
                    [\'file\', \'File (Video MP4)\'],
                    [\'link\', \'Link\'],
                    [\'oembed\', \'oEmbed / Video\'],
                    [\'repeater\', \'Ripetitore\']
                ];

                var SUB_TYPES = [
                    [\'text\', \'Testo\'],
                    [\'textarea\', \'Area di testo\'],
                    [\'wysiwyg\', \'WYSIWYG\'],
                    [\'image\', \'Immagine\'],
                    [\'gallery\', \'Galleria\'],
                    [\'file\', \'File (Video MP4)\'],
                    [\'link\', \'Link\']
                ];

                /* ── Analizza ─────────────────────────────── */
                $analyze.addEventListener(\'click\', function () {
                    var url = $url.value.trim();
                    if (!url) { showStatus(\'suite-barrio-af-analyze-status\', \'Inserisci un URL valido.\', \'warning\'); return; }

                    $analyze.disabled = true;
                    $analyze.innerHTML = \'<div class="suite-barrio-spinner"></div> Analisi in corso…\';
                    hideStatus(\'suite-barrio-af-analyze-status\');

                    var fd = new FormData();
                    fd.append(\'action\', \'suite_barrio_auto_fields_analyze\');
                    fd.append(\'nonce\', NONCE);
                    fd.append(\'url\', url);

                    fetch(AJAX_URL, { method: \'POST\', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            if (json.success) {
                                state.sections = json.data.sections;
                                renderPreview();
                                $preview.style.display = \'\';
                                $genSec.style.display = \'\';
                                $result.style.display = \'none\';
                                showStatus(\'suite-barrio-af-analyze-status\',
                                    \'Trovate <strong>\' + state.sections.length + \' sezioni</strong> con <strong>\' +
                                    countFields() + \' campi</strong> totali.\', \'success\');
                            } else {
                                showStatus(\'suite-barrio-af-analyze-status\', json.data || \'Errore durante l\\\'analisi.\', \'error\');
                            }
                        })
                        .catch(function () { showStatus(\'suite-barrio-af-analyze-status\', \'Errore di rete.\', \'error\'); })
                        .finally(function () {
                            $analyze.disabled = false;
                            $analyze.innerHTML = \'<span class="dashicons dashicons-search"></span> Analizza\';
                        });
                });

                /* ── Render preview ──────────────────────── */
                function renderPreview() {
                    $sections.innerHTML = \'\';
                    state.sections.forEach(function (section, si) {
                        var card = document.createElement(\'div\');
                        card.className = \'suite-barrio-af-section-card\';
                        card.innerHTML =
                            \'<div class="suite-barrio-af-section-header">\' +
                            \'<input type="text" value="\' + esc(section.label) + \'" class="suite-barrio-af-section-label" data-si="\' + si + \'">\' +
                            \'<span class="suite-barrio-af-section-badge">\' + section.fields.length + \' campi</span>\' +
                            \'</div>\' +
                            \'<div class="suite-barrio-af-fields-list">\' +
                            renderInsertBtn(si, 0) +
                            section.fields.map(function (f, fi) {
                                return renderField(f, si, fi) + renderInsertBtn(si, fi + 1);
                            }).join(\'\') +
                            \'</div>\';
                        $sections.appendChild(card);
                    });

                    document.querySelectorAll(\'.suite-barrio-af-section-label\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var si = parseInt(this.dataset.si);
                            var newLabel = this.value;
                            var newId = toSnakeCase(newLabel);
                            var section = state.sections[si];
                            section.label = newLabel;
                            section.id = newId;
                            section.fields.forEach(function (f) {
                                if (f.suffix) f.name = newId + \'_\' + f.suffix;
                            });
                            renderPreview();
                        });
                    });

                    bindFieldEvents();
                }

                function renderInsertBtn(si, pos) {
                    return \'<div class="suite-barrio-af-insert-row">\' +
                        \'<button class="suite-barrio-af-insert-btn" data-si="\' + si + \'" data-pos="\' + pos + \'">+ Aggiungi campo</button>\' +
                        \'</div>\';
                }

                function renderField(field, si, fi) {
                    var typeOpts = TYPES.map(function (t) {
                        return \'<option value="\' + t[0] + \'"\' + (t[0] === field.type ? \' selected\' : \'\') + \'>\' + t[1] + \'</option>\';
                    }).join(\'\');

                    return \'<div class="suite-barrio-af-field" data-si="\' + si + \'" data-fi="\' + fi + \'">\' +
                        \'<div class="suite-barrio-af-field-row">\' +
                        \'<select class="suite-barrio-af-ftype">\' + typeOpts + \'</select>\' +
                        \'<input type="text" value="\' + esc(field.label) + \'" class="suite-barrio-af-flabel" placeholder="Etichetta">\' +
                        \'<input type="text" value="\' + esc(field.name) + \'" class="suite-barrio-af-fname suite-barrio-af-field-name" placeholder="Nome campo">\' +
                        \'<button class="suite-barrio-af-remove" title="Rimuovi">\' +
                        \'<span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span>\' +
                        \'</button>\' +
                        \'</div>\' +
                        (field.preview ? \'<div class="suite-barrio-af-preview">\' + esc(field.preview) + \'</div>\' : \'\') +
                        (field.type === \'repeater\' && field.sub_fields ? renderSubFields(field.sub_fields, si, fi) : \'\') +
                        \'</div>\';
                }

                function renderSubFields(subFields, si, fi) {
                    var html = \'<div class="suite-barrio-af-sub-fields">\' +
                        \'<div class="suite-barrio-af-sub-label">Sub-campi ripetitore</div>\';
                    subFields.forEach(function (sf, sfi) {
                        var typeOpts = SUB_TYPES.map(function (t) {
                            return \'<option value="\' + t[0] + \'"\' + (t[0] === sf.type ? \' selected\' : \'\') + \'>\' + t[1] + \'</option>\';
                        }).join(\'\');
                        html += \'<div class="suite-barrio-af-sub-field" data-si="\' + si + \'" data-fi="\' + fi + \'" data-sfi="\' + sfi + \'">\' +
                            \'<select class="suite-barrio-af-sftype">\' + typeOpts + \'</select>\' +
                            \'<input type="text" value="\' + esc(sf.label) + \'" class="suite-barrio-af-sflabel" placeholder="Etichetta">\' +
                            \'<input type="text" value="\' + esc(sf.name) + \'" class="suite-barrio-af-sfname suite-barrio-af-field-name" placeholder="Nome">\' +
                            \'</div>\';
                    });
                    return html + \'</div>\';
                }

                /* ── Bind eventi campi ────────────────────── */
                function bindFieldEvents() {
                    document.querySelectorAll(\'.suite-barrio-af-insert-btn\').forEach(function (el) {
                        el.addEventListener(\'click\', function () {
                            var si = parseInt(this.dataset.si), pos = parseInt(this.dataset.pos);
                            var section = state.sections[si];
                            var nextNum = section.fields.length + 1;
                            var suffix = \'campo_\' + nextNum;
                            section.fields.splice(pos, 0, { name: section.id + \'_\' + suffix, label: \'Nuovo campo\', type: \'text\', preview: \'\', suffix: suffix });
                            renderPreview();
                        });
                    });

                    document.querySelectorAll(\'.suite-barrio-af-ftype\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var f = this.closest(\'.suite-barrio-af-field\');
                            var si = parseInt(f.dataset.si), fi = parseInt(f.dataset.fi);
                            state.sections[si].fields[fi].type = this.value;
                            if (this.value === \'repeater\' && !state.sections[si].fields[fi].sub_fields) {
                                state.sections[si].fields[fi].sub_fields = [{ name: \'voce\', label: \'Voce\', type: \'text\' }];
                            }
                            renderPreview();
                        });
                    });

                    document.querySelectorAll(\'.suite-barrio-af-flabel\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var f = this.closest(\'.suite-barrio-af-field\');
                            var si = parseInt(f.dataset.si), fi = parseInt(f.dataset.fi);
                            var field = state.sections[si].fields[fi];
                            field.label = this.value;
                            var newSuffix = toSnakeCase(this.value);
                            field.suffix = newSuffix;
                            field.name = state.sections[si].id + \'_\' + newSuffix;
                            var nameInput = f.querySelector(\'.suite-barrio-af-fname\');
                            if (nameInput) nameInput.value = field.name;
                        });
                    });

                    document.querySelectorAll(\'.suite-barrio-af-fname\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var f = this.closest(\'.suite-barrio-af-field\');
                            var si = parseInt(f.dataset.si), fi = parseInt(f.dataset.fi);
                            state.sections[si].fields[fi].name = this.value;
                            state.sections[si].fields[fi].suffix = null;
                        });
                    });

                    document.querySelectorAll(\'.suite-barrio-af-remove\').forEach(function (el) {
                        el.addEventListener(\'click\', function () {
                            var f = this.closest(\'.suite-barrio-af-field\');
                            var si = parseInt(f.dataset.si), fi = parseInt(f.dataset.fi);
                            state.sections[si].fields.splice(fi, 1);
                            renderPreview();
                        });
                    });

                    document.querySelectorAll(\'.suite-barrio-af-sftype\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var sf = this.closest(\'.suite-barrio-af-sub-field\');
                            state.sections[parseInt(sf.dataset.si)].fields[parseInt(sf.dataset.fi)].sub_fields[parseInt(sf.dataset.sfi)].type = this.value;
                        });
                    });
                    document.querySelectorAll(\'.suite-barrio-af-sflabel\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var sf = this.closest(\'.suite-barrio-af-sub-field\');
                            var sub = state.sections[parseInt(sf.dataset.si)].fields[parseInt(sf.dataset.fi)].sub_fields[parseInt(sf.dataset.sfi)];
                            sub.label = this.value;
                            sub.name = toSnakeCase(this.value);
                            var nameInput = sf.querySelector(\'.suite-barrio-af-sfname\');
                            if (nameInput) nameInput.value = sub.name;
                        });
                    });
                    document.querySelectorAll(\'.suite-barrio-af-sfname\').forEach(function (el) {
                        el.addEventListener(\'change\', function () {
                            var sf = this.closest(\'.suite-barrio-af-sub-field\');
                            state.sections[parseInt(sf.dataset.si)].fields[parseInt(sf.dataset.fi)].sub_fields[parseInt(sf.dataset.sfi)].name = this.value;
                        });
                    });
                }

                /* ── Genera ───────────────────────────────── */
                $generate.addEventListener(\'click\', function () {
                    var title = document.getElementById(\'suite-barrio-af-group-title\').value.trim();
                    if (!title) { showStatus(\'suite-barrio-af-generate-status\', \'Inserisci un nome per il gruppo ACF.\', \'warning\'); return; }

                    $generate.disabled = true;
                    $generate.innerHTML = \'<div class="suite-barrio-spinner"></div> Generazione in corso…\';
                    hideStatus(\'suite-barrio-af-generate-status\');

                    var fd = new FormData();
                    fd.append(\'action\', \'suite_barrio_auto_fields_generate\');
                    fd.append(\'nonce\', NONCE);
                    fd.append(\'group_title\', title);
                    fd.append(\'sections\', JSON.stringify(state.sections));
                    fd.append(\'location_type\', $locType.value);
                    fd.append(\'location_value\', $locVal.value);

                    fetch(AJAX_URL, { method: \'POST\', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            if (json.success) {
                                $result.style.display = \'\';
                                document.getElementById(\'suite-barrio-af-result-info\').innerHTML =
                                    \'<div class="suite-barrio-notice suite-barrio-notice-success" style="margin-bottom:0;">\' +
                                    \'<span class="dashicons dashicons-yes-alt"></span>\' +
                                    \'<div><strong>\' + esc(json.data.message) + \'</strong><br>\' +
                                    json.data.field_count + \' campi creati. \' +
                                    \'<a href="\' + esc(json.data.edit_url) + \'" target="_blank" style="font-weight:600;">Modifica in ACF &rarr;</a>\' +
                                    \'</div></div>\';
                                document.getElementById(\'suite-barrio-af-graphql\').textContent = json.data.graphql;
                                $result.scrollIntoView({ behavior: \'smooth\' });
                            } else {
                                showStatus(\'suite-barrio-af-generate-status\', json.data || \'Errore durante la generazione.\', \'error\');
                            }
                        })
                        .catch(function () { showStatus(\'suite-barrio-af-generate-status\', \'Errore di rete.\', \'error\'); })
                        .finally(function () {
                            $generate.disabled = false;
                            $generate.innerHTML = \'<span class="dashicons dashicons-hammer"></span> Genera campi ACF + Query GraphQL\';
                        });
                });

                /* ── Copia GraphQL ────────────────────────── */
                document.getElementById(\'suite-barrio-af-copy\').addEventListener(\'click\', function () {
                    var btn = this;
                    navigator.clipboard.writeText(document.getElementById(\'suite-barrio-af-graphql\').textContent).then(function () {
                        btn.textContent = \'✓ Copiato!\';
                        setTimeout(function () { btn.textContent = \'Copia\'; }, 2000);
                    });
                });

                /* ── Location type ───────────────────────── */
                $locType.addEventListener(\'change\', function () { updateLocOptions(this.value); });

                function updateLocOptions(type) {
                    var opts = LOC_OPTS[type] || [];
                    $locVal.innerHTML = \'\';
                    opts.forEach(function (o) {
                        var opt = document.createElement(\'option\');
                        opt.value = o.value;
                        opt.textContent = o.label;
                        $locVal.appendChild(opt);
                    });
                }

                /* ── Enter key on URL ────────────────────── */
                $url.addEventListener(\'keydown\', function (e) { if (e.key === \'Enter\') { e.preventDefault(); $analyze.click(); } });

                /* ── Helpers ─────────────────────────────── */
                function countFields() {
                    var n = 0;
                    state.sections.forEach(function (s) { n += s.fields.length; });
                    return n;
                }

                function showStatus(id, msg, type) {
                    var el = document.getElementById(id);
                    el.style.display = \'\';
                    el.className = \'suite-barrio-notice suite-barrio-notice-\' + (type || \'info\') + \' suite-barrio-af-status\';
                    var icon = { success: \'dashicons-yes-alt\', error: \'dashicons-warning\', warning: \'dashicons-warning\', info: \'dashicons-info\' };
                    el.innerHTML = \'<span class="dashicons \' + (icon[type] || \'dashicons-info\') + \'"></span><div>\' + msg + \'</div>\';
                }

                function hideStatus(id) {
                    document.getElementById(id).style.display = \'none\';
                }

                function esc(str) {
                    if (!str) return \'\';
                    var d = document.createElement(\'div\');
                    d.appendChild(document.createTextNode(String(str)));
                    return d.innerHTML;
                }

                function toSnakeCase(str) {
                    return String(str)
                        .normalize(\'NFD\').replace(/[\\u0300-\\u036f]/g, \'\')
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, \'_\')
                        .replace(/^_+|_+$/g, \'\')
                        .replace(/_+/g, \'_\');
                }

            })();';

    $suite_barrio_af_js = str_replace(
        ['SUITE_BARRIO_AF_NONCE', 'SUITE_BARRIO_AF_AJAX_URL', 'SUITE_BARRIO_AF_LOC_OPTS'],
        [
            wp_json_encode(wp_create_nonce('suite_barrio_auto_fields_nonce')),
            wp_json_encode(admin_url('admin-ajax.php')),
            wp_json_encode($loc_options),
        ],
        $suite_barrio_af_js
    );

    wp_add_inline_script('suite-barrio-auto-fields-admin', $suite_barrio_af_js);
});