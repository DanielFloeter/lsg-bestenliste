<?php
/**
 * Die Parse-Pipeline, soweit sie ohne WordPress auskommt.
 *
 * ⚠ Diese Datei ruft keine WordPress-Funktion auf und liest kein $wpdb – wie
 * class-lsg-normalize.php. Alles, was Transients, Optionen oder den Abruf
 * braucht, steht in class-lsg-import.php.
 *
 * Die Pipeline hat vier Stufen mit je einem definierten Zwischenergebnis
 * (Plan 6.5):
 *
 *   P1  Alle Ergebnisse lesen        →  Rohzeilen, normalisiert
 *   P2  Auf LSG Karlsruhe filtern    →  nur Vereinsmitglieder
 *   P3  Athleten zuordnen            →  lsg_athlete.id je Zeile      (M3)
 *   P4  Gegen lsg_best abgleichen    →  Status je Zeile              (M3)
 *
 * P1 steckt im Adapter (der liest und normalisiert), P2 steht hier. P3 und P4
 * brauchen die Datenbank und kommen mit M3.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Der Trichter
 * ---------------------------------------------------------------------- */

/**
 * Das Zählwerk der Oberfläche.
 *
 * `null` heißt „diese Stufe ist noch nicht gelaufen", `0` heißt „gelaufen und
 * nichts gefunden". Der Unterschied ist wichtig: eine Stufe, die es noch
 * nicht gibt, darf nicht wie ein Nulltreffer aussehen.
 *
 * @return array<string,int|null>
 */
function lsg_bl_trichter_leer() {
	return array(
		'gelesen'    => 0,
		'verworfen'  => 0,
		'lsg'        => 0,
		'zugeordnet' => null,   // P3, ab M3
		'offen'      => null,   // P3
		'neu'        => null,   // P4
		'schneller'  => null,   // P4
		'langsamer'  => null,   // P4
		'gleich'     => null,   // P4
	);
}

/**
 * Die Stufen des Trichters als Label/Wert-Paare, in Leserichtung – und nur
 * die, die wirklich gelaufen sind.
 *
 * Das ist die wichtigste Kontrollanzeige der ganzen Seite: springt „LSG" auf
 * 0, stimmt der Vereinsfilter nicht, und man sieht es sofort (Plan 6.5).
 *
 * @param array $trichter Ergebnis von lsg_bl_trichter_leer(), gefüllt.
 * @return array<int,array{key:string,label:string,wert:int}>
 */
