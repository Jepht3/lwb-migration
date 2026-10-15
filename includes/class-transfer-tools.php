<?php
/**
 * Outils de transfert partagés par la migration directe, le mode « pull » et l'import
 * depuis l'administration : brouillage des corps, requêtes HTTP Range, signatures des
 * liens de téléchargement, taille des morceaux.
 *
 * Classe autonome (sans WordPress) : testée unitairement et utilisée par le scénario e2e.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Fonctions pures de transfert.
 */
class Transfer_Tools {

	/**
	 * Brouille (ou rétablit : l'opération est symétrique) un corps de requête.
	 *
	 * Les pare-feux d'hébergeurs (o2switch, Imunify360, ModSecurity…) inspectent le contenu
	 * des requêtes et rejettent en 403 celles qui ressemblent à du code PHP malveillant :
	 * une archive de site, qui contient des extensions entières, finit toujours par en
	 * contenir. Le corps est donc combiné (XOR) à un flux dérivé du secret et de l'aléa de
	 * la requête : il ne présente plus aucun motif reconnaissable. La confidentialité reste
	 * assurée par HTTPS et l'intégrité par la signature HMAC (ou la session d'administration).
	 *
	 * Le même calcul est fait en JavaScript (onglet « Importer ») avec WebCrypto.
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
	 * Analyse un en-tête Range (une seule plage d'octets).
	 *
	 * @param string $header Valeur de l'en-tête (ex. « bytes=100-199 »), vide si absent.
	 * @param int    $size   Taille du fichier.
	 * @return array|null|false [début, fin incluse] ; null : fichier entier ; false : plage impossible (416).
	 */
	public static function parse_range( $header, $size ) {
		$header = trim( (string) $header );
		$size   = (int) $size;
		if ( '' === $header ) {
			return null;
		}
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/i', $header, $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return null; // Syntaxe non gérée (plages multiples…) : réponse complète, comme le permet la RFC 9110.
		}
		if ( '' === $m[1] ) {
			// Suffixe : les N derniers octets.
			$n = (int) $m[2];
			if ( $n <= 0 || $size <= 0 ) {
				return false;
			}
			return array( max( 0, $size - $n ), $size - 1 );
		}
		$start = (int) $m[1];
		$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		if ( $start >= $size || $end < $start ) {
			return false;
		}
		return array( $start, $end );
	}

	/**
	 * Début de la plage annoncée par un en-tête Content-Range (« bytes 100-199/1000 »).
	 *
	 * @param string $header Valeur de l'en-tête.
	 * @return int|null
	 */
	public static function content_range_start( $header ) {
		return preg_match( '#^bytes\s+(\d+)-(\d+)/(\d+|\*)$#i', trim( (string) $header ), $m ) ? (int) $m[1] : null;
	}

	/**
	 * Signature d'un lien de téléchargement (mode « pull »).
	 *
	 * @param string $secret  Secret propre à la tâche.
	 * @param string $job     Identifiant de la tâche.
	 * @param string $file    archive|installer.
	 * @param int    $expires Horodatage d'expiration.
	 * @return string
	 */
	public static function fetch_signature( $secret, $job, $file, $expires ) {
		return hash_hmac( 'sha256', implode( "\n", array( 'lwb-fetch', (string) $job, (string) $file, (string) (int) $expires ) ), (string) $secret );
	}

	/**
	 * Taille des morceaux envoyés depuis le navigateur (onglet « Importer »).
	 *
	 * Un morceau ne doit jamais dépasser upload_max_filesize (fichier refusé par PHP) ni
	 * post_max_size (requête vidée par PHP), avec une marge pour les autres champs.
	 *
	 * @param int $upload_max upload_max_filesize en octets (0 : illimité).
	 * @param int $post_max   post_max_size en octets (0 : illimité).
	 * @param int $memory     memory_limit en octets (0 ou moins : illimité).
	 * @return int
	 */
	public static function import_chunk_size( $upload_max, $post_max, $memory ) {
		$chunk = 8388608;
		if ( $upload_max > 0 ) {
			$chunk = min( $chunk, $upload_max );
		}
		if ( $post_max > 0 ) {
			$chunk = min( $chunk, $post_max - 65536 );
		}
		if ( $memory > 0 ) {
			$chunk = min( $chunk, (int) ( $memory / 8 ) );
		}
		return max( 65536, (int) $chunk );
	}

	/**
	 * Format d'une archive d'après ses premiers octets.
	 *
	 * @param string $magic Premiers octets (au moins 8).
	 * @return string zip|lwb|encrypted|'' (inconnu).
	 */
	public static function archive_kind( $magic ) {
		$magic = (string) $magic;
		if ( 0 === strpos( $magic, "PK\x03\x04" ) || 0 === strpos( $magic, "PK\x05\x06" ) ) {
			return 'zip';
		}
		if ( 0 === strpos( $magic, 'LWBARC01' ) ) {
			return 'lwb';
		}
		if ( 0 === strpos( $magic, 'LWBENC01' ) ) {
			return 'encrypted';
		}
		return '';
	}
}
