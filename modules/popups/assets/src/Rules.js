// Le regole dei popup nell'admin (modules/popups/src/Rules.php): le card Dove e Chi dell'editor e il Dove breve della
// tabella. Le scelte salvano gli ID; i loro nomi (labels) arrivano dal server con il popup e, per quelli appena
// aggiunti, dalla ricerca.
import apiFetch from '@wordpress/api-fetch';
import { Dropdown, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	Group,
	Icon,
	Select,
	Toggle,
} from '../../../../assets/src/admin/ui.js';

const nameOf = ( names, id ) => names[ id ] ?? `#${ id }`;

const typeNames = ( types ) =>
	Object.fromEntries( types.map( ( type ) => [ type.name, type.label ] ) );

/**
 * Dove, in breve, per le tabelle.
 *
 * @param {Object} where
 * @param {Object} labels { posts, terms }: i nomi per ID.
 * @param {Array}  types  { name, label } dei tipi di contenuto.
 * @return {string} Il testo.
 */
export function whereText( where, labels, types ) {
	if ( 'all' === where.mode ) {
		return __( 'Whole site', 'barrio-suite' );
	}
	const names = typeNames( types );
	const chosen =
		[
			where.home && __( 'Home page', 'barrio-suite' ),
			...where.posts.map( ( id ) => nameOf( labels.posts, id ) ),
			...where.types.map( ( type ) => names[ type ] ?? type ),
			...where.terms.map( ( id ) => nameOf( labels.terms, id ) ),
		]
			.filter( Boolean )
			.join( ', ' ) || '—';
	return 'only' === where.mode
		? /* translators: %s: pages, separated by commas. */
			sprintf( __( 'Only: %s', 'barrio-suite' ), chosen )
		: /* translators: %s: pages, separated by commas. */
			sprintf( __( 'Except: %s', 'barrio-suite' ), chosen );
}

// I risultati della ricerca dei contenuti o dei termini (/wp/v2/search), senza quelli già scelti. Senza testo i più
// recenti.
function Results( { kind, chosen, onPick } ) {
	const input = useRef();
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState( null );
	useEffect( () => {
		let current = true;
		const timer = setTimeout(
			() =>
				apiFetch( {
					path:
						'/wp/v2/search?' +
						new URLSearchParams( {
							search,
							type: kind,
							per_page: 20,
							_fields: 'id,title,subtype',
						} ),
				} )
					.then( ( items ) => current && setFound( items ) )
					.catch( () => current && setFound( [] ) ),
			search ? 300 : 0
		);
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ kind, search ] );
	const { contentTypes } = window.barrioAdmin;
	const groups = typeNames( [
		...contentTypes.post_types,
		...contentTypes.taxonomies,
	] );
	const items = ( found ?? [] ).filter(
		( item ) => ! chosen.includes( item.id )
	);
	return (
		<div className="barrio-picker">
			<input
				ref={ input }
				type="search"
				className="barrio-picker__search"
				placeholder={ __( 'Search', 'barrio-suite' ) }
				aria-label={ __( 'Search', 'barrio-suite' ) }
				value={ search }
				onChange={ ( event ) => setSearch( event.target.value ) }
			/>
			<div className="barrio-picker__list">
				{ null === found && <Spinner /> }
				{ items.map( ( item ) => (
					<button
						type="button"
						key={ item.id }
						className="barrio-picker__option"
						// Il bottone scelto sparisce dall'elenco: il focus torna alla ricerca, così Esc chiude ancora.
						onClick={ () => {
							onPick(
								item.id,
								decodeEntities( item.title ) ||
									__( '(no title)', 'barrio-suite' )
							);
							input.current.focus();
						} }
					>
						{ decodeEntities( item.title ) ||
							__( '(no title)', 'barrio-suite' ) }
						<span className="barrio-picker__count">
							{ groups[ item.subtype ] ?? item.subtype }
						</span>
					</button>
				) ) }
				{ found && ! items.length && (
					<p className="barrio-picker__empty">
						{ __( 'No results.', 'barrio-suite' ) }
					</p>
				) }
			</div>
		</div>
	);
}

// Contenuti o termini scelti uno per uno: i nomi con la X per toglierli e Aggiungi, che apre la ricerca.
function Lookup( { label, help, kind, ids, names, onChange } ) {
	return (
		<Group label={ label } help={ help }>
			{ ids.length > 0 && (
				<div className="barrio-tokens">
					{ ids.map( ( id ) => (
						<span key={ id } className="barrio-token">
							{ nameOf( names, id ) }
							<button
								type="button"
								className="barrio-token__remove"
								aria-label={ sprintf(
									/* translators: %s: name of a page or a term. */
									__( 'Remove %s', 'barrio-suite' ),
									nameOf( names, id )
								) }
								onClick={ () =>
									onChange(
										ids.filter( ( other ) => other !== id ),
										names
									)
								}
							>
								<Icon name="no-alt" />
							</button>
						</span>
					) ) }
				</div>
			) }
			<Dropdown
				className="barrio-lookup"
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<button
						type="button"
						className="barrio-button"
						aria-expanded={ isOpen }
						onClick={ onToggle }
					>
						<Icon name="plus-alt2" />
						<span className="barrio-button__text">
							{ __( 'Add', 'barrio-suite' ) }
						</span>
					</button>
				) }
				renderContent={ () => (
					<Results
						kind={ kind }
						chosen={ ids }
						onPick={ ( id, name ) =>
							onChange( [ ...ids, id ], {
								...names,
								[ id ]: name,
							} )
						}
					/>
				) }
			/>
		</Group>
	);
}

