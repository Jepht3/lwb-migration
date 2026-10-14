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
	const MAX_CHUNK  = 4194304;

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
		$masked = ! empty( $_GET['m'] );
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
		$expected = Security::sign( $data['secret'], self::signature_base( $op, $kid, $ts, $nonce, $offset, $extra, $body, $masked ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			self::reply_error( 'Signature invalide : la requête a été refusée.', 'Copiez à nouveau la clé de migration.', 403 );
		}
		if ( $masked ) {
			$body = self::mask( $body, $data['secret'], $nonce );
		}

		try {
			switch ( $op ) {
				case 'hello':
					wp_send_json( array_merge( array( 'ok' => true ), self::receiver_info() ) );
					break;
				case 'upload':
					wp_send_json( self::receive_chunk( $kid, $offset, $body ) );
					break;
				case 'installer':
					wp_send_json( self::receive_installer( $kid, $body ) );
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
	 * @param string $body   Corps (tel que transmis).
	 * @param bool   $masked Corps brouillé (voir mask()).
	 * @return string
	 */
	private static function signature_base( $op, $kid, $ts, $nonce, $offset, $extra, $body, $masked = false ) {
		$parts = array( 'lwb-migration', $op, $kid, (string) $ts, $nonce, (string) $offset, $extra, hash( 'sha256', $body ) );
		if ( $masked ) {
			$parts[] = 'm1';
		}
		return implode( "\n", $parts );
	}

	/**
	 * Brouille (ou rétablit : l'opération est symétrique) un corps de requête.
	 *
	 * Les pare-feux d'hébergeurs (o2switch, Imunify360, ModSecurity…) inspectent le contenu
	 * des requêtes et rejettent en 403 celles qui ressemblent à du code PHP malveillant :
	 * une archive de site, qui contient des extensions entières, finit toujours par en
	 * contenir. Le corps est donc combiné (XOR) à un flux dérivé du secret et de l'aléa de
	 * la requête : il ne présente plus aucun motif reconnaissable. La confidentialité reste
	 * assurée par HTTPS et l'intégrité par la signature HMAC.
	 *
	 * @param string $data   Données.
	 * @param string $secret Secret partagé.
	 * @param string $nonce  Aléa de la requête.
	 * @return string
	 */
	public static function mask( $data, $secret, $nonce ) {
		$len = strlen( $data );
		if ( 0 === $len ) {
			return $data;
		}
		$block = '';
		$seed  = hash_hmac( 'sha256', 'mask|' . $nonce, $secret, true );
		for ( $i = 0; $i < 256; $i++ ) {
			$block .= hash_hmac( 'sha256', pack( 'N', $i ), $seed, true );
		}
		$stream = str_repeat( $block, (int) ceil( $len / 8192 ) );
		return $data ^ substr( $stream, 0, $len );
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
			'site_url'         => site_url(),
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'plugin_version'   => LWB_MIGRATION_VERSION,
			'format_version'   => Exporter::FORMAT_VERSION,
			'chunk'            => max( self::MIN_CHUNK, $chunk ),
			'zlib'             => function_exists( 'gzinflate' ),
			'mask'             => 1,
			// 1.3.0+ : accepte l'installeur de la source (voir receive_installer()).
			'installer_upload' => 1,
		);
	}

	/**
	 * Reçoit l'installeur portable de la source : le site sera installé avec la version de
	 * l'installeur de la source, même si l'extension de ce site est plus ancienne.
	 *
	 * Le code est authentifié par la signature HMAC de la requête, comme l'archive (qui
	 * contient elle-même du code PHP), et n'est écrit qu'à la finalisation.
	 *
	 * @param string $kid  Clé.
	 * @param string $code Code de l'installeur portable.
	 * @return array
	 * @throws Migration_Exception Code invalide ou écriture impossible.
	 */
	private static function receive_installer( $kid, $code ) {
		if ( ! Installer_Builder::is_portable( $code ) ) {
			throw new Migration_Exception( 'Installeur reçu invalide.', 'Mettez à jour Lwb Migration sur les deux sites, puis relancez la migration directe.' );
		}
		if ( false === @file_put_contents( self::incoming_dir( $kid ) . '/installer.src', $code ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new Migration_Exception( 'Écriture impossible sur le site de destination (disque plein ?).', 'Libérez de l’espace disque sur le serveur de destination.' );
		}
		return array( 'ok' => true );
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
		// Installeur de la source s'il a été transmis (1.3.0+), sinon celui de ce site.
		$portable_file = dirname( $part ) . '/installer.src';
		$portable      = is_file( $portable_file ) ? (string) file_get_contents( $portable_file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( null !== $portable && ! Installer_Builder::is_portable( $portable ) ) {
			$portable = null;
		}
		Installer_Builder::build( $root . '/' . $installer, $config, $portable );
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
	 * Les données partent en PUT par défaut : de nombreux pare-feu d'hébergeurs (o2switch
	 * « PowerBoost », Imunify360, Sucuri…) imposent un test anti-robot à toute requête POST
	 * venant d'un serveur, mais laissent passer PUT. Si la destination refuse PUT, repli
	 * automatique en POST. La méthode qui fonctionne est mémorisée dans $remote['method'].
	 * Les limitations de débit (HTTP 429) sont absorbées par une attente puis un nouvel essai.
	 *
	 * @param array  $remote Informations de la clé (modifiées : méthode retenue, cadence).
	 * @param string $op     Opération.
	 * @param string $offset Offset.
	 * @param string $extra  Paramètre supplémentaire.
	 * @param string $body   Corps.
	 * @return array Réponse JSON décodée.
	 * @throws Migration_Exception Erreur réseau ou refus.
	 */
	private static function request( array &$remote, $op, $offset = '0', $extra = '', $body = '' ) {
		$methods = ! empty( $remote['method'] ) ? array( (string) $remote['method'] ) : array( 'PUT', 'POST' );
		$code    = 0;
		$raw     = '';
		$json    = null;
		foreach ( $methods as $method ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$res = self::send( $remote, $method, $op, $offset, $extra, $body );
				if ( is_wp_error( $res ) ) {
					throw new Migration_Exception(
						sprintf( /* translators: %s: erreur réseau. */ __( 'Le site de destination est injoignable : %s', 'lwb-migration' ), $res->get_error_message() ),
						__( 'Vérifiez que le site de destination est en ligne et que son certificat SSL est valide. Le transfert reprendra automatiquement.', 'lwb-migration' ),
						true
					);
				}
				$code = (int) wp_remote_retrieve_response_code( $res );
				$raw  = (string) wp_remote_retrieve_body( $res );
				$json = json_decode( $raw, true );
				if ( 429 !== $code ) {
					break;
				}
				// Limitation de débit de l'hébergeur : on ralentit et on réessaie.
				$remote['pace'] = 1;
				$wait           = (int) wp_remote_retrieve_header( $res, 'retry-after' );
				$wait           = $wait > 0 ? min( 15, $wait ) : 3 * ( $attempt + 1 );
				if ( $attempt < 2 ) {
					sleep( $wait );
				}
			}
			if ( is_array( $json ) ) {
				$remote['method'] = $method;
				break;
			}
			if ( 429 === $code ) {
				break;
			}
		}
		if ( 429 === $code ) {
			throw new Migration_Exception(
				__( 'L’hébergeur du site de destination limite temporairement le nombre de requêtes (HTTP 429).', 'lwb-migration' ),
				__( 'Rien à faire : le transfert ralentit sa cadence et reprend automatiquement.', 'lwb-migration' ),
				true
			);
		}
		if ( ! is_array( $json ) ) {
			if ( self::is_firewall_page( $code, $raw ) ) {
				throw new Migration_Exception(
					sprintf( /* translators: %d: code HTTP. */ __( 'Le pare-feu de l’hébergeur du site de destination bloque le transfert (HTTP %d).', 'lwb-migration' ), $code ),
					self::firewall_solution( $op, $remote ),
					false
				);
			}
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
	 * Conseil adapté quand un pare-feu bloque la requête.
	 *
	 * @param string $op     Opération.
	 * @param array  $remote Informations de la clé.
	 * @return string
	 */
	private static function firewall_solution( $op, array $remote ) {
		if ( 'upload' === $op && empty( $remote['mask'] ) ) {
			return __( 'Le pare-feu inspecte le contenu de l’archive. Mettez à jour Lwb Migration sur le site de destination (version 1.2.0 ou plus récente, qui rend les envois méconnaissables pour les pare-feux), puis cliquez sur Reprendre.', 'lwb-migration' );
		}
		$ip  = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		$who = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
			/* translators: %s: adresse IP publique du serveur source. */
			? sprintf( __( 'l’adresse IP de ce serveur (%s)', 'lwb-migration' ), $ip )
			: __( 'l’adresse IP de ce serveur (indiquée dans cPanel › Informations générales)', 'lwb-migration' );
		return sprintf(
			/* translators: %s: adresse IP du serveur source. */
			__( 'Autorisez %s dans la protection de l’hébergeur de destination (chez o2switch : cPanel › Outils o2switch › Sécurité), ou désactivez-la le temps de la migration, puis cliquez sur Reprendre. Vous pouvez aussi utiliser l’export classique (archive + installer.php).', 'lwb-migration' ),
			$who
		);
	}

	/**
	 * Envoie une requête signée (nouvel horodatage et nouvel aléa à chaque essai).
	 *
	 * @param array  $remote Informations de la clé.
	 * @param string $method PUT ou POST.
	 * @param string $op     Opération.
	 * @param string $offset Offset.
	 * @param string $extra  Paramètre supplémentaire.
	 * @param string $body   Corps.
	 * @return array|\WP_Error
	 */
	private static function send( array $remote, $method, $op, $offset, $extra, $body ) {
		$ts     = time();
		$nonce  = Runtime::random_hex( 16 );
		$masked = '' !== $body && ! empty( $remote['mask'] );
		if ( $masked ) {
			$body = self::mask( $body, $remote['secret'], $nonce );
		}
		$sig  = Security::sign( $remote['secret'], self::signature_base( $op, $remote['kid'], $ts, $nonce, $offset, $extra, $body, $masked ) );
		$args = array(
			'action' => 'lwb_migration_receive',
			'op'     => $op,
			'kid'    => $remote['kid'],
			'ts'     => $ts,
			'n'      => $nonce,
			'offset' => $offset,
			'x'      => rawurlencode( $extra ),
			'sig'    => $sig,
		);
		if ( $masked ) {
			$args['m'] = 1;
		}
		$url = add_query_arg( $args, $remote['url'] );
		return wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 120,
				'redirection' => 0,
				'body'        => $body,
				'user-agent'  => 'LwbMigration/' . LWB_MIGRATION_VERSION . '; ' . home_url( '/' ),
				'headers'     => array(
					'Content-Type' => 'application/octet-stream',
					'Accept'       => 'application/json',
				),
				'sslverify'   => (bool) apply_filters( 'lwb_migration_sslverify', true ),
			)
		);
	}

	/**
	 * La réponse est-elle une page de pare-feu / test anti-robot ?
	 *
	 * @param int    $code Code HTTP.
	 * @param string $body Corps.
	 * @return bool
	 */
	private static function is_firewall_page( $code, $body ) {
		if ( ! in_array( $code, array( 403, 406, 409, 503 ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/security check|test de s[ée]curit[ée]|captcha|challenge|are you a (human|robot)|access denied|sucuri|imunify|wordfence|cloudflare|mod_security|not acceptable|forbidden/i', $body );
	}

	/**
	 * Vérifie la destination avant de lancer l'export.
	 *
	 * @param array $remote Clé décodée (complétée avec la méthode HTTP retenue).
	 * @return array Informations de la destination.
	 * @throws Migration_Exception Incompatibilité.
	 */
	public static function hello( array &$remote ) {
		$info = self::request( $remote, 'hello' );
		// Destination capable de rétablir les corps brouillés (1.2.0+).
		$remote['mask'] = ! empty( $info['mask'] ) ? 1 : 0;

		// Destination capable de recevoir l'installeur de la source (1.3.0+).
		$remote['installer_upload'] = ! empty( $info['installer_upload'] ) ? 1 : 0;

		// Avant 1.3.0, l'installeur est construit par l'extension de la destination : une
		// version plus ancienne installerait le site sans les correctifs de cette version
		// (ex. 1.2.2 : caches Elementor non vidés, liens vers l'ancien domaine, CSS introuvables).
		$remote_version = isset( $info['plugin_version'] ) ? (string) $info['plugin_version'] : '';
		if ( empty( $remote['installer_upload'] ) && self::destination_outdated( $remote_version, LWB_MIGRATION_VERSION ) ) {
			throw new Migration_Exception(
				sprintf(
					/* translators: 1: version de la destination, 2: version de ce site. */
					__( 'Le site de destination utilise Lwb Migration %1$s, une version plus ancienne que celle de ce site (%2$s) : il installerait le site sans les derniers correctifs.', 'lwb-migration' ),
					'' !== $remote_version ? $remote_version : '?',
					LWB_MIGRATION_VERSION
				),
				sprintf(
					/* translators: %s: version de ce site. */
					__( 'Mettez à jour Lwb Migration sur le site de destination (Extensions › Mises à jour, ou bouton « Vérifier » de l’extension) vers la version %s ou plus récente, puis relancez la migration directe.', 'lwb-migration' ),
					LWB_MIGRATION_VERSION
				)
			);
		}
		if ( Exporter::FORMAT_VERSION !== (int) $info['format_version'] ) {
			throw new Migration_Exception(
				__( 'Les versions de Lwb Migration des deux sites sont incompatibles.', 'lwb-migration' ),
				__( 'Mettez à jour l’extension sur les deux sites.', 'lwb-migration' )
			);
		}
		return $info;
	}

	/**
	 * La destination a-t-elle une version de l'extension plus ancienne que la source ?
	 *
	 * Une version absente ou illisible est considérée comme ancienne.
	 *
	 * @param string $remote_version Version annoncée par la destination.
	 * @param string $local_version  Version de ce site (source).
	 * @return bool
	 */
	public static function destination_outdated( $remote_version, $local_version ) {
		$remote_version = trim( (string) $remote_version );
		if ( ! preg_match( '/^\d+(\.\d+)*([-+.][0-9A-Za-z.\-]+)?$/', $remote_version ) ) {
			return true;
		}
		return version_compare( $remote_version, (string) $local_version, '<' );
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
		if ( ! isset( $d['direct']['mask'] ) ) {
			// Tâche démarrée avec une version antérieure : on redemande les capacités de la destination.
			self::hello( $d['direct'] );
		}
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
				if ( ! empty( $d['direct']['pace'] ) ) {
					sleep( 1 ); // Hébergeur qui limite le débit : on espace les envois.
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
		$d = &$job->data;
		if ( ! empty( $d['direct']['installer_upload'] ) ) {
			// La destination installera le site avec l'installeur de cette version.
			self::request( $d['direct'], 'installer', '0', '', Installer_Builder::render_portable() );
			$job->logger()->info( 'Installeur de la source transmis à la destination.' );
		}
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
