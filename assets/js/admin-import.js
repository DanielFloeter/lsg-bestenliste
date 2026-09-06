/**
 * Der Ergebnis-Import ohne Reload (Plan 6.9, 6.11, M6).
 *
 * ⚠ Eine Zugabe, keine Voraussetzung. Die Seite ist ein vollständiges
 * Formular, das über admin-post.php funktioniert; dieses Skript fängt die
 * Knöpfe ab und holt stattdessen dieselben Schritte über die REST-Routen.
 * Bleibt es weg, ändert sich nichts ausser der Anzahl der Seitenaufbauten.
 *
 * ⚠ Gerendert wird hier nichts. Die Antworten tragen die fertigen Fragmente
 * (`html`), und das Skript hängt sie in ihre Behälter. Die Vorschautabelle
 * ein zweites Mal in JavaScript zu bauen hiesse, sie zweimal zu pflegen –
 * dieselbe Regel wie bei der Logik, wo Formular und REST dieselbe Funktion
 * rufen.
 *
 * Rein clientseitig ist genau das, was keinen Server braucht: die
 * Kopf-Checkbox, das mitzählende Knopf-Label und der Statusfilter. Der Filter
 * bleibt hier, weil er sonst die gesetzten Haken kostet – ohne JavaScript
 * lädt er die Seite neu und setzt die Auswahl zurück, und genau das ist der
 * Satz, der dann neben ihm steht.
 */
