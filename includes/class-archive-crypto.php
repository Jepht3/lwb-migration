<?php
/**
 * Chiffrement de l'archive exportée (AES-256-GCM, clé dérivée du mot de passe de l'installeur).
 *
 * Format : en-tête de 32 octets (« LWBENC01 », sel de 16 octets, nombre d'itérations PBKDF2,
 * taille des blocs), puis des blocs indépendants : IV (12 octets) + données chiffrées + tag
 * GCM (16 octets). Les données additionnelles authentifiées de chaque bloc contiennent
 * l'en-tête, le rang du bloc et un indicateur « dernier bloc » : un bloc modifié, déplacé ou
 * une archive tronquée sont détectés au déchiffrement.
 *
 * Chiffrement et déchiffrement se font par étapes (budget de temps) et reprennent au
 * dernier bloc complet après une coupure.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Chiffrement par blocs.
 */
class Archive_Crypto {

	const MAGIC      = 'LWBENC01';
	const CIPHER     = 'aes-256-gcm';
	const CHUNK      = 1048576;
	const ITERATIONS = 150000;
	const HEADER_LEN = 32;
	const IV_LEN     = 12;
	const TAG_LEN    = 16;
	const EXTENSION  = '.lwbenc';

	/**
	 * Le serveur sait-il chiffrer et déchiffrer ?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'hash_pbkdf2' ) && in_array( self::CIPHER, array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	/**
	 * Clé de 256 bits dérivée du mot de passe.
	 *
	 * @param string $password   Mot de passe de l'installeur.
	 * @param string $salt       Sel (binaire).
	 * @param int    $iterations Itérations PBKDF2-SHA256.
	 * @return string
	 */
	public static function derive_key( $password, $salt, $iterations = self::ITERATIONS ) {
		return hash_pbkdf2( 'sha256', (string) $password, (string) $salt, max( 1, (int) $iterations ), 32, true );
	}

	/**
	 * En-tête du fichier chiffré.
	 *
	 * @param string $salt       Sel (16 octets).
	 * @param int    $iterations Itérations.
	 * @return string
	 */
	public static function header( $salt, $iterations ) {
		return self::MAGIC . $salt . pack( 'N', (int) $iterations ) . pack( 'N', self::CHUNK );
	}

	/**
	 * Lit l'en-tête d'un fichier chiffré.
	 *
	 * @param string $file Fichier.
	 * @return array|null salt, iterations, chunk, header ; null si ce n'est pas un fichier chiffré.
	 */
	public static function read_header( $file ) {
		$raw = is_file( $file ) ? (string) @file_get_contents( $file, false, null, 0, self::HEADER_LEN ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( self::HEADER_LEN !== strlen( $raw ) || 0 !== strpos( $raw, self::MAGIC ) ) {
			return null;
		}
		$iter  = unpack( 'N', substr( $raw, 24, 4 ) );
		$chunk = unpack( 'N', substr( $raw, 28, 4 ) );
		return array(
			'salt'       => substr( $raw, 8, 16 ),
			'iterations' => (int) $iter[1],
			'chunk'      => (int) $chunk[1],
			'header'     => $raw,
		);
	}

	/**
	 * Données authentifiées d'un bloc.
	 *
	 * @param string $header En-tête.
	 * @param int    $index  Rang du bloc.
	 * @param bool   $last   Dernier bloc.
	 * @return string
	 */
	private static function aad( $header, $index, $last ) {
		return $header . pack( 'N2', ( $index >> 32 ) & 0xffffffff, $index & 0xffffffff ) . ( $last ? "\x01" : "\x00" );
	}

	/**
	 * Chiffre $src vers $dst par étapes.
	 *
	 * @param string       $src   Archive en clair.
	 * @param string       $dst   Fichier chiffré.
	 * @param string       $key   Clé (32 octets).
	 * @param array        $state État (in, salt, iterations) mis à jour ; salt et iterations requis.
	 * @param Runtime|null $rt    Budget (null : tout d'un coup).
	 * @return bool true quand tout est chiffré.
	 * @throws Migration_Exception Lecture, écriture ou chiffrement impossible.
	 */
	public static function encrypt_step( $src, $dst, $key, array &$state, $rt = null ) {
		$size   = (int) filesize( $src );
		$header = self::header( $state['salt'], $state['iterations'] );
		$in     = isset( $state['in'] ) ? (int) $state['in'] : 0;
		$index  = intdiv( $in, self::CHUNK );
		// Reprise : on repart du dernier bloc complet enregistré.
		self::truncate_or_create( $dst, self::HEADER_LEN + $index * ( self::CHUNK + self::IV_LEN + self::TAG_LEN ) );
		$in = $index * self::CHUNK;
		$r  = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$w  = @fopen( $dst, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $r || ! $w ) {
			throw new Migration_Exception( 'Chiffrement de l’archive impossible (fichier illisible).', 'Vérifiez l’espace disque et les permissions du dossier de stockage.' );
		}
		try {
			if ( 0 === $index ) {
				fwrite( $w, $header );
			}
			fseek( $w, 0, SEEK_END );
			fseek( $r, $in );
			do {
				$plain = (string) fread( $r, self::CHUNK );
				$last  = $in + strlen( $plain ) >= $size;
				$iv    = random_bytes( self::IV_LEN );
				$tag   = '';
				$data  = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::aad( $header, $index, $last ), self::TAG_LEN );
				if ( false === $data || self::TAG_LEN !== strlen( $tag ) ) {
					throw new Migration_Exception( 'Chiffrement de l’archive impossible (OpenSSL).', 'Désactivez le chiffrement dans les réglages ou demandez à l’hébergeur d’activer OpenSSL.' );
				}
				$block = $iv . $data . $tag;
				if ( strlen( $block ) !== fwrite( $w, $block ) ) {
					throw new Migration_Exception( 'Écriture de l’archive chiffrée impossible (disque plein ?).', 'Libérez de l’espace disque puis cliquez sur Reprendre.', true );
				}
				$in += strlen( $plain );
				++$index;
				$state['in'] = $in;
			} while ( ! $last && ( null === $rt || ! $rt->should_stop() ) );
			return $last;
		} finally {
			fclose( $r );
			fclose( $w );
		}
	}

