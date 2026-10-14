<?php
/**
 * Migration directe de serveur à serveur.
 *
 * 1. Sur le site de destination, l'extension génère une clé (valable 24 h, usage unique)
 *    contenant l'URL de réception et un secret.
 * 2. Le site source exporte un paquet puis l'envoie en morceaux (≤ post_max_size) par
 *    HTTPS. Chaque requête est signée en HMAC-SHA256 (opération, horodatage, offset,
 *    empreinte SHA-256 du morceau) : rien n'est accepté sans la clé.
 * 3. La destination dépose l'archive et un installer.php à sa racine, protégé par un
 *    jeton à usage unique, puis l'utilisateur est redirigé vers l'installeur qui se
 *    déroule automatiquement avec les identifiants de base de données existants.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Transfert direct.
 */
class Direct_Transfer {

	const OPTION     = 'lwb_migration_direct_key';
	const KEY_PREFIX = 'LWB1';
	const MAX_SKEW   = 900;
	const MIN_CHUNK  = 262144;
	const MAX_CHUNK  = 8388608;

	// ------------------------------------------------------------------
	// Destination : clé et réception.
	// ------------------------------------------------------------------

	/**
	 * Génère une nouvelle clé de migration (remplace la précédente).
	 *
	 * @return array key (chaîne à copier), expires.
	 */
	public static function generate_key() {
		$data = array(
			'kid'     => Runtime::random_hex( 16 ),
			'secret'  => Runtime::random_hex( 64 ),
			'expires' => time() + DAY_IN_SECONDS,
			'created' => time(),
		);
		update_option( self::OPTION, $data, false );
		$payload = (string) wp_json_encode(
			array(
				'u' => admin_url( 'admin-ajax.php' ),
				'k' => $data['kid'],
				's' => $data['secret'],
				'e' => $data['expires'],
				'v' => Exporter::FORMAT_VERSION,
			),
			JSON_UNESCAPED_SLASHES
		);
		$key     = self::KEY_PREFIX . '.' . Security::b64url_encode( $payload ) . '.' . Security::b64url_encode( hash_hmac( 'sha256', $payload, $data['secret'], true ) );
		return array(
			'key'     => $key,
			'expires' => $data['expires'],
		);
	}

