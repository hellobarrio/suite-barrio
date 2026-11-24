// Una registrazione: i campi della versione compilata, i dettagli, il rinvio delle notifiche e lo
// storico con le note. Aprirla la segna come letta.
import apiFetch from '@wordpress/api-fetch';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Icon,
	Lang,
	Loading,
	Notice,
	Text,
	errorMessage,
	restLink,
	useUnsavedWarning,
} from '../../../../../assets/src/admin/ui.js';
import { entriesUrl } from '../ui.js';

const { languages } = window.barrioAdmin;

const LEVEL = {
	info: [ __( 'Note', 'barrio-suite' ), '' ],
	success: [ __( 'Sent', 'barrio-suite' ), 'is-success' ],
	error: [ __( 'Error', 'barrio-suite' ), 'is-error' ],
};

// Coppie etichetta e valore; quelle senza valore si saltano.
const Details = ( { items } ) => (
	<dl className="barrio-dl">
		{ items
			.filter( ( [ , value ] ) => value )
			.map( ( [ label, value ] ) => (
				<Fragment key={ label }>
					<dt>{ label }</dt>
					<dd>{ value }</dd>
				</Fragment>
			) ) }
	</dl>
);

// Un valore come si legge meglio: il testo lungo con i suoi a capo, email e telefono come link, i
// file da scaricare. Un campo vuoto, o nascosto dalla logica condizionale, è "—".
function Answer( { field, entryId } ) {
	if ( field.files.length ) {
		return (
			<div className="barrio-files">
				{ field.files.map( ( file ) => (
					<a
						key={ file.index }
						className="barrio-file"
						href={ restLink(
							`entries/${ entryId }/files/${ file.index }`
						) }
					>
						<Icon name="media-document" />
						<span className="barrio-file__name">{ file.name }</span>
						<span className="barrio-file__size">{ file.size }</span>
						<Icon name="download" />
					</a>
				) ) }
			</div>
		);
	}
	if ( ! field.value ) {
		return <span className="barrio-answers__empty">—</span>;
	}
	switch ( field.type ) {
		case 'textarea':
			return <div className="barrio-answers__text">{ field.value }</div>;
		case 'email':
			return <a href={ `mailto:${ field.value }` }>{ field.value }</a>;
		case 'tel':
			return (
				<a href={ `tel:${ field.value.replace( /\s/g, '' ) }` }>
					{ field.value }
				</a>
			);
		default:
			return field.value;
	}
}

const bulk = ( action, form, id ) =>
	apiFetch( {
		path: `/barrio/v1/forms/${ form }/entries/bulk`,
		method: 'POST',
		data: { action, ids: [ id ] },
	} );

