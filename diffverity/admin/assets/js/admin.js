/** DiffVerity admin interactions. */
( function () {
	'use strict';

	const config = window.diffverityAdmin || {};
	const post = async ( action, data = {} ) => {
		const body = new URLSearchParams( { action, nonce: config.nonce, ...data } );
		const response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body } );
		const json = await response.json();
		if ( ! json.success ) {
			throw new Error( json.data && json.data.message ? json.data.message : 'Request failed.' );
		}
		return json.data;
	};

	const scan = document.querySelector( '[data-diffverity-scan]' );
	if ( scan ) {
		const start = scan.querySelector( '[data-diffverity-start]' );
		const cancel = scan.querySelector( '[data-diffverity-cancel]' );
		const progress = scan.querySelector( '[data-diffverity-progress]' );
		const bar = scan.querySelector( '[data-diffverity-progress-bar]' );
		const text = scan.querySelector( '[data-diffverity-progress-text]' );
		const component = scan.querySelector( '[data-diffverity-scan-component]' );
		let scanId = 0;
		let stopped = false;
		const paint = ( data ) => {
			const componentRatio = data.total_components ? data.processed_components / data.total_components : 0;
			const fileRatio = data.total_files ? data.processed_files / data.total_files / Math.max( 1, data.total_components ) : 0;
			bar.style.width = Math.min( 100, Math.round( ( componentRatio + fileRatio ) * 100 ) ) + '%';
			text.textContent = data.current_component ? `Scanning ${ data.current_component }: ${ data.processed_files } of ${ data.total_files } files. Components ${ data.processed_components } of ${ data.total_components }.` : `Components ${ data.processed_components } of ${ data.total_components }.`;
		};
		const step = async () => {
			if ( stopped ) return;
			try {
				const data = await post( 'diffverity_scan_step', { scan_id: scanId } );
				paint( data );
				if ( data.status === 'complete' ) {
					text.textContent = config.labels.complete;
					cancel.hidden = true;
					setTimeout( () => { window.location.href = 'admin.php?page=diffverity-changes&scan_id=' + data.scan_id; }, 700 );
				} else {
					window.setTimeout( step, 50 );
				}
			} catch ( error ) {
				text.textContent = error.message;
				start.disabled = false;
				cancel.hidden = true;
			}
		};
		start.addEventListener( 'click', async () => {
			start.disabled = true; stopped = false; progress.hidden = false; cancel.hidden = false; text.textContent = config.labels.starting;
			try { const data = await post( 'diffverity_start_scan', { component: component ? component.value : '' } ); scanId = data.scan_id; paint( data ); step(); } catch ( error ) { text.textContent = error.message; start.disabled = false; cancel.hidden = true; }
		} );
		cancel.addEventListener( 'click', async () => { if ( ! scanId ) return; stopped = true; try { await post( 'diffverity_cancel_scan', { scan_id: scanId } ); text.textContent = config.labels.cancelled; cancel.hidden = true; start.disabled = false; } catch ( error ) { text.textContent = error.message; } } );
	}

	document.querySelectorAll( '[data-diffverity-baseline-create]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
		if ( ! window.confirm( config.labels.confirmBaseline ) ) return;
		button.disabled = true;
		try { await post( 'diffverity_create_baseline', { component: document.querySelector( '[data-diffverity-component]' ).value } ); window.location.reload(); } catch ( error ) { window.alert( error.message ); button.disabled = false; }
	} ) );
	document.querySelectorAll( '[data-diffverity-baseline-delete]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
		if ( ! window.confirm( config.labels.confirmDelete ) ) return;
		try { await post( 'diffverity_delete_baseline', { baseline_id: button.dataset.diffverityBaselineDelete } ); window.location.reload(); } catch ( error ) { window.alert( error.message ); }
	} ) );

	const modal = document.querySelector( '[data-diffverity-modal]' );
	const modalBody = modal ? modal.querySelector( '[data-diffverity-modal-body]' ) : null;
	const openModal = () => { modal.hidden = false; modal.querySelector( '[data-diffverity-close]' ).focus(); };
	if ( modal ) {
		modal.querySelectorAll( '[data-diffverity-close]' ).forEach( ( item ) => item.addEventListener( 'click', () => { modal.hidden = true; } ) );
		document.addEventListener( 'keydown', ( event ) => { if ( 'Escape' === event.key && ! modal.hidden ) modal.hidden = true; } );
	}
	document.querySelectorAll( '[data-diffverity-details]' ).forEach( ( button ) => button.addEventListener( 'click', () => {
		const data = JSON.parse( button.dataset.result );
		modalBody.textContent = '';
		const dl = document.createElement( 'dl' );
		Object.entries( data ).forEach( ( [ key, value ] ) => { const dt = document.createElement( 'dt' ); dt.textContent = key.replaceAll( '_', ' ' ); const dd = document.createElement( 'dd' ); dd.textContent = String( value || '—' ); dl.append( dt, dd ); } );
		modalBody.append( dl ); openModal();
	} ) );
	document.querySelectorAll( '[data-diffverity-diff]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
		modalBody.textContent = 'Loading one checksum-verified source file…'; openModal();
		try { const data = await post( 'diffverity_view_diff', { result_id: button.dataset.id, scan_id: button.dataset.scan } ); modalBody.textContent = ''; if ( data.kind === 'text' ) { const wrapper = document.createElement( 'div' ); wrapper.className = 'diffverity-code-diff'; wrapper.innerHTML = data.html; modalBody.append( wrapper ); } else { const p = document.createElement( 'p' ); p.textContent = data.message; modalBody.append( p ); } } catch ( error ) { modalBody.textContent = error.message; }
	} ) );
	document.querySelectorAll( '[data-diffverity-restore]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
		if ( ! window.confirm( config.labels.confirmRestore ) ) return;
		button.disabled = true;
		const original = button.textContent;
		button.textContent = config.labels.restoring;
		try {
			const data = await post( 'diffverity_restore_file', { result_id: button.dataset.id, scan_id: button.dataset.scan } );
			button.textContent = config.labels.restored;
			button.title = data.message;
		} catch ( error ) {
			window.alert( error.message );
			button.textContent = original;
			button.disabled = false;
		}
	} ) );
}() );
