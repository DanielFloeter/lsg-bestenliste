<?php
/**
 * Server-Render-Callback für den Block lsg-bestenliste/bestenliste.
 * $attributes, $content, $block stehen laut Block-API automatisch zur Verfügung.
 *
 * @var array $attributes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo lsg_bl_render_bestenliste_block( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
