// Builder di un form: carica con GET /forms/{id}?context=edit e salva con PUT e base_version
// (409 se nel frattempo qualcuno ha salvato) o, per un form nuovo, con POST /forms. Con più lingue
// la barra sceglie quella dei testi: nelle altre si traducono solo i testi (Texts.js).
import apiFetch from '@wordpress/api-fetch';
import { TabPanel } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Dialog,
	Fixed,
	Icon,
	LangNotice,
	LangPicker,
	Loading,
	Lucide,
	Notice,
	SectionHead,
	Select,
	Text,
	Toggle,
	errorMessage,
	useScrollIntoView,
	useUnsavedWarning,
} from '../../../../../assets/src/admin/ui.js';
import { Shortcode, pageUrl } from '../ui.js';
import { fillTranslations, trimTranslations, withTexts } from './model.js';
import { Conditions } from './Conditions.js';
import { FieldsTab, ruleFields } from './Fields.js';
import { IntegrationsTab, formIntegrations } from './Integrations.js';
import { TextIn } from './Texts.js';

const { languages } = window.barrioAdmin;
const main = languages[ 0 ];

// Un testo facoltativo vuoto è null: il valore standard (docs/database.md).
const orNull = ( optional, value ) => ( optional && ! value ? null : value );

const empty = () => ( {
	title: '',
	i18n: {
		[ main ]: {
			submit_label: __( 'Submit', 'barrio-suite' ),
			success_message: null,
			success_url: null,
		},
	},
	notifications: [],
	fields: [],
	integrations: [],
} );

