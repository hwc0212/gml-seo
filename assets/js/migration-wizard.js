/**
 * GML AI SEO — Migration Wizard.
 *
 * Four-step wizard:
 *   1. Scan     — count posts that carry source-plugin meta.
 *   2. Preview  — render the first 20 field mappings.
 *   3. Execute  — require explicit "I backed up" confirmation, then start.
 *   4. Progress — poll status every N seconds until completed/failed.
 *
 * All server interaction goes through admin-ajax.php with a shared nonce
 * (`gml_seo_migration`) provided by wp_localize_script on PHP side.
 *
 * @package GML_SEO
 * @since   1.9.0
 */
( function () {
	'use strict';

	var cfg = window.gmlSeoMigration || {};
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	var root = document.getElementById( 'gml-seo-migration-root' );
	if ( ! root ) {
		return;
	}

	var state = {
		slug: ( cfg.detectedSlugs && cfg.detectedSlugs[ 0 ] ) || '',
		step: stepForServerState( cfg.initialState ),
		serverState: cfg.initialState || {},
		previewRows: [],
		pollTimer: null,
	};

	render();

	// ── Step router ────────────────────────────────────────────────────

	function stepForServerState( s ) {
		if ( ! s || ! s.status ) return 'scan';
		if ( s.status === 'running' )   return 'progress';
		if ( s.status === 'completed' ) return 'progress';
		if ( s.status === 'failed' )    return 'progress';
		if ( s.status === 'scanned' )   return 'preview';
		return 'scan';
	}

	function render() {
		root.innerHTML = '';
		var header = el( 'div', 'gml-seo-migration-header' );
		header.appendChild( renderStepsNav() );
		root.appendChild( header );

		if ( state.step === 'scan' )     root.appendChild( renderScan() );
		if ( state.step === 'preview' )  root.appendChild( renderPreview() );
		if ( state.step === 'execute' )  root.appendChild( renderExecute() );
		if ( state.step === 'progress' ) root.appendChild( renderProgress() );

		if ( state.step === 'progress' ) {
			startPolling();
		} else {
			stopPolling();
		}
	}

	function renderStepsNav() {
		var steps = [
			{ id: 'scan',     label: cfg.i18n.step1 },
			{ id: 'preview',  label: cfg.i18n.step2 },
			{ id: 'execute',  label: cfg.i18n.step3 },
			{ id: 'progress', label: cfg.i18n.step4 },
		];
		var ol = el( 'ol', 'gml-seo-migration-steps' );
		steps.forEach( function ( step ) {
			var li = el( 'li', 'gml-seo-migration-step' );
			if ( step.id === state.step ) li.classList.add( 'gml-seo-migration-step--active' );
			li.textContent = step.label;
			ol.appendChild( li );
		} );
		return ol;
	}

	// ── Step 1: Scan ───────────────────────────────────────────────────

	function renderScan() {
		var wrap = el( 'div', 'gml-seo-migration-panel' );
		wrap.appendChild( el( 'h3', '', cfg.i18n.step1 ) );

		var pickLabel = el( 'label', '', cfg.i18n.pickSource + ': ' );
		var select    = el( 'select', 'gml-seo-migration-source' );
		Object.keys( cfg.slugs || {} ).forEach( function ( slug ) {
			var opt = el( 'option', '', cfg.slugs[ slug ] );
			opt.value = slug;
			if ( slug === state.slug ) opt.selected = true;
			select.appendChild( opt );
		} );
		select.addEventListener( 'change', function () { state.slug = select.value; } );
		pickLabel.appendChild( select );
		wrap.appendChild( pickLabel );

		var btn = el( 'button', 'button button-primary', cfg.i18n.scanBtn );
		btn.style.marginLeft = '10px';
		btn.addEventListener( 'click', function () {
			if ( ! state.slug ) return;
			btn.disabled = true;
			request( 'gml_seo_migration_scan', { slug: state.slug } ).then( function ( res ) {
				btn.disabled = false;
				if ( res.success ) {
					state.serverState = res.data;
					state.step = 'preview';
					render();
				} else {
					alert( ( res.data && res.data.message ) || 'Scan failed' );
				}
			} );
		} );
		wrap.appendChild( btn );
		return wrap;
	}

	// ── Step 2: Preview ────────────────────────────────────────────────

	function renderPreview() {
		var wrap = el( 'div', 'gml-seo-migration-panel' );
		wrap.appendChild( el( 'h3', '', cfg.i18n.step2 ) );

		var summary = cfg.i18n.postsCounted.replace( '{n}', state.serverState.total_posts || 0 );
		wrap.appendChild( el( 'p', '', summary ) );

		var btn = el( 'button', 'button', cfg.i18n.previewBtn );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			request( 'gml_seo_migration_preview', { slug: state.slug, limit: 20 } ).then( function ( res ) {
				btn.disabled = false;
				if ( res.success ) {
					state.previewRows = ( res.data && res.data.rows ) || [];
					render();
					renderPreviewTable( wrap );
				} else {
					alert( ( res.data && res.data.message ) || 'Preview failed' );
				}
			} );
		} );
		wrap.appendChild( btn );

		var next = el( 'button', 'button button-primary', '→' );
		next.style.marginLeft = '10px';
		next.addEventListener( 'click', function () {
			state.step = 'execute';
			render();
		} );
		wrap.appendChild( next );

		if ( state.previewRows.length ) {
			renderPreviewTable( wrap );
		}
		return wrap;
	}

	function renderPreviewTable( wrap ) {
		// Remove any prior table first.
		var prev = wrap.querySelector( '.gml-seo-migration-preview' );
		if ( prev ) prev.parentNode.removeChild( prev );

		var table = el( 'table', 'widefat striped gml-seo-migration-preview' );
		var thead = el( 'thead' );
		var header = el( 'tr' );
		[ 'Post', 'Source key', 'Source value', 'Target key', 'Target value' ].forEach( function ( h ) {
			header.appendChild( el( 'th', '', h ) );
		} );
		thead.appendChild( header );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		state.previewRows.forEach( function ( row ) {
			( row.mapping || [] ).forEach( function ( m, idx ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', '', idx === 0 ? ( '#' + row.post_id + ' ' + ( row.title || '' ) ) : '' ) );
				tr.appendChild( el( 'td', '', m.source_key ) );
				tr.appendChild( el( 'td', '', truncate( m.source_value, 80 ) ) );
				tr.appendChild( el( 'td', '', m.target_key ) );
				tr.appendChild( el( 'td', '', truncate( m.target_value, 80 ) ) );
				tbody.appendChild( tr );
			} );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
	}

	// ── Step 3: Execute ────────────────────────────────────────────────

	function renderExecute() {
		var wrap = el( 'div', 'gml-seo-migration-panel' );
		wrap.appendChild( el( 'h3', '', cfg.i18n.step3 ) );

		var checkbox = el( 'input' );
		checkbox.type = 'checkbox';
		checkbox.id   = 'gml-seo-migration-ack';

		var label = el( 'label' );
		label.htmlFor = 'gml-seo-migration-ack';
		label.style.display = 'block';
		label.style.margin  = '12px 0';
		label.appendChild( checkbox );
		label.appendChild( document.createTextNode( ' ' + cfg.i18n.backupCheck ) );

		var btn = el( 'button', 'button button-primary', cfg.i18n.executeBtn );
		btn.disabled = true;

		checkbox.addEventListener( 'change', function () {
			btn.disabled = ! checkbox.checked;
		} );

		btn.addEventListener( 'click', function () {
			if ( ! checkbox.checked ) return;
			btn.disabled = true;
			request( 'gml_seo_migration_start', { slug: state.slug } ).then( function ( res ) {
				if ( res.success ) {
					state.serverState = res.data;
					state.step = 'progress';
					render();
				} else {
					alert( ( res.data && res.data.message ) || 'Start failed' );
					btn.disabled = false;
				}
			} );
		} );

		wrap.appendChild( label );
		wrap.appendChild( btn );
		return wrap;
	}

	// ── Step 4: Progress ───────────────────────────────────────────────

	function renderProgress() {
		var wrap = el( 'div', 'gml-seo-migration-panel gml-seo-migration-progress' );
		wrap.appendChild( el( 'h3', '', cfg.i18n.step4 ) );

		var s = state.serverState || {};
		var pct = s.total_posts > 0 ? Math.round( ( ( s.processed_posts || 0 ) / s.total_posts ) * 100 ) : 0;

		var barOuter = el( 'div', 'gml-seo-migration-progress-bar' );
		var barInner = el( 'div', 'gml-seo-migration-progress-bar-fill' );
		barInner.style.width = pct + '%';
		barOuter.appendChild( barInner );
		wrap.appendChild( barOuter );

		var summary = cfg.i18n.progressFmt
			.replace( '{processed}', s.processed_posts || 0 )
			.replace( '{total}',     s.total_posts || 0 )
			.replace( '{written}',   s.written_posts || 0 )
			.replace( '{skipped}',   s.skipped_posts || 0 );
		wrap.appendChild( el( 'p', '', summary ) );

		if ( s.status === 'completed' ) {
			wrap.appendChild( el( 'p', 'notice notice-success inline', cfg.i18n.completed ) );
		}

		if ( s.status === 'failed' && s.last_error ) {
			wrap.appendChild( el( 'p', 'notice notice-error inline', s.last_error ) );
		}

		// WP-Cron stall detection.
		var nowSec  = Math.floor( Date.now() / 1000 );
		var stalled = s.status === 'running' && s.last_batch_at && ( nowSec - s.last_batch_at ) > cfg.staleAfter;
		if ( stalled ) {
			var warn = el( 'div', 'notice notice-warning inline', cfg.i18n.cronStalled );
			wrap.appendChild( warn );
			var trigger = el( 'button', 'button', cfg.i18n.triggerNext );
			trigger.addEventListener( 'click', function () {
				trigger.disabled = true;
				request( 'gml_seo_migration_start', { slug: state.slug } ).then( function () {
					trigger.disabled = false;
					pollOnce();
				} );
			} );
			wrap.appendChild( trigger );
		}

		return wrap;
	}

	function startPolling() {
		stopPolling();
		state.pollTimer = setInterval( pollOnce, cfg.pollInterval || 5000 );
	}

	function stopPolling() {
		if ( state.pollTimer ) {
			clearInterval( state.pollTimer );
			state.pollTimer = null;
		}
	}

	function pollOnce() {
		request( 'gml_seo_migration_status', {} ).then( function ( res ) {
			if ( ! res.success ) return;
			state.serverState = res.data;
			render();
			if ( res.data && ( res.data.status === 'completed' || res.data.status === 'failed' ) ) {
				stopPolling();
			}
		} );
	}

	// ── HTTP + DOM helpers ─────────────────────────────────────────────

	function request( action, payload ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( payload || {} ).forEach( function ( k ) {
			fd.append( k, payload[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.catch( function () { return { success: false, data: { message: 'Network error' } }; } );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( text !== undefined && text !== null ) node.textContent = String( text );
		return node;
	}

	function truncate( v, n ) {
		if ( v === null || v === undefined ) return '';
		var s = typeof v === 'string' ? v : JSON.stringify( v );
		return s.length > n ? s.slice( 0, n ) + '…' : s;
	}
} )();
