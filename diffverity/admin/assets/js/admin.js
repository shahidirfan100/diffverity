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
		const progressTrack = scan.querySelector( '[data-diffverity-progress-track]' );
		const bar = scan.querySelector( '[data-diffverity-progress-bar]' );
		const text = scan.querySelector( '[data-diffverity-progress-text]' );
		const component = scan.querySelector( '[data-diffverity-scan-component]' );
		let scanId = Number( config.activeScanId ) || 0;
		let stopped = false;
		const paint = ( data ) => {
			const componentRatio = data.total_components ? data.processed_components / data.total_components : 0;
			const fileRatio = data.total_files ? data.processed_files / data.total_files / Math.max( 1, data.total_components ) : 0;
			const progressPercent = Math.min( 100, Math.round( ( componentRatio + fileRatio ) * 100 ) );
			bar.style.width = progressPercent + '%';
			if ( progressTrack ) progressTrack.setAttribute( 'aria-valuenow', String( progressPercent ) );
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
				scanId = 0;
				start.disabled = false;
				cancel.hidden = true;
				if ( component ) component.disabled = false;
			}
		};
		start.addEventListener( 'click', async () => {
			start.disabled = true; if ( component ) component.disabled = true; stopped = false; progress.hidden = false; cancel.hidden = false; text.textContent = config.labels.starting;
			try { const data = await post( 'diffverity_start_scan', { component: component ? component.value : '' } ); scanId = data.scan_id; paint( data ); step(); } catch ( error ) { text.textContent = error.message; start.disabled = false; cancel.hidden = true; if ( component ) component.disabled = false; }
		} );
		cancel.addEventListener( 'click', async () => { if ( ! scanId ) return; stopped = true; try { await post( 'diffverity_cancel_scan', { scan_id: scanId } ); scanId = 0; text.textContent = config.labels.cancelled; cancel.hidden = true; start.disabled = false; if ( component ) component.disabled = false; } catch ( error ) { text.textContent = error.message; } } );
		if ( scanId ) {
			start.disabled = true;
			if ( component ) component.disabled = true;
			progress.hidden = false;
			cancel.hidden = false;
			text.textContent = config.labels.resuming;
			step();
		}
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
	let returnFocus = null;
	const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	const openModal = ( trigger ) => {
		returnFocus = trigger || document.activeElement;
		modal.hidden = false;
		modal.querySelector( 'button[data-diffverity-close]' ).focus();
	};
	const closeModal = () => {
		modal.hidden = true;
		if ( returnFocus && document.contains( returnFocus ) ) returnFocus.focus();
		returnFocus = null;
	};
	if ( modal ) {
		modal.querySelectorAll( '[data-diffverity-close]' ).forEach( ( item ) => item.addEventListener( 'click', closeModal ) );
		document.addEventListener( 'keydown', ( event ) => {
			if ( modal.hidden ) return;
			if ( 'Escape' === event.key ) {
				closeModal();
				return;
			}
			if ( 'Tab' !== event.key ) return;

			const focusable = Array.from( modal.querySelectorAll( focusableSelector ) ).filter( ( item ) => item.getClientRects().length );
			if ( ! focusable.length ) return;
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];

			if ( ! modal.contains( document.activeElement ) ) {
				event.preventDefault();
				( event.shiftKey ? last : first ).focus();
			} else if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );
	}
	document.querySelectorAll( '[data-diffverity-details]' ).forEach( ( button ) => button.addEventListener( 'click', () => {
		const data = JSON.parse( button.dataset.result );
		modalBody.setAttribute( 'aria-live', 'off' );
		modalBody.textContent = '';
		const dl = document.createElement( 'dl' );
		Object.entries( data ).forEach( ( [ key, value ] ) => { const dt = document.createElement( 'dt' ); dt.textContent = key.replaceAll( '_', ' ' ); const dd = document.createElement( 'dd' ); dd.textContent = String( value || '—' ); dl.append( dt, dd ); } );
		modalBody.append( dl ); openModal( button );
	} ) );
	document.querySelectorAll( '[data-diffverity-diff]' ).forEach( ( button ) => button.addEventListener( 'click', async () => {
		modalBody.setAttribute( 'aria-live', 'polite' );
		modalBody.textContent = config.labels.loadingDiff; openModal( button );
		try { const data = await post( 'diffverity_view_diff', { result_id: button.dataset.id, scan_id: button.dataset.scan } ); modalBody.setAttribute( 'aria-live', 'off' ); modalBody.textContent = ''; if ( data.kind === 'text' ) { const wrapper = document.createElement( 'div' ); wrapper.className = 'diffverity-code-diff'; wrapper.innerHTML = data.html; modalBody.append( wrapper ); } else { const p = document.createElement( 'p' ); p.textContent = data.message; modalBody.append( p ); } } catch ( error ) { modalBody.setAttribute( 'aria-live', 'polite' ); modalBody.textContent = error.message; }
	} ) );
}() );