export function Builder( { id } ) {
	const [ form, setForm ] = useState( id ? null : empty );
	const [ saved, setSaved ] = useState( form );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	// L'anteprima aperta: del form, o di una notifica con destinatari e oggetto.
	const [ preview, setPreview ] = useState( null );
	// La lingua dei testi che si modificano (e delle anteprime).
	const [ lang, setLang ] = useState( main );

	// Ciò che si è scritto durante il salvataggio (sent) resta, da salvare.
	const load = ( data, sent ) => {
		const loaded = trimTranslations( withTexts( data, main ), languages );
		setSaved( loaded );
		setForm( ( now ) =>
			sent && now !== sent
				? { ...now, id: loaded.id, version: loaded.version }
				: loaded
		);
	};
	const fail = ( error ) =>
		setNotice( {
			status: 'error',
			message: errorMessage( error ),
			conflict:
				'barrio_version_conflict' === error.code &&
				error.data.current_version,
		} );

	useEffect( () => {
		if ( id ) {
			apiFetch( { path: `/barrio/v1/forms/${ id }?context=edit` } )
				.then( load )
				.catch( fail );
		}
	}, [ id ] );

	const dirty = JSON.stringify( form ) !== JSON.stringify( saved );
	useUnsavedWarning( dirty );

	if ( ! form ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const update = ( changes ) => setForm( { ...form, ...changes } );
	// Il formato del server: ogni lingua tradotta con tutti i testi.
	const payload = fillTranslations( form, languages );
	const request = ( kind, options, then ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( then )
			.catch( fail )
			.finally( () => setBusy( null ) );
	};
	const save = ( base = form.version ) =>
		request(
			'save',
			form.id
				? {
						path: `/barrio/v1/forms/${ form.id }`,
						method: 'PUT',
						data: { ...payload, base_version: base },
					}
				: { path: '/barrio/v1/forms', method: 'POST', data: payload },
			( data ) => {
				load( data, form );
				if ( ! form.id ) {
					window.history.replaceState(
						null,
						'',
						pageUrl( { form: data.id } )
					);
				}
				setNotice( {
					status: 'success',
					message: __( 'Form saved.', 'barrio-suite' ),
				} );
			}
		);
	// Senza notification: il form; con l'indice di una notifica: la sua email.
	const showPreview = ( notification ) =>
		request(
			'preview',
			{
				path: '/barrio/v1/forms/preview',
				method: 'POST',
				data: { ...payload, notification, lang },
			},
			setPreview
		);

	return (
		<div className="barrio-builder">
			<Bar
				title={
					<>
						<a
							className="barrio-icon-button"
							href={ pageUrl() }
							aria-label={ __( 'All forms', 'barrio-suite' ) }
						>
							<Icon name="arrow-left-alt2" />
						</a>
						<input
							className="barrio-input barrio-builder__name"
							aria-label={ __( 'Form name', 'barrio-suite' ) }
							placeholder={ __(
								'Form name (only for the admin)',
								'barrio-suite'
							) }
							value={ form.title }
							onChange={ ( event ) =>
								update( { title: event.target.value } )
							}
						/>
					</>
				}
			>
				{ form.id && (
					<Shortcode
						id={ form.id }
						onCopy={ () =>
							setNotice( {
								status: 'success',
								message: __(
									'Shortcode copied.',
									'barrio-suite'
								),
							} )
						}
					/>
				) }
				<LangPicker value={ lang } onChange={ setLang } />
				<button
					type="button"
					className="barrio-button"
					disabled={ !! busy }
					onClick={ () => showPreview() }
				>
					<Icon name="visibility" />
					<span className="barrio-button__text">
						{ __( 'Preview', 'barrio-suite' ) }
					</span>
				</button>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ !! busy || ( form.id && ! dirty ) }
					onClick={ () => save() }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ 'save' === busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice
				notice={
					notice?.conflict
						? {
								...notice,
								action: (
									<>
										<button
											type="button"
											className="barrio-button"
											onClick={ () =>
												window.location.reload()
											}
										>
											{ __(
												'Reload the form',
												'barrio-suite'
											) }
										</button>
										<button
											type="button"
											className="barrio-button"
											disabled={ !! busy }
											onClick={ () =>
												save( notice.conflict )
											}
										>
											{ __(
												'Save my version anyway',
												'barrio-suite'
											) }
										</button>
									</>
								),
							}
						: notice
				}
				onClose={ () => setNotice( null ) }
			/>
			<LangNotice lang={ lang } />

			<TabPanel
				tabs={ [
					{ name: 'fields', title: __( 'Fields', 'barrio-suite' ) },
					{ name: 'options', title: __( 'Options', 'barrio-suite' ) },
					{
						name: 'notifications',
						title: __( 'Notifications', 'barrio-suite' ),
					},
					// Solo con un'estensione che ne aggiunge, o con quelle già salvate nel form.
					( formIntegrations.length > 0 ||
						form.integrations.length > 0 ) && {
						name: 'integrations',
						title: __( 'Integrations', 'barrio-suite' ),
					},
				].filter( Boolean ) }
			>
				{ ( tab ) => (
					<>
						{ 'fields' === tab.name && (
							<FieldsTab
								fields={ form.fields }
								saved={ saved.fields }
								lang={ lang }
								onChange={ ( fields ) => update( { fields } ) }
							/>
						) }
						{ 'options' === tab.name && (
							<OptionsTab
								form={ form }
								lang={ lang }
								update={ update }
							/>
						) }
						{ 'integrations' === tab.name && (
							<IntegrationsTab
								form={ form }
								lang={ lang }
								update={ update }
							/>
						) }
						{ 'notifications' === tab.name && (
							<NotificationsTab
								form={ form }
								lang={ lang }
								update={ update }
								onPreview={ showPreview }
								busy={ !! busy }
							/>
						) }
					</>
				) }
			</TabPanel>

			{ preview && (
				<Dialog
					className="barrio-preview-modal"
					title={
						preview.subject
							? __( 'Email preview', 'barrio-suite' )
							: __( 'Preview', 'barrio-suite' )
					}
					size="large"
					onRequestClose={ () => setPreview( null ) }
				>
					<div className="barrio-stack">
						{ preview.subject ? (
							<dl className="barrio-dl">
								<dt>{ __( 'To', 'barrio-suite' ) }</dt>
								<dd>{ preview.to.join( ', ' ) || '—' }</dd>
								<dt>{ __( 'Reply to', 'barrio-suite' ) }</dt>
								<dd>{ preview.reply_to ?? '—' }</dd>
								<dt>{ __( 'Subject', 'barrio-suite' ) }</dt>
								<dd>{ preview.subject }</dd>
							</dl>
						) : (
							<p>
								{ __(
									'Conditional logic and steps work as on the site; the form is not sent. The styles of the theme are not loaded.',
									'barrio-suite'
								) }
							</p>
						) }
						{ /* allow-same-origin: con l'origine opaca gli script module del form non si caricherebbero. */ }
						<iframe
							className="barrio-preview-frame"
							title={ __( 'Preview', 'barrio-suite' ) }
							sandbox="allow-scripts allow-same-origin allow-forms"
							srcDoc={ preview.html }
							onLoad={ fitContent }
						/>
						{ preview.subject && (
							<p className="barrio-field__help">
								{ __(
									'With sample values: the field label in square brackets, or a realistic value for emails, dates and choices.',
									'barrio-suite'
								) }
							</p>
						) }
					</div>
				</Dialog>
			) }
		</div>
	);
}