	/**
	 * Déchiffre $src vers $dst par étapes.
	 *
	 * @param string       $src   Fichier chiffré.
	 * @param string       $dst   Archive en clair.
	 * @param string       $key   Clé (32 octets).
	 * @param array        $state État (index) mis à jour.
	 * @param Runtime|null $rt    Budget (null : tout d'un coup).
	 * @return bool true quand tout est déchiffré.
	 * @throws Migration_Exception Mot de passe incorrect ou archive modifiée ou tronquée.
	 */
	public static function decrypt_step( $src, $dst, $key, array &$state, $rt = null ) {
		$head = self::read_header( $src );
		if ( null === $head || self::CHUNK !== $head['chunk'] ) {
			throw new Migration_Exception( 'Archive chiffrée illisible.', 'Retransférez l’archive en mode binaire (FTP).' );
		}
		clearstatcache();
		$size  = (int) filesize( $src );
		$block = self::CHUNK + self::IV_LEN + self::TAG_LEN;
		$index = isset( $state['index'] ) ? (int) $state['index'] : 0;
		self::truncate_or_create( $dst, $index * self::CHUNK );
		$r = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$w = @fopen( $dst, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $r || ! $w ) {
			throw new Migration_Exception( 'Déchiffrement de l’archive impossible (fichier illisible).', 'Vérifiez l’espace disque et les permissions du dossier.' );
		}
		try {
			fseek( $w, 0, SEEK_END );
			do {
				$pos = self::HEADER_LEN + $index * $block;
				$len = min( $block, $size - $pos );
				if ( $len < self::IV_LEN + self::TAG_LEN ) {
					throw new Migration_Exception( 'Archive chiffrée incomplète.', 'L’archive est tronquée : retransférez-la en mode binaire (FTP).' );
				}
				fseek( $r, $pos );
				$raw  = (string) fread( $r, $len );
				$last = $pos + $len >= $size;
				$data = openssl_decrypt( substr( $raw, self::IV_LEN, -self::TAG_LEN ), self::CIPHER, $key, OPENSSL_RAW_DATA, substr( $raw, 0, self::IV_LEN ), substr( $raw, -self::TAG_LEN ), self::aad( $head['header'], $index, $last ) );
				if ( false === $data ) {
					throw new Migration_Exception( 'Déchiffrement refusé : archive modifiée, tronquée, ou chiffrée avec un autre mot de passe.', 'Vérifiez que l’archive et installer.php proviennent du même export, puis retransférez l’archive en mode binaire.' );
				}
				if ( strlen( $data ) !== fwrite( $w, $data ) ) {
					throw new Migration_Exception( 'Écriture impossible pendant le déchiffrement (disque plein ?).', 'Libérez de l’espace disque puis relancez.', true );
				}
				++$index;
				$state['index'] = $index;
			} while ( ! $last && ( null === $rt || ! $rt->should_stop() ) );
			return $last;
		} finally {
			fclose( $r );
			fclose( $w );
		}
	}

	/**
	 * Ramène un fichier à une taille donnée (le crée vide au besoin).
	 *
	 * @param string $file Fichier.
	 * @param int    $size Taille.
	 * @return void
	 * @throws Migration_Exception Fichier inaccessible.
	 */
	private static function truncate_or_create( $file, $size ) {
		$h = @fopen( $file, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $h ) {
			throw new Migration_Exception( sprintf( 'Impossible d’écrire %s.', basename( $file ) ), 'Vérifiez les permissions du dossier et l’espace disque.' );
		}
		clearstatcache();
		if ( (int) filesize( $file ) !== (int) $size ) {
			ftruncate( $h, max( 0, (int) $size ) );
		}
		fclose( $h );
	}
}
