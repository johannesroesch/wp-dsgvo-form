/**
 * Tests for block registration (index.js).
 *
 * @package
 */

import { registerBlockType } from '@wordpress/blocks';
import metadata from '../block.json';

// Mock all heavy WordPress packages to avoid ESM import issues.
jest.mock( '@wordpress/blocks', () => ( {
	registerBlockType: jest.fn(),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: () => ( {} ),
	InspectorControls: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	SelectControl: () => null,
	Placeholder: () => null,
	Spinner: () => null,
	ExternalLink: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/element', () => ( {
	useEffect: jest.fn(),
	useState: jest.fn( ( init ) => [ init, jest.fn() ] ),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

describe( 'Block registration', () => {
	beforeAll( () => {
		require( '../index' );
	} );

	it( 'calls registerBlockType with block.json metadata', () => {
		expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
		expect( registerBlockType ).toHaveBeenCalledWith(
			metadata,
			expect.objectContaining( {
				edit: expect.any( Function ),
				save: expect.any( Function ),
			} )
		);
	} );

	it( 'uses correct block name from block.json', () => {
		expect( metadata.name ).toBe( 'dsgvo-form/form' );
	} );

	it( 'block.json has formId attribute with default 0', () => {
		expect( metadata.attributes.formId ).toEqual( {
			type: 'number',
			default: 0,
		} );
	} );

	it( 'block.json supports wide and full alignment', () => {
		expect( metadata.supports.align ).toEqual( [ 'wide', 'full' ] );
	} );

	it( 'block.json disables raw HTML', () => {
		expect( metadata.supports.html ).toBe( false );
	} );
} );
