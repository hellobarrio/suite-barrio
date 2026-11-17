// SEO → Organizzazione (manage_options): i dati dell'azienda per lo schema (Settings::schema()), una card per
// argomento, con l'anteprima del JSON-LD calcolata dal server mentre si compila, nella colonna a destra.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Card,
	Details,
	Fixed,
	Icon,
	Select,
	Text,
	errorMessage,
} from '../../../../../assets/src/admin/ui.js';
import { SeoText, SettingsPage, cleanOrganization } from './SettingsPage.js';
import { MediaField, seoData } from './ui.js';

const AREAS = [
	[ 'City', __( 'City', 'barrio-suite' ) ],
	[ 'AdministrativeArea', __( 'Province or region', 'barrio-suite' ) ],
	[ 'State', __( 'State', 'barrio-suite' ) ],
	[ 'Country', __( 'Country', 'barrio-suite' ) ],
	[ 'Place', __( 'Other place', 'barrio-suite' ) ],
].map( ( [ value, label ] ) => ( { value, label } ) );

const AVAILABILITY = [
	[ '', __( 'Not specified', 'barrio-suite' ) ],
	[ 'InStock', __( 'Available', 'barrio-suite' ) ],
	[ 'LimitedAvailability', __( 'Limited availability', 'barrio-suite' ) ],
	[ 'PreOrder', __( 'Pre-order', 'barrio-suite' ) ],
	[ 'OutOfStock', __( 'Not available', 'barrio-suite' ) ],
	[ 'Discontinued', __( 'Discontinued', 'barrio-suite' ) ],
].map( ( [ value, label ] ) => ( { value, label } ) );

const OFFER = {
	type: 'Service',
	name: '',
	description: '',
	category: '',
	url: '',
	image: 0,
	price: null,
	availability: '',
};

const number = ( value ) => ( '' === value ? null : Number( value ) );

const AddButton = ( { text, onClick } ) => (
	<button type="button" className="barrio-button" onClick={ onClick }>
		<Icon name="plus-alt2" />
		<span className="barrio-button__text">{ text }</span>
	</button>
);

const RemoveButton = ( { onClick } ) => (
	<button
		type="button"
		className="barrio-icon-button"
		aria-label={ __( 'Remove', 'barrio-suite' ) }
		onClick={ onClick }
	>
		<Icon name="trash" />
	</button>
);

// Un elenco di testi (competenze, profili) in una card, una riga ciascuno.
function TextList( {
	icon,
	title,
	description,
	add,
	label,
	type,
	values,
	onChange,
} ) {
	return (
		<Card
			icon={ icon }
			title={ title }
			description={ description }
			actions={
				<AddButton
					text={ add }
					onClick={ () => onChange( [ ...values, '' ] ) }
				/>
			}
		>
			{ values.map( ( value, index ) => (
				<div className="barrio-row is-nowrap" key={ index }>
					<Text
						hideLabel
						label={ label }
						type={ type }
						value={ value }
						onChange={ ( next ) =>
							onChange(
								values.map( ( item, i ) =>
									i === index ? next : item
								)
							)
						}
					/>
					<RemoveButton
						onClick={ () =>
							onChange( values.filter( ( v, i ) => i !== index ) )
						}
					/>
				</div>
			) ) }
		</Card>
	);
}

function Preview( { organization, description } ) {
	const [ preview, setPreview ] = useState( '' );
	useEffect( () => {
		let current = true;
		const timer = setTimeout(
			() =>
				apiFetch( {
					path: '/barrio/v1/seo/settings/schema',
					method: 'POST',
					data: {
						organization: cleanOrganization( organization ),
						description,
					},
				} )
					.then(
						( node ) =>
							current &&
							setPreview( JSON.stringify( node, null, 2 ) )
					)
					.catch(
						( error ) =>
							current && setPreview( errorMessage( error ) )
					),
			400
		);
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ organization, description ] );
	return (
		<Card
			icon="editor-code"
			title={ __( 'Structured data', 'barrio-suite' ) }
			description={ __(
				'What search engines read about the business, in JSON-LD: it is in every indexed page, with the site and the page.',
				'barrio-suite'
			) }
		>
			<pre className="barrio-code">{ preview }</pre>
		</Card>
	);
}

