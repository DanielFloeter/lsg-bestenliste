<?php
/**
 * Die Listentabelle der Seite „Sportler" (Plan 11.3).
 *
 * ⚠ Eigene Datei, und bewusst NICHT von lsg-bestenliste.php geladen:
 * `WP_List_Table` steckt in `wp-admin/includes/class-wp-list-table.php` und
 * ist beim Laden der Plugin-Dateien noch nicht deklariert. Geladen wird diese
 * Datei von lsg_bl_athlet_liste_anzeigen() – derselbe Grund und derselbe Weg
 * wie bei class-lsg-best-table.php.
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
 * Die Liste über `lsg_athlete` (Plan 11.3).
 *
 * ⚠ Sortierbar sind Nachname, Jahrgang und Ergebnisse – und alle drei
 * sortieren wirklich. Das ist kein selbstverständlicher Satz: in
 * `LSG_BL_Best_Table` waren drei Spaltenköpfe als sortierbar ausgezeichnet,
 * ohne dass `prepare_items()` `orderby` je gelesen hätte. Die Links sahen aus
 * wie eine Funktion und waren keine. Hier reicht `prepare_items()` beides an
 * lsg_bl_athlet_liste() durch, und der Filter lässt nur die drei Werte zu.
 */
class LSG_BL_Athlet_Table extends WP_List_Table {

	/** @var array Aktive Filter. */
	private $filter;

	/** @var int Gesamtzahl der Treffer. */
	private $gesamt = 0;

	/**
	 * @param array $filter Ergebnis von lsg_bl_athlet_filter().
	 */
	public function __construct( array $filter ) {
		$this->filter = $filter;

		parent::__construct(
			array(
				'singular' => 'sportler',
				'plural'   => 'sportler',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'name'       => __( 'Nachname', 'lsg-bestenliste' ),
			'firstname'  => __( 'Vorname', 'lsg-bestenliste' ),
			'born'       => __( 'Jahrgang', 'lsg-bestenliste' ),
			'cat'        => __( 'Geschlecht', 'lsg-bestenliste' ),
			'active'     => __( 'Status', 'lsg-bestenliste' ),
			'ergebnisse' => __( 'Ergebnisse', 'lsg-bestenliste' ),
			'siege'      => __( 'Gesamtsiege', 'lsg-bestenliste' ),
			'regeln'     => __( 'Regeln', 'lsg-bestenliste' ),
		);
	}

	/**
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'name'       => array( 'name', false ),
			'born'       => array( 'born', false ),
			'ergebnisse' => array( 'ergebnisse', true ),
		);
	}

	/**
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Kein Sportler zu diesen Filtern.', 'lsg-bestenliste' );
	}

	/**
	 * @return void
	 */
	public function prepare_items() {
		$pro   = 100;
		$seite = $this->get_pagenum();

		$daten = lsg_bl_athlet_liste( $this->filter, $seite, $pro );

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
			case 'firstname':
				return esc_html( (string) $item['firstname'] );

			case 'born':
				return $item['born'] ? esc_html( (string) (int) $item['born'] ) : '&#8211;';

			case 'cat':
				return esc_html(
					'f' === strtolower( (string) $item['cat'] )
						? __( 'weiblich', 'lsg-bestenliste' )
						: __( 'männlich', 'lsg-bestenliste' )
				);

			case 'active':
				return '1' === (string) $item['active']
					? esc_html__( 'aktiv', 'lsg-bestenliste' )
					: '<span class="lsg-bl-ehemalig">' . esc_html__( 'ehemalig', 'lsg-bestenliste' ) . '</span>';

			case 'ergebnisse':
				return $this->zahl_verlinkt(
					(int) $item['n_best'],
					lsg_bl_best_url( array( 'athlet' => (int) $item['id'] ) )
				);

			case 'siege':
				// Für `lsg_win` gibt es noch keine Pflegeseite (M8, 9.2) –
				// die Zahl steht hier, der Link kommt mit der Seite.
				return (int) $item['n_win'] > 0 ? esc_html( (string) (int) $item['n_win'] ) : '&#8211;';

			case 'regeln':
				return $this->zahl_verlinkt(
					(int) $item['n_map'],
					add_query_arg( array( 'page' => 'lsg-bestenliste-map' ), admin_url( 'admin.php' ) )
				);
		}

		return '';
	}

	/**
	 * Eine Zählspalte: die Zahl führt dorthin, wo die Zeilen stehen.
	 *
	 * Eine Zahl, die nirgends hinführt, beantwortet die nächste Frage nicht
	 * (11.3). Die Null bleibt bewusst ein Strich – ein Link auf eine leere
	 * Liste ist ein Versprechen, das die Zielseite nicht hält.
	 *
	 * @param int    $n   Anzahl.
	 * @param string $url Ziel.
	 * @return string
	 */
	private function zahl_verlinkt( $n, $url ) {
		if ( $n <= 0 ) {
			return '&#8211;';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( (string) $n )
		);
	}

	/**
	 * Die Namensspalte mit den Zeilen-Aktionen.
	 *
	 * @param array $item Zeile.
	 * @return string
	 */
	public function column_name( $item ) {
		$bearbeiten = lsg_bl_athlet_url(
			array(
				'action' => 'edit',
				'id'     => (int) $item['id'],
			)
		);

		$aktionen = array(
			'edit' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $bearbeiten ),
				esc_html__( 'Bearbeiten', 'lsg-bestenliste' )
			),
		);

		/*
		 * ⚠ „Löschen" steht nur da, wo es auch geht (11.3). Ein Link, der auf
		 * eine Seite führt, die dann doch nur erklärt, warum nicht gelöscht
		 * werden kann, ist eine Zumutung – die Liste weiß die Zahlen bereits.
		 */
		if ( 0 === (int) $item['n_summe'] ) {
			$aktionen['delete'] = sprintf(
				'<a href="%1$s" class="submitdelete">%2$s</a>',
				esc_url(
					lsg_bl_athlet_url(
						array(
							'action' => 'delete',
							'id'     => (int) $item['id'],
						)
					)
				),
				esc_html__( 'Löschen', 'lsg-bestenliste' )
			);
		}

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $bearbeiten ),
			esc_html( (string) $item['name'] ),
			$this->row_actions( $aktionen )
		);
	}
}
