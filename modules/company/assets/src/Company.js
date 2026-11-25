// La pagina Barrio → Dati aziendali (modules/company/src/Module.php): l'azienda, i contatti, le sedi e i profili, con
// un solo Salva. Lo schema del SEO li legge da qui. Impostazioni, agli amministratori: come mostrarli e i Permessi.
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import {
	AddButton,
	Card,
	Details,
	Group,
	HowTo,
	Icon,
	Permissions,
	RemoveButton,
	SectionHead,
	Text,
	TextList,
} from '../../../../assets/src/admin/ui.js';
import { OFFICE, body, editable, officeTitle, problem } from './model.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const COMPANY = {
	icon: 'building',
	title: __( 'Company details', 'barrio-suite' ),
	description: __(
		'Name, contacts, legal details and profiles, for the footer and the schema',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #1e293b, #475569)',
};

const { canManageOptions } = window.barrioAdmin;

// Module::admin_data(), a chi modifica i dati aziendali. Con il modulo spento manca.
const companyData = window.barrioAdmin.company;

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_manage_company: __( 'Edit', 'barrio-suite' ) };

// I nomi dei dati, per lo shortcode: quelli di Module::data().
const FIELDS = [
	[ 'name', __( 'Name', 'barrio-suite' ) ],
	[ 'legal_name', __( 'Company name', 'barrio-suite' ) ],
	[ 'vat_id', __( 'VAT number', 'barrio-suite' ) ],
	[ 'tax_code', __( 'Tax code', 'barrio-suite' ) ],
	[ 'registration_number', __( 'REA number', 'barrio-suite' ) ],
	[ 'share_capital', __( 'Share capital', 'barrio-suite' ) ],
	[ 'certified_email', __( 'Certified email', 'barrio-suite' ) ],
	[ 'invoice_code', __( 'SDI code', 'barrio-suite' ) ],
	[ 'phone', __( 'Phone', 'barrio-suite' ) ],
	[ 'mobile', __( 'Mobile', 'barrio-suite' ) ],
	[ 'email', __( 'Email', 'barrio-suite' ) ],
	[ 'privacy_email', __( 'Privacy email', 'barrio-suite' ) ],
	[ 'address', __( 'Registered office', 'barrio-suite' ) ],
	[ 'offices', __( 'Operating offices', 'barrio-suite' ) ],
];

// I nomi dello shortcode, come le card della pagina, ognuno con il nome leggibile accanto.
const GROUPS = [
	[
		__( 'Company', 'barrio-suite' ),
		[
			'name',
			'legal_name',
			'vat_id',
			'tax_code',
			'registration_number',
			'share_capital',
			'invoice_code',
		],
	],
	[
		__( 'Contacts', 'barrio-suite' ),
		[ 'phone', 'mobile', 'email', 'certified_email', 'privacy_email' ],
	],
	[ __( 'Offices', 'barrio-suite' ), [ 'address', 'offices' ] ],
];

const Codes = ( { codes } ) => {
	const labels = Object.fromEntries( FIELDS );
	return (
		<div className="barrio-merge-tags">
			{ codes.map( ( code ) => (
				<span key={ code }>
					<code>{ code }</code> { labels[ code ] }
				</span>
			) ) }
		</div>
	);
};

// I telefoni o le email di una sede: il valore e l'etichetta facoltativa, una riga ciascuno.
function Contacts( {
	title,
	level,
	add,
	label,
	type,
	maxLength,
	values,
	onChange,
} ) {
	const set = ( index, contact ) =>
		onChange(
			values.map( ( item, i ) => ( i === index ? contact : item ) )
		);
	return (
		<>
			<SectionHead title={ title } level={ level }>
				<AddButton
					label={ add }
					onClick={ () =>
						onChange( [ ...values, { label: '', value: '' } ] )
					}
				/>
			</SectionHead>
			{ values.length > 0 && (
				<div className="barrio-choices">
					{ values.map( ( contact, index ) => (
						<div className="barrio-choice" key={ index }>
							<Text
								hideLabel
								label={ label }
								placeholder={ label }
								type={ type }
								maxLength={ maxLength }
								value={ contact.value }
								onChange={ ( value ) =>
									set( index, { ...contact, value } )
								}
							/>
							<Text
								hideLabel
								label={ __( 'Label', 'barrio-suite' ) }
								placeholder={ __(
									'Label, such as Sales',
									'barrio-suite'
								) }
								value={ contact.label }
								onChange={ ( text ) =>
									set( index, { ...contact, label: text } )
								}
							/>
							<RemoveButton
								onClick={ () =>
									onChange(
										values.filter( ( v, i ) => i !== index )
									)
								}
							/>
						</div>
					) ) }
				</div>
			) }
		</>
	);
}

