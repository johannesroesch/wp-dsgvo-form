/**
 * Edit component for the DSGVO Form block.
 *
 * Displays form selection, live preview, and inspector controls
 * in the Gutenberg editor.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Placeholder,
	Spinner,
	ExternalLink,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Editor component for the DSGVO Form block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor markup.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { formId } = attributes;
	const blockProps = useBlockProps();

	const [ forms, setForms ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	// Fetch available forms from REST API.
	useEffect( () => {
		setIsLoading( true );
		setError( null );

		apiFetch( { path: '/dsgvo-form/v1/forms' } )
			.then( ( data ) => {
				setForms( data || [] );
				setIsLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Formulare konnten nicht geladen werden.',
							'wp-dsgvo-form'
						)
				);
				setIsLoading( false );
			} );
	}, [] );

	// Build options for the form selector dropdown.
	const formOptions = [
		{
			label: __( '— Formular waehlen —', 'wp-dsgvo-form' ),
			value: 0,
		},
		...forms.map( ( form ) => ( {
			label: form.title,
			value: form.id,
		} ) ),
	];

	// Admin URL for editing the selected form.
	const editFormUrl =
		formId > 0
			? `${
					window.dsgvoFormAdmin?.adminUrl || '/wp-admin/'
			  }admin.php?page=dsgvo-form&action=edit&form_id=${ formId }`
			: null;

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Formular', 'wp-dsgvo-form' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Formular waehlen', 'wp-dsgvo-form' ) }
						value={ formId }
						options={ formOptions }
						onChange={ ( value ) =>
							setAttributes( { formId: parseInt( value, 10 ) } )
						}
					/>
					{ editFormUrl && (
						<ExternalLink href={ editFormUrl }>
							{ __( 'Im Admin bearbeiten', 'wp-dsgvo-form' ) }
						</ExternalLink>
					) }
				</PanelBody>
			</InspectorControls>

			{ isLoading && (
				<Placeholder
					icon="feedback"
					label={ __( 'DSGVO Formular', 'wp-dsgvo-form' ) }
				>
					<Spinner />
				</Placeholder>
			) }

			{ ! isLoading && error && (
				<Placeholder
					icon="warning"
					label={ __( 'DSGVO Formular', 'wp-dsgvo-form' ) }
					instructions={ error }
				/>
			) }

			{ ! isLoading && ! error && formId === 0 && (
				<Placeholder
					icon="feedback"
					label={ __( 'DSGVO Formular', 'wp-dsgvo-form' ) }
					instructions={ __(
						'Bitte waehlen Sie ein Formular aus der Seitenleiste.',
						'wp-dsgvo-form'
					) }
				>
					<SelectControl
						value={ formId }
						options={ formOptions }
						onChange={ ( value ) =>
							setAttributes( { formId: parseInt( value, 10 ) } )
						}
					/>
				</Placeholder>
			) }

			{ ! isLoading && ! error && formId > 0 && (
				<ServerSideRender
					block="dsgvo-form/form"
					attributes={ attributes }
				/>
			) }
		</div>
	);
}
