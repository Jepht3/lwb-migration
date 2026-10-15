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
 * Variantes (1.5.0) :
 * - Mode « pull » : quand le pare-feu de la destination bloque les envois (PUT et POST
 *   entrants), la destination télécharge elle-même l'archive depuis la source (requêtes
 *   GET sortantes, rarement filtrées) via un lien signé, temporaire et reprenable (Range).
 *   Les requêtes de pilotage, sans corps, partent en GET.
 * - Avant le changement de DNS : la destination est jointe par son adresse IP (résolution
 *   forcée, certificat vérifié sur le vrai nom) ou par une URL temporaire ; la source
 *   pilote alors elle-même l'installeur, que le navigateur ne peut pas encore atteindre.
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
	const PULL_CHUNK = 8388608;
	const PULL_TTL   = 21600;

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
				case 'pull':
					wp_send_json( self::receive_pull( $kid, $extra ) );
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
	 * Brouille (ou rétablit) un corps de requête : voir Transfer_Tools::mask().
	 *
	 * @param string $data   Données.
	 * @param string $secret Secret partagé.
	 * @param string $nonce  Aléa de la requête.
	 * @return string
	 */
	public static function mask( $data, $secret, $nonce ) {
		return Transfer_Tools::mask( $data, $secret, $nonce );
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
			// 1.5.0+ : téléchargement par la destination (voir receive_pull()) et URL
			// définitive imposée par la source (voir receive_finalize()).
			'pull'             => 1,
			'final_url'        => 1,
		);
	}

	/**
	 * Mode « pull » : la destination télécharge l'archive depuis la source, par plages
	 * (HTTP Range), jusqu'à épuisement de son budget. Appelée en GET par la source.
	 *
	 * @param string $kid   Clé.
	 * @param string $extra JSON encodé en base64 : u (lien de l'archive), s (taille), i (lien de l'installeur), b (budget en secondes).
	 * @return array ok, size.
	 * @throws Migration_Exception Lien invalide ou téléchargement impossible.
	 */
	private static function receive_pull( $kid, $extra ) {
		$info = json_decode( (string) base64_decode( $extra, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $info ) || empty( $info['u'] ) || empty( $info['s'] ) ) {
			throw new Migration_Exception( 'Paramètres de téléchargement invalides.', 'Mettez à jour Lwb Migration sur les deux sites.' );
		}
		$url       = esc_url_raw( (string) $info['u'] );
		$installer = ! empty( $info['i'] ) ? esc_url_raw( (string) $info['i'] ) : '';
		foreach ( array_filter( array( $url, $installer ) ) as $link ) {
			if ( 0 !== strpos( $link, 'https://' ) && ! self::allow_http() ) {
				throw new Migration_Exception( 'Le site source n’utilise pas HTTPS : le téléchargement est refusé pour protéger vos données.', 'Activez un certificat SSL sur le site source.' );
			}
		}
		Runtime::prepare();
		$budget = isset( $info['b'] ) ? (float) $info['b'] : 0.0;
		$rt     = new Runtime( $budget > 0 ? min( Runtime::default_budget(), max( 1.0, $budget ) ) : null );
		$total  = (int) $info['s'];
		$dir    = self::incoming_dir( $kid );
		$part   = $dir . '/archive.part';
		clearstatcache();
		$size = is_file( $part ) ? (int) filesize( $part ) : 0;
		if ( $size > $total ) {
			// Archive différente (migration relancée) : on repart de zéro.
			self::truncate( $part, 0 );
			$size = 0;
		}
		while ( $size < $total ) {
			$size = self::pull_range( $url, $part, $size, (int) min( self::PULL_CHUNK, $total - $size ) );
			if ( $rt->should_stop() ) {
				break;
			}
		}
		if ( $size >= $total && '' !== $installer && ! is_file( $dir . '/installer.src' ) ) {
			self::pull_installer( $installer, $dir . '/installer.src' );
		}
		return array(
			'ok'   => true,
			'size' => $size,
		);
	}

	/**
	 * Télécharge une plage de l'archive et l'ajoute au fichier partiel.
	 *
	 * @param string $url    Lien signé.
	 * @param string $part   Fichier partiel.
	 * @param int    $offset Début de la plage (taille actuelle du fichier partiel).
	 * @param int    $len    Longueur demandée.
	 * @return int Nouvelle taille.
	 * @throws Migration_Exception Téléchargement impossible.
	 */
	private static function pull_range( $url, $part, $offset, $len ) {
		$tmp = dirname( $part ) . '/pull.tmp';
		wp_delete_file( $tmp );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'             => 120,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $len,
				'user-agent'          => 'LwbMigration/' . LWB_MIGRATION_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'Range' => 'bytes=' . $offset . '-' . ( $offset + $len - 1 ) ),
				'sslverify'           => (bool) apply_filters( 'lwb_migration_sslverify', true ),
			)
		);
		try {
			if ( is_wp_error( $res ) ) {
				throw new Migration_Exception(
					sprintf( 'Le site de destination ne parvient pas à télécharger l’archive depuis le site source : %s', $res->get_error_message() ),
					'Vérifiez que le site source est en ligne et que son certificat SSL est valide. Le transfert reprendra automatiquement.',
					true
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 206 === $code ) {
				if ( Transfer_Tools::content_range_start( (string) wp_remote_retrieve_header( $res, 'content-range' ) ) !== $offset ) {
					throw new Migration_Exception( 'Plage de téléchargement inattendue.', 'Le transfert reprendra automatiquement.', true );
				}
			} elseif ( 200 !== $code || 0 !== $offset ) {
				throw new Migration_Exception(
					200 === $code
						? 'Le site source (ou un proxy) ne permet pas la reprise des téléchargements (HTTP Range).'
						: sprintf( 'Le site source a refusé le téléchargement de l’archive (HTTP %d).', $code ),
					403 === $code
						? 'Le lien de téléchargement a expiré ou a été révoqué : relancez la migration directe.'
						: 'Désactivez le cache ou le proxy devant admin-ajax.php sur le site source, ou utilisez l’envoi par la source.',
					$code >= 500 || 0 === $code
				);
			}
			clearstatcache();
			$got = is_file( $tmp ) ? min( $len, (int) filesize( $tmp ) ) : 0;
			if ( $got <= 0 ) {
				throw new Migration_Exception( 'Téléchargement interrompu (aucune donnée reçue).', 'Le transfert reprendra automatiquement.', true );
			}
			$in  = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$out = @fopen( $part, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$ok  = $in && $out && stream_copy_to_stream( $in, $out, $got ) === $got;
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( $out ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( ! $ok ) {
				self::truncate( $part, $offset );
				throw new Migration_Exception( 'Écriture impossible sur le site de destination (disque plein ?).', 'Libérez de l’espace disque sur le serveur de destination.' );
			}
			return $offset + $got;
		} finally {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Télécharge l'installeur portable de la source (mode « pull »).
	 *
	 * @param string $url    Lien signé.
	 * @param string $target Fichier de destination.
	 * @return void
	 * @throws Migration_Exception Installeur invalide.
	 */
	private static function pull_installer( $url, $target ) {
		$res  = wp_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 0,
				'limit_response_size' => Installer_Builder::MAX_PORTABLE_SIZE,
				'sslverify'           => (bool) apply_filters( 'lwb_migration_sslverify', true ),
			)
		);
		$code = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! Installer_Builder::is_portable( $code ) ) {
			throw new Migration_Exception( 'Installeur de la source introuvable ou invalide.', 'Le transfert reprendra automatiquement.', true );
		}
		if ( false === @file_put_contents( $target, $code ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new Migration_Exception( 'Écriture impossible sur le site de destination (disque plein ?).', 'Libérez de l’espace disque sur le serveur de destination.' );
		}
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
		// Installeur de la source s'il a été transmis (1.3.0+), sinon celui de ce site.
		$portable_file = dirname( $part ) . '/installer.src';
		$portable      = is_file( $portable_file ) ? (string) file_get_contents( $portable_file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$url           = self::install_package( $part, $name, $key['kid'], $portable, isset( $info['final_url'] ) ? (string) $info['final_url'] : '' );
		Json_Store::delete_dir( dirname( $part ) );
		// Clé à usage unique.
		delete_option( self::OPTION );
		return array(
			'ok'            => true,
			'installer_url' => $url,
		);
	}

	/**
	 * URL définitive imposée par la source : adresses du site et de l'accueil à installer.
	 *
	 * @param string $final_url URL définitive (accueil), vide pour garder celles de ce site.
	 * @return array site_url, home_url.
	 * @throws Migration_Exception URL invalide.
	 */
	private static function target_urls( $final_url ) {
		$site = untrailingslashit( site_url() );
		$home = untrailingslashit( home_url() );
		if ( '' === $final_url ) {
			return array(
				'site_url' => $site,
				'home_url' => $home,
			);
		}
		$final = untrailingslashit( esc_url_raw( $final_url, array( 'http', 'https' ) ) );
		if ( ! preg_match( '#^https?://[^\s/?\#]+[^\s?\#]*$#i', $final ) ) {
			throw new Migration_Exception( 'Adresse définitive du site invalide.', 'Saisissez une URL complète, par exemple https://www.mon-site.fr' );
		}
		// WordPress installé dans un sous-dossier de l'accueil : même sous-dossier.
		$sub = 0 === strpos( $site, $home ) ? substr( $site, strlen( $home ) ) : '';
		return array(
			'site_url' => $final . $sub,
			'home_url' => $final,
		);
	}

	/**
	 * Dépose une archive complète et un installeur à la racine du site, protégé par un
	 * jeton à usage unique : l'installation se fait avec la base de ce site.
	 *
	 * Utilisé par la migration directe et par l'import depuis l'administration.
	 *
	 * @param string      $part      Archive complète (sera déplacée).
	 * @param string      $name      Nom de l'archive à la racine (archive-<32 hex>.zip|lwb).
	 * @param string      $package   Identifiant du paquet.
	 * @param string|null $portable  Installeur portable de la source (null : celui de ce site).
	 * @param string      $final_url URL définitive (vide : celle de ce site).
	 * @return string URL de l'installeur (avec le jeton).
	 * @throws Migration_Exception Archive invalide ou écriture impossible.
	 */
	public static function install_package( $part, $name, $package, $portable = null, $final_url = '' ) {
		global $wpdb;
		$reader   = new Archive_Reader( $part );
		$entry    = $reader->find( 'lwb-package.json' );
		$manifest = $entry ? json_decode( $reader->read_string( $entry ), true ) : null;
		unset( $reader );
		if ( ! is_array( $manifest ) || empty( $manifest['source'] ) ) {
			throw new Migration_Exception( 'Le paquet reçu ne contient pas de manifeste valide.', 'Vérifiez qu’il s’agit bien d’une archive créée par Lwb Migration, puis recommencez.' );
		}
		$target = self::target_urls( $final_url );

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
			'package'        => $package,
			'archive'        => $name,
			'archive_size'   => (int) filesize( $root . '/' . $name ),
			'created'        => time(),
			'password_hash'  => '',
			'secret'         => Runtime::random_hex( 64 ),
			'mode'           => 'direct',
			'token_hash'     => hash( 'sha256', $token ),
			'source'         => $manifest['source'],
			'target'         => array(
				'site_url' => $target['site_url'],
				'home_url' => $target['home_url'],
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
		if ( null !== $portable && ! Installer_Builder::is_portable( $portable ) ) {
			$portable = null;
		}
		Installer_Builder::build( $root . '/' . $installer, $config, $portable );
		return add_query_arg( 'lwb_token', $token, site_url( '/' . $installer ) );
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
	 * Les requêtes de pilotage, sans corps, partent en GET (rarement filtré), avec le même
	 * repli ; leur méthode est mémorisée à part ($remote['ctl_method']).
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
		$slot    = '' === $body ? 'ctl_method' : 'method';
		$methods = ! empty( $remote[ $slot ] ) ? array( (string) $remote[ $slot ] ) : ( '' === $body ? array( 'GET', 'PUT', 'POST' ) : array( 'PUT', 'POST' ) );
		$code    = 0;
		$raw     = '';
		$json    = null;
		unset( $remote['blocked'] );
		foreach ( $methods as $method ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$res = self::send( $remote, $method, $op, $offset, $extra, $body );
				if ( is_wp_error( $res ) ) {
					throw new Migration_Exception(
						sprintf( /* translators: %s: erreur réseau. */ __( 'Le site de destination est injoignable : %s', 'lwb-migration' ), $res->get_error_message() ),
						self::unreachable_solution( $remote ),
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
				$remote[ $slot ] = $method;
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
			if ( self::is_firewall_page( $code, $raw ) || 413 === $code || 405 === $code ) {
				// Envoi bloqué : en mode automatique, send_step() bascule sur le téléchargement par la destination.
				$remote['blocked'] = $op;
			}
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
					? __( 'Le serveur de destination refuse les envois volumineux : augmentez post_max_size ou client_max_body_size (nginx), ou choisissez « Téléchargement par la destination ».', 'lwb-migration' )
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
	 * Conseil quand la destination est injoignable.
	 *
	 * @param array $remote Informations de la clé.
	 * @return string
	 */
	private static function unreachable_solution( array $remote ) {
		if ( ! empty( $remote['route']['ip'] ) ) {
			return sprintf(
				/* translators: 1: adresse IP, 2: nom de domaine. */
				__( 'Vérifiez l’adresse IP du nouveau serveur (%1$s) et que son certificat SSL est valide pour %2$s : avant le changement de DNS, un certificat Let’s Encrypt ne peut souvent pas encore être émis. Utilisez alors l’URL temporaire fournie par l’hébergeur. Le transfert reprendra automatiquement.', 'lwb-migration' ),
				$remote['route']['ip'],
				(string) wp_parse_url( $remote['url'], PHP_URL_HOST )
			);
		}
		return __( 'Vérifiez que le site de destination est en ligne et que son certificat SSL est valide. Le transfert reprendra automatiquement.', 'lwb-migration' );
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
			__( 'Choisissez « Téléchargement par la destination » (aucun envoi entrant), ou autorisez %s dans la protection de l’hébergeur de destination (chez o2switch : cPanel › Outils o2switch › Sécurité), puis cliquez sur Reprendre. Vous pouvez aussi utiliser l’export classique, ou l’onglet « Importer » du site de destination.', 'lwb-migration' ),
			$who
		);
	}

	/**
	 * Requête HTTP vers le site de destination, en suivant la route éventuelle (avant le
	 * changement de DNS).
	 *
	 * Route par adresse IP : l'URL définitive est conservée (en-tête Host, SNI et
	 * vérification du certificat sur le vrai nom) et seule la résolution DNS est remplacée
	 * (CURLOPT_RESOLVE, comme `curl --resolve`). Route par URL temporaire : la base de l'URL
	 * est remplacée.
	 *
	 * @param array  $remote Informations de la clé (url, route).
	 * @param string $url    URL définitive.
	 * @param array  $args   Arguments de wp_remote_request().
	 * @return array|\WP_Error
	 */
	private static function http( array $remote, $url, array $args ) {
		$route = isset( $remote['route'] ) ? $remote['route'] : null;
		$real  = Route::apply( $route, $url, Route::site_base( $remote['url'] ) );
		$entry = Route::resolve_entry( $route, $real );
		if ( '' === $entry ) {
			return wp_remote_request( $real, $args );
		}
		if ( ! self::curl_available() ) {
			return new \WP_Error( 'lwb_migration_no_curl', __( 'l’extension PHP cURL est indispensable pour joindre le nouveau serveur par son adresse IP', 'lwb-migration' ) );
		}
		$applied = false;
		$hook    = function ( $handle ) use ( $entry, &$applied ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- résolution forcée, sans équivalent dans l'API HTTP de WordPress.
			$applied = curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) );
		};
		add_action( 'http_api_curl', $hook, 10, 1 );
		try {
			$res = wp_remote_request( $real, $args );
		} finally {
			remove_action( 'http_api_curl', $hook, 10 );
		}
		if ( ! $applied && ! is_wp_error( $res ) ) {
			// Transport autre que cURL : la requête a suivi le DNS, donc l'ancien serveur.
			return new \WP_Error( 'lwb_migration_no_resolve', __( 'la résolution vers l’adresse IP n’a pas pu être appliquée (transport HTTP sans cURL)', 'lwb-migration' ) );
		}
		return $res;
	}

	/**
	 * L’extension cURL est-elle utilisable (avec SSL) ?
	 *
	 * @return bool
	 */
	private static function curl_available() {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return false;
		}
		$v = curl_version(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_version
		return is_array( $v ) && defined( 'CURL_VERSION_SSL' ) && ( $v['features'] & CURL_VERSION_SSL );
	}

	/**
	 * Envoie une requête signée (nouvel horodatage et nouvel aléa à chaque essai).
	 *
	 * @param array  $remote Informations de la clé.
	 * @param string $method GET, PUT ou POST.
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
		$request = array(
			'method'      => $method,
			'timeout'     => 120,
			'redirection' => 0,
			'user-agent'  => 'LwbMigration/' . LWB_MIGRATION_VERSION . '; ' . home_url( '/' ),
			'headers'     => array( 'Accept' => 'application/json' ),
			'sslverify'   => (bool) apply_filters( 'lwb_migration_sslverify', true ),
		);
		if ( 'GET' !== $method ) {
			$request['body']                    = $body;
			$request['headers']['Content-Type'] = 'application/octet-stream';
		}
		return self::http( $remote, add_query_arg( $args, $remote['url'] ), $request );
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

		// Destination capable de télécharger l'archive elle-même et d'installer le site à une
		// adresse imposée par la source (1.5.0+).
		$remote['pull']      = ! empty( $info['pull'] ) ? 1 : 0;
		$remote['final_ok']  = ! empty( $info['final_url'] ) ? 1 : 0;
		$remote['mode']      = isset( $remote['mode'] ) ? (string) $remote['mode'] : 'auto';
		$remote_version      = isset( $info['plugin_version'] ) ? (string) $info['plugin_version'] : '';
		$needs_recent_plugin = ( 'pull' === $remote['mode'] && empty( $remote['pull'] ) ) || ( ! empty( $remote['final_url'] ) && empty( $remote['final_ok'] ) );
		if ( $needs_recent_plugin ) {
			throw new Migration_Exception(
				sprintf(
					/* translators: %s: version de la destination. */
					__( 'Le site de destination utilise Lwb Migration %s : le téléchargement par la destination et l’adresse définitive imposée exigent la version 1.5.0 ou plus récente.', 'lwb-migration' ),
					'' !== $remote_version ? $remote_version : '?'
				),
				__( 'Mettez à jour Lwb Migration sur le site de destination, ou choisissez l’envoi par la source.', 'lwb-migration' )
			);
		}

		// Avant 1.3.0, l'installeur est construit par l'extension de la destination : une
		// version plus ancienne installerait le site sans les correctifs de cette version
		// (ex. 1.2.2 : caches Elementor non vidés, liens vers l'ancien domaine, CSS introuvables).
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
	 * Transfère l'archive jusqu'à épuisement du budget : envoi par la source (push) ou
	 * téléchargement par la destination (pull).
	 *
	 * En mode automatique, un envoi bloqué par le pare-feu de la destination (ou refusé
	 * comme trop volumineux) bascule sur le téléchargement par la destination, qui reprend
	 * là où l'envoi s'est arrêté.
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 * @throws Migration_Exception Erreur réseau ou archive absente.
	 */
	public static function send_step( Job $job, Runtime $rt ) {
		$d       = &$job->data;
		$archive = $d['package_dir'] . '/' . $d['archive_name'];
		if ( ! isset( $d['direct']['mask'] ) || ! isset( $d['direct']['pull'] ) ) {
			// Tâche démarrée avec une version antérieure : on redemande les capacités de la destination.
			self::hello( $d['direct'] );
		}
		if ( ! isset( $d['transfer'] ) ) {
			$d['transfer'] = array(
				'offset' => 0,
				'size'   => (int) filesize( $archive ),
				'chunk'  => (int) $d['direct']['chunk'],
			);
			$job->logger()->info( sprintf( 'Transfert vers %s : %s.', $d['direct']['site_url'], Runtime::format_bytes( $d['transfer']['size'] ) ) );
			if ( ! empty( $d['direct']['route'] ) ) {
				$job->logger()->info( 'Avant le changement de DNS : nouveau serveur joint par ' . Route::describe( $d['direct']['route'] ) . '.' );
			}
		}
		if ( 'pull' === $d['direct']['mode'] ) {
			self::pull_step( $job, $rt );
			return;
		}
		try {
			self::push_step( $job, $rt, $archive );
		} catch ( Migration_Exception $e ) {
			if ( 'auto' !== $d['direct']['mode'] || empty( $d['direct']['pull'] ) || empty( $d['direct']['blocked'] ) ) {
				throw $e;
			}
			$job->logger()->warning( 'Envoi bloqué par le site de destination (' . $e->getMessage() . ') : la destination va télécharger l’archive elle-même.' );
			$d['direct']['mode'] = 'pull';
			unset( $d['direct']['blocked'] );
		}
	}

	/**
	 * Envoie des morceaux jusqu'à épuisement du budget.
	 *
	 * @param Job     $job     Tâche.
	 * @param Runtime $rt      Budget.
	 * @param string  $archive Archive.
	 * @return void
	 * @throws Migration_Exception Erreur réseau ou archive absente.
	 */
	private static function push_step( Job $job, Runtime $rt, $archive ) {
		$d = &$job->data;
		$h = @fopen( $archive, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			throw new Migration_Exception( __( 'Archive introuvable pour le transfert.', 'lwb-migration' ), __( 'Relancez la migration directe.', 'lwb-migration' ) );
		}
		try {
			$t = &$d['transfer'];
			if ( empty( $t['logged_push'] ) ) {
				$t['logged_push'] = 1;
				$job->logger()->info( sprintf( 'Envoi par la source en morceaux de %s.', Runtime::format_bytes( $t['chunk'] ) ) );
			}
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
	 * Mode « pull » : la destination télécharge l'archive via un lien signé, temporaire et
	 * reprenable ; chaque appel (GET) la fait avancer pendant son propre budget.
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 * @throws Migration_Exception Erreur réseau.
	 */
	private static function pull_step( Job $job, Runtime $rt ) {
		$d = &$job->data;
		$t = &$d['transfer'];
		if ( empty( $d['pull'] ) || (int) $d['pull']['expires'] < time() + 600 ) {
			$d['pull'] = array(
				'secret'  => Runtime::random_hex( 64 ),
				'expires' => time() + self::PULL_TTL,
			);
			// Installeur portable de cette version, servi avec l'archive.
			if ( false === @file_put_contents( $job->path( 'installer.src' ), Installer_Builder::render_portable() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				throw new Migration_Exception( __( 'Impossible de préparer l’installeur à télécharger.', 'lwb-migration' ), __( 'Vérifiez l’espace disque et les permissions du dossier de stockage.', 'lwb-migration' ) );
			}
			$job->save();
			$job->logger()->info( 'Téléchargement par la destination : lien signé valable 6 h, reprise par plages (HTTP Range).' );
		}
		// Au moins un appel une fois l'archive complète : la destination télécharge alors
		// l'installeur (cas d'une bascule après l'envoi complet de l'archive).
		while ( $t['offset'] < $t['size'] || empty( $t['installer'] ) ) {
			if ( $rt->remaining() < 3 && $rt->elapsed() > 0.5 ) {
				break;
			}
			$extra       = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				(string) wp_json_encode(
					array(
						'u' => self::fetch_url( $job, 'archive' ),
						'i' => self::fetch_url( $job, 'installer' ),
						's' => $t['size'],
						// La destination rend la main avant que cette requête n'expire.
						'b' => max( 1, (int) floor( $rt->remaining() - 2 ) ),
					),
					JSON_UNESCAPED_SLASHES
				)
			);
			$res         = self::request( $d['direct'], 'pull', (string) $t['offset'], $extra );
			$t['offset'] = min( (int) $res['size'], $t['size'] );
			if ( $t['offset'] >= $t['size'] ) {
				$t['installer'] = 1;
			}
			if ( $rt->should_stop() ) {
				break;
			}
		}
		if ( $t['offset'] >= $t['size'] && ! empty( $t['installer'] ) ) {
			$job->logger()->info( 'Archive téléchargée par la destination.' );
			$d['phase'] = 'remote';
		}
	}

	/**
	 * Lien de téléchargement signé (mode « pull »).
	 *
	 * @param Job    $job  Tâche.
	 * @param string $file archive|installer.
	 * @return string
	 */
	private static function fetch_url( Job $job, $file ) {
		$expires = (int) $job->data['pull']['expires'];
		return add_query_arg(
			array(
				'action' => 'lwb_migration_fetch',
				'job'    => $job->id(),
				'f'      => $file,
				'e'      => $expires,
				's'      => Transfer_Tools::fetch_signature( $job->data['pull']['secret'], $job->id(), $file, $expires ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Point de téléchargement du mode « pull » (admin-ajax, sans session : lien signé).
	 *
	 * Le lien est propre à la tâche, expire au bout de 6 h et est révoqué dès que la
	 * destination a reçu l'archive. Les plages (HTTP Range) permettent la reprise.
	 *
	 * @return void
	 */
	public static function handle_fetch() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authentification par signature ci-dessous.
		$id      = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		$file    = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : '';
		$expires = isset( $_GET['e'] ) ? absint( $_GET['e'] ) : 0;
		$sig     = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( ! is_ssl() && ! self::allow_http() ) {
			wp_die( esc_html__( 'HTTPS requis.', 'lwb-migration' ), '', array( 'response' => 403 ) );
		}
		try {
			$job = Job::load( Security::storage_dir( 'jobs' ), $id );
		} catch ( \Throwable $e ) {
			$job = null;
		}
		$pull = null !== $job && ! empty( $job->data['pull']['secret'] ) ? $job->data['pull'] : null;
		if ( null === $pull || $expires < time() || $expires > (int) $pull['expires'] || ! hash_equals( Transfer_Tools::fetch_signature( $pull['secret'], $id, $file, $expires ), $sig ) ) {
			wp_die( esc_html__( 'Lien de téléchargement invalide ou expiré.', 'lwb-migration' ), '', array( 'response' => 403 ) );
		}
		if ( 'archive' === $file && ! empty( $job->data['package_dir'] ) ) {
			$path = $job->data['package_dir'] . '/' . basename( (string) $job->data['archive_name'] );
		} elseif ( 'installer' === $file ) {
			$path = $job->path( 'installer.src' );
		} else {
			$path = '';
		}
		if ( '' === $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'lwb-migration' ), '', array( 'response' => 404 ) );
		}
		$size  = (int) filesize( $path );
		$slice = Transfer_Tools::parse_range( $range, $size );
		if ( false === $slice ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		Runtime::prepare();
		if ( Runtime::function_enabled( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		list( $start, $end ) = null === $slice ? array( 0, $size - 1 ) : $slice;
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Accept-Ranges: bytes' );
		if ( null !== $slice ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Length: ' . max( 0, $end - $start + 1 ) );
		$h    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$left = $end - $start + 1;
		if ( $h ) {
			fseek( $h, $start );
			while ( $left > 0 && ! feof( $h ) ) {
				$buf = (string) fread( $h, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( '' === $buf ) {
					break;
				}
				echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contenu binaire.
				$left -= strlen( $buf );
				flush();
			}
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * Demande à la destination de préparer l'installation.
	 *
	 * @param Job $job Tâche.
	 * @return void
	 * @throws Migration_Exception Destination injoignable ou refus.
	 */
	public static function remote_finalize( Job $job ) {
		$d = &$job->data;
		if ( ! empty( $d['direct']['installer_upload'] ) && 'pull' !== $d['direct']['mode'] ) {
			// La destination installera le site avec l'installeur de cette version (en mode
			// « pull », elle l'a téléchargé avec l'archive).
			try {
				self::request( $d['direct'], 'installer', '0', '', Installer_Builder::render_portable() );
			} catch ( Migration_Exception $e ) {
				if ( 'auto' !== $d['direct']['mode'] || empty( $d['direct']['pull'] ) || empty( $d['direct']['blocked'] ) ) {
					throw $e;
				}
				// Envoi de l'installeur bloqué : la destination le téléchargera.
				$job->logger()->warning( 'Envoi de l’installeur bloqué par le site de destination : la destination va le télécharger.' );
				$d['direct']['mode'] = 'pull';
				$d['phase']          = 'transfer';
				unset( $d['direct']['blocked'] );
				return;
			}
			$job->logger()->info( 'Installeur de la source transmis à la destination.' );
		}
		$finalize = array(
			'name' => $d['archive_name'],
			'size' => $d['transfer']['size'],
		);
		if ( ! empty( $d['direct']['final_url'] ) ) {
			$finalize['final_url'] = $d['direct']['final_url'];
		}
		$res = self::request(
			$d['direct'],
			'finalize',
			'0',
			base64_encode( (string) wp_json_encode( $finalize ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
		// Archive reçue : le lien de téléchargement ne sert plus.
		unset( $d['pull'] );
		wp_delete_file( $job->path( 'installer.src' ) );
		$d['remote_result'] = array( 'installer_url' => esc_url_raw( $res['installer_url'] ) );
		if ( ! empty( $d['direct']['route'] ) ) {
			// Avant le changement de DNS, le navigateur ne peut pas encore joindre l'installeur :
			// cette extension le pilote elle-même, par la même route.
			$d['phase'] = 'install';
			$job->logger()->info( 'Site de destination prêt : installation pilotée depuis ce site.' );
			return;
		}
		$d['phase'] = 'done';
		$job->logger()->info( 'Site de destination prêt : l’installation peut commencer.' );
		Exporter::archive_log( $job );
	}

	/**
	 * Avant le changement de DNS : pilote l'installeur de la destination depuis ce serveur,
	 * comme le ferait le navigateur (lien à usage unique, session, étapes successives).
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 * @throws Migration_Exception Installeur injoignable ou installation en échec.
	 */
	public static function remote_install_step( Job $job, Runtime $rt ) {
		$d = &$job->data;
		if ( empty( $d['remote_install'] ) ) {
			$d['remote_install'] = array(
				'cookie'   => '',
				'csrf'     => '',
				'started'  => false,
				'progress' => 0,
				'detail'   => '',
			);
		}
		$ri       = &$d['remote_install'];
		$url      = (string) $d['remote_result']['installer_url'];
		$endpoint = (string) preg_replace( '/\?.*$/', '', $url );

		if ( '' === $ri['cookie'] ) {
			$res    = self::installer_http( $d['direct'], 'GET', $url );
			$cookie = Route::cookie_value( wp_remote_retrieve_header( $res, 'set-cookie' ), 'lwb_installer_auth' );
			if ( null === $cookie ) {
				throw new Migration_Exception(
					sprintf( /* translators: %s: message de l'installeur. */ __( 'L’installeur du nouveau serveur a refusé l’ouverture de session : %s', 'lwb-migration' ), self::page_text( wp_remote_retrieve_body( $res ) ) ),
					self::install_solution( $d )
				);
			}
			$ri['cookie'] = $cookie;
			$job->save();
		}
		if ( '' === $ri['csrf'] ) {
			$res = self::installer_http( $d['direct'], 'GET', $endpoint, array(), $ri['cookie'] );
			if ( ! preg_match( '/"csrf":"([a-f0-9]{64})"/', (string) wp_remote_retrieve_body( $res ), $m ) ) {
				$ri['cookie'] = '';
				throw new Migration_Exception( __( 'Interface de l’installeur introuvable sur le nouveau serveur.', 'lwb-migration' ), self::install_solution( $d ), true );
			}
			$ri['csrf'] = $m[1];
		}
		if ( empty( $ri['started'] ) ) {
			$status = self::installer_api( $d, $endpoint, 'status' );
			if ( 'setup' === $status['phase'] ) {
				foreach ( (array) $status['checks'] as $check ) {
					if ( isset( $check['level'] ) && 'error' === $check['level'] ) {
						throw new Migration_Exception( $check['label'] . ' : ' . $check['value'], isset( $check['help'] ) ? (string) $check['help'] : '' );
					}
				}
				$form               = (array) $status['defaults'];
				$form['quarantine'] = '1'; // Option cochée par défaut dans l'interface.
				unset( $form['path'] );
				self::installer_api( $d, $endpoint, 'start', $form );
				$job->logger()->info( sprintf( 'Installation démarrée sur le nouveau serveur, à l’adresse %s.', isset( $form['site_url'] ) ? $form['site_url'] : '' ) );
			}
			$ri['started'] = true;
			return;
		}
		do {
			$step = self::installer_api( $d, $endpoint, 'step' );
			if ( ! empty( $step['busy'] ) ) {
				break;
			}
			$ri['progress'] = isset( $step['progress'] ) ? (float) $step['progress'] : $ri['progress'];
			$ri['detail']   = isset( $step['detail'] ) ? (string) $step['detail'] : '';
			if ( isset( $step['phase'] ) && 'done' === $step['phase'] ) {
				$errors = isset( $step['result']['errors'] ) ? (int) $step['result']['errors'] : 0;

				$d['remote_result']['installed'] = true;

				$d['phase'] = 'done';
				$job->logger()->info( sprintf( 'Site installé sur le nouveau serveur (%d erreur(s) SQL). Vous pouvez changer les DNS.', $errors ) );
				Exporter::archive_log( $job );
				return;
			}
		} while ( ! $rt->should_stop() );
	}

	/**
	 * Appel de l'API de l'installeur distant.
	 *
	 * @param array  $d        Données de la tâche.
	 * @param string $endpoint URL de l’installeur.
	 * @param string $action   Action.
	 * @param array  $data     Champs.
	 * @return array
	 * @throws Migration_Exception Réponse invalide ou erreur de l'installeur.
	 */
	private static function installer_api( array &$d, $endpoint, $action, array $data = array() ) {
		$ri   = &$d['remote_install'];
		$res  = self::installer_http(
			$d['direct'],
			'POST',
			$endpoint,
			array_merge(
				$data,
				array(
					'lwb_action' => $action,
					'csrf'       => $ri['csrf'],
				)
			),
			$ri['cookie']
		);
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			if ( self::is_firewall_page( $code, (string) wp_remote_retrieve_body( $res ) ) ) {
				throw new Migration_Exception(
					sprintf( /* translators: %d: code HTTP. */ __( 'Le pare-feu du nouveau serveur bloque le pilotage de l’installeur (HTTP %d).', 'lwb-migration' ), $code ),
					self::install_solution( $d )
				);
			}
			throw new Migration_Exception( sprintf( /* translators: %d: code HTTP. */ __( 'Réponse inattendue de l’installeur du nouveau serveur (HTTP %d).', 'lwb-migration' ), $code ), __( 'L’installation reprendra automatiquement.', 'lwb-migration' ), true );
		}
		if ( empty( $json['ok'] ) ) {
			if ( 403 === $code ) {
				// Session expirée : on en ouvrira une nouvelle (tant que l'installation n'a pas commencé).
				$ri['cookie'] = '';
				$ri['csrf']   = '';
			}
			throw new Migration_Exception(
				isset( $json['message'] ) ? (string) $json['message'] : __( 'L’installeur du nouveau serveur a refusé la requête.', 'lwb-migration' ),
				isset( $json['solution'] ) ? (string) $json['solution'] : '',
				! empty( $json['retryable'] )
			);
		}
		return $json;
	}

	/**
	 * Requête vers l'installeur distant, par la route de la migration.
	 *
	 * @param array  $remote Informations de la clé.
	 * @param string $method GET ou POST.
	 * @param string $url    URL.
	 * @param array  $body   Champs (POST).
	 * @param string $cookie Cookie de session.
	 * @return array Réponse.
	 * @throws Migration_Exception Nouveau serveur injoignable.
	 */
	private static function installer_http( array $remote, $method, $url, array $body = array(), $cookie = '' ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 120,
			'redirection' => 0,
			'user-agent'  => 'LwbMigration/' . LWB_MIGRATION_VERSION . '; ' . home_url( '/' ),
			'sslverify'   => (bool) apply_filters( 'lwb_migration_sslverify', true ),
			'headers'     => array(),
		);
		if ( '' !== $cookie ) {
			$args['headers']['Cookie'] = 'lwb_installer_auth=' . $cookie;
		}
		if ( 'POST' === $method ) {
			$args['body'] = $body;
		}
		$res = self::http( $remote, $url, $args );
		if ( is_wp_error( $res ) ) {
			throw new Migration_Exception(
				sprintf( /* translators: %s: erreur réseau. */ __( 'Le nouveau serveur est injoignable : %s', 'lwb-migration' ), $res->get_error_message() ),
				self::unreachable_solution( $remote ),
				true
			);
		}
		return $res;
	}

	/**
	 * Conseil quand l'installation ne peut pas être pilotée depuis ce site : ouvrir
	 * l'installeur dans le navigateur, en faisant pointer le domaine vers le nouveau serveur
	 * sur cet ordinateur seulement (fichier hosts).
	 *
	 * @param array $d Données de la tâche.
	 * @return string
	 */
	private static function install_solution( array $d ) {
		$line = Route::hosts_line( isset( $d['direct']['route'] ) ? $d['direct']['route'] : null, $d['direct']['url'] );
		if ( '' === $line ) {
			return __( 'Ouvrez l’installeur depuis l’URL temporaire du nouveau serveur, ou attendez le changement de DNS puis relancez la migration directe.', 'lwb-migration' );
		}
		return sprintf(
			/* translators: %s: ligne à ajouter au fichier hosts. */
			__( 'Ajoutez la ligne « %s » au fichier hosts de votre ordinateur, puis relancez la migration directe et ouvrez l’installeur dans votre navigateur.', 'lwb-migration' ),
			$line
		);
	}

	/**
	 * Texte lisible d'une page HTML (message d'erreur de l'installeur).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function page_text( $html ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $html ) ) );
		return '' !== $text ? substr( $text, 0, 200 ) : '?';
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