// I campi di una sede, con il link alla mappa e i contatti. level è quello dei titoli dei contatti: 4 dentro un Details,
// che ha già il suo h3.
function AddressFields( { address, level = 3, onChange } ) {
	const set = ( name ) => ( value ) =>
		onChange( { ...address, [ name ]: value } );
	return (
		<>
			<Text
				label={ __( 'Office name', 'barrio-suite' ) }
				help={ __(
					'Optional, such as Head office or Milan warehouse. On the site it takes the place of Registered office or Operating office.',
					'barrio-suite'
				) }
				value={ address.name }
				onChange={ set( 'name' ) }
			/>
			<Text
				label={ __( 'Street and number', 'barrio-suite' ) }
				value={ address.street }
				onChange={ set( 'street' ) }
			/>
			<Text
				label={ __( 'Postal code', 'barrio-suite' ) }
				value={ address.postal_code }
				onChange={ set( 'postal_code' ) }
			/>
			<Text
				label={ __( 'City', 'barrio-suite' ) }
				value={ address.locality }
				onChange={ set( 'locality' ) }
			/>
			<Text
				label={ __( 'Province or region', 'barrio-suite' ) }
				value={ address.region }
				onChange={ set( 'region' ) }
			/>
			<Text
				label={ __( 'Country', 'barrio-suite' ) }
				help={ __( 'Two-letter code, such as IT.', 'barrio-suite' ) }
				maxLength={ 2 }
				value={ address.country }
				onChange={ ( value ) =>
					set( 'country' )( value.toUpperCase() )
				}
			/>
			<Text
				type="url"
				label={ __( 'Map', 'barrio-suite' ) }
				help={ __(
					'The link to the place on Google Maps or another map: on the site the address becomes a link.',
					'barrio-suite'
				) }
				value={ address.maps_url }
				onChange={ set( 'maps_url' ) }
			/>
			<Contacts
				level={ level }
				title={ __( 'Phones', 'barrio-suite' ) }
				add={ __( 'Add phone', 'barrio-suite' ) }
				label={ __( 'Phone', 'barrio-suite' ) }
				type="tel"
				maxLength={ 64 }
				values={ address.phones }
				onChange={ set( 'phones' ) }
			/>
			<Contacts
				level={ level }
				title={ __( 'Emails', 'barrio-suite' ) }
				add={ __( 'Add email', 'barrio-suite' ) }
				label={ __( 'Email', 'barrio-suite' ) }
				type="email"
				values={ address.emails }
				onChange={ set( 'emails' ) }
			/>
		</>
	);
}

// Module::data() toglie dal sito una sede senza indirizzo, anche con il nome e i contatti.
const WITH_ADDRESS = __(
	'An office is shown on the site only with an address.',
	'barrio-suite'
);

