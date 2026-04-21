const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	transformIgnorePatterns: [ 'node_modules/(?!(parsel-js)/)' ],
	moduleNameMapper: {
		...( defaultConfig.moduleNameMapper || {} ),
		'\\.(scss|css)$': '<rootDir>/src/block/test/__mocks__/style-mock.js',
		'@wordpress/server-side-render':
			'<rootDir>/src/block/test/__mocks__/@wordpress/server-side-render.js',
	},
};
