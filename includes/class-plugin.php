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
		self::drop_backup_tables();
		set_transient( 'lwb_migration_cleaned', 1, 300 );
	}

	/**
	 * Supprime les copies des tables de l'ancien site laissées par installer.php
	 * (sauvegarde de la destination, préfixe lwbbk_) une fois la migration validée.
	 *
	 * @return void
	 */
	private static function drop_backup_tables() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- liste des tables, sans cache possible.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'lwbbk_' ) . '%' ) );
		foreach ( (array) $tables as $table ) {
			if ( preg_match( '/^lwbbk_[A-Za-z0-9_$]+$/', (string) $table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- nom de table validé ci-dessus (%i exige WordPress 6.2).
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
			}
		}
	}
}
