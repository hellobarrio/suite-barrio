// La scheda Blocchi di Barrio → Editor (modules/blocks/src/Module.php): blocchi e restrizioni per ruolo, l'editor
// per tipo di contenuto e i contenuti esterni. E i suoi numeri nella card Editor della Bacheca.
import apiFetch from '@wordpress/api-fetch';
import { useId, useState } from '@wordpress/element';
import { __, _x } from '@wordpress/i18n';

import {
	Card,
	Group,
	Picker,
	SectionHead,
	Select,
	Toggle,
} from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const BLOCKS = {
	title: __( 'Blocks', 'barrio-suite' ),
	description: __(
		'The blocks and the editor restrictions of each role, and the editor of each content type.',
		'barrio-suite'
	),
};

// Module::admin_data(): le impostazioni salvate. Con il modulo spento manca.
export const blocksData = window.barrioAdmin.blocks;

const RESTRICTIONS = {
	h1: [
		__( 'No heading 1', 'barrio-suite' ),
		__(
			'The H1 is the title of the page. Headings already saved stay as they are.',
			'barrio-suite'
		),
	],
	styles: [
		__( 'Only the colors and font sizes of the theme', 'barrio-suite' ),
		__(
			'No custom colors, font sizes, line heights and other typography settings. Blocks styled by an administrator keep their style.',
			'barrio-suite'
		),
	],
	code: [
		__( 'No code editor', 'barrio-suite' ),
		__(
			'Neither the code editor of the content nor Edit as HTML of the blocks.',
			'barrio-suite'
		),
	],
	lock: [
		__( 'No locking and unlocking of blocks', 'barrio-suite' ),
		__( 'Blocks locked by an administrator stay locked.', 'barrio-suite' ),
	],
	panels: [
		__( 'Hide Author, Revisions and Parent', 'barrio-suite' ),
		__(
			'In the settings of the content. Revisions are still saved.',
			'barrio-suite'
		),
	],
};

const NONE = {
	disabled: [],
	...Object.fromEntries(
		Object.keys( RESTRICTIONS ).map( ( key ) => [ key, false ] )
	),
};

const restricted = ( role ) =>
	role.disabled.length > 0 ||
	Object.keys( RESTRICTIONS ).some( ( key ) => role[ key ] );

// Per la card Editor della Bacheca, dalle impostazioni salvate.
export const blocksStats = () => [
	[
		__( 'Roles with restrictions', 'barrio-suite' ),
		Object.keys( blocksData.roles ).length,
	],
	[
		__( 'Content types with a chosen editor', 'barrio-suite' ),
		Object.keys( blocksData.editors ).length,
	],
];

// I blocchi di un ruolo, per categoria. Tutti e Nessuno valgono per quelli trovati dalla ricerca.
function BlockList( { blocks, categories, disabled, onChange } ) {
	const id = useId();
	const [ search, setSearch ] = useState( '' );
	const term = search.trim().toLowerCase();
	const found = blocks.filter( ( block ) =>
		block.title.toLowerCase().includes( term )
	);
	const names = found.map( ( { name } ) => name );
	const known = categories.map( ( { slug } ) => slug );
	const groups = [
		...categories.map( ( { slug, title } ) => [
			title,
			found.filter( ( block ) => block.category === slug ),
		] ),
		[
			__( 'Other', 'barrio-suite' ),
			found.filter( ( block ) => ! known.includes( block.category ) ),
		],
	].filter( ( [ , list ] ) => list.length );
	// In ordine, così lo stesso elenco salvato non risulta cambiato.
	const change = ( list ) => onChange( [ ...new Set( list ) ].sort() );
	return (
		<>
			<SectionHead
				title={ __( 'Available blocks', 'barrio-suite' ) }
				level={ 3 }
			>
				<input
					type="search"
					className="barrio-input"
					placeholder={ __( 'Search blocks', 'barrio-suite' ) }
					aria-label={ __( 'Search blocks', 'barrio-suite' ) }
					value={ search }
					onChange={ ( event ) => setSearch( event.target.value ) }
				/>
				<button
					type="button"
					className="barrio-button"
					onClick={ () =>
						change(
							disabled.filter(
								( name ) => ! names.includes( name )
							)
						)
					}
				>
					{ __( 'All', 'barrio-suite' ) }
				</button>
				<button
					type="button"
					className="barrio-button"
					onClick={ () => change( [ ...disabled, ...names ] ) }
				>
					{ _x( 'None', 'blocks', 'barrio-suite' ) }
				</button>
			</SectionHead>
			{ groups.map( ( [ title, list ] ) => (
				<Group key={ title } label={ title }>
					<div className="barrio-blocks">
						{ list.map( ( block ) => (
							<label
								key={ block.name }
								className="barrio-choice"
								htmlFor={ `${ id }-${ block.name }` }
							>
								<input
									type="checkbox"
									id={ `${ id }-${ block.name }` }
									checked={
										! disabled.includes( block.name )
									}
									onChange={ ( event ) =>
										change(
											event.target.checked
												? disabled.filter(
														( name ) =>
															name !== block.name
													)
												: [ ...disabled, block.name ]
										)
									}
								/>
								{ block.title }
							</label>
						) ) }
					</div>
				</Group>
			) ) }
			{ ! groups.length && (
				<p>{ __( 'No results.', 'barrio-suite' ) }</p>
			) }
		</>
	);
}

