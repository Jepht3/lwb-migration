<?php
/**
 * Interface d'administration.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Page « Lwb Migration ».
 */
class Admin {

	const SLUG = 'lwb-migration';

	/**
	 * Crochets.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LWB_MIGRATION_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Lwb Migration', 'lwb-migration' ),
			__( 'Lwb Migration', 'lwb-migration' ),
			Security::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-migrate',
			80
		);
	}

	/**
	 * Lien « Exporter » dans la liste des extensions.
	 *
	 * @param string[] $links Liens.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Exporter', 'lwb-migration' ) . '</a>' );
		return $links;
	}

	/**
	 * Avis après nettoyage automatique.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( get_transient( 'lwb_migration_cleaned' ) && current_user_can( Security::CAPABILITY ) ) {
			delete_transient( 'lwb_migration_cleaned' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lwb Migration : migration terminée, les fichiers d’installation restants ont été supprimés.', 'lwb-migration' ) . '</p></div>';
		}
	}

	/**
	 * Feuilles de style et scripts (sur notre page uniquement).
	 *
	 * @param string $hook Page courante.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'lwb-migration-admin', LWB_MIGRATION_URL . 'admin/css/admin.css', array(), LWB_MIGRATION_VERSION );
		wp_enqueue_script( 'lwb-migration-admin', LWB_MIGRATION_URL . 'admin/js/admin.js', array( 'wp-i18n' ), LWB_MIGRATION_VERSION, true );
		wp_set_script_translations( 'lwb-migration-admin', 'lwb-migration', LWB_MIGRATION_DIR . 'languages' );
		wp_localize_script(
			'lwb-migration-admin',
			'LwbMigration',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Security::NONCE ),
			)
		);
	}

	/**
	 * Onglet courant.
	 *
	 * @return string
	 */
	private static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export';
		return in_array( $tab, array( 'export', 'direct', 'settings' ), true ) ? $tab : 'export';
	}

	/**
	 * Affiche la page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Security::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'lwb-migration' ) );
		}
		$tab   = self::current_tab();
		$tabs  = array(
			'export'   => __( 'Exporter', 'lwb-migration' ),
			'direct'   => __( 'Migration directe', 'lwb-migration' ),
			'settings' => __( 'Paramètres', 'lwb-migration' ),
		);
		$error = '';
		try {
			Security::storage_dir();
		} catch ( Migration_Exception $e ) {
			$error = $e->getMessage() . ' ' . $e->get_solution();
		}
		include LWB_MIGRATION_DIR . 'admin/views/page.php';
	}

	/**
	 * Diagnostic rapide de l'environnement (affiché dans l'onglet Exporter).
	 *
	 * @return array[] label, value, ok.
	 */
	public static function environment() {
		global $wpdb;
		$max = (int) ini_get( 'max_execution_time' );
		return array(
			array(
				'label' => __( 'Version de PHP', 'lwb-migration' ),
				'value' => PHP_VERSION,
				'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
			),
			array(
				'label' => __( 'Base de données', 'lwb-migration' ),
				'value' => $wpdb->db_server_info(),
				'ok'    => true,
			),
			array(
				'label' => __( 'Format d’archive', 'lwb-migration' ),
				'value' => 'zip' === Archiver::choose_format( Settings::get()['format'] ) ? __( 'ZIP standard', 'lwb-migration' ) : __( 'LWB (repli sans compression)', 'lwb-migration' ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Durée d’une étape', 'lwb-migration' ),
				/* translators: 1: budget en secondes, 2: max_execution_time. */
				'value' => sprintf( __( '%1$s s (max_execution_time : %2$s)', 'lwb-migration' ), number_format_i18n( Runtime::default_budget(), 1 ), $max > 0 ? $max . ' s' : __( 'illimité', 'lwb-migration' ) ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Mémoire PHP', 'lwb-migration' ),
				'value' => (string) ini_get( 'memory_limit' ),
				'ok'    => Runtime::memory_limit_bytes() <= 0 || Runtime::memory_limit_bytes() >= 64 * 1048576,
			),
		);
	}

	/**
	 * Tables de la base (pour les exclusions).
	 *
	 * @return array[] name, size.
	 */
	public static function tables() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['Engine'] ) ) {
				continue; // Vue.
			}
			$out[] = array(
				'name' => $row['Name'],
				'size' => Runtime::format_bytes( (int) $row['Data_length'] + (int) $row['Index_length'] ),
				'own'  => 0 === strpos( $row['Name'], $wpdb->base_prefix ),
			);
		}
		return $out;
	}
}
