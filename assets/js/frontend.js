/**
 * Frontend-Interaktivität für die LSG-Bestenliste-Blöcke: Filteränderungen
 * lösen einen fetch() gegen die REST-API aus und ersetzen nur den Ergebnis-
 * Container, statt die ganze Seite neu zu laden. Ohne JavaScript funktioniert
 * das gleiche Formular weiterhin per normalem GET-Reload (progressive
 * enhancement) – die Serverseite (render.php) liest dieselben Parameternamen.
 */
( function () {
	'use strict';

	function getConfig() {
		return window.lsgBestenlisteConfig || { restUrl: '/wp-json/lsg/v1/' };
	}

	function setLoading( container, isLoading ) {
		if ( isLoading ) {
			container.classList.add( 'lsg-loading' );
		} else {
			container.classList.remove( 'lsg-loading' );
		}
	}

	function updateResults( form ) {
		var endpoint = form.getAttribute( 'data-lsg-endpoint' );
		var targetSelector = form.getAttribute( 'data-lsg-target' );
		if ( ! endpoint || ! targetSelector ) {
			return;
		}
		var target = document.querySelector( targetSelector );
		if ( ! target ) {
			return;
		}

		var params = new URLSearchParams();
		var elements = form.querySelectorAll( 'select, input' );
		elements.forEach( function ( el ) {
			if ( el.name ) {
				params.set( el.name, el.value );
			}
		} );

		var url = getConfig().restUrl.replace( /\/$/, '' ) + '/' + endpoint + '?' + params.toString();

		setLoading( target, true );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Request failed' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( typeof data.html === 'string' ) {
					target.innerHTML = data.html;
				}
				if ( data.title ) {
					var block = form.closest( '.lsg-block' );
					var titleEl = block ? block.querySelector( '.lsg-title' ) : null;
					if ( titleEl ) {
						titleEl.textContent = data.title;
					}
				}
			} )
			.catch( function () {
				// Bei einem Fehler bleibt der vorherige Inhalt sichtbar; ein
				// erneuter Versuch per normalem Formular-Submit ist weiterhin möglich.
			} )
			.finally( function () {
				setLoading( target, false );
			} );
	}

	function init() {
		var forms = document.querySelectorAll( '.lsg-filters[data-lsg-endpoint]' );
		forms.forEach( function ( form ) {
			form.addEventListener( 'change', function () {
				updateResults( form );
			} );
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				updateResults( form );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