function lsg_bl_trichter_stufen( array $trichter ) {
	$labels = array(
		'gelesen'    => 'gelesen',
		'verworfen'  => 'ohne Zeit',
		'lsg'        => 'LSG',
		'zugeordnet' => 'zugeordnet',
		'offen'      => 'ohne Zuordnung',
		'neu'        => 'neu',
		'schneller'  => 'schneller',
		'langsamer'  => 'langsamer',
		'gleich'     => 'gleich',
	);

	$out = array();
	foreach ( $labels as $key => $label ) {
		if ( ! array_key_exists( $key, $trichter ) || null === $trichter[ $key ] ) {
			continue;
		}
		// „ohne Zeit" nur zeigen, wenn es welche gab – sonst ist die Stufe
		// eine Null, die nichts erklärt.
		if ( 'verworfen' === $key && 0 === (int) $trichter[ $key ] ) {
			continue;
		}
		$out[] = array(
			'key'   => $key,
			'label' => $label,
			'wert'  => (int) $trichter[ $key ],
		);
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * P2 – auf LSG Karlsruhe filtern
 * ---------------------------------------------------------------------- */

/**
 * Anzeigemarke für Zeilen ohne Vereinsangabe.
 *
 * Sie steht an einer Stelle, weil zwei Orte sie vergleichen: der Block der
 * nicht übernommenen Vereine zeigt sie, und die Alias-Aktion lehnt sie ab –
 * ein Alias auf „kein Verein" würde jede vereinslose Zeile übernehmen.
 *
 * @return string
 */
function lsg_bl_ohne_verein_marke() {
	return '(kein Verein)';
}

/**
 * Wie nah ist diese Vereinsschreibweise am eigenen Verein?
 *
 * Das steuert die Reihenfolge im Block „nicht übernommene Vereine" und die
 * Markierung an der Zeile. Der Zweck des Blocks ist, eine VERPASSTE
 * Schreibweise zu finden – also muss oben stehen, was danach aussieht, nicht
 * das, was am häufigsten vorkommt.
 *
 *   2  enthält `lsg`        →  `LSG Ka.`, `LSG KA`, `LSG Weiher`
 *   1  enthält `karlsruhe`  →  `Karlsruher Lemminge`, `LG Region Karlsruhe`
 *   0  alles andere
 *
 * ⚠ Ausgenommen ist die Form `(Ort)`: race result setzt bei fehlendem Verein
 * den Wohnort in Klammern. `(Karlsruhe)` ist also keine verpasste
 * Vereinsschreibweise, sondern gar keine – und da es davon viele gibt, würde
 * es sonst das eine `LSG Ka.` nach unten drängen.
 *
 * @param string $verein Rohe Schreibweise aus der Quelle.
 * @return int 0, 1 oder 2.
 */
function lsg_bl_verein_naehe( $verein ) {
	$roh = trim( (string) $verein );
	$n   = lsg_bl_verein_normalisieren( $roh );

	if ( '' === $n ) {
		return 0;
	}
	if ( false !== strpos( $n, 'lsg' ) ) {
		return 2;
	}
	// Nur ein Wohnort in Klammern – kein Verein.
	if ( preg_match( '/^\(\s*[^()]+\s*\)$/', $roh ) ) {
		return 0;
	}
	if ( false !== strpos( $n, 'karlsruhe' ) ) {
		return 1;
	}
	return 0;
}

/**
 * P2: aus den gelesenen Zeilen die des Vereins heraussuchen.
 *
 * Zurück kommen nicht nur die Treffer, sondern auch die ausgefilterten
 * Vereinsschreibweisen mit ihrer Häufigkeit. Das ist die Sicherung gegen
 * stille Fehler: steht dort ein `LSG Ka.` oder `LSG KA`, sieht man den
 * verpassten Treffer sofort, statt ihn nie zu bemerken (Plan 6.5.2).
 *
 * @param LSG_BL_Ergebnis[] $zeilen   Ergebnis von P1.
 * @param string[]          $aliasse  Zusätzlich als LSG geltende, bereits
 *                                    normalisierte Vereinsschreibweisen.
 * @return array{
 *   lsg:LSG_BL_Ergebnis[],
 *   abgelehnt:array<string,int>,
 *   anzahl_abgelehnt:int,
 *   nahe:string[]
 * }
 */
function lsg_bl_p2_filtern( array $zeilen, array $aliasse = array() ) {
	$lsg       = array();
	$abgelehnt = array();
	$nahe      = array();

	foreach ( $zeilen as $e ) {
		$verein = isset( $e->verein ) ? (string) $e->verein : '';

		if ( lsg_bl_ist_lsg( $verein, $aliasse ) ) {
			$lsg[] = $e;
			continue;
		}

		// Zeilen ohne Vereinsangabe fallen durch den Filter. Sie erscheinen
		// trotzdem im Block, damit ein Mitglied, das ohne Verein gemeldet
		// war, nicht unsichtbar verschwindet.
		$anzeige = ( '' === trim( $verein ) ) ? lsg_bl_ohne_verein_marke() : trim( $verein );

		if ( ! isset( $abgelehnt[ $anzeige ] ) ) {
			$abgelehnt[ $anzeige ] = 0;
		}
		++$abgelehnt[ $anzeige ];

		$nahe[ $anzeige ] = lsg_bl_verein_naehe( $verein );
	}

	// Sortierung: erst die Nähe zum Verein, dann die Häufigkeit, dann
	// alphabetisch. So steht ein einzelnes `LSG Ka.` über zwanzig Zeilen eines
	// fremden Vereins – und genau darum geht es in diesem Block.
	uksort(
		$abgelehnt,
		function ( $a, $b ) use ( $abgelehnt, $nahe ) {
			$na = isset( $nahe[ $a ] ) ? $nahe[ $a ] : 0;
			$nb = isset( $nahe[ $b ] ) ? $nahe[ $b ] : 0;
			if ( $na !== $nb ) {
				return $nb - $na;
			}
			if ( $abgelehnt[ $a ] !== $abgelehnt[ $b ] ) {
				return $abgelehnt[ $b ] - $abgelehnt[ $a ];
			}
			return strcasecmp( $a, $b );
		}
	);

	return array(
		'lsg'              => $lsg,
		'abgelehnt'        => $abgelehnt,
		'anzahl_abgelehnt' => array_sum( $abgelehnt ),
		'nahe'             => array_keys( array_filter( $nahe ) ),
	);
}

/* -------------------------------------------------------------------------
 * Distanz, Datum und Ort – die drei Felder über der Tabelle
 * ---------------------------------------------------------------------- */

/**
 * Ist das ein Zeitlauf-Wettbewerb?
 *
 * Zeitläufe sind vom Import ausgenommen: dort hält `lsg_best.time` eine
 * Strecke (`112,737 km`), die Parse-Pipeline erzeugt aber immer eine Zeit.
 * Stünde `6h` im Select, würde eine Zeit in ein Streckenfeld geschrieben und
 * P4 vergliche sie anschließend als Zahl – ein stiller Fehler ohne
 * Fehlermeldung (Plan 6.5.1).
 *
 * Erkannt wird das am Wettbewerbsnamen, damit die Oberfläche den Grund
 * nennen kann, statt nur „keine passende Distanz" zu sagen.
 *
 * @param string $name Wettbewerbsname aus der Quelle.
 * @return bool
 */
function lsg_bl_ist_zeitlauf_name( $name ) {
	$n = lsg_bl_text_normalisieren( $name );
	if ( '' === $n ) {
		return false;
	}
	if ( preg_match( '/\b(6|12|24)\s*(h|std|stunden|stundenlauf)\b/', $n ) ) {
		return true;
	}
	return (bool) preg_match( '/\bstundenlauf\b|\b(sechs|zwoelf|vierundzwanzig)\s*stunden\b/', $n );
}

/**
 * Ort aus dem Veranstaltungsnamen – als Vorbelegung, nicht als Feststellung.
 *
 * Die Heuristik ist absichtlich schmal: das letzte Wort des Eventnamens,
 * wenn es kein Laufwort ist. „17. SWE Halbmarathon Ettlingen" ergibt
 * „Ettlingen"; „19. Hambrücker Lußhardtlauf" ergibt nichts, weil das letzte
 * Wort auf „lauf" endet. Lieber ein leeres Feld als ein Ort, der keiner ist.
 *
 * @param string $event_name Veranstaltungsname.
 * @return string Leer, wenn sich nichts Sinnvolles ableiten lässt.
 */
function lsg_bl_ort_aus_eventname( $event_name ) {
	$name = trim( preg_replace( '/\s+/u', ' ', (string) $event_name ) );
	if ( '' === $name ) {
		return '';
	}

	$worte = explode( ' ', $name );
	$letzt = trim( array_pop( $worte ), " \t\n\r\0\x0B.,;:()[]\"'" );
	if ( '' === $letzt ) {
		return '';
	}

	$n = lsg_bl_text_normalisieren( $letzt );
	if ( '' === $n || strlen( $n ) < 3 ) {
		return '';
	}

	// Laufwörter sind keine Orte.
	if ( preg_match( '/lauf|laeufe|run|race|marathon|walk|cup|trail|meile|staffel|meeting|challenge|nacht|berglauf|crosslauf/', $n ) ) {
		return '';
	}
	// Reine Zahlen und Jahrgangsangaben auch nicht.
	if ( preg_match( '/^\d+$/', $n ) ) {
		return '';
	}

	return $letzt;
}

/**
 * Plausibilitätshinweise zu einem Veranstaltungsdatum.
 *
 * Hinweise, keine Sperren (Plan 6.5.1): der Mensch am Formular weiß Dinge,
 * die die Software nicht weiß.
 *
 * @param string $datum      'JJJJ-MM-TT' oder ''.
 * @param string $event_name Veranstaltungsname, für den Jahresvergleich.
 * @param int    $jetzt_ts   Aktueller Zeitstempel (wird übergeben, damit die
 *                           Funktion ohne WordPress prüfbar bleibt).
 * @return string[] Klartext-Hinweise, leer wenn alles unauffällig ist.
 */
function lsg_bl_datum_hinweise( $datum, $event_name = '', $jetzt_ts = 0 ) {
	$hinweise = array();

	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( (string) $datum ), $m ) ) {
		return $hinweise;
	}
	if ( $jetzt_ts <= 0 ) {
		$jetzt_ts = time();
	}

	$jahr       = (int) $m[1];
	$jetzt_jahr = (int) gmdate( 'Y', $jetzt_ts );

	// Zum Vergleich auf Mittag, damit ein Datum von heute nicht wegen der
	// Uhrzeit als Zukunft gilt.
	$datum_ts = strtotime( $datum . ' 12:00:00 UTC' );

	// 86400 statt DAY_IN_SECONDS: diese Datei soll ohne WordPress ladbar
	// bleiben, und die Konstante gehört WordPress.
	if ( $datum_ts && $datum_ts > $jetzt_ts + 86400 ) {
		$hinweise[] = 'Der Lauf liegt in der Zukunft – stimmt das Datum?';
	}
	if ( $jahr < $jetzt_jahr - 10 ) {
		$hinweise[] = sprintf(
			'Der Lauf liegt mehr als zehn Jahre zurück (%d) – ist das so gewollt?',
			$jahr
		);
	}

	// Weicht das Jahr von der Jahreszahl im Eventnamen ab, werden beide
	// gezeigt – ohne zu entscheiden, welche stimmt.
	$aus_name = lsg_bl_datum_aus_text( $event_name );
	$name_jahr = ( '' !== $aus_name['jahr'] ) ? (int) $aus_name['jahr'] : 0;
	if ( $name_jahr > 0 && $name_jahr !== $jahr ) {
		$hinweise[] = sprintf(
			'Der Veranstaltungsname nennt %1$d, das Datumsfeld %2$d.',
			$name_jahr,
			$jahr
		);
	}

	return $hinweise;
}