// L'iframe è alto quanto il contenuto, anche quando cambia (passi, errori): scorre solo il modale.
function fitContent( { target: frame } ) {
	const root = frame.contentDocument.documentElement;
	new frame.contentWindow.ResizeObserver( () => {
		frame.style.height = `${ root.scrollHeight }px`;
	} ).observe( root );
}

// Scheda Opzioni: il pulsante di invio e cosa succede dopo, un messaggio o un'altra pagina.
function OptionsTab( { form, lang, update } ) {
	const [ redirect, setRedirect ] = useState(
		!! form.i18n[ main ].success_url
	);
	const props = { item: form, lang, onChange: update };
	const setAfter = ( after ) => {
		setRedirect( 'page' === after );
		if ( 'page' !== after ) {
			// Con il messaggio nessuna lingua apre una pagina.
			update( {
				i18n: Object.fromEntries(
					Object.entries( form.i18n ).map( ( [ code, text ] ) => [
						code,
						{ ...text, success_url: null },
					] )
				),
			} );
		}
	};
	return (
		<Card
			className="barrio-wide"
			icon="yes-alt"
			title={ __( 'Submission', 'barrio-suite' ) }
		>
			<TextIn
				{ ...props }
				name="submit_label"
				label={ __( 'Submit button', 'barrio-suite' ) }
			/>
			<Fixed lang={ lang }>
				<Select
					label={ __( 'After sending', 'barrio-suite' ) }
					value={ redirect ? 'page' : 'message' }
					options={ [
						{
							value: 'message',
							label: __( 'Show a message', 'barrio-suite' ),
						},
						{
							value: 'page',
							label: __( 'Open another page', 'barrio-suite' ),
						},
					] }
					onChange={ setAfter }
				/>
			</Fixed>
			{ redirect ? (
				<TextIn
					{ ...props }
					name="success_url"
					optional
					label={ __( 'Page to open', 'barrio-suite' ) }
					help={ __(
						'Like /thank-you/ or https://example.com/thank-you/.',
						'barrio-suite'
					) }
				/>
			) : (
				<TextIn
					{ ...props }
					name="success_message"
					optional
					multiline
					label={ __( 'Success message', 'barrio-suite' ) }
					help={ __(
						'Leave empty for the standard message.',
						'barrio-suite'
					) }
				/>
			) }
		</Card>
	);
}

function NotificationsTab( { form, lang, update, onPreview, busy } ) {
	const translating = lang !== main;
	const [ added, setAdded ] = useState( null );
	const set = ( i, notification ) =>
		update( {
			notifications: form.notifications.map( ( n, j ) =>
				i === j ? notification : n
			),
		} );
	const add = () => {
		setAdded( form.notifications.length );
		update( {
			notifications: [
				...form.notifications,
				{
					name: __( 'Admin notification', 'barrio-suite' ),
					recipients: '{{admin_email}}',
					reply_to: null,
					enabled: true,
					attach_files: false,
					conditions: null,
					i18n: {
						[ main ]: {
							subject: __(
								'New entry: {{form_title}}',
								'barrio-suite'
							),
							heading: null,
							body: '{{all_fields}}',
						},
					},
				},
			],
		} );
	};
	const fields = ruleFields( form.fields );
	const tags = [
		'all_fields',
		'form_title',
		'entry_id',
		'entry_date',
		'source_url',
		'site_name',
		'admin_email',
		...fields.map( ( f ) => f.key ),
	];

	return (
		<div className="barrio-stack">
			<SectionHead
				title={ __( 'Emails sent after each entry', 'barrio-suite' ) }
			>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ translating }
					onClick={ add }
				>
					<Icon name="plus-alt2" />
					{ __( 'Add notification', 'barrio-suite' ) }
				</button>
			</SectionHead>
			{ form.notifications.map( ( n, i ) => (
				<NotificationCard
					key={ i }
					notification={ n }
					lang={ lang }
					fields={ fields }
					tags={ tags }
					isNew={ i === added }
					open={ i === added || 1 === form.notifications.length }
					busy={ busy }
					onChange={ ( notification ) => set( i, notification ) }
					onPreview={ () => onPreview( i ) }
					onRemove={ () => {
						// Senza questo, aggiungere di nuovo nella stessa posizione non fa lo scroll.
						setAdded( null );
						update( {
							notifications: form.notifications.filter(
								( x, j ) => i !== j
							),
						} );
					} }
				/>
			) ) }
			{ ! form.notifications.length && (
				<Card
					description={ __(
						'No email is sent after a submission: entries are saved anyway.',
						'barrio-suite'
					) }
				/>
			) }
		</div>
	);
}

