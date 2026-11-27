/**
 * Apex Toolbox settings page: left vertical tab menu.
 *
 * Progressive enhancement - all panels stay in the DOM (so every field still submits with the one
 * settings form); this just shows one section at a time. Without JS every panel stays visible.
 * The active tab is remembered (URL hash > localStorage > first tab) so a save returns you to it.
 */
( function () {
	'use strict';

	var wrap = document.querySelector( '.apex-settings-tabs' );
	if ( ! wrap ) {
		return;
	}

	// Signals the CSS to start hiding inactive panels (kept in a class so no-JS shows everything).
	wrap.classList.add( 'apex-has-js' );

	var tabs = [].slice.call( wrap.querySelectorAll( '.apex-settings-tab' ) );
	var panels = [].slice.call( wrap.querySelectorAll( '.apex-settings-panel' ) );

	function activate( id, updateHash ) {
		if ( ! id || ! document.getElementById( id ) ) {
			return;
		}
		tabs.forEach( function ( tab ) {
			var isActive = tab.getAttribute( 'data-apex-tab' ) === id;
			tab.classList.toggle( 'active', isActive );
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );
		panels.forEach( function ( panel ) {
			panel.classList.toggle( 'active', panel.id === id );
		} );
		try {
			window.localStorage.setItem( 'apexSettingsTab', id );
		} catch ( e ) {}
		// Keep the URL hash in sync so deep links and back/forward reflect the tab. replaceState
		// (not location.hash =) avoids the jump-to-anchor scroll.
		if ( updateHash && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + id );
		}
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			activate( tab.getAttribute( 'data-apex-tab' ), true );
		} );
	} );

	// Restore the active tab: URL hash first, then the last one used, then the first tab.
	var initial = '';
	if ( window.location.hash ) {
		var hash = window.location.hash.slice( 1 );
		if ( document.getElementById( hash ) ) {
			initial = hash;
		}
	}
	if ( ! initial ) {
		try {
			initial = window.localStorage.getItem( 'apexSettingsTab' ) || '';
		} catch ( e ) {}
	}
	if ( ! initial || ! document.getElementById( initial ) ) {
		initial = panels.length ? panels[ 0 ].id : '';
	}
	activate( initial );
} )();