export function Entry( { id } ) {
	const [ entry, setEntry ] = useState( null );
	const [ note, setNote ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	useUnsavedWarning( '' !== note.trim() );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	const load = () =>
		apiFetch( { path: `/barrio/v1/entries/${ id }` } )
			.then( setEntry )
			.catch( fail );

	useEffect( () => {
		apiFetch( { path: `/barrio/v1/entries/${ id }` } )
			.then( ( data ) => {
				setEntry( data );
				if ( ! data.is_read ) {
					bulk( 'read', data.form.id, id ).catch( fail );
				}
			} )
			.catch( fail );
	}, [ id ] );

	if ( ! entry ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const back = entriesUrl( { form: entry.form.id } );
	const run = ( kind, promise, then ) => {
		setBusy( kind );
		setNotice( null );
		promise
			.then( then )
			.catch( fail )
			.finally( () => setBusy( null ) );
	};
	const leave = () => {
		window.location.href = back;
	};
	const remove = () => {
		if (
			// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
			window.confirm(
				__(
					'Delete this entry permanently, with its files?',
					'barrio-suite'
				)
			)
		) {
			run( 'delete', bulk( 'delete', entry.form.id, id ), leave );
		}
	};
	// Riuscito o no, l'esito finisce nello storico: si ricarica comunque.
	const resend = ( notification ) =>
		run(
			`resend-${ notification.id }`,
			apiFetch( {
				path: `/barrio/v1/entries/${ id }/notifications/${ notification.id }/resend`,
				method: 'POST',
			} ).finally( load ),
			() =>
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %s: notification name. */
						__( 'Notification "%s" sent.', 'barrio-suite' ),
						notification.name
					),
				} )
		);
	const addNote = () =>
		run(
			'note',
			apiFetch( {
				path: `/barrio/v1/entries/${ id }/notes`,
				method: 'POST',
				data: { message: note },
			} ),
			( notes ) => {
				setEntry( { ...entry, notes } );
				setNote( '' );
			}
		);

	return (
		<div className="barrio-stack">
			<Bar
				title={
					<>
						<a
							className="barrio-icon-button"
							href={ back }
							aria-label={ __( 'All entries', 'barrio-suite' ) }
						>
							<Icon name="arrow-left-alt2" />
						</a>
						<h2 className="barrio-bar__title">
							{ sprintf(
								/* translators: %d: entry number. */
								__( 'Entry #%d', 'barrio-suite' ),
								entry.id
							) }
						</h2>
					</>
				}
			>
				<button
					type="button"
					className="barrio-button"
					disabled={ !! busy }
					onClick={ () =>
						run(
							'unread',
							bulk( 'unread', entry.form.id, id ),
							leave
						)
					}
				>
					<Icon name="hidden" />
					<span className="barrio-button__text">
						{ __( 'Mark as unread', 'barrio-suite' ) }
					</span>
				</button>
				<button
					type="button"
					className="barrio-button is-danger"
					disabled={ !! busy }
					onClick={ remove }
				>
					<Icon name="trash" />
					<span className="barrio-button__text">
						{ __( 'Delete', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />

			<div className="barrio-fields">
				<Card icon="feedback" title={ __( 'Answers', 'barrio-suite' ) }>
					<dl className="barrio-answers">
						{ entry.fields.map( ( field ) => (
							<div key={ field.key }>
								<dt>{ field.label }</dt>
								<dd>
									<Answer field={ field } entryId={ id } />
								</dd>
							</div>
						) ) }
					</dl>
				</Card>

				<div className="barrio-panel">
					<Card
						icon="info-outline"
						title={ __( 'Details', 'barrio-suite' ) }
					>
						<Details
							items={ [
								[
									__( 'Form', 'barrio-suite' ),
									entry.form.title,
								],
								[ __( 'Date', 'barrio-suite' ), entry.date ],
								[
									__( 'Language', 'barrio-suite' ),
									languages.length > 1 && (
										<Lang code={ entry.lang } />
									),
								],
								[
									__( 'Page', 'barrio-suite' ),
									entry.source_url && (
										<a
											href={ entry.source_url }
											target="_blank"
											rel="noreferrer"
										>
											{ entry.source_url }
										</a>
									),
								],
								[
									__( 'Form version', 'barrio-suite' ),
									String( entry.form.version ),
								],
							] }
						/>
					</Card>

					{ entry.notifications.length > 0 && (
						<Card
							icon="email-alt"
							title={ __( 'Notifications', 'barrio-suite' ) }
							description={ __(
								'Resend with the current settings of the notification.',
								'barrio-suite'
							) }
						>
							{ entry.notifications.map( ( notification ) => (
								<div
									key={ notification.id }
									className="barrio-section-head"
								>
									<span>{ notification.name }</span>
									<button
										type="button"
										className="barrio-button"
										disabled={ !! busy }
										onClick={ () => resend( notification ) }
									>
										<Icon name="email-alt" />
										{ `resend-${ notification.id }` === busy
											? __( 'Sending…', 'barrio-suite' )
											: __( 'Resend', 'barrio-suite' ) }
									</button>
								</div>
							) ) }
						</Card>
					) }

					<Card
						icon="backup"
						title={ __( 'History', 'barrio-suite' ) }
					>
						<ul className="barrio-notes">
							{ entry.notes.map( ( item, index ) => (
								<li key={ index }>
									<span
										className={ `barrio-badge ${
											LEVEL[ item.level ][ 1 ]
										}` }
									>
										{ LEVEL[ item.level ][ 0 ] }
									</span>
									<p className="barrio-notes__bubble">
										{ item.message }
									</p>
									<small>
										{ [ item.date, item.author ]
											.filter( Boolean )
											.join( ' · ' ) }
									</small>
								</li>
							) ) }
						</ul>
						<Text
							multiline
							rows={ 3 }
							label={ __( 'Add a note', 'barrio-suite' ) }
							value={ note }
							onChange={ setNote }
						/>
						<div className="barrio-row is-fill">
							<button
								type="button"
								className="barrio-button"
								disabled={ !! busy || ! note.trim() }
								onClick={ addNote }
							>
								<Icon name="plus-alt2" />
								{ __( 'Add note', 'barrio-suite' ) }
							</button>
						</div>
					</Card>
				</div>
			</div>
		</div>
	);
}
