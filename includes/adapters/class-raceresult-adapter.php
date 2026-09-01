<?php
/**
 * Adapter für my.raceresult.com – JSON-API, die bevorzugte Quelle.
 *
 * Ablauf (Plan 4.1):
 *   1) GET https://my.raceresult.com/{eventId}/results/config
 *          ?lang=de&noVisitor=1&sanitize=true
 *      → key, server, eventname, contests{}, Tab.Config.Lists[]
 *   2) GET https://{server}/{eventId}/results/list
 *          ?key={key}&listname={Name}&page=results&contest={id}&r=all&l=0
 *      → list.Fields[], data[][], DataFields[]
 *
 * ⚠ Zwei Fallstricke, beide verifiziert:
 *   - `key` rotiert → bei jedem Datenabruf frisch aus config holen, nie cachen.
 *   - `server` ist nicht my.raceresult.com, sondern z.B. my4.raceresult.com
 *     oder my-us-1.raceresult.com. Der Wert wechselt tatsächlich und muss
 *     aus config.server kommen – und er läuft trotzdem durch die Allowlist
 *     (Plan 6.10), weil er aus der Antwort eines Fremdservers stammt.
 *
 * ⚠ Korrektur gegenüber Plan 4.1: Die Ergebnislisten stehen NICHT unter
 *   config.lists, sondern unter config.Tab.Config.Lists (Prüfung 2026-09-01,
 *   Event 375768). Der Adapter liest beide Stellen, damit er auch dann noch
 *   trägt, wenn race result das Feld wieder umhängt.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LSG_BL_RaceResult_Adapter implements LSG_BL_Ergebnis_Quelle {

	/**
	 * Der HTTP-Getter. Wird injiziert, damit die Parser ohne Netz und ohne
	 * WordPress prüfbar bleiben (Plan, Abschnitt 5).
	 *
	 * @var callable
	 */
	private $get;

	/**
	 * @param callable|null $get function( string $url, string $adapter_cls ): string
	 */
	public function __construct( $get = null ) {
		$this->get = ( null !== $get ) ? $get : 'lsg_bl_http_get';
	}

	/* --------------------------------------------------------------------
	 * Identität und Erkennung
	 * ----------------------------------------------------------------- */

	/**
	 * @return string
	 */
	public static function key() {
		return 'raceresult';
	}

	/**
	 * @return string
	 */
	public static function label() {
		return 'race result';
	}

	/**
	 * @return string[]
	 */
	public static function hosts() {
		return array( 'raceresult.com', '*.raceresult.com' );
	}

	/**
	 * @param string $url Eingegebene URL.
	 * @return int
	 */
	public static function erkennt( $url ) {
		$teile = lsg_bl_parse_url( (string) $url );
		if ( empty( $teile['host'] ) ) {
			return 0;
		}
		$host = strtolower( $teile['host'] );
		if ( 'raceresult.com' !== $host && '.raceresult.com' !== substr( $host, -15 ) ) {
			return 0;
		}
		return ( '' !== self::event_id_aus_url( $url ) ) ? 90 : 40;
	}

	/**
	 * Erstes rein numerisches Pfadsegment = Event-ID.
	 *
	 * @param string $url Eingegebene URL.
	 * @return string Leer, wenn keine ID im Pfad steht.
	 */
	public static function event_id_aus_url( $url ) {
		$teile = lsg_bl_parse_url( (string) $url );
		$pfad  = isset( $teile['path'] ) ? $teile['path'] : '';
		foreach ( explode( '/', trim( $pfad, '/' ) ) as $seg ) {
			if ( '' !== $seg && ctype_digit( $seg ) ) {
				return $seg;
			}
		}
		return '';
	}

	/**
	 * Vorauswahl aus dem URL-Fragment lesen: `#2_B45FAB` → contest 2,
	 * Liste B45FAB.
	 *
	 * ⚠ Das Fragment erreicht den Server beim Laden einer Seite nicht – hier
	 * ist das unkritisch, weil die URL als Formularwert übertragen wird.
	 * Beim Einfügen aus der Adresszeile ist es enthalten.
	 *
	 * @param string $url Eingegebene URL.
	 * @return array{contest:string,list:string}
	 */
	public static function fragment_lesen( $url ) {
		$teile = lsg_bl_parse_url( (string) $url );
		$frag  = isset( $teile['fragment'] ) ? $teile['fragment'] : '';
		if ( preg_match( '/^([A-Za-z0-9]+)_([A-Za-z0-9]+)$/', $frag, $m ) ) {
			return array(
				'contest' => $m[1],
				'list'    => $m[2],
			);
		}
		return array(
			'contest' => '',
			'list'    => '',
		);
	}

	/* --------------------------------------------------------------------
	 * Parser – statisch, String rein, Struktur raus. Kein Netz, kein WP.
	 * ----------------------------------------------------------------- */

	/**
	 * Die config-Antwort auswerten.
	 *
	 * @param string $json Rohantwort von /results/config.
	 * @return array{key:string,server:string,eventname:string,contests:array,lists:array}
	 * @throws LSG_BL_Quelle_Exception Wenn die Antwort unbrauchbar ist.
	 */
	public static function parse_config( $json ) {
		$j = json_decode( (string) $json, true );
		if ( ! is_array( $j ) ) {
			throw new LSG_BL_Quelle_Exception(
				'Die Antwort von race result ist kein gültiges JSON.'
			);
		}

		$schluessel = isset( $j['key'] ) ? (string) $j['key'] : '';
		$server     = isset( $j['server'] ) ? (string) $j['server'] : '';

		if ( '' === $schluessel ) {
			throw new LSG_BL_Quelle_Exception(
				'Die Antwort von race result enthält keinen Zugriffsschlüssel (key).'
			);
		}

		$contests = array();
		if ( isset( $j['contests'] ) && is_array( $j['contests'] ) ) {
			foreach ( $j['contests'] as $id => $name ) {
				$contests[ (string) $id ] = (string) $name;
			}
			// Numerisch sortieren, sonst steht "11" vor "2".
			uksort(
				$contests,
				function ( $a, $b ) {
					if ( ctype_digit( (string) $a ) && ctype_digit( (string) $b ) ) {
						return (int) $a <=> (int) $b;
					}
					return strcmp( (string) $a, (string) $b );
				}
			);
		}

		// Listen: erst der heute gültige Ort, dann die Varianten.
		$roh = array();
		if ( isset( $j['Tab']['Config']['Lists'] ) && is_array( $j['Tab']['Config']['Lists'] ) ) {
			$roh = $j['Tab']['Config']['Lists'];
		} elseif ( isset( $j['TabConfig']['Lists'] ) && is_array( $j['TabConfig']['Lists'] ) ) {
			$roh = $j['TabConfig']['Lists'];
		} elseif ( isset( $j['lists'] ) && is_array( $j['lists'] ) ) {
			$roh = $j['lists'];
		}

		$lists = array();
		foreach ( $roh as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$name   = isset( $l['Name'] ) ? (string) $l['Name'] : '';
			$show   = isset( $l['ShowAs'] ) ? trim( (string) $l['ShowAs'] ) : '';
			$id     = isset( $l['ID'] ) ? (string) $l['ID'] : $name;
			$anzeig = ( '' !== $show ) ? $show : self::listenname_lesbar( $name );

			$lists[] = array(
				'id'            => $id,
				'name'          => $anzeig,
				'ref'           => $name,
				'contest'       => isset( $l['Contest'] ) ? (string) $l['Contest'] : '',
				'live'          => ! empty( $l['Live'] ),
				'gesamtwertung' => self::ist_gesamtwertung( $anzeig, $name ),
			);
		}

		return array(
			'key'       => $schluessel,
			'server'    => $server,
			'eventname' => isset( $j['eventname'] ) ? (string) $j['eventname'] : '',
			'contests'  => $contests,
			'lists'     => $lists,
		);
	}

	/**
	 * Aus '01.1_Ergebnisse|Zieleinlauf_Brutto' etwas Lesbares machen, falls
	 * ShowAs leer ist.
	 *
	 * @param string $name Technischer Listenname.
	 * @return string
	 */
	private static function listenname_lesbar( $name ) {
		$teil = strrchr( (string) $name, '|' );
		$teil = ( false !== $teil ) ? substr( $teil, 1 ) : (string) $name;
		return trim( str_replace( '_', ' ', $teil ) );
	}

	/**
	 * Ist das die Gesamtwertung? Nur dort ist Platz 1 ein Gesamtsieg und
	 * kein Klassensieg (Plan 6.5.5).
	 *
	 * Bei Unklarheit false – ein falsch gemeldeter Sieg wäre deutlich
	 * ärgerlicher als ein übersehener.
	 *
	 * @param string $anzeige Anzeigename (ShowAs).
	 * @param string $ref     Technischer Name.
	 * @return bool
	 */
	private static function ist_gesamtwertung( $anzeige, $ref ) {
		$text = lsg_bl_text_normalisieren( $anzeige . ' ' . $ref );
		if ( '' === $text ) {
			return false;
		}
		// Klassen- oder Geschlechtsfilter im Namen → keine Gesamtwertung.
		if ( preg_match( '/\b(ak|altersklasse|mw|m w|frauen|maenner|damen|herren|jugend|senioren)\b/', $text ) ) {
			return false;
		}
		return (bool) preg_match( '/\b(gesamt|gesamtergebnisliste|gesamtwertung|zieleinlauf|total)\b/', $text );
	}

	/**
	 * Die list-Antwort auswerten und auf das Zielformat normalisieren.
	 *
	 * Das Feld-Mapping läuft über DataFields, nicht über Spaltenpositionen:
	 * die Datenzeilen haben zwei zusätzliche führende Felder (BIB, ID) vor
	 * dem Platz, `list.Fields` kennt sie nicht. 8 Labels stehen also 9
	 * Werten gegenüber – wer nach Index rät, liest alles um zwei verschoben.
	 *
	 * @param string $json Rohantwort von /results/list.
	 * @return array{zeilen:LSG_BL_Ergebnis[],gelesen:int,verworfen:int,zeit_typ:string,warnungen:string[],listname:string}
	 * @throws LSG_BL_Quelle_Exception Wenn die Antwort unbrauchbar ist.
	 */
	public static function parse_liste( $json ) {
		$j = json_decode( (string) $json, true );
		if ( ! is_array( $j ) || ! isset( $j['data'] ) ) {
			throw new LSG_BL_Quelle_Exception(
				'Die Ergebnisliste von race result ist kein gültiges JSON oder enthält keine Daten.'
			);
		}

		$felder      = isset( $j['list']['Fields'] ) && is_array( $j['list']['Fields'] ) ? $j['list']['Fields'] : array();
		$data_felder = isset( $j['DataFields'] ) && is_array( $j['DataFields'] ) ? array_values( $j['DataFields'] ) : array();
		$spalten     = self::spalten_mappen( $felder, $data_felder );

		$i_platz  = self::spalte( $spalten, array( 'platz', 'rang', 'pos', 'place', 'rank' ) );
		$i_stn    = self::spalte( $spalten, array( 'stn', 'startnr', 'startnummer', 'bib', 'nr', 'nummer' ) );
		$i_name   = self::spalte( $spalten, array( 'name', 'teilnehmer', 'athlet', 'sportler' ) );
		$i_verein = self::spalte( $spalten, array( 'verein', 'club', 'team', 'mannschaft', 'verein ort' ) );
		$i_jg     = self::spalte( $spalten, array( 'jg', 'jahrgang', 'jahr', 'geburtsjahr', 'yob' ) );
		$i_ak     = self::spalte( $spalten, array( 'ak pl', 'ak', 'altersklasse', 'klasse', 'ak platz', 'ak rang' ) );
		$i_mw     = self::spalte( $spalten, array( 'mw pl', 'mw', 'm w', 'geschlecht', 'sex', 'mw platz' ) );

		// Nettozeit hat Vorrang. Erst wenn keines dieser Labels existiert,
		// wird die Bruttozeit genommen – und der Typ wird mitgeführt, sonst
		// vergleicht man später Netto gegen Brutto, ohne es zu merken.
		$zeit_typ = 'netto';
		$i_zeit   = self::spalte( $spalten, array( 'netto', 'nettozeit', 'netto zeit', 'net', 'net time', 'chip', 'chipzeit', 'chip time', 'zeit netto' ) );
		if ( null === $i_zeit ) {
			$zeit_typ = 'brutto';
			$i_zeit   = self::spalte( $spalten, array( 'zeit', 'brutto', 'bruttozeit', 'brutto zeit', 'gesamtzeit', 'endzeit', 'zielzeit', 'laufzeit', 'time', 'finish' ) );
		}

		$warnungen = array();
		if ( null === $i_name ) {
			throw new LSG_BL_Quelle_Exception(
				'In dieser Ergebnisliste ist keine Namensspalte zu finden – sie lässt sich nicht auswerten.'
			);
		}
		if ( null === $i_zeit ) {
			throw new LSG_BL_Quelle_Exception(
				'In dieser Ergebnisliste ist keine Zeitspalte zu finden – sie lässt sich nicht auswerten.'
			);
		}
		if ( null === $i_jg ) {
			$warnungen[] = 'Die Ergebnisliste nennt keinen Jahrgang. Ohne Jahrgang lässt sich kein Athlet zuordnen.';
		}
		if ( null === $i_verein ) {
			$warnungen[] = 'Die Ergebnisliste nennt keinen Verein. Der LSG-Filter kann so nicht greifen.';
		}

		$zeilen    = array();
		$gelesen   = 0;
		$verworfen = 0;

		foreach ( self::datenzeilen( $j['data'] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$gelesen;

			$hole = function ( $i ) use ( $row ) {
				if ( null === $i || ! array_key_exists( $i, $row ) ) {
					return '';
				}
				return trim( (string) $row[ $i ] );
			};

			$roh_zeit = $hole( $i_zeit );
			$zeit     = lsg_bl_zeit_normalisieren( $roh_zeit );
			if ( '' === $zeit ) {
				// DNF / DSQ / DNS / unlesbar → verwerfen und zählen.
				// Kein Rückfall auf den Zahlen-Fallback (Plan 6.5.1).
				++$verworfen;
				continue;
			}

			$teilnehmer = $hole( $i_name );
			$split      = lsg_bl_name_splitten( $teilnehmer );

			$ak_roh = $hole( $i_ak );
			$mw_roh = $hole( $i_mw );

			$geschlecht = lsg_bl_geschlecht_aus_klasse( $mw_roh );
			if ( '' === $geschlecht ) {
				$geschlecht = lsg_bl_geschlecht_aus_klasse( $ak_roh );
			}

			$jahrgang = 0;
			if ( preg_match( '/\d{4}/', $hole( $i_jg ), $m ) ) {
				$jahrgang = (int) $m[0];
			}

			$e                 = new LSG_BL_Ergebnis();
			$e->nachname       = $split['nachname'];
			$e->vorname        = $split['vorname'];
			$e->teilnehmer     = $teilnehmer;
			$e->namen_unsicher = $split['unsicher'];
			$e->geschlecht     = $geschlecht;
			$e->jahrgang       = $jahrgang;
			$e->verein         = $hole( $i_verein );
			$e->zeit           = $zeit;
			$e->roh_zeit       = $roh_zeit;
			$e->zeit_typ       = $zeit_typ;
			$e->platz          = rtrim( $hole( $i_platz ), ' .' );
			$e->startnummer    = $hole( $i_stn );
			$e->quelle_klasse  = ( '' !== $ak_roh ) ? $ak_roh : $mw_roh;

			$zeilen[] = $e;
		}

		if ( $verworfen > 0 ) {
			$warnungen[] = sprintf(
				'%d Zeile(n) ohne verwertbare Zeit (DNF/DSQ/DNS) wurden übergangen.',
				$verworfen
			);
		}

		return array(
			'zeilen'    => $zeilen,
			'gelesen'   => $gelesen,
			'verworfen' => $verworfen,
			'zeit_typ'  => $zeit_typ,
			'warnungen' => $warnungen,
			'listname'  => isset( $j['list']['ListName'] ) ? (string) $j['list']['ListName'] : '',
		);
	}

	/**
	 * `data` ist meist ein Array von Arrays – bei gruppierten Listen (z.B.
	 * der AK-Liste) aber ein Objekt, dessen Werte je eine Gruppe halten.
	 * Beide Formen werden zu einer flachen Zeilenliste.
	 *
	 * @param mixed $data Der data-Zweig der Antwort.
	 * @return array<int,array>
	 */
	private static function datenzeilen( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		$erste = reset( $data );
		if ( is_array( $erste ) && isset( $erste[0] ) && is_array( $erste[0] ) ) {
			// Gruppiert: { "M30": [ [...], [...] ], … }
			$flach = array();
			foreach ( $data as $gruppe ) {
				if ( is_array( $gruppe ) ) {
					foreach ( $gruppe as $row ) {
						$flach[] = $row;
					}
				}
			}
			return $flach;
		}
		return array_values( $data );
	}

	/**
	 * Label → Spaltenindex in den Datenzeilen.
	 *
	 * Aufgelöst wird über die Expression aus list.Fields, die in DataFields
	 * wieder auftaucht. Wo das scheitert, greift der Positionsversatz
	 * (count(DataFields) − count(Fields)) – bei race result heute 2.
	 *
	 * @param array $felder      list.Fields.
	 * @param array $data_felder DataFields.
	 * @return array<string,int> normalisiertes Label => Index
	 */
	private static function spalten_mappen( array $felder, array $data_felder ) {
		$versatz = count( $data_felder ) - count( $felder );
		$map     = array();

		foreach ( array_values( $felder ) as $i => $f ) {
			$expr = isset( $f['Expression'] ) ? (string) $f['Expression'] : '';
			$pos  = null;

			if ( $versatz >= 0 && isset( $data_felder[ $i + $versatz ] ) && $data_felder[ $i + $versatz ] === $expr ) {
				$pos = $i + $versatz;
			} else {
				$gefunden = array_search( $expr, $data_felder, true );
				if ( false !== $gefunden ) {
					$pos = (int) $gefunden;
				} elseif ( $versatz >= 0 && isset( $data_felder[ $i + $versatz ] ) ) {
					$pos = $i + $versatz;
				}
			}

			if ( null === $pos ) {
				continue;
			}

			$label = lsg_bl_text_normalisieren( isset( $f['Label'] ) ? $f['Label'] : '' );
			if ( '' === $label ) {
				continue;
			}
			if ( ! isset( $map[ $label ] ) ) {
				$map[ $label ] = $pos;
			}
		}

		return $map;
	}

	/**
	 * Ersten passenden Spaltenindex zu einer Liste von Label-Kandidaten
	 * suchen: erst exakt, dann als Präfix („zeit" trifft „zeit brutto").
	 *
	 * @param array<string,int> $spalten    Label → Index.
	 * @param string[]          $kandidaten Normalisierte Labels.
	 * @return int|null
	 */
	private static function spalte( array $spalten, array $kandidaten ) {
		foreach ( $kandidaten as $k ) {
			if ( isset( $spalten[ $k ] ) ) {
				return $spalten[ $k ];
			}
		}
		foreach ( $kandidaten as $k ) {
			foreach ( $spalten as $label => $i ) {
				if ( 0 === strpos( $label, $k ) ) {
					return $i;
				}
			}
		}
		return null;
	}

	/* --------------------------------------------------------------------
	 * Discovery und Datenabruf – hier, und nur hier, wird abgerufen.
	 * ----------------------------------------------------------------- */

	/**
	 * @param string $url Eingegebene URL.
	 * @return LSG_BL_Event_Ref
	 * @throws LSG_BL_Quelle_Exception Wenn keine Event-ID in der URL steht.
	 */
	public function eventLesen( $url ) {
		$event_id = self::event_id_aus_url( $url );
		if ( '' === $event_id ) {
			throw new LSG_BL_Quelle_Exception(
				'In dieser Adresse steckt keine Veranstaltungs-Nummer. '
				. 'Erwartet wird etwas wie https://my.raceresult.com/375768/'
			);
		}

		$ref       = new LSG_BL_Event_Ref( self::key(), $event_id, (string) $url );
		$fragment  = self::fragment_lesen( $url );
		$ref->contest_id = $fragment['contest'];
		$ref->list_id    = $fragment['list'];

		$config           = $this->config( $ref );
		$ref->event_name  = $config['eventname'];

		return $ref;
	}

	/**
	 * config abrufen und auswerten. Immer frisch – der `key` rotiert.
	 *
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return array Ergebnis von parse_config().
	 */
	private function config( LSG_BL_Event_Ref $ref ) {
		$url  = 'https://my.raceresult.com/' . rawurlencode( $ref->event_id )
			. '/results/config?lang=de&noVisitor=1&sanitize=true';
		$json = call_user_func( $this->get, $url, __CLASS__ );

		$config = self::parse_config( $json );

		// Der Server aus der Antwort wechselt (my4, my-us-1, …) und wird
		// deshalb nicht angenommen, sondern geprüft – siehe laden().
		$ref->meta['config'] = $config;

		return $config;
	}

	/**
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return LSG_BL_Wettbewerb[]
	 */
	public function wettbewerbe( LSG_BL_Event_Ref $ref ) {
		$config = isset( $ref->meta['config'] ) ? $ref->meta['config'] : $this->config( $ref );

		$out = array();
		foreach ( $config['contests'] as $id => $name ) {
			$out[] = new LSG_BL_Wettbewerb( $id, $name );
		}
		return $out;
	}

	/**
	 * Listen eines Wettbewerbs.
	 *
	 * Einträge mit Contest 0 (bzw. leerem Feld) gelten für ALLE Wettbewerbe
	 * und werden jedem zugeschlagen (Plan 6.4).
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @return LSG_BL_Liste[]
	 */
	public function listen( LSG_BL_Event_Ref $ref, $contest_id ) {
		$config     = isset( $ref->meta['config'] ) ? $ref->meta['config'] : $this->config( $ref );
		$contest_id = (string) $contest_id;

		$out = array();
		foreach ( $config['lists'] as $l ) {
			$c = (string) $l['contest'];
			if ( '' !== $c && '0' !== $c && $c !== $contest_id ) {
				continue;
			}
			$liste                = new LSG_BL_Liste( $l['id'], $l['name'], $l['ref'] );
			$liste->live          = (bool) $l['live'];
			$liste->gesamtwertung = (bool) $l['gesamtwertung'];
			$out[]                = $liste;
		}
		return $out;
	}

	/**
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @param string|null      $list_id    Listen-ID.
	 * @return LSG_BL_Ergebnis[]
	 * @throws LSG_BL_Quelle_Exception Bei unbekannter Liste oder fremdem Host.
	 */
	public function laden( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null ) {
		// Immer frisch: der key rotiert, und der server wechselt.
		$config = $this->config( $ref );

		$liste = null;
		foreach ( $this->listen( $ref, $contest_id ) as $l ) {
			if ( null === $list_id || '' === $list_id || $l->id === $list_id ) {
				$liste = $l;
				break;
			}
		}
		if ( null === $liste ) {
			throw new LSG_BL_Quelle_Exception(
				'Diese Ergebnisliste gibt es bei der Quelle nicht (mehr). '
				. 'Bitte die Auswahl neu laden.'
			);
		}

		if ( '' === $config['server'] ) {
			throw new LSG_BL_Quelle_Exception(
				'race result nennt keinen Datenserver – der Abruf wird abgebrochen.'
			);
		}

		$url = 'https://' . $config['server'] . '/' . rawurlencode( $ref->event_id ) . '/results/list'
			. '?key=' . rawurlencode( $config['key'] )
			. '&listname=' . rawurlencode( $liste->ref )
			. '&page=results'
			. '&contest=' . rawurlencode( (string) $contest_id )
			. '&r=all&l=0';

		$json = call_user_func( $this->get, $url, __CLASS__ );
		$p1   = self::parse_liste( $json );

		// Kennzahlen für den Trichter und das Log am Kontext ablegen.
		$ref->meta['p1'] = array(
			'gelesen'   => $p1['gelesen'],
			'verworfen' => $p1['verworfen'],
			'zeit_typ'  => $p1['zeit_typ'],
			'warnungen' => $p1['warnungen'],
			'listname'  => $p1['listname'],
		);

		return $p1['zeilen'];
	}

	/**
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @param string|null      $list_id    Listen-ID.
	 * @return string
	 */
	public function quelleUrl( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null ) {
		$url = 'https://my.raceresult.com/' . rawurlencode( $ref->event_id ) . '/';
		if ( '' !== (string) $contest_id ) {
			$url .= '#' . rawurlencode( (string) $contest_id );
			if ( ! empty( $list_id ) ) {
				$url .= '_' . rawurlencode( (string) $list_id );
			}
		}
		return $url;
	}

	/**
	 * Veranstaltungsdatum – bei race result gibt es keines.
	 *
	 * Geprüft am 2026-08-27 und erneut am 2026-09-01: die config-Antwort
	 * enthält kein Datumsfeld. Was danach aussieht, ist es nicht:
	 *   Tab.ActiveFrom  = Gültigkeit der Ergebnis-Ansicht (hier 2022!)
	 *   Time            = Zählwert der Zeitmessung, kein Zeitstempel
	 *   EventOver       = nur „Veranstaltung ist vorbei"
	 *
	 * Bleibt Stufe 2/3: ein Datum oder eine Jahreszahl im Eventnamen.
	 *
	 * ⚠ Nur der EVENT-Name wird gelesen, nicht der Wettbewerbsname: bei race
	 * result stehen dort Jahrgangsgrenzen („Bambini 500m (<2019)",
	 * „Kids 1000m (2015/2016)"), die ein zu gieriger Parser prompt als
	 * Veranstaltungsjahr missverstünde.
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key (hier ungenutzt).
	 * @return array{datum:string,quelle:string,hinweis:string}
	 */
	public function datum( LSG_BL_Event_Ref $ref, $contest_id = '' ) {
		$treffer = lsg_bl_datum_aus_text( $ref->event_name );

		if ( '' !== $treffer['datum'] || '' !== $treffer['jahr'] ) {
			return array(
				'datum'   => $treffer['datum'],
				'quelle'  => ( '' !== $treffer['datum'] ) ? 'name' : 'jahr',
				'hinweis' => ( '' !== $treffer['datum'] )
					? 'aus dem Namen gelesen'
					: 'nur das Jahr erkannt – Tag und Monat ergänzen',
			);
		}

		return array(
			'datum'   => '',
			'quelle'  => '',
			'hinweis' => 'Die Quelle nennt kein Datum – bitte eintragen.',
		);
	}
}
