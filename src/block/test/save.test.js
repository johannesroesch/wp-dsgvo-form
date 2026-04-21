/**
 * Tests for Save component.
 *
 * @package
 */

import Save from '../save';

describe( 'Save component', () => {
	it( 'returns null (dynamic server-side block)', () => {
		expect( Save() ).toBeNull();
	} );
} );