/* -------------------------------------------------------------------------
 * Lesehilfen auf den Discovery-Daten
 *
 * Rechnen nur auf dem uebergebenen Array – kein Abruf, kein WordPress.
 * ---------------------------------------------------------------------- */

/**
 * Name eines Wettbewerbs aus der Discovery.
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @return string
 */
function lsg_bl_contest_name( array $disc, $contest_id ) {
	foreach ( $disc['contests'] as $c ) {
		if ( (string) $c['id'] === (string) $contest_id ) {
			return $c['name'];
		}
	}
	return '';
}

/**
 * Listen eines Wettbewerbs aus der Discovery.
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @return array<int,array>
 */
function lsg_bl_contest_listen( array $disc, $contest_id ) {
	$out = array();
	foreach ( $disc['lists'] as $l ) {
		if ( (string) $l['contest'] === (string) $contest_id ) {
			$out[] = $l;
		}
	}
	return $out;
}

/**
 * Eine bestimmte Liste aus der Discovery.
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @param string $list_id    Listen-ID.
 * @return array|null
 */
function lsg_bl_contest_liste( array $disc, $contest_id, $list_id ) {
	$listen = lsg_bl_contest_listen( $disc, $contest_id );
	foreach ( $listen as $l ) {
		if ( (string) $l['id'] === (string) $list_id ) {
			return $l;
		}
	}
	return $listen ? $listen[0] : null;
}

