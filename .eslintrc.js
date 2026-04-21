module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	env: {
		browser: true,
		es2021: true,
	},
	globals: {
		wp: 'readonly',
		dsgvoFormAdmin: 'readonly',
	},
	overrides: [
		{
			files: [ '**/test/**/*.js', '**/*.test.js' ],
			env: {
				jest: true,
			},
		},
	],
};
