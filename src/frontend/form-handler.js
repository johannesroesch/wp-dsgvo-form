/**
 * DSGVO Form — Frontend Handler
 *
 * Vanilla JavaScript form handler for DSGVO-compliant forms.
 * Handles inline validation, CAPTCHA integration, and form submission.
 *
 * No framework dependencies. Uses fetch API for REST submission.
 * Loaded only on pages containing a DSGVO form block.
 *
 * @package wp-dsgvo-form
 * @since 1.0.0
 */
( function () {
	'use strict';

	/**
	 * System field names excluded from the user fields payload.
	 * These are handled separately in buildPayload().
	 */
	var SYSTEM_FIELDS = [
		'dsgvo_form_id',
		'_dsgvo_nonce',
		'dsgvo_consent',
		'dsgvo_consent_version',
		'website_url',
		'captcha_token',
	];

	/** Config injected via wp_localize_script(). */
	var config = window.dsgvoFormHandler || {};
	var restUrl = config.restUrl || '/wp-json/dsgvo-form/v1/submit';
	var i18n = config.i18n || {};

	/** Validation messages with German defaults. */
	var msg = {
		required: i18n.required || 'Dieses Feld ist erforderlich.',
		emailInvalid: i18n.emailInvalid || 'Bitte geben Sie eine gueltige E-Mail-Adresse ein.',
		telInvalid: i18n.telInvalid || 'Bitte geben Sie eine gueltige Telefonnummer ein.',
		dateInvalid: i18n.dateInvalid || 'Bitte geben Sie ein gueltiges Datum ein.',
		consentRequired: i18n.consentRequired || 'Sie muessen der Datenverarbeitung zustimmen.',
		captchaRequired: i18n.captchaRequired || 'Bitte loesen Sie das CAPTCHA.',
		fileTooLarge: i18n.fileTooLarge || 'Die Datei ist zu gross.',
		fileTypeNotAllowed: i18n.fileTypeNotAllowed || 'Dieser Dateityp ist nicht erlaubt.',
		submitting: i18n.submitting || 'Wird gesendet...',
		networkError: i18n.networkError || 'Netzwerkfehler. Bitte pruefen Sie Ihre Verbindung.',
		genericError: i18n.genericError || 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es spaeter erneut.',
	};

	/* -------------------------------------------------------
	 * Initialization
	 * ----------------------------------------------------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.dsgvo-form' );
		for ( var i = 0; i < forms.length; i++ ) {
			setupForm( forms[ i ] );
		}
	} );

	/**
	 * Attaches event listeners to a single form instance.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function setupForm( form ) {
		form.addEventListener( 'submit', handleSubmit );
		setupInlineValidation( form );
	}

	/* -------------------------------------------------------
	 * Inline Validation (blur + input)
	 * ----------------------------------------------------- */

	/**
	 * Sets up blur validation and live error clearing for all inputs.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function setupInlineValidation( form ) {
		var inputs = form.querySelectorAll(
			'.dsgvo-form__field input:not([type="hidden"]), ' +
			'.dsgvo-form__field textarea, ' +
			'.dsgvo-form__field select'
		);

		for ( var i = 0; i < inputs.length; i++ ) {
			attachFieldListeners( inputs[ i ] );
		}

		var consent = form.querySelector( 'input[name="dsgvo_consent"]' );
		if ( consent ) {
			consent.addEventListener( 'change', function () {
				validateConsentField( this );
			} );
		}
	}

	/**
	 * Attaches blur and input listeners to a single field.
	 *
	 * @param {HTMLElement} input The input element.
	 */
	function attachFieldListeners( input ) {
		input.addEventListener( 'blur', function () {
			validateSingleField( this );
		} );

		input.addEventListener( 'input', function () {
			if ( this.getAttribute( 'aria-invalid' ) === 'true' ) {
				clearFieldError( this );
			}
		} );
	}

	/* -------------------------------------------------------
	 * Form Validation
	 * ----------------------------------------------------- */

	/**
	 * Validates all fields in a form.
	 *
	 * @param {HTMLFormElement} form The form element.
	 * @return {boolean} True if all fields are valid.
	 */
	function validateForm( form ) {
		var isValid = true;

		var fields = form.querySelectorAll(
			'.dsgvo-form__field input:not([type="hidden"]), ' +
			'.dsgvo-form__field textarea, ' +
			'.dsgvo-form__field select'
		);

		for ( var i = 0; i < fields.length; i++ ) {
			if ( ! validateSingleField( fields[ i ] ) ) {
				isValid = false;
			}
		}

		var consent = form.querySelector( 'input[name="dsgvo_consent"]' );
		if ( consent && ! validateConsentField( consent ) ) {
			isValid = false;
		}

		var captchaInput = form.querySelector( 'input[name="captcha_token"]' );
		if ( captchaInput && captchaInput.value.trim() === '' ) {
			showCaptchaError( form );
			isValid = false;
		}

		return isValid;
	}

	/**
	 * Validates a single form field.
	 *
	 * @param {HTMLElement} input The input element.
	 * @return {boolean} True if valid.
	 */
	function validateSingleField( input ) {
		if ( input.type === 'hidden' || isSystemField( input.name ) ) {
			return true;
		}

		var value = getFieldValue( input );
		var isRequired = input.hasAttribute( 'required' );

		if ( isRequired && isEmpty( value ) ) {
			showFieldError( input, msg.required );
			return false;
		}

		if ( isEmpty( value ) ) {
			clearFieldError( input );
			return true;
		}

		var type = input.getAttribute( 'type' ) || input.tagName.toLowerCase();
		var error = validateByType( type, value, input );

		if ( error ) {
			showFieldError( input, error );
			return false;
		}

		clearFieldError( input );
		return true;
	}

	/**
	 * Runs type-specific validation.
	 *
	 * @param {string}      type  The input type.
	 * @param {*}           value The field value.
	 * @param {HTMLElement} input The input element.
	 * @return {string|null} Error message or null if valid.
	 */
	function validateByType( type, value, input ) {
		switch ( type ) {
			case 'email':
				return isValidEmail( value ) ? null : msg.emailInvalid;
			case 'tel':
				return isValidTel( value ) ? null : msg.telInvalid;
			case 'date':
				return isValidDate( value ) ? null : msg.dateInvalid;
			case 'file':
				return validateFileInput( input );
			default:
				return null;
		}
	}

	/**
	 * Validates the consent checkbox.
	 *
	 * @param {HTMLInputElement} checkbox The consent checkbox.
	 * @return {boolean} True if checked.
	 */
	function validateConsentField( checkbox ) {
		if ( ! checkbox.checked ) {
			showFieldError( checkbox, msg.consentRequired );
			return false;
		}
		clearFieldError( checkbox );
		return true;
	}

	/**
	 * Validates a file input (client-side pre-validation).
	 * Server-side validation is authoritative.
	 *
	 * @param {HTMLInputElement} input The file input.
	 * @return {string|null} Error message or null if valid.
	 */
	function validateFileInput( input ) {
		if ( ! input.files || input.files.length === 0 ) {
			return null;
		}

		var file = input.files[ 0 ];
		var accept = input.getAttribute( 'accept' );

		if ( accept ) {
			var allowedExts = accept.split( ',' ).map( function ( ext ) {
				return ext.trim().toLowerCase();
			} );
			var fileExt = '.' + file.name.toLowerCase().split( '.' ).pop();

			if ( allowedExts.indexOf( fileExt ) === -1 ) {
				return msg.fileTypeNotAllowed;
			}
		}

		var maxSize = parseInt( input.dataset.maxSize || '5242880', 10 );
		if ( file.size > maxSize ) {
			return msg.fileTooLarge;
		}

		return null;
	}

	/* -------------------------------------------------------
	 * Validation Helpers
	 * ----------------------------------------------------- */

	function isValidEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
	}

	function isValidTel( value ) {
		return /^\+?[0-9\s\-()]{5,20}$/.test( value );
	}

	function isValidDate( value ) {
		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
			return false;
		}
		var parts = value.split( '-' );
		var date = new Date( parseInt( parts[0], 10 ), parseInt( parts[1], 10 ) - 1, parseInt( parts[2], 10 ) );
		return date.getFullYear() === parseInt( parts[0], 10 ) &&
			date.getMonth() === parseInt( parts[1], 10 ) - 1 &&
			date.getDate() === parseInt( parts[2], 10 );
	}

	function isEmpty( value ) {
		if ( Array.isArray( value ) ) {
			return value.length === 0;
		}
		return value === '' || value === null || value === undefined;
	}

	function isSystemField( name ) {
		return SYSTEM_FIELDS.indexOf( name ) !== -1;
	}

	/**
	 * Reads the current value from an input, handling checkboxes and radios.
	 *
	 * @param {HTMLElement} input The input element.
	 * @return {*} The field value.
	 */
	function getFieldValue( input ) {
		if ( input.type === 'checkbox' ) {
			var name = input.name;
			var form = input.closest( 'form' );

			if ( name.indexOf( '[]' ) !== -1 ) {
				var checked = form.querySelectorAll(
					'input[name="' + name + '"]:checked'
				);
				return Array.prototype.map.call( checked, function ( cb ) {
					return cb.value;
				} );
			}
			return input.checked ? input.value : '';
		}

		if ( input.type === 'radio' ) {
			var radioForm = input.closest( 'form' );
			var selected = radioForm.querySelector(
				'input[name="' + input.name + '"]:checked'
			);
			return selected ? selected.value : '';
		}

		return ( input.value || '' ).trim();
	}

	/* -------------------------------------------------------
	 * Error Display (WCAG 2.1 AA compliant)
	 * ----------------------------------------------------- */

	/**
	 * Shows an error message for a field.
	 * Sets aria-invalid and aria-describedby for screen readers.
	 *
	 * @param {HTMLElement} input   The input element.
	 * @param {string}      message The error message.
	 */
	function showFieldError( input, message ) {
		var wrapper = input.closest( '.dsgvo-form__field' ) ||
			input.closest( '.dsgvo-form__field--consent' );

		if ( ! wrapper ) {
			return;
		}

		wrapper.classList.add( 'dsgvo-form__field--error' );

		var errorEl = wrapper.querySelector( '.dsgvo-form__error' );
		if ( errorEl ) {
			errorEl.textContent = message;
			if ( ! errorEl.id ) {
				errorEl.id = ( input.id || input.name ) + '-error';
			}
			input.setAttribute( 'aria-describedby', errorEl.id );
		}

		input.setAttribute( 'aria-invalid', 'true' );
	}

	/**
	 * Clears the error state for a field.
	 *
	 * @param {HTMLElement} input The input element.
	 */
	function clearFieldError( input ) {
		var wrapper = input.closest( '.dsgvo-form__field' ) ||
			input.closest( '.dsgvo-form__field--consent' );

		if ( ! wrapper ) {
			return;
		}

		wrapper.classList.remove( 'dsgvo-form__field--error' );

		var errorEl = wrapper.querySelector( '.dsgvo-form__error' );
		if ( errorEl ) {
			errorEl.textContent = '';
		}

		input.removeAttribute( 'aria-invalid' );
		input.removeAttribute( 'aria-describedby' );
	}

	/**
	 * Shows CAPTCHA validation error.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function showCaptchaError( form ) {
		var captchaWrapper = form.querySelector( '.dsgvo-form__captcha' );
		if ( ! captchaWrapper ) {
			return;
		}

		var errorEl = captchaWrapper.querySelector( '.dsgvo-form__error' );
		if ( ! errorEl ) {
			errorEl = document.createElement( 'div' );
			errorEl.className = 'dsgvo-form__error';
			errorEl.setAttribute( 'role', 'alert' );
			captchaWrapper.appendChild( errorEl );
		}

		errorEl.textContent = msg.captchaRequired;
	}

	/**
	 * Clears CAPTCHA validation error.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function clearCaptchaError( form ) {
		var captchaWrapper = form.querySelector( '.dsgvo-form__captcha' );
		if ( ! captchaWrapper ) {
			return;
		}

		var errorEl = captchaWrapper.querySelector( '.dsgvo-form__error' );
		if ( errorEl ) {
			errorEl.textContent = '';
		}
	}

	/**
	 * Focuses the first invalid field in the form (WCAG focus management).
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function focusFirstError( form ) {
		var firstInvalid = form.querySelector( '[aria-invalid="true"]' );
		if ( firstInvalid ) {
			firstInvalid.focus();
		}
	}

	/* -------------------------------------------------------
	 * Form Submission
	 * ----------------------------------------------------- */

	/**
	 * Main submit handler. Validates, builds payload, and submits.
	 *
	 * @param {Event} e The submit event.
	 */
	function handleSubmit( e ) {
		e.preventDefault();

		var form = e.target;

		clearStatus( form );
		clearCaptchaError( form );

		if ( ! validateForm( form ) ) {
			focusFirstError( form );
			return;
		}

		var payload = buildPayload( form );

		setLoading( form, true );

		submitRequest( form, payload ).then( function ( result ) {
			showSuccess( form, result );
		} ).catch( function ( error ) {
			setLoading( form, false );
			showSubmissionError( form, error );
		} );
	}

	/**
	 * Builds the API payload from form data.
	 *
	 * Transforms HTML field names to match SubmitEndpoint contract:
	 *   _dsgvo_nonce  -> _wpnonce
	 *   dsgvo_consent -> consent_given
	 *   data-locale   -> consent_locale
	 *   flat fields   -> fields: { name: value }
	 *
	 * @param {HTMLFormElement} form The form element.
	 * @return {Object} Structured payload for the REST API.
	 */
	function buildPayload( form ) {
		var formIdInput = form.querySelector( 'input[name="dsgvo_form_id"]' );
		var nonceInput = form.querySelector( 'input[name="_dsgvo_nonce"]' );
		var consentCb = form.querySelector( 'input[name="dsgvo_consent"]' );
		var consentVersionInput = form.querySelector( 'input[name="dsgvo_consent_version"]' );
		var captchaInput = form.querySelector( 'input[name="captcha_token"]' );
		var honeypotInput = form.querySelector( 'input[name="website_url"]' );

		return {
			form_id: parseInt( formIdInput ? formIdInput.value : '0', 10 ),
			_wpnonce: nonceInput ? nonceInput.value : '',
			consent_given: consentCb ? consentCb.checked : false,
			consent_locale: form.dataset.locale || 'de_DE',
			consent_version_id: parseInt( consentVersionInput ? consentVersionInput.value : '0', 10 ),
			captcha_token: captchaInput ? captchaInput.value : '',
			website_url: honeypotInput ? honeypotInput.value : '',
			fields: collectFields( form ),
		};
	}

	/**
	 * Collects user field values from the form, excluding system fields.
	 *
	 * @param {HTMLFormElement} form The form element.
	 * @return {Object} Map of field names to values.
	 */
	function collectFields( form ) {
		var fields = {};
		var formData = new FormData( form );

		formData.forEach( function ( value, name ) {
			if ( isSystemField( name ) || name === 'captcha_token' ) {
				return;
			}

			var input = form.querySelector( '[name="' + name + '"]' );
			if ( input && input.type === 'file' ) {
				return;
			}

			if ( name.indexOf( '[]' ) !== -1 ) {
				var key = name.replace( '[]', '' );
				if ( ! fields[ key ] ) {
					fields[ key ] = [];
				}
				fields[ key ].push( value );
			} else {
				fields[ name ] = value;
			}
		} );

		return fields;
	}

	/**
	 * Sends the submission request to the REST API.
	 *
	 * Uses JSON for text-only forms, FormData for forms with file uploads.
	 * SubmitEndpoint supports both via get_json_params() with get_body_params() fallback.
	 *
	 * @param {HTMLFormElement} form    The form element.
	 * @param {Object}         payload The structured payload.
	 * @return {Promise<Object>} Resolves with server response data.
	 */
	function submitRequest( form, payload ) {
		var fileInputs = form.querySelectorAll( 'input[type="file"]' );
		var hasFiles = false;

		for ( var i = 0; i < fileInputs.length; i++ ) {
			if ( fileInputs[ i ].files && fileInputs[ i ].files.length > 0 ) {
				hasFiles = true;
				break;
			}
		}

		if ( hasFiles ) {
			return submitWithFiles( payload, fileInputs );
		}
		return submitJson( payload );
	}

	/**
	 * Submits form data as JSON (text-only forms).
	 *
	 * @param {Object} payload The payload object.
	 * @return {Promise<Object>} Server response data.
	 */
	function submitJson( payload ) {
		return fetch( restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( payload ),
		} ).then( parseResponse );
	}

	/**
	 * Submits form data as multipart/form-data (forms with file uploads).
	 *
	 * @param {Object}   payload    The payload object.
	 * @param {NodeList}  fileInputs File input elements.
	 * @return {Promise<Object>} Server response data.
	 */
	function submitWithFiles( payload, fileInputs ) {
		var formData = new FormData();

		formData.append( 'form_id', String( payload.form_id ) );
		formData.append( '_wpnonce', payload._wpnonce );
		formData.append( 'consent_given', payload.consent_given ? '1' : '0' );
		formData.append( 'consent_locale', payload.consent_locale );
		formData.append( 'consent_version_id', String( payload.consent_version_id ) );
		formData.append( 'captcha_token', payload.captcha_token );
		formData.append( 'website_url', payload.website_url );

		var fieldKeys = Object.keys( payload.fields );
		for ( var i = 0; i < fieldKeys.length; i++ ) {
			var key = fieldKeys[ i ];
			var value = payload.fields[ key ];

			if ( Array.isArray( value ) ) {
				for ( var j = 0; j < value.length; j++ ) {
					formData.append( 'fields[' + key + '][]', value[ j ] );
				}
			} else {
				formData.append( 'fields[' + key + ']', value );
			}
		}

		for ( var k = 0; k < fileInputs.length; k++ ) {
			if ( fileInputs[ k ].files && fileInputs[ k ].files.length > 0 ) {
				formData.append( fileInputs[ k ].name, fileInputs[ k ].files[ 0 ] );
			}
		}

		return fetch( restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( parseResponse );
	}

	/**
	 * Parses fetch response and throws on HTTP errors.
	 *
	 * @param {Response} response The fetch response.
	 * @return {Promise<Object>} Parsed JSON data.
	 * @throws {Object} Error object with message, status, and optional fieldErrors.
	 */
	function parseResponse( response ) {
		return response.json().then( function ( data ) {
			if ( ! response.ok ) {
				var errorMessage = data.message || msg.genericError;
				var fieldErrors = ( data.data && data.data.errors ) ? data.data.errors : null;
				var error = new Error( errorMessage );
				error.status = response.status;
				error.fieldErrors = fieldErrors;
				throw error;
			}
			return data;
		} ).catch( function ( error ) {
			if ( error.status ) {
				throw error;
			}
			var networkErr = new Error( msg.networkError );
			networkErr.status = 0;
			networkErr.fieldErrors = null;
			throw networkErr;
		} );
	}

	/* -------------------------------------------------------
	 * UI State Management
	 * ----------------------------------------------------- */

	/**
	 * Toggles the loading state on the submit button.
	 *
	 * @param {HTMLFormElement} form      The form element.
	 * @param {boolean}        isLoading Whether to show loading state.
	 */
	function setLoading( form, isLoading ) {
		var button = form.querySelector( '.dsgvo-form__button' );
		if ( ! button ) {
			return;
		}

		if ( isLoading ) {
			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
			button.classList.add( 'dsgvo-form__button--loading' );
			button.dataset.originalText = button.textContent;
			button.textContent = msg.submitting;
		} else {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.classList.remove( 'dsgvo-form__button--loading' );
			if ( button.dataset.originalText ) {
				button.textContent = button.dataset.originalText;
				delete button.dataset.originalText;
			}
		}
	}

	/**
	 * Shows the success message and hides form fields.
	 *
	 * @param {HTMLFormElement} form   The form element.
	 * @param {Object}         result Server response with .message.
	 */
	function showSuccess( form, result ) {
		var statusEl = form.querySelector( '.dsgvo-form__status' );
		if ( ! statusEl ) {
			return;
		}

		var hideSelectors = [
			'.dsgvo-form__field',
			'.dsgvo-form__field--consent',
			'.dsgvo-form__captcha',
			'.dsgvo-form__submit',
			'.dsgvo-form__hp',
		];

		for ( var i = 0; i < hideSelectors.length; i++ ) {
			var elements = form.querySelectorAll( hideSelectors[ i ] );
			for ( var j = 0; j < elements.length; j++ ) {
				elements[ j ].style.display = 'none';
			}
		}

		statusEl.setAttribute( 'data-status', 'success' );
		statusEl.textContent = result.message || msg.genericError;

		statusEl.setAttribute( 'tabindex', '-1' );
		statusEl.focus();
	}

	/**
	 * Shows the error message and optional field-level errors.
	 *
	 * @param {HTMLFormElement} form  The form element.
	 * @param {Error}           error Error with .message, .status, .fieldErrors.
	 */
	function showSubmissionError( form, error ) {
		var statusEl = form.querySelector( '.dsgvo-form__status' );
		if ( statusEl ) {
			statusEl.setAttribute( 'data-status', 'error' );
			statusEl.textContent = error.message || msg.genericError;
		}

		if ( error.fieldErrors ) {
			var errorKeys = Object.keys( error.fieldErrors );
			for ( var i = 0; i < errorKeys.length; i++ ) {
				var fieldName = errorKeys[ i ];
				var input = form.querySelector( '[name="' + fieldName + '"]' );
				if ( input ) {
					showFieldError( input, error.fieldErrors[ fieldName ] );
				}
			}
		}

		if ( error.fieldErrors ) {
			focusFirstError( form );
		} else if ( statusEl ) {
			statusEl.setAttribute( 'tabindex', '-1' );
			statusEl.focus();
		}
	}

	/**
	 * Clears the status message area.
	 *
	 * @param {HTMLFormElement} form The form element.
	 */
	function clearStatus( form ) {
		var statusEl = form.querySelector( '.dsgvo-form__status' );
		if ( statusEl ) {
			statusEl.textContent = '';
			statusEl.removeAttribute( 'data-status' );
			statusEl.removeAttribute( 'tabindex' );
		}
	}
} )();
