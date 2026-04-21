/**
 * Tests for form-handler.js — Frontend Form Handler.
 *
 * Covers: buildPayload, inline validation, CAPTCHA flow,
 * form submission, error handling, nonce/consent remap.
 *
 * @package
 */

describe( 'form-handler.js', () => {
	let form;
	let initHandler;
	const originalAddEventListener = document.addEventListener.bind( document );

	/**
	 * Creates a DSGVO form element in the DOM.
	 *
	 * @param {Object} options Form configuration.
	 * @return {HTMLFormElement} The form element.
	 */
	function createForm( options = {} ) {
		const {
			formId = '7',
			nonce = 'abc123',
			fields = [],
			hasConsent = true,
			consentChecked = false,
			hasCaptcha = false,
			captchaToken = '',
			hasHoneypot = true,
			locale = 'de_DE',
		} = options;

		let html = '<form class="dsgvo-form" data-locale="' + locale + '">';
		html +=
			'<input type="hidden" name="dsgvo_form_id" value="' + formId + '">';
		html +=
			'<input type="hidden" name="_dsgvo_nonce" value="' + nonce + '">';

		if ( hasHoneypot ) {
			html +=
				'<div class="dsgvo-form__hp"><input type="text" name="website_url" value=""></div>';
		}

		for ( const field of fields ) {
			html += '<div class="dsgvo-form__field">';
			if ( field.type === 'textarea' ) {
				html +=
					'<textarea name="' +
					field.name +
					'"' +
					( field.required ? ' required' : '' ) +
					'>' +
					( field.value || '' ) +
					'</textarea>';
			} else if ( field.type === 'select' ) {
				html +=
					'<select name="' +
					field.name +
					'"' +
					( field.required ? ' required' : '' ) +
					'>';
				for ( const opt of field.options || [] ) {
					html +=
						'<option value="' +
						opt +
						'"' +
						( opt === field.value ? ' selected' : '' ) +
						'>' +
						opt +
						'</option>';
				}
				html += '</select>';
			} else if ( field.type === 'checkbox' && field.multi ) {
				for ( const opt of field.options || [] ) {
					const checked = ( field.value || [] ).includes( opt )
						? ' checked'
						: '';
					html +=
						'<input type="checkbox" name="' +
						field.name +
						'[]" value="' +
						opt +
						'"' +
						checked +
						'>';
				}
			} else {
				html +=
					'<input type="' +
					( field.type || 'text' ) +
					'"' +
					' name="' +
					field.name +
					'"' +
					' value="' +
					( field.value || '' ) +
					'"' +
					( field.required ? ' required' : '' ) +
					( field.accept ? ' accept="' + field.accept + '"' : '' ) +
					( field.maxSize
						? ' data-max-size="' + field.maxSize + '"'
						: '' ) +
					'>';
			}
			html += '<div class="dsgvo-form__error"></div>';
			html += '</div>';
		}

		if ( hasConsent ) {
			html += '<div class="dsgvo-form__field--consent">';
			html +=
				'<input type="checkbox" name="dsgvo_consent" value="1"' +
				( consentChecked ? ' checked' : '' ) +
				'>';
			html +=
				'<input type="hidden" name="dsgvo_consent_version" value="2.1">';
			html += '<div class="dsgvo-form__error"></div>';
			html += '</div>';
		}

		if ( hasCaptcha ) {
			html += '<div class="dsgvo-form__captcha">';
			html +=
				'<input type="hidden" name="captcha_token" value="' +
				captchaToken +
				'">';
			html += '</div>';
		}

		html += '<div class="dsgvo-form__submit">';
		html +=
			'<button type="submit" class="dsgvo-form__button">Absenden</button>';
		html += '</div>';
		html += '<div class="dsgvo-form__status"></div>';
		html += '</form>';

		document.body.innerHTML = html;
		form = document.querySelector( '.dsgvo-form' );
		return form;
	}

	/**
	 * Loads the form handler script and triggers DOM initialization.
	 * Intercepts DOMContentLoaded to prevent listener accumulation.
	 */
	function loadHandler() {
		jest.resetModules();
		require( '../form-handler' );
		if ( initHandler ) {
			initHandler();
		}
	}

	function submitForm() {
		form.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
	}

	function blurField( input ) {
		input.dispatchEvent( new Event( 'blur', { bubbles: true } ) );
	}

	function flushPromises() {
		return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}

	beforeEach( () => {
		// Intercept DOMContentLoaded to prevent listener accumulation across tests.
		document.addEventListener = function ( event, handler, opts ) {
			if ( event === 'DOMContentLoaded' ) {
				initHandler = handler;
				return;
			}
			return originalAddEventListener( event, handler, opts );
		};

		window.dsgvoFormHandler = {
			restUrl: '/wp-json/dsgvo-form/v1/submit',
			i18n: {},
		};

		global.fetch = jest.fn();
	} );

	afterEach( () => {
		document.body.innerHTML = '';
		document.addEventListener = originalAddEventListener;
		delete window.dsgvoFormHandler;
		initHandler = null;
	} );

	// ── buildPayload ──────────────────────────────────

	describe( 'buildPayload', () => {
		function getPayload() {
			return JSON.parse( global.fetch.mock.calls[ 0 ][ 1 ].body );
		}

		function mockSuccessfulFetch() {
			global.fetch.mockResolvedValue( {
				ok: true,
				json: () => Promise.resolve( { message: 'OK' } ),
			} );
		}

		it( 'remaps _dsgvo_nonce to _wpnonce', () => {
			createForm( {
				fields: [ { name: 'vorname', value: 'Max' } ],
				consentChecked: true,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			const body = getPayload();
			expect( body._wpnonce ).toBe( 'abc123' );
			expect( body ).not.toHaveProperty( '_dsgvo_nonce' );
		} );

		it( 'remaps dsgvo_consent to consent_given boolean', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			const body = getPayload();
			expect( body.consent_given ).toBe( true );
			expect( body ).not.toHaveProperty( 'dsgvo_consent' );
		} );

		it( 'sets consent_given to false when unchecked', () => {
			// Consent unchecked but no consent field validation (no consent element).
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				hasConsent: false,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			const body = getPayload();
			expect( body.consent_given ).toBe( false );
		} );

		it( 'extracts consent_locale from form data-locale attribute', () => {
			createForm( {
				locale: 'en_US',
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			expect( getPayload().consent_locale ).toBe( 'en_US' );
		} );

		it( 'excludes all system fields from fields object', () => {
			createForm( {
				fields: [ { name: 'email', type: 'email', value: 'a@b.com' } ],
				consentChecked: true,
				hasCaptcha: true,
				captchaToken: 'tok',
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			const { fields } = getPayload();
			expect( fields ).not.toHaveProperty( 'dsgvo_form_id' );
			expect( fields ).not.toHaveProperty( '_dsgvo_nonce' );
			expect( fields ).not.toHaveProperty( 'dsgvo_consent' );
			expect( fields ).not.toHaveProperty( 'dsgvo_consent_version' );
			expect( fields ).not.toHaveProperty( 'website_url' );
			expect( fields ).not.toHaveProperty( 'captcha_token' );
			expect( fields.email ).toBe( 'a@b.com' );
		} );

		it( 'sends form_id as integer', () => {
			createForm( {
				formId: '42',
				fields: [ { name: 'x', value: 'y' } ],
				consentChecked: true,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			expect( getPayload().form_id ).toBe( 42 );
		} );

		it( 'includes captcha_token in payload', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
				hasCaptcha: true,
				captchaToken: 'captcha-abc',
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			expect( getPayload().captcha_token ).toBe( 'captcha-abc' );
		} );

		it( 'collects multi-checkbox values as array', () => {
			createForm( {
				fields: [
					{
						name: 'topics',
						type: 'checkbox',
						multi: true,
						options: [ 'a', 'b', 'c' ],
						value: [ 'a', 'c' ],
					},
				],
				consentChecked: true,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			expect( getPayload().fields.topics ).toEqual( [ 'a', 'c' ] );
		} );

		it( 'includes honeypot field value at top level', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();
			mockSuccessfulFetch();

			submitForm();

			expect( getPayload().website_url ).toBe( '' );
		} );
	} );

	// ── Inline Validation ─────────────────────────────

	describe( 'inline validation', () => {
		it( 'shows error for empty required field on blur', () => {
			createForm( {
				fields: [ { name: 'name', type: 'text', required: true } ],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="name"]' );
			blurField( input );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			const errorEl = input
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.textContent ).toBe(
				'Dieses Feld ist erforderlich.'
			);
		} );

		it( 'clears error on input when field was marked invalid', () => {
			createForm( {
				fields: [ { name: 'name', type: 'text', required: true } ],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="name"]' );
			blurField( input );
			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );

			// Fix the value and trigger input event.
			input.value = 'filled';
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

			expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
		} );

		it( 'rejects invalid email on blur', () => {
			createForm( {
				fields: [
					{ name: 'email', type: 'email', value: 'not-an-email' },
				],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="email"]' );
			blurField( input );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		} );

		it( 'accepts valid email on blur', () => {
			createForm( {
				fields: [
					{ name: 'email', type: 'email', value: 'user@example.com' },
				],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="email"]' );
			blurField( input );

			expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
		} );

		it( 'rejects invalid phone number on blur', () => {
			createForm( {
				fields: [ { name: 'phone', type: 'tel', value: 'abc' } ],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="phone"]' );
			blurField( input );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		} );

		it( 'accepts valid phone number on blur', () => {
			createForm( {
				fields: [
					{ name: 'phone', type: 'tel', value: '+49 7251 12345' },
				],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="phone"]' );
			blurField( input );

			expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
		} );

		it( 'rejects invalid date on blur', () => {
			createForm( {
				fields: [ { name: 'date', type: 'date' } ],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="date"]' );
			// jsdom sanitises invalid date values — override property directly.
			Object.defineProperty( input, 'value', {
				value: '2000-13-45',
				writable: true,
				configurable: true,
			} );
			blurField( input );

			expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		} );

		it( 'accepts valid date on blur', () => {
			createForm( {
				fields: [ { name: 'date', type: 'date', value: '2026-04-17' } ],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="date"]' );
			blurField( input );

			expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
		} );

		it( 'sets aria-describedby linking input to error element', () => {
			createForm( {
				fields: [ { name: 'name', type: 'text', required: true } ],
			} );
			loadHandler();

			const input = form.querySelector( 'input[name="name"]' );
			blurField( input );

			const errorEl = input
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.id ).toBeTruthy();
			expect( input.getAttribute( 'aria-describedby' ) ).toBe(
				errorEl.id
			);
		} );
	} );

	// ── Consent Validation ────────────────────────────

	describe( 'consent validation', () => {
		it( 'prevents submit when consent is not checked', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: false,
			} );
			loadHandler();

			submitForm();

			expect( global.fetch ).not.toHaveBeenCalled();
			const consent = form.querySelector( 'input[name="dsgvo_consent"]' );
			expect( consent.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		} );

		it( 'clears consent error when checkbox is checked', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: false,
			} );
			loadHandler();

			const consent = form.querySelector( 'input[name="dsgvo_consent"]' );
			submitForm();
			expect( consent.getAttribute( 'aria-invalid' ) ).toBe( 'true' );

			consent.checked = true;
			consent.dispatchEvent( new Event( 'change', { bubbles: true } ) );

			expect( consent.hasAttribute( 'aria-invalid' ) ).toBe( false );
		} );
	} );

	// ── CAPTCHA Flow ──────────────────────────────────

	describe( 'CAPTCHA flow', () => {
		it( 'prevents submit when captcha_token is empty', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
				hasCaptcha: true,
				captchaToken: '',
			} );
			loadHandler();

			submitForm();

			expect( global.fetch ).not.toHaveBeenCalled();
			const captchaError = form.querySelector(
				'.dsgvo-form__captcha .dsgvo-form__error'
			);
			expect( captchaError ).not.toBeNull();
			expect( captchaError.textContent ).toBe(
				'Bitte loesen Sie das CAPTCHA.'
			);
		} );

		it( 'allows submit when captcha_token is present', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
				hasCaptcha: true,
				captchaToken: 'valid-token',
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: true,
				json: () => Promise.resolve( { message: 'OK' } ),
			} );

			submitForm();

			expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'creates error element with role=alert for CAPTCHA', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
				hasCaptcha: true,
				captchaToken: '',
			} );
			loadHandler();

			submitForm();

			const errorEl = form.querySelector(
				'.dsgvo-form__captcha .dsgvo-form__error'
			);
			expect( errorEl.getAttribute( 'role' ) ).toBe( 'alert' );
		} );
	} );

	// ── Form Submission ───────────────────────────────

	describe( 'form submission', () => {
		it( 'sends JSON POST to configured restUrl', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: true,
				json: () => Promise.resolve( { message: 'OK' } ),
			} );

			submitForm();

			expect( global.fetch ).toHaveBeenCalledWith(
				'/wp-json/dsgvo-form/v1/submit',
				expect.objectContaining( {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
				} )
			);
		} );

		it( 'shows loading state on submit button', () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			// Return a never-resolving promise to freeze in loading state.
			global.fetch.mockReturnValue( new Promise( () => {} ) );

			submitForm();

			const button = form.querySelector( '.dsgvo-form__button' );
			expect( button.disabled ).toBe( true );
			expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );
			expect( button.textContent ).toBe( 'Wird gesendet...' );
		} );

		it( 'shows success message and hides form fields', async () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: true,
				json: () => Promise.resolve( { message: 'Vielen Dank!' } ),
			} );

			submitForm();
			await flushPromises();

			const statusEl = form.querySelector( '.dsgvo-form__status' );
			expect( statusEl.getAttribute( 'data-status' ) ).toBe( 'success' );
			expect( statusEl.textContent ).toBe( 'Vielen Dank!' );

			const fieldWrappers = form.querySelectorAll( '.dsgvo-form__field' );
			for ( const wrapper of fieldWrappers ) {
				expect( wrapper.style.display ).toBe( 'none' );
			}
		} );

		it( 'does not submit when validation fails', () => {
			createForm( {
				fields: [
					{ name: 'email', type: 'email', value: '', required: true },
				],
				consentChecked: true,
			} );
			loadHandler();

			submitForm();

			expect( global.fetch ).not.toHaveBeenCalled();
		} );

		it( 'uses default restUrl when config is not set', () => {
			delete window.dsgvoFormHandler;

			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: true,
				json: () => Promise.resolve( { message: 'OK' } ),
			} );

			submitForm();

			expect( global.fetch ).toHaveBeenCalledWith(
				'/wp-json/dsgvo-form/v1/submit',
				expect.any( Object )
			);
		} );
	} );

	// ── Error Handling ────────────────────────────────

	describe( 'error handling', () => {
		it( 'shows server error message in status area', async () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: false,
				status: 422,
				json: () =>
					Promise.resolve( {
						message: 'Validierung fehlgeschlagen',
					} ),
			} );

			submitForm();
			await flushPromises();

			const statusEl = form.querySelector( '.dsgvo-form__status' );
			expect( statusEl.getAttribute( 'data-status' ) ).toBe( 'error' );
			expect( statusEl.textContent ).toBe( 'Validierung fehlgeschlagen' );
		} );

		it( 'maps server fieldErrors to individual field error displays', async () => {
			createForm( {
				fields: [
					{ name: 'email', type: 'email', value: 'test@x.com' },
					{ name: 'name', value: 'Test' },
				],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: false,
				status: 422,
				json: () =>
					Promise.resolve( {
						message: 'Fehler',
						data: {
							errors: { email: 'E-Mail bereits registriert' },
						},
					} ),
			} );

			submitForm();
			await flushPromises();

			const emailInput = form.querySelector( 'input[name="email"]' );
			expect( emailInput.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			const errorEl = emailInput
				.closest( '.dsgvo-form__field' )
				.querySelector( '.dsgvo-form__error' );
			expect( errorEl.textContent ).toBe( 'E-Mail bereits registriert' );
		} );

		it( 'shows network error when JSON parsing fails', async () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: true,
				json: () =>
					Promise.reject( new TypeError( 'Failed to parse' ) ),
			} );

			submitForm();
			await flushPromises();

			const statusEl = form.querySelector( '.dsgvo-form__status' );
			expect( statusEl.getAttribute( 'data-status' ) ).toBe( 'error' );
			expect( statusEl.textContent ).toBe(
				'Netzwerkfehler. Bitte pruefen Sie Ihre Verbindung.'
			);
		} );

		it( 'restores submit button after error', async () => {
			createForm( {
				fields: [ { name: 'name', value: 'Test' } ],
				consentChecked: true,
			} );
			loadHandler();

			global.fetch.mockResolvedValue( {
				ok: false,
				status: 500,
				json: () => Promise.resolve( { message: 'Server Error' } ),
			} );

			submitForm();
			await flushPromises();

			const button = form.querySelector( '.dsgvo-form__button' );
			expect( button.disabled ).toBe( false );
			expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
			expect( button.textContent ).toBe( 'Absenden' );
		} );
	} );
} );
