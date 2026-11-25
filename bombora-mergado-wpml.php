<?php
/**
 * Plugin Name:       Bombora Mergado WPML
 * Plugin URI:        https://github.com/bombora-tech/mergado-wpml-support
 * Description:       Samostatný Mergado produktový feed pro každý jazyk WPML. Cron URL s parametrem zg_lang přepne jazyk, hotový feed se uloží jako vlastní soubor. Přehled cron URL a URL feedů v Bombora → Mergado feedy.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Bombora group s.r.o.
 * Author URI:        https://www.zgruzie.cz/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bombora-mergado-wpml
 * Domain Path:       /languages
 * Update URI:        https://github.com/bombora-tech/mergado-wpml-support
 *
 * @package Bombora\MergadoWpml
 */

defined( 'ABSPATH' ) || exit;

// Pojistka proti druhé kopii (např. dřívější mu-plugin zg-mergado-lang-feeds.php).
if ( defined( 'BMW_VERSION' ) || class_exists( 'ZG_Mergado_Lang_Feeds' ) ) {
	return;
}

define( 'BMW_VERSION', '1.0.0' );
define( 'BMW_FILE', __FILE__ );
define( 'BMW_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMW_BASENAME', plugin_basename( __FILE__ ) );

require_once BMW_DIR . 'includes/class-bmw-settings.php';
require_once BMW_DIR . 'includes/class-bmw-cron.php';
require_once BMW_DIR . 'includes/class-bmw-admin.php';

// Aktualizace spravuje Bombora Manager (bombora-tech/bombora-manager).

// Cron request se musí zachytit co nejdřív – dřív, než WPML určí jazyk z URL.
Bombora\MergadoWpml\Cron::init();

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'bombora-mergado-wpml', false, dirname( BMW_BASENAME ) . '/languages' );
	},
	0
);

if ( is_admin() ) {
	Bombora\MergadoWpml\Admin::init();
}
