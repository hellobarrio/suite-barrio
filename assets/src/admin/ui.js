// Componenti di base delle pagine admin: HTML nativo con lo stile di admin.css. Da
// @wordpress/components si usa solo ciò che l'HTML non ha (modale, popover e menu, schede).
import { speak } from '@wordpress/a11y';
import {
	Dropdown,
	MenuItem,
	Modal,
	NavigableMenu,
	Spinner,
} from '@wordpress/components';
import {
	cloneElement,
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { PER_PAGE, nextSort, pages } from './query.js';

// La validazione degli argomenti REST mette i dettagli in data.params ("fields[2][key] …").
export const errorMessage = ( error ) =>
	error.data?.params
		? Object.values( error.data.params ).join( ' ' )
		: error.message;

// Lo stato di un segreto salvato (una chiave API), che non torna mai dal server.
export const SECRET_HELP = {
	saved: __( 'Saved: leave empty to keep it.', 'barrio-suite' ),
	unreadable: __(
		'The saved value can no longer be read, probably because the WordPress security keys have changed. Enter it again.',
		'barrio-suite'
	),
};

// Un download (file, CSV) è un link e non passa da apiFetch: il nonce va nell'URL. Con i
// permalink semplici l'URL della REST ha già un "?" (?rest_route=).
export function restLink( path, args = {} ) {
	const url = window.barrioAdmin.rest.url + path;
	const query = new URLSearchParams( {
		...args,
		_wpnonce: window.barrioAdmin.rest.nonce,
	} );
	return `${ url }${ url.includes( '?' ) ? '&' : '?' }${ query }`;
}

export const Icon = ( { name } ) => (
	<span className={ `dashicons dashicons-${ name }` } aria-hidden="true" />
);

// Icone di Lucide (licenza ISC), dove le dashicons non bastano.
const LUCIDE = {
	'arrow-up': [ 'm5 12 7-7 7 7', 'M12 19V5' ],
	'arrow-down': [ 'M12 5v14', 'm19 12-7 7-7-7' ],
	'arrow-up-down': [
		'm21 16-4 4-4-4',
		'M17 20V4',
		'm3 8 4-4 4 4',
		'M7 4v16',
	],
	save: [
		'M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
		'M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7',
		'M7 3v4a1 1 0 0 0 1 1h7',
	],
};

export const Lucide = ( { name, size = 16 } ) => (
	<svg
		viewBox="0 0 24 24"
		width={ size }
		height={ size }
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		{ LUCIDE[ name ].map( ( d ) => (
			<path key={ d } d={ d } />
		) ) }
	</svg>
);

// Le modifiche non salvate non si perdono chiudendo o ricaricando la pagina per sbaglio.
export function useUnsavedWarning( dirty ) {
	useEffect( () => {
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		if ( dirty ) {
			window.addEventListener( 'beforeunload', warn );
		}
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );
}

// Porta in vista un elemento appena aggiunto (per esempio in fondo a un elenco lungo).
export function useScrollIntoView( active ) {
	const ref = useRef();
	useEffect( () => {
		if ( active ) {
			ref.current?.scrollIntoView( {
				behavior: window.matchMedia(
					'(prefers-reduced-motion: reduce)'
				).matches
					? 'auto'
					: 'smooth',
				block: 'nearest',
			} );
		}
	}, [ active ] );
	return ref;
}

export const Loading = () => (
	<div className="barrio-loading">
		<Spinner />
	</div>
);

// Il codice di una lingua (EN-US) con la sua bandiera, se la fonte delle lingue ne ha una (WPML).
export const Lang = ( { code } ) => (
	<span className="barrio-lang">
		{ window.barrioAdmin.flags[ code ] && (
			<img
				className="barrio-flag"
				src={ window.barrioAdmin.flags[ code ] }
				alt=""
			/>
		) }
		{ code.toUpperCase() }
	</span>
);

// Una scelta di un filtro per lingua, con la bandiera.
export const langOption = ( code, label = code.toUpperCase() ) => ( {
	value: code,
	label,
	image: window.barrioAdmin.flags[ code ],
} );

/**
 * Nella barra delle pagine con testi tradotti (builder, impostazioni SEO): la lingua dei testi da modificare.
 * Niente con una lingua sola.
 *
 * @param {Object}                 props
 * @param {string}                 props.value
 * @param {(lang: string) => void} props.onChange
 */
export function LangPicker( { value, onChange } ) {
	const [ main, ...others ] = window.barrioAdmin.languages;
	return (
		others.length > 0 && (
			<Picker
				label={ __( 'Language', 'barrio-suite' ) }
				text={ value.toUpperCase() }
				image={ langOption( value ).image }
				className="barrio-bar__lang"
				active={ value !== main }
				options={ [
					langOption(
						main,
						sprintf(
							/* translators: %s: language code, like IT. */
							__( '%s (main language)', 'barrio-suite' ),
							main.toUpperCase()
						)
					),
					...others.map( ( code ) => langOption( code ) ),
				] }
				value={ value }
				onChange={ onChange }
			/>
		)
	);
}

// Sotto la barra, quando si traducono i testi in un'altra lingua.
export function LangNotice( { lang } ) {
	const main = window.barrioAdmin.languages[ 0 ];
	return (
		<Notice
			notice={
				lang !== main && {
					status: 'info',
					message: sprintf(
						/* translators: 1: code of the language being translated, like EN; 2: code of the main language, like IT. */
						__(
							'You are editing the %1$s texts: an empty text shows the %2$s one. The settings in grey are the same in every language: change them in %2$s.',
							'barrio-suite'
						),
						lang.toUpperCase(),
						main.toUpperCase()
					),
				}
			}
		/>
	);
}

// Impostazioni uguali in tutte le lingue: nelle altre lingue il fieldset le disattiva tutte. Con disabled invece
// della lingua, quelle uguali per tutti i ruoli di Blocchi.
export const Fixed = ( {
	lang,
	disabled = lang !== window.barrioAdmin.languages[ 0 ],
	children,
} ) => (
	<fieldset className="barrio-fixed" disabled={ disabled }>
		{ children }
	</fieldset>
);

// addon: un bottone accanto al controllo (per esempio "Mostra" della password); actions: bottoni sotto, larghi
// quanto il controllo.
export function Field( { label, help, addon, actions, children } ) {
	const id = useId();
	const control = cloneElement( children, {
		id,
		'aria-describedby': help ? `${ id }-help` : undefined,
	} );
	return (
		<div className="barrio-field">
			<label className="barrio-field__label" htmlFor={ id }>
				{ label }
			</label>
			<div className="barrio-field__control">
				{ addon ? (
					<div className="barrio-row is-nowrap">
						{ control }
						{ addon }
					</div>
				) : (
					control
				) }
				{ actions && (
					<div className="barrio-row is-fill">{ actions }</div>
				) }
				{ help && (
					<p className="barrio-field__help" id={ `${ id }-help` }>
						{ help }
					</p>
				) }
			</div>
		</div>
	);
}

// Come Field, per ciò che non è un singolo controllo (un'etichetta <label> non avrebbe a chi riferirsi).
export function Group( { label, help, children } ) {
	const id = useId();
	return (
		<div className="barrio-field" role="group" aria-labelledby={ id }>
			<span className="barrio-field__label" id={ id }>
				{ label }
			</span>
			<div className="barrio-field__control">
				{ children }
				{ help && <p className="barrio-field__help">{ help }</p> }
			</div>
		</div>
	);
}

// Un testo facoltativo vuoto lo trasforma in null chi lo usa (docs/database.md: NULL = standard).
export function Text( {
	label,
	help,
	addon,
	actions,
	value,
	onChange,
	multiline,
	hideLabel,
	...props
} ) {
	const Tag = multiline ? 'textarea' : 'input';
	const input = (
		<Tag
			className="barrio-input"
			value={ value ?? '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			aria-label={ hideLabel ? label : undefined }
			{ ...props }
		/>
	);
	return hideLabel ? (
		input
	) : (
		<Field
			label={ label }
			help={ help }
			addon={ addon }
			actions={ actions }
		>
			{ input }
		</Field>
	);
}

export function Select( {
	label,
	help,
	actions,
	value,
	options,
	onChange,
	hideLabel,
} ) {
	const select = (
		<select
			className="barrio-input"
			value={ value ?? '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			aria-label={ hideLabel ? label : undefined }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	);
	return hideLabel ? (
		select
	) : (
		<Field label={ label } help={ help } actions={ actions }>
			{ select }
		</Field>
	);
}

// Un interruttore, con il testo a sinistra: preferito alle caselle, che restano per scegliere da un
// elenco (righe, colonne, opzioni).
export function Toggle( { label, help, checked, onChange, disabled } ) {
	const id = useId();
	return (
		<div className="barrio-toggle">
			<div>
				<label htmlFor={ id }>{ label }</label>
				{ help && (
					<p className="barrio-field__help" id={ `${ id }-help` }>
						{ help }
					</p>
				) }
			</div>
			<input
				type="checkbox"
				role="switch"
				className="barrio-switch"
				id={ id }
				aria-describedby={ help ? `${ id }-help` : undefined }
				disabled={ disabled }
				checked={ !! checked }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
		</div>
	);
}

/**
 * Una card con intestazione (icona, titolo, azioni) e corpo. collapsible: il titolo apre e chiude il
 * corpo, per gli elenchi di card lunghe (notifiche, integrazioni).
 *
 * @param {Object}  props
 * @param {string}  props.icon
 * @param {string}  props.title
 * @param {string}  props.description
 * @param {Object}  props.actions
 * @param {Object}  props.children
 * @param {string}  props.className
 * @param {boolean} props.collapsible
 * @param {boolean} props.defaultOpen Se collapsible, aperta all'inizio.
 */
export function Card( {
	icon,
	title,
	description,
	actions,
	children,
	className = '',
	collapsible,
	defaultOpen = true,
} ) {
	const [ open, setOpen ] = useState( ! collapsible || defaultOpen );
	const id = useId();
	const heading = (
		<>
			{ icon && (
				<span className="barrio-card__icon">
					<Icon name={ icon } />
				</span>
			) }
			{ title }
		</>
	);
	return (
		<section
			className={ `barrio-card ${ className }${ open ? '' : ' is-closed' }` }
		>
			{ title && (
				<header className="barrio-card__header">
					<h2 className="barrio-card__title">
						{ collapsible ? (
							<button
								type="button"
								className="barrio-card__toggle"
								aria-expanded={ open }
								aria-controls={ id }
								onClick={ () => setOpen( ! open ) }
							>
								{ heading }
								<Icon name="arrow-down-alt2" />
							</button>
						) : (
							heading
						) }
					</h2>
					{ actions && (
						<div className="barrio-card__actions">{ actions }</div>
					) }
				</header>
			) }
			<div className="barrio-card__body" id={ id } hidden={ ! open }>
				{ description && (
					<p className="barrio-card__description">{ description }</p>
				) }
				{ children }
			</div>
		</section>
	);
}

/**
 * Nelle Impostazioni di un modulo, come il suo contenuto arriva nel sito. Shortcode e link sono etichette che si
 * selezionano e si copiano, come i merge tag delle email.
 *
 * @param {Object}   props
 * @param {string}   props.description
 * @param {string[]} [props.codes]     Gli esempi da copiare.
 * @param {Object}   [props.children]  Altro sotto gli esempi (i nomi dei dati aziendali).
 */
export const HowTo = ( { description, codes = [], children } ) => (
	<Card
		className="barrio-wide"
		icon="editor-code"
		title={ __( 'How to show them', 'barrio-suite' ) }
		description={ description }
	>
		{ codes.length > 0 && (
			<div className="barrio-merge-tags">
				{ codes.map( ( code ) => (
					<code key={ code }>{ code }</code>
				) ) }
			</div>
		) }
		{ children }
	</Card>
);

export const AddButton = ( { text, onClick } ) => (
	<button type="button" className="barrio-button" onClick={ onClick }>
		<Icon name="plus-alt2" />
		<span className="barrio-button__text">{ text }</span>
	</button>
);

export const RemoveButton = ( { onClick } ) => (
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
export function TextList( {
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

/**
 * Le card dei tipi di contenuto e delle tassonomie degli strumenti di Contenuti (Admin::content_types()), con un
 * interruttore per tipo. Sotto ogni tassonomia i tipi che la usano: due possono chiamarsi entrambe "Categorie".
 *
 * @param {Object}                                             props
 * @param {Object}                                             props.cards    { post_types, taxonomies }: icon, title e description di ogni card.
 * @param {(group: string, name: string) => boolean}           props.checked
 * @param {(group: string, name: string, on: boolean) => void} props.onChange
 */
export const TypeCards = ( { cards, checked, onChange } ) =>
	Object.entries( cards ).map( ( [ group, card ] ) => (
		<Card key={ group } className="barrio-wide" { ...card }>
			{ window.barrioAdmin.contentTypes[ group ].map( ( type ) => (
				<Toggle
					key={ type.name }
					label={ type.label }
					help={
						type.types &&
						sprintf(
							/* translators: %s: content types, separated by commas. */
							__( 'Used by %s', 'barrio-suite' ),
							type.types.join( ', ' )
						)
					}
					checked={ checked( group, type.name ) }
					onChange={ ( on ) => onChange( group, type.name, on ) }
				/>
			) ) }
		</Card>
	) );

/**
 * La card Permessi di un modulo (src/Roles.php): per ogni ruolo un interruttore per capability. Con una capability
 * sola ogni riga è il ruolo e il suo interruttore: cosa permette lo dice la descrizione. Gli amministratori non sono
 * in roles: tutto acceso e fisso.
 *
 * @param {Object}                  props
 * @param {string}                  props.description
 * @param {Object}                  props.caps        { capability: etichetta }, nell'ordine in cui si salvano.
 * @param {Object}                  props.names       I nomi tradotti di tutti i ruoli (role_names).
 * @param {Object}                  props.roles       Per ruolo le capability che ha.
 * @param {(roles: Object) => void} props.onChange
 */
export function Permissions( { description, caps, names, roles, onChange } ) {
	const keys = Object.keys( caps );
	const toggle = ( role, cap, label ) => (
		<Toggle
			key={ `${ role }-${ cap }` }
			label={ label }
			disabled={ ! roles[ role ] }
			checked={ ! roles[ role ] || roles[ role ].includes( cap ) }
			// Nell'ordine di caps, così il confronto con quelle salvate non dipende dall'ordine dei clic.
			onChange={ ( on ) =>
				onChange( {
					...roles,
					[ role ]: keys.filter( ( key ) =>
						key === cap ? on : roles[ role ].includes( key )
					),
				} )
			}
		/>
	);
	return (
		<Card
			className="barrio-wide"
			icon="groups"
			title={ __( 'Permissions', 'barrio-suite' ) }
			description={ description }
		>
			{ Object.entries( names ).map( ( [ role, name ] ) =>
				1 === keys.length ? (
					toggle( role, keys[ 0 ], name )
				) : (
					<Group key={ role } label={ name }>
						<div className="barrio-checks">
							{ keys.map( ( cap ) =>
								toggle( role, cap, caps[ cap ] )
							) }
						</div>
					</Group>
				)
			) }
		</Card>
	);
}

/**
 * Ogni modale del plugin: l'intestazione resta in alto, sempre separata dal contenuto da una linea, e i bottoni
 * (footer) restano in fondo. Scorre solo il corpo, così la scrollbar non passa sui bottoni. Il corpo è una pila
 * (admin.css): i campi hanno già lo spazio tra loro. WordPress la mette in fondo alla pagina, fuori da
 * .barrio-admin: la classe la porta anche qui, con gli stili dei campi. Le altre props sono quelle di Modal.
 *
 * @param {Object} props
 * @param {string} props.className
 * @param {Object} props.footer    I bottoni, la principale per ultima.
 * @param {Object} props.children
 */
export const Dialog = ( { className = '', footer, children, ...props } ) => (
	<Modal
		className={ `barrio-admin barrio-modal ${ className }` }
		{ ...props }
	>
		<div className="barrio-modal__body">{ children }</div>
		{ footer && <div className="barrio-modal__footer">{ footer }</div> }
	</Modal>
);

// Una sezione di una card chiusa all'inizio, per ciò che serve di rado (le impostazioni avanzate).
export const Details = ( { title, children } ) => (
	<details className="barrio-details">
		<summary>
			<h3>{ title }</h3>
			<Icon name="arrow-down-alt2" />
		</summary>
		<div className="barrio-details__body">{ children }</div>
	</details>
);

/**
 * Nella Bacheca, la card di un modulo acceso, con al massimo tre numeri: le card restano alte uguali. La descrizione
 * e ogni numero stanno in una riga; il testo che non ci sta finisce con … e si legge intero al passaggio.
 *
 * @param {Object}                       props
 * @param {Object}                       props.module   icon, title, description e color del modulo.
 * @param {Array<[string, number]>|null} props.stats    [ etichetta, numero ]; null mentre si caricano. Oltre il terzo non si mostrano.
 * @param {Array<[string, string]>}      props.actions  [ testo, link ], la prima è la principale.
 * @param {Object}                       [props.notice] Un errore nel caricare i numeri.
 */
export const ModuleCard = ( { module, stats, actions, notice } ) => (
	<section className="barrio-card barrio-module">
		<header
			className="barrio-module__head"
			style={ { background: module.color } }
		>
			<span className="barrio-module__icon">
				<Icon name={ module.icon } />
			</span>
			<div>
				<h2 className="barrio-module__title">{ module.title }</h2>
				<p title={ module.description }>{ module.description }</p>
			</div>
		</header>
		<div className="barrio-module__body">
			<Notice notice={ notice } />
			{ stats ? (
				<dl className="barrio-module__stats">
					{ stats.slice( 0, 3 ).map( ( [ label, value ] ) => (
						<div key={ label }>
							<dt>{ label }</dt>
							<dd title={ value.toLocaleString() }>
								{ value.toLocaleString() }
							</dd>
						</div>
					) ) }
				</dl>
			) : (
				! notice && <Loading />
			) }
			<div className="barrio-row is-fill">
				{ actions.map( ( [ text, href ], index ) => (
					<a
						key={ href }
						className={ `barrio-button${ index ? '' : ' is-primary' }` }
						href={ href }
					>
						{ text }
					</a>
				) ) }
			</div>
		</div>
	</section>
);

// Titolo di una sezione con le sue azioni a destra: ogni elenco ha "Aggiungi" qui, in alto.
export function SectionHead( { title, level = 2, children } ) {
	const Heading = `h${ level }`;
	return (
		<div className="barrio-section-head">
			<Heading>{ title }</Heading>
			{ children && (
				<div className="barrio-row is-nowrap">{ children }</div>
			) }
		</div>
	);
}

/**
 * Le schede di una pagina e il contenuto di quella aperta. Ogni scheda è un URL (&tab=): link normali, niente
 * router. Senza tab, o con una che chi guarda non ha, si apre la prima.
 *
 * @param {Object} props
 * @param {string} props.page Il ?page= della pagina.
 * @param {Array}  props.tabs [ tab, nome, componente ]; '' per un URL senza tab.
 */
export function Tabs( { page, tabs } ) {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );
	const [ current, , Content ] =
		tabs.find( ( [ value ] ) => value === tab ) ?? tabs[ 0 ];
	return (
		<>
			<nav
				className="barrio-nav"
				aria-label={ __( 'Sections', 'barrio-suite' ) }
			>
				{ tabs.map( ( [ value, label ] ) => (
					<a
						key={ value }
						href={
							'?' +
							new URLSearchParams(
								value ? { page, tab: value } : { page }
							)
						}
						aria-current={ value === current ? 'page' : undefined }
						// Sugli schermi stretti le schede scorrono: quella aperta resta in vista.
						ref={
							value === current
								? ( link ) =>
										link?.scrollIntoView( {
											block: 'nearest',
											inline: 'nearest',
										} )
								: undefined
						}
					>
						{ label }
					</a>
				) ) }
			</nav>
			<Content />
		</>
	);
}

// Oltre quante voci compare la ricerca, nelle scelte e nei menu.
const SEARCH_ABOVE = 5;

// La ricerca sopra le liste lunghe: chi la usa sta in un componente a parte, che si monta a ogni
// apertura, così riparte vuota.
function useSearch( items ) {
	const [ search, setSearch ] = useState( '' );
	const term = search.trim().toLowerCase();
	return [
		items.filter( ( item ) => item.label.toLowerCase().includes( term ) ),
		items.length > SEARCH_ABOVE && (
			<input
				type="search"
				className="barrio-picker__search"
				placeholder={ __( 'Search', 'barrio-suite' ) }
				aria-label={ __( 'Search', 'barrio-suite' ) }
				value={ search }
				onChange={ ( event ) => setSearch( event.target.value ) }
			/>
		),
	];
}

/**
 * Menu a tendina: le azioni di una riga (senza text, il bottone "⋯") o una scelta (con text).
 * Con tante voci ha la ricerca, come Picker.
 *
 * @param {Object}  props
 * @param {string}  props.label     Il nome del menu, per chi usa uno screen reader.
 * @param {string}  props.text      Il testo del bottone; senza, solo l'icona.
 * @param {string}  props.icon
 * @param {string}  props.className Del bottone con il testo.
 * @param {boolean} props.inBar     Aperto dalla barra sticky. Gli altri, scorrendo, passano sotto
 *                                  la barra e sotto quella di WordPress (i popover sono nel body).
 * @param {Array}   props.items     { label, icon?, onClick?, href?, danger?, disabled? }; i falsy si saltano. Niente
 *                                  descrizioni: il motivo di una voce spenta lo dice la pagina.
 */
export function Menu( {
	label,
	text,
	icon = 'ellipsis',
	className = '',
	inBar,
	items,
} ) {
	return (
		<Dropdown
			popoverProps={ {
				placement: 'bottom-end',
				className: inBar ? undefined : 'barrio-popover-below',
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={
						text
							? `barrio-button ${ className }`
							: 'barrio-icon-button'
					}
					aria-label={ text ? undefined : label }
					aria-haspopup="true"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					<Icon name={ icon } />
					{ text && (
						<span className="barrio-button__text">{ text }</span>
					) }
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<MenuItems
					label={ label }
					items={ items.filter( Boolean ) }
					onClose={ onClose }
				/>
			) }
		/>
	);
}

function MenuItems( { label, items, onClose } ) {
	const [ found, search ] = useSearch( items );
	return (
		<div className="barrio-menu">
			{ search }
			<NavigableMenu role="menu" aria-label={ label }>
				{ found.map( ( item ) => (
					<MenuItem
						key={ item.label }
						icon={ item.icon }
						iconPosition="left"
						href={ item.href }
						isDestructive={ item.danger }
						disabled={ item.disabled }
						onClick={ () => {
							onClose();
							item.onClick?.();
						} }
					>
						{ item.label }
					</MenuItem>
				) ) }
			</NavigableMenu>
			{ ! found.length && (
				<p className="barrio-picker__empty">
					{ __( 'No results.', 'barrio-suite' ) }
				</p>
			) }
		</div>
	);
}

// notice: { status: success|error|info, message, action? }. Lo annuncia speak(): un role="status"
// che compare, invece di cambiare, non viene letto in modo affidabile.
export function Notice( { notice, onClose } ) {
	const message = notice?.message;
	const assertive = 'error' === notice?.status;
	useEffect( () => {
		if ( 'string' === typeof message ) {
			speak( message, assertive ? 'assertive' : 'polite' );
		}
	}, [ message, assertive ] );
	if ( ! notice ) {
		return null;
	}
	return (
		<div
			className={ `barrio-notice is-${ notice.status }${
				onClose ? ' is-sticky' : ''
			}` }
		>
			<p>{ notice.message }</p>
			{ notice.action }
			{ onClose && (
				<button
					type="button"
					className="barrio-icon-button"
					aria-label={ __( 'Dismiss', 'barrio-suite' ) }
					onClick={ onClose }
				>
					<Icon name="no-alt" />
				</button>
			) }
		</div>
	);
}

const SORT_ICON = { asc: 'arrow-up', desc: 'arrow-down' };

// Il primo assetto in cui tutto sta su una riga: con i testi; con i bottoni senza testo (il nome
// del form può accorciarsi); anche con la ricerca ridotta a un bottone. Solo se non basta, il
// titolo va sopra e il resto sotto.
const LAYOUTS = [
	[],
	[ 'is-compact' ],
	[ 'is-compact', 'is-searchless' ],
	[ 'is-compact', 'is-stacked' ],
];

function fit( bar ) {
	for ( const classes of LAYOUTS ) {
		bar.classList.remove( 'is-compact', 'is-searchless', 'is-stacked' );
		bar.classList.add( ...classes );
		// Su una riga niente va a capo (admin.css): ci sta se niente esce dalla barra.
		if ( bar.scrollWidth <= bar.clientWidth ) {
			return;
		}
	}
}

// Prima di lasciare ciò che si è scritto (Esc, un clic fuori dalla modale, un altro passaggio): true se si può.
export const discard = ( dirty ) =>
	! dirty ||
	// eslint-disable-next-line no-alert -- le modifiche non salvate non si perdono senza conferma.
	window.confirm( __( 'Discard the changes?', 'barrio-suite' ) );

// Il Salva di barre e modali, uguale ovunque: fermo mentre salva, e chi lo usa lo spegne senza modifiche.
export const SaveButton = ( { busy, disabled, ...props } ) => (
	<button
		type="button"
		className="barrio-button is-primary"
		disabled={ !! busy || disabled }
		{ ...props }
	>
		<Lucide name="save" />
		<span className="barrio-button__text">
			{ busy
				? __( 'Saving…', 'barrio-suite' )
				: __( 'Save', 'barrio-suite' ) }
		</span>
	</button>
);

/**
 * La barra sticky in alto di ogni pagina, sempre uguale: titolo a sinistra (con il
 * ritorno, se c'è), azioni a destra con la principale per ultima. Non va mai a capo a caso:
 * vedi fit().
 *
 * @param {Object} props
 * @param {Object} props.title    Testo, o un nodo (il nome modificabile del form).
 * @param {Object} props.children Le azioni.
 */
export function Bar( { title, children } ) {
	const ref = useRef();
	// A ogni render: compare "N selezionate", cambia il testo di un filtro...
	useLayoutEffect( () => fit( ref.current ) );
	// ...e quando cambia la larghezza. Al frame dopo: cambiare l'altezza dentro il
	// callback farebbe ripartire l'observer.
	useEffect( () => {
		const bar = ref.current;
		let width;
		let frame;
		const observer = new window.ResizeObserver( ( [ entry ] ) => {
			if ( entry.contentRect.width !== width ) {
				width = entry.contentRect.width;
				window.cancelAnimationFrame( frame );
				frame = window.requestAnimationFrame( () => fit( bar ) );
			}
		} );
		observer.observe( bar );
		return () => {
			observer.disconnect();
			window.cancelAnimationFrame( frame );
		};
	}, [] );
	return (
		<div className="barrio-card barrio-bar" ref={ ref }>
			{ 'string' === typeof title ? (
				<h2 className="barrio-bar__title">{ title }</h2>
			) : (
				title
			) }
			<div className="barrio-bar__end">{ children }</div>
		</div>
	);
}

/**
 * La ricerca nella barra, dentro .barrio-bar__tools, o in un SectionHead. Quando la barra è stretta
 * diventa un bottone che la apre in un menu: vedi fit().
 *
 * @param {Object}                  props
 * @param {string}                  props.label    Anche il segnaposto.
 * @param {string}                  props.value
 * @param {(value: string) => void} props.onChange
 */
export function Search( { label, value, onChange } ) {
	const input = ( className ) => (
		<input
			type="search"
			className={ className }
			placeholder={ label }
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	);
	return (
		<>
			<div className="barrio-search">
				<Icon name="search" />
				{ input( 'barrio-input' ) }
			</div>
			<Dropdown
				className="barrio-search-toggle"
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<button
						type="button"
						className={ `barrio-button${ value ? ' is-active' : '' }` }
						aria-label={ label }
						aria-expanded={ isOpen }
						onClick={ onToggle }
					>
						<Icon name="search" />
					</button>
				) }
				renderContent={ () => (
					<div className="barrio-picker">
						{ input( 'barrio-picker__search' ) }
					</div>
				) }
			/>
		</>
	);
}

/**
 * Un pulsante della barra con una scelta e la ricerca quando le opzioni sono tante: il form delle
 * registrazioni, la lingua del builder. La scelta si applica subito e l'elenco resta aperto, così
 * con le frecce si scorrono le opzioni; si chiude con Esc o fuori.
 *
 * @param {Object}                  props
 * @param {string}                  props.label     Il nome dell'elenco.
 * @param {string}                  props.text      Sul pulsante (di default label): l'opzione scelta.
 * @param {string}                  props.icon      Prima del testo: nella barra stretta resta solo lei (il ruolo di Blocchi).
 * @param {string}                  props.image     Prima del testo: la bandiera della lingua scelta.
 * @param {boolean}                 props.active    Diverso dal default.
 * @param {string}                  props.className Del pulsante, oltre a barrio-button.
 * @param {string}                  props.placement Del menu.
 * @param {Array}                   props.options   { value, label, count?, image? }.
 * @param {string}                  props.value
 * @param {(value: string) => void} props.onChange
 */
export function Picker( {
	label,
	text = label,
	icon,
	image,
	active,
	className = '',
	placement = 'bottom-end',
	options,
	value,
	onChange,
} ) {
	return (
		<Dropdown
			popoverProps={ { placement } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={ `barrio-button ${ className }${ active ? ' is-active' : '' }` }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					{ icon && <Icon name={ icon } /> }
					{ image && (
						<img className="barrio-flag" src={ image } alt="" />
					) }
					<span className="barrio-button__text">
						{ text !== label && (
							<span className="screen-reader-text">
								{ label }:{ ' ' }
							</span>
						) }
						{ text }
					</span>
					<Icon name="arrow-down-alt2" />
				</button>
			) }
			renderContent={ () => (
				<Choices
					label={ label }
					options={ options }
					value={ value }
					onChange={ onChange }
				/>
			) }
		/>
	);
}

// heading: il nome si vede (le sezioni del pannello dei filtri); da solo lo dice già il pulsante.
function Choices( { label, heading, options, value, multiple, onChange } ) {
	const name = useId();
	const [ found, search ] = useSearch( options );
	return (
		<div className="barrio-picker">
			{ heading && (
				<div className="barrio-picker__heading" aria-hidden="true">
					{ label }
				</div>
			) }
			{ search }
			<fieldset className="barrio-picker__list">
				<legend className="screen-reader-text">{ label }</legend>
				{ found.map( ( option ) => (
					<label
						key={ option.value }
						className="barrio-picker__option"
						htmlFor={ `${ name }-${ option.value }` }
					>
						<input
							type={ multiple ? 'checkbox' : 'radio' }
							id={ `${ name }-${ option.value }` }
							name={ name }
							checked={
								multiple
									? value.includes( option.value )
									: value === option.value
							}
							onChange={ ( event ) =>
								onChange( option.value, event.target.checked )
							}
						/>
						{ option.image && (
							<img
								className="barrio-flag"
								src={ option.image }
								alt=""
							/>
						) }
						{ option.label }
						{ undefined !== option.count && (
							<span className="barrio-picker__count">
								{ option.count }
							</span>
						) }
					</label>
				) ) }
				{ ! found.length && (
					<p className="barrio-picker__empty">
						{ __( 'No results.', 'barrio-suite' ) }
					</p>
				) }
			</fieldset>
		</div>
	);
}

// I filtri di una tabella e le colonne da mostrare in un solo pannello. Sul pulsante il numero
// dei filtri diversi da quelli iniziali; onClear riporta tutta la vista all'inizio (changed: se
// qualcosa è cambiato, anche solo colonne, ordinamento o ricerca).
function Filters( {
	filters,
	query,
	initial,
	onQuery,
	columns,
	shown,
	onColumn,
	changed,
	onClear,
} ) {
	const active = filters.filter(
		( filter ) => query[ filter.key ] !== initial[ filter.key ]
	);
	const label = __( 'Filters', 'barrio-suite' );
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-end' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={ `barrio-button${ active.length ? ' is-active' : '' }` }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					<Icon name="filter" />
					<span className="barrio-button__text">{ label }</span>
					{ active.length > 0 && (
						<span className="barrio-button__count">
							<span className="screen-reader-text">
								{ __(
									'Active filters:',
									'barrio-suite'
								) }{ ' ' }
							</span>
							{ active.length }
						</span>
					) }
					<Icon name="arrow-down-alt2" />
				</button>
			) }
			renderContent={ () => (
				<div className="barrio-filters">
					{ filters.map( ( filter ) => (
						<Choices
							key={ filter.key }
							heading
							label={ filter.label }
							options={ filter.options }
							value={ query[ filter.key ] }
							onChange={ ( value ) =>
								onQuery( {
									...query,
									[ filter.key ]: value,
									page: 1,
								} )
							}
						/>
					) ) }
					{ columns.length > 0 && (
						<Choices
							heading
							multiple
							label={ __( 'Columns', 'barrio-suite' ) }
							options={ columns }
							value={ shown }
							onChange={ onColumn }
						/>
					) }
					{ /* Sempre presente: se sparisse al clic, il focus uscirebbe dal pannello e
					non si potrebbe più chiuderlo con Esc o cliccando fuori. */ }
					<button
						type="button"
						className="barrio-button barrio-filters__clear"
						aria-disabled={ ! changed }
						onClick={ () => changed && onClear() }
					>
						{ __( 'Clear filters', 'barrio-suite' ) }
					</button>
				</div>
			) }
		/>
	);
}

/**
 * La tabella di tutte le pagine del plugin, uguale ovunque. Nella barra: ricerca, un pannello
 * Filtri (i filtri, per esempio gli stati se le righe ne hanno, e le colonne da mostrare) e le
 * azioni sulle righe selezionate. Pagine e
 * ordinamento a tre stati, con una colonna sempre ordinata. È controllata: rows è già la pagina
 * da mostrare (localQuery() o il server).
 *
 * @param {Object}                   props
 * @param {Array}                    props.columns { key, label, sort?: campo su cui ordinare, render?( row ), className?, hideable?: nelle colonne da scegliere, hidden?: nascosta all'inizio }.
 * @param {Array}                    props.rows
 * @param {number}                   props.total   Righe trovate, su tutte le pagine.
 * @param {Object}                   props.query   { search, sort, order, page } e i filtri.
 * @param {Object}                   props.initial La query iniziale, con la colonna di default.
 * @param {(query: Object) => void}  props.onQuery
 * @param {string}                   props.title   Il titolo della barra.
 * @param {string}                   props.search  Il segnaposto della ricerca.
 * @param {Array}                    props.filters { key: chiave della query, label, options: [ { value, label, count?, image? } ] }.
 * @param {(ids: number[]) => Array} props.bulk    Le voci del menu per le righe selezionate.
 * @param {string}                   props.empty   Il testo senza righe.
 * @param {Object}                   props.toolbar In fondo alla barra.
 * @param {Object}                   props.shown   Facoltativo: { chiave: mostrata }, le colonne scelte a mano.
 * @param {(shown: Object) => void}  props.onShown
 */
export function Table( {
	columns,
	rows,
	total,
	query,
	initial,
	onQuery,
	title,
	search,
	filters: allFilters = [],
	bulk,
	empty,
	toolbar,
	shown: controlled,
	onShown,
} ) {
	// La selezione vale nella query in cui è nata e solo per le righe ancora mostrate: cambiando
	// pagina, filtro o ordine si azzera, e le righe eliminate ne escono da sole.
	const [ selection, setSelection ] = useState( { query, ids: [] } );
	// Le colonne mostrate o nascoste a mano; le altre seguono hidden.
	const [ own, setOwn ] = useState( {} );
	const shown = controlled ?? own;
	const setShown = onShown ?? setOwn;

	const last = pages( total );
	const page = Math.min( query.page, last );
	const first = total ? ( page - 1 ) * PER_PAGE + 1 : 0;
	const go = ( to ) => onQuery( { ...query, page: to } );
	const count = sprintf(
		/* translators: 1: first row shown; 2: last row shown; 3: number of rows found. */
		__( '%1$d–%2$d of %3$d', 'barrio-suite' ),
		first,
		first + rows.length - ( total ? 1 : 0 ),
		total
	);
	// Mentre si scrive nella ricerca il conteggio cambia a ogni tasto: si annuncia a scrittura ferma.
	const spoken = useRef( count );
	useEffect( () => {
		const timer = setTimeout( () => {
			if ( spoken.current !== count ) {
				spoken.current = count;
				speak( count );
			}
		}, 500 );
		return () => clearTimeout( timer );
	}, [ count ] );

	const ids = rows.map( ( row ) => row.id );
	const selected =
		selection.query === query
			? selection.ids.filter( ( id ) => ids.includes( id ) )
			: [];
	const select = ( list ) => setSelection( { query, ids: list } );
	const all = ids.length > 0 && selected.length === ids.length;
	const filters = allFilters.filter( Boolean );
	const hideable = columns.filter( ( column ) => column?.hideable );
	const isShown = ( column ) => shown[ column.key ] ?? ! column.hidden;
	// La vista è diversa da quella iniziale (la pagina non conta).
	const changed =
		Object.keys( initial ).some(
			( key ) => 'page' !== key && query[ key ] !== initial[ key ]
		) ||
		hideable.some( ( column ) => isShown( column ) === !! column.hidden );
	const toggle = ( column, on ) => {
		setShown( { ...shown, [ column.key ]: on } );
		// Una colonna nascosta non può restare quella ordinata.
		if ( ! on && column.sort === query.sort ) {
			onQuery( {
				...query,
				sort: initial.sort,
				order: initial.order,
				page: 1,
			} );
		}
	};

	const visible = [
		bulk && {
			key: 'select',
			className: 'is-narrow',
			label: (
				<input
					type="checkbox"
					aria-label={ __(
						'Select all on this page',
						'barrio-suite'
					) }
					checked={ all }
					ref={ ( input ) => {
						if ( input ) {
							input.indeterminate = selected.length > 0 && ! all;
						}
					} }
					onChange={ () => select( all ? [] : ids ) }
				/>
			),
			render: ( row ) => (
				<input
					type="checkbox"
					aria-label={ sprintf(
						/* translators: %d: row number (the # column). */
						__( 'Select #%d', 'barrio-suite' ),
						row.id
					) }
					checked={ selected.includes( row.id ) }
					onChange={ () =>
						select(
							selected.includes( row.id )
								? selected.filter( ( id ) => id !== row.id )
								: [ ...selected, row.id ]
						)
					}
				/>
			),
		},
		...columns.filter( ( column ) => column && isShown( column ) ),
	].filter( Boolean );

	return (
		<div className="barrio-stack">
			<Bar title={ title }>
				<div className="barrio-bar__tools">
					<Search
						label={ search }
						value={ query.search }
						onChange={ ( value ) =>
							onQuery( { ...query, search: value, page: 1 } )
						}
					/>
					{ ( filters.length > 0 || hideable.length > 0 ) && (
						<Filters
							filters={ filters }
							query={ query }
							initial={ initial }
							onQuery={ onQuery }
							columns={ hideable.map( ( column ) => ( {
								value: column.key,
								label: column.label,
							} ) ) }
							shown={ hideable
								.filter( isShown )
								.map( ( column ) => column.key ) }
							changed={ changed }
							onClear={ () => {
								setShown( {} );
								onQuery( initial );
							} }
							onColumn={ ( key, on ) =>
								toggle(
									hideable.find(
										( column ) => column.key === key
									),
									on
								)
							}
						/>
					) }
					{ selected.length > 0 && (
						<Menu
							inBar
							text={ sprintf(
								/* translators: %d: number of selected rows. */
								__( '%d selected', 'barrio-suite' ),
								selected.length
							) }
							icon="yes-alt"
							label={ __(
								'Actions for the selected rows',
								'barrio-suite'
							) }
							items={ bulk( selected ) }
						/>
					) }
				</div>
				{ toolbar }
			</Bar>
			<div className="barrio-card">
				{ /* Scorre solo la tabella: la paginazione resta larga quanto la card. */ }
				<div className="barrio-table__scroll">
					<table className="barrio-table">
						<thead>
							<tr>
								{ visible.map( ( column ) => {
									const active = query.sort === column.sort;
									let ariaSort;
									if ( active ) {
										ariaSort =
											'asc' === query.order
												? 'ascending'
												: 'descending';
									}
									return (
										<th
											key={ column.key }
											scope="col"
											className={ column.className }
											aria-sort={ ariaSort }
										>
											{ column.sort ? (
												<button
													type="button"
													onClick={ () =>
														onQuery(
															nextSort(
																query,
																column.sort,
																initial
															)
														)
													}
												>
													{ column.label }
													<Lucide
														size={ 14 }
														name={
															active
																? SORT_ICON[
																		query
																			.order
																	]
																: 'arrow-up-down'
														}
													/>
												</button>
											) : (
												column.label
											) }
										</th>
									);
								} ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									{ visible.map( ( column ) => (
										<td
											key={ column.key }
											className={ column.className }
										>
											{ column.render
												? column.render( row )
												: row[ column.key ] }
										</td>
									) ) }
								</tr>
							) ) }
							{ ! rows.length && (
								<tr>
									<td
										colSpan={ visible.length }
										className="barrio-table__empty"
									>
										{ empty }
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</div>
				<div className="barrio-table__footer">
					<span>{ count }</span>
					<button
						type="button"
						className="barrio-icon-button"
						aria-label={ __( 'Previous page', 'barrio-suite' ) }
						disabled={ page <= 1 }
						onClick={ () => go( page - 1 ) }
					>
						<Icon name="arrow-left-alt2" />
					</button>
					<span>
						{ sprintf(
							/* translators: 1: current page; 2: number of pages. */
							__( 'Page %1$d of %2$d', 'barrio-suite' ),
							page,
							last
						) }
					</span>
					<button
						type="button"
						className="barrio-icon-button"
						aria-label={ __( 'Next page', 'barrio-suite' ) }
						disabled={ page >= last }
						onClick={ () => go( page + 1 ) }
					>
						<Icon name="arrow-right-alt2" />
					</button>
				</div>
			</div>
		</div>
	);
}
