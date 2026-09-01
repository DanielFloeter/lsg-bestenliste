<?php
/**
 * Hilfsfunktionen: Distanz-Kategorien, Zeit/Distanz-Parsing & Sortierung,
 * Altersklassen/Geschlecht, Formatierung von Datum und Namen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kanonische Reihenfolge & Typ der Distanzen wie in lsg_best.distance /
 * lsg_win.distance verwendet. 'time'  => Ergebnis ist eine Zeit, kleiner ist
 * besser. 'distance' => Ergebnis ist eine gelaufene Strecke (bei Zeitläufen
 * wie 6h/12h/24h), größer ist besser.
 *
 * @return array<string,array{label:string,type:string}>
 */
function lsg_bl_distance_map() {
	return array(
		'5km'      => array( 'label' => '5 km', 'type' => 'time' ),
		'10km'     => array( 'label' => '10 km', 'type' => 'time' ),
		'15km'     => array( 'label' => '15 km', 'type' => 'time' ),
		'20km'     => array( 'label' => '20 km', 'type' => 'time' ),
		'25km'     => array( 'label' => '25 km', 'type' => 'time' ),
		'HM'       => array( 'label' => 'Halbmarathon', 'type' => 'time' ),
		'Marathon' => array( 'label' => 'Marathon', 'type' => 'time' ),
		'50km'     => array( 'label' => '50 km', 'type' => 'time' ),
		'100km'    => array( 'label' => '100 km', 'type' => 'time' ),
		'6h'       => array( 'label' => '6 Stunden', 'type' => 'distance' ),
		'12h'      => array( 'label' => '12 Stunden', 'type' => 'distance' ),
		'24h'      => array( 'label' => '24 Stunden', 'type' => 'distance' ),
	);
}

/**
 * Label für eine Distanz, mit Fallback auf den Rohwert falls unbekannt.
 */
function lsg_bl_distance_label( $distance ) {
	$map = lsg_bl_distance_map();
	if ( isset( $map[ $distance ] ) ) {
		return $map[ $distance ]['label'];
	}
	return $distance;
}

/**
 * Typ einer Distanz ('time' oder 'distance'), mit heuristischem Fallback für
 * unbekannte/zukünftige Distanzwerte.
 */
function lsg_bl_distance_type( $distance ) {
	$map = lsg_bl_distance_map();
	if ( isset( $map[ $distance ] ) ) {
		return $map[ $distance ]['type'];
	}
	// Heuristik: enthält die Distanzbezeichnung ein 'h' (Stunden) und keine km-Zahl -> Zeitlauf.
	if ( preg_match( '/\d+\s*h$/i', trim( $distance ) ) ) {
		return 'distance';
	}
	return 'time';
}

/**
 * Maximale Anzahl Zeilen pro Distanz in der Ewigen Bestenliste.
 */
function lsg_bl_eternal_limit( $distance ) {
	if ( '10km' === $distance ) {
		return 30;
	}
	return 20;
}

/**
 * Parst einen Ergebniswert (Spalte "time" in lsg_best/lsg_win) in einen
 * sortierbaren numerischen Wert. Unterstützt sowohl Zeiten (HH:MM:SS, auch
 * mit Anhängseln wie " h") als auch Streckenangaben (z.B. "80,475 km").
 *
 * Die historischen Daten enthalten bei den Zeiten vereinzelt Tippfehler
 * (Punkt statt Doppelpunkt als Trenner, z.B. "01:20.24", oder fehlende
 * Stundenangabe bei kürzeren Distanzen, z.B. "38:57"). Diese werden
 * toleranter erkannt, damit sie nicht in den ungenauen Zahlen-Fallback
 * fallen und die Sortierung verfälschen.
 *
 * @return array{sort:float,better:string,display:string}
 */
