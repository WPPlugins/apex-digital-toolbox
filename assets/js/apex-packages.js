/**
 * Apex Toolbox -> Packages: feedback while an action is running.
 *
 * Installing or updating a package is a download, a checksum and an unzip - slow
 * enough that with no feedback the natural reaction is to click again, and a
 * second install starting on top of the first is a genuinely bad outcome. So the
 * submitted button goes busy and every other action button is disabled until the
 * page reloads.
 *
 * `updating-message` is WordPress's own class: it renders the same spinning
 * update icon the Plugins screen uses, so this looks native and needs no CSS.
 *
 * Progressive enhancement only. With JavaScript off, every form still submits and
 * still works - it just does so without the spinner.
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.apex-packages' );

		if ( ! wrap ) {
			return;
		}

		var buttons = Array.prototype.slice.call( wrap.querySelectorAll( 'form button' ) );

		wrap.addEventListener( 'submit', function ( event ) {
			var form = event.target;

			if ( ! form || form.tagName !== 'FORM' ) {
				return;
			}

			var pressed = form.querySelector( 'button' );

			buttons.forEach( function ( button ) {
				if ( button === pressed ) {
					return;
				}

				// Not `disabled`: a disabled button in a form that is ALSO being
				// submitted would drop its value. These are other forms, so plain
				// disabling is safe and stops a second action being started.
				button.disabled = true;
				button.classList.add( 'disabled' );
			} );

			if ( ! pressed ) {
				return;
			}

			var busy = pressed.getAttribute( 'data-apex-busy' );

			if ( busy ) {
				pressed.textContent = busy;
			}

			pressed.classList.add( 'updating-message' );

			// Deliberately NOT disabled. The action and slug travel in hidden
			// inputs so nothing is lost, but a disabled button cannot be
			// re-enabled if the request is cancelled or the page is restored from
			// the back/forward cache - which would leave the screen inert.
			pressed.setAttribute( 'aria-disabled', 'true' );
		} );

		// Restoring from the back/forward cache replays the DOM as it was left,
		// spinner and all. Put it back so the screen is usable.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( ! event.persisted ) {
				return;
			}

			buttons.forEach( function ( button ) {
				button.disabled = false;
				button.classList.remove( 'disabled', 'updating-message' );
				button.removeAttribute( 'aria-disabled' );
			} );
		} );
	} );
})();
