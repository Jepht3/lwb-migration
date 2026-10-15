<?php
/**
 * Désinstallation : supprime les réglages et le dossier de stockage (paquets compris).
 *
 * @package LwbMigration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lwb_migration_key = get_option( 'lwb_migration_storage_key' );
if ( is_string( $lwb_migration_key ) && preg_match( '/^[a-f0-9]{20}$/', $lwb_migration_key ) ) {
	$lwb_migration_uploads = wp_upload_dir( null, false );
	$lwb_migration_dir     = trailingslashit( $lwb_migration_uploads['basedir'] ) . 'lwb-migration-' . $lwb_migration_key;
	if ( is_dir( $lwb_migration_dir ) ) {
		require_once __DIR__ . '/includes/class-migration-exception.php';
		require_once __DIR__ . '/includes/class-runtime.php';
		require_once __DIR__ . '/includes/class-json-store.php';
		\LwbMigration\Json_Store::delete_dir( $lwb_migration_dir );
	}
}
delete_option( 'lwb_migration_settings' );
delete_option( 'lwb_migration_storage_key' );
delete_option( 'lwb_migration_direct_key' );
delete_option( 'lwb_migration_import' );
