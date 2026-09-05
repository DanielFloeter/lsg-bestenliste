<?php
/**
 * Die Listentabelle der Seite „Gesamtsiege" (Plan 12.4).
 *
 * ⚠ Eigene Datei, und bewusst NICHT von lsg-bestenliste.php geladen:
 * `WP_List_Table` ist beim Laden der Plugin-Dateien noch nicht deklariert.
 * Geladen wird sie von lsg_bl_win_liste_anzeigen() – derselbe Grund und
 * derselbe Weg wie bei den beiden anderen Listentabellen.
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
 * Die Chronik über `lsg_win` (Plan 12.4).
 *
 * ⚠ Distanz und Zeit stehen hier so, wie sie in der Datenbank stehen – „48
 * Runden" und „Pforzheim nach Basel" eingeschlossen (12.1). Nur die Distanz
 * läuft durch `lsg_bl_distance_label()`, und das ausschliesslich, damit ein
 * gespeicherter Code wie `HM` als „Halbmarathon" erscheint; unbekannte Werte
 * gibt die Funktion unverändert zurück.
 *
 * ⚠ Sortierbar sind Datum, Ort und Sportler – und alle drei sortieren
 * wirklich, siehe die Notiz zu `LSG_BL_Best_Table` in 11.3.
 */
class LSG_BL_Win_Table extends WP_List_Table {

	/** @var array Aktive Filter. */
	private $filter;

	/** @var int Gesamtzahl der Treffer. */
	private $gesamt = 0;

	/**
	 * @param array $filter Ergebnis von lsg_bl_win_filter().
	 */
	public function __construct( array $filter ) {
		$this->filter = $filter;

		parent::__construct(
			array(
				'singular' => 'gesamtsieg',
				'plural'   => 'gesamtsiege',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'datum'   => __( 'Datum', 'lsg-bestenliste' ),
			'ort'     => __( 'Ort', 'lsg-bestenliste' ),
			'event'   => __( 'Veranstaltung', 'lsg-bestenliste' ),
			'distanz' => __( 'Distanz', 'lsg-bestenliste' ),
			'athlet'  => __( 'Sportler', 'lsg-bestenliste' ),
			'zeit'    => __( 'Zeit', 'lsg-bestenliste' ),
		);
	}

	/**
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'datum'  => array( 'datum', true ),
			'ort'    => array( 'ort', false ),
			'athlet' => array( 'athlet', false ),
		);
	}

	/**
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Kein Gesamtsieg zu diesen Filtern.', 'lsg-bestenliste' );
	}

	/**
	 * @return void
	 */
	public function prepare_items() {
		$pro   = 50;
		$seite = $this->get_pagenum();

		$daten = lsg_bl_win_liste( $this->filter, $seite, $pro );

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
			case 'ort':
				return esc_html( (string) $item['town'] );

			case 'event':
				return esc_html( (string) $item['event'] );

			case 'distanz':
				return esc_html( lsg_bl_distance_label( $item['distance'] ) );

			case 'athlet':
				$name = lsg_bl_athlete_display_name( $item['name'], $item['firstname'] );
				if ( '' === trim( $name, ' ,' ) ) {
					return esc_html(
						sprintf(
							/* translators: %d: athletes_id */
							__( '(kein Sportler, id %d)', 'lsg-bestenliste' ),
							(int) $item['athletes_id']
						)
					);
				}
				if ( ! empty( $item['active'] ) && '1' !== (string) $item['active'] ) {
					$name .= ' ' . __( '(ehemalig)', 'lsg-bestenliste' );
				}
				return esc_html( $name );

			case 'zeit':
				// ⚠ Unverändert. Auch „48 Runden" (12.1).
				return '<strong>' . esc_html( (string) $item['time'] ) . '</strong>';
		}

		return '';
	}

	/**
	 * Die Datumsspalte mit den Zeilen-Aktionen.
	 *
	 * Das Datum ist die primäre Spalte, weil die Chronik chronologisch
	 * gelesen wird – nicht der Name, wie bei den anderen beiden Listen.
	 *
	 * @param array $item Zeile.
	 * @return string
	 */
	public function column_datum( $item ) {
		$bearbeiten = lsg_bl_win_url(
			array(
				'action' => 'edit',
				'id'     => (int) $item['id'],
			)
		);
		$loeschen   = lsg_bl_win_url(
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

		$datum = $item['date']
			? lsg_bl_format_date( (int) $item['date'] )
			: __( 'ohne Datum', 'lsg-bestenliste' );

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $bearbeiten ),
			esc_html( $datum ),
			$this->row_actions( $aktionen )
		);
	}
}
