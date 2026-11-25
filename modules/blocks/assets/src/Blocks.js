// La scheda Blocchi di Barrio → Contenuti (modules/blocks/src/Module.php): blocchi e restrizioni per ruolo, l'editor
// per tipo di contenuto e i contenuti esterni. E i suoi numeri nella card Contenuti della Bacheca.
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

import {
	Card,
	Fixed,
	Group,
	Icon,
	Notice,
	Picker,
	Search,
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

// Per la card Contenuti della Bacheca, dalle impostazioni salvate: un numero per strumento.
export const blocksStats = () => [
	[
		__( 'Roles with restrictions', 'barrio-suite' ),
		Object.keys( blocksData.roles ).length,
	],
];

// I blocchi di un ruolo, per categoria. Tutti e Nessuno valgono per quelli trovati dalla ricerca.
function BlockList( { blocks, categories, disabled, onChange } ) {
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
				<Search
					label={ __( 'Search blocks', 'barrio-suite' ) }
					value={ search }
					onChange={ setSearch }
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
					<Icon name="yes" />
					<span className="barrio-button__text">
						{ __( 'All', 'barrio-suite' ) }
					</span>
				</button>
				<button
					type="button"
					className="barrio-button"
					onClick={ () => change( [ ...disabled, ...names ] ) }
				>
					<Icon name="minus" />
					<span className="barrio-button__text">
						{ _x( 'None', 'blocks', 'barrio-suite' ) }
					</span>
				</button>
			</SectionHead>
			{ groups.map( ( [ title, list ] ) => (
				<Group key={ title } label={ title }>
					<div className="barrio-blocks">
						{ list.map( ( block ) => (
							<Toggle
								key={ block.name }
								label={ block.title }
								checked={ ! disabled.includes( block.name ) }
								onChange={ ( on ) =>
									change(
										on
											? disabled.filter(
													( name ) =>
														name !== block.name
												)
											: [ ...disabled, block.name ]
									)
								}
							/>
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

// Come la lingua principale: ha sempre tutto, e con lui si cambiano le impostazioni uguali per tutti i ruoli.
const ADMIN = 'administrator';

// Le scelte per nome nell'ordine dei ruoli o dei tipi che esistono, come le salva il server. Quelle di un ruolo o di
// un tipo che ora non c'è (il suo plugin è spento) restano in fondo, come in Ordinamento.
const ordered = ( values, names ) =>
	[
		...names,
		...Object.keys( values ).filter( ( name ) => ! names.includes( name ) ),
	].map( ( name ) => [ name, values[ name ] ] );

// Il ruolo scelto nella barra (Tools); all'inizio l'amministratore.
const roleOf = ( { roles }, view ) =>
	view.role ?? ( roles[ ADMIN ] ? ADMIN : Object.keys( roles )[ 0 ] );

// Le card uguali per tutti i ruoli si cambiano con l'amministratore; un sito senza quel ruolo le cambia con tutti.
const shared = ( { roles }, role ) => role === ADMIN || ! roles[ ADMIN ];

function RolesCard( { available, role, draft, set } ) {
	if ( ! role ) {
		return null;
	}
	const names = Object.keys( available.roles );
	const current = draft.roles[ role ] ?? NONE;
	// Senza i ruoli senza restrizioni, come li salva il server.
	const change = ( values ) =>
		set( {
			...draft,
			roles: Object.fromEntries(
				ordered(
					{ ...draft.roles, [ role ]: { ...current, ...values } },
					names
				).filter( ( [ , value ] ) => value && restricted( value ) )
			),
		} );
	return (
		<Card
			className="barrio-wide"
			icon="block-default"
			title={ sprintf(
				/* translators: %s: role name, such as Editor. */
				__( 'Blocks and restrictions for %s', 'barrio-suite' ),
				available.roles[ role ]
			) }
			description={ __(
				'What the users with the role chosen at the top find in the editor. Administrators always have everything, and nothing changes on the site.',
				'barrio-suite'
			) }
		>
			{ /* Gli amministratori non si salvano: tutto acceso e fisso. */ }
			<Fixed disabled={ ADMIN === role }>
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
			</Fixed>
		</Card>
	);
}

// GET e PUT /barrio/v1/blocks, con il Salva della barra (general/General.js), e nella barra il ruolo della prima card.
export const blocksSection = {
	id: 'blocks',
	load: () => apiFetch( { path: '/barrio/v1/blocks' } ),
	value: ( { available, ...settings } ) => settings,
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/blocks', method: 'PUT', data: draft } ),
	Tools: ( { data, view, setView } ) => {
		const { roles } = data.available;
		const role = roleOf( data.available, view );
		if ( ! role ) {
			return null;
		}
		return (
			<Picker
				icon="groups"
				label={ __( 'Role', 'barrio-suite' ) }
				text={ roles[ role ] }
				active={ ! shared( data.available, role ) }
				options={ Object.keys( roles ).map( ( name ) => ( {
					value: name,
					label: roles[ name ],
				} ) ) }
				value={ role }
				onChange={ ( value ) => setView( { ...view, role: value } ) }
			/>
		);
	},
	Cards: ( { data, draft, set, view } ) => {
		const { available } = data;
		const role = roleOf( available, view );
		const setEditor = ( type, editor ) =>
			set( {
				...draft,
				editors: Object.fromEntries(
					ordered(
						{ ...draft.editors, [ type ]: editor },
						available.types.map( ( { name } ) => name )
					).filter( ( [ , value ] ) => value )
				),
			} );
		return (
			<>
				<Notice
					notice={
						! shared( available, role ) && {
							status: 'info',
							message: sprintf(
								/* translators: 1: role being edited, such as Editor; 2: name of the administrator role. */
								__(
									'You are editing the %1$s role. The settings in grey are the same for every role: change them with %2$s.',
									'barrio-suite'
								),
								available.roles[ role ],
								available.roles[ ADMIN ]
							),
						}
					}
				/>
				<RolesCard
					available={ available }
					role={ role }
					draft={ draft }
					set={ set }
				/>
				<Fixed disabled={ ! shared( available, role ) }>
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
										label: __(
											'Block editor',
											'barrio-suite'
										),
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
										label: __(
											'No editor',
											'barrio-suite'
										),
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
				</Fixed>
			</>
		);
	},
};
