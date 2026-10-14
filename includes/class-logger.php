<?php
/**
 * Journal détaillé d'une migration.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Journal texte (ajout en fin de fichier, protégé par un en-tête PHP).
 */
class Logger {

	/**
	 * Fichier du journal.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Constructeur.
	 *
	 * @param string $file Chemin du journal (.php recommandé).
	 */
	public function __construct( $file ) {
		$this->file = $file;
		if ( ! is_file( $file ) ) {
			@file_put_contents( $file, Json_Store::HEADER ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Chemin du fichier.
	 *
	 * @return string
	 */
	public function get_file() {
		return $this->file;
	}

	/**
	 * Information.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function info( $message ) {
		$this->write( 'INFO', $message );
	}

	/**
	 * Avertissement.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( $message ) {
		$this->write( 'AVERT', $message );
	}

	/**
	 * Erreur.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( $message ) {
		$this->write( 'ERREUR', $message );
	}

	/**
	 * Écrit une ligne.
	 *
	 * @param string $level   Niveau.
	 * @param string $message Message.
	 * @return void
	 */
	private function write( $level, $message ) {
		$line = sprintf( "[%s] [%s] %s\n", gmdate( 'Y-m-d H:i:s' ), $level, str_replace( array( "\r", "\n" ), ' ', (string) $message ) );
		@file_put_contents( $this->file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Contenu lisible du journal (sans l'en-tête de protection).
	 *
	 * @param string $file Chemin.
	 * @return string
	 */
	public static function read_contents( $file ) {
		if ( ! is_file( $file ) ) {
			return '';
		}
		$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 0 === strpos( $raw, Json_Store::HEADER ) ) {
			$raw = substr( $raw, strlen( Json_Store::HEADER ) );
		}
		return $raw;
	}

	/**
	 * Dernières lignes du journal.
	 *
	 * @param int $count Nombre de lignes.
	 * @return string[]
	 */
	public function tail( $count = 20 ) {
		$size = @filesize( $this->file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size ) {
			return array();
		}
		$h = @fopen( $this->file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			return array();
		}
		$read = min( $size, 16384 );
		fseek( $h, -$read, SEEK_END );
		$chunk = (string) fread( $h, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = array_filter( explode( "\n", $chunk ), 'strlen' );
		$lines = array_values(
			array_filter(
				$lines,
				function ( $l ) {
					return 0 === strpos( $l, '[' );
				}
			)
		);
		return array_slice( $lines, -$count );
	}
}
