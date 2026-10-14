<?php
/**
 * Lecture/écriture atomique d'états JSON protégés contre l'accès web.
 *
 * Les fichiers portent l'extension .php et commencent par un exit : même sur un
 * serveur qui ignore .htaccess (nginx), leur contenu n'est jamais servi.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Stockage JSON.
 */
class Json_Store {

	const HEADER = "<?php exit; ?>\n";

	/**
	 * Lit un fichier d'état.
	 *
	 * @param string $file Chemin.
	 * @return array|null Données ou null si absent/illisible.
	 */
	public static function read( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- petit fichier local.
		if ( false === $raw ) {
			return null;
		}
		if ( 0 === strpos( $raw, self::HEADER ) ) {
			$raw = substr( $raw, strlen( self::HEADER ) );
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Écrit un fichier d'état de façon atomique (fichier temporaire + rename).
	 *
	 * @param string $file Chemin.
	 * @param array  $data Données.
	 * @return void
	 * @throws Migration_Exception En cas d'échec d'écriture.
	 */
	public static function write( $file, array $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- autonome.
		if ( false === $json ) {
			throw new Migration_Exception( 'Impossible d’encoder l’état de la migration.', 'Relancez l’opération.' );
		}
		$tmp = $file . '.' . Runtime::random_hex( 8 ) . '.tmp';
		if ( false === file_put_contents( $tmp, self::HEADER . $json, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new Migration_Exception(
				sprintf( 'Impossible d’écrire le fichier %s.', basename( $file ) ),
				'Vérifiez que le dossier est accessible en écriture (permissions 755 ou 775) et que l’espace disque n’est pas saturé.'
			);
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			// Certains systèmes de fichiers (Windows) refusent d'écraser : on supprime puis on renomme.
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				throw new Migration_Exception(
					sprintf( 'Impossible d’enregistrer le fichier %s.', basename( $file ) ),
					'Vérifiez les permissions du dossier de stockage.'
				);
			}
		}
	}

	/**
	 * Crée un dossier protégé (index.php + .htaccess).
	 *
	 * @param string $dir Chemin.
	 * @return void
	 * @throws Migration_Exception Si le dossier ne peut être créé.
	 */
	public static function ensure_protected_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			throw new Migration_Exception(
				sprintf( 'Impossible de créer le dossier %s.', $dir ),
				'Donnez les droits d’écriture au dossier parent (permissions 755) ou contactez votre hébergeur.'
			);
		}
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Lwb Migration : accès interdit\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Supprime récursivement un dossier.
	 *
	 * @param string $dir Chemin.
	 * @return bool
	 */
	public static function delete_dir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return is_link( $dir ) ? @unlink( $dir ) : true; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_dir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