function lsg_bl_parse_performance( $distance, $raw ) {
	$raw  = trim( (string) $raw );
	$type = lsg_bl_distance_type( $distance );

	if ( 'distance' === $type ) {
		// Streckenangabe, z.B. "80,475 km" oder "228 km".
		if ( preg_match( '/([\d]+(?:[.,]\d+)?)\s*km/i', $raw, $m ) ) {
			$num = (float) str_replace( ',', '.', $m[1] );
			return array(
				'sort'    => $num,
				'better'  => 'higher',
				'display' => $raw,
			);
		}

		// Fallback: irgendeine Zahl im String.
		if ( preg_match( '/([\d]+(?:[.,]\d+)?)/', $raw, $m ) ) {
			$num = (float) str_replace( ',', '.', $m[1] );
			return array(
				'sort'    => $num,
				'better'  => 'higher',
				'display' => $raw,
			);
		}

		return array(
			'sort'    => -PHP_INT_MAX,
			'better'  => 'higher',
			'display' => $raw,
		);
	}

	// Zeitformat HH:MM:SS, auch mit vertauschtem/abweichendem Trenner
	// (":" oder ".") zwischen den drei Teilen, z.B. "01:20.24" oder
	// "02.04:12".
	if ( preg_match( '/(\d{1,3})[:.]([0-5]\d)[:.]([0-5]\d)/', $raw, $m ) ) {
		$seconds = ( (int) $m[1] ) * 3600 + ( (int) $m[2] ) * 60 + (int) $m[3];
		return array(
			'sort'    => (float) $seconds,
			'better'  => 'lower',
			'display' => $raw,
		);
	}

	// Zeitformat MM:SS ohne Stundenangabe, z.B. "38:57".
	if ( preg_match( '/^(\d{1,3}):([0-5]\d)$/', $raw, $m ) ) {
		$seconds = ( (int) $m[1] ) * 60 + (int) $m[2];
		return array(
			'sort'    => (float) $seconds,
			'better'  => 'lower',
			'display' => $raw,
		);
	}

	// Fallback: irgendeine Zahl im String.
	if ( preg_match( '/([\d]+(?:[.,]\d+)?)/', $raw, $m ) ) {
		$num = (float) str_replace( ',', '.', $m[1] );
		return array(
			'sort'    => -$num,
			'better'  => 'lower',
			'display' => $raw,
		);
	}

	return array(
		'sort'    => PHP_INT_MAX,
		'better'  => 'lower',
		'display' => $raw,
	);
}

/**
 * Sortiert ein Array von Ergebnis-Zeilen (jede Zeile braucht einen Key
 * '_perf' mit dem Ergebnis von lsg_bl_parse_performance()) nach Leistung,
 * beste Leistung zuerst (kleinste Zeit bzw. größte Strecke).
 */
function lsg_bl_sort_rows_by_performance( array $rows ) {
	usort(
		$rows,
		function ( $a, $b ) {
			$dir = ( 'higher' === $a['_perf']['better'] ) ? -1 : 1;
			if ( $a['_perf']['sort'] === $b['_perf']['sort'] ) {
				return 0;
			}
			return ( $a['_perf']['sort'] < $b['_perf']['sort'] ) ? -$dir : $dir;
		}
	);
	return $rows;
}

/**
 * Entfernt Duplikate desselben Athleten aus einer bereits nach Leistung
 * sortierten Zeilenliste und behält jeweils nur die erste (= beste) Zeile.
 * So taucht z.B. für die Ewige Bestenliste jeder Athlet pro Distanz nur
 * einmal auf, auch wenn er mehrere Einträge in lsg_best hat.
 */
function lsg_bl_dedupe_rows_by_athlete( array $rows ) {
	$seen   = array();
	$result = array();
	foreach ( $rows as $row ) {
		$athlete_id = isset( $row['athletes_id'] ) ? (int) $row['athletes_id'] : 0;
		if ( $athlete_id && isset( $seen[ $athlete_id ] ) ) {
			continue;
		}
		if ( $athlete_id ) {
			$seen[ $athlete_id ] = true;
		}
		$result[] = $row;
	}
	return $result;
}

/**
 * Geschlecht ('m'|'f') aus einem Altersklassen-Code ableiten (z.B. 'm45' => 'm',
 * 'whk' => 'f'). Fällt auf 'm' zurück, falls der Code nicht erkannt wird.
 */
