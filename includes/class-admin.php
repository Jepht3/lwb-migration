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
	 * Icône du menu (SVG monochrome recoloré par WordPress).
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0zIDJoOC41QTEuNSAxLjUgMCAwIDEgMTMgMy41VjhoLTEuNlY1LjJIMy42djQuMkg2VjExSDNBMS41IDEuNSAwIDAgMSAxLjUgOS41di02QTEuNSAxLjUgMCAwIDEgMyAyem01IDdoOWExLjUgMS41IDAgMCAxIDEuNSAxLjV2NkExLjUgMS41IDAgMCAxIDE3IDE4SDhhMS41IDEuNSAwIDAgMS0xLjUtMS41di02QTEuNSAxLjUgMCAwIDEgOCA5em0xLjIgMy4ydjMuNmg2LjZ2LTMuNnpNMy42IDEyLjRoMS42djEuNGMwIC4zLjIuNS41LjVoLjNsLS45LS45IDEuMS0xLjEgMi44IDIuOC0yLjggMi44LTEuMS0xLjEuOS0uOWgtLjNhMi4xIDIuMSAwIDAgMS0yLjEtMi4xeiIvPjwvc3ZnPg==';

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
			self::MENU_ICON,
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
				'update'  => Updater::status(),
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
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home';
		return in_array( $tab, array( 'home', 'export', 'direct', 'import', 'backups', 'settings' ), true ) ? $tab : 'home';
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
		$tabs  = self::sections();
		$error = '';
		try {
			Security::storage_dir();
		} catch ( Migration_Exception $e ) {
			$error = $e->getMessage() . ' ' . $e->get_solution();
		}
		include LWB_MIGRATION_DIR . 'admin/views/page.php';
	}

	/**
	 * Sections du tableau de bord : titre et phrase d'accroche.
	 *
	 * @return array[] slug => title, lead.
	 */
	public static function sections() {
		return array(
			'export'   => array(
				'title' => __( 'Exporter le site', 'lwb-migration' ),
				'lead'  => __( 'Deux choix, un bouton. Vous obtenez un seul fichier, à importer sur le nouveau site.', 'lwb-migration' ),
			),
			'direct'   => array(
				'title' => __( 'Migration directe', 'lwb-migration' ),
				'lead'  => __( 'De serveur à serveur, avec une clé. Rien à télécharger sur votre ordinateur.', 'lwb-migration' ),
			),
			'import'   => array(
				'title' => __( 'Importer un site', 'lwb-migration' ),
				'lead'  => __( 'Un seul fichier, un clic. Le site est restauré ici, avec ses adresses mises à jour.', 'lwb-migration' ),
			),
			'backups'  => array(
				'title' => __( 'Sauvegardes', 'lwb-migration' ),
				'lead'  => __( 'Votre site se sauvegarde tout seul. Chaque sauvegarde se restaure en un clic.', 'lwb-migration' ),
			),
			'settings' => array(
				'title' => __( 'Paramètres', 'lwb-migration' ),
				'lead'  => __( 'Fichiers et tables exclus, format et chiffrement de l’archive.', 'lwb-migration' ),
			),
		);
	}

	/**
	 * URL d'une section (ou de l'accueil).
	 *
	 * @param string $slug Section, vide pour l'accueil.
	 * @return string
	 */
	public static function url( $slug = '' ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		return '' === $slug ? $url : add_query_arg( 'tab', $slug, $url );
	}

	/**
	 * Icône du tableau de bord (SVG en trait, décoratif).
	 *
	 * @param string $name Nom.
	 * @param int    $size Taille en px.
	 * @return string
	 */
	public static function icon( $name, $size = 24 ) {
		$paths = array(
			'export'   => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
			'direct'   => '<rect x="2" y="4" width="8" height="12" rx="2"/><rect x="14" y="8" width="8" height="12" rx="2"/><path d="M10 10h4"/><path d="m12.5 8 2 2-2 2"/>',
			'import'   => '<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M5 3h14"/>',
			'backups'  => '<path d="M12 3a9 9 0 1 0 9 9"/><path d="M21 4v5h-5"/><path d="M12 8v4l3 2"/>',
			'settings' => '<path d="M4 6h10"/><path d="M18 6h2"/><circle cx="16" cy="6" r="2"/><path d="M4 12h4"/><path d="M12 12h8"/><circle cx="10" cy="12" r="2"/><path d="M4 18h12"/><circle cx="18" cy="18" r="2"/>',
			'arrow'    => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
			'back'     => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
			'check'    => '<path d="m5 12 5 5 9-10"/>',
			'alert'    => '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
			'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
			'upload'   => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
			'restore'  => '<path d="M12 3a9 9 0 1 0 9 9"/><path d="M21 4v5h-5"/>',
			'key'      => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3"/><path d="m16 6 3 3"/><path d="m14 8 2 2"/>',
			'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'server'   => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01"/><path d="M7 16.5h.01"/>',
		);
		$d     = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['arrow'];
		return '<svg class="lwbm-svg" aria-hidden="true" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
	}

	/**
	 * Données de l'accueil : état de chaque section et activité récente.
	 *
	 * @return array tiles, activity.
	 */
	public static function home() {
		$fmt      = get_option( 'time_format' );
		$packages = array();
		$backups  = array();
		try {
			$packages = Exporter::list_packages( 'migration' );
			$backups  = Exporter::list_packages( 'backup' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$key = Direct_Transfer::active_key();
		$b   = Backups::settings();
		$st  = Backups::state();

		$n          = count( $packages );
		$freq_names = array(
			'twicedaily' => __( 'Deux fois par jour', 'lwb-migration' ),
			'daily'      => __( 'Tous les jours', 'lwb-migration' ),
			'weekly'     => __( 'Toutes les semaines', 'lwb-migration' ),
			'monthly'    => __( 'Tous les mois', 'lwb-migration' ),
		);
		$last       = ! empty( $st['history'] ) ? $st['history'][0] : null;
		$tiles      = array(
			'export'   => array(
				'text' => __( 'Un seul fichier, à importer sur le nouveau site en un clic.', 'lwb-migration' ),
				/* translators: %d: nombre de paquets. */
				'meta' => $n > 0 ? sprintf( _n( '%d paquet disponible', '%d paquets disponibles', $n, 'lwb-migration' ), $n ) : __( 'Aucun paquet pour le moment', 'lwb-migration' ),
				'tone' => '',
			),
			'direct'   => array(
				'text' => __( 'De serveur à serveur, avec une clé. Rien à télécharger.', 'lwb-migration' ),
				/* translators: %s: heure d'expiration. */
				'meta' => $key ? sprintf( __( 'Clé active jusqu’à %s', 'lwb-migration' ), wp_date( $fmt, $key['expires'] ) ) : __( 'Aucune clé active', 'lwb-migration' ),
				'tone' => $key ? 'ok' : '',
			),
			'import'   => array(
				'text' => __( 'Déposez le fichier d’un site et cliquez sur Restaurer.', 'lwb-migration' ),
				'meta' => __( 'Remplace ce site', 'lwb-migration' ),
				'tone' => 'warn',
			),
			'backups'  => array(
				'text' => __( 'Automatiques, restaurables en un clic.', 'lwb-migration' ),
				'meta' => $b['enabled']
					/* translators: 1: fréquence, 2: heure. */
					? sprintf( __( '%1$s à %2$s', 'lwb-migration' ), $freq_names[ $b['frequency'] ], sprintf( '%02d:00', (int) $b['hour'] ) )
					: __( 'Désactivées', 'lwb-migration' ),
				'tone' => $b['enabled'] ? ( $last && 'error' === $last['status'] ? 'error' : 'ok' ) : 'warn',
				'sub'  => $last
					/* translators: %s: date. */
					? sprintf( 'ok' === $last['status'] ? __( 'Dernière : %s', 'lwb-migration' ) : __( 'Dernière : échec le %s', 'lwb-migration' ), wp_date( get_option( 'date_format' ) . ' ' . $fmt, (int) $last['time'] ) )
					: '',
			),
			'settings' => array(
				'text' => __( 'Fichiers et tables exclus, format et chiffrement de l’archive.', 'lwb-migration' ),
				'meta' => Settings::get() == Settings::defaults() ? __( 'Réglages recommandés', 'lwb-migration' ) : __( 'Réglages personnalisés', 'lwb-migration' ), // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- comparaison de tableaux.
				'tone' => '',
			),
		);

		$activity = array();
		foreach ( array_slice( $st['history'], 0, 4 ) as $h ) {
			$ok         = 'ok' === $h['status'];
			$activity[] = array(
				'time'  => (int) $h['time'],
				'icon'  => $ok ? 'check' : 'alert',
				'tone'  => $ok ? 'ok' : 'error',
				'title' => $ok
					? ( 'auto' === ( isset( $h['trigger'] ) ? $h['trigger'] : '' ) ? __( 'Sauvegarde automatique réussie', 'lwb-migration' ) : __( 'Sauvegarde réussie', 'lwb-migration' ) )
					: __( 'Sauvegarde en échec', 'lwb-migration' ),
				'meta'  => $ok
					? trim( ( 'database' === ( isset( $h['content'] ) ? $h['content'] : '' ) ? __( 'Base de données seule', 'lwb-migration' ) : __( 'Site complet', 'lwb-migration' ) ) . ( ! empty( $h['size'] ) ? ' · ' . Runtime::format_bytes( (int) $h['size'] ) : '' ) )
					: (string) $h['message'],
				'url'   => self::url( 'backups' ),
			);
		}
		try {
			$history = History::entries();
		} catch ( \Throwable $e ) {
			$history = array();
		}
		foreach ( array_slice( $history, 0, 5 ) as $h ) {
			$direct = 'direct' === $h['type'];
			$dest   = preg_replace( '#^https?://#', '', '' !== $h['final_url'] ? $h['final_url'] : $h['destination'] );
			$titles = $direct
				? array(
					/* translators: %s: site de destination. */
					'ok'          => $h['installed'] ? __( 'Site migré vers %s', 'lwb-migration' ) : __( 'Migration directe vers %s : prête à installer', 'lwb-migration' ),
					/* translators: %s: site de destination. */
					'running'     => __( 'Migration directe vers %s en cours', 'lwb-migration' ),
					/* translators: %s: site de destination. */
					'error'       => __( 'Migration directe vers %s en échec', 'lwb-migration' ),
					/* translators: %s: site de destination. */
					'cancelled'   => __( 'Migration directe vers %s annulée', 'lwb-migration' ),
					/* translators: %s: site de destination. */
					'interrupted' => __( 'Migration directe vers %s interrompue', 'lwb-migration' ),
				)
				: array(
					'ok'          => __( 'Export du site créé', 'lwb-migration' ),
					'running'     => __( 'Export du site en cours', 'lwb-migration' ),
					'error'       => __( 'Export du site en échec', 'lwb-migration' ),
					'cancelled'   => __( 'Export du site annulé', 'lwb-migration' ),
					'interrupted' => __( 'Export du site interrompu', 'lwb-migration' ),
				);
			$tones  = array(
				'ok'          => 'ok',
				'running'     => '',
				'error'       => 'error',
				'cancelled'   => '',
				'interrupted' => 'warn',
			);
			$meta   = array();
			if ( in_array( $h['status'], array( 'error' ), true ) && '' !== $h['message'] ) {
				$meta[] = $h['message'];
			}
			if ( $h['size'] > 0 ) {
				$meta[] = Runtime::format_bytes( (int) $h['size'] );
			}
			if ( isset( $h['files']['archive'] ) ) {
				$meta[] = __( 'fichier du site téléchargeable', 'lwb-migration' );
			}
			$activity[] = array(
				'time'  => (int) $h['started'],
				'icon'  => 'ok' === $h['status'] ? 'check' : ( in_array( $h['status'], array( 'error', 'interrupted' ), true ) ? 'alert' : ( $direct ? 'direct' : 'export' ) ),
				'tone'  => isset( $tones[ $h['status'] ] ) ? $tones[ $h['status'] ] : '',
				'title' => sprintf( isset( $titles[ $h['status'] ] ) ? $titles[ $h['status'] ] : $titles['running'], '' !== $dest ? $dest : __( 'un autre site', 'lwb-migration' ) ),
				'meta'  => implode( ' · ', $meta ),
				'url'   => self::url( $direct ? 'direct' : 'export' ),
			);
		}
		// Paquets antérieurs à l'historique (versions précédentes de l'extension).
		$known = wp_list_pluck( $history, 'package' );
		foreach ( array_slice( $packages, 0, 3 ) as $p ) {
			if ( in_array( $p['id'], $known, true ) ) {
				continue;
			}
			$activity[] = array(
				'time'  => (int) $p['created'],
				'icon'  => 'export',
				'tone'  => '',
				'title' => ! empty( $p['direct'] ) ? __( 'Migration directe envoyée', 'lwb-migration' ) : __( 'Paquet de migration créé', 'lwb-migration' ),
				'meta'  => Runtime::format_bytes( (int) $p['archive_size'] ),
				'url'   => self::url( ! empty( $p['direct'] ) ? 'direct' : 'export' ),
			);
		}
		usort(
			$activity,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return array(
			'tiles'    => $tiles,
			'activity' => array_slice( $activity, 0, 6 ),
			'backups'  => count( $backups ),
		);
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
				'name'  => $row['Name'],
				'size'  => Runtime::format_bytes( (int) $row['Data_length'] + (int) $row['Index_length'] ),
				'bytes' => (int) $row['Data_length'] + (int) $row['Index_length'],
				'own'   => 0 === strpos( $row['Name'], $wpdb->base_prefix ),
			);
		}
		return $out;
	}
}
