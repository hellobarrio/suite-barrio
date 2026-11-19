// La scheda Testi alternativi di Barrio → Contenuti (modules/alt/src/Module.php): le immagini della libreria dei
// media, con ricerca, filtro e pagine sul server (GET /alt/images), all'inizio quelle senza testo alternativo. Genera
// e Modifica nel menu ⋯ di ogni riga aprono la modale, che salva con la REST del core. La massa e "Genera tutti"
// lavorano nel browser, un'immagine alla volta: il server scrive solo dove manca, quindi si riprende da dove si era.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

import { initialQuery } from '../../../../assets/src/admin/query.js';
import {
	Dialog,
	Icon,
	Loading,
	Lucide,
	Menu,
	Notice,
	Table,
	Text,
	Toggle,
	errorMessage,
} from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const ALT = {
	title: _x( 'Alt text', 'module name', 'barrio-suite' ),
	description: __(
		'The alternative text of the images, written by Google AI looking at each image: from the image details, in bulk or after the upload.',
		'barrio-suite'
	),
};

// Module::admin_data(), a chi carica i media. Con il modulo spento manca.
export const altData = window.barrioAdmin.alt;

// Per la card Contenuti della Bacheca.
export const altStats = () => [
	[ __( 'Images without alt text', 'barrio-suite' ), altData.missing ],
];

const AI_SETUP = __(
	'To use it, set up Google AI in Barrio → Settings.',
	'barrio-suite'
);

// Le più recenti per prime, tra quelle senza testo alternativo.
const INITIAL = { ...initialQuery( 'date', 'desc' ), alt: 'missing' };

// Senza i filtri vuoti, che il server rifiuterebbe.
const params = ( query ) =>
	new URLSearchParams(
		Object.entries( query ).filter( ( [ , value ] ) => '' !== value )
	);

const generate = ( id, save = false ) =>
	apiFetch( {
		path: `/barrio/v1/alt/${ id }`,
		method: 'POST',
		data: { save },
	} );