	/**
	 * Clé active (sans le secret).
	 *
	 * @return array|null
	 */
	public static function active_key() {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || empty( $data['expires'] ) ) {
			return null;
		}
		if ( $data['expires'] < time() ) {
			delete_option( self::OPTION );
			return null;
		}
		return array(
			'expires' => (int) $data['expires'],
			'created' => (int) $data['created'],
		);
	}

	/**
	 * Révoque la clé.
	 *
	 * @return void
	 */
	public static function revoke() {
		delete_option( self::OPTION );
	}

	/**
	 * Le transport HTTP non chiffré est-il autorisé (tests locaux uniquement) ?
	 *
	 * @return bool
	 */
	public static function allow_http() {
		return defined( 'LWB_MIGRATION_ALLOW_HTTP' ) && LWB_MIGRATION_ALLOW_HTTP;
	}

	/**
	 * Décode et vérifie une clé collée sur le site source.
	 *
	 * @param string $key Clé.
	 * @return array url, kid, secret, expires.
	 * @throws Migration_Exception Clé invalide ou expirée.
	 */
	public static function parse_key( $key ) {
		$invalid = new Migration_Exception(
			__( 'La clé de migration est invalide.', 'lwb-migration' ),
			__( 'Copiez à nouveau la clé complète depuis l’onglet « Migration directe » du site de destination.', 'lwb-migration' )
		);
		$parts   = explode( '.', trim( preg_replace( '/\s+/', '', (string) $key ) ) );
		if ( 3 !== count( $parts ) || self::KEY_PREFIX !== $parts[0] ) {
			throw $invalid;
		}
		$payload = Security::b64url_decode( $parts[1] );
		$sig     = Security::b64url_decode( $parts[2] );
		$data    = false === $payload ? null : json_decode( $payload, true );
		if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['k'] ) || empty( $data['s'] ) || empty( $data['e'] ) || false === $sig ) {
			throw $invalid;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $payload, (string) $data['s'], true ), $sig ) ) {
			throw $invalid;
		}
		if ( (int) $data['e'] < time() ) {
			throw new Migration_Exception(
				__( 'La clé de migration a expiré (validité : 24 h).', 'lwb-migration' ),
				__( 'Générez une nouvelle clé sur le site de destination.', 'lwb-migration' )
			);
		}
		$url = esc_url_raw( (string) $data['u'] );
		if ( 0 !== strpos( $url, 'https://' ) && ! self::allow_http() ) {
			throw new Migration_Exception(
				__( 'Le site de destination n’utilise pas HTTPS : le transfert direct est refusé pour protéger vos données.', 'lwb-migration' ),
				__( 'Activez un certificat SSL sur le site de destination (Let’s Encrypt est gratuit chez la plupart des hébergeurs), ou utilisez l’export classique (archive + installer.php).', 'lwb-migration' )
			);
		}
		if ( untrailingslashit( $url ) === untrailingslashit( admin_url( 'admin-ajax.php' ) ) ) {
			throw new Migration_Exception(
				__( 'Cette clé appartient à ce site : impossible de migrer un site vers lui-même.', 'lwb-migration' ),
				__( 'Générez la clé sur le site de destination, puis collez-la ici, sur le site source.', 'lwb-migration' )
			);
		}
		return array(
			'url'     => $url,
			'kid'     => (string) $data['k'],
			'secret'  => (string) $data['s'],
			'expires' => (int) $data['e'],
		);
	}

	/**
	 * Point d'entrée de réception (admin-ajax, sans session : authentifié par HMAC).
	 *
	 * @return void
	 */
	public static function handle_receive() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authentification HMAC ci-dessous.
		$op     = isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : '';
		$kid    = isset( $_GET['kid'] ) ? sanitize_key( wp_unslash( $_GET['kid'] ) ) : '';
		$ts     = isset( $_GET['ts'] ) ? absint( $_GET['ts'] ) : 0;
		$nonce  = isset( $_GET['n'] ) ? sanitize_key( wp_unslash( $_GET['n'] ) ) : '';
		$offset = isset( $_GET['offset'] ) ? sanitize_text_field( wp_unslash( $_GET['offset'] ) ) : '0';
		$extra  = isset( $_GET['x'] ) ? sanitize_text_field( wp_unslash( $_GET['x'] ) ) : '';
		$sig    = isset( $_GET['sig'] ) ? sanitize_key( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable

		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || empty( $data['secret'] ) || ! hash_equals( (string) $data['kid'], $kid ) ) {
			self::reply_error( 'Clé de migration inconnue ou révoquée sur le site de destination.', 'Générez une nouvelle clé sur le site de destination.', 403 );
		}
		if ( (int) $data['expires'] < time() ) {
			self::reply_error( 'Clé de migration expirée.', 'Générez une nouvelle clé sur le site de destination.', 403 );
		}
		if ( abs( time() - $ts ) > self::MAX_SKEW ) {
			self::reply_error( 'Horloges des deux serveurs trop éloignées (ou requête rejouée).', 'Vérifiez l’heure système des serveurs puis relancez.', 403 );
		}
		if ( ! is_ssl() && ! self::allow_http() ) {
			self::reply_error( 'Le transfert direct exige HTTPS.', 'Activez un certificat SSL sur le site de destination.', 403 );
		}
		$body     = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$expected = Security::sign( $data['secret'], self::signature_base( $op, $kid, $ts, $nonce, $offset, $extra, $body ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			self::reply_error( 'Signature invalide : la requête a été refusée.', 'Copiez à nouveau la clé de migration.', 403 );
		}

		try {
			switch ( $op ) {
				case 'hello':
					wp_send_json( array_merge( array( 'ok' => true ), self::receiver_info() ) );
					break;
				case 'upload':
					wp_send_json( self::receive_chunk( $kid, $offset, $body ) );
					break;
				case 'finalize':
					wp_send_json( self::receive_finalize( $data, $extra ) );
					break;
			}
		} catch ( Migration_Exception $e ) {
			self::reply_error( $e->getMessage(), $e->get_solution(), 500 );
		}
		self::reply_error( 'Opération inconnue.', '', 400 );
	}

	/**
	 * Réponse d'erreur JSON.
	 *
	 * @param string $message  Message.
	 * @param string $solution Solution.
	 * @param int    $code     Code HTTP.
	 * @return void
	 */
	private static function reply_error( $message, $solution, $code ) {
		wp_send_json(
			array(
				'ok'       => false,
				'message'  => $message,
				'solution' => $solution,
			),
			$code
		);
	}

	/**
	 * Base de signature commune aux deux côtés.
	 *
	 * @param string $op     Opération.
	 * @param string $kid    Identifiant de clé.
	 * @param int    $ts     Horodatage.
	 * @param string $nonce  Aléa.
	 * @param string $offset Offset.
	 * @param string $extra  Paramètre supplémentaire.
	 * @param string $body   Corps.
	 * @return string
	 */
	private static function signature_base( $op, $kid, $ts, $nonce, $offset, $extra, $body ) {
		return implode( "\n", array( 'lwb-migration', $op, $kid, (string) $ts, $nonce, (string) $offset, $extra, hash( 'sha256', $body ) ) );
	}

	/**
	 * Informations du site de destination.
	 *
	 * @return array
	 */
	private static function receiver_info() {
		$post_max = Runtime::to_bytes( (string) ini_get( 'post_max_size' ) );
		$mem      = Runtime::memory_limit_bytes();
		$chunk    = self::MAX_CHUNK;
		if ( $post_max > 0 ) {
			$chunk = min( $chunk, $post_max - 65536 );
		}
		if ( $mem > 0 ) {
			$chunk = min( $chunk, (int) ( $mem / 8 ) );
		}
		return array(
			'site_url'       => site_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'plugin_version' => LWB_MIGRATION_VERSION,
			'format_version' => Exporter::FORMAT_VERSION,
			'chunk'          => max( self::MIN_CHUNK, $chunk ),
			'zlib'           => function_exists( 'gzinflate' ),
		);
	}

	/**
	 * Dossier de réception.
	 *
	 * @param string $kid Clé.
	 * @return string
	 */
	private static function incoming_dir( $kid ) {
		$dir = Security::storage_dir( 'incoming' ) . '/' . $kid;
		Json_Store::ensure_protected_dir( $dir );
		return $dir;
	}

	/**
	 * Reçoit un morceau.
	 *
	 * @param string $kid    Clé.
	 * @param string $offset Offset annoncé.
	 * @param string $body   Données.
	 * @return array
	 * @throws Migration_Exception Écriture impossible.
	 */
	private static function receive_chunk( $kid, $offset, $body ) {
		$file = self::incoming_dir( $kid ) . '/archive.part';
		clearstatcache();
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( (int) $offset !== $size ) {
			// Désynchronisation (morceau rejoué ou perdu) : on indique où reprendre.
			return array(
				'ok'       => true,
				'size'     => $size,
				'resynced' => true,
			);
		}
		$h = @fopen( $file, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h || strlen( $body ) !== fwrite( $h, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( $h ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				self::truncate( $file, $size );
			}
			throw new Migration_Exception( 'Écriture impossible sur le site de destination (disque plein ?).', 'Libérez de l’espace disque sur le serveur de destination.' );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'ok'   => true,
			'size' => $size + strlen( $body ),
		);
	}

	/**
	 * Finalise la réception : dépose l'archive et l'installeur à la racine du site.
	 *
	 * @param array  $key   Clé stockée.
	 * @param string $extra JSON : name, size.
	 * @return array
	 * @throws Migration_Exception Archive incomplète ou écriture impossible.
	 */
	private static function receive_finalize( array $key, $extra ) {
		global $wpdb;
		$info = json_decode( (string) base64_decode( $extra, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $info ) || empty( $info['name'] ) || ! isset( $info['size'] ) ) {
			throw new Migration_Exception( 'Paramètres de finalisation invalides.', '' );
		}
		$name = basename( (string) $info['name'] );
		if ( ! preg_match( '/^archive-[a-f0-9]{32}\.(zip|lwb)$/', $name ) ) {
			throw new Migration_Exception( 'Nom d’archive invalide.', '' );
		}
		$part = self::incoming_dir( $key['kid'] ) . '/archive.part';
		clearstatcache();
		if ( ! is_file( $part ) || (int) filesize( $part ) !== (int) $info['size'] ) {
			throw new Migration_Exception( 'Archive incomplète sur le site de destination.', 'Relancez la migration directe : le transfert reprendra.' );
		}
		$reader   = new Archive_Reader( $part );
		$entry    = $reader->find( 'lwb-package.json' );
		$manifest = $entry ? json_decode( $reader->read_string( $entry ), true ) : null;
		unset( $reader );
		if ( ! is_array( $manifest ) || empty( $manifest['source'] ) ) {
			throw new Migration_Exception( 'Le paquet reçu ne contient pas de manifeste valide.', 'Relancez la migration directe.' );
		}

		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		if ( ! @rename( $part, $root . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			throw new Migration_Exception( 'Impossible de déposer l’archive à la racine du site de destination.', 'Rendez la racine du site accessible en écriture (755).' );
		}
		$installer = 'installer.php';
		if ( is_file( $root . '/' . $installer ) ) {
			$head = (string) file_get_contents( $root . '/' . $installer, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === strpos( $head, 'LWB_MIGRATION_STANDALONE' ) ) {
				$installer = 'lwb-installer-' . Runtime::random_hex( 12 ) . '.php';
			}
		}
		$token  = Runtime::random_hex( 48 );
		$config = array(
			'version'        => LWB_MIGRATION_VERSION,
			'format_version' => Exporter::FORMAT_VERSION,
			'package'        => $key['kid'],
			'archive'        => $name,
			'archive_size'   => (int) $info['size'],
			'created'        => time(),
			'password_hash'  => '',
			'secret'         => Runtime::random_hex( 64 ),
			'mode'           => 'direct',
			'token_hash'     => hash( 'sha256', $token ),
			'source'         => $manifest['source'],
			'target'         => array(
				'site_url' => untrailingslashit( site_url() ),
				'home_url' => untrailingslashit( home_url() ),
				'abspath'  => $root,
			),
			'db'             => array(
				'host'     => DB_HOST,
				'name'     => DB_NAME,
				'user'     => DB_USER,
				'password' => DB_PASSWORD,
				'prefix'   => $wpdb->base_prefix,
			),
		);
		Installer_Builder::build( $root . '/' . $installer, $config );
		Json_Store::delete_dir( dirname( $part ) );
		// Clé à usage unique.
		delete_option( self::OPTION );
		return array(
			'ok'            => true,
			'installer_url' => add_query_arg( 'lwb_token', $token, site_url( '/' . $installer ) ),
		);
	}

	// ------------------------------------------------------------------
	// Source : envoi.
	// ------------------------------------------------------------------

	/**
	 * Requête signée vers la destination.
	 *
	 * @param array  $remote Informations de la clé.
	 * @param string $op     Opération.
	 * @param string $offset Offset.
	 * @param string $extra  Paramètre supplémentaire.
	 * @param string $body   Corps.
	 * @return array Réponse JSON décodée.
	 * @throws Migration_Exception Erreur réseau ou refus.
	 */
	private static function request( array $remote, $op, $offset = '0', $extra = '', $body = '' ) {
		$ts    = time();
		$nonce = Runtime::random_hex( 16 );
		$sig   = Security::sign( $remote['secret'], self::signature_base( $op, $remote['kid'], $ts, $nonce, $offset, $extra, $body ) );
		$url   = add_query_arg(
			array(
				'action' => 'lwb_migration_receive',
				'op'     => $op,
				'kid'    => $remote['kid'],
				'ts'     => $ts,
				'n'      => $nonce,
				'offset' => $offset,
				'x'      => rawurlencode( $extra ),
				'sig'    => $sig,
			),
			$remote['url']
		);
		$res   = wp_remote_post(
			$url,
			array(
				'timeout'     => 120,
				'redirection' => 0,
				'body'        => $body,
				'headers'     => array( 'Content-Type' => 'application/octet-stream' ),
				'sslverify'   => (bool) apply_filters( 'lwb_migration_sslverify', true ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new Migration_Exception(
				sprintf( /* translators: %s: erreur réseau. */ __( 'Le site de destination est injoignable : %s', 'lwb-migration' ), $res->get_error_message() ),
				__( 'Vérifiez que le site de destination est en ligne et que son certificat SSL est valide. Le transfert reprendra automatiquement.', 'lwb-migration' ),
				true
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			throw new Migration_Exception(
				sprintf( /* translators: %d: code HTTP. */ __( 'Réponse inattendue du site de destination (HTTP %d).', 'lwb-migration' ), $code ),
				413 === $code
					? __( 'Le serveur de destination refuse les envois volumineux : augmentez post_max_size ou client_max_body_size (nginx).', 'lwb-migration' )
					: __( 'Vérifiez que Lwb Migration est activée sur le site de destination et qu’aucun pare-feu ne bloque admin-ajax.php.', 'lwb-migration' ),
				$code >= 500 || 0 === $code
			);
		}
		if ( empty( $json['ok'] ) ) {
			throw new Migration_Exception(
				isset( $json['message'] ) ? (string) $json['message'] : __( 'Le site de destination a refusé la requête.', 'lwb-migration' ),
				isset( $json['solution'] ) ? (string) $json['solution'] : '',
				$code >= 500
			);
		}
		return $json;
	}

	/**
	 * Vérifie la destination avant de lancer l'export.
	 *
	 * @param array $remote Clé décodée.
	 * @return array Informations de la destination.
	 * @throws Migration_Exception Incompatibilité.
	 */
	public static function hello( array $remote ) {
		$info = self::request( $remote, 'hello' );
		if ( Exporter::FORMAT_VERSION !== (int) $info['format_version'] ) {
			throw new Migration_Exception(
				__( 'Les versions de Lwb Migration des deux sites sont incompatibles.', 'lwb-migration' ),
				__( 'Mettez à jour l’extension sur les deux sites.', 'lwb-migration' )
			);
		}
		return $info;
	}

	/**
	 * Envoie des morceaux jusqu'à épuisement du budget.
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 * @throws Migration_Exception Erreur réseau ou archive absente.
	 */
	public static function send_step( Job $job, Runtime $rt ) {
		$d       = &$job->data;
		$archive = $d['package_dir'] . '/' . $d['archive_name'];
		if ( ! isset( $d['transfer'] ) ) {
			$d['transfer'] = array(
				'offset' => 0,
				'size'   => (int) filesize( $archive ),
				'chunk'  => (int) $d['direct']['chunk'],
			);
			$job->logger()->info( sprintf( 'Transfert vers %s : %s en morceaux de %s.', $d['direct']['site_url'], Runtime::format_bytes( $d['transfer']['size'] ), Runtime::format_bytes( $d['transfer']['chunk'] ) ) );
		}
		$h = @fopen( $archive, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			throw new Migration_Exception( __( 'Archive introuvable pour le transfert.', 'lwb-migration' ), __( 'Relancez la migration directe.', 'lwb-migration' ) );
		}
		try {
			$t = &$d['transfer'];
			while ( $t['offset'] < $t['size'] ) {
				// On ne démarre un envoi que s'il reste du temps pour l'attendre.
				if ( $rt->remaining() < 3 && $rt->elapsed() > 0.5 ) {
					break;
				}
				fseek( $h, $t['offset'] );
				$chunk = (string) fread( $h, (int) min( $t['chunk'], $t['size'] - $t['offset'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$res   = self::request( $d['direct'], 'upload', (string) $t['offset'], '', $chunk );
				if ( ! empty( $res['resynced'] ) ) {
					$job->logger()->warning( sprintf( 'Resynchronisation du transfert à %s.', Runtime::format_bytes( $res['size'] ) ) );
				}
				$t['offset'] = min( (int) $res['size'], $t['size'] );
				if ( $rt->should_stop() ) {
					break;
				}
			}
			if ( $t['offset'] >= $t['size'] ) {
				$job->logger()->info( 'Transfert terminé.' );
				$d['phase'] = 'remote';
			}
		} finally {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Demande à la destination de préparer l'installation.
	 *
	 * @param Job $job Tâche.
	 * @return void
	 */
	public static function remote_finalize( Job $job ) {
		$d                  = &$job->data;
		$res                = self::request(
			$d['direct'],
			'finalize',
			'0',
			base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				(string) wp_json_encode(
					array(
						'name' => $d['archive_name'],
						'size' => $d['transfer']['size'],
					)
				)
			)
		);
		$d['remote_result'] = array( 'installer_url' => esc_url_raw( $res['installer_url'] ) );
		$d['phase']         = 'done';
		$job->logger()->info( 'Site de destination prêt : l’installation peut commencer.' );
		Exporter::archive_log( $job );
	}

	/**
	 * Tronque un fichier sans lever d'erreur.
	 *
	 * @param string $file Fichier.
	 * @param int    $size Taille.
	 * @return void
	 */
	private static function truncate( $file, $size ) {
		$h = @fopen( $file, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $h ) {
			ftruncate( $h, $size );
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}
}