/**
 * Vorbelegung der drei Felder über der Tabelle: Distanz, Datum, Ort.
 *
 * Jedes davon vorbelegt, wenn es sich ermitteln lässt, und in jedem Fall
 * änderbar. Im Zweifel bleibt das Feld leer – ein leeres Feld ist ehrlicher
 * als ein falsch geratenes (Plan 6.5.1).
 *
 * @param array  $disc       Discovery-Daten.
 * @param string $contest_id Contest-Key.
 * @return array{distanz:string,datum:string,datum_quelle:string,datum_hinweis:string,ort:string,zeitlauf:bool,contest_name:string}
 */
function lsg_bl_import_vorbelegung( array $disc, $contest_id ) {
	$contest_name = lsg_bl_contest_name( $disc, $contest_id );

	$distanz = lsg_bl_distanz_aus_name( $contest_name );
	if ( '' !== $distanz && ! in_array( $distanz, lsg_bl_import_distanzen(), true ) ) {
		// Zeitläufe stehen nicht im Select – lieber leer als ein Wert, den
		// das Feld nicht anbietet.
		$distanz = '';
	}

	$datum = isset( $disc['datum'] ) ? (array) $disc['datum'] : array();

	return array(
		'contest_name'  => $contest_name,
		'distanz'       => $distanz,
		'datum'         => isset( $datum['datum'] ) ? (string) $datum['datum'] : '',
		'datum_quelle'  => isset( $datum['quelle'] ) ? (string) $datum['quelle'] : '',
		'datum_hinweis' => isset( $datum['hinweis'] ) ? (string) $datum['hinweis'] : '',
		'ort'           => lsg_bl_ort_aus_eventname( $disc['event_name'] ),
		'zeitlauf'      => lsg_bl_ist_zeitlauf_name( $contest_name ),
	);
}

