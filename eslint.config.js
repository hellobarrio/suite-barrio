// La configurazione di @wordpress/scripts, più i pacchetti @wordpress/* usati dalle pagine React:
// li fornisce WordPress (wp.components, wp.apiFetch…), la build non li include e non si installano.
import config from '@wordpress/scripts/config/eslint.config.cjs';

export default [
	...config,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/a11y',
				'@wordpress/api-fetch',
				'@wordpress/components',
				'@wordpress/core-data',
				'@wordpress/data',
				'@wordpress/editor',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/media-utils',
				'@wordpress/plugins',
			],
		},
	},
];
