<?php
/**
 * Die Listentabelle der Seite „Bestenliste" (Plan 7.4).
 *
 * ⚠ Eigene Datei, und bewusst NICHT von lsg-bestenliste.php geladen:
 * `WP_List_Table` steckt in `wp-admin/includes/class-wp-list-table.php` und
 * ist beim Laden der Plugin-Dateien noch nicht deklariert. WordPress lädt sie
 * erst, wenn eine Listentabelle gebraucht wird. Ein `extends WP_List_Table`
 * zur Ladezeit des Plugins wäre deshalb ein „Class not found" – und zwar auf
 * JEDER Adminseite, nicht nur auf dieser.
 *
 * Geladen wird diese Datei daher von lsg_bl_best_liste_anzeigen(), direkt
 * nachdem die Basisklasse angefordert wurde.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Die Liste über `lsg_best` (Plan 7.4).
 *
 * ⚠ Hier wird `WP_List_Table` benutzt, anders als bei der Log-Ansicht. Der
 * Unterschied ist nicht Inkonsequenz, sondern der Bedarf: diese Seite braucht
 * Zeilen-Aktionen („Bearbeiten | Löschen"), ein Suchfeld und die vertraute
 * Admin-Optik einer Tabelle, in der man arbeitet. Das Log braucht keine
 * Zeilen-Aktionen – es wird gelesen, nicht bearbeitet.
 *
 * ⚠ Sortierbar sind bewusst nur Sportler, Datum und Ort. NICHT Distanz und
 * NICHT Leistung: eine alphabetische Distanzsortierung stellte `100km` vor
 * `10km`, und eine Leistungssortierung über alle Distanzen hinweg vergliche
 * Sekunden mit Kilometern. Beides sieht nach einer Funktion aus und wäre
 * keine. Wer nach Leistung sortieren will, filtert zuerst auf eine Distanz –
 * dann ist die Standardsortierung genau das.
 */
class LSG_BL_Best_Table extends WP_List_Table {

	/** @var array Aktive Filter. */
	private $filter;

	/** @var int Gesamtzahl der Treffer. */
	private $gesamt = 0;

	/**
	 * @param array $filter Ergebnis von lsg_bl_best_filter().
	 */
	public function __construct( array $filter ) {
		$this->filter = $filter;

		parent::__construct(
			array(
				'singular' => 'ergebnis',
				'plural'   => 'ergebnisse',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'athlet'   => __( 'Sportler', 'lsg-bestenliste' ),
			'born'     => __( 'Jg', 'lsg-bestenliste' ),
			'jahr'     => __( 'Jahr', 'lsg-bestenliste' ),
			'distanz'  => __( 'Distanz', 'lsg-bestenliste' ),
			'leistung' => __( 'Leistung', 'lsg-bestenliste' ),
			'ak'       => __( 'AK', 'lsg-bestenliste' ),
			'ort'      => __( 'Ort', 'lsg-bestenliste' ),
			'datum'    => __( 'Datum', 'lsg-bestenliste' ),
		);
	}

	/**
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'athlet' => array( 'athlet', false ),
			'datum'  => array( 'datum', true ),
			'ort'    => array( 'ort', false ),
		);
	}

	/**
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Keine Ergebnisse zu diesen Filtern.', 'lsg-bestenliste' );
	}

	/**
	 * @return void
	 */
	public function prepare_items() {
		$pro   = 50;
		$seite = $this->get_pagenum();

		$daten = lsg_bl_best_liste( $this->filter, $seite, $pro );

		$this->items  = $daten['zeilen'];
		$this->gesamt = $daten['gesamt'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $this->gesamt,
				'per_page'    => $pro,
				'total_pages' => (int) ceil( $this->gesamt / $pro ),
			)
		);
	}

	/**
	 * @param array  $item   Zeile.
	 * @param string $spalte Spaltenname.
	 * @return string
	 */
	public function column_default( $item, $spalte ) {
		switch ( $spalte ) {
			case 'born':
				return $item['born'] ? esc_html( (string) (int) $item['born'] ) : '&#8211;';

			case 'jahr':
				$jahr = lsg_bl_year_from_timestamp( (int) $item['date'] );
				return $jahr ? esc_html( (string) $jahr ) : '&#8211;';

			case 'distanz':
				return esc_html( lsg_bl_distance_label( $item['distance'] ) );

			case 'leistung':
				return '<strong>' . esc_html( (string) $item['time'] ) . '</strong>';

			case 'ak':
				return esc_html( (string) $item['ak'] );

			case 'ort':
				return esc_html( (string) $item['town'] );

			case 'datum':
				return $item['date'] ? esc_html( lsg_bl_format_date( (int) $item['date'] ) ) : '&#8211;';
		}

		return '';
	}

	/**
	 * Die Namensspalte mit den Zeilen-Aktionen.
	 *
	 * @param array $item Zeile.
	 * @return string
	 */
	public function column_athlet( $item ) {
		$name = lsg_bl_athlete_display_name( $item['name'], $item['firstname'] );
		if ( '' === trim( $name, ' ,' ) ) {
			// Eine Bestandszeile ohne Athleten sollte es nicht geben – falls
			// doch, ist das Verschweigen die schlechteste Reaktion.
			$name = sprintf(
				/* translators: %d: athletes_id */
				__( '(kein Sportler, id %d)', 'lsg-bestenliste' ),
				(int) $item['athletes_id']
			);
		}

		$bearbeiten = lsg_bl_best_url(
			array(
				'action' => 'edit',
				'id'     => (int) $item['id'],
			)
		);
		$loeschen   = lsg_bl_best_url(
			array(
				'action' => 'delete',
				'id'     => (int) $item['id'],
			)
		);

		$aktionen = array(
			'edit'   => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $bearbeiten ),
				esc_html__( 'Bearbeiten', 'lsg-bestenliste' )
			),
			// ⚠ Der Link führt auf die Rückfrage, nicht auf das Löschen.
			'delete' => sprintf(
				'<a href="%1$s" class="submitdelete">%2$s</a>',
				esc_url( $loeschen ),
				esc_html__( 'Löschen', 'lsg-bestenliste' )
			),
		);

		if ( ! empty( $item['active'] ) && '1' !== (string) $item['active'] ) {
			$name .= ' ' . __( '(ehemalig)', 'lsg-bestenliste' );
		}

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $bearbeiten ),
			esc_html( $name ),
			$this->row_actions( $aktionen )
		);
	}
}