// In un'altra lingua si traducono oggetto, intestazione e messaggio; il resto è uguale in tutte.
function NotificationCard( {
	notification: n,
	lang,
	fields,
	tags,
	isNew,
	open,
	busy,
	onChange,
	onPreview,
	onRemove,
} ) {
	const ref = useScrollIntoView( isNew );
	const translating = lang !== main;
	const set = ( key, optional ) => ( value ) =>
		onChange( { ...n, [ key ]: orNull( optional, value ) } );
	const props = { item: n, lang, onChange };

	return (
		<div ref={ ref } className="barrio-scroll-target">
			<Card
				className="barrio-wide"
				collapsible
				defaultOpen={ open }
				icon="email"
				title={ n.name || __( 'Notification', 'barrio-suite' ) }
				actions={
					<>
						<Toggle
							label={ __( 'Active', 'barrio-suite' ) }
							disabled={ translating }
							checked={ n.enabled }
							onChange={ set( 'enabled' ) }
						/>
						<button
							type="button"
							className="barrio-button"
							disabled={ busy }
							onClick={ onPreview }
						>
							<Icon name="visibility" />
							{ __( 'Preview', 'barrio-suite' ) }
						</button>
						<button
							type="button"
							className="barrio-icon-button is-danger"
							aria-label={ __(
								'Remove notification',
								'barrio-suite'
							) }
							disabled={ translating }
							onClick={ onRemove }
						>
							<Icon name="trash" />
						</button>
					</>
				}
			>
				<Fixed lang={ lang }>
					<Text
						label={ __( 'Name', 'barrio-suite' ) }
						help={ __(
							'Only for the admin, for example in the notes of the entries.',
							'barrio-suite'
						) }
						value={ n.name }
						onChange={ set( 'name' ) }
					/>
					<Text
						label={ __( 'Send to', 'barrio-suite' ) }
						help={ __(
							'Addresses separated by commas; merge tags are allowed, like {{admin_email}} or {{email}}.',
							'barrio-suite'
						) }
						value={ n.recipients }
						onChange={ set( 'recipients' ) }
					/>
					<Text
						label={ __( 'Reply to', 'barrio-suite' ) }
						help={ __(
							'Optional, for example {{email}}.',
							'barrio-suite'
						) }
						value={ n.reply_to }
						onChange={ set( 'reply_to', true ) }
					/>
				</Fixed>
				<TextIn
					{ ...props }
					name="subject"
					label={ __( 'Subject', 'barrio-suite' ) }
				/>
				<TextIn
					{ ...props }
					name="heading"
					optional
					label={ __( 'Heading', 'barrio-suite' ) }
					help={ __(
						'Shown at the top of the email. Leave empty to use the subject.',
						'barrio-suite'
					) }
				/>
				<TextIn
					{ ...props }
					name="body"
					multiline
					rows={ 8 }
					label={ __( 'Message', 'barrio-suite' ) }
					help={ __( 'HTML is allowed.', 'barrio-suite' ) }
				/>
				<div className="barrio-field">
					<span className="barrio-field__label">
						{ __( 'Merge tags', 'barrio-suite' ) }
					</span>
					<div className="barrio-merge-tags">
						{ tags.map( ( tag ) => (
							<code key={ tag }>{ `{{${ tag }}}` }</code>
						) ) }
					</div>
				</div>
				<Fixed lang={ lang }>
					<Toggle
						label={ __(
							'Attach the uploaded files',
							'barrio-suite'
						) }
						checked={ n.attach_files }
						onChange={ set( 'attach_files' ) }
					/>
					<Conditions
						value={ n.conditions }
						fields={ fields }
						actions={ [
							{
								value: 'show',
								label: __( 'Send it', 'barrio-suite' ),
							},
							{
								value: 'hide',
								label: __( 'Do not send it', 'barrio-suite' ),
							},
						] }
						empty={ __( 'Add some fields first.', 'barrio-suite' ) }
						onChange={ set( 'conditions' ) }
					/>
				</Fixed>
			</Card>
		</div>
	);
}