// Modifica di alt e titolo, con "Genera con AI" che riempie i campi da vedere prima di salvare. Da Genera nel menu
// ⋯ si apre generando.
function AltModal( { row, start, onSaved, onClose } ) {
	const [ alt, setAlt ] = useState( row.alt );
	const [ title, setTitle ] = useState( row.title );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const dirty = alt !== row.alt || title !== row.title;
	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	const fill = () => {
		setBusy( true );
		setNotice( null );
		generate( row.id )
			.then( ( text ) => {
				setAlt( text.alt );
				if ( text.title ) {
					setTitle( text.title );
				}
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};
	useEffect( () => {
		if ( start ) {
			fill();
		}
		// Solo all'apertura.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const save = () => {
		setBusy( true );
		setNotice( null );
		apiFetch( {
			path: `/wp/v2/media/${ row.id }`,
			method: 'POST',
			data: { alt_text: alt.trim(), title: title.trim() },
		} )
			.then( () => {
				onSaved();
				onClose();
			} )
			.catch( ( error ) => {
				fail( error );
				setBusy( false );
			} );
	};
	const close = () => {
		if (
			! dirty ||
			// eslint-disable-next-line no-alert -- le modifiche non salvate non si perdono con Esc o un clic fuori.
			window.confirm( __( 'Discard the changes?', 'barrio-suite' ) )
		) {
			onClose();
		}
	};

	return (
		<Dialog
			footer={
				<>
					<button
						type="button"
						className="barrio-button"
						onClick={ close }
					>
						{ __( 'Cancel', 'barrio-suite' ) }
					</button>
					<button
						type="button"
						className="barrio-button is-primary"
						disabled={ busy || ! dirty }
						onClick={ save }
					>
						<Lucide name="save" />
						<span className="barrio-button__text">
							{ __( 'Save', 'barrio-suite' ) }
						</span>
					</button>
				</>
			}
			size="medium"
			title={ sprintf(
				/* translators: %s: file name of an image. */
				__( 'Alt text of “%s”', 'barrio-suite' ),
				row.file
			) }
			onRequestClose={ close }
		>
			<div className="barrio-stack">
				<Notice notice={ notice } />
				<img className="barrio-alt-preview" src={ row.thumb } alt="" />
				<Text
					multiline
					rows={ 3 }
					label={ __( 'Alt text', 'barrio-suite' ) }
					help={
						altData.ai
							? __(
									'What the image shows, for people who cannot see it. Leave it empty for a decorative image.',
									'barrio-suite'
								)
							: AI_SETUP
					}
					value={ alt }
					onChange={ setAlt }
					actions={
						<button
							type="button"
							className="barrio-button"
							disabled={ busy || ! altData.ai || ! row.supported }
							onClick={ fill }
						>
							<Icon name="lightbulb" />
							<span className="barrio-button__text">
								{ busy
									? __( 'Generating…', 'barrio-suite' )
									: __( 'Generate with AI', 'barrio-suite' ) }
							</span>
						</button>
					}
				/>
				<Text
					label={ __( 'Title', 'barrio-suite' ) }
					value={ title }
					onChange={ setTitle }
				/>
			</div>
		</Dialog>
	);
}

// Le impostazioni, agli amministratori: dalla barra, perché la scheda è una tabella.
function SettingsModal( { onClose } ) {
	const [ draft, setDraft ] = useState( altData.settings );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const dirty =
		JSON.stringify( draft ) !== JSON.stringify( altData.settings );
	const save = () => {
		setBusy( true );
		apiFetch( {
			path: '/barrio/v1/alt/settings',
			method: 'PUT',
			data: draft,
		} )
			.then( ( settings ) => {
				altData.settings = settings;
				onClose();
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( false ) );
	};
	return (
		<Dialog
			footer={
				<>
					<button
						type="button"
						className="barrio-button"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'barrio-suite' ) }
					</button>
					<button
						type="button"
						className="barrio-button is-primary"
						disabled={ busy || ! dirty }
						onClick={ save }
					>
						<Lucide name="save" />
						<span className="barrio-button__text">
							{ __( 'Save', 'barrio-suite' ) }
						</span>
					</button>
				</>
			}
			size="medium"
			title={ __( 'Alt text settings', 'barrio-suite' ) }
			onRequestClose={ onClose }
		>
			<Notice notice={ notice } />
			<Toggle
				label={ __( 'Generate after the upload', 'barrio-suite' ) }
				help={ __(
					'For new images without alt text. Google is called in the background, so the upload does not wait.',
					'barrio-suite'
				) }
				checked={ draft.on_upload }
				onChange={ ( on ) => setDraft( { ...draft, on_upload: on } ) }
			/>
			<Toggle
				label={ __( 'Generate the title too', 'barrio-suite' ) }
				help={ __(
					'The Generate buttons suggest a title as well. After the upload and in bulk it replaces only a title that is still the file name.',
					'barrio-suite'
				) }
				checked={ draft.title }
				onChange={ ( title ) => setDraft( { ...draft, title } ) }
			/>
		</Dialog>
	);
}

export function AltText() {
	const [ query, setQuery ] = useState( INITIAL );
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ settings, setSettings ] = useState( false );
	const [ running, setRunning ] = useState( false );
	// Cambia dopo ogni scrittura: ricarica la pagina e i conteggi.
	const [ reload, setReload ] = useState( 0 );
	const stopped = useRef( false );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	useEffect( () => {
		let current = true;
		// Mentre si scrive nella ricerca parte una sola richiesta.
		const timer = setTimeout( () => {
			apiFetch( { path: `/barrio/v1/alt/images?${ params( query ) }` } )
				.then( ( result ) => current && setData( result ) )
				.catch( fail );
		}, 250 );
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ query, reload ] );

	if ( ! data ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	// Una alla volta, per i limiti di richieste al minuto di Google. Si ferma al primo errore o con Interrompi: le
	// immagini già fatte restano salvate.
	const run = async ( ids ) => {
		stopped.current = false;
		setRunning( true );
		let done = 0;
		try {
			for ( const id of ids ) {
				if ( stopped.current ) {
					break;
				}
				setNotice( {
					status: 'info',
					message: sprintf(
						/* translators: 1: number of the image being generated, 2: number of images. */
						__( 'Generating %1$d of %2$d…', 'barrio-suite' ),
						done + 1,
						ids.length
					),
					action: (
						<button
							type="button"
							className="barrio-button"
							onClick={ () => {
								stopped.current = true;
							} }
						>
							{ __( 'Stop', 'barrio-suite' ) }
						</button>
					),
				} );
				await generate( id, true );
				done++;
			}
			setNotice( {
				status: 'success',
				message: sprintf(
					/* translators: 1: number of images done, 2: number of images. */
					__( '%1$d of %2$d images done.', 'barrio-suite' ),
					done,
					ids.length
				),
			} );
		} catch ( error ) {
			fail( error );
		} finally {
			setRunning( false );
			setReload( ( n ) => n + 1 );
		}
	};
	const all = () => {
		setNotice( null );
		apiFetch( {
			path: `/barrio/v1/alt/images?${ params( {
				alt: 'missing',
				fields: 'ids',
			} ) }`,
		} )
			.then( ( { ids } ) =>
				ids.length
					? run( ids )
					: setNotice( {
							status: 'success',
							message: __(
								'Every image has its alt text.',
								'barrio-suite'
							),
						} )
			)
			.catch( fail );
	};
	const ready = altData.ai && ! running;

	const columns = [
		{
			key: 'image',
			label: __( 'Image', 'barrio-suite' ),
			sort: 'title',
			className: 'is-truncate',
			render: ( row ) => (
				<span className="barrio-row is-nowrap">
					<img
						className="barrio-alt-thumb"
						src={ row.thumb }
						alt=""
					/>
					{ row.can ? (
						<button
							type="button"
							className="barrio-table__title"
							onClick={ () => setEditing( { row } ) }
						>
							{ row.title || row.file }
						</button>
					) : (
						row.title || row.file
					) }
				</span>
			),
		},
		{
			key: 'alt',
			label: __( 'Alt text', 'barrio-suite' ),
			className: 'is-truncate',
			hideable: true,
			render: ( row ) => row.alt || '—',
		},
		{
			key: 'file',
			label: __( 'File', 'barrio-suite' ),
			className: 'is-truncate',
			hideable: true,
			hidden: true,
		},
		{
			key: 'parent',
			label: __( 'Attached to', 'barrio-suite' ),
			className: 'is-truncate',
			hideable: true,
			render: ( row ) => row.parent || '—',
		},
		{
			key: 'date',
			label: __( 'Date', 'barrio-suite' ),
			sort: 'date',
		},
		{
			key: 'actions',
			label: (
				<span className="screen-reader-text">
					{ __( 'Actions', 'barrio-suite' ) }
				</span>
			),
			className: 'is-narrow is-end',
			render: ( row ) =>
				row.can && (
					<Menu
						label={ sprintf(
							/* translators: %s: name of the row: a form, a page, a post or a term. */
							__( 'Actions for "%s"', 'barrio-suite' ),
							row.title || row.file
						) }
						items={ [
							{
								label: __( 'Generate', 'barrio-suite' ),
								icon: 'lightbulb',
								disabled: ! ready || ! row.supported,
								info: altData.ai ? undefined : AI_SETUP,
								onClick: () =>
									setEditing( { row, start: true } ),
							},
							{
								label: __( 'Edit', 'barrio-suite' ),
								icon: 'edit',
								onClick: () => setEditing( { row } ),
							},
							{
								label: __( 'Edit in Media', 'barrio-suite' ),
								icon: 'admin-media',
								href: row.edit,
							},
						] }
					/>
				),
		},
	];

	return (
		<div className="barrio-stack">
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Table
				columns={ columns }
				rows={ data.rows }
				total={ data.total }
				query={ query }
				initial={ INITIAL }
				onQuery={ setQuery }
				title={ ALT.title }
				search={ __( 'Search images', 'barrio-suite' ) }
				empty={
					'missing' === query.alt && ! query.search
						? __( 'Every image has its alt text.', 'barrio-suite' )
						: __( 'No images found.', 'barrio-suite' )
				}
				filters={ [
					{
						key: 'alt',
						label: __( 'Alt text', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All images', 'barrio-suite' ),
								count: data.counts.all,
							},
							{
								value: 'missing',
								label: __( 'Without alt text', 'barrio-suite' ),
								count: data.counts.missing,
							},
						],
					},
				] }
				bulk={ ( ids ) => [
					{
						label: __(
							'Generate with AI, empty fields only',
							'barrio-suite'
						),
						icon: 'lightbulb',
						disabled: ! ready,
						info: altData.ai ? undefined : AI_SETUP,
						// Le immagini che chi guarda non può modificare, o in un formato che Google non legge, si saltano.
						onClick: () =>
							run(
								data.rows
									.filter(
										( row ) =>
											ids.includes( row.id ) &&
											row.can &&
											row.supported
									)
									.map( ( row ) => row.id )
							),
					},
				] }
				toolbar={
					<>
						{ altData.settings && (
							<button
								type="button"
								className="barrio-button"
								onClick={ () => setSettings( true ) }
							>
								<Icon name="admin-generic" />
								<span className="barrio-button__text">
									{ __( 'Settings', 'barrio-suite' ) }
								</span>
							</button>
						) }
						<button
							type="button"
							className="barrio-button is-primary"
							disabled={ ! ready }
							title={ altData.ai ? undefined : AI_SETUP }
							onClick={ all }
						>
							<Icon name="lightbulb" />
							<span className="barrio-button__text">
								{ __( 'Generate all missing', 'barrio-suite' ) }
							</span>
						</button>
					</>
				}
			/>
			{ editing && (
				<AltModal
					row={ editing.row }
					start={ editing.start }
					onSaved={ () => setReload( ( n ) => n + 1 ) }
					onClose={ () => setEditing( null ) }
				/>
			) }
			{ settings && (
				<SettingsModal onClose={ () => setSettings( false ) } />
			) }
		</div>
	);
}