/* -------------------------------------------------------------------------
 * Gesamtsieg (Plan 6.5.5 – erkennen und markieren, noch nicht schreiben)
 * ---------------------------------------------------------------------- */

/**
 * Ist diese Zeile ein Gesamtsieg?
 *
 * Erkennung: `platz` ist 1. Mehr braucht es nicht – aber nur in der
 * **Gesamtwertung**. In einer nach Geschlecht oder Altersklasse gefilterten
 * Liste ist Platz 1 kein Gesamtsieg, sondern ein Klassensieg. Bei jeder
 * anderen Liste wird deshalb keiner gemeldet: ein falsch gemeldeter Sieg wäre
 * deutlich ärgerlicher als ein übersehener.
 *
 * ⚠ Das Ergebnis hat vorerst keine Wirkung auf die Übernahme. Geschrieben
 * wird nach `lsg_win` noch nicht (Plan 6.5.5, 9.2).
 *
 * @param array $zeile         Ergebniszeile als Array (LSG_BL_Ergebnis::to_array()).
 * @param bool  $gesamtwertung Ist die gewählte Liste die Gesamtwertung?
 * @return bool
 */
function lsg_bl_ist_gesamtsieg( array $zeile, $gesamtwertung ) {
	if ( ! $gesamtwertung ) {
		return false;
	}
	$platz = isset( $zeile['platz'] ) ? trim( (string) $zeile['platz'] ) : '';
	return ( '1' === $platz );
}

/* -------------------------------------------------------------------------
 * Vorschau-Fingerabdruck
 * ---------------------------------------------------------------------- */

/**
 * Fingerabdruck der Werte, von denen die Vorschau abhängt.
 *
 * ⚠ Wird Datum oder Distanz nach dem Parsen geändert, ist die Vorschau
 * ungültig: beide gehen in P4 ein – die Distanz in die Suche nach dem
 * Bestand, das Datum in das Jahr. Die Oberfläche verwirft die Tabelle dann
 * und schaltet zurück auf „Parsen" (Plan 6.5.1).
 *
 * ⚠ Der Ort steht bewusst NICHT drin. Er landet in `lsg_best.town`, geht aber
 * in keinen Vergleich ein – ihn zu ändern darf keine Tabelle wegwerfen.
 *
 * @param array $args adapter, event_id, contest_id, list_id, distanz, datum.
 * @return string
 */
function lsg_bl_import_fingerprint( array $args ) {
	$teile = array();
	foreach ( array( 'adapter', 'event_id', 'contest_id', 'list_id', 'distanz', 'datum' ) as $k ) {
		$teile[] = isset( $args[ $k ] ) ? (string) $args[ $k ] : '';
	}
	return md5( implode( '|', $teile ) );
}

/* -------------------------------------------------------------------------
 * Zustände der Oberfläche
 * ---------------------------------------------------------------------- */

/**
 * Alle Zustände der Import-Seite, mit Klartext für die Anzeige.
 *
 * Ein Zustand, der nicht dargestellt ist, wird später als Bug gemeldet –
 * daher stehen hier alle elf aus Plan 6.11, auch die, die erst mit einem
 * späteren Meilenstein sichtbar werden.
 *
 * @return array<string,string>
 */
