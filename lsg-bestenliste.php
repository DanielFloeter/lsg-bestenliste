<?php
/**
 * Plugin Name:       LSG Bestenliste
 * Plugin URI:        https://www.lsg-ka.de/
 * Description:       Drei Gutenberg-Blöcke zur Ausgabe der LSG-Karlsruhe Laufergebnisse: Bestenliste (Jahr), Gesamtsiege (Jahr) und Ewige Bestenliste (all-time). Liest aus den bestehenden Tabellen lsg_ak, lsg_athlete, lsg_best und lsg_win.
 * Version:           1.0.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Karlsruher Lemminge
 * Text Domain:       lsg-bestenliste
 * License:           GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Kein direkter Zugriff.
}

define( 'LSG_BL_VERSION', '1.0.0' );
define( 'LSG_BL_PATH', plugin_dir_path( __FILE__ ) );
define( 'LSG_BL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Tabellenpräfix. Die vier Tabellen (lsg_ak, lsg_athlete, lsg_best, lsg_win) wurden
 * bereits ohne WordPress-Präfix in die Datenbank importiert (1:1 aus den
 * phpMyAdmin-Dumps in /assets). Falls du die Tabellen stattdessen mit dem
 * WordPress-Tabellenpräfix (z.B. wp_lsg_ak) angelegt hast, definiere vor dem
 * Laden dieses Plugins die Konstante LSG_BL_USE_WP_PREFIX als true, z.B. in der
 * wp-config.php: define( 'LSG_BL_USE_WP_PREFIX', true );
 */
if ( ! defined( 'LSG_BL_USE_WP_PREFIX' ) ) {
	define( 'LSG_BL_USE_WP_PREFIX', false );
}

require_once LSG_BL_PATH . 'includes/class-lsg-helpers.php';
require_once LSG_BL_PATH . 'includes/class-lsg-db.php';
require_once LSG_BL_PATH . 'includes/render-bestenliste.php';
require_once LSG_BL_PATH . 'includes/render-gesamtsiege.php';
require_once LSG_BL_PATH . 'includes/render-ewige-bestenliste.php';
require_once LSG_BL_PATH . 'includes/class-lsg-rest.php';

/**
 * Liefert den vollen (ggf. präfixierten) Tabellennamen.
 *
 * @param string $name Basisname ohne Präfix, z.B. 'lsg_best'.
 * @return string
 */
function lsg_bl_table( $name ) {
	global $wpdb;
	if ( LSG_BL_USE_WP_PREFIX ) {
		return $wpdb->prefix . $name;
	}
	return $name;
}

/**
 * Aktivierung: legt die vier Tabellen an, falls sie noch nicht existieren
 * (z.B. bei einer frischen Installation ohne vorherigen manuellen Import).
 * Bereits vorhandene Tabellen und Daten werden nicht verändert.
 */
function lsg_bl_activate() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$t_ak      = lsg_bl_table( 'lsg_ak' );
	$t_athlete = lsg_bl_table( 'lsg_athlete' );
	$t_best    = lsg_bl_table( 'lsg_best' );
	$t_win     = lsg_bl_table( 'lsg_win' );

	$sql = "CREATE TABLE {$t_ak} (
		id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
		tstamp int(10) UNSIGNED NOT NULL DEFAULT 0,
		ak varchar(8) NOT NULL DEFAULT '',
		PRIMARY KEY  (id)
	) {$charset_collate};

	CREATE TABLE {$t_athlete} (
		id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
		tstamp int(10) UNSIGNED NOT NULL DEFAULT 0,
		name varchar(30) NOT NULL DEFAULT '',
		firstname varchar(30) NOT NULL DEFAULT '',
		born year(4) NOT NULL DEFAULT 0000,
		cat varchar(1) NOT NULL DEFAULT 'm',
		active varchar(1) NOT NULL DEFAULT '1',
		PRIMARY KEY  (id)
	) {$charset_collate};

	CREATE TABLE {$t_best} (
		id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
		tstamp int(10) UNSIGNED NOT NULL DEFAULT 0,
		distance varchar(15) NOT NULL DEFAULT '',
		time varchar(15) NOT NULL DEFAULT '00:00:00',
		town varchar(30) NOT NULL DEFAULT '',
		date int(10) UNSIGNED DEFAULT NULL,
		athletes_id int(10) UNSIGNED NOT NULL DEFAULT 0,
		ak varchar(10) NOT NULL DEFAULT '',
		PRIMARY KEY  (id)
	) {$charset_collate};

	CREATE TABLE {$t_win} (
		id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
		tstamp int(10) UNSIGNED NOT NULL DEFAULT 0,
		date int(10) UNSIGNED DEFAULT NULL,
		town varchar(30) NOT NULL DEFAULT '',
		event varchar(40) NOT NULL DEFAULT '',
		distance varchar(20) NOT NULL DEFAULT '',
		athletes_id int(10) UNSIGNED NOT NULL DEFAULT 0,
		time varchar(15) NOT NULL DEFAULT '00:00:00',
		PRIMARY KEY  (id)
	) {$charset_collate};";

	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'lsg_bl_activate' );

