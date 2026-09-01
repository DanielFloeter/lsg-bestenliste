<?php
/**
 * Value-Objects der Adapter-Schicht: EventRef, Wettbewerb, Liste, Ergebnis.
 *
 * ⚠ Ohne WordPress ladbar (siehe class-lsg-normalize.php).
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Der Event-Kontext, den Schritt 1 aus der URL löst und den alle weiteren
 * Adapter-Aufrufe mitbekommen.
 */
class LSG_BL_Event_Ref {

	/** @var string Schlüssel des Adapters, z.B. 'raceresult'. */
	public $adapter = '';

	/** @var string Event-ID der Quelle. String! Nie (int) casten. */
	public $event_id = '';

	/** @var string Veranstaltungsname, soweit die Quelle ihn nennt. */
	public $event_name = '';

	/** @var string Die vom Benutzer eingegebene URL, unverändert. */
	public $url = '';

	/**
	 * Aus der URL bereits mitgelesene Vorauswahl. Leer, wenn die URL nur auf
	 * das Event zeigt.
	 *
	 * @var string
	 */
	public $contest_id = '';

	/** @var string Aus der URL mitgelesene Listen-ID bzw. Runtix-`rlt`. */
	public $list_id = '';

	/**
	 * Freier Zwischenspeicher des Adapters (race result: key + server aus
	 * der config-Antwort). Wird NICHT gecacht – der race-result-`key`
	 * rotiert (Plan 4.2).
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * @param string $adapter  Adapter-Schlüssel.
	 * @param string $event_id Event-ID der Quelle.
	 * @param string $url      Eingegebene URL.
	 */
	public function __construct( $adapter = '', $event_id = '', $url = '' ) {
		$this->adapter  = (string) $adapter;
		$this->event_id = (string) $event_id;
		$this->url      = (string) $url;
	}
}

/**
 * Ein Wettbewerb eines Events („Hauptlauf 21,1km", „21 KM Kraichgau-Lauf").
 */
class LSG_BL_Wettbewerb {

	/** @var string Contest-Key. ACHTUNG: nicht rein numerisch („w" bei Runtix). */
	public $id = '';

	/** @var string Anzeigename. */
	public $name = '';

	/**
	 * @param string $id   Contest-Key.
	 * @param string $name Anzeigename.
	 */
	public function __construct( $id = '', $name = '' ) {
		$this->id   = (string) $id;
		$this->name = (string) $name;
	}
}

/**
 * Eine Ergebnisliste innerhalb eines Wettbewerbs.
 */
class LSG_BL_Liste {

	/** @var string Listen-ID (race result: 'B45FAB'; Runtix: 'total'). */
	public $id = '';

	/** @var string Anzeigename für die Oberfläche. */
	public $name = '';

	/**
	 * Technischer Name, den der Datenabruf braucht (race result:
	 * '01.1_Ergebnisse|Zieleinlauf_Brutto'). Bei Runtix identisch mit `id`.
	 *
	 * @var string
	 */
	public $ref = '';

	/** @var bool Live-Liste? Nur Anzeige – importiert wird trotzdem (Plan 5.2). */
	public $live = false;

	/**
	 * Ist das die Gesamtwertung? Nur dort ist Platz 1 ein Gesamtsieg
	 * (Plan 6.5.5). Bei Unklarheit false – lieber nichts anbieten.
	 *
	 * @var bool
	 */
	public $gesamtwertung = false;

	/**
	 * @param string $id   Listen-ID.
	 * @param string $name Anzeigename.
	 * @param string $ref  Technischer Name für den Abruf.
	 */
	public function __construct( $id = '', $name = '', $ref = '' ) {
		$this->id   = (string) $id;
		$this->name = (string) $name;
		$this->ref  = ( '' === $ref ) ? (string) $id : (string) $ref;
	}
}

/**
 * Eine normalisierte Ergebniszeile – das Zielformat aus Plan 5.1/6.5.1.
 *
 * Was die Quelle nicht hergibt, bleibt leer. Nichts wird geraten.
 */
class LSG_BL_Ergebnis {

	/** @var string Nachname, gesplittet. Pflicht. */
	public $nachname = '';

	/** @var string Vorname, gesplittet. Pflicht. */
	public $vorname = '';

	/** @var string Roher Namensstring der Quelle, ungesplittet. */
	public $teilnehmer = '';

	/** @var bool Der Splitter musste raten (Plan 6.5.1, Regel 3). */
	public $namen_unsicher = false;

	/** @var string 'm' | 'f' | '' – aus dem Klassen-Code der Quelle. */
	public $geschlecht = '';

	/** @var int Jahrgang, 0 wenn die Quelle keinen nennt. */
	public $jahrgang = 0;

	/** @var string Vereinsfeld, roh. */
	public $verein = '';

	/** @var string Normalisiert auf 'HH:MM:SS'. Leer = nicht verwertbar. */
	public $zeit = '';

	/** @var string Die Zeit, wie die Quelle sie schrieb (Log: roh_zeit). */
	public $roh_zeit = '';

	/** @var string 'netto' | 'brutto'. */
	public $zeit_typ = '';

	/** @var string Gesamtplatz, roh. Nur für die Gesamtsieg-Erkennung (6.5.5). */
	public $platz = '';

	/** @var string Startnummer, roh. */
	public $startnummer = '';

	/** @var string Klassen-Code der Quelle, roh – nur zum Abgleich. */
	public $quelle_klasse = '';

	/**
	 * Als Array, für Transient und Log.
	 *
	 * @return array
	 */
	public function to_array() {
		return get_object_vars( $this );
	}

	/**
	 * Umkehrung von to_array() – der Parse-Transient hält Arrays.
	 *
	 * @param array $daten Zuvor mit to_array() erzeugt.
	 * @return LSG_BL_Ergebnis
	 */
	public static function from_array( array $daten ) {
		$e = new self();
		foreach ( $daten as $k => $v ) {
			if ( property_exists( $e, $k ) ) {
				$e->$k = $v;
			}
		}
		return $e;
	}
}
