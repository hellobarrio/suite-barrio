// Barrio → Migration: i passaggi che un'estensione di importazione (barrio-suite-migration) dà con barrio_admin_data,
// in window.barrioAdmin.migration, una card ciascuno con le sue rotte REST. Migra si apre solo dopo la prova a vuoto
// del passaggio, in questa pagina. Gli item di un passaggio (i form) vanno uno per richiesta, poi una senza item chiude.
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Buttons,
	Card,
	Icon,
	Notice,
	errorMessage,
	useUnsavedWarning,
} from '../admin/ui.js';

const STATUS = {
	pending: [ __( 'To migrate', 'barrio-suite' ), 'is-warning' ],
	migrated: [ __( 'Migrated', 'barrio-suite' ), 'is-success' ],
	empty: [ __( 'Nothing to migrate', 'barrio-suite' ), '' ],
};

// label distingue note e avvisi per chi non vede il colore.
const List = ( { status, label, items } ) =>
	items.length > 0 && (
		<div className={ `barrio-notice is-${ status }` }>
			<ul className="barrio-report" aria-label={ label }>
				{ items.map( ( item, index ) => (
					<li key={ index }>{ item }</li>
				) ) }
			</ul>
		</div>
	);

function Report( { step, report } ) {
	let notice = {
		status: 'success',
		message: report.dryRun
			? __(
					'Dry run: nothing was saved. If the report is right, migrate.',
					'barrio-suite'
				)
			: __( 'Migrated. Empty the page cache.', 'barrio-suite' ),
	};
	// Un form non passato è una riga del resoconto in una risposta riuscita: il passaggio resta da migrare.
	if ( ! report.dryRun && 'pending' === step.status ) {
		notice = {
			status: 'warning',
			message: __(
				'Not everything was migrated: see the report.',
				'barrio-suite'
			),
		};
	}
	if ( report.error ) {
		notice = { status: 'error', message: report.error };
	}
	const columns = Object.entries( step.columns );
	return (
		<>
			<Notice notice={ notice } />
			{ report.rows.length > 0 && (
				<div className="barrio-card">
					<div className="barrio-table__scroll">
						<table className="barrio-table">
							<thead>
								<tr>
									{ columns.map( ( [ key, label ] ) => (
										<th key={ key } scope="col">
											{ label }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ report.rows.map( ( row, index ) => (
									<tr key={ index }>
										{ columns.map( ( [ key ] ) => (
											<td key={ key }>{ row[ key ] }</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</div>
			) }
			<List
				status="info"
				label={ __( 'Notes', 'barrio-suite' ) }
				items={ report.notes }
			/>
			<List
				status="warning"
				label={ __( 'Warnings', 'barrio-suite' ) }
				items={ report.warnings }
			/>
		</>
	);
}

export function Migration() {
	const [ steps, setSteps ] = useState( window.barrioAdmin.migration ?? [] );
	// Per passaggio: { dryRun, rows, notes, warnings, error }.
	const [ reports, setReports ] = useState( {} );
	// { id, dryRun, done, total } mentre un passaggio lavora: uno alla volta.
	const [ busy, setBusy ] = useState( null );
	// Chiusa a metà, la richiesta che chiude il passaggio (impostazioni e shortcode) non partirebbe.
	useUnsavedWarning( !! busy );

	const run = async ( step, dryRun ) => {
		if (
			! dryRun &&
			// eslint-disable-next-line no-alert -- conferma prima di scrivere i dati migrati.
			! window.confirm(
				sprintf(
					/* translators: %s: step name, like Forms. */
					__(
						'Migrate %s? What the dry run showed is saved in Barrio Suite.',
						'barrio-suite'
					),
					step.title
				)
			)
		) {
			return;
		}
		// key: un resoconto nuovo si annuncia anche se dice lo stesso di prima.
		const report = {
			key: Date.now(),
			dryRun,
			rows: [],
			notes: [],
			warnings: [],
		};
		const post = async ( item ) => {
			const response = await apiFetch( {
				path: step.path,
				method: 'POST',
				data: { dry_run: dryRun, ...( item && { item } ) },
			} );
			report.rows.push( ...response.rows );
			report.notes.push( ...response.notes );
			report.warnings.push( ...response.warnings );
			return response;
		};
		try {
			for ( const [ done, item ] of step.items.entries() ) {
				setBusy( {
					id: step.id,
					dryRun,
					done,
					total: step.items.length,
				} );
				// Uno per richiesta, uno dopo l'altro: un form con molte registrazioni può durare.
				await post( item );
			}
			setBusy( { id: step.id, dryRun } );
			const { step: now } = await post();
			setSteps( ( all ) =>
				all.map( ( one ) => ( one.id === now.id ? now : one ) )
			);
		} catch ( error ) {
			// Un tempo scaduto arriva come errore generico di WordPress o del proxy: si dice almeno come ripartire.
			report.error = `${ errorMessage( error ) } ${ __(
				'Run the dry run again: what was already migrated is skipped.',
				'barrio-suite'
			) }`;
		}
		setBusy( null );
		setReports( ( all ) => ( { ...all, [ step.id ]: report } ) );
	};

	return (
		<div className="barrio-stack">
			<p className="barrio-field__help">
				{ __(
					'In this order, one step at a time. Dry run shows the counts and the warnings without saving anything; then Migrate saves the data in Barrio Suite. Each step can be run again: what Barrio Suite already has stays as it is.',
					'barrio-suite'
				) }
			</p>
			{ steps.map( ( step ) => {
				const report = reports[ step.id ];
				const [ status, style ] = STATUS[ step.status ];
				const button = ( dryRun, label, icon ) => {
					let text = label;
					if ( busy?.id === step.id && busy.dryRun === dryRun ) {
						text = busy.total
							? sprintf(
									/* translators: 1: item being migrated; 2: number of items. */
									__( '%1$d of %2$d…', 'barrio-suite' ),
									busy.done + 1,
									busy.total
								)
							: __( 'Please wait…', 'barrio-suite' );
					}
					return (
						<button
							type="button"
							className={ `barrio-button${
								dryRun ? '' : ' is-primary'
							}` }
							aria-label={ sprintf(
								/* translators: 1: action or its progress, like Dry run or 1 of 5…; 2: step name, like Forms. */
								__( '%1$s: %2$s', 'barrio-suite' ),
								text,
								step.title
							) }
							disabled={
								!! busy ||
								( ! dryRun &&
									( ! report?.dryRun || !! report.error ) )
							}
							onClick={ () => run( step, dryRun ) }
						>
							<Icon name={ icon } />
							<span className="barrio-button__text">
								{ text }
							</span>
						</button>
					);
				};
				return (
					<Card
						key={ step.id }
						className="barrio-wide"
						icon="database"
						title={ step.title }
						description={ step.description }
						actions={
							<>
								<span className={ `barrio-badge ${ style }` }>
									{ status }
								</span>
								<Buttons>
									{ button(
										true,
										__( 'Dry run', 'barrio-suite' ),
										'visibility'
									) }
									{ button(
										false,
										__( 'Migrate', 'barrio-suite' ),
										'database-import'
									) }
								</Buttons>
							</>
						}
					>
						{ report && (
							<Report
								key={ report.key }
								step={ step }
								report={ report }
							/>
						) }
					</Card>
				);
			} ) }
		</div>
	);
}
