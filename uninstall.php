<?php
/**
 * Odinstalace: smaže nastavení, stav a zámek. Vygenerované feedy v uploads/bombora-feeds
 * zůstávají – mohou na ně být napojené Mergado / Merchant Center.
 *
 * @package Bombora\MergadoWpml
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bmw_settings' );
delete_option( 'bmw_status' );
delete_option( 'bmw_lock' );
