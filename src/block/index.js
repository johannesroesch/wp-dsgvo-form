/**
 * DSGVO Form Gutenberg Block — Registration.
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import Save from './save';

// Styles — compiled by webpack into build/block/index.css.
import './frontend.scss';

// Note: editor.scss is loaded via "editorStyle" in block.json, not imported here.

registerBlockType( metadata, {
	edit: Edit,
	save: Save,
} );