function RolesCard( { available, draft, set } ) {
	const names = Object.keys( available.roles );
	const [ role, setRole ] = useState(
		names.includes( 'editor' ) ? 'editor' : names[ 0 ]
	);
	if ( ! role ) {
		return null;
	}
	const current = draft.roles[ role ] ?? NONE;
	// Nell'ordine dei ruoli e senza quelli senza restrizioni, come li salva il server.
	const change = ( values ) => {
		const roles = { ...draft.roles, [ role ]: { ...current, ...values } };
		set( {
			...draft,
			roles: Object.fromEntries(
				names
					.filter(
						( name ) => roles[ name ] && restricted( roles[ name ] )
					)
					.map( ( name ) => [ name, roles[ name ] ] )
			),
		} );
	};
	return (
		<Card
			className="barrio-wide"
			icon="block-default"
			title={ __( 'Blocks and restrictions', 'barrio-suite' ) }
			description={ __(
				'What the users with this role find in the editor. Administrators always have everything, and nothing changes on the site.',
				'barrio-suite'
			) }
			actions={
				<Picker
					label={ __( 'Role', 'barrio-suite' ) }
					text={ available.roles[ role ] }
					options={ names.map( ( name ) => ( {
						value: name,
						label: available.roles[ name ],
					} ) ) }
					value={ role }
					onChange={ setRole }
				/>
			}
		>
			{ Object.entries( RESTRICTIONS ).map(
				( [ key, [ label, help ] ] ) => (
					<Toggle
						key={ key }
						label={ label }
						help={ help }
						checked={ current[ key ] }
						onChange={ ( on ) => change( { [ key ]: on } ) }
					/>
				)
			) }
			<BlockList
				blocks={ available.blocks }
				categories={ available.categories }
				disabled={ current.disabled }
				onChange={ ( disabled ) => change( { disabled } ) }
			/>
		</Card>
	);
}

// GET e PUT /barrio/v1/blocks, con il Salva della barra (general/General.js).
export const blocksSection = {
	id: 'blocks',
	load: () => apiFetch( { path: '/barrio/v1/blocks' } ),
	value: ( { available, ...settings } ) => settings,
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/blocks', method: 'PUT', data: draft } ),
	Cards: ( { data, draft, set } ) => {
		const { available } = data;
		const setEditor = ( type, editor ) =>
			set( {
				...draft,
				editors: Object.fromEntries(
					available.types
						.map( ( { name } ) => [
							name,
							name === type ? editor : draft.editors[ name ],
						] )
						.filter( ( [ , value ] ) => value )
				),
			} );
		return (
			<>
				<RolesCard
					available={ available }
					draft={ draft }
					set={ set }
				/>
				<Card
					className="barrio-wide"
					icon="edit"
					title={ __( 'Editor by content type', 'barrio-suite' ) }
					description={ __(
						'With No editor the screen keeps the title and the other fields, and the content stays in the REST API and in GraphQL.',
						'barrio-suite'
					) }
				>
					{ available.types.map( ( type ) => (
						<Select
							key={ type.name }
							label={ type.label }
							value={ draft.editors[ type.name ] ?? '' }
							options={ [
								{
									value: '',
									label: __(
										'WordPress default',
										'barrio-suite'
									),
								},
								type.blocks && {
									value: 'blocks',
									label: __( 'Block editor', 'barrio-suite' ),
								},
								{
									value: 'classic',
									label: __(
										'Classic editor',
										'barrio-suite'
									),
								},
								{
									value: 'none',
									label: __( 'No editor', 'barrio-suite' ),
								},
							].filter( Boolean ) }
							onChange={ ( editor ) =>
								setEditor( type.name, editor )
							}
						/>
					) ) }
				</Card>
				<Card
					className="barrio-wide"
					icon="cloud"
					title={ __( 'External content', 'barrio-suite' ) }
					description={ __(
						'What the inserter of the editor offers from other services, for everyone who writes.',
						'barrio-suite'
					) }
				>
					<Toggle
						label={ __(
							'Patterns from WordPress.org',
							'barrio-suite'
						) }
						help={ __(
							'The patterns of the WordPress.org directory, downloaded by the site.',
							'barrio-suite'
						) }
						checked={ draft.remote_patterns }
						onChange={ ( on ) =>
							set( { ...draft, remote_patterns: on } )
						}
					/>
					<Toggle
						label={ __( 'Openverse images', 'barrio-suite' ) }
						help={ __(
							'Free images to search in the Media tab: the browser of whoever writes asks Openverse.',
							'barrio-suite'
						) }
						checked={ draft.openverse }
						onChange={ ( on ) =>
							set( { ...draft, openverse: on } )
						}
					/>
				</Card>
			</>
		);
	},
};