function CompanyCards( { draft, set } ) {
	const field = ( name ) => ( value ) => set( { ...draft, [ name ]: value } );
	const setOffices = field( 'offices' );
	return (
		<>
			<Card
				className="barrio-wide"
				icon="building"
				title={ __( 'Company', 'barrio-suite' ) }
				description={ __(
					'The name and the legal details, for the footer of the site and for search engines.',
					'barrio-suite'
				) }
			>
				<Text
					label={ __( 'Name', 'barrio-suite' ) }
					help={ __(
						'As customers know it, such as Trattoria Mario. Without a name, search engines get the name of the site.',
						'barrio-suite'
					) }
					value={ draft.name }
					onChange={ field( 'name' ) }
				/>
				<Text
					label={ __( 'Company name', 'barrio-suite' ) }
					help={ __(
						'The legal name, such as Trattoria Mario S.r.l. Empty, it is the name.',
						'barrio-suite'
					) }
					value={ draft.legal_name }
					onChange={ field( 'legal_name' ) }
				/>
				<Text
					label={ __( 'VAT number', 'barrio-suite' ) }
					value={ draft.vat_id }
					onChange={ field( 'vat_id' ) }
				/>
				<Text
					label={ __( 'Tax code', 'barrio-suite' ) }
					value={ draft.tax_code }
					onChange={ field( 'tax_code' ) }
				/>
				<Text
					label={ __( 'REA number', 'barrio-suite' ) }
					help={ __(
						'The number in the register of the Chamber of Commerce, such as NA-123456.',
						'barrio-suite'
					) }
					value={ draft.registration_number }
					onChange={ field( 'registration_number' ) }
				/>
				<Text
					label={ __( 'Share capital', 'barrio-suite' ) }
					help={ __(
						'As it must appear, such as € 10,000 fully paid.',
						'barrio-suite'
					) }
					value={ draft.share_capital }
					onChange={ field( 'share_capital' ) }
				/>
				<Text
					label={ __( 'SDI code', 'barrio-suite' ) }
					help={ __(
						'The recipient code for electronic invoices.',
						'barrio-suite'
					) }
					value={ draft.invoice_code }
					onChange={ field( 'invoice_code' ) }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="phone"
				title={ __( 'Contacts', 'barrio-suite' ) }
			>
				<Text
					type="tel"
					label={ __( 'Phone', 'barrio-suite' ) }
					value={ draft.phone }
					onChange={ field( 'phone' ) }
				/>
				<Text
					type="tel"
					label={ __( 'Mobile', 'barrio-suite' ) }
					value={ draft.mobile }
					onChange={ field( 'mobile' ) }
				/>
				<Text
					type="email"
					label={ __( 'Email', 'barrio-suite' ) }
					value={ draft.email }
					onChange={ field( 'email' ) }
				/>
				<Text
					type="email"
					label={ __( 'Certified email', 'barrio-suite' ) }
					help={ __( 'The PEC of the company.', 'barrio-suite' ) }
					value={ draft.certified_email }
					onChange={ field( 'certified_email' ) }
				/>
				<Text
					type="email"
					label={ __( 'Privacy email', 'barrio-suite' ) }
					help={ __(
						'For the requests about personal data, as written in the privacy policy.',
						'barrio-suite'
					) }
					value={ draft.privacy_email }
					onChange={ field( 'privacy_email' ) }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="location"
				title={ __( 'Registered office', 'barrio-suite' ) }
				description={ WITH_ADDRESS }
			>
				<AddressFields
					address={ draft.address }
					onChange={ field( 'address' ) }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="store"
				title={ __( 'Operating offices', 'barrio-suite' ) }
				description={ `${ __(
					'The other offices of the company, such as branches, warehouses or shops. Without them, the registered office is the only one.',
					'barrio-suite'
				) } ${ WITH_ADDRESS }` }
				actions={
					<AddButton
						label={ __( 'Add office', 'barrio-suite' ) }
						onClick={ () =>
							setOffices( [ ...draft.offices, OFFICE ] )
						}
					/>
				}
			>
				{ draft.offices.map( ( office, index ) => (
					<Details
						// Con una sede in più o in meno i pannelli si rimontano chiusi: tolta una sede, la successiva non
						// resta aperta con Rimuovi sotto il puntatore.
						key={ `${ index }/${ draft.offices.length }` }
						title={
							officeTitle( office ) ||
							__( 'New office', 'barrio-suite' )
						}
					>
						<AddressFields
							address={ office }
							level={ 4 }
							onChange={ ( next ) =>
								setOffices(
									draft.offices.map( ( item, i ) =>
										i === index ? next : item
									)
								)
							}
						/>
						<button
							type="button"
							className="barrio-button is-danger"
							onClick={ () =>
								setOffices(
									draft.offices.filter(
										( v, i ) => i !== index
									)
								)
							}
						>
							<Icon name="trash" />
							<span className="barrio-button__text">
								{ __( 'Remove office', 'barrio-suite' ) }
							</span>
						</button>
					</Details>
				) ) }
			</Card>

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
				values={ draft.profiles }
				onChange={ field( 'profiles' ) }
			/>
		</>
	);
}