function OrganizationCards( { seo, lang, texts, setSeo, setText } ) {
	const o = seo.organization;
	const set = ( name ) => ( value ) =>
		setSeo( 'organization' )( { ...o, [ name ]: value } );
	const setIn = ( group, name ) => ( value ) =>
		setSeo( 'organization' )( {
			...o,
			[ group ]: { ...o[ group ], [ name ]: value },
		} );
	const setItems = ( items ) => setIn( 'offers', 'items' )( items );
	const setItem = ( index, name ) => ( value ) =>
		setItems(
			o.offers.items.map( ( item, i ) =>
				i === index ? { ...item, [ name ]: value } : item
			)
		);
	const setArea = ( index, name ) => ( value ) =>
		set( 'area_served' )(
			o.area_served.map( ( area, i ) =>
				i === index ? { ...area, [ name ]: value } : area
			)
		);

	return (
		<div className="barrio-fields">
			<div>
				<Card
					className="barrio-wide"
					icon="building"
					title={ __( 'Business', 'barrio-suite' ) }
					description={ __(
						'The business behind the site, for search engines: Google can show it in the results and on Maps.',
						'barrio-suite'
					) }
				>
					<Fixed lang={ lang }>
						<Select
							label={ __( 'Type', 'barrio-suite' ) }
							help={ __(
								'The schema.org type that best describes the business.',
								'barrio-suite'
							) }
							value={ o.type }
							options={ seoData.organizations.map(
								( value ) => ( {
									value,
									label: value,
								} )
							) }
							onChange={ set( 'type' ) }
						/>
						<Text
							label={ __( 'Name', 'barrio-suite' ) }
							help={ __(
								'Without a name, the name of the site is used.',
								'barrio-suite'
							) }
							value={ o.name }
							onChange={ set( 'name' ) }
						/>
					</Fixed>
					<SeoText
						form={ { lang, seo, setText } }
						name="organization_description"
						label={ __( 'Description', 'barrio-suite' ) }
					/>
					<Fixed lang={ lang }>
						<MediaField
							label={ __( 'Logo', 'barrio-suite' ) }
							help={ __(
								'At least 112 pixels wide, on a plain background.',
								'barrio-suite'
							) }
							value={ o.logo }
							onChange={ set( 'logo' ) }
						/>
						<MediaField
							label={ __( 'Image', 'barrio-suite' ) }
							help={ __(
								'A photo of the place or of the business.',
								'barrio-suite'
							) }
							value={ o.image }
							onChange={ set( 'image' ) }
						/>
						<Text
							type="tel"
							label={ __( 'Phone', 'barrio-suite' ) }
							value={ o.phone }
							onChange={ set( 'phone' ) }
						/>
						<Text
							type="email"
							label={ __( 'Email', 'barrio-suite' ) }
							value={ o.email }
							onChange={ set( 'email' ) }
						/>
						<Text
							label={ __( 'VAT number', 'barrio-suite' ) }
							value={ o.vat_id }
							onChange={ set( 'vat_id' ) }
						/>
					</Fixed>
				</Card>

				<Fixed lang={ lang }>
					<Card
						className="barrio-wide"
						icon="location"
						title={ __( 'Address', 'barrio-suite' ) }
					>
						<Text
							label={ __( 'Street and number', 'barrio-suite' ) }
							value={ o.address.street }
							onChange={ setIn( 'address', 'street' ) }
						/>
						<Text
							label={ __( 'Postal code', 'barrio-suite' ) }
							value={ o.address.postal_code }
							onChange={ setIn( 'address', 'postal_code' ) }
						/>
						<Text
							label={ __( 'City', 'barrio-suite' ) }
							value={ o.address.locality }
							onChange={ setIn( 'address', 'locality' ) }
						/>
						<Text
							label={ __( 'Province or region', 'barrio-suite' ) }
							value={ o.address.region }
							onChange={ setIn( 'address', 'region' ) }
						/>
						<Text
							label={ __( 'Country', 'barrio-suite' ) }
							help={ __(
								'Two-letter code, such as IT.',
								'barrio-suite'
							) }
							maxLength={ 2 }
							value={ o.address.country }
							onChange={ ( value ) =>
								setIn(
									'address',
									'country'
								)( value.toUpperCase() )
							}
						/>
						<Text
							type="number"
							step="any"
							min={ -90 }
							max={ 90 }
							label={ __( 'Latitude', 'barrio-suite' ) }
							help={ __(
								'In Google Maps, right-click the place: the first number is the latitude, the second the longitude.',
								'barrio-suite'
							) }
							value={ o.geo.latitude }
							onChange={ ( value ) =>
								setIn( 'geo', 'latitude' )( number( value ) )
							}
						/>
						<Text
							type="number"
							step="any"
							min={ -180 }
							max={ 180 }
							label={ __( 'Longitude', 'barrio-suite' ) }
							value={ o.geo.longitude }
							onChange={ ( value ) =>
								setIn( 'geo', 'longitude' )( number( value ) )
							}
						/>
					</Card>

					<Card
						icon="location-alt"
						title={ __( 'Areas served', 'barrio-suite' ) }
						description={ __(
							'The cities or regions where the business works, if it goes to its customers.',
							'barrio-suite'
						) }
						actions={
							<AddButton
								text={ __( 'Add area', 'barrio-suite' ) }
								onClick={ () =>
									set( 'area_served' )( [
										...o.area_served,
										{ type: 'City', name: '' },
									] )
								}
							/>
						}
					>
						{ o.area_served.map( ( area, index ) => (
							<div className="barrio-row is-nowrap" key={ index }>
								<Select
									hideLabel
									label={ __(
										'Kind of area',
										'barrio-suite'
									) }
									value={ area.type }
									options={ AREAS }
									onChange={ setArea( index, 'type' ) }
								/>
								<Text
									hideLabel
									label={ __(
										'Name of the area',
										'barrio-suite'
									) }
									value={ area.name }
									onChange={ setArea( index, 'name' ) }
								/>
								<RemoveButton
									onClick={ () =>
										set( 'area_served' )(
											o.area_served.filter(
												( a, i ) => i !== index
											)
										)
									}
								/>
							</div>
						) ) }
					</Card>

					<TextList
						icon="lightbulb"
						title={ __( 'Expertise', 'barrio-suite' ) }
						description={ __(
							'What the business knows best, such as “Neapolitan pizza” or “wood-fired oven”.',
							'barrio-suite'
						) }
						add={ __( 'Add topic', 'barrio-suite' ) }
						label={ __( 'Topic', 'barrio-suite' ) }
						values={ o.knows_about }
						onChange={ set( 'knows_about' ) }
					/>
					<TextList
						icon="share"
						title={ __( 'Profiles', 'barrio-suite' ) }
						description={ __(
							'Social profiles and listings of the business, such as Facebook, Instagram, LinkedIn or Google Business Profile.',
							'barrio-suite'
						) }
						add={ __( 'Add profile', 'barrio-suite' ) }
						label={ __(
							'Full address of a profile, such as https://www.instagram.com/…',
							'barrio-suite'
						) }
						type="url"
						values={ o.same_as }
						onChange={ set( 'same_as' ) }
					/>

					<Card
						className="barrio-wide"
						icon="cart"
						title={ __( 'Offers', 'barrio-suite' ) }
						actions={
							<AddButton
								text={ __( 'Add offer', 'barrio-suite' ) }
								onClick={ () =>
									setItems( [ ...o.offers.items, OFFER ] )
								}
							/>
						}
					>
						<Text
							label={ __( 'Catalog name', 'barrio-suite' ) }
							value={ o.offers.name }
							onChange={ setIn( 'offers', 'name' ) }
						/>
						<Text
							label={ __( 'Currency', 'barrio-suite' ) }
							help={ __(
								'Three-letter code, such as EUR.',
								'barrio-suite'
							) }
							maxLength={ 3 }
							value={ o.offers.currency }
							onChange={ ( value ) =>
								setIn(
									'offers',
									'currency'
								)( value.toUpperCase() )
							}
						/>
						{ o.offers.items.map( ( item, index ) => (
							<Details
								key={ index }
								title={
									item.name ||
									__( 'New offer', 'barrio-suite' )
								}
							>
								<Select
									label={ __( 'Type', 'barrio-suite' ) }
									value={ item.type }
									options={ [
										{
											value: 'Service',
											label: __(
												'Service',
												'barrio-suite'
											),
										},
										{
											value: 'Product',
											label: __(
												'Product',
												'barrio-suite'
											),
										},
									] }
									onChange={ setItem( index, 'type' ) }
								/>
								<Text
									label={ __( 'Name', 'barrio-suite' ) }
									required
									value={ item.name }
									onChange={ setItem( index, 'name' ) }
								/>
								<Text
									multiline
									label={ __(
										'Description',
										'barrio-suite'
									) }
									value={ item.description }
									onChange={ setItem( index, 'description' ) }
								/>
								<Text
									label={ __( 'Category', 'barrio-suite' ) }
									value={ item.category }
									onChange={ setItem( index, 'category' ) }
								/>
								<Text
									type="url"
									label={ __( 'Page', 'barrio-suite' ) }
									value={ item.url }
									onChange={ setItem( index, 'url' ) }
								/>
								<MediaField
									label={ __( 'Image', 'barrio-suite' ) }
									value={ item.image }
									onChange={ setItem( index, 'image' ) }
								/>
								<Text
									type="number"
									step="any"
									min={ 0 }
									label={ __( 'Price', 'barrio-suite' ) }
									help={ __(
										'Leave empty if the price depends on the request.',
										'barrio-suite'
									) }
									value={ item.price }
									onChange={ ( value ) =>
										setItem(
											index,
											'price'
										)( number( value ) )
									}
								/>
								<Select
									label={ __(
										'Availability',
										'barrio-suite'
									) }
									value={ item.availability }
									options={ AVAILABILITY }
									onChange={ setItem(
										index,
										'availability'
									) }
								/>
								<button
									type="button"
									className="barrio-button is-danger"
									onClick={ () =>
										setItems(
											o.offers.items.filter(
												( v, i ) => i !== index
											)
										)
									}
								>
									{ __( 'Remove offer', 'barrio-suite' ) }
								</button>
							</Details>
						) ) }
					</Card>
				</Fixed>
			</div>

			<div className="barrio-panel">
				<Preview
					organization={ o }
					description={ texts.organization_description }
				/>
			</div>
		</div>
	);
}

export const Organization = () => (
	<SettingsPage
		title={ __( 'Organization', 'barrio-suite' ) }
		render={ ( form ) => <OrganizationCards { ...form } /> }
	/>
);
