<?php
/**
 * Sécurité : capacités, nonces, dossier de stockage protégé, signatures HMAC.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions de sécurité.
 */
class Security {

	const NONCE      = 'lwb_migration';
	const CAPABILITY = 'manage_options';
	const OPT_SECRET = 'lwb_migration_storage_key';

	/**
	 * Vérifie une requête AJAX d'administration (nonce + capacité) ou termine.
	 *
	 * @return void
	 */
	public static function check_ajax() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Votre session a expiré (jeton de sécurité invalide).', 'lwb-migration' ),
					'solution' => __( 'Rechargez la page puis recommencez.', 'lwb-migration' ),
				),
				403
			);
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Accès refusé : cette action est réservée aux administrateurs.', 'lwb-migration' ),
					'solution' => __( 'Connectez-vous avec un compte administrateur.', 'lwb-migration' ),
				),
				403
			);
		}
	}

	/**
	 * Dossier de stockage protégé : uploads/lwb-migration-<hash>/.
	 *
	 * @param string $sub Sous-dossier optionnel (jobs, packages, incoming).
	 * @return string
	 * @throws Migration_Exception Si le dossier ne peut être créé.
	 */
	public static function storage_dir( $sub = '' ) {
		$key = get_option( self::OPT_SECRET );
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{20}$/', $key ) ) {
			$key = Runtime::random_hex( 20 );
			update_option( self::OPT_SECRET, $key, false );
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) && ! is_dir( $uploads['basedir'] ) ) {
			throw new Migration_Exception(
				__( 'Le dossier des médias (uploads) est inaccessible.', 'lwb-migration' ),
				__( 'Vérifiez que wp-content/uploads existe et est accessible en écriture (permissions 755).', 'lwb-migration' )
			);
		}
		$dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . '/lwb-migration-' . $key;
		Json_Store::ensure_protected_dir( $dir );
		if ( '' !== $sub ) {
			$dir .= '/' . $sub;
			Json_Store::ensure_protected_dir( $dir );
		}
		return $dir;
	}

	/**
	 * Signature HMAC-SHA256.
	 *
	 * @param string $secret Secret.
	 * @param string $data   Données.
	 * @return string
	 */
	public static function sign( $secret, $data ) {
		return hash_hmac( 'sha256', $data, $secret );
	}

	/**
	 * Encodage base64 URL-safe.
	 *
	 * @param string $data Données.
	 * @return string
	 */
	public static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Décodage base64 URL-safe.
	 *
	 * @param string $data Données.
	 * @return string|false
	 */
	public static function b64url_decode( $data ) {
		return base64_decode( strtr( (string) $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Identifiant de paquet valide ?
	 *
	 * @param mixed $id Valeur.
	 * @return bool
	 */
	public static function valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{24}$/', $id );
	}

	/**
	 * Lit un paramètre POST texte assaini.
	 *
	 * @param string $key      Clé.
	 * @param string $fallback Valeur par défaut.
	 * @return string
	 */
	public static function post_text( $key, $fallback = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par check_ajax().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Lit un paramètre POST brut (mot de passe : aucun assainissement qui l'altérerait).
	 *
	 * @param string $key Clé.
	 * @return string
	 */
	public static function post_raw( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par check_ajax() ; mot de passe/clé utilisés tels quels.
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}
}
