const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'block/index': './src/block/index.js',
		'block/editor': './src/block/editor.scss',
		'admin/submissions': './src/admin/submissions/SubmissionList.js',
		'frontend/form-handler': './src/frontend/form-handler.js',
	},
};
