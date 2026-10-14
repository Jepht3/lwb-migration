<?php
/**
 * Amorçage de l'extension.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Classe principale.
 */
class Plugin {

	/**
	 * Marqueur déposé par installer.php après une migration réussie.
	 */
	const DONE_MARKER = '.lwb-migration-done';

	/**
	 * Initialisation.
	 *
	 * @return void
	 */
	public static function init() {
		load_plugin_textdomain( 'lwb-migration', false, dirname( plugin_basename( LWB_MIGRATION_FILE ) ) . '/languages' );
		Ajax::register();
		Updater::register();
		if ( is_admin() ) {
			Admin::register();
			add_action( 'admin_init', array( __CLASS__, 'cleanup_installer_leftovers' ) );
		}
	}

	/**
	 * Activation : prépare le dossier de stockage protégé.
	 *
	 * @return void
	 */
	public static function activate() {
		try {
			Security::storage_dir( 'packages' );
			Security::storage_dir( 'jobs' );
		} catch ( \Throwable $e ) {
			// Le message sera affiché lors du premier export.
			unset( $e );
		}
	}

	/**
	 * Supprime les fichiers d'installation restés à la racine après une migration.
	 *
	 * @return void
	 */
	public static function cleanup_installer_leftovers() {
		if ( ! current_user_can( Security::CAPABILITY ) ) {
			return;
		}
		$root   = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$marker = $root . '/' . self::DONE_MARKER;
		if ( ! is_file( $marker ) ) {
			return;
		}
		$list = json_decode( (string) file_get_contents( $marker ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( is_array( $list ) ? $list : array() as $rel ) {
			$rel = Archive_Reader::safe_name( (string) $rel );
			if ( null === $rel || false !== strpos( $rel, '/' ) ) {
				continue; // Uniquement des éléments situés directement à la racine.
			}
			$path = $root . '/' . $rel;
			if ( is_dir( $path ) ) {
				Json_Store::delete_dir( $path );
			} elseif ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		wp_delete_file( $marker );
		set_transient( 'lwb_migration_cleaned', 1, 300 );
	}
}
