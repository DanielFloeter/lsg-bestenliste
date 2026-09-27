<?php
/**
 * Adapter für racepedia.de – HTML, die dritte Quelle.
 *
 * Ablauf:
 *   1) GET https://{event}.racepedia.de/ergebnisse
 *      → Veranstaltungskopf (Name, Datum), a.competition je Wettbewerb
 *   2) GET https://{event}.racepedia.de/ergebnisse/{contest}/all/{all|m|w}
 *      → table.result_table, vollständig im HTML (DataTables blättert erst
 *        im Browser)
 *
 * ⚠ Fallstricke, am 2026-09-27 gegen „topiblueten-lauf-staffort-2026" und
 *   sieben weitere Veranstaltungen geprüft:
 *
 *   1) Die Veranstaltung steckt nicht im Pfad, sondern in der SUBDOMAIN.
 *      Eine Nummer gibt es nicht; die Event-ID ist der Subdomain-Name,
 *      kleingeschrieben (Links auf racepedia360.de schreiben
 *      „Forstsportlauf-2026" gemischt).
 *
 *   2) Die Tabelle beginnt mit einer versteckten Spalte (`display:none`,
 *      Kopf leer), die DataTables zum Gruppieren nach AK braucht. Gelesen
 *      wird deshalb über die Position des Spaltenkopfs, nicht über eine
 *      angenommene Reihenfolge – und der leere Kopf bekommt keinen Namen.
 *
 *   3) Die Spalten wechseln je Veranstaltung. „AK" und „AK Platz" fehlen
 *      oft (Burglauf, Salacher Löwenlauf); eine Jahrgangsspalte hat keine
 *      der geprüften Listen.
 *
 *   4) Vor dem Lauf liefert die Seite eine vollständige, aber leere
 *      Tabelle – Kopf ja, Zeilen nein. Das ist kein Fehler der Quelle,
 *      sondern „noch keine Ergebnisse", und wird so gemeldet.
 *
 * Das Datum steht – anders als bei race result und runtix – im Kopf jeder
 * Seite: `li.kalender` = „27. SEPTEMBER 2026" bzw. „Samstag 05. SEPTEMBER
 * 2026". Siehe datum().
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LSG_BL_Racepedia_Adapter implements LSG_BL_Ergebnis_Quelle {

	/**
	 * Der HTTP-Getter, injiziert – damit die Parser ohne Netz und ohne
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
		return 'racepedia';
	}

	/**
	 * @return string
	 */
	public static function label() {
		return 'racepedia';
	}

	/**
	 * @return string[]
	 */
	public static function hosts() {
		return array( '*.racepedia.de' );
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
		if ( 'racepedia.de' !== $host && '.racepedia.de' !== substr( $host, -13 ) ) {
			return 0;
		}
		return ( '' !== self::event_id_aus_url( $url ) ) ? 90 : 40;
	}

	/**
	 * Eine racepedia-Adresse in ihre Bestandteile zerlegen.
	 *
	 * Erkannt werden:
	 *   https://{event}.racepedia.de/                      → event
	 *   https://{event}.racepedia.de/ergebnisse            → event
	 *   https://{event}.racepedia.de/ergebnisse/8112       → contest 8112
	 *   https://{event}.racepedia.de/ergebnisse/8112/all/w → contest 8112, Liste w
	 *   https://{event}.racepedia.de/ergebnisse/8112/M30   → contest 8112, AK-Filter
	 *                                                       (keine Liste, s. listen())
	 *
	 * @param string $url Eingegebene URL.
	 * @return array{event_id:string,contest:string,liste:string}
	 */
	public static function url_zerlegen( $url ) {
		$out = array(
			'event_id' => '',
			'contest'  => '',
			'liste'    => '',
		);

		$teile = lsg_bl_parse_url( (string) $url );
		$host  = isset( $teile['host'] ) ? strtolower( $teile['host'] ) : '';

		// Genau eine Ebene unter racepedia.de. „www" ist das Portal, keine
		// Veranstaltung; tiefere Subdomains gibt es nicht.
		if ( ! preg_match( '/^([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)\.racepedia\.de$/', $host, $m ) ) {
			return $out;
		}
		if ( 'www' === $m[1] ) {
			return $out;
		}
		$out['event_id'] = $m[1];

		$pfad = isset( $teile['path'] ) ? $teile['path'] : '';
		$seg  = array_values(
			array_filter(
				explode( '/', $pfad ),
				function ( $s ) {
					return '' !== $s;
				}
			)
		);

		if ( ! isset( $seg[0] ) || 'ergebnisse' !== strtolower( $seg[0] ) ) {
			return $out;
		}
		if ( ! isset( $seg[1] ) || ! ctype_digit( $seg[1] ) ) {
			return $out;
		}
		$out['contest'] = $seg[1];

		// /all/{all|m|w} ist die Geschlechtsauswahl. Alles andere an dieser
		// Stelle ist ein AK-Filter und wird nicht als Liste übernommen.
		if ( isset( $seg[2], $seg[3] ) && 'all' === strtolower( $seg[2] ) ) {
			$g = strtolower( $seg[3] );
			if ( isset( self::listentypen()[ $g ] ) ) {
				$out['liste'] = $g;
			}
		}
		return $out;
	}

	/**
	 * Event-ID aus der URL – für den Discovery-Cache-Schlüssel, der ohne
	 * Fremdabruf gebildet werden muss (lsg_bl_discovery()).
	 *
	 * @param string $url Eingegebene URL.
	 * @return string Leer, wenn die Adresse keine Veranstaltung meint.
	 */
	public static function event_id_aus_url( $url ) {
		$teil = self::url_zerlegen( $url );
		return $teil['event_id'];
	}

	/**
	 * Vorauswahl aus der URL lesen – bei racepedia steht sie im Pfad.
	 *
	 * @param string $url Eingegebene URL.
	 * @return array{contest:string,list:string}
	 */
	public static function vorauswahl_aus_url( $url ) {
		$teil = self::url_zerlegen( $url );
		return array(
			'contest' => $teil['contest'],
			'list'    => $teil['liste'],
		);
	}

	/**
	 * Adressen bauen – eine einzige Stelle.
	 *
	 * @param string $event_id   Subdomain.
	 * @param string $contest_id Contest-ID oder ''.
	 * @param string $liste      'all' | 'm' | 'w' oder ''.
	 * @return string
	 */
	public static function url_bauen( $event_id, $contest_id = '', $liste = '' ) {
		$url = 'https://' . strtolower( (string) $event_id ) . '.racepedia.de/ergebnisse';

		if ( '' !== (string) $contest_id ) {
			$g    = ( '' === (string) $liste ) ? 'all' : (string) $liste;
			$url .= '/' . rawurlencode( (string) $contest_id ) . '/all/' . rawurlencode( $g );
		}
		return $url;
	}

	/**
	 * Die Listentypen. Sie hängen nicht am Wettbewerb – racepedia bietet
	 * für jeden dieselbe Geschlechtsauswahl.
	 *
	 * @return array<string,string> id => Anzeigename
	 */
	private static function listentypen() {
		return array(
			'all' => 'Gesamt',
			'm'   => 'nur männlich',
			'w'   => 'nur weiblich',
		);
	}

	/* --------------------------------------------------------------------
	 * DOM-Hilfen
	 * ----------------------------------------------------------------- */

	/**
	 * HTML in ein DOMXPath, ohne die üblichen Warnungen.
	 *
	 * ⚠ Der Meta-Charset-Vorspann ist nötig: libxml nimmt ohne Angabe
	 * Latin-1 an und macht aus „Topiblüten" „TopiblÃ¼ten".
	 *
	 * @param string $html Rohantwort.
	 * @return DOMXPath
	 * @throws LSG_BL_Quelle_Exception Wenn nichts Parsebares ankommt.
	 */
	private static function xpath( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			throw new LSG_BL_Quelle_Exception(
				'racepedia hat eine leere Seite geliefert.'
			);
		}

		$doc = new DOMDocument();
		$alt = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $alt );

		return new DOMXPath( $doc );
	}

	/**
	 * Trägt ein Element die genannte CSS-Klasse – exakt, nicht als Teilwort?
	 *
	 * @param DOMElement|DOMNode $el     Element.
	 * @param string             $klasse Gesuchte Klasse.
	 * @return bool
	 */
	private static function hat_klasse( $el, $klasse ) {
		if ( ! ( $el instanceof DOMElement ) ) {
			return false;
		}
		$fel = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $fel ) && in_array( $klasse, $fel, true );
	}

	/**
	 * Erstes Element eines Tags mit der Klasse.
	 *
	 * @param DOMXPath     $xp     XPath.
	 * @param string       $tag    Tagname.
	 * @param string       $klasse CSS-Klasse.
	 * @param DOMNode|null $in     Kontextknoten.
	 * @return DOMElement|null
	 */
	private static function finde( DOMXPath $xp, $tag, $klasse, $in = null ) {
		$treffer = ( null === $in ) ? $xp->query( '//' . $tag ) : $xp->query( './/' . $tag, $in );
		foreach ( $treffer as $el ) {
			if ( self::hat_klasse( $el, $klasse ) ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * Textinhalt eines Knotens, zusammengefaltet.
	 *
	 * @param DOMNode|null $n Knoten.
	 * @return string
	 */
	private static function text( $n ) {
		if ( null === $n ) {
			return '';
		}
		$t = preg_replace( '/\s+/u', ' ', (string) $n->textContent );
		return trim( html_entity_decode( $t, ENT_QUOTES, 'UTF-8' ) );
	}

	/* --------------------------------------------------------------------
	 * Parser – statisch, String rein, Struktur raus. Kein Netz, kein WP.
	 * ----------------------------------------------------------------- */

	/**
	 * Die Rahmendaten: Eventname, Datum, Wettbewerbe.
	 *
	 * Gelesen wird von der Übersicht /ergebnisse (a.competition) und –
	 * falls jemand die Listenseite eingibt – ebenso aus deren
	 * `select[name=select_competition]`.
	 *
	 * @param string $html Rohantwort von /ergebnisse[/...].
	 * @return array{eventname:string,datum_text:string,ort:string,contests:array}
	 * @throws LSG_BL_Quelle_Exception Wenn die Seite nicht auswertbar ist.
	 */
	public static function parse_rahmen( $html ) {
		$xp = self::xpath( $html );

		// Der Name steht dreimal im Kopf; das alt des Logos ist der einzige
		// ohne „Racepedia - "-Präfix.
		$eventname = '';
		$logo      = self::finde( $xp, 'div', 'event_logo' );
		if ( null !== $logo ) {
			$img = $xp->query( './/img', $logo )->item( 0 );
			if ( $img instanceof DOMElement ) {
				$eventname = trim( $img->getAttribute( 'alt' ) );
			}
		}
		if ( '' === $eventname ) {
			$eventname = self::text( $xp->query( '//title' )->item( 0 ) );
			$eventname = trim( preg_replace( '/^\s*Racepedia\s*[-–]\s*/iu', '', $eventname ) );
		}

		$infos      = self::finde( $xp, 'div', 'event_infos' );
		$kalender   = ( null !== $infos ) ? self::finde( $xp, 'li', 'kalender', $infos ) : null;
		$standort   = ( null !== $infos ) ? self::finde( $xp, 'li', 'standort', $infos ) : null;
		$datum_text = self::text( $kalender );
		$ort        = self::text( $standort );

		$contests = array();
		$gesehen  = array();
		foreach ( $xp->query( '//a' ) as $a ) {
			if ( ! self::hat_klasse( $a, 'competition' ) ) {
				continue;
			}
			if ( ! preg_match( '#/ergebnisse/(\d+)#', $a->getAttribute( 'href' ), $m ) || isset( $gesehen[ $m[1] ] ) ) {
				continue;
			}
			$name = self::text( $xp->query( './/h2', $a )->item( 0 ) );
			if ( '' === $name ) {
				$name = self::text( $a );
			}
			$gesehen[ $m[1] ] = true;
			$contests[]       = array(
				'id'   => (string) $m[1],
				'name' => $name,
			);
		}
		foreach ( $xp->query( '//select[@name="select_competition"]/option' ) as $opt ) {
			if ( ! preg_match( '#/ergebnisse/(\d+)#', $opt->getAttribute( 'value' ), $m ) || isset( $gesehen[ $m[1] ] ) ) {
				continue;
			}
			$gesehen[ $m[1] ] = true;
			$contests[]       = array(
				'id'   => (string) $m[1],
				'name' => self::text( $opt ),
			);
		}

		if ( empty( $contests ) ) {
			throw new LSG_BL_Quelle_Exception(
				'Auf dieser racepedia-Seite sind keine Wettbewerbe zu finden. '
				. 'Erwartet wird die Ergebnisseite einer Veranstaltung, etwa '
				. 'https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse'
			);
		}

		return array(
			'eventname'  => $eventname,
			'datum_text' => $datum_text,
			'ort'        => $ort,
			'contests'   => $contests,
		);
	}

	/**
	 * Die Ergebnistabelle auswerten.
	 *
	 * Gelesen wird über die Spaltenköpfe (Fallstrick 2 und 3), siehe
	 * spalte().
	 *
	 * @param string $html Rohantwort von /ergebnisse/{contest}/all/{g}.
	 * @return array{zeilen:LSG_BL_Ergebnis[],gelesen:int,verworfen:int,zeit_typ:string,warnungen:string[],listname:string}
	 * @throws LSG_BL_Quelle_Exception Wenn keine Tabelle da ist.
	 */
	public static function parse_liste( $html ) {
		$xp      = self::xpath( $html );
		$tabelle = self::finde( $xp, 'table', 'result_table' );
		if ( null === $tabelle ) {
			throw new LSG_BL_Quelle_Exception(
				'Auf dieser racepedia-Seite ist keine Ergebnistabelle zu finden. '
				. 'Ist ein Wettbewerb gewählt?'
			);
		}

		// Spaltenkopf → Position. Die versteckte erste Spalte hat einen
		// leeren Kopf und zählt nur für die Position mit.
		$spalten = array();
		$kopf    = $xp->query( './/thead//tr', $tabelle )->item( 0 );
		if ( null !== $kopf ) {
			$i = 0;
			foreach ( $kopf->childNodes as $th ) {
				if ( ! ( $th instanceof DOMElement ) || ! in_array( strtolower( $th->nodeName ), array( 'th', 'td' ), true ) ) {
					continue;
				}
				$label = lsg_bl_text_normalisieren( self::text( $th ) );
				if ( '' !== $label && ! isset( $spalten[ $label ] ) ) {
					$spalten[ $label ] = $i;
				}
				++$i;
			}
		}

		$i_platz  = self::spalte( $spalten, array( 'platz', 'gesamtplatz', 'rang', 'pl' ) );
		$i_stn    = self::spalte( $spalten, array( 'start nr', 'startnr', 'startnummer', 'stnr', 'nr' ) );
		$i_name   = self::spalte( $spalten, array( 'name', 'teilnehmer' ) );
		$i_verein = self::spalte( $spalten, array( 'team', 'verein', 'club', 'mannschaft' ) );
		$i_jg     = self::spalte( $spalten, array( 'jg', 'jahrgang', 'geburtsjahr' ) );
		$i_ak     = self::spalte( $spalten, array( 'ak', 'altersklasse', 'klasse' ) );
		$i_mw     = self::spalte( $spalten, array( 'm w', 'geschlecht' ) );

		// „Gesamtzeit" ist die Zeit ab Startschuss. Eine Nettospalte hat
		// keine der geprüften Listen; sollte eine auftauchen, hat sie
		// Vorrang – und der Typ wird mitgeführt.
		$zeit_typ = 'netto';
		$i_zeit   = self::spalte( $spalten, array( 'netto', 'nettozeit', 'chipzeit' ) );
		if ( null === $i_zeit ) {
			$zeit_typ = 'brutto';
			$i_zeit   = self::spalte( $spalten, array( 'gesamtzeit', 'zeit', 'bruttozeit', 'endzeit', 'zielzeit' ) );
		}

		if ( null === $i_name ) {
			throw new LSG_BL_Quelle_Exception(
				'In dieser racepedia-Liste ist keine Namensspalte zu finden – sie lässt sich nicht auswerten.'
			);
		}
		if ( null === $i_zeit ) {
			throw new LSG_BL_Quelle_Exception(
				'In dieser racepedia-Liste ist keine Zeitspalte zu finden – sie lässt sich nicht auswerten.'
			);
		}

		$zeilen    = array();
		$gelesen   = 0;
		$verworfen = 0;

		foreach ( $xp->query( './/tbody/tr', $tabelle ) as $tr ) {
			$zellen = array();
			foreach ( $tr->childNodes as $td ) {
				if ( $td instanceof DOMElement && 'td' === strtolower( $td->nodeName ) ) {
					$zellen[] = self::text( $td );
				}
			}
			if ( empty( $zellen ) ) {
				continue;
			}
			++$gelesen;

			$hole = function ( $i ) use ( $zellen ) {
				return ( null !== $i && isset( $zellen[ $i ] ) ) ? $zellen[ $i ] : '';
			};

			$roh_zeit = $hole( $i_zeit );
			$zeit     = lsg_bl_zeit_normalisieren( $roh_zeit );
			if ( '' === $zeit ) {
				// DNF / DSQ / leer → verwerfen und zählen, nicht raten.
				++$verworfen;
				continue;
			}

			$teilnehmer = $hole( $i_name );
			$split      = lsg_bl_name_splitten( $teilnehmer );
			$ak_roh     = $hole( $i_ak );

			// Die m/w-Spalte ist die verlässlichere Angabe; die AK nur, wenn
			// sie fehlt.
			$geschlecht = lsg_bl_geschlecht_aus_klasse( $hole( $i_mw ) );
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
			$e->quelle_klasse  = $ak_roh;

			$zeilen[] = $e;
		}

		if ( 0 === $gelesen ) {
			// Fallstrick 4: vor dem Lauf ist die Tabelle da, aber leer.
			throw new LSG_BL_Quelle_Exception(
				'Diese racepedia-Liste enthält (noch) keine Ergebnisse.'
			);
		}

		$warnungen = array();
		if ( null === $i_jg ) {
			// Kein Jahrgang ist bei racepedia der Regelfall. Solange die
			// Liste eine Altersklasse führt, arbeitet P3 mit deren
			// Jahrgangsband weiter; ohne beides ist die Zeile nicht
			// zuzuordnen.
			$warnungen[] = ( null !== $i_ak )
				? 'Diese racepedia-Liste nennt keinen Jahrgang. Die Zuordnung stützt sich deshalb auf das Jahrgangsband der Altersklasse – wo deren Schema unbekannt ist, bleibt die Zeile offen.'
				: 'Diese racepedia-Liste nennt weder Jahrgang noch Altersklasse. So lässt sich kein Athlet zuordnen.';
		}
		if ( null === $i_verein ) {
			$warnungen[] = 'Diese racepedia-Liste nennt keinen Verein. Der LSG-Filter kann so nicht greifen.';
		}
		if ( null === $i_platz ) {
			$warnungen[] = 'Diese Liste nennt keinen Gesamtplatz – ein Gesamtsieg wird daraus nicht erkannt.';
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
			'listname'  => '',
		);
	}

	/**
	 * Ersten passenden Spaltenindex suchen – nur exakt.
	 *
	 * ⚠ Kein Präfix-Vergleich wie bei race result: wo die Spalte „AK"
	 * fehlt, träfe „ak" sonst „AK Platz", und die Klasse wäre eine Zahl.
	 * racepedia schreibt seine Köpfe ohnehin immer gleich.
	 *
	 * @param array<string,int> $spalten    Normalisiertes Label → Index.
	 * @param string[]          $kandidaten Normalisierte Labels.
	 * @return int|null
	 */
	private static function spalte( array $spalten, array $kandidaten ) {
		foreach ( $kandidaten as $k ) {
			if ( isset( $spalten[ $k ] ) ) {
				return $spalten[ $k ];
			}
		}
		return null;
	}

	/* --------------------------------------------------------------------
	 * Schnittstelle
	 * ----------------------------------------------------------------- */

	/**
	 * @param string $url Eingegebene URL.
	 * @return LSG_BL_Event_Ref
	 * @throws LSG_BL_Quelle_Exception Wenn die Adresse keine Veranstaltung meint.
	 */
	public function eventLesen( $url ) {
		$teil = self::url_zerlegen( $url );
		if ( '' === $teil['event_id'] ) {
			throw new LSG_BL_Quelle_Exception(
				'Diese Adresse zeigt auf keine racepedia-Veranstaltung. '
				. 'Erwartet wird etwas wie https://topiblueten-lauf-staffort-2026.racepedia.de/ergebnisse'
			);
		}

		$ref             = new LSG_BL_Event_Ref( self::key(), $teil['event_id'], (string) $url );
		$ref->contest_id = $teil['contest'];
		$ref->list_id    = $teil['liste'];

		$rahmen          = $this->rahmen( $ref );
		$ref->event_name = $rahmen['eventname'];

		return $ref;
	}

	/**
	 * Die Übersichtsseite holen und auswerten. Einmal je Request.
	 *
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return array Ergebnis von parse_rahmen().
	 */
	private function rahmen( LSG_BL_Event_Ref $ref ) {
		if ( isset( $ref->meta['rahmen'] ) ) {
			return $ref->meta['rahmen'];
		}

		$html = call_user_func( $this->get, self::url_bauen( $ref->event_id ), __CLASS__ );

		$rahmen              = self::parse_rahmen( $html );
		$ref->meta['rahmen'] = $rahmen;

		return $rahmen;
	}

	/**
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return LSG_BL_Wettbewerb[]
	 */
	public function wettbewerbe( LSG_BL_Event_Ref $ref ) {
		$out = array();
		foreach ( $this->rahmen( $ref )['contests'] as $c ) {
			$out[] = new LSG_BL_Wettbewerb( $c['id'], $c['name'] );
		}
		return $out;
	}

	/**
	 * Gesamt, männlich, weiblich – für jeden Wettbewerb dieselben.
	 *
	 * Die AK-Filter (/ergebnisse/8112/M30) werden bewusst nicht angeboten:
	 * sie sind Ausschnitte der Gesamtliste, die ohnehin jede Zeile samt AK
	 * führt. Nur `all` ist die Gesamtwertung (Plan 6.5.5).
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key (hier ungenutzt).
	 * @return LSG_BL_Liste[]
	 */
	public function listen( LSG_BL_Event_Ref $ref, $contest_id ) {
		$out = array();
		foreach ( self::listentypen() as $id => $name ) {
			$liste                = new LSG_BL_Liste( $id, $name, $id );
			$liste->gesamtwertung = ( 'all' === $id );
			$out[]                = $liste;
		}
		return $out;
	}

	/**
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @param string|null      $list_id    'all' | 'm' | 'w'.
	 * @return LSG_BL_Ergebnis[]
	 * @throws LSG_BL_Quelle_Exception Wenn der Wettbewerb fehlt.
	 */
	public function laden( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null ) {
		$contest_id = (string) $contest_id;
		if ( '' === $contest_id ) {
			throw new LSG_BL_Quelle_Exception(
				'Ohne Wettbewerb lässt sich bei racepedia keine Liste abrufen.'
			);
		}

		$liste = ( null === $list_id || '' === $list_id ) ? 'all' : (string) $list_id;
		if ( ! isset( self::listentypen()[ $liste ] ) ) {
			throw new LSG_BL_Quelle_Exception(
				'Diese Ergebnisliste gibt es bei racepedia nicht. Bitte die Auswahl neu laden.'
			);
		}

		$html = call_user_func( $this->get, self::url_bauen( $ref->event_id, $contest_id, $liste ), __CLASS__ );
		$p1   = self::parse_liste( $html );

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
	 * @param string|null      $list_id    Listentyp.
	 * @return string
	 */
	public function quelleUrl( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null ) {
		return self::url_bauen( $ref->event_id, (string) $contest_id, (string) $list_id );
	}

	/**
	 * Veranstaltungsdatum – steht bei racepedia im Kopf jeder Seite.
	 *
	 * Die Übersicht wurde für eventLesen() ohnehin geholt; es kostet also
	 * keinen weiteren Abruf. Erst wenn dort nichts Lesbares steht, bleibt
	 * der Eventname („…-2026" in der Subdomain zählt bewusst nicht: das ist
	 * die Adresse, keine Angabe der Quelle).
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key (hier ungenutzt).
	 * @return array{datum:string,quelle:string,hinweis:string}
	 */
	public function datum( LSG_BL_Event_Ref $ref, $contest_id = '' ) {
		try {
			$rahmen = $this->rahmen( $ref );
		} catch ( LSG_BL_Quelle_Exception $e ) {
			$rahmen = array( 'datum_text' => '' );
		}

		$treffer = lsg_bl_datum_aus_text( $rahmen['datum_text'] );
		if ( '' !== $treffer['datum'] ) {
			return array(
				'datum'   => $treffer['datum'],
				'quelle'  => 'api',
				'hinweis' => 'aus dem Veranstaltungskopf bei racepedia',
			);
		}

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
