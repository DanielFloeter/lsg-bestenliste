<?php
/**
 * „Details anzeigen" statt „Besuche die Plugin-Website" auf der Seite Plugins.
 *
 * ⚠ WordPress schreibt „Besuche die Plugin-Website" von sich aus in die
 * Zeilen-Fußzeile, sobald der Plugin-Header „Plugin URI" gesetzt ist (hier:
 * das GitHub-Repository) – das ist kein Text, den man im Plugin selbst
 * findet, er kommt aus class-wp-plugins-list-table.php. Diese Datei ersetzt
 * genau diesen einen Eintrag durch einen „Details anzeigen"-Link, der ein
 * Thickbox-Fenster öffnet, wie bei einem Plugin aus dem offiziellen
 * Verzeichnis.
 *
 * ⚠ Weil dieses Plugin nicht im Verzeichnis liegt, kennt WordPress dessen
 * Angaben nicht – ohne den plugins_api-Filter unten bliebe das
 * Thickbox-Fenster leer oder zeigte (bei Namensgleichheit) ein fremdes
 * Plugin aus dem Verzeichnis. Der Filter liefert die Angaben stattdessen
 * direkt aus dem eigenen Plugin-Header.
 *
 * @package lsg-bestenliste
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ersetzt den Plugin-URI-Link in der Zeilen-Fußzeile durch „Details anzeigen".
 *
 * @param string[] $meta         Die Zeilen-Fußzeile (Version | Autor | ...).
 * @param string   $plugin_file  z.B. „lsg-bestenliste/lsg-bestenliste.php".
 * @param array    $plugin_data  Rückgabe von get_plugin_data().
 * @return string[]
 */
function lsg_bl_plugin_row_meta( $meta, $plugin_file, $plugin_data = array() ) {
	if ( plugin_basename( LSG_BL_PATH . 'lsg-bestenliste.php' ) !== $plugin_file ) {
		return $meta;
	}

	/*
	 * ⚠ Nicht am Linktext („Visit plugin site" bzw. „Besuche die
	 * Plugin-Website" je nach Sprache) erkennen, sondern an der URL selbst –
	 * die kommt unverändert aus $plugin_data['PluginURI'] und ist damit
	 * sprachunabhängig.
	 */
	if ( ! empty( $plugin_data['PluginURI'] ) ) {
		foreach ( $meta as $i => $eintrag ) {
			if ( false !== strpos( $eintrag, esc_url( $plugin_data['PluginURI'] ) ) ) {
				unset( $meta[ $i ] );
			}
		}
	}

	$meta[] = sprintf(
		'<a href="%1$s" class="thickbox open-plugin-details-modal" aria-label="%2$s">%3$s</a>',
		esc_url(
			add_query_arg(
				array(
					'tab'       => 'plugin-information',
					'plugin'    => 'lsg-bestenliste',
					'TB_iframe' => 'true',
					'width'     => 600,
					'height'    => 550,
				),
				self_admin_url( 'plugin-install.php' )
			)
		),
		esc_attr__( 'Mehr Details zu LSG Bestenliste anzeigen', 'lsg-bestenliste' ),
		esc_html__( 'Details anzeigen', 'lsg-bestenliste' )
	);

	return array_values( $meta );
}
add_filter( 'plugin_row_meta', 'lsg_bl_plugin_row_meta', 10, 3 );

/**
 * Liefert die Angaben für das Thickbox-Fenster aus dem Plugin-Header, statt
 * WordPress die (fremde) plugins.org-API fragen zu lassen.
 *
 * @param false|object|array $ergebnis Vorgabe – unverändert für jedes andere Plugin.
 * @param string             $aktion   z.B. 'plugin_information'.
 * @param object             $args     u.a. $args->slug.
 * @return false|object
 */
function lsg_bl_plugins_api( $ergebnis, $aktion, $args ) {
	if ( 'plugin_information' !== $aktion || empty( $args->slug ) || 'lsg-bestenliste' !== $args->slug ) {
		return $ergebnis;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$daten = get_plugin_data( LSG_BL_PATH . 'lsg-bestenliste.php', false, false );

	return (object) array(
		'name'         => $daten['Name'],
		'slug'         => 'lsg-bestenliste',
		'version'      => $daten['Version'],
		'author'       => empty( $daten['AuthorURI'] )
			? $daten['Author']
			: sprintf( '<a href="%1$s">%2$s</a>', esc_url( $daten['AuthorURI'] ), esc_html( $daten['Author'] ) ),
		'homepage'     => $daten['PluginURI'],
		'requires'     => isset( $daten['RequiresWP'] ) ? $daten['RequiresWP'] : '',
		'requires_php' => isset( $daten['RequiresPHP'] ) ? $daten['RequiresPHP'] : '',
		'sections'     => array(
			'description' => wpautop( esc_html( $daten['Description'] ) ),
		),
	);
}
add_filter( 'plugins_api', 'lsg_bl_plugins_api', 10, 3 );