function lsg_bl_gender_from_ak( $ak ) {
	$ak = strtolower( trim( (string) $ak ) );
	return ( 0 === strpos( $ak, 'w' ) ) ? 'f' : 'm';
}

/**
 * SQL LIKE-Pattern für ein Geschlecht ('m' => 'm%', 'f' => 'w%'), passend zu
 * den Altersklassen-Codes in lsg_best.ak.
 */
function lsg_bl_gender_ak_pattern( $gender ) {
	return ( 'f' === $gender ) ? 'w%' : 'm%';
}

/**
 * Entfernt das führende Geschlechts-Präfix ('m'/'w') aus einem
 * Altersklassen-Code, z.B. 'm45' => '45', 'whk' => 'hk'. Das Geschlecht wird
 * bereits über ein eigenes Dropdown gewählt, daher soll es in der
 * Altersklassen-Anzeige nicht noch einmal (doppelt) auftauchen.
 */
function lsg_bl_ak_strip_gender( $ak ) {
	$ak = strtolower( trim( (string) $ak ) );
	if ( 0 === strpos( $ak, 'm' ) || 0 === strpos( $ak, 'w' ) ) {
		return substr( $ak, 1 );
	}
	return $ak;
}

/**
 * Sortierschlüssel für Altersklassen: Hauptklasse (hk) zuerst, danach
 * aufsteigend nach Alterszahl.
 */
function lsg_bl_ak_sort_key( $ak ) {
	$ak = strtolower( trim( (string) $ak ) );
	if ( preg_match( '/hk$/', $ak ) ) {
		return -1;
	}
	if ( preg_match( '/(\d+)/', $ak, $m ) ) {
		return (int) $m[1];
	}
	return 999;
}

/**
 * Liste der verfügbaren Altersklassen für ein Geschlecht ('m'/'f'), oder für
 * beide zusammen ('alle'), aus der Tabelle lsg_ak. Gibt die Codes ohne
 * Geschlechts-Präfix zurück (z.B. 'hk', '30', '35', …) und dedupliziert sie,
 * damit bei "Alle" nicht jede Alterszahl doppelt (einmal für m, einmal für w)
 * auftaucht – das Geschlecht wird ja bereits über ein eigenes Dropdown
 * gewählt. Sortiert nach Hauptklasse zuerst, dann aufsteigend nach Alter.
 *
 * @return string[]
 */
function lsg_bl_ak_list_for_gender( $gender ) {
	global $wpdb;
	$table = lsg_bl_table( 'lsg_ak' );

	if ( 'alle' === $gender ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT ak FROM {$table}" );
	} else {
		$pattern = lsg_bl_gender_ak_pattern( $gender );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ak FROM {$table} WHERE ak LIKE %s", $pattern ) ); // phpcs:ignore
	}

	// Hinweis: NICHT als Array-Keys deduplizieren – PHP wandelt numerische
	// String-Keys (z.B. '30') automatisch in int(30) um, wodurch der spätere
	// strikte Vergleich mit dem String-Wert aus $_GET/dem Request
	// (in_array( $ak, $valid_ak, true )) fehlschlagen und die Auswahl immer
	// auf "alle" zurückfallen würde.
	$suffixes = array();
	foreach ( $rows as $ak ) {
		$suffixes[] = lsg_bl_ak_strip_gender( $ak );
	}
	$rows = array_values( array_unique( $suffixes, SORT_STRING ) );

	usort(
		$rows,
		function ( $a, $b ) {
			$key_a = lsg_bl_ak_sort_key( $a );
			$key_b = lsg_bl_ak_sort_key( $b );
			if ( $key_a === $key_b ) {
				return strcasecmp( $a, $b );
			}
			return ( $key_a <=> $key_b );
		}
	);
	return $rows;
}