( function () {
	'use strict';

	var cfg = window.lsgImportConfig;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var wrap = document.getElementById( 'lsg-bl-import' );
	if ( ! wrap ) {
		return;
	}

	// Sagt dem Stylesheet, dass die Hinweise und Knöpfe für den Weg ohne
	// JavaScript nicht mehr gebraucht werden.
	wrap.classList.add( 'lsg-bl-js' );

	var texte  = cfg.texte || {};
	var filter = '';   // Statusfilter, rein clientseitig

	/*
	 * Zwei Arten von „läuft gerade", weil sie zwei verschiedene Antworten
	 * verdienen.
	 *
	 * Die Leseschritte (erkennen, Auswahl nachziehen) lösen einander ab: wer
	 * dreimal schnell den Wettbewerb wechselt, will das Ergebnis des dritten
	 * Wechsels sehen, nicht das des ersten, das zufällig zuletzt eintrifft.
	 * Der laufende Request wird deshalb abgebrochen, nicht abgewartet.
	 *
	 * ⚠ Parsen und Übernehmen werden NICHT abgebrochen. Ein abgebrochener
	 * fetch() hält den Server nicht an – beim Übernehmen liefe der
	 * Schreibvorgang weiter, und die Seite wüsste nichts davon. Solange einer
	 * von beiden läuft, passiert nichts anderes.
	 */
	var abbruch      = null;
	var beschaeftigt = false;

	// Entpreller fuer das Adressfeld: „erkennen“ soll auch beim Tippen
	// laufen, nicht erst beim Verlassen des Feldes – aber nicht bei jedem
	// Tastendruck einzeln, sonst ginge ein Request an die fremde Quelle raus,
	// waehrend die Adresse noch unvollstaendig ist.
	var urlErkennenTimer = null;

	// Welchen Wert „erkennen()“ zuletzt tatsächlich abgefragt hat. Ohne das
	// feuert der native „change“ eines Textfelds beim Verlassen ein zweites
	// Mal für denselben Wert, den der Entpreller Sekundenbruchteile vorher
	// schon abgefragt hatte – und die Antwort tauscht dann die
	// Wettbewerbs-Auswahl aus, ausgerechnet während sie angeklickt wird.
	// Genau das war der Grund, weshalb man sie bisher zweimal anklicken
	// musste.
	var urlLetzteAnfrage = null;

	/* ---------------------------------------------------------------------
	 * Werte und Adresse
	 * ------------------------------------------------------------------ */

	function feld( name ) {
		var el = document.querySelector( '#lsg-bl-form [name="' + name + '"]' );
		return el ? el.value : '';
	}

	/**
	 * Die Werte des Formulars.
	 *
	 * ⚠ Ein Feld, das es auf der Seite nicht gibt, wird WEGGELASSEN und nicht
	 * als leerer String geschickt. Die Serverseite unterscheidet beides: „nicht
	 * angefasst" wird vorbelegt, „leer" bleibt leer. Ein mitgeschicktes
	 * `list: ''` löschte genau die Vorauswahl, die die Adresse mitgebracht hat
	 * – bei runtix steht die Liste im Pfad (`/3152/21/total`), und das Feld
	 * dazu wird gar nicht erst angezeigt, wenn es nichts zu wählen gibt.
	 */
	function werte() {
		var out = {};
		[ 'url', 'adapter', 'contest', 'list', 'distanz', 'datum', 'ort', 'token' ].forEach( function ( name ) {
			var el = document.querySelector( '#lsg-bl-form [name="' + name + '"]' );
			if ( el ) {
				out[ name ] = el.value;
			}
		} );
		return out;
	}

	/**
	 * Den Seitenzustand in die Adresszeile schreiben.
	 *
	 * Der Assistent hält seinen Stand in der Query (Plan 6.9) – ohne das hier
	 * wäre ein Reload nach drei Schritten ohne Reload wieder der erste
	 * Schritt, und Browser-Zurück führte ins Leere.
	 */
	function adresseNachfuehren( w ) {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var url = new URL( window.location.href );
		[ 'url', 'adapter', 'contest', 'list', 'distanz', 'datum', 'ort', 'token' ].forEach( function ( k ) {
			if ( w[ k ] ) {
				url.searchParams.set( k, w[ k ] );
			} else {
				url.searchParams.delete( k );
			}
		} );
		// Der Filter ist mit JavaScript eine Sache der Tabelle, nicht der
		// Adresse – und die Aktionen gehören zu genau einem Klick.
		[ 'filter', 'aktion', 'verein', '_wpnonce' ].forEach( function ( k ) {
			url.searchParams.delete( k );
		} );
		window.history.replaceState( {}, '', url.toString() );
	}

	/* ---------------------------------------------------------------------
	 * Zustände (Plan 6.11)
	 * ------------------------------------------------------------------ */

	function zustandSetzen( key ) {
		var el = document.getElementById( 'lsg-bl-zustand' );
		if ( ! el ) {
			return;
		}
		var punkt = document.createElement( 'span' );
		punkt.className = 'lsg-bl-zustand-punkt lsg-bl-zustand-' + key;

		el.setAttribute( 'data-zustand', key );
		el.textContent = '';
		el.appendChild( punkt );
		el.appendChild( document.createTextNode( ( cfg.zustaende && cfg.zustaende[ key ] ) || '' ) );
	}

	function spinner( id, an ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.classList.toggle( 'is-active', !! an );
		}
	}

	/**
	 * Ein abgebrochener Request ist kein Fehler: er wurde abgelöst, und die
	 * Antwort, die zählt, ist schon unterwegs.
	 */
	function melden( e ) {
		if ( e && 'AbortError' === e.name ) {
			return;
		}
		fehlerZeigen( e ? e.message : ( texte.netzfehler || '' ) );
	}

	function fehlerZeigen( text ) {
		var behaelter = document.getElementById( 'lsg-bl-notices' );
		if ( behaelter ) {
			var box = document.createElement( 'div' );
			// `inline` aus demselben Grund wie serverseitig: sonst wandert die
			// Meldung aus dem Behälter und bleibt beim nächsten Austausch stehen.
			box.className = 'notice notice-error inline';
			var p = document.createElement( 'p' );
			// textContent, nicht innerHTML: die Meldung kann von der fremden
			// Quelle stammen.
			p.textContent = text;
			box.appendChild( p );
			behaelter.textContent = '';
			behaelter.appendChild( box );
		}
		zustandSetzen( 'fehler' );
	}

	/* ---------------------------------------------------------------------
	 * Anfragen
	 * ------------------------------------------------------------------ */

	function anfrage( route, methode, daten, abbrechbar ) {
		var adresse = cfg.restUrl + route;
		var optionen = {
			method: methode,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		};

		if ( abbrechbar && 'undefined' !== typeof AbortController ) {
			if ( abbruch ) {
				abbruch.abort();
			}
			abbruch = new AbortController();
			optionen.signal = abbruch.signal;
		}

		if ( 'GET' === methode ) {
			var params = new URLSearchParams();
			Object.keys( daten ).forEach( function ( k ) {
				params.set( k, daten[ k ] );
			} );
			adresse += '?' + params.toString();
		} else {
			optionen.headers['Content-Type'] = 'application/json';
			optionen.body = JSON.stringify( daten );
		}

		return fetch( adresse, optionen ).then( function ( antwort ) {
			return antwort.json().then( function ( inhalt ) {
				if ( ! antwort.ok ) {
					throw new Error( ( inhalt && inhalt.message ) || texte.netzfehler || 'Fehler' );
				}
				return inhalt;
			} );
		} );
	}

	/**
	 * Die Beschriftung der Option „automatisch“ in der Portalwahl.
	 *
	 * Der Schritt „erkennen“ läuft bei jeder Änderung von Adresse ODER
	 * Portalwahl (siehe der change-Handler unten). Was er über die Adresse
	 * herausfindet, gehört sichtbar zur Auswahl selbst – sonst steht dort
	 * dauerhaft nur „automatisch“, während zwei Zeilen tiefer längst
	 * „Erkannt: race result – …“ steht.
	 *
	 * ⚠ Verändert wird NUR die Beschriftung der Option, nicht die Auswahl
	 * selbst. Eine von Hand gewählte Portalüberschreibung bleibt stehen –
	 * dieselbe Regel wie beim vollen Seitenaufbau (Plan 6.9,
	 * lsg_bl_import_schritt1()).
	 *
	 * @param {string} label Portalname aus der Antwort, leer wenn nichts
	 *                        erkannt wurde.
	 */
	function adapterBeschriftungAktualisieren( label ) {
		var option = document.querySelector( '#lsg-bl-adapter option[value="auto"]' );
		if ( ! option ) {
			return;
		}
		option.textContent = label
			? ( texte.automatischErkannt || 'automatisch (erkannt: %s)' ).replace( '%s', label )
			: ( texte.automatisch || 'automatisch' );
	}

	/**
	 * Ein Element durch das Fragment aus der Antwort ersetzen.
	 */
	function ersetze( id, html ) {
		var el = document.getElementById( id );
		if ( ! el || 'string' !== typeof html ) {
			return;
		}
		var huelle = document.createElement( 'div' );
		huelle.innerHTML = html;
		var neu = huelle.firstElementChild;
		if ( neu ) {
			el.parentNode.replaceChild( neu, el );
		} else {
			el.textContent = '';
		}
	}

	function anwenden( data ) {
		if ( ! data || ! data.html ) {
			return;
		}
		ersetze( 'lsg-bl-notices', data.html.notices );
		ersetze( 'lsg-bl-zustand', data.html.zustand );
		ersetze( 'lsg-bl-erkannt', data.html.erkannt );
		ersetze( 'lsg-bl-auswahl', data.html.auswahl );
		ersetze( 'lsg-bl-vorschau', data.html.vorschau );

		// `label` steht nur in der Antwort von „erkennen“ (immer, auch als
		// leerer String bei keinem Treffer) – die anderen drei Schritte
		// lassen die Feldbeschriftung deshalb unangetastet.
		if ( 'undefined' !== typeof data.label ) {
			adapterBeschriftungAktualisieren( data.label );
		}

		if ( data.werte ) {
			adresseNachfuehren( data.werte );
		}
		tabelleAufwerten();
	}

	/**
	 * @param {string}      zustand   Schlüssel aus 6.11.
	 * @param {string}      spinnerId Element, das den Spinner trägt.
	 * @param {Element|null} knopf    Wird für die Dauer gesperrt.
	 * @param {boolean}     sperren   true bei Parsen und Übernehmen: solange
	 *                                die laufen, passiert nichts anderes.
	 */
	function starten( zustand, spinnerId, knopf, sperren ) {
		if ( sperren ) {
			beschaeftigt = true;
		}
		zustandSetzen( zustand );
		spinner( spinnerId, true );
		if ( knopf ) {
			knopf.disabled = true;
		}
	}

	function beenden( spinnerId, knopf ) {
		beschaeftigt = false;
		spinner( spinnerId, false );
		if ( knopf && document.contains( knopf ) ) {
			knopf.disabled = false;
		}
	}

	/* ---------------------------------------------------------------------
	 * Die vier Schritte
	 * ------------------------------------------------------------------ */

	function erkennen() {
		var w = werte();
		if ( beschaeftigt || ! w.url ) {
			return;
		}
		urlLetzteAnfrage = w.url;
		var knopf = document.getElementById( 'lsg-bl-pruefen' );
		starten( 'erkenne', 'lsg-bl-spinner-pruefen', knopf, false );

		anfrage( 'erkennen', 'POST', { url: w.url, adapter: w.adapter }, true )
			.then( anwenden )
			.catch( melden )
			.finally( function () {
				beenden( 'lsg-bl-spinner-pruefen', document.getElementById( 'lsg-bl-pruefen' ) );
			} );
	}

	/**
	 * Auswahl nachziehen: Listen, Vorbelegungen, Plausibilitätshinweise und
	 * die Sperre des Parsen-Knopfes.
	 *
	 * Das ist derselbe Weg, den ohne JavaScript der Knopf „Auswahl übernehmen"
	 * geht. Ein Abruf bei der Quelle entsteht nicht – die Discovery liegt im
	 * Transient (Plan 5.2).
	 */
	function auswahlNachziehen() {
		var w = werte();
		if ( beschaeftigt || ! w.url ) {
			return;
		}

		anfrage( 'listen', 'GET', w, true ).then( anwenden ).catch( melden );
	}

	function parsen() {
		var w = werte();
		if ( beschaeftigt ) {
			return;
		}
		var knopf = document.getElementById( 'lsg-bl-parsen' );
		starten( 'parse', 'lsg-bl-spinner-parsen', knopf, true );

		anfrage( 'parsen', 'POST', w )
			.then( function ( data ) {
				filter = '';
				anwenden( data );
			} )
			.catch( melden )
			.finally( function () {
				beenden( 'lsg-bl-spinner-parsen', document.getElementById( 'lsg-bl-parsen' ) );
			} );
	}

	function uebernehmen( formular ) {
		if ( beschaeftigt ) {
			return;
		}
		var w = werte();

		w.token  = formular.querySelector( '[name="token"]' ).value;
		w.zeilen = gewaehlt( formular );

		var knopf = document.getElementById( 'lsg-bl-uebernehmen' );
		starten( 'uebernahme', 'lsg-bl-spinner-uebernehmen', knopf, true );

		anfrage( 'uebernehmen', 'POST', w )
			.then( anwenden )
			.catch( melden )
			.finally( function () {
				beenden( 'lsg-bl-spinner-uebernehmen', document.getElementById( 'lsg-bl-uebernehmen' ) );
			} );
	}

	/* ---------------------------------------------------------------------
	 * Die Tabelle: Kopf-Checkbox, Zähler, Filter
	 * ------------------------------------------------------------------ */

	function tabelle() {
		return document.getElementById( 'lsg-bl-tabelle' );
	}

	function kaestchen() {
		var t = tabelle();
		return t ? t.querySelectorAll( 'tbody input[type="checkbox"][name="zeilen[]"]' ) : [];
	}

	/**
	 * Welche Zeilen gingen bei einem Abschicken hinaus?
	 *
	 * ⚠ Es gehen Zeilen-INDIZES hinaus, keine Daten. Und es zählen nicht nur
	 * die Haken: hat der Server gefiltert, stehen die vorausgewählten Zeilen
	 * der anderen Status als Hidden-Feld im Formular, damit ein Filter die
	 * Vorauswahl nicht heimlich abwählt. Wer hier nur `:checked` einsammelt,
	 * verliert sie – und zwar lautlos.
	 */
	function gewaehlt( wurzel ) {
		var out = [];
		( wurzel || document ).querySelectorAll( 'input[name="zeilen[]"]' ).forEach( function ( el ) {
			if ( 'hidden' === el.type || el.checked ) {
				out.push( parseInt( el.value, 10 ) );
			}
		} );
		return out;
	}

	/**
	 * Die Kopf-Checkbox nachrüsten (Plan 6.6).
	 *
	 * Sie steht bewusst nicht im PHP: ohne JavaScript hätte sie keine Wirkung,
	 * und ein Bedienelement, das nichts tut, ist schlimmer als keines.
	 */
	function kopfKaestchen() {
		var t = tabelle();
		if ( ! t ) {
			return;
		}
		var zelle = t.querySelector( 'thead td.check-column' );
		if ( ! zelle || zelle.querySelector( 'input' ) ) {
			return;
		}

		var label = document.createElement( 'label' );
		label.className = 'screen-reader-text';
		label.setAttribute( 'for', 'cb-select-all-1' );
		label.textContent = texte.alleWaehlen || 'Alle auswählen';

		var box = document.createElement( 'input' );
		box.type = 'checkbox';
		box.id   = 'cb-select-all-1';

		box.addEventListener( 'change', function () {
			// Nur die sichtbaren Zeilen: was ein Filter gerade versteckt, hat
			// niemand vor Augen und soll sich nicht mit umschalten.
			kaestchen().forEach( function ( el ) {
				var zeile = el.closest( 'tr' );
				if ( zeile && ! zeile.classList.contains( 'hidden' ) ) {
					el.checked = box.checked;
				}
			} );
			versteckteHakenZurueck();
			zaehlerNachfuehren();
		} );

		zelle.appendChild( label );
		zelle.appendChild( box );
	}

	function zaehlerNachfuehren() {
		var knopf = document.getElementById( 'lsg-bl-uebernehmen' );
		if ( ! knopf ) {
			return;
		}
		var formular = knopf.closest( 'form' );
		var anzahl   = gewaehlt( formular ).length;

		var muster = ( 1 === anzahl ) ? texte.uebernehmen1 : texte.uebernehmenN;
		if ( muster ) {
			knopf.textContent = muster.replace( '%d', String( anzahl ) );
		}
		knopf.disabled = ( 0 === anzahl );
	}

	/**
	 * Die Haken versteckter Zeilen zurückholen.
	 *
	 * ⚠ Der Grund ist WordPress selbst. `wp-admin/js/common.js` hängt an jeder
	 * Kopf-Checkbox einer `wp-list-table` einen eigenen Klick-Handler, und der
	 * setzt jede Checkbox, die gerade `:hidden` ist, auf **false** – gedacht
	 * für ausgeblendete Spalten, hier aber genau die Zeilen, die der
	 * Statusfilter versteckt. Ohne diese Rücknahme kostete ein „Alle"-Klick
	 * im Filter still die Vorauswahl der anderen Status: die Zeilen stünden
	 * unverändert in der Tabelle, wären aber nicht mehr angehakt.
	 *
	 * Der Handler von WordPress hängt am `click`, dieser hier am `change` –
	 * `click` kommt zuerst, wir kommen also hinterher und können das
	 * Gemerkte zurückschreiben.
	 *
	 * @return void
	 */
	function versteckteHakenZurueck() {
		kaestchen().forEach( function ( el ) {
			var zeile = el.closest( 'tr' );
			if ( zeile && zeile.classList.contains( 'hidden' ) && undefined !== el.dataset.haken ) {
				el.checked = ( '1' === el.dataset.haken );
			}
		} );
	}

	function filterAnwenden() {
		var t = tabelle();
		if ( ! t ) {
			return;
		}
		// Nur echte Ergebniszeilen: die Platzhalterzeilen eines serverseitigen
		// Filters tragen keinen Status und dürfen nicht sichtbar werden.
		var sichtbar = 0;
		t.querySelectorAll( 'tbody tr[class*="lsg-bl-status-"]' ).forEach( function ( tr ) {
			var passt = ( '' === filter ) || tr.classList.contains( 'lsg-bl-status-' + filter );
			var box   = tr.querySelector( 'input[type="checkbox"][name="zeilen[]"]' );
			var war   = tr.classList.contains( 'hidden' );

			if ( box ) {
				if ( ! passt && ! war ) {
					// Beim Verstecken merken, was gesetzt war.
					box.dataset.haken = box.checked ? '1' : '0';
				} else if ( passt && war && undefined !== box.dataset.haken ) {
					// Beim Wiederauftauchen zurückholen – dazwischen kann
					// WordPress den Haken entfernt haben.
					box.checked = ( '1' === box.dataset.haken );
					delete box.dataset.haken;
				}
			}

			tr.classList.toggle( 'hidden', ! passt );
			if ( passt ) {
				sichtbar++;
			}
		} );

		var leer = document.getElementById( 'lsg-bl-leer' );
		if ( 0 === sichtbar ) {
			if ( ! leer ) {
				leer = document.createElement( 'p' );
				leer.id = 'lsg-bl-leer';
				leer.textContent = texte.keinTreffer || '';
				t.parentNode.insertBefore( leer, t.nextSibling );
			}
		} else if ( leer ) {
			leer.parentNode.removeChild( leer );
		}

		var liste = document.getElementById( 'lsg-bl-statusfilter' );
		if ( liste ) {
			liste.querySelectorAll( 'a' ).forEach( function ( a ) {
				a.classList.toggle( 'current', filterAusLink( a ) === filter );
			} );
		}
	}

	function filterAusLink( a ) {
		try {
			return new URL( a.href, window.location.origin ).searchParams.get( 'filter' ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function tabelleAufwerten() {
		kopfKaestchen();
		filterAnwenden();
		zaehlerNachfuehren();
	}

	/* ---------------------------------------------------------------------
	 * Verdrahtung
	 *
	 * Alles über Delegation am äusseren Behälter: die Behälter darin werden
	 * bei jeder Antwort ausgetauscht, an ihnen hängende Listener wären nach
	 * dem ersten Schritt weg.
	 * ------------------------------------------------------------------ */

	wrap.addEventListener( 'click', function ( e ) {
		var ziel = e.target;

		if ( ziel.closest( '#lsg-bl-pruefen' ) ) {
			e.preventDefault();
			erkennen();
			return;
		}
		if ( ziel.closest( '#lsg-bl-parsen' ) ) {
			e.preventDefault();
			parsen();
			return;
		}

		var link = ziel.closest( '#lsg-bl-statusfilter a' );
		if ( link ) {
			// Hat der Server schon gefiltert, stehen die anderen Zeilen gar
			// nicht im Dokument – dann bleibt es beim Reload.
			var t = tabelle();
			if ( t && '' === t.getAttribute( 'data-serverfilter' ) ) {
				e.preventDefault();
				filter = filterAusLink( link );
				filterAnwenden();
			}
		}
	} );

	// „input“ statt „change“: change feuert bei einem Textfeld erst beim
	// Verlassen, input bei jedem Tastendruck UND beim Einfuegen per
	// Zwischenablage. Nur das Adressfeld braucht das – bei einem <select>
	// wie der Portalwahl ist „change“ bereits der richtige, sofortige
	// Zeitpunkt.
	wrap.addEventListener( 'input', function ( e ) {
		if ( 'url' !== e.target.name ) {
			return;
		}
		if ( urlErkennenTimer ) {
			window.clearTimeout( urlErkennenTimer );
		}
		urlErkennenTimer = window.setTimeout( function () {
			urlErkennenTimer = null;
			erkennen();
		}, 400 );
	} );

	wrap.addEventListener( 'change', function ( e ) {
		var name = e.target.name;

		if ( 'url' === name || 'adapter' === name ) {
			// Verlaesst der Mensch das Feld, bevor der Entpreller abgelaufen
			// ist, soll das nicht noch einmal 400ms warten.
			if ( urlErkennenTimer ) {
				window.clearTimeout( urlErkennenTimer );
				urlErkennenTimer = null;
			}
			// Beim Adressfeld selbst: hat der Entpreller diesen Wert schon
			// abgefragt, während der Mensch noch im Feld stand, ist der
			// „change“ beim Verlassen nur die zweite Anfrage für denselben
			// Wert – überflüssig, und sie käme gerade dann zurück, wenn als
			// nächstes in die Wettbewerbs-Auswahl geklickt wird.
			if ( 'url' === name && werte().url === urlLetzteAnfrage ) {
				return;
			}
			erkennen();
			return;
		}
		if ( 'contest' === name || 'list' === name || 'distanz' === name || 'datum' === name ) {
			auswahlNachziehen();
			return;
		}
		if ( 'zeilen[]' === name ) {
			zaehlerNachfuehren();
		}
	} );

	wrap.addEventListener( 'submit', function ( e ) {
		if ( e.target.classList.contains( 'lsg-bl-uebernahme' ) ) {
			e.preventDefault();
			uebernehmen( e.target );
		}
	} );

	tabelleAufwerten();
} )();
