<?php
/**
 * Réglages de l'extension.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture, validation et valeurs par défaut des réglages.
 */
class Settings {

	const OPTION = 'lwb_migration_settings';

	/**
	 * Valeurs par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'format'          => 'auto',
			'compress'        => true,
			'exclude_dirs'    => array(),
			'exclude_exts'    => array( 'log' ),
			'exclude_tables'  => array(),
			'all_tables'      => false,
			'max_file_size'   => 0,
			'keep_packages'   => 3,
			'default_exclude' => true,
		);
	}

	/**
	 * Réglages courants.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Valide et enregistre des réglages bruts (issus d'un formulaire).
	 *
	 * @param array $raw Données non fiables.
	 * @return array Réglages enregistrés.
	 */
	public static function save( array $raw ) {
		$s           = self::defaults();
		$s['format'] = isset( $raw['format'] ) && in_array( $raw['format'], array( 'auto', 'zip', 'lwb' ), true ) ? $raw['format'] : 'auto';

		$s['compress']        = ! empty( $raw['compress'] );
		$s['all_tables']      = ! empty( $raw['all_tables'] );
		$s['default_exclude'] = ! empty( $raw['default_exclude'] );
		$s['max_file_size']   = isset( $raw['max_file_size'] ) ? max( 0, absint( $raw['max_file_size'] ) ) : 0;
		$s['keep_packages']   = isset( $raw['keep_packages'] ) ? min( 50, max( 1, absint( $raw['keep_packages'] ) ) ) : 3;

		$s['exclude_dirs'] = array();
		foreach ( self::lines( isset( $raw['exclude_dirs'] ) ? $raw['exclude_dirs'] : '' ) as $line ) {
			$line = trim( str_replace( '\\', '/', sanitize_text_field( $line ) ), '/' );
			if ( '' !== $line && false === strpos( $line, '..' ) ) {
				$s['exclude_dirs'][] = $line;
			}
		}
		$s['exclude_exts'] = array();
		foreach ( preg_split( '/[\s,;]+/', isset( $raw['exclude_exts'] ) ? (string) $raw['exclude_exts'] : '' ) as $ext ) {
			$ext = strtolower( ltrim( sanitize_text_field( $ext ), '.' ) );
			if ( '' !== $ext && preg_match( '/^[a-z0-9_-]{1,20}$/', $ext ) ) {
				$s['exclude_exts'][] = $ext;
			}
		}
		$s['exclude_tables'] = array();
		$tables              = isset( $raw['exclude_tables'] ) ? (array) $raw['exclude_tables'] : array();
		foreach ( $tables as $table ) {
			$table = sanitize_text_field( $table );
			if ( preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) {
				$s['exclude_tables'][] = $table;
			}
		}
		$s['exclude_dirs']   = array_values( array_unique( $s['exclude_dirs'] ) );
		$s['exclude_exts']   = array_values( array_unique( $s['exclude_exts'] ) );
		$s['exclude_tables'] = array_values( array_unique( $s['exclude_tables'] ) );
		update_option( self::OPTION, $s, false );
		return $s;
	}

	/**
	 * Découpe un texte en lignes.
	 *
	 * @param mixed $text Texte ou tableau.
	 * @return string[]
	 */
	private static function lines( $text ) {
		if ( is_array( $text ) ) {
			return array_map( 'strval', $text );
		}
		return preg_split( '/\r\n|\r|\n/', (string) $text );
	}

	/**
	 * Dossiers exclus par défaut (relatifs à la racine du site ou au dossier wp-content).
	 *
	 * @return string[] Chemins absolus.
	 */
	public static function default_excluded_paths() {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$uploads = wp_upload_dir( null, false );
		$up      = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$paths   = array(
			// Caches.
			$content . '/cache',
			$content . '/et-cache',
			$content . '/litespeed',
			$content . '/w3tc-cache',
			$content . '/wp-rocket-config',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$up . '/cache',
			$up . '/wpo-cache',
			$up . '/elementor/css',
			// Sauvegardes d'autres extensions.
			$content . '/ai1wm-backups',
			$content . '/updraft',
			$content . '/backups-dup-lite',
			$content . '/backups-dup-pro',
			$content . '/backup-db',
			$content . '/backups',
			$content . '/backupwordpress',
			$content . '/wpvividbackups',
			$content . '/wpvivid_staging',
			$content . '/backuply',
			$up . '/backupbuddy_backups',
			$up . '/backwpup',
			$up . '/wp-staging',
			$content . '/wp-staging',
			$up . '/jetbackup',
			untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/wp-snapshots',
		);
		foreach ( (array) glob( $up . '/backwpup-*', GLOB_ONLYDIR ) as $dir ) {
			$paths[] = wp_normalize_path( $dir );
		}
		foreach ( (array) glob( $up . '/wp-migrate-db*', GLOB_ONLYDIR ) as $dir ) {
			$paths[] = wp_normalize_path( $dir );
		}
		return $paths;
	}

	/**
	 * Noms exclus partout (dossiers et fichiers).
	 *
	 * @return string[]
	 */
	public static function default_excluded_names() {
		return array( '.git', '.svn', '.hg', 'node_modules', '.ds_store', 'thumbs.db', 'error_log', 'debug.log', '.lwb-migration-done' );
	}

	/**
	 * Règles d'exclusion complètes pour un export.
	 *
	 * @param array  $settings Réglages.
	 * @param string $storage  Dossier de stockage de l'extension.
	 * @return array
	 */
	public static function file_rules( array $settings, $storage ) {
		$root  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$paths = array(
			wp_normalize_path( $storage ),
			untrailingslashit( wp_normalize_path( LWB_MIGRATION_DIR ) ),
		);
		$names = array();
		if ( $settings['default_exclude'] ) {
			$paths = array_merge( $paths, self::default_excluded_paths() );
			$names = self::default_excluded_names();
		}
		// Restes d'une migration précédente à la racine.
		foreach ( (array) glob( $root . '/lwb-installer-*', GLOB_ONLYDIR ) as $dir ) {
			$paths[] = wp_normalize_path( $dir );
		}
		// Pas de GLOB_BRACE : indisponible sur certains systèmes (Alpine/musl).
		foreach ( array_merge( (array) glob( $root . '/archive-*.zip' ), (array) glob( $root . '/archive-*.lwb' ) ) as $file ) {
			$paths[] = wp_normalize_path( $file );
		}
		foreach ( (array) glob( $root . '/*installer*.php' ) as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== strpos( $head, 'LWB_MIGRATION_STANDALONE' ) ) {
				$paths[] = wp_normalize_path( $file );
			}
		}
		foreach ( $settings['exclude_dirs'] as $rel ) {
			$paths[] = $root . '/' . ltrim( $rel, '/' );
		}
		return array(
			'paths'      => array_values( array_unique( $paths ) ),
			'names'      => $names,
			'extensions' => $settings['exclude_exts'],
			'max_size'   => (int) $settings['max_file_size'] * 1048576,
		);
	}
}
