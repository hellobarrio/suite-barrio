(function () {
    'use strict';

    var hooks   = wp.hooks;
    var compose = wp.compose;
    var el      = wp.element.createElement;

    /*
     * Rimuove i pannelli Revisioni, Autore e Attributi della pagina dalla sidebar
     * del block editor per i non-admin.
     * - Author e Page Attributes spariscono anche via PHP (remove_post_type_support),
     *   questo garantisce la copertura anche per post type non-public o edge case.
     * - Revisioni: il pannello usa PanelBody diretto, non PluginDocumentSettingPanel,
     *   quindi removeEditorPanel è l'unico modo affidabile.
     */
    wp.domReady(function () {
        var dispatch = wp.data && wp.data.dispatch('core/edit-post');
        if (!dispatch || !dispatch.removeEditorPanel) return;
        dispatch.removeEditorPanel('post-revisions');
        dispatch.removeEditorPanel('post-author/panel');
        dispatch.removeEditorPanel('page-attributes');
    });

    var TEXT_BLOCKS = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/list-item',
        'core/quote',
        'core/pullquote',
        'core/verse',
        'core/preformatted',
        'core/code',
    ];

    /*
     * Rimuove i pannelli Colore, Tipografia e Avanzate dalla sidebar
     * per tutti i blocchi testuali (non-admin).
     * - color: false       → nasconde il pannello Colore
     * - typography: false  → nasconde il pannello Tipografia
     * - customClassName + anchor: false → svuota il pannello Avanzate (sparisce)
     */
    hooks.addFilter(
        'blocks.registerBlockType',
        'suite-barrio/remove-text-panels',
        function (settings, name) {
            if (TEXT_BLOCKS.indexOf(name) === -1) return settings;
            return Object.assign({}, settings, {
                supports: Object.assign({}, settings.supports || {}, {
                    color:           false,
                    typography:      false,
                    customClassName: false,
                    anchor:          false,
                })
            });
        }
    );

    /*
     * Rimuove H1 dalle opzioni di livello heading (WP 6.4+ legge levelOptions
     * dall'attributo del blocco per popolare la HeadingLevelDropdown toolbar).
     */
    hooks.addFilter(
        'blocks.registerBlockType',
        'suite-barrio/no-h1-levels',
        function (settings, name) {
            if (name !== 'core/heading') return settings;
            return Object.assign({}, settings, {
                attributes: Object.assign({}, settings.attributes, {
                    levelOptions: {
                        type: 'array',
                        default: [2, 3, 4, 5, 6]
                    }
                })
            });
        }
    );

    /*
     * HOC di sicurezza: se un blocco H1 è già salvato nel contenuto,
     * lo porta automaticamente a H2 all'apertura dell'editor.
     */
    hooks.addFilter(
        'editor.BlockEdit',
        'suite-barrio/no-h1-coerce',
        compose.createHigherOrderComponent(function (BlockEdit) {
            return function (props) {
                if (props.name === 'core/heading' && props.attributes.level === 1) {
                    props.setAttributes({ level: 2 });
                }
                return el(BlockEdit, props);
            };
        }, 'suiteBarrioNoH1Coerce')
    );

})();
