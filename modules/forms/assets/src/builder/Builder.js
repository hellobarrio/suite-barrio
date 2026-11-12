// Builder di un form: carica con GET /forms/{id}?context=edit e salva con PUT e base_version
// (409 se nel frattempo qualcuno ha salvato) o, per un form nuovo, con POST /forms.
import apiFetch from '@wordpress/api-fetch';
import { Modal, TabPanel } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Check,
	Icon,
	Loading,
	Lucide,
	Notice,
	SectionHead,
	Shortcode,
	Text,
	pageUrl,
	useScrollIntoView,
	useUnsavedWarning,
} from '../admin/ui.js';
import { Conditions } from './Conditions.js';
import { FieldsTab, ruleFields } from './Fields.js';
import { errorMessage, withTexts } from './model.js';

const lang = window.barrioAdmin.languages[ 0 ];

// Un testo facoltativo vuoto è null: il valore standard (docs/database.md).
const orNull = ( optional, value ) => ( optional && ! value ? null : value );

const empty = () => ( {
	title: '',
	i18n: {
		[ lang ]: {
			submit_label: __( 'Submit', 'barrio-suite' ),
			success_message: null,
			success_url: null,
		},
	},
	notifications: [],
	fields: [],
} );

export function Builder( { id } ) {
	const [ form, setForm ] = useState( id ? null : empty );
	const [ saved, setSaved ] = useState( form );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	// L'anteprima aperta: del form, o di una notifica con destinatari e oggetto.
	const [ preview, setPreview ] = useState( null );

	const load = ( data ) => {
		const loaded = withTexts( data, lang );
		setForm( loaded );
		setSaved( loaded );
	};
	const fail = ( error ) =>
		setNotice( {
			status: 'error',
			message: errorMessage( error ),
			action: 'barrio_version_conflict' === error.code && (
				<button
					type="button"
					className="barrio-button"
					onClick={ () => window.location.reload() }
				>
					{ __( 'Reload the form', 'barrio-suite' ) }
				</button>
			),
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
	const request = ( kind, options, then ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( then )
			.catch( fail )
			.finally( () => setBusy( null ) );
	};
	const save = () =>
		request(
			'save',
			form.id
				? {
						path: `/barrio/v1/forms/${ form.id }`,
						method: 'PUT',
						data: { ...form, base_version: form.version },
					}
				: { path: '/barrio/v1/forms', method: 'POST', data: form },
			( data ) => {
				load( data );
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
				data: { ...form, notification },
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
				<button
					type="button"
					className="barrio-button"
					disabled={ !! busy }
					onClick={ () => showPreview() }
				>
					<Icon name="visibility" />
					{ __( 'Preview', 'barrio-suite' ) }
				</button>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ !! busy || ( form.id && ! dirty ) }
					onClick={ save }
				>
					<Lucide name="save" />
					{ 'save' === busy
						? __( 'Saving…', 'barrio-suite' )
						: __( 'Save', 'barrio-suite' ) }
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />

			<TabPanel
				tabs={ [
					{ name: 'fields', title: __( 'Fields', 'barrio-suite' ) },
					{
						name: 'submission',
						title: __( 'Submission', 'barrio-suite' ),
					},
					{
						name: 'notifications',
						title: __( 'Notifications', 'barrio-suite' ),
					},
				] }
			>
				{ ( tab ) => (
					<>
						{ 'fields' === tab.name && (
							<FieldsTab
								fields={ form.fields }
								onChange={ ( fields ) => update( { fields } ) }
							/>
						) }
						{ 'submission' === tab.name && (
							<SubmissionTab form={ form } update={ update } />
						) }
						{ 'notifications' === tab.name && (
							<NotificationsTab
								form={ form }
								update={ update }
								onPreview={ showPreview }
								busy={ !! busy }
							/>
						) }
					</>
				) }
			</TabPanel>

			{ preview && (
				<Modal
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
							<dl className="barrio-email-meta">
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
						<iframe
							className="barrio-preview-frame"
							title={ __( 'Preview', 'barrio-suite' ) }
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
				</Modal>
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

function SubmissionTab( { form, update } ) {
	const text = form.i18n[ lang ];
	const setText = ( key, optional ) => ( value ) =>
		update( {
			i18n: {
				...form.i18n,
				[ lang ]: { ...text, [ key ]: orNull( optional, value ) },
			},
		} );
	return (
		<Card
			className="barrio-wide"
			icon="yes-alt"
			title={ __( 'Submission', 'barrio-suite' ) }
			description={ __(
				'The button that sends the form and what visitors see afterwards.',
				'barrio-suite'
			) }
		>
			<Text
				label={ __( 'Submit button', 'barrio-suite' ) }
				value={ text.submit_label }
				onChange={ setText( 'submit_label' ) }
			/>
			<Text
				multiline
				label={ __( 'Success message', 'barrio-suite' ) }
				help={ __(
					'Shown after sending. Leave empty for the standard message.',
					'barrio-suite'
				) }
				value={ text.success_message }
				onChange={ setText( 'success_message', true ) }
			/>
			<Text
				label={ __( 'Redirect after sending', 'barrio-suite' ) }
				help={ __(
					'A page to open instead of the message, like /thank-you/ or https://example.com/thank-you/. Leave empty to stay on the page.',
					'barrio-suite'
				) }
				value={ text.success_url }
				onChange={ setText( 'success_url', true ) }
			/>
		</Card>
	);
}

function NotificationsTab( { form, update, onPreview, busy } ) {
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
						[ lang ]: {
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
					fields={ fields }
					tags={ tags }
					isNew={ i === added }
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

function NotificationCard( {
	notification: n,
	fields,
	tags,
	isNew,
	busy,
	onChange,
	onPreview,
	onRemove,
} ) {
	const ref = useScrollIntoView( isNew );
	const text = n.i18n[ lang ];
	const set = ( key, optional ) => ( value ) =>
		onChange( { ...n, [ key ]: orNull( optional, value ) } );
	const setText = ( key, optional ) => ( value ) =>
		onChange( {
			...n,
			i18n: {
				...n.i18n,
				[ lang ]: { ...text, [ key ]: orNull( optional, value ) },
			},
		} );

	return (
		<div ref={ ref } className="barrio-scroll-target">
			<Card
				className="barrio-wide"
				icon="email"
				title={ n.name || __( 'Notification', 'barrio-suite' ) }
				actions={
					<>
						<Check
							label={ __( 'Active', 'barrio-suite' ) }
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
							onClick={ onRemove }
						>
							<Icon name="trash" />
						</button>
					</>
				}
			>
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
				<Text
					label={ __( 'Subject', 'barrio-suite' ) }
					value={ text.subject }
					onChange={ setText( 'subject' ) }
				/>
				<Text
					label={ __( 'Heading', 'barrio-suite' ) }
					help={ __(
						'Shown at the top of the email. Leave empty to use the subject.',
						'barrio-suite'
					) }
					value={ text.heading }
					onChange={ setText( 'heading', true ) }
				/>
				<Text
					multiline
					rows={ 8 }
					label={ __( 'Message', 'barrio-suite' ) }
					help={ __( 'HTML is allowed.', 'barrio-suite' ) }
					value={ text.body }
					onChange={ setText( 'body' ) }
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
				<Check
					label={ __( 'Attach the uploaded files', 'barrio-suite' ) }
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
			</Card>
		</div>
	);
}
