<?php
/**
 * Import depuis l'administration du site de destination (onglet « Importer »), sans FTP.
 *
 * 1. Le navigateur envoie l'archive d'un export classique par morceaux, chacun plus petit
 *    que upload_max_filesize et post_max_size. Les morceaux sont brouillés (voir
 *    Transfer_Tools::mask()) pour ne pas être bloqués par les pare-feux qui inspectent les
 *    envois. Un envoi interrompu reprend là où il s'est arrêté (même fichier).
 * 2. Une archive chiffrée est déchiffrée par étapes avec le mot de passe de l'installeur.
 * 3. L'archive et un installeur protégé par un jeton à usage unique sont déposés à la
 *    racine, comme en migration directe : l'installation se fait avec la base de ce site.
 *
 * Toutes les actions exigent une session d'administrateur (nonce + manage_options).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Import par morceaux.
 */
class Upload_Import {

	const OPTION   = 'lwb_migration_import';
	const MAX_IDLE = 172800;

	/**
	 * Taille des morceaux envoyés par le navigateur.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		return Transfer_Tools::import_chunk_size(
			Runtime::to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			Runtime::to_bytes( (string) ini_get( 'post_max_size' ) ),
			Runtime::memory_limit_bytes()
		);
	}

	/**
	 * Import en cours (null si aucun ou trop ancien).
	 *
	 * @return array|null
	 */
	private static function current() {
		$cur = get_option( self::OPTION );
		if ( ! is_array( $cur ) || empty( $cur['id'] ) || ! Security::valid_id( $cur['id'] ) ) {
			return null;
		}
		if ( (int) $cur['touched'] < time() - self::MAX_IDLE ) {
			self::discard( $cur );
			return null;
		}
		return $cur;
	}

	/**
	 * Dossier de travail d'un import.
	 *
	 * @param string $id Identifiant.
	 * @return string
	 */
	private static function dir( $id ) {
		$dir = Security::storage_dir( 'incoming' ) . '/import-' . $id;
		Json_Store::ensure_protected_dir( $dir );
		return $dir;
	}

