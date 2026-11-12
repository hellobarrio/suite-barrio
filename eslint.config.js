// La configurazione di @wordpress/scripts, più i pacchetti @wordpress/* usati dalle pagine React:
// li fornisce WordPress (wp.components, wp.apiFetch…), la build non li include e non si installano.
import config from '@wordpress/scripts/config/eslint.config.cjs';

export default [
	...config,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/components',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/media-utils',
			],
		},
	},
];