/**
 * Dove: tutto il sito, solo in, o ovunque tranne la home, contenuti scelti, tutti i contenuti di un tipo e termini.
 *
 * @param {Object}                                  props
 * @param {Object}                                  props.where
 * @param {Object}                                  props.labels   { posts, terms }: i nomi per ID.
 * @param {Array}                                   props.types    { name, label } dei tipi con una pagina nel sito.
 * @param {(where: Object, labels: Object) => void} props.onChange
 */
export function WhereCard( { where, labels, types, onChange } ) {
	const set = ( changes, next = labels ) =>
		onChange( { ...where, ...changes }, next );
	return (
		<Card
			icon="location"
			title={ __( 'Where', 'barrio-suite' ) }
			description={ __(
				'The pages of the site where it shows up. In other languages the translations of the chosen pages count too.',
				'barrio-suite'
			) }
		>
			<Select
				label={ __( 'Pages', 'barrio-suite' ) }
				value={ where.mode }
				options={ [
					{
						value: 'all',
						label: __( 'The whole site', 'barrio-suite' ),
					},
					{
						value: 'only',
						label: __( 'Only on the pages below', 'barrio-suite' ),
					},
					{
						value: 'except',
						label: __(
							'Everywhere except the pages below',
							'barrio-suite'
						),
					},
				] }
				onChange={ ( mode ) => set( { mode } ) }
			/>
			{ 'all' !== where.mode && (
				<>
					<Toggle
						label={ __( 'Home page', 'barrio-suite' ) }
						checked={ where.home }
						onChange={ ( home ) => set( { home } ) }
					/>
					<Lookup
						label={ __( 'Contents', 'barrio-suite' ) }
						help={ __(
							'Pages, posts and other contents, one by one.',
							'barrio-suite'
						) }
						kind="post"
						ids={ where.posts }
						names={ labels.posts }
						onChange={ ( posts, names ) =>
							set( { posts }, { ...labels, posts: names } )
						}
					/>
					<Group
						label={ __( 'Content types', 'barrio-suite' ) }
						help={ __(
							'All the contents of a type.',
							'barrio-suite'
						) }
					>
						<div className="barrio-blocks">
							{ types.map( ( type ) => (
								<Toggle
									key={ type.name }
									label={ type.label }
									checked={ where.types.includes(
										type.name
									) }
									onChange={ ( on ) =>
										set( {
											types: on
												? [ ...where.types, type.name ]
												: where.types.filter(
														( name ) =>
															name !== type.name
													),
										} )
									}
								/>
							) ) }
						</div>
					</Group>
					<Lookup
						label={ __( 'Terms', 'barrio-suite' ) }
						help={ __(
							'The contents with the term, such as a category, and its archive.',
							'barrio-suite'
						) }
						kind="term"
						ids={ where.terms }
						names={ labels.terms }
						onChange={ ( terms, names ) =>
							set( { terms }, { ...labels, terms: names } )
						}
					/>
				</>
			) }
		</Card>
	);
}

export const WhoCard = ( { who, onChange } ) => (
	<Card icon="groups" title={ __( 'Who', 'barrio-suite' ) }>
		<Select
			label={ __( 'Devices', 'barrio-suite' ) }
			help={ __(
				'Phones are the screens narrower than 768 pixels.',
				'barrio-suite'
			) }
			value={ who.device }
			options={ [
				{ value: 'all', label: __( 'All devices', 'barrio-suite' ) },
				{
					value: 'desktop',
					label: __( 'Only computers and tablets', 'barrio-suite' ),
				},
				{ value: 'mobile', label: __( 'Only phones', 'barrio-suite' ) },
			] }
			onChange={ ( device ) => onChange( { ...who, device } ) }
		/>
		<Select
			label={ __( 'Visitors', 'barrio-suite' ) }
			value={ who.users }
			options={ [
				{ value: 'all', label: __( 'Everyone', 'barrio-suite' ) },
				{
					value: 'guests',
					label: __( 'Only who is not logged in', 'barrio-suite' ),
				},
				{
					value: 'members',
					label: __( 'Only logged-in users', 'barrio-suite' ),
				},
			] }
			onChange={ ( users ) => onChange( { ...who, users } ) }
		/>
	</Card>
);
