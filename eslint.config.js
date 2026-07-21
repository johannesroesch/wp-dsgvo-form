const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	// Extend ignores from wp-scripts base config, adding captcha.min.js
	{
		ignores: [
			'build/**',
			'node_modules/**',
			'vendor/**',
			'public/js/captcha.min.js',
		],
	},
	// Spread all wp-scripts rules (skip the first ignores entry, replaced above)
	...wpScriptsConfig.slice( 1 ),
	// Project-specific globals
	{
		languageOptions: {
			globals: {
				wp: 'readonly',
				dsgvoFormAdmin: 'readonly',
			},
		},
	},
];
