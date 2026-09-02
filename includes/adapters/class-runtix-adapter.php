<?php
/**
 * Adapter für runtix.com – HTML, die zweite Quelle.
 *
 * Ablauf (Plan 4.2):
 *   1) GET https://runtix.com/sts/10050/{eventId}
 *      → h1 (Eventname), select[name=contest], select[name=rlt]
 *   2) GET https://runtix.com/sts/10050/{eventId}/{contest}/{rlt}
 *      → table.results
 *
 * ⚠ Vier Fallstricke, alle am 2026-09-02 live gegen Event 3152 geprüft:
 *
 *   1) KEIN trailing slash. `/sts/10050/3152/21/total/` liefert nicht die
 *      Liste. Der URL-Bauer hängt deshalb niemals ein „/" an.
 *
 *   2) Contest-Keys sind nicht durchweg Zahlen. Bei 3152 heißt der Walk „w".
 *      Jedes (int)-Cast macht daraus 0 und lädt den falschen Wettbewerb.
 *
 *   3) `col-place-ageclass` (Platz innerhalb der AK) und `col-ageclass`
 *      (der Klassencode selbst, z.B. „M 60") sind zwei verschiedene
 *      Spalten. Ein contains(@class,'col-ageclass') trifft beide – und der
 *      Parser liest dann „10" als Altersklasse. Gesucht wird deshalb nach
 *      der exakten Klasse, mit normalisiertem Attribut.
 *
 *   4) Die Zeitspalte heißt `class="col-time "` – mit Leerzeichen am Ende.
 *      Ein Vergleich auf Gleichheit des rohen Attributs geht schief.
 *
 * ⚠ Und der Grund für die aufwendige Datumsauflösung: auf der Ergebnisseite
 *   steht kein Datum. Nirgends, in keiner Form (geprüft: kein einziges
 *   TT.MM.JJJJ im gesamten Seitentext). Siehe datum().
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LSG_BL_Runtix_Adapter implements LSG_BL_Ergebnis_Quelle {

	/**
	 * Der HTTP-Getter, injiziert – damit die Parser ohne Netz und ohne
	 * WordPress prüfbar bleiben (Plan, Abschnitt 5).
	 *
	 * @var callable
	 */
	private $get;

	/**
	 * Gelesene Jahresübersichten, je Jahr. Nur für die Dauer eines
	 * Requests; das Cachen über Requests hinweg macht lsg_bl_discovery()
	 * bzw. lsg_bl_runtix_jahr() (Transient, 15 min).
	 *
	 * @var array<string,array>
	 */
	private $jahre = array();

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
		return 'runtix';
	}

	/**
	 * @return string
	 */
	public static function label() {
		return 'runtix';
	}

	/**
	 * @return string[]
	 */
	public static function hosts() {
		return array( 'runtix.com', '*.runtix.com' );
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
		if ( 'runtix.com' !== $host && '.runtix.com' !== substr( $host, -11 ) ) {
			return 0;
		}
		$teil = self::url_zerlegen( $url );
		return ( '' !== $teil['event_id'] ) ? 90 : 40;
	}

	/**
	 * Eine Runtix-Adresse in ihre Bestandteile zerlegen.
	 *
	 * Erkannt werden:
	 *   /sts/10050/3152                → event 3152
	 *   /sts/10050/3152/21/total       → event 3152, contest 21, rlt total
	 *   /sts/10050/3152/w/ac           → contest „w" – KEIN int-Cast!
	 *   /sts/10021/3152                → Veranstaltungsseite, event 3152
	 *   /sts/10020/2026                → Jahresübersicht, kein Event
	 *
	 * Die Modulnummer (10020/10021/10040/10050/10080) kommt mit zurück,
	 * damit eventLesen() weiß, ob überhaupt ein Event gemeint war.
	 *
	 * @param string $url Eingegebene URL.
	 * @return array{modul:string,event_id:string,contest:string,rlt:string}
	 */
	public static function url_zerlegen( $url ) {
		$leer = array(
			'modul'    => '',
			'event_id' => '',
			'contest'  => '',
			'rlt'      => '',
		);

		$teile = lsg_bl_parse_url( (string) $url );
		$pfad  = isset( $teile['path'] ) ? $teile['path'] : '';
		$seg   = array_values(
			array_filter(
				explode( '/', $pfad ),
				function ( $s ) {
					return '' !== $s;
				}
			)
		);

		// Erwartet: sts / <modul> / <id> [ / <contest> [ / <rlt> ] ]
		$i = array_search( 'sts', $seg, true );
		if ( false === $i || ! isset( $seg[ $i + 1 ] ) ) {
			return $leer;
		}

		$modul = $seg[ $i + 1 ];
		if ( ! ctype_digit( $modul ) ) {
			return $leer;
		}

		$out          = $leer;
		$out['modul'] = $modul;

		if ( ! isset( $seg[ $i + 2 ] ) ) {
			return $out;
		}
		$id = $seg[ $i + 2 ];

		// /sts/10020/{jahr} ist die Jahresübersicht, kein Event.
		if ( '10020' === $modul ) {
			return $out;
		}
		if ( ! ctype_digit( $id ) ) {
			return $out;
		}

		$out['event_id'] = $id;
		if ( isset( $seg[ $i + 3 ] ) ) {
			$out['contest'] = $seg[ $i + 3 ];
		}
		if ( isset( $seg[ $i + 4 ] ) ) {
			$out['rlt'] = $seg[ $i + 4 ];
		}
		return $out;
	}

	/**
	 * Event-ID aus der URL – für den Discovery-Cache-Schlüssel, der ohne
	 * Fremdabruf gebildet werden muss (lsg_bl_discovery()).
	 *
	 * @param string $url Eingegebene URL.
	 * @return string Leer, wenn keine ID im Pfad steht.
	 */
	public static function event_id_aus_url( $url ) {
		$teil = self::url_zerlegen( $url );
		return $teil['event_id'];
	}

	/**
	 * Vorauswahl aus der URL lesen – bei Runtix steht sie im PFAD, nicht in
	 * einem Fragment: /sts/10050/3152/21/total.
	 *
	 * ⚠ Genau deshalb gibt es diese Methode neben fragment_lesen(): eine
	 * Vorauswahl, die auf dem Cache-Treffer-Weg verloren geht, führt dazu,
	 * dass die Oberfläche beim zweiten Aufruf derselben Adresse plötzlich
	 * keinen Wettbewerb mehr vorbelegt.
	 *
	 * @param string $url Eingegebene URL.
	 * @return array{contest:string,list:string}
	 */
	public static function vorauswahl_aus_url( $url ) {
		$teil = self::url_zerlegen( $url );
		return array(
			'contest' => $teil['contest'],
			'list'    => $teil['rlt'],
		);
	}

	/**
	 * Adressen bauen. Bewusst eine einzige Stelle – und bewusst ohne
	 * abschließenden Schrägstrich (Fallstrick 1).
	 *
	 * @param string $modul      Modulnummer, z.B. '10050'.
	 * @param string $id         Event-ID oder Jahr.
	 * @param string $contest_id Contest-Key oder ''.
	 * @param string $rlt        Listentyp oder ''.
	 * @return string
	 */
	public static function url_bauen( $modul, $id, $contest_id = '', $rlt = '' ) {
		$url = 'https://runtix.com/sts/' . rawurlencode( (string) $modul )
			. '/' . rawurlencode( (string) $id );

		if ( '' !== (string) $contest_id ) {
			$url .= '/' . rawurlencode( (string) $contest_id );
			if ( '' !== (string) $rlt ) {
				$url .= '/' . rawurlencode( (string) $rlt );
			}
		}
		return $url;
	}

	/* --------------------------------------------------------------------
	 * DOM-Hilfen
	 * ----------------------------------------------------------------- */

	/**
	 * HTML in ein DOMDocument, ohne die üblichen Warnungen.
	 *
	 * ⚠ Der Meta-Charset-Vorspann ist nötig: libxml nimmt ohne Angabe
	 * Latin-1 an und macht aus „Lußhardtlauf" „LuÃŸhardtlauf". Die Umlaute
	 * sind genau das, was hier am häufigsten schiefgeht.
	 *
	 * @param string $html Rohantwort.
	 * @return DOMXPath
	 * @throws LSG_BL_Quelle_Exception Wenn nichts Parsebares ankommt.
	 */
	private static function xpath( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			throw new LSG_BL_Quelle_Exception(
				'runtix hat eine leere Seite geliefert.'
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
	 * Der ganze Grund für diese Funktion sind die Fallstricke 3 und 4:
	 * `col-ageclass` darf nicht in `col-place-ageclass` treffen, und
	 * `col-time` muss trotz des Leerzeichens in `class="col-time "` treffen.
	 *
	 * @param DOMElement|DOMNode $el     Element.
	 * @param string             $klasse Gesuchte Klasse.
	 * @return bool
	 */
	private static function hat_klasse( $el, $klasse ) {
		if ( ! ( $el instanceof DOMElement ) ) {
			return false;
		}
		$roh = $el->getAttribute( 'class' );
		$fel = preg_split( '/\s+/', trim( $roh ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $fel ) && in_array( $klasse, $fel, true );
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
	 * Die Rahmendaten einer Ergebnisseite: Eventname, Wettbewerbe, Listen.
	 *
	 * Der h1 lautet „Ergebnislisten - 19. Hambrücker Lußhardtlauf". Das
	 * Präfix wird abgeschnitten, damit der Eventname der ist, den ein
	 * Mensch nennen würde – er landet in lsg_import_run.event_name und in
	 * der Distanz-Vorbelegung.
	 *
	 * @param string $html Rohantwort von /sts/10050/{id}[/...].
	 * @return array{eventname:string,contest_name:string,contests:array,listen:array}
	 * @throws LSG_BL_Quelle_Exception Wenn die Seite nicht auswertbar ist.
	 */
	public static function parse_rahmen( $html ) {
		$xp = self::xpath( $html );

		$eventname = self::text( $xp->query( '//h1' )->item( 0 ) );
		$eventname = preg_replace( '/^\s*Ergebnislisten\s*[-–]\s*/u', '', $eventname );
		$eventname = trim( $eventname );

		$contest_name = self::text( $xp->query( '//h2' )->item( 0 ) );

		$contests = array();
		foreach ( $xp->query( '//select[@name="contest"]/option' ) as $opt ) {
			$id = trim( $opt->getAttribute( 'value' ) );
			if ( '' === $id ) {
				continue; // die „ --- "-Zeile.
			}
			// String! „w" ist ein gültiger Contest-Key (Fallstrick 2).
			$contests[] = array(
				'id'   => (string) $id,
				'name' => self::text( $opt ),
			);
		}

		$listen = array();
		foreach ( $xp->query( '//select[@name="rlt"]/option' ) as $opt ) {
			$id = trim( $opt->getAttribute( 'value' ) );
			if ( '' === $id ) {
				continue;
			}
			$listen[] = array(
				'id'   => (string) $id,
				'name' => self::text( $opt ),
			);
		}

		if ( empty( $contests ) && null === $xp->query( '//table[@class="results"]' )->item( 0 ) ) {
			throw new LSG_BL_Quelle_Exception(
				'Auf dieser runtix-Seite sind weder Wettbewerbe noch eine '
				. 'Ergebnistabelle zu finden. Ist die Adresse vollständig?'
			);
		}

		return array(
			'eventname'    => $eventname,
			'contest_name' => $contest_name,
			'contests'     => $contests,
			'listen'       => $listen,
		);
	}

	/**
	 * Die Ergebnistabelle auswerten.
	 *
	 * Gelesen wird nach CSS-Klasse, nicht nach Spaltenposition.
	 *
	 * ⚠ Korrektur gegenüber Plan 4.2: die drei Listentypen liefern
	 * DIESELBEN elf Spalten. Am 2026-09-02 gegen Event 3152 geprüft –
	 * `total` (234 Zeilen), `sex` (73) und `ac` (23) haben Zeile für Zeile
	 * elf Zellen, keinen colspan, keine Gruppenzeilen. Der Plan nahm an,
	 * dass `ac` den Gesamtplatz und `sex` den AK-Platz weglässt; das tun
	 * sie nicht.
	 *
	 * Am Lesen nach Klasse ändert das nichts – es bleibt der richtige Weg,
	 * denn es kostet nichts und trägt auch, wenn Runtix die Reihenfolge
	 * ändert oder eine Spalte doch einmal wegfällt.
	 *
	 * ⚠ Was `sex` und `ac` dagegen wirklich unterscheidet: sie sind
	 * TEILLISTEN. `sex` zeigt ein Geschlecht, `ac` eine Altersklasse. Wer
	 * dort importiert, holt einen Ausschnitt – und Platz 1 darin ist kein
	 * Gesamtsieg (siehe listen(), gesamtwertung nur bei `total`).
	 *
	 * @param string $html Rohantwort von /sts/10050/{id}/{contest}/{rlt}.
	 * @return array{zeilen:LSG_BL_Ergebnis[],gelesen:int,verworfen:int,zeit_typ:string,warnungen:string[],listname:string}
	 * @throws LSG_BL_Quelle_Exception Wenn keine Tabelle da ist.
	 */
	public static function parse_liste( $html ) {
		$xp = self::xpath( $html );

		$tabelle = null;
		foreach ( $xp->query( '//table' ) as $t ) {
			if ( self::hat_klasse( $t, 'results' ) ) {
				$tabelle = $t;
				break;
			}
		}
		if ( null === $tabelle ) {
			throw new LSG_BL_Quelle_Exception(
				'Auf dieser runtix-Seite ist keine Ergebnistabelle zu finden. '
				. 'Häufigste Ursache: ein Schrägstrich am Ende der Adresse.'
			);
		}

		// Runtix liefert alle Zeiten als Bruttozeit; eine Nettospalte gibt
		// es in dieser Ansicht nicht. Der Typ wird trotzdem mitgeführt,
		// damit später nicht Netto gegen Brutto verglichen wird.
		$zeit_typ  = 'brutto';
		$warnungen = array();

		$zeilen    = array();
		$gelesen   = 0;
		$verworfen = 0;
		$spalten   = array();

		foreach ( $xp->query( './/tr', $tabelle ) as $tr ) {
			$zellen = array();
			$ist_kopf = false;

			foreach ( $tr->childNodes as $td ) {
				if ( ! ( $td instanceof DOMElement ) ) {
					continue;
				}
				$tag = strtolower( $td->nodeName );
				if ( 'td' !== $tag && 'th' !== $tag ) {
					continue;
				}
				if ( 'th' === $tag ) {
					$ist_kopf = true;
				}
				$zellen[] = $td;
			}

			if ( empty( $zellen ) ) {
				continue;
			}

			if ( $ist_kopf ) {
				// Die Kopfzeile nur zur Kontrolle: welche Spalten gibt es?
				foreach ( $zellen as $th ) {
					$roh = preg_split( '/\s+/', trim( $th->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
					foreach ( (array) $roh as $k ) {
						$spalten[ $k ] = true;
					}
				}
				continue;
			}

			++$gelesen;

			$hole = function ( $klasse ) use ( $zellen ) {
				foreach ( $zellen as $td ) {
					if ( self::hat_klasse( $td, $klasse ) ) {
						return self::text( $td );
					}
				}
				return '';
			};

			$roh_zeit = $hole( 'col-time' );
			$zeit     = lsg_bl_zeit_normalisieren( $roh_zeit );
			if ( '' === $zeit ) {
				// DNF / DSQ / „---" → verwerfen und zählen, nicht raten.
				++$verworfen;
				continue;
			}

			$teilnehmer = $hole( 'col-competitor' );
			$split      = lsg_bl_name_splitten( $teilnehmer );

			// Fallstrick 3: der Klassencode steht in col-ageclass, der Platz
			// darin in col-place-ageclass. Nur der Code interessiert.
			$ak_roh = $hole( 'col-ageclass' );

			$jahrgang = 0;
			if ( preg_match( '/\d{4}/', $hole( 'col-birth' ), $m ) ) {
				$jahrgang = (int) $m[0];
			}

			$e                 = new LSG_BL_Ergebnis();
			$e->nachname       = $split['nachname'];
			$e->vorname        = $split['vorname'];
			$e->teilnehmer     = $teilnehmer;
			$e->namen_unsicher = $split['unsicher'];
			$e->geschlecht     = lsg_bl_geschlecht_aus_klasse( $ak_roh );
			$e->jahrgang       = $jahrgang;
			$e->verein         = $hole( 'col-team' );
			$e->zeit           = $zeit;
			$e->roh_zeit       = $roh_zeit;
			$e->zeit_typ       = $zeit_typ;
			$e->platz          = rtrim( $hole( 'col-place-total' ), ' .' );
			$e->startnummer    = $hole( 'col-number' );
			$e->quelle_klasse  = $ak_roh;

			$zeilen[] = $e;
		}

		if ( 0 === $gelesen ) {
			throw new LSG_BL_Quelle_Exception(
				'Die Ergebnistabelle auf dieser runtix-Seite ist leer.'
			);
		}
		if ( ! isset( $spalten['col-birth'] ) ) {
			$warnungen[] = 'Diese runtix-Liste nennt keinen Jahrgang. Ohne Jahrgang lässt sich kein Athlet zuordnen.';
		}
		if ( ! isset( $spalten['col-team'] ) ) {
			$warnungen[] = 'Diese runtix-Liste nennt keinen Verein. Der LSG-Filter kann so nicht greifen.';
		}
		if ( ! isset( $spalten['col-place-total'] ) ) {
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
	 * Die Jahresübersicht /sts/10020/{jahr} auswerten.
	 *
	 * ⚠ Der Eintrag wird über die ID gefunden, niemals über den Namen: der
	 * Name auf der Übersicht („19. Hambrücker Lußhardtlauf") und der auf der
	 * Ergebnisseite müssen nicht Zeichen für Zeichen gleich sein, und in
	 * einem Jahr laufen mehrere Veranstaltungen mit fast gleichem Namen.
	 *
	 * ⚠ Und der Eintrag wird über den DATUMS-Link gefunden, nicht über den
	 * Ergebnis-Link. Live standen 58 von 157 Zeilen ohne Ergebnisse da –
	 * dort verlinkt auch der Name auf /sts/10021/. Der Datumslink
	 * /sts/10021/{id} ist dagegen in jeder Zeile vorhanden.
	 *
	 * @param string $html Rohantwort von /sts/10020/{jahr}.
	 * @return array<string,array{datum:string,name:string}> Schlüssel = Event-ID.
	 */
	public static function parse_jahr( $html ) {
		$xp  = self::xpath( $html );
		$out = array();

		/*
		 * Gelesen wird zeilenweise, nicht über einen flachen Link-Scan.
		 * Der Grund ist der „Ergebnisse"-Knopf: er zeigt auf dieselbe
		 * /sts/10050/{id}, trägt aber das Wort „Ergebnisse" als Text. Ein
		 * flacher Scan schriebe das als Veranstaltungsnamen fort.
		 *
		 * Innerhalb der Zeile zählt nur der Beschreibungsblock: dort steht
		 * links das Datum, rechts der Name.
		 */
		foreach ( $xp->query( '//div' ) as $zeile ) {
			if ( ! self::hat_klasse( $zeile, 'competition' ) ) {
				continue;
			}

			$id    = '';
			$datum = '';
			$name  = '';

			foreach ( $xp->query( './/a', $zeile ) as $a ) {
				// Der Beschreibungsblock, nicht die Knöpfe.
				$in_beschreibung = false;
				for ( $p = $a->parentNode; $p && $p !== $zeile; $p = $p->parentNode ) {
					if ( self::hat_klasse( $p, 'description' ) ) {
						$in_beschreibung = true;
						break;
					}
				}
				if ( ! $in_beschreibung ) {
					continue;
				}

				$href = $a->getAttribute( 'href' );
				if ( ! preg_match( '#/sts/\d+/(\d+)#', $href, $m ) ) {
					continue;
				}
				if ( '' === $id ) {
					$id = $m[1];
				}

				$text = self::text( $a );
				if ( '' === $text ) {
					continue;
				}

				if ( preg_match( '/^\d{1,2}\.\d{1,2}\.\d{4}$/', $text ) ) {
					$treffer = lsg_bl_datum_aus_text( $text );
					if ( '' !== $treffer['datum'] ) {
						$datum = $treffer['datum'];
					}
				} elseif ( '' === $name ) {
					$name = $text;
				}
			}

			if ( '' === $id ) {
				continue;
			}

			$out[ $id ] = array(
				'datum' => $datum,
				'name'  => $name,
			);
		}

		return $out;
	}

	/**
	 * Die Veranstaltungsseite /sts/10021/{id} auswerten – nur als Einstieg.
	 *
	 * Zurück kommt, was dort an Jahreszahlen zu holen ist, damit datum()
	 * weiß, welches Jahr es in der Übersicht nachschlagen soll.
	 *
	 * ⚠ Drei Ablenkungen stehen live auf genau dieser Seite:
	 * Lastschrifteinzug 19.08., Meldeschluss 15.08., Stand der
	 * Ausschreibung 12.03. Deshalb wird von hier NIE ein Datum
	 * übernommen – nur die Jahreszahlen, und die Auflösung macht die
	 * Übersicht.
	 *
	 * ⚠ Die Fußzeile nennt „Copyright © CODERESEARCH 2001 - 2026". Diese
	 * Jahre gehören zum Betreiber, nicht zum Lauf, und werden ausgefiltert.
	 *
	 * @param string $html Rohantwort von /sts/10021/{id}.
	 * @return array{eventname:string,jahre:int[],roh_datum:string}
	 */
	public static function parse_event( $html ) {
		$xp = self::xpath( $html );

		$eventname = self::text( $xp->query( '//h1' )->item( 0 ) );

		// Fußzeile heraushalten, bevor irgendetwas gelesen wird.
		foreach ( $xp->query( '//footer' ) as $f ) {
			if ( $f->parentNode ) {
				$f->parentNode->removeChild( $f );
			}
		}

		$text = self::text( $xp->query( '//body' )->item( 0 ) );

		// Ein vollständiges Datum in einem <strong> ist der beste Hinweis,
		// den diese Seite hergibt („Sonntag, den 16. August 2026").
		$roh_datum = '';
		foreach ( $xp->query( '//strong | //b' ) as $s ) {
			$t = self::text( $s );
			if ( '' === $t || mb_strlen( $t ) > 120 ) {
				continue;
			}
			if ( preg_match( '/(Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag)/u', $t ) ) {
				$treffer = lsg_bl_datum_aus_text( $t );
				if ( '' !== $treffer['datum'] ) {
					$roh_datum = $treffer['datum'];
					break;
				}
			}
		}

		$jahre = array();
		if ( '' !== $roh_datum ) {
			$jahre[] = (int) substr( $roh_datum, 0, 4 );
		}
		if ( preg_match_all( '/(?<!\d)(20\d{2})(?!\d)/', $text, $m ) ) {
			foreach ( $m[1] as $j ) {
				$j = (int) $j;
				if ( ! in_array( $j, $jahre, true ) ) {
					$jahre[] = $j;
				}
			}
		}

		return array(
			'eventname' => $eventname,
			'jahre'     => $jahre,
			'roh_datum' => $roh_datum,
		);
	}

	/* --------------------------------------------------------------------
	 * Schnittstelle
	 * ----------------------------------------------------------------- */

	/**
	 * @param string $url Eingegebene URL.
	 * @return LSG_BL_Event_Ref
	 * @throws LSG_BL_Quelle_Exception Wenn keine Event-ID in der URL steckt.
	 */
	public function eventLesen( $url ) {
		$teil = self::url_zerlegen( $url );
		if ( '' === $teil['event_id'] ) {
			throw new LSG_BL_Quelle_Exception(
				'In dieser Adresse steckt keine Veranstaltungs-Nummer. '
				. 'Erwartet wird etwas wie https://runtix.com/sts/10050/3152'
			);
		}

		$ref             = new LSG_BL_Event_Ref( self::key(), $teil['event_id'], (string) $url );
		$ref->contest_id = $teil['contest'];
		$ref->list_id    = $teil['rlt'];

		$rahmen          = $this->rahmen( $ref );
		$ref->event_name = $rahmen['eventname'];

		return $ref;
	}

	/**
	 * Die Ergebnisseite holen und auswerten. Einmal je Request.
	 *
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return array Ergebnis von parse_rahmen().
	 */
	private function rahmen( LSG_BL_Event_Ref $ref ) {
		if ( isset( $ref->meta['rahmen'] ) ) {
			return $ref->meta['rahmen'];
		}

		$url  = self::url_bauen( '10050', $ref->event_id );
		$html = call_user_func( $this->get, $url, __CLASS__ );

		$rahmen              = self::parse_rahmen( $html );
		$ref->meta['rahmen'] = $rahmen;

		return $rahmen;
	}

	/**
	 * @param LSG_BL_Event_Ref $ref Event-Kontext.
	 * @return LSG_BL_Wettbewerb[]
	 */
	public function wettbewerbe( LSG_BL_Event_Ref $ref ) {
		$rahmen = $this->rahmen( $ref );

		$out = array();
		foreach ( $rahmen['contests'] as $c ) {
			$out[] = new LSG_BL_Wettbewerb( $c['id'], $c['name'] );
		}
		return $out;
	}

	/**
	 * Die drei Listentypen. Bei Runtix hängen sie nicht am Wettbewerb –
	 * `total`, `sex` und `ac` gibt es für jeden.
	 *
	 * Nur `total` ist die Gesamtwertung: Platz 1 in der Geschlechts- oder
	 * AK-Liste ist kein Gesamtsieg (Plan 6.5.5).
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key (hier ungenutzt).
	 * @return LSG_BL_Liste[]
	 */
	public function listen( LSG_BL_Event_Ref $ref, $contest_id ) {
		$rahmen = $this->rahmen( $ref );

		$out = array();
		foreach ( $rahmen['listen'] as $l ) {
			$liste                = new LSG_BL_Liste( $l['id'], $l['name'], $l['id'] );
			$liste->gesamtwertung  = ( 'total' === $l['id'] );
			$out[]                = $liste;
		}
		return $out;
	}

	/**
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key.
	 * @param string|null      $list_id    Listentyp ('total'|'sex'|'ac').
	 * @return LSG_BL_Ergebnis[]
	 * @throws LSG_BL_Quelle_Exception Wenn der Wettbewerb fehlt.
	 */
	public function laden( LSG_BL_Event_Ref $ref, $contest_id, $list_id = null ) {
		$contest_id = (string) $contest_id;
		if ( '' === $contest_id ) {
			throw new LSG_BL_Quelle_Exception(
				'Ohne Wettbewerb lässt sich bei runtix keine Liste abrufen.'
			);
		}

		$rlt = ( null === $list_id || '' === $list_id ) ? 'total' : (string) $list_id;

		$url  = self::url_bauen( '10050', $ref->event_id, $contest_id, $rlt );
		$html = call_user_func( $this->get, $url, __CLASS__ );
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
		$rlt = ( null === $list_id || '' === $list_id ) ? 'total' : (string) $list_id;
		if ( '' === (string) $contest_id ) {
			return self::url_bauen( '10050', $ref->event_id );
		}
		return self::url_bauen( '10050', $ref->event_id, (string) $contest_id, $rlt );
	}

	/**
	 * Veranstaltungsdatum – bei runtix in zwei Schritten.
	 *
	 * Warum nicht in einem: die Ergebnisseite nennt kein Datum (geprüft,
	 * kein einziges TT.MM.JJJJ im Seitentext), und die Veranstaltungsseite
	 * nennt vier – Lauftag, Meldeschluss, Lastschrifteinzug und „Stand der
	 * Ausschreibung". Von dort ein Datum zu greifen heißt raten.
	 *
	 * Also:
	 *   1) /sts/10021/{id} lesen. Von dort nur die JAHRESZAHLEN nehmen.
	 *   2) /sts/10020/{jahr} lesen und den Eintrag mit dieser Event-ID
	 *      suchen. Dort steht das Datum in einer einzigen, immer gleichen
	 *      Form: TT.MM.JJJJ (live 157 von 157).
	 *
	 * ⚠ Höchstens zwei Jahres-Versuche. Danach bleibt das Feld leer und die
	 * Oberfläche verlangt die Eingabe – ein durchprobiertes Jahrzehnt sind
	 * zehn Fremdabrufe für ein Datum, das ein Mensch in fünf Sekunden
	 * eintippt (Plan 4.2).
	 *
	 * @param LSG_BL_Event_Ref $ref        Event-Kontext.
	 * @param string           $contest_id Contest-Key (hier ungenutzt).
	 * @return array{datum:string,quelle:string,hinweis:string}
	 */
	public function datum( LSG_BL_Event_Ref $ref, $contest_id = '' ) {
		$event = null;

		try {
			$url   = self::url_bauen( '10021', $ref->event_id );
			$html  = call_user_func( $this->get, $url, __CLASS__ );
			$event = self::parse_event( $html );
		} catch ( LSG_BL_Quelle_Exception $e ) {
			$event = null;
		}

		$jahre = array();
		if ( is_array( $event ) ) {
			$jahre = $event['jahre'];
		}
		// Kein Hinweis auf der Veranstaltungsseite? Dann das laufende Jahr
		// und das davor – mehr nicht.
		if ( empty( $jahre ) ) {
			$jetzt = (int) gmdate( 'Y' );
			$jahre = array( $jetzt, $jetzt - 1 );
		}

		$versuche = array_slice( $jahre, 0, 2 );

		foreach ( $versuche as $jahr ) {
			$liste = $this->jahr( (string) $jahr );
			if ( isset( $liste[ $ref->event_id ]['datum'] ) && '' !== $liste[ $ref->event_id ]['datum'] ) {
				return array(
					'datum'   => $liste[ $ref->event_id ]['datum'],
					'quelle'  => 'liste',
					'hinweis' => sprintf(
						'aus der runtix-Veranstaltungsübersicht %d',
						(int) $jahr
					),
				);
			}
		}

		// Die Übersicht hat nichts hergegeben. Das Datum aus dem
		// Ausschreibungstext ist dann der zweitbeste Wert – ausgewiesen als
		// solcher, damit die Oberfläche es zur Bestätigung anzeigt.
		if ( is_array( $event ) && '' !== $event['roh_datum'] ) {
			return array(
				'datum'   => $event['roh_datum'],
				'quelle'  => 'ausschreibung',
				'hinweis' => 'aus dem Ausschreibungstext gelesen – bitte prüfen',
			);
		}

		$treffer = lsg_bl_datum_aus_text( $ref->event_name );
		if ( '' !== $treffer['jahr'] ) {
			return array(
				'datum'   => '',
				'quelle'  => 'jahr',
				'hinweis' => 'nur das Jahr erkannt – Tag und Monat ergänzen',
			);
		}

		return array(
			'datum'   => '',
			'quelle'  => '',
			'hinweis' => 'Die Quelle nennt kein Datum – bitte eintragen.',
		);
	}

	/**
	 * Eine Jahresübersicht holen. Innerhalb des Requests gemerkt; über
	 * Requests hinweg cacht lsg_bl_runtix_jahr_cache() (Transient, 15 min).
	 *
	 * @param string $jahr Jahreszahl.
	 * @return array<string,array{datum:string,name:string}>
	 */
	private function jahr( $jahr ) {
		$jahr = (string) (int) $jahr;
		if ( isset( $this->jahre[ $jahr ] ) ) {
			return $this->jahre[ $jahr ];
		}

		$liste = array();
		if ( function_exists( 'lsg_bl_runtix_jahr_cache' ) ) {
			$liste = lsg_bl_runtix_jahr_cache( $jahr, $this->get );
		} else {
			try {
				$url   = self::url_bauen( '10020', $jahr );
				$html  = call_user_func( $this->get, $url, __CLASS__ );
				$liste = self::parse_jahr( $html );
			} catch ( LSG_BL_Quelle_Exception $e ) {
				$liste = array();
			}
		}

		$this->jahre[ $jahr ] = $liste;
		return $liste;
	}
}
