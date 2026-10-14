<?php
/**
 * Plugin Name:       Lwb Migration
 * Plugin URI:        https://lewebmax.com/lwb-migration/
 * Description:       Migrez un site WordPress complet (fichiers + base de données) vers n’importe quel serveur, sans configuration : export en un clic, installer.php autonome ou migration directe de serveur à serveur.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            LEWEBMAX
 * Author URI:        https://lewebmax.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://lewebmax.com/lwb-migration/
 * Text Domain:       lwb-migration
 * Domain Path:       /languages
 *
 * @package LwbMigration
 */

defined( 'ABSPATH' ) || exit;

define( 'LWB_MIGRATION_VERSION', '1.1.0' );
define( 'LWB_MIGRATION_FILE', __FILE__ );
define( 'LWB_MIGRATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'LWB_MIGRATION_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'LwbMigration\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
		$file = LWB_MIGRATION_DIR . 'includes/class-' . $name . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'LwbMigration\\Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'LwbMigration\\Plugin', 'activate' ) );
