(function () {
    'use strict';

    var hooks   = wp.hooks;
    var compose = wp.compose;
    var el      = wp.element.createElement;

    /*
     * Rimuove H1 dalle opzioni di livello heading (WP 6.4+ legge levelOptions
     * dall'attributo del blocco per popolare la HeadingLevelDropdown toolbar).
     */
    hooks.addFilter(
        'blocks.registerBlockType',
        'wpb/no-h1-levels',
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
        'wpb/no-h1-coerce',
        compose.createHigherOrderComponent(function (BlockEdit) {
            return function (props) {
                if (props.name === 'core/heading' && props.attributes.level === 1) {
                    props.setAttributes({ level: 2 });
                }
                return el(BlockEdit, props);
            };
        }, 'wpbNoH1Coerce')
    );

})();
