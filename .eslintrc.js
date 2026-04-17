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
};
