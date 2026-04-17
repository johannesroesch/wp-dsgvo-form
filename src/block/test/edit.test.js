/**
 * Tests for Edit component.
 *
 * @package wp-dsgvo-form
 */

import { render, screen, waitFor, act } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import Edit from '../edit';

// Mock WordPress packages.
jest.mock( '@wordpress/api-fetch' );

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: () => ( { className: 'wp-block-dsgvo-form' } ),
	InspectorControls: ( { children } ) => (
		<div data-testid="inspector">{ children }</div>
	),
} ) );

jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children, title } ) => (
		<div data-testid="panel-body">{ children }</div>
	),
	SelectControl: ( { label, value, options, onChange } ) => (
		<select
			data-testid="select-control"
			aria-label={ label }
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
		>
			{ options.map( ( opt ) => (
				<option key={ opt.value } value={ opt.value }>
					{ opt.label }
				</option>
			) ) }
		</select>
	),
	Placeholder: ( { children, label, instructions, icon } ) => (
		<div data-testid="placeholder">
			{ label && <span>{ label }</span> }
			{ instructions && <span>{ instructions }</span> }
			{ children }
		</div>
	),
	Spinner: () => <div data-testid="spinner">Loading...</div>,
	ExternalLink: ( { children, href } ) => (
		<a href={ href } data-testid="external-link">
			{ children }
		</a>
	),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

describe( 'Edit component', () => {
	const defaultProps = {
		attributes: { formId: 0 },
		setAttributes: jest.fn(),
	};

	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'shows spinner while loading forms', async () => {
		apiFetch.mockReturnValue( new Promise( () => {} ) );

		await act( async () => {
			render( <Edit { ...defaultProps } /> );
		} );

		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'shows placeholder when no form is selected after loading', async () => {
		apiFetch.mockResolvedValue( [
			{ id: 1, title: 'Kontakt' },
			{ id: 2, title: 'Anmeldung' },
		] );

		await act( async () => {
			render( <Edit { ...defaultProps } /> );
		} );

		await waitFor( () => {
			expect(
				screen.getByText(
					'Bitte waehlen Sie ein Formular aus der Seitenleiste.'
				)
			).toBeInTheDocument();
		} );
	} );

	it( 'renders ServerSideRender when formId is selected', async () => {
		apiFetch.mockResolvedValue( [ { id: 5, title: 'Test' } ] );

		const props = {
			...defaultProps,
			attributes: { formId: 5 },
		};

		await act( async () => {
			render( <Edit { ...props } /> );
		} );

		// ServerSideRender is mocked to return null — verify no placeholder shown.
		await waitFor( () => {
			const placeholders = screen.queryAllByTestId( 'placeholder' );
			// When formId > 0 and no error, no Placeholder should render.
			expect( placeholders.length ).toBe( 0 );
		} );
	} );

	it( 'shows error message when API fetch fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'Netzwerkfehler' ) );

		await act( async () => {
			render( <Edit { ...defaultProps } /> );
		} );

		await waitFor( () => {
			expect( screen.getByText( 'Netzwerkfehler' ) ).toBeInTheDocument();
		} );
	} );

	it( 'fetches forms from /dsgvo-form/v1/forms endpoint', async () => {
		apiFetch.mockResolvedValue( [] );

		await act( async () => {
			render( <Edit { ...defaultProps } /> );
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/dsgvo-form/v1/forms',
		} );
	} );

	// ──────────────────────────────────────────────────
	// Edit-Link URL tests (Bug-Fix: UX-FINDING-02, Task #187)
	// ──────────────────────────────────────────────────

	describe( 'edit form link', () => {
		afterEach( () => {
			delete window.dsgvoFormAdmin;
		} );

		it( 'shows edit link with correct URL when form is selected', async () => {
			window.dsgvoFormAdmin = { adminUrl: 'https://example.com/wp-admin/' };
			apiFetch.mockResolvedValue( [ { id: 7, title: 'Kontakt' } ] );

			const props = {
				...defaultProps,
				attributes: { formId: 7 },
			};

			await act( async () => {
				render( <Edit { ...props } /> );
			} );

			await waitFor( () => {
				const link = screen.getByTestId( 'external-link' );
				expect( link ).toBeInTheDocument();
				expect( link ).toHaveAttribute(
					'href',
					'https://example.com/wp-admin/admin.php?page=dsgvo-form&action=edit&form_id=7'
				);
				expect( link ).toHaveTextContent( 'Im Admin bearbeiten' );
			} );
		} );

		it( 'does not show edit link when no form is selected', async () => {
			apiFetch.mockResolvedValue( [ { id: 1, title: 'Test' } ] );

			await act( async () => {
				render( <Edit { ...defaultProps } /> );
			} );

			await waitFor( () => {
				expect( screen.queryByTestId( 'external-link' ) ).not.toBeInTheDocument();
			} );
		} );

		it( 'uses /wp-admin/ fallback when dsgvoFormAdmin is not set', async () => {
			delete window.dsgvoFormAdmin;
			apiFetch.mockResolvedValue( [ { id: 3, title: 'Feedback' } ] );

			const props = {
				...defaultProps,
				attributes: { formId: 3 },
			};

			await act( async () => {
				render( <Edit { ...props } /> );
			} );

			await waitFor( () => {
				const link = screen.getByTestId( 'external-link' );
				expect( link ).toHaveAttribute(
					'href',
					'/wp-admin/admin.php?page=dsgvo-form&action=edit&form_id=3'
				);
			} );
		} );

		it( 'uses /wp-admin/ fallback when adminUrl property is missing', async () => {
			window.dsgvoFormAdmin = {};
			apiFetch.mockResolvedValue( [ { id: 4, title: 'Anfrage' } ] );

			const props = {
				...defaultProps,
				attributes: { formId: 4 },
			};

			await act( async () => {
				render( <Edit { ...props } /> );
			} );

			await waitFor( () => {
				const link = screen.getByTestId( 'external-link' );
				expect( link ).toHaveAttribute(
					'href',
					'/wp-admin/admin.php?page=dsgvo-form&action=edit&form_id=4'
				);
			} );
		} );

		it( 'edit link URL does not contain dsgvo-form-builder', async () => {
			window.dsgvoFormAdmin = { adminUrl: 'https://site.de/wp-admin/' };
			apiFetch.mockResolvedValue( [ { id: 10, title: 'Newsletter' } ] );

			const props = {
				...defaultProps,
				attributes: { formId: 10 },
			};

			await act( async () => {
				render( <Edit { ...props } /> );
			} );

			await waitFor( () => {
				const link = screen.getByTestId( 'external-link' );
				expect( link.getAttribute( 'href' ) ).not.toContain( 'dsgvo-form-builder' );
				expect( link.getAttribute( 'href' ) ).toContain( 'page=dsgvo-form' );
				expect( link.getAttribute( 'href' ) ).toContain( 'action=edit' );
				expect( link.getAttribute( 'href' ) ).toContain( 'form_id=10' );
			} );
		} );
	} );
} );
