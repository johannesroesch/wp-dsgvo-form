/**
 * Tests for form-handler.js (vanilla JS frontend handler).
 *
 * Covers: inline validation, payload transformation, CAPTCHA token,
 * error display (WCAG), submission path selection, loading/success states.
 *
 * @package wp-dsgvo-form
 */

// --- Top-level setup: load IIFE once, keep fetch mock in same scope. ---
window.dsgvoFormHandler = {
	restUrl: '/wp-json/dsgvo-form/v1/submit',
	i18n: {},
};
global.fetch = jest.fn();
require( '../../src/frontend/form-handler.js' );

describe( 'DSGVO Form Handler', () => {
	let form;

	/**
	 * Creates standard form HTML matching FormBlock.php output.
	 *
	 * @param {Object} options Configuration overrides.
	 * @return {string} HTML string.
	 */
	function createFormHtml( options = {} ) {
		const fields = options.fields || `
			<div class="dsgvo-form__field dsgvo-form__field--text">
				<label for="dsgvo-field-1" class="dsgvo-form__label">Vorname</label>
				<input type="text" id="dsgvo-field-1" name="vorname"
					class="dsgvo-form__input" required aria-required="true">
				<div class="dsgvo-form__error" role="alert"></div>
			</div>
			<div class="dsgvo-form__field dsgvo-form__field--email">
				<label for="dsgvo-field-2" class="dsgvo-form__label">E-Mail</label>
				<input type="email" id="dsgvo-field-2" name="email"
					class="dsgvo-form__input" required aria-required="true">
				<div class="dsgvo-form__error" role="alert"></div>
			</div>
		`;

		const consent = options.noConsent
			? ''
			: `
			<div class="dsgvo-form__field dsgvo-form__field--consent">
				<label for="dsgvo-consent-1" class="dsgvo-form__consent-label">
					<input type="checkbox" id="dsgvo-consent-1" name="dsgvo_consent"
						value="1" required aria-required="true" class="dsgvo-form__checkbox">
					<span>Ich stimme zu.</span>
				</label>
				<input type="hidden" name="dsgvo_consent_version" value="1">
				<div class="dsgvo-form__error" role="alert"></div>
			</div>
		`;

		const captchaToken = options.captchaToken || '';

		return `
			<div class="wp-block-dsgvo-form-form">
				<form class="dsgvo-form" data-form-id="1" data-locale="de_DE"
					method="post" novalidate>
					<input type="hidden" name="_dsgvo_nonce" value="nonce123">
					<input type="hidden" name="dsgvo_form_id" value="1">
					<div class="dsgvo-form__hp" aria-hidden="true"
						style="position:absolute;left:-9999px;">
						<input type="text" name="website_url" value="" tabindex="-1">
					</div>
					${ fields }
					${ consent }
					<div class="dsgvo-form__captcha">
						<input type="hidden" name="captcha_token" value="${ captchaToken }">
					</div>
					<div class="dsgvo-form__submit">
						<button type="submit" class="dsgvo-form__button">Absenden</button>
					</div>
					<div class="dsgvo-form__status" role="alert" aria-live="polite"></div>
				</form>
			</div>
		`;
	}

	/**
	 * Full setup: DOM, fetch mock reset, DOMContentLoaded re-dispatch.
	 *
	 * The IIFE is already loaded (top-level require). Each DOMContentLoaded
	 * dispatch re-initializes form handlers for the new DOM elements.
	 */
	function setupForm( options = {} ) {
		document.body.innerHTML = createFormHtml( options );
		global.fetch.mockReset();
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		form = document.querySelector( '.dsgvo-form' );
	}

	/**
	 * Fills required fields and checks consent so the form is submit-ready.
	 */
	function fillValidForm() {
		form.querySelector( 'input[name="vorname"]' ).value = 'Max';
		form.querySelector( 'input[name="email"]' ).value = 'max@example.com';
		const consent = form.querySelector( 'input[name="dsgvo_consent"]' );
		if ( consent ) {
			consent.checked = true;
		}
	}

	/**
	 * Mocks fetch to resolve with a success response.
	 */
	function mockFetchSuccess( message = 'Danke!' ) {
		global.fetch.mockResolvedValueOnce( {
			ok: true,
			json: () => Promise.resolve( { message } ),
		} );
	}

	afterEach( () => {
		document.body.innerHTML = '';
		global.fetch.mockReset();
	} );

	// ------------------------------------------------------------------
	// Validation — required fields
	// ------------------------------------------------------------------

	describe( 'Validation: required fields', () => {
		it( 'shows error for empty required text field on blur', () => {
			setupForm();
			const input = form.querySelector( 'input[name="vorname"]' );

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			const errorEl = input
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.textContent ).toBe(
				'Dieses Feld ist erforderlich.'
			);
		} );

		it( 'clears error when user types after invalid state', () => {
			setupForm();
			const input = form.querySelector( 'input[name="vorname"]' );

			input.dispatchEvent( new Event( 'blur' ) );
			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );

			input.value = 'Max';
			input.dispatchEvent( new Event( 'input' ) );
			expect( input.getAttribute( 'aria-invalid' ) ).toBeNull();
		} );

		it( 'does not show error for non-required empty field', () => {
			const fields = `
				<div class="dsgvo-form__field dsgvo-form__field--text">
					<label for="f1" class="dsgvo-form__label">Optional</label>
					<input type="text" id="f1" name="optional_field"
						class="dsgvo-form__input">
					<div class="dsgvo-form__error" role="alert"></div>
				</div>
			`;
			setupForm( { fields, noConsent: true } );
			const input = form.querySelector( 'input[name="optional_field"]' );

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBeNull();
		} );
	} );

	// ------------------------------------------------------------------
	// Validation — type-specific
	// ------------------------------------------------------------------

	describe( 'Validation: type-specific', () => {
		it( 'shows error for invalid email', () => {
			setupForm();
			const input = form.querySelector( 'input[name="email"]' );
			input.value = 'not-an-email';

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			const errorEl = input
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.textContent ).toContain( 'E-Mail' );
		} );

		it( 'accepts valid email address', () => {
			setupForm();
			const input = form.querySelector( 'input[name="email"]' );
			input.value = 'test@example.com';

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBeNull();
		} );

		it( 'shows error for invalid phone number', () => {
			const fields = `
				<div class="dsgvo-form__field dsgvo-form__field--tel">
					<label for="f1" class="dsgvo-form__label">Telefon</label>
					<input type="tel" id="f1" name="telefon"
						class="dsgvo-form__input">
					<div class="dsgvo-form__error" role="alert"></div>
				</div>
			`;
			setupForm( { fields, noConsent: true } );
			const input = form.querySelector( 'input[name="telefon"]' );
			input.value = 'abc';

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		} );

		it( 'accepts valid phone number', () => {
			const fields = `
				<div class="dsgvo-form__field dsgvo-form__field--tel">
					<label for="f1" class="dsgvo-form__label">Telefon</label>
					<input type="tel" id="f1" name="telefon"
						class="dsgvo-form__input">
					<div class="dsgvo-form__error" role="alert"></div>
				</div>
			`;
			setupForm( { fields, noConsent: true } );
			const input = form.querySelector( 'input[name="telefon"]' );
			input.value = '+49 171 1234567';

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBeNull();
		} );

		it( 'shows error for invalid date (month 13)', () => {
			const fields = `
				<div class="dsgvo-form__field dsgvo-form__field--date">
					<label for="f1" class="dsgvo-form__label">Datum</label>
					<input type="date" id="f1" name="datum"
						class="dsgvo-form__input">
					<div class="dsgvo-form__error" role="alert"></div>
				</div>
			`;
			setupForm( { fields, noConsent: true } );
			const input = form.querySelector( 'input[name="datum"]' );

			// jsdom sanitizes invalid date input values to ''.
			// Override value property to test isValidDate() validation.
			Object.defineProperty( input, 'value', {
				get: () => '2026-13-01',
				configurable: true,
			} );

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		} );

		it( 'accepts valid date', () => {
			const fields = `
				<div class="dsgvo-form__field dsgvo-form__field--date">
					<label for="f1" class="dsgvo-form__label">Datum</label>
					<input type="date" id="f1" name="datum"
						class="dsgvo-form__input">
					<div class="dsgvo-form__error" role="alert"></div>
				</div>
			`;
			setupForm( { fields, noConsent: true } );
			const input = form.querySelector( 'input[name="datum"]' );
			input.value = '2026-04-17';

			input.dispatchEvent( new Event( 'blur' ) );

			expect( input.getAttribute( 'aria-invalid' ) ).toBeNull();
		} );
	} );

	// ------------------------------------------------------------------
	// Validation — consent checkbox
	// ------------------------------------------------------------------

	describe( 'Validation: consent', () => {
		it( 'shows error when consent not checked on change', () => {
			setupForm();
			const cb = form.querySelector( 'input[name="dsgvo_consent"]' );

			cb.dispatchEvent( new Event( 'change' ) );

			expect( cb.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			const errorEl = cb
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.textContent ).toContain( 'zustimmen' );
		} );

		it( 'clears error when consent is checked', () => {
			setupForm();
			const cb = form.querySelector( 'input[name="dsgvo_consent"]' );

			cb.dispatchEvent( new Event( 'change' ) );
			expect( cb.getAttribute( 'aria-invalid' ) ).toBe( 'true' );

			cb.checked = true;
			cb.dispatchEvent( new Event( 'change' ) );
			expect( cb.getAttribute( 'aria-invalid' ) ).toBeNull();
		} );
	} );

	// ------------------------------------------------------------------
	// Validation — CAPTCHA token
	// ------------------------------------------------------------------

	describe( 'Validation: CAPTCHA', () => {
		it( 'shows CAPTCHA error when token is empty on submit', () => {
			setupForm(); // captchaToken defaults to empty
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);

			const captchaError = form.querySelector(
				'.dsgvo-form__captcha .dsgvo-form__error'
			);
			expect( captchaError.textContent ).toBe(
				'Bitte loesen Sie das CAPTCHA.'
			);
		} );
	} );

	// ------------------------------------------------------------------
	// Payload Transformation
	// ------------------------------------------------------------------

	describe( 'Payload transformation', () => {
		beforeEach( () => {
			setupForm( { captchaToken: 'test-captcha-token' } );
		} );

		it( 'transforms _dsgvo_nonce to _wpnonce', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			expect( global.fetch ).toHaveBeenCalledTimes( 1 );
			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body._wpnonce ).toBe( 'nonce123' );
			expect( body ).not.toHaveProperty( '_dsgvo_nonce' );
		} );

		it( 'transforms dsgvo_consent to consent_given boolean', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.consent_given ).toBe( true );
			expect( body ).not.toHaveProperty( 'dsgvo_consent' );
		} );

		it( 'includes consent_locale from data-locale attribute', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.consent_locale ).toBe( 'de_DE' );
		} );

		it( 'collects user fields into fields object', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.fields ).toEqual( {
				vorname: 'Max',
				email: 'max@example.com',
			} );
		} );

		it( 'excludes system fields from fields object', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.fields ).not.toHaveProperty( 'dsgvo_form_id' );
			expect( body.fields ).not.toHaveProperty( '_dsgvo_nonce' );
			expect( body.fields ).not.toHaveProperty( 'dsgvo_consent' );
			expect( body.fields ).not.toHaveProperty( 'captcha_token' );
			expect( body.fields ).not.toHaveProperty( 'website_url' );
		} );

		it( 'includes honeypot field (empty for real users)', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.website_url ).toBe( '' );
		} );

		it( 'includes CAPTCHA token in payload', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.captcha_token ).toBe( 'test-captcha-token' );
		} );

		it( 'includes form_id as integer', async () => {
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.form_id ).toBe( 1 );
		} );
	} );

	// ------------------------------------------------------------------
	// Checkbox array handling
	// ------------------------------------------------------------------

	describe( 'Checkbox arrays', () => {
		it( 'collects multiple checked checkboxes as array', async () => {
			const fields = `
				<div class="dsgvo-form__field dsgvo-form__field--checkbox">
					<label class="dsgvo-form__label">Interessen</label>
					<div class="dsgvo-form__checkbox-group">
						<label><input type="checkbox" name="interessen[]"
							value="Sport" class="dsgvo-form__checkbox" checked> Sport</label>
						<label><input type="checkbox" name="interessen[]"
							value="Musik" class="dsgvo-form__checkbox" checked> Musik</label>
						<label><input type="checkbox" name="interessen[]"
							value="Film" class="dsgvo-form__checkbox"> Film</label>
					</div>
					<div class="dsgvo-form__error" role="alert"></div>
				</div>
			`;
			setupForm( { fields, noConsent: true, captchaToken: 'valid' } );
			mockFetchSuccess();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const body = JSON.parse(
				global.fetch.mock.calls[ 0 ][ 1 ].body
			);
			expect( body.fields.interessen ).toEqual( [
				'Sport',
				'Musik',
			] );
		} );
	} );

	// ------------------------------------------------------------------
	// Submission Path
	// ------------------------------------------------------------------

	describe( 'Submission path', () => {
		it( 'uses JSON Content-Type for text-only forms', async () => {
			setupForm( { captchaToken: 'valid' } );
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const options = global.fetch.mock.calls[ 0 ][ 1 ];
			expect( options.headers[ 'Content-Type' ] ).toBe(
				'application/json'
			);
			expect( options.method ).toBe( 'POST' );
			expect( options.credentials ).toBe( 'same-origin' );
		} );

		it( 'sends to configured REST URL', async () => {
			setupForm( { captchaToken: 'valid' } );
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 0 ) );

			const url = global.fetch.mock.calls[ 0 ][ 0 ];
			expect( url ).toBe( '/wp-json/dsgvo-form/v1/submit' );
		} );
	} );

	// ------------------------------------------------------------------
	// Error Display
	// ------------------------------------------------------------------

	describe( 'Error display', () => {
		it( 'shows server field errors on specific fields', async () => {
			setupForm( { captchaToken: 'valid' } );

			global.fetch.mockResolvedValueOnce( {
				ok: false,
				status: 422,
				json: () =>
					Promise.resolve( {
						message: 'Validierungsfehler',
						data: {
							errors: {
								vorname: 'Name ist zu kurz.',
							},
						},
					} ),
			} );
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 50 ) );

			const statusEl = form.querySelector( '.dsgvo-form__status' );
			expect( statusEl.textContent ).toBe( 'Validierungsfehler' );
			expect( statusEl.getAttribute( 'data-status' ) ).toBe( 'error' );

			const vornameInput = form.querySelector(
				'input[name="vorname"]'
			);
			expect( vornameInput.getAttribute( 'aria-invalid' ) ).toBe(
				'true'
			);
			const errorEl = vornameInput
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.textContent ).toBe( 'Name ist zu kurz.' );
		} );

		it( 'shows global error in status area on server error', async () => {
			setupForm( { captchaToken: 'valid' } );

			global.fetch.mockResolvedValueOnce( {
				ok: false,
				status: 500,
				json: () =>
					Promise.resolve( {
						message: 'Interner Serverfehler',
					} ),
			} );
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 50 ) );

			const statusEl = form.querySelector( '.dsgvo-form__status' );
			expect( statusEl.getAttribute( 'data-status' ) ).toBe( 'error' );
			expect( statusEl.textContent ).toBe( 'Interner Serverfehler' );
		} );

		it( 'focuses first invalid field on validation failure', () => {
			setupForm();
			// Leave all fields empty -> validation fails.

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);

			const firstInput = form.querySelector(
				'input[name="vorname"]'
			);
			expect( document.activeElement ).toBe( firstInput );
		} );

		it( 'sets aria-describedby pointing to error element', () => {
			setupForm();
			const input = form.querySelector( 'input[name="vorname"]' );

			input.dispatchEvent( new Event( 'blur' ) );

			const errorEl = input
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( input.getAttribute( 'aria-describedby' ) ).toBe(
				errorEl.id
			);
		} );

		it( 'adds error class to field wrapper', () => {
			setupForm();
			const input = form.querySelector( 'input[name="vorname"]' );

			input.dispatchEvent( new Event( 'blur' ) );

			const wrapper = input.closest( '.dsgvo-form__field' );
			expect(
				wrapper.classList.contains( 'dsgvo-form__field--error' )
			).toBe( true );
		} );
	} );

	// ------------------------------------------------------------------
	// Success State
	// ------------------------------------------------------------------

	describe( 'Success state', () => {
		it( 'hides form fields and shows success message', async () => {
			setupForm( { captchaToken: 'valid' } );
			mockFetchSuccess( 'Vielen Dank!' );
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 50 ) );

			const statusEl = form.querySelector( '.dsgvo-form__status' );
			expect( statusEl.textContent ).toBe( 'Vielen Dank!' );
			expect( statusEl.getAttribute( 'data-status' ) ).toBe(
				'success'
			);

			const fields = form.querySelectorAll( '.dsgvo-form__field' );
			fields.forEach( ( field ) => {
				expect( field.style.display ).toBe( 'none' );
			} );
		} );

		it( 'hides submit button on success', async () => {
			setupForm( { captchaToken: 'valid' } );
			mockFetchSuccess();
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 50 ) );

			const submitWrapper = form.querySelector(
				'.dsgvo-form__submit'
			);
			expect( submitWrapper.style.display ).toBe( 'none' );
		} );
	} );

	// ------------------------------------------------------------------
	// Loading State
	// ------------------------------------------------------------------

	describe( 'Loading state', () => {
		it( 'disables button and shows loading text during submission', () => {
			setupForm( { captchaToken: 'valid' } );
			// Never-resolving promise keeps loading state active.
			global.fetch.mockReturnValueOnce( new Promise( () => {} ) );
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);

			const button = form.querySelector( '.dsgvo-form__button' );
			expect( button.disabled ).toBe( true );
			expect( button.textContent ).toBe( 'Wird gesendet...' );
			expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );
			expect(
				button.classList.contains(
					'dsgvo-form__button--loading'
				)
			).toBe( true );
		} );

		it( 'restores button after error response', async () => {
			setupForm( { captchaToken: 'valid' } );
			global.fetch.mockResolvedValueOnce( {
				ok: false,
				status: 500,
				json: () =>
					Promise.resolve( { message: 'Server error' } ),
			} );
			fillValidForm();

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);
			await new Promise( ( r ) => setTimeout( r, 50 ) );

			const button = form.querySelector( '.dsgvo-form__button' );
			expect( button.disabled ).toBe( false );
			expect( button.textContent ).toBe( 'Absenden' );
			expect( button.getAttribute( 'aria-busy' ) ).toBeNull();
		} );
	} );

	// ------------------------------------------------------------------
	// Form prevents default submission
	// ------------------------------------------------------------------

	describe( 'Submit handling', () => {
		it( 'prevents default form submission', () => {
			setupForm( { captchaToken: 'valid' } );
			mockFetchSuccess();
			fillValidForm();

			const event = new Event( 'submit', { cancelable: true } );
			form.dispatchEvent( event );

			expect( event.defaultPrevented ).toBe( true );
		} );

		it( 'does not call fetch when validation fails', () => {
			setupForm(); // No captcha token, required fields empty.

			form.dispatchEvent(
				new Event( 'submit', { cancelable: true } )
			);

			expect( global.fetch ).not.toHaveBeenCalled();
		} );
	} );
} );