/**
 * Kalenderjahr → Zeitspanne [von, bis) in Unix-Timestamps, in der Zeitzone
 * der Installation. Für JEDE Jahresabfrage auf lsg_best und lsg_win.
 *
 * ⚠ Nicht YEAR(FROM_UNIXTIME(`date`)) in SQL. Das rechnet mit der
 * MySQL-Session-Zeitzone, und die ist nicht die Zeitzone der
 * WordPress-Installation. Der Bestand speichert `date` als 00:00 Ortszeit
 * des Wettkampftags – steht die Session auf UTC, wird daraus der Vortag, und
 * bei einem Lauf am 1. Januar das Vorjahr (Plan 6.5.4).
 *
 * ⚠ Und nicht mktime(). WordPress setzt die PHP-Zeitzone auf UTC, also
 * liefert mktime( 0, 0, 0, 1, 1, $jahr ) den 1. Januar 00:00 UTC – eine
 * Zeile, die auf 00:00 Ortszeit liegt, fällt davor. Derselbe Fehler, nur von
 * SQL nach PHP verschoben.
 *
 * Nebenbei ist die Zeitspanne auch die schnellere Form: YEAR(FROM_UNIXTIME(x))
 * ist ein Funktionsaufruf auf der Spalte und schließt jeden Index aus.
 *
 * @param int $jahr Kalenderjahr.
 * @return array{0:int,1:int} [von, bis)
 */
function lsg_bl_jahr_grenzen( $jahr ) {
	$jahr = (int) $jahr;
	$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

	return array(
		( new DateTimeImmutable( sprintf( '%04d-01-01 00:00:00', $jahr ), $tz ) )->getTimestamp(),
		( new DateTimeImmutable( sprintf( '%04d-01-01 00:00:00', $jahr + 1 ), $tz ) )->getTimestamp(),
	);
}

/**
 * Veranstaltungsdatum ('JJJJ-MM-TT') → Unix-Timestamp, 12:00 Uhr Ortszeit.
 *
 * ⚠ 12:00, nicht 00:00: Bei Mitternacht kann die Zeitzonenrechnung den Tag um
 * eins verschieben, und dann steht in der Bestenliste der Vortag – genau so
 * liegen sechs Altfälle im Bestand (alles Neujahrsläufe).
 *
 * ⚠ Über wp_timezone(), nicht über mktime(): WordPress setzt die
 * PHP-Zeitzone auf UTC, mktime( 12, 0, 0, … ) liefert also 12:00 UTC. In
 * Mitteleuropa wäre das derselbe Tag, der Wert also brauchbar – aber er ist
 * nicht das, was dasteht, und auf einer Installation mit anderer Zeitzone
 * bricht die Annahme (Plan 6.5.1, 6.5.4, 7.3).
 *
 * @param string $datum 'JJJJ-MM-TT'.
 * @return int Timestamp, oder 0 bei ungültigem Datum.
 */
function lsg_bl_datum_zu_timestamp( $datum ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( (string) $datum ), $m ) ) {
		return 0;
	}
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return 0;
	}

	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

	return ( new DateTimeImmutable(
		sprintf( '%04d-%02d-%02d 12:00:00', (int) $m[1], (int) $m[2], (int) $m[3] ),
		$tz
	) )->getTimestamp();
}

/**
 * Formatiert einen Unix-Timestamp als TT.MM.JJJJ. Leere/ungültige Werte
 * ergeben einen leeren String.
 */
function lsg_bl_format_date( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return '';
	}
	return date_i18n( 'd.m.Y', $timestamp );
}

/**
 * Jahr aus einem Unix-Timestamp.
 */
function lsg_bl_year_from_timestamp( $timestamp ) {
	$timestamp = (int) $timestamp;
	if ( $timestamp <= 0 ) {
		return 0;
	}
	return (int) date_i18n( 'Y', $timestamp );
}

/**
 * Anzeigename eines Athleten: "Nachname Vorname" (wie in den Originaldaten).
 */
function lsg_bl_athlete_display_name( $name, $firstname ) {
	return trim( $name . ' ' . $firstname );
}

/**
 * Escaped Ausgabe-Helfer für Tabellenzellen (leerer Wert => "–").
 */
function lsg_bl_cell( $value ) {
	$value = trim( (string) $value );
	return ( '' === $value ) ? '&#8211;' : esc_html( $value );
}