// Un'email o un paese che il server rifiuterebbe, detti con il nome del campo o della sede operativa (model.js).
function problemMessage( [ field, type, index ], data ) {
	const label =
		'offices' === field
			? officeTitle( data.offices[ index ] ) ||
				sprintf(
					/* translators: %d: position of an operating office, like 2. */
					__( 'Operating office %d', 'barrio-suite' ),
					index + 1
				)
			: Object.fromEntries( FIELDS )[ field ];
	return 'email' === type
		? sprintf(
				/* translators: %s: name of a field, like Certified email. */
				__( '%s: enter a valid email address.', 'barrio-suite' ),
				label
			)
		: sprintf(
				/* translators: %s: name of an office, like Registered office. */
				__(
					'%s: the country is a two-letter code, such as IT.',
					'barrio-suite'
				),
				label
			);
}

// La pagina, per General (model.js).
export const companySection = {
	id: 'company',
	load: () => apiFetch( { path: '/barrio/v1/company' } ),
	value: editable,
	save: ( draft ) => {
		const data = body( draft );
		const wrong = problem( data );
		return wrong
			? Promise.reject( new Error( problemMessage( wrong, data ) ) )
			: apiFetch( { path: '/barrio/v1/company', method: 'PUT', data } );
	},
	Cards: CompanyCards,
};

// La scheda Impostazioni, agli amministratori: come mostrarli e i Permessi.
export const companySettingsSection = {
	id: 'company-settings',
	load: () => apiFetch( { path: '/barrio/v1/company' } ),
	value: ( data ) => data.roles,
	save: ( roles ) =>
		apiFetch( {
			path: '/barrio/v1/company',
			method: 'PUT',
			data: { roles },
		} ),
	Cards: ( { data, draft, set } ) => (
		<>
			<HowTo
				description={ __(
					'The Company details block, or the shortcode with the names of the details.',
					'barrio-suite'
				) }
			>
				<Group label={ __( 'Shortcode', 'barrio-suite' ) }>
					<Codes
						codes={ [
							'[barrio_company]',
							'[barrio_company layout="list"]',
							'[barrio_company fields="legal_name,vat_id"]',
						] }
					/>
				</Group>
				{ GROUPS.map( ( [ title, names ] ) => (
					<Group key={ title } label={ title }>
						<Codes codes={ names } />
					</Group>
				) ) }
			</HowTo>
			<Permissions
				description={ __(
					'Who can edit the company details. Administrators can always do everything; only administrators change these settings.',
					'barrio-suite'
				) }
				caps={ CAPS }
				names={ data.role_names }
				roles={ draft }
				onChange={ set }
			/>
		</>
	),
};

// Nella Bacheca, larga sotto la messa online: i dati principali in una riga, con — dove mancano.
export function CompanyCard() {
	if ( ! companyData ) {
		return null;
	}
	const labels = Object.fromEntries( FIELDS );
	return (
		<section className="barrio-card barrio-module barrio-company-card">
			<header
				className="barrio-module__head"
				style={ { background: COMPANY.color } }
			>
				<span className="barrio-module__icon">
					<Icon name={ COMPANY.icon } />
				</span>
				<div>
					<h2 className="barrio-module__title">{ COMPANY.title }</h2>
					<p>{ COMPANY.description }</p>
				</div>
			</header>
			<div className="barrio-module__body">
				<dl className="barrio-company-card__details">
					{ Object.entries( companyData ).map(
						( [ field, text ] ) => (
							<div key={ field }>
								<dt>{ labels[ field ] }</dt>
								<dd title={ text }>{ text || '—' }</dd>
							</div>
						)
					) }
				</dl>
				<div className="barrio-row is-fill">
					<a
						className="barrio-button is-primary"
						href="?page=barrio-company"
					>
						<Icon name="edit" />
						<span className="barrio-button__text">
							{ __( 'Edit', 'barrio-suite' ) }
						</span>
					</a>
					{ canManageOptions && (
						<a
							className="barrio-button"
							href="?page=barrio-company&tab=settings"
						>
							<Icon name="admin-generic" />
							<span className="barrio-button__text">
								{ __( 'Settings', 'barrio-suite' ) }
							</span>
						</a>
					) }
				</div>
			</div>
		</section>
	);
}
