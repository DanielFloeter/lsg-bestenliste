<?php
/**
 * Normalisierung – die Übersetzungsschicht zwischen dem, was die Quellen
 * schreiben, und dem, was die Datenbank kennt.
 *
 * ⚠ Diese Datei kommt bewusst OHNE WordPress aus. Sie ruft keine
 * WordPress-Funktion auf und liest kein $wpdb. Nur so lassen sich die
 * Adapter und die Parse-Pipeline gegen eine Fixture prüfen, ohne WordPress
 * und ohne Netz (Plan, Abschnitt 5 und Abschnitt 8/Verifikation).
 *
 * Alles, was wp_timezone(), $wpdb oder Optionen braucht, gehört nach
 * class-lsg-helpers.php bzw. class-lsg-db.php – nicht hierher.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * URL
 * ---------------------------------------------------------------------- */

/**
 * wp_parse_url(), solange WordPress da ist – sonst parse_url().
 *
 * Grund für den Umweg: die Adapter-Erkennung (Plan 6.3) gehört in die
 * Unit-Lage der Tests, und die läuft ohne WordPress.
 *
 * @param string $url Zu zerlegende URL.
 * @return array Wie parse_url() mit PHP_URL_ALL, leeres Array bei Unsinn.
 */
function lsg_bl_parse_url( $url ) {
	$url = (string) $url;
	if ( function_exists( 'wp_parse_url' ) ) {
		$teile = wp_parse_url( $url );
	} else {
		$teile = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return is_array( $teile ) ? $teile : array();
}

/* -------------------------------------------------------------------------
 * Text
 * ---------------------------------------------------------------------- */

/**
 * Kleinschreiben, multibyte-fest – und sonst nichts.
 *
 * Der Unterschied zu lsg_bl_text_normalisieren(): dort fallen auch Umlaute,
 * Bindestriche und Punkte weg. Hier bleibt „Dr. Pfeiffer" „dr. pfeiffer" und
 * ist damit von „Pfeiffer" unterscheidbar – genau das braucht die exakte
 * Stufe der Athletenzuordnung (Plan 6.5.3).
 *
 * @param string $wert Rohwert.
 * @return string
 */
function lsg_bl_kleinschreiben( $wert ) {
	$wert = trim( (string) $wert );
	if ( function_exists( 'mb_strtolower' ) ) {
		return mb_strtolower( $wert, 'UTF-8' );
	}
	return strtolower( $wert );
}

/**
 * Gemeinsame Textnormalisierung für Vereins- und Namensvergleiche:
 * klein, Umlaute aufgelöst, alles außer a-z0-9 zu einem Leerzeichen.
 *
 * Damit fallen Körner/Koerner, Anna-Maria/Anna Maria, MÜLLER/Müller und
 * LSG-Karlsruhe/LSG Karlsruhe von selbst zusammen.
 *
 * @param string $wert Rohwert aus der Quelle.
 * @return string Normalisierte Fassung, ggf. leer.
 */
function lsg_bl_text_normalisieren( $wert ) {
	$wert = (string) $wert;
	if ( function_exists( 'mb_strtolower' ) ) {
		$wert = mb_strtolower( $wert, 'UTF-8' );
	} else {
		$wert = strtolower( $wert );
	}

	$wert = strtr(
		$wert,
		array(
			'ä' => 'ae',
			'ö' => 'oe',
			'ü' => 'ue',
			'ß' => 'ss',
			'à' => 'a',
			'á' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'å' => 'a',
			'æ' => 'ae',
			'ç' => 'c',
			'è' => 'e',
			'é' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'ì' => 'i',
			'í' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'ñ' => 'n',
			'ò' => 'o',
			'ó' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ø' => 'o',
			'ù' => 'u',
			'ú' => 'u',
			'û' => 'u',
			'ý' => 'y',
			'ÿ' => 'y',
			'š' => 's',
			'ž' => 'z',
			'č' => 'c',
			'ć' => 'c',
			'ł' => 'l',
			'ń' => 'n',
			'ř' => 'r',
			'ś' => 's',
			'ź' => 'z',
			'ż' => 'z',
		)
	);

	$wert = preg_replace( '/[^a-z0-9]+/', ' ', $wert );

	return trim( preg_replace( '/\s+/', ' ', $wert ) );
}

/**
 * Vereinsname normalisieren. Gleiche Regel wie lsg_bl_text_normalisieren(),
 * eigener Name, weil P2 (Plan 6.5.2) ihn namentlich nennt.
 *
 * @param string $verein Rohwert aus der Quelle.
 * @return string
 */
function lsg_bl_verein_normalisieren( $verein ) {
	return lsg_bl_text_normalisieren( $verein );
}

/**
 * P2: Gehört diese Zeile zur LSG Karlsruhe?
 *
 * Das Vereinsfeld muss `lsg` UND `karlsruhe` enthalten. Die UND-Verknüpfung
 * ist der Zweck der Regel:
 *   trifft     LSG Karlsruhe · LSG-Karlsruhe · lsg karlsruhe e.V. · LSG Karlsruhe/Lemminge
 *   trifft NICHT  LG Region Karlsruhe (lg ≠ lsg) · LSG Weiher (anderer Ort)
 *                 · "(Karlsruhe)" als Wohnort · Karlsruher Lemminge (kein lsg)
 *
 * @param string   $verein  Rohwert aus der Quelle.
 * @param string[] $aliasse Zusätzlich als LSG geltende, bereits normalisierte
 *                          Vereinsschreibweisen (Option lsg_bl_verein_alias).
 * @return bool
 */
function lsg_bl_ist_lsg( $verein, array $aliasse = array() ) {
	$n = lsg_bl_verein_normalisieren( $verein );
	if ( '' === $n ) {
		return false;
	}
	if ( false !== strpos( $n, 'lsg' ) && false !== strpos( $n, 'karlsruhe' ) ) {
		return true;
	}
	return in_array( $n, $aliasse, true );
}

/* -------------------------------------------------------------------------
 * Namen
 * ---------------------------------------------------------------------- */

/**
 * Ist dieses Wort komplett großgeschrieben?
 *
 * ⚠ Das scharfe ß hat keine Großform, die die Quellen benutzen: race result
 * liefert `GEIßLER` und `STÖßER` – ein naiver Vergleich
 * `mb_strtoupper($w) === $w` scheitert daran und würde den Nachnamen
 * verlieren. Deshalb wird ß vor dem Vergleich zu SS aufgelöst.
 *
 * @param string $wort Einzelwort.
 * @return bool
 */
function lsg_bl_wort_ist_gross( $wort ) {
	$wort = str_replace( array( 'ß', 'ẞ' ), 'SS', (string) $wort );
	if ( ! preg_match( '/\p{L}/u', $wort ) ) {
		return false;
	}
	if ( function_exists( 'mb_strtoupper' ) ) {
		return mb_strtoupper( $wort, 'UTF-8' ) === $wort;
	}
	return strtoupper( $wort ) === $wort;
}

/**
 * Teilnehmerstring einer Quelle in Nachname und Vorname zerlegen.
 *
 * Die beiden Quellen schreiben Namen unterschiedlich, deshalb drei Regeln in
 * fester Reihenfolge (Plan 6.5.1):
 *
 *   1. Komma vorhanden            → „Nachname, Vorname". Eindeutig.
 *   2. Führender Block komplett
 *      großgeschriebener Wörter   → dieser Block ist der Nachname.
 *                                   Deckt „VON HOFF Anna-Maria" mit ab.
 *   3. Sonst                      → letztes Wort = Vorname, Rest = Nachname,
 *                                   und die Zeile wird `unsicher` markiert.
 *
 * `unsicher` ist kein Fehler, sondern eine Anzeige: die Oberfläche hebt
 * solche Zeilen hervor, die Zuordnung in P3 läuft trotzdem.
 *
 * @param string $teilnehmer Roher Namensstring der Quelle.
 * @return array{nachname:string,vorname:string,unsicher:bool}
 */
function lsg_bl_name_splitten( $teilnehmer ) {
	$roh = trim( preg_replace( '/\s+/u', ' ', (string) $teilnehmer ) );

	if ( '' === $roh ) {
		return array(
			'nachname' => '',
			'vorname'  => '',
			'unsicher' => true,
		);
	}

	// Regel 1: Komma trennt Nachname von Vorname.
	if ( false !== strpos( $roh, ',' ) ) {
		$teile = explode( ',', $roh, 2 );
		return array(
			'nachname' => trim( $teile[0] ),
			'vorname'  => isset( $teile[1] ) ? trim( $teile[1] ) : '',
			'unsicher' => '' === trim( $teile[0] ) || '' === trim( isset( $teile[1] ) ? $teile[1] : '' ),
		);
	}

	$worte = explode( ' ', $roh );

	// Regel 2: zusammenhängender Block GROSSGESCHRIEBENER Wörter am Anfang.
	$gross = 0;
	foreach ( $worte as $wort ) {
		if ( ! lsg_bl_wort_ist_gross( $wort ) ) {
			break;
		}
		++$gross;
	}

	// Ein Block, der *alle* Wörter umfasst, trennt nichts – dann greift
	// Regel 3, sonst bliebe der Vorname leer.
	if ( $gross > 0 && $gross < count( $worte ) ) {
		return array(
			'nachname' => implode( ' ', array_slice( $worte, 0, $gross ) ),
			'vorname'  => implode( ' ', array_slice( $worte, $gross ) ),
			'unsicher' => false,
		);
	}

	// Regel 3: letztes Wort = Vorname, alles davor = Nachname.
	if ( count( $worte ) < 2 ) {
		return array(
			'nachname' => $roh,
			'vorname'  => '',
			'unsicher' => true,
		);
	}

	$vorname = array_pop( $worte );
	return array(
		'nachname' => implode( ' ', $worte ),
		'vorname'  => $vorname,
		'unsicher' => true,
	);
}

/* -------------------------------------------------------------------------
 * Zeit
 * ---------------------------------------------------------------------- */

/**
 * Roh-Zeit einer Quelle → 'HH:MM:SS', oder '' wenn nicht verwertbar.
 *
 * Die Quellen liefern vier Schreibweisen, nicht zwei – die Stundenangabe
 * fehlt bei kurzen Distanzen, und Zehntel können an beiden Formen hängen:
 *
 *   1:13:08     → 01:13:08   HH:MM:SS, führende Null ergänzen
 *   01:11:54.9  → 01:11:55   HH:MM:SS.t, Zehntel aufgerundet (World Athletics)
 *   38:57       → 00:38:57   MM:SS
 *   18:57,3     → 00:18:58   MM:SS.t – Komma als Dezimaltrenner kommt vor
 *   DNF/DSQ/DNS → ''         Zeile verwerfen, der Aufrufer zählt sie
 *
 * ⚠ MM:SS.t ist der Fall, der leicht durchrutscht. Deshalb werden die
 * Zehntel abgetrennt, BEVOR über HH:MM:SS oder MM:SS entschieden wird.
 *
 * ⚠ ceil() auf einem Float wäre falsch: (float) '54.9' ist nicht exakt
 * darstellbar, und bei .0 würde ein Rundungsfehler eine Sekunde erfinden.
 * Deshalb der Vergleich auf dem Nachkommastring – und deshalb ltrim() auf
 * den String statt (int) > 0: bei '.000' ist beides gleich, bei einer Quelle
 * mit Millisekunden ('.004') nicht.
 *
 * Dieselbe Funktion benutzt das Formular aus Abschnitt 7 (7.2). Es gibt
 * keine zweite Implementierung.
 *
 * @param string $raw Rohwert aus der Quelle oder aus dem Formular.
 * @return string 'HH:MM:SS' oder ''.
 */
function lsg_bl_zeit_normalisieren( $raw ) {
	$raw = trim( (string) $raw );

	// DNF / DSQ / DNS / leer → keine Zeit. Der Aufrufer zählt sie.
	if ( '' === $raw || preg_match( '/^(dnf|dsq|dns|dq|--?)$/i', $raw ) ) {
		return '';
	}

	// 1. Zehntel abtrennen – vor jeder weiteren Entscheidung.
	$auf    = 0;
	$stellen = 0;
	if ( preg_match( '/^(.*?)[.,](\d+)$/', $raw, $m ) ) {
		$raw     = $m[1];
		$stellen = strlen( $m[2] );
		if ( '' !== ltrim( $m[2], '0' ) ) { // jede Stelle > 0 rundet auf
			$auf = 1;
		}
	}

	// 2. HH:MM:SS oder MM:SS – beide Formen, danach identische Rechnung.
	if ( preg_match( '/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $raw, $m ) ) {
		$sek = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
	} elseif ( preg_match( '/^(\d{1,3}):([0-5]\d)$/', $raw, $m ) ) {
		/*
		 * ⚠ Hier trennen sich zwei Schreibweisen, die gleich aussehen:
		 *     18:57.3   MM:SS mit Zehntel  → 00:18:58
		 *     01:20.24  Tippfehler für 01:20:24, Punkt statt Doppelpunkt
		 *
		 * Beide sind „MM:SS + Nachkommastellen". Unterscheidbar sind sie nur
		 * an der Stellenzahl: eine echte Zehntelangabe hat genau eine Stelle,
		 * eine verrutschte Sekundenangabe zwei. Mehr als eine Stelle nach
		 * einer MM:SS-Form ist deshalb keine Zeit, sondern ein Zweifelsfall –
		 * und der wird verworfen, nicht geraten. Für die historischen
		 * Tippfehler im Bestand ist der tolerante Zweig in
		 * lsg_bl_parse_performance() zuständig; über den Import darf kein
		 * neuer dazukommen (Plan 6.5.1).
		 */
		if ( $stellen > 1 ) {
			return '';
		}
		$sek = (int) $m[1] * 60 + (int) $m[2];
	} else {
		return '';
	}

	$sek += $auf;

	// Der Übertrag über die Minuten- und Stundengrenze fällt durch die
	// Rechnung in Sekunden von selbst richtig aus: 01:11:59.9 wird zu
	// 01:12:00, nicht zu 01:11:60.
	return sprintf(
		'%02d:%02d:%02d',
		intdiv( $sek, 3600 ),
		intdiv( $sek % 3600, 60 ),
		$sek % 60
	);
}

/* -------------------------------------------------------------------------
 * Geschlecht und Altersklasse
 * ---------------------------------------------------------------------- */

/**
 * Geschlecht aus einem Klassen-Code der Quelle ableiten.
 *
 * Keine Quelle führt ein eigenes Geschlechtsfeld, aber beide schreiben es in
 * die Klasse:
 *   Runtix       col-ageclass  „M 30" / „W 45"
 *   race result  AK-Pl.        „1. M35"        MW-Pl. „1. M"
 *
 * Gemappt auf die Plugin-Konvention 'm' / 'f' (lsg_athlete.cat).
 *
 * ⚠ Für die Altersklasse ist dieser Wert NICHT maßgeblich – die rechnet sich
 * in jedem Fall aus lsg_athlete.cat (6.5.3). Er dient dem Abgleich: weicht
 * das Geschlecht der Quelle vom zugeordneten Athleten ab, ist das ein
 * starker Hinweis auf eine Fehlzuordnung.
 *
 * @param string $klasse Rohwert, z.B. '1. M35', 'M 30', '2. WJ U18'.
 * @return string 'm', 'f' oder '' wenn nicht erkennbar.
 */
function lsg_bl_geschlecht_aus_klasse( $klasse ) {
	$klasse = trim( (string) $klasse );
	if ( '' === $klasse ) {
		return '';
	}

	// Führenden Platz abschneiden: '1. M35' → 'M35', 'DNS M40' → 'M40'.
	$rest = preg_replace( '/^\s*(?:\d+\s*\.?|dnf|dsq|dns|dq)\s*/i', '', $klasse );
	$rest = ltrim( $rest );
	if ( '' === $rest ) {
		return '';
	}

	$erste = strtoupper( substr( $rest, 0, 1 ) );
	if ( 'M' === $erste ) {
		return 'm';
	}
	if ( 'W' === $erste || 'F' === $erste ) {
		return 'f';
	}
	return '';
}

/**
 * Altersklassen-Code für lsg_best.ak berechnen.
 *
 * Entschieden: Jahrgangsklassen, keine Stichtagsklassen. Das Alter ist
 * Veranstaltungsjahr − Jahrgang, unabhängig davon, ob der Geburtstag am
 * Wettkampftag schon war. Ein Jahrgang 1976 läuft also ab dem 1. Januar 2026
 * in m50, auch wenn er erst im November 50 wird. Das Geburtsdatum wird nicht
 * gebraucht – lsg_athlete.born ist ohnehin nur ein year(4).
 *
 *   alter < 30 → 'hk' (Hauptklasse)
 *   sonst      → 5er-Stufe abgerundet: 30, 35, 40, …
 *   Code       = ('f' === cat ? 'w' : 'm') . stufe
 *
 * @param int    $jahrgang         lsg_athlete.born.
 * @param int    $veranstaltungsjahr Jahr des Veranstaltungsdatums.
 * @param string $cat              lsg_athlete.cat ('m' | 'f').
 * @return string z.B. 'm50', 'whk'. Leer, wenn die Eingaben unbrauchbar sind.
 */
function lsg_bl_ak_berechnen( $jahrgang, $veranstaltungsjahr, $cat ) {
	$jahrgang = (int) $jahrgang;
	$jahr     = (int) $veranstaltungsjahr;

	if ( $jahrgang <= 0 || $jahr <= 0 || $jahr < $jahrgang ) {
		return '';
	}

	$alter = $jahr - $jahrgang;
	$stufe = ( $alter < 30 ) ? 'hk' : (string) ( intdiv( $alter, 5 ) * 5 );

	return ( 'f' === strtolower( (string) $cat ) ? 'w' : 'm' ) . $stufe;
}

/* -------------------------------------------------------------------------
 * Datum
 * ---------------------------------------------------------------------- */

/**
 * Deutsche Monatsnamen → Monatszahl.
 *
 * @return array<string,int>
 */
function lsg_bl_monatsnamen() {
	return array(
		'januar'    => 1,
		'jan'       => 1,
		'februar'   => 2,
		'feb'       => 2,
		'maerz'     => 3,
		'mrz'       => 3,
		'mar'       => 3,
		'april'     => 4,
		'apr'       => 4,
		'mai'       => 5,
		'juni'      => 6,
		'jun'       => 6,
		'juli'      => 7,
		'jul'       => 7,
		'august'    => 8,
		'aug'       => 8,
		'september' => 9,
		'sep'       => 9,
		'sept'      => 9,
		'oktober'   => 10,
		'okt'       => 10,
		'november'  => 11,
		'nov'       => 11,
		'dezember'  => 12,
		'dez'       => 12,
	);
}

/**
 * Ein Veranstaltungsdatum aus freiem Text lesen.
 *
 * Erkannt werden, in dieser Reihenfolge:
 *   16.08.2026 · 16.8.26 · 2026-08-16 · „16. August 2026" · „Aug 16, 2026"
 * und, wenn nichts davon greift, eine alleinstehende Jahreszahl.
 *
 * ⚠ Ein unvollständiges Datum wird NICHT ergänzt – kein stiller 1. Januar.
 * Fehlen Tag und Monat, kommt nur das Jahr zurück und die Oberfläche
 * verlangt die Ergänzung (Plan 6.5.1).
 *
 * @param string $text     Freier Text (Eventname, Ausschreibung, …).
 * @param int    $jahr_min Untere Plausibilitätsgrenze für Jahreszahlen.
 * @param int    $jahr_max Obere Grenze. 0 = laufendes Jahr + 2.
 * @return array{datum:string,jahr:string} datum als 'JJJJ-MM-TT' oder ''.
 */
function lsg_bl_datum_aus_text( $text, $jahr_min = 1950, $jahr_max = 0 ) {
	$leer = array(
		'datum' => '',
		'jahr'  => '',
	);

	$text = trim( (string) $text );
	if ( '' === $text ) {
		return $leer;
	}

	if ( $jahr_max <= 0 ) {
		$jahr_max = (int) gmdate( 'Y' ) + 2;
	}

	$pruefen = function ( $j, $m, $t ) use ( $jahr_min, $jahr_max ) {
		$j = (int) $j;
		$m = (int) $m;
		$t = (int) $t;
		if ( $j < $jahr_min || $j > $jahr_max ) {
			return '';
		}
		if ( ! checkdate( $m, $t, $j ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $j, $m, $t );
	};

	// 2026-08-16
	if ( preg_match( '/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $text, $m ) ) {
		$d = $pruefen( $m[1], $m[2], $m[3] );
		if ( '' !== $d ) {
			return array(
				'datum' => $d,
				'jahr'  => substr( $d, 0, 4 ),
			);
		}
	}

	// 16.08.2026 / 16.8.26
	if ( preg_match( '/(?<!\d)(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{2,4})(?!\d)/', $text, $m ) ) {
		$jahr = (int) $m[3];
		if ( $jahr < 100 ) {
			$jahr += ( $jahr <= 69 ) ? 2000 : 1900;
		}
		$d = $pruefen( $jahr, $m[2], $m[1] );
		if ( '' !== $d ) {
			return array(
				'datum' => $d,
				'jahr'  => substr( $d, 0, 4 ),
			);
		}
		/*
		 * Der Text nennt ein vollständiges Datum – es ist nur unmöglich
		 * („31.02.2026"). Dann wird auch die Jahreszahl nicht übernommen:
		 * Wer den Tag falsch schreibt, kann das Jahr genauso falsch
		 * geschrieben haben. Lieber ein leeres Feld, das eine Entscheidung
		 * verlangt, als ein halber Wert, den niemand mehr prüft.
		 */
		return $leer;
	}

	// „16. August 2026" / „16 Aug 2026"
	$norm    = lsg_bl_text_normalisieren( $text );
	$monate  = lsg_bl_monatsnamen();
	$muster  = implode( '|', array_keys( $monate ) );
	if ( preg_match( '/(?<!\d)(\d{1,2})\s+(' . $muster . ')\s+(\d{4})(?!\d)/', $norm, $m ) ) {
		$d = $pruefen( $m[3], $monate[ $m[2] ], $m[1] );
		if ( '' !== $d ) {
			return array(
				'datum' => $d,
				'jahr'  => substr( $d, 0, 4 ),
			);
		}
	}

	// Nur eine Jahreszahl – und nur, wenn es genau eine plausible gibt.
	if ( preg_match_all( '/(?<!\d)(19|20)\d{2}(?!\d)/', $text, $m ) ) {
		$jahre = array();
		foreach ( $m[0] as $j ) {
			$j = (int) $j;
			if ( $j >= $jahr_min && $j <= $jahr_max ) {
				$jahre[] = $j;
			}
		}
		$jahre = array_values( array_unique( $jahre ) );
		if ( 1 === count( $jahre ) ) {
			return array(
				'datum' => '',
				'jahr'  => (string) $jahre[0],
			);
		}
	}

	return $leer;
}

/* -------------------------------------------------------------------------
 * Distanz
 * ---------------------------------------------------------------------- */

/**
 * Schreibweisen aus den Quellen → kanonischer Distanzcode.
 * Schlüssel sind bereits normalisiert (klein, ohne Leer-/Sonderzeichen).
 *
 * 6h / 12h / 24h fehlen bewusst: Zeitläufe werden nicht importiert – dort
 * hält lsg_best.time eine Strecke, keine Zeit (Plan 6.5.1). Sie werden über
 * die Seite „Bestenliste" (Abschnitt 7) von Hand erfasst.
 *
 * @return array<string,string>
 */
function lsg_bl_distance_aliases() {
	return array(
		// Halbmarathon
		'21'           => 'HM',
		'21km'         => 'HM',
		'211'          => 'HM',
		'211km'        => 'HM',      // "21,1 km"
		'2110'         => 'HM',
		'2110km'       => 'HM',      // "21,10 km"
		'210975'       => 'HM',
		'210975km'     => 'HM',      // "21,0975 km"
		'halbmarathon' => 'HM',
		'hm'           => 'HM',
		'halfmarathon' => 'HM',
		// Marathon
		'42'           => 'Marathon',
		'42km'         => 'Marathon',
		'42195'        => 'Marathon',
		'42195km'      => 'Marathon',
		'marathon'     => 'Marathon',
		// der Rest ist geradeaus
		'5'            => '5km',
		'5km'          => '5km',
		'10'           => '10km',
		'10km'         => '10km',
		'15'           => '15km',
		'15km'         => '15km',
		'20'           => '20km',
		'20km'         => '20km',
		'25'           => '25km',
		'25km'         => '25km',
		'50'           => '50km',
		'50km'         => '50km',
		'100'          => '100km',
		'100km'        => '100km',
	);
}

/**
 * Distanzcodes, die der Import anbieten darf – die neun Streckendistanzen.
 *
 * Zeitläufe (6h, 12h, 24h) sind ausgenommen: dort hält lsg_best.time eine
 * Strecke ("112,737 km"), die Parse-Pipeline erzeugt aber immer eine Zeit.
 * Stünde 6h im Select, würde eine Zeit in ein Streckenfeld geschrieben und
 * P4 vergliche sie anschließend als Zahl – ein stiller Fehler ohne
 * Fehlermeldung (Plan 6.5.1).
 *
 * @return string[]
 */
function lsg_bl_import_distanzen() {
	return array( '5km', '10km', '15km', '20km', '25km', 'HM', 'Marathon', '50km', '100km' );
}

/**
 * Wettbewerbsbezeichnung → kanonischer Distanzcode (Mapping 1 von 2).
 *
 * Gesucht wird in dieser Reihenfolge:
 *   1. Staffel-Multiplikator („4x10 km") → mehrdeutig, sofort leer
 *   2. Namens-Token: halbmarathon, hm, marathon
 *      → trifft „Marathon" vor „42" und verhindert, dass „5. Ettlinger
 *        Marathon" wegen der 5 als 5km durchgeht
 *   3. Zahlen-Token mit oder ohne „km"
 *      → Ordnungszahlen („5.", „17.") und fremde Einheiten („10 Meilen",
 *        „500m", „1500 m") zählen nicht
 *   4. Widersprechen sich Name und Zahl, oder liefern die Zahlen zwei
 *      verschiedene Codes → leer
 *
 * Ein leeres Ergebnis ist ehrlicher als ein falsch geratenes: Es hält den
 * Parsen-Button gesperrt und verlangt eine Entscheidung.
 *
 * @param string $name Wettbewerbsname aus der Quelle.
 * @return string Kanonischer Code oder '' wenn nicht eindeutig.
 */
function lsg_bl_distanz_aus_name( $name ) {
	$n = (string) $name;
	if ( function_exists( 'mb_strtolower' ) ) {
		$n = mb_strtolower( $n, 'UTF-8' );
	} else {
		$n = strtolower( $n );
	}
	$n = strtr( $n, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );

	if ( '' === trim( $n ) ) {
		return '';
	}

	$aliases = lsg_bl_distance_aliases();

	// 1. Staffel: „4x10 km", „4 x 500m" – mehrere Strecken in einer Angabe.
	if ( preg_match( '/\d+\s*[x*]\s*\d/', $n ) ) {
		return '';
	}

	// 2. Namens-Token. Halbmarathon zuerst – es enthält „marathon".
	$namens_code = '';
	if ( preg_match( '/halbmarathon|half\s*marathon/', $n ) ) {
		$namens_code = 'HM';
	} elseif ( preg_match( '/\bhm\b/', $n ) ) {
		$namens_code = 'HM';
	} elseif ( preg_match( '/marathon/', $n ) ) {
		$namens_code = 'Marathon';
	}

	// 3. Zahlen-Token.
	$zahl_codes = array();
	// (?<!\d) … (?!\d) ist Pflicht, sonst zerfällt „1000m" in „100" + „0"
	// und der Bambinilauf bekäme 100km vorgeschlagen.
	if ( preg_match_all( '/(?<!\d)\d{1,4}(?:[.,]\d{1,4})?(?!\d)/', $n, $treffer, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $treffer[0] as $t ) {
			$zahl   = $t[0];
			$offset = $t[1] + strlen( $zahl );
			$rest   = substr( $n, $offset );

			// Einheit direkt hinter der Zahl, mit oder ohne Leerzeichen.
			$einheit = '';
			if ( preg_match( '/^\s*([a-z]+)/', $rest, $e ) ) {
				$einheit = $e[1];
			}

			if ( 'km' === $einheit || 'kilometer' === $einheit ) {
				$key = str_replace( array( ',', '.' ), '', $zahl ) . 'km';
			} elseif ( '' !== $einheit ) {
				// Fremde Einheit („10 meilen", „500m", „1500 m") oder ein
				// Wort, das direkt anschließt. Nur bekannte Einheiten
				// verwerfen die Zahl; „21 sparkasse" bleibt gültig.
				$fremd = array( 'm', 'meile', 'meilen', 'mile', 'miles', 'mi', 'h', 'std', 'stunde', 'stunden', 'min', 'sek', 'yd', 'yards' );
				if ( in_array( $einheit, $fremd, true ) ) {
					continue;
				}
				$key = str_replace( array( ',', '.' ), '', $zahl );
			} else {
				// Ordnungszahl? „5. Ettlinger Marathon", „17. SWE …"
				if ( preg_match( '/^\./', $rest ) ) {
					continue;
				}
				$key = str_replace( array( ',', '.' ), '', $zahl );
			}

			if ( isset( $aliases[ $key ] ) ) {
				$zahl_codes[] = $aliases[ $key ];
			}
		}
	}

	$zahl_codes = array_values( array_unique( $zahl_codes ) );

	// 4. Auflösen.
	if ( count( $zahl_codes ) > 1 ) {
		return '';   // zwei verschiedene Strecken im Namen
	}

	if ( '' !== $namens_code && $zahl_codes && $zahl_codes[0] !== $namens_code ) {
		return '';   // Name und Zahl widersprechen sich
	}

	if ( '' !== $namens_code ) {
		return $namens_code;
	}

	return $zahl_codes ? $zahl_codes[0] : '';
}