/**
 * Registriert die Editor-Skripte (edit.js) der drei Blöcke manuell mit den
 * korrekten Abhängigkeiten (u.a. wp-server-side-render), da ohne JS-Build-
 * Schritt kein *.asset.php mit Abhängigkeiten existiert, aus dem block.json
 * dies automatisch ableiten könnte. block.json referenziert diese Handles
 * dann per Name statt per Dateipfad.
 */
function lsg_bl_register_editor_scripts() {
	$deps = array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' );

	$scripts = array(
		'lsg-bestenliste-edit-bestenliste'        => 'blocks/bestenliste/edit.js',
		'lsg-bestenliste-edit-gesamtsiege'        => 'blocks/gesamtsiege/edit.js',
		'lsg-bestenliste-edit-ewige-bestenliste'  => 'blocks/ewige-bestenliste/edit.js',
	);

	foreach ( $scripts as $handle => $rel_path ) {
		wp_register_script(
			$handle,
			LSG_BL_URL . $rel_path,
			$deps,
			LSG_BL_VERSION,
			true
		);
	}
}
add_action( 'init', 'lsg_bl_register_editor_scripts', 5 );

/**
 * Registriert das gemeinsame Frontend-Script unter einem festen Handle.
 * Grund: block.json referenziert es per Handle-Name (nicht per Dateipfad),
 * damit wp_localize_script() unten zuverlässig an dasselbe, tatsächlich
 * enqueue-te Script andockt (die REST-URL/Nonce). Alle drei Blöcke teilen
 * sich dieses eine registrierte Script, WordPress lädt es nur einmal.
 */
function lsg_bl_register_frontend_script() {
	wp_register_script(
		'lsg-bestenliste-frontend',
		LSG_BL_URL . 'assets/js/frontend.js',
		array(),
		LSG_BL_VERSION,
		true
	);
}
add_action( 'init', 'lsg_bl_register_frontend_script', 5 );

/**
 * Registriert die drei Blöcke über ihre block.json (Block API v3, "render"
 * Feld => dynamischer Block ohne JS-Build-Schritt).
 */
function lsg_bl_register_blocks() {
	$blocks = array( 'bestenliste', 'gesamtsiege', 'ewige-bestenliste' );
	foreach ( $blocks as $block ) {
		$path = LSG_BL_PATH . 'blocks/' . $block;
		if ( file_exists( $path . '/block.json' ) ) {
			register_block_type( $path );
		}
	}
}
add_action( 'init', 'lsg_bl_register_blocks', 10 );

/**
 * Übergibt REST-URL und Nonce an das Frontend-Script. Das Enqueuen des
 * Scripts selbst übernimmt WordPress automatisch, sobald einer der drei
 * Blöcke tatsächlich auf der Seite gerendert wird (viewScript aus block.json).
 */
function lsg_bl_localize_frontend() {
	wp_localize_script(
		'lsg-bestenliste-frontend',
		'lsgBestenlisteConfig',
		array(
			'restUrl' => esc_url_raw( rest_url( 'lsg/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'lsg_bl_localize_frontend' );
