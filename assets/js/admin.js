/* global idea89Admin */
( function () {
	'use strict';

	function post( action, button, busyLabel, idleLabel ) {
		var result = document.getElementById( 'idea89-action-result' );
		var original = button.textContent;

		button.disabled = true;
		button.textContent = busyLabel;
		result.textContent = '';
		result.className = '';

		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', idea89Admin.nonce );

		fetch( idea89Admin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				var ok = json && json.success;
				var message = json && json.data && json.data.message ? json.data.message : idea89Admin.failed;
				result.className = ok ? 'notice notice-success' : 'notice notice-error';
				// textContent only: this string comes back from the server and
				// must never be interpreted as markup.
				result.textContent = '';
				var p = document.createElement( 'p' );
				p.textContent = message;
				result.appendChild( p );
			} )
			.catch( function () {
				result.className = 'notice notice-error';
				result.textContent = '';
				var p = document.createElement( 'p' );
				p.textContent = idea89Admin.failed;
				result.appendChild( p );
			} )
			.then( function () {
				button.disabled = false;
				button.textContent = idleLabel || original;
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var testBtn = document.getElementById( 'idea89-test-connection' );
		var syncBtn = document.getElementById( 'idea89-sync-now' );

		if ( testBtn ) {
			testBtn.addEventListener( 'click', function () {
				post( 'idea89_test_connection', testBtn, idea89Admin.testing, idea89Admin.testLabel );
			} );
		}
		if ( syncBtn ) {
			syncBtn.addEventListener( 'click', function () {
				post( 'idea89_sync_now', syncBtn, idea89Admin.syncing, idea89Admin.syncLabel );
			} );
		}
	} );
}() );