	/**
	 * Supprime un import.
	 *
	 * @param array $cur Import.
	 * @return void
	 */
	private static function discard( array $cur ) {
		if ( ! empty( $cur['id'] ) && Security::valid_id( $cur['id'] ) ) {
			Json_Store::delete_dir( Security::storage_dir( 'incoming' ) . '/import-' . $cur['id'] );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Erreur JSON.
	 *
	 * @param string $message  Message.
	 * @param string $solution Solution.
	 * @return void
	 */
	private static function fail( $message, $solution = '' ) {
		wp_send_json_error(
			array(
				'message'  => $message,
				'solution' => $solution,
			)
		);
	}

	/**
	 * Démarre (ou reprend) l'envoi d'un fichier.
	 *
	 * @return void
	 */
	public static function import_init() {
		Security::check_ajax();
		$name = sanitize_file_name( Security::post_text( 'name' ) );
		$size = absint( Security::post_text( 'size' ) );
		if ( '' === $name || $size <= 0 ) {
			self::fail( __( 'Choisissez l’archive à importer.', 'lwb-migration' ), __( 'Fichier archive-….zip (ou .lwb, .lwbenc) téléchargé depuis l’onglet « Exporter » du site source.', 'lwb-migration' ) );
		}
		try {
			$cur    = self::current();
			$offset = 0;
			if ( null !== $cur && $cur['name'] === $name && (int) $cur['size'] === $size ) {
				// Même fichier : reprise là où l'envoi s'est arrêté.
				$part = self::dir( $cur['id'] ) . '/archive.part';
				clearstatcache();
				$offset = is_file( $part ) ? min( $size, (int) filesize( $part ) ) : 0;
			} else {
				if ( null !== $cur ) {
					self::discard( $cur );
				}
				$cur = array(
					'id'     => Runtime::random_hex( 24 ),
					'secret' => Runtime::random_hex( 64 ),
					'name'   => $name,
					'size'   => $size,
				);
			}
			$cur['touched'] = time();
			update_option( self::OPTION, $cur, false );
			self::dir( $cur['id'] );
		} catch ( Migration_Exception $e ) {
			self::fail( $e->getMessage(), $e->get_solution() );
		}
		wp_send_json_success(
			array(
				'id'     => $cur['id'],
				'secret' => $cur['secret'],
				'offset' => $offset,
				'chunk'  => self::chunk_size(),
				'resume' => $offset > 0,
			)
		);
	}

	/**
	 * Reçoit un morceau.
	 *
	 * @return void
	 */
	public static function import_chunk() {
		Security::check_ajax();
		$cur = self::current();
		if ( null === $cur || ! hash_equals( $cur['id'], Security::post_text( 'id' ) ) ) {
			self::fail( __( 'Cet import est introuvable ou a expiré.', 'lwb-migration' ), __( 'Sélectionnez à nouveau le fichier et relancez l’import.', 'lwb-migration' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce vérifié par check_ajax() ; fichier binaire.
		$upload = isset( $_FILES['chunk'] ) && is_array( $_FILES['chunk'] ) ? $_FILES['chunk'] : null;
		if ( null === $upload || UPLOAD_ERR_OK !== (int) $upload['error'] || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			$code = null === $upload ? -1 : (int) $upload['error'];
			self::fail(
				/* translators: %d: code d'erreur PHP. */
				sprintf( __( 'Le morceau n’a pas été reçu par le serveur (code %d).', 'lwb-migration' ), $code ),
				in_array( $code, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, -1 ), true )
					? __( 'Le serveur limite la taille des envois : rechargez la page (la taille des morceaux sera recalculée), puis relancez l’import ; il reprendra là où il s’est arrêté.', 'lwb-migration' )
					: __( 'Relancez l’import : il reprendra là où il s’est arrêté. Si l’erreur persiste, vérifiez l’espace disque et le dossier temporaire de PHP.', 'lwb-migration' )
			);
		}
		$data = (string) file_get_contents( $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' !== Security::post_text( 'm' ) ) {
			$data = Transfer_Tools::mask( $data, $cur['secret'], sanitize_key( Security::post_text( 'n' ) ) );
		}
		$offset = absint( Security::post_text( 'offset' ) );
		try {
			$part = self::dir( $cur['id'] ) . '/archive.part';
		} catch ( Migration_Exception $e ) {
			self::fail( $e->getMessage(), $e->get_solution() );
		}
		clearstatcache();
		$size = is_file( $part ) ? (int) filesize( $part ) : 0;
		if ( $offset !== $size ) {
			// Morceau rejoué ou perdu : on indique où reprendre.
			wp_send_json_success(
				array(
					'size'     => $size,
					'resynced' => true,
				)
			);
		}
		if ( $size + strlen( $data ) > (int) $cur['size'] ) {
			self::fail( __( 'Le fichier envoyé est plus grand qu’annoncé.', 'lwb-migration' ), __( 'Sélectionnez à nouveau le fichier et relancez l’import.', 'lwb-migration' ) );
		}
		$h = @fopen( $part, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h || strlen( $data ) !== fwrite( $h, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( $h ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$t = @fopen( $part, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( $t ) {
					ftruncate( $t, $size );
					fclose( $t ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
			}
			self::fail( __( 'Écriture impossible sur ce serveur (disque plein ?).', 'lwb-migration' ), __( 'Libérez de l’espace disque, puis relancez l’import : il reprendra là où il s’est arrêté.', 'lwb-migration' ) );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$cur['touched'] = time();
		update_option( self::OPTION, $cur, false );
		wp_send_json_success( array( 'size' => $size + strlen( $data ) ) );
	}

	/**
	 * Archive reçue : déchiffrement éventuel (par étapes), puis dépôt de l'archive et de
	 * l'installeur à la racine. À appeler jusqu'à obtenir status = done.
	 *
	 * @return void
	 */
	public static function import_finalize() {
		Security::check_ajax();
		$cur = self::current();
		if ( null === $cur || ! hash_equals( $cur['id'], Security::post_text( 'id' ) ) ) {
			self::fail( __( 'Cet import est introuvable ou a expiré.', 'lwb-migration' ), __( 'Sélectionnez à nouveau le fichier et relancez l’import.', 'lwb-migration' ) );
		}
		try {
			wp_send_json_success( self::finalize_step( $cur, Security::post_raw( 'password' ) ) );
		} catch ( Migration_Exception $e ) {
			self::fail( $e->getMessage(), $e->get_solution() );
		}
	}

	/**
	 * Une étape de finalisation.
	 *
	 * @param array  $cur      Import en cours.
	 * @param string $password Mot de passe de l'installeur (archive chiffrée).
	 * @return array status (decrypting|done), progress, installer_url.
	 * @throws Migration_Exception Archive incomplète, invalide ou mot de passe incorrect.
	 */
	private static function finalize_step( array $cur, $password ) {
		Runtime::prepare();
		$dir   = self::dir( $cur['id'] );
		$part  = $dir . '/archive.part';
		$plain = $dir . '/archive.plain';
		clearstatcache();
		$file = $part;
		if ( ! is_file( $part ) || (int) filesize( $part ) !== (int) $cur['size'] ) {
			if ( ! is_file( $plain ) ) {
				throw new Migration_Exception( __( 'L’archive n’est pas complète sur ce serveur.', 'lwb-migration' ), __( 'Relancez l’import avec le même fichier : il reprendra là où il s’est arrêté.', 'lwb-migration' ) );
			}
			$file = $plain; // Déchiffrement terminé, archive chiffrée déjà supprimée.
		}
		$kind = Transfer_Tools::archive_kind( (string) file_get_contents( $file, false, null, 0, 8 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 'encrypted' === $kind ) {
			$state = Json_Store::read( $dir . '/decrypt.php' );
			$state = is_array( $state ) ? $state : array( 'index' => 0 );
			if ( empty( $state['key'] ) ) {
				$head = Archive_Crypto::read_header( $part );
				if ( '' === $password ) {
					throw new Migration_Exception( __( 'Cette archive est chiffrée : saisissez le mot de passe de l’installeur.', 'lwb-migration' ), __( 'Il a été affiché à la fin de l’export sur le site source.', 'lwb-migration' ) );
				}
				if ( null === $head || ! Archive_Crypto::available() ) {
					throw new Migration_Exception( __( 'Impossible de déchiffrer l’archive sur ce serveur (OpenSSL indisponible).', 'lwb-migration' ), __( 'Demandez à l’hébergeur d’activer OpenSSL, ou refaites l’export sans chiffrement.', 'lwb-migration' ) );
				}
				$state['key'] = bin2hex( Archive_Crypto::derive_key( $password, $head['salt'], $head['iterations'] ) );
			}
			$crypto = array( 'index' => (int) $state['index'] );
			try {
				$done = Archive_Crypto::decrypt_step( $part, $plain, (string) hex2bin( $state['key'] ), $crypto, new Runtime() );
			} catch ( Migration_Exception $e ) {
				if ( 0 === (int) $state['index'] ) {
					throw new Migration_Exception( __( 'Mot de passe incorrect, ou archive modifiée.', 'lwb-migration' ), __( 'Saisissez le mot de passe affiché à la fin de l’export sur le site source.', 'lwb-migration' ) );
				}
				throw $e;
			}
			$state['index'] = $crypto['index'];
			if ( ! $done ) {
				Json_Store::write( $dir . '/decrypt.php', $state );
				clearstatcache();
				return array(
					'status'   => 'decrypting',
					'progress' => round( 100 * min( 1, (int) filesize( $plain ) / max( 1, (int) $cur['size'] ) ), 1 ),
				);
			}
			wp_delete_file( $part );
			wp_delete_file( $dir . '/decrypt.php' );
			$file = $plain;
			$kind = Transfer_Tools::archive_kind( (string) file_get_contents( $file, false, null, 0, 8 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( ! in_array( $kind, array( 'zip', 'lwb' ), true ) ) {
			throw new Migration_Exception( __( 'Ce fichier n’est pas une archive Lwb Migration.', 'lwb-migration' ), __( 'Importez le fichier archive-….zip (ou .lwb, .lwbenc) créé par l’onglet « Exporter » du site source, pas installer.php.', 'lwb-migration' ) );
		}
		$url = Direct_Transfer::install_package( $file, 'archive-' . Runtime::random_hex( 32 ) . '.' . $kind, $cur['id'] );
		self::discard( $cur );
		return array(
			'status'        => 'done',
			'progress'      => 100,
			'installer_url' => $url,
		);
	}

	/**
	 * Abandonne l'import en cours.
	 *
	 * @return void
	 */
	public static function import_cancel() {
		Security::check_ajax();
		$cur = self::current();
		if ( null !== $cur ) {
			self::discard( $cur );
		}
		wp_send_json_success();
	}
}
