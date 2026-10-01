/**
 * Das Leistungsfeld der Seite „Bestenliste" wechselt mit der Distanz
 * (Plan 7.2, nachgezogen mit M6).
 *
 * ⚠ Warum das erst jetzt kommt: ohne JavaScript ist der Distanzwechsel ein
 * Formular-Roundtrip über „Prüfen". Die getippte Leistung dabei wegzuwerfen
 * hiesse, dass ein Vertipper in der Distanz die getippte Zeit kostet –
 * deshalb lehnt die Serverseite eine unpassende Eingabe ab, statt sie still
 * zu verwerfen. Mit JavaScript passiert der Wechsel ohne Reload, die Eingabe
 * steht noch im Feld, und dann ist Leeren richtig.
 *
 * ⚠ Geleert wird beim Wechsel des Feld-TYPS, nicht bei jedem Wechsel der
 * Distanz. `01:36:44` bleibt gültig, wenn aus Halbmarathon Marathon wird;
 * unter „Strecke" ist derselbe Wert Unsinn und muss weg.
 *
 * ⚠ Was das Feld verlangt, entscheidet weiterhin PHP: die Tabelle kommt aus
 * lsg_bl_leistung_feld() über wp_localize_script(). Und sie ist die
 * Vorprüfung, nicht die Prüfung – geschrieben wird nur, was
 * lsg_bl_leistung_lesen() serverseitig durchlässt.
 */
( function () {
	'use strict';

	var cfg = window.lsgBestConfig;
	if ( ! cfg || ! cfg.felder ) {
		return;
	}

	var wrap    = document.getElementById( 'lsg-bl-best' );
	var distanz = document.getElementById( 'lsg-bl-distanz' );
	var eingabe = document.getElementById( 'lsg-bl-leistung' );

	if ( ! wrap || ! distanz || ! eingabe ) {
		return;
	}

	wrap.classList.add( 'lsg-bl-js' );

	var beschriftung = document.querySelector( 'label[for="lsg-bl-leistung"]' );
	var hinweis      = document.getElementById( 'lsg-bl-leistung-hinweis' );
	var typAlt       = ( cfg.felder[ distanz.value ] || {} ).typ || '';

	distanz.addEventListener( 'change', function () {
		var feld = cfg.felder[ distanz.value ];
		if ( ! feld ) {
			return;
		}

		if ( beschriftung ) {
			beschriftung.textContent = feld.label;
		}
		eingabe.placeholder = feld.platzhalter;
		eingabe.pattern     = feld.pattern;
		if ( hinweis ) {
			hinweis.textContent = feld.hinweis;
		}

		if ( feld.typ !== typAlt ) {
			eingabe.value = '';
		}
		typAlt = feld.typ;
	} );
} )();