function lsg_bl_import_zustaende() {
	return array(
		'leer'        => 'URL eingeben',
		'erkenne'     => 'Adresse wird geprüft …',            // sichtbar erst mit JS (M6)
		'unbekannt'   => 'Kein Adapter für diese Adresse',
		// „erkannt" deckt zwei Lagen ab, die der Plan nicht trennt: Wettbewerb
		// noch nicht gewählt, und Wettbewerb gewählt, aber Distanz oder Datum
		// fehlen noch. In beiden Fällen ist die Auswahl unvollständig und der
		// Parsen-Button gesperrt – deshalb ein Label, das beides trägt.
		'erkannt'     => 'Quelle erkannt – Auswahl vervollständigen',
		'bereit'      => 'Bereit zum Parsen',
		'parse'       => 'Ergebnisliste wird gelesen …',       // M6
		'vorschau'    => 'Vorschau',
		'uebernahme'  => 'Ergebnisse werden übernommen …',     // M3/M6
		'gespeichert' => 'Übernommen',                          // M3
		'teilfehler'  => 'Teilweise übernommen',                // M3
		'fehler'      => 'Fehler',
	);
}

/**
 * Welchen Zustand hat die Seite?
 *
 * Eine Funktion, ein Wahrheitsort – die Oberfläche entscheidet nicht selbst,
 * was sie zeigt.
 *
 * @param array $ctx url, adapter_cls, fehler, discovery, contest_id,
 *                   distanz, datum, vorschau.
 * @return string Schlüssel aus lsg_bl_import_zustaende().
 */
function lsg_bl_import_zustand( array $ctx ) {
	if ( ! empty( $ctx['fehler'] ) ) {
		return 'fehler';
	}
	if ( empty( $ctx['url'] ) ) {
		return 'leer';
	}
	if ( empty( $ctx['adapter_cls'] ) ) {
		return 'unbekannt';
	}
	if ( ! empty( $ctx['vorschau'] ) ) {
		return 'vorschau';
	}
	if ( empty( $ctx['contest_id'] ) ) {
		return 'erkannt';
	}
	if ( empty( $ctx['distanz'] ) || empty( $ctx['datum'] ) ) {
		return 'erkannt';
	}
	return 'bereit';
}

/**
 * Was fehlt noch, damit „Parsen" freigegeben wird?
 *
 * Der Parsen-Button bleibt gesperrt, bis Distanz UND vollständiges Datum
 * stehen – mit einer Meldung, die sagt, welcher der beiden Werte fehlt
 * (Plan 6.5.1).
 *
 * @param string $distanz Gewählter Distanzcode.
 * @param string $datum   'JJJJ-MM-TT' oder ''.
 * @return string Klartext, oder '' wenn beides steht.
 */
function lsg_bl_import_was_fehlt( $distanz, $datum ) {
	$fehlt = array();

	if ( '' === trim( (string) $distanz ) ) {
		$fehlt[] = 'die Distanz';
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( (string) $datum ) ) ) {
		$fehlt[] = 'ein vollständiges Veranstaltungsdatum';
	}

	if ( ! $fehlt ) {
		return '';
	}

	return 'Vor dem Parsen fehlt noch ' . implode( ' und ', $fehlt ) . '.';
}

/**
 * Klartext zur Herkunft eines Datums, wie er am Feld steht (Plan 6.5.1).
 *
 * @param string $quelle liste|ausschreibung|api|name|jahr|manuell|''
 * @return string
 */
function lsg_bl_datum_quelle_label( $quelle ) {
	$map = array(
		'liste'         => 'aus der Veranstaltungsübersicht',
		'ausschreibung' => 'aus der Ausschreibung gelesen',
		'api'           => 'aus der Quelle übernommen',
		'name'          => 'aus dem Namen gelesen',
		'jahr'          => 'nur das Jahr erkannt – Tag und Monat ergänzen',
		'manuell'       => '',
	);
	return isset( $map[ $quelle ] ) ? $map[ $quelle ] : '';
}
