<?php
/**
 * Mises à jour automatiques de l'extension depuis les versions publiées.
 *
 * Chaque version publiée (release GitHub construite automatiquement à partir d'un tag)
 * contient le zip de l'extension et un manifeste latest.json :
 * { version, zip_url, sha256, requires, requires_php, tested, published, changelog[] }.
 *
 * L'extension déclare « Update URI: https://lewebmax.com/lwb-migration/ » : WordPress
 * ne la confond donc jamais avec une extension homonyme de wordpress.org et appelle le
 * filtre natif update_plugins_lewebmax.com (WordPress 5.8+). La mise à jour apparaît
 * dans Extensions › Mises à jour comme n'importe quelle autre, et l'intégrité du zip
 * est vérifiée (SHA-256) avant installation.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Gestion des mises à jour.
 */
class Updater {

	const MANIFEST = 'https://github.com/Jepht3/lwb-migration/releases/latest/download/latest.json';
	const HOMEPAGE = 'https://lewebmax.com/lwb-migration/';
	const HOST     = 'lewebmax.com';
	const SLUG     = 'lwb-migration';
	const CACHE    = 'lwb_migration_latest_release';
	const CHECKED  = 'lwb_migration_update_checked';

	/**
	 * Crochets.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'check' ), 10, 4 );
		// Filet de sécurité : si wordpress.org est injoignable, WordPress n'appelle pas le
		// filtre ci-dessus ; l'information est alors ajoutée à la lecture du cache.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_package' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		// « Rechercher les mises à jour » (Tableau de bord › Mises à jour) relit aussi le manifeste.
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'flush' ) );
		add_action( 'admin_post_lwb_migration_check_updates', array( __CLASS__, 'handle_row_check' ) );
		// Tâche planifiée de WordPress (2 fois par jour) : rafraîchit le manifeste même si
		// wordpress.org est injoignable depuis l'hébergement.
		add_action( 'wp_update_plugins', array( __CLASS__, 'refresh_cache' ), 5 );
	}

	/**
	 * Rafraîchit le manifeste si le cache a expiré.
	 *
	 * @return void
	 */
	public static function refresh_cache() {
		if ( false === get_site_transient( self::CACHE ) ) {
			self::latest( true );
		}
		self::ensure_transient();
	}

	/**
	 * Adresse du manifeste (filtrable pour un miroir ou des tests).
	 *
	 * @return string
	 */
	public static function manifest_url() {
		return (string) apply_filters( 'lwb_migration_update_manifest', self::MANIFEST );
	}

	/**
	 * Fichier principal de l'extension (relatif au dossier des extensions).
	 *
	 * @return string
	 */
	private static function plugin_file() {
		return plugin_basename( LWB_MIGRATION_FILE );
	}

	/**
	 * Vide le cache du manifeste.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Valide un manifeste reçu.
	 *
	 * @param mixed $data Données décodées.
	 * @return array|null
	 */
	public static function sanitize_manifest( $data ) {
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['zip_url'] ) ) {
			return null;
		}
		$version = (string) $data['version'];
		// HTTPS obligatoire (HTTP seulement pour les tests locaux, comme la migration directe).
		$schemes = Direct_Transfer::allow_http() ? array( 'https', 'http' ) : array( 'https' );
		$zip     = esc_url_raw( (string) $data['zip_url'], $schemes );
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?([.-][0-9A-Za-z.-]+)?$/', $version ) || '' === $zip ) {
			return null;
		}
		$sha = isset( $data['sha256'] ) ? strtolower( (string) $data['sha256'] ) : '';
		return array(
			'version'      => $version,
			'zip_url'      => $zip,
			'sha256'       => preg_match( '/^[a-f0-9]{64}$/', $sha ) ? $sha : '',
			'requires'     => isset( $data['requires'] ) ? sanitize_text_field( (string) $data['requires'] ) : '5.8',
			'requires_php' => isset( $data['requires_php'] ) ? sanitize_text_field( (string) $data['requires_php'] ) : '7.4',
			'tested'       => isset( $data['tested'] ) ? sanitize_text_field( (string) $data['tested'] ) : '',
			'published'    => isset( $data['published'] ) ? sanitize_text_field( (string) $data['published'] ) : '',
			'changelog'    => isset( $data['changelog'] ) && is_array( $data['changelog'] ) ? array_map( 'sanitize_text_field', array_map( 'strval', $data['changelog'] ) ) : array(),
		);
	}

	/**
	 * Dernière version publiée (mise en cache 6 h, 30 min en cas d'échec).
	 *
	 * @param bool $force Ignorer le cache.
	 * @return array|null
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}
		$res  = wp_remote_get(
			self::manifest_url(),
			array(
				'timeout'    => 10,
				'user-agent' => 'LwbMigration/' . LWB_MIGRATION_VERSION . '; ' . home_url( '/' ),
			)
		);
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = self::sanitize_manifest( json_decode( (string) wp_remote_retrieve_body( $res ), true ) );
		}
		set_site_transient( self::CACHE, $data ? $data : array(), $data ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
		update_site_option( self::CHECKED, time() );
		return $data;
	}

	/**
	 * Icônes de l'extension.
	 *
	 * @return array
	 */
	private static function icons() {
		return array(
			'svg' => LWB_MIGRATION_URL . 'assets/icon.svg',
			'1x'  => LWB_MIGRATION_URL . 'assets/icon-128x128.png',
			'2x'  => LWB_MIGRATION_URL . 'assets/icon-256x256.png',
		);
	}

	/**
	 * Filtre update_plugins_lewebmax.com : informe WordPress de la dernière version.
	 *
	 * @param array|false $update      Mise à jour déjà trouvée.
	 * @param array       $plugin_data En-têtes de l'extension.
	 * @param string      $plugin_file Fichier de l'extension.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::plugin_file() !== $plugin_file ) {
			return $update;
		}
		$m = self::latest();
		if ( ! $m ) {
			return $update;
		}
		return array(
			'id'           => self::HOMEPAGE,
			'slug'         => self::SLUG,
			'version'      => $m['version'],
			'url'          => self::HOMEPAGE,
			'package'      => $m['zip_url'],
			'requires'     => $m['requires'],
			'requires_php' => $m['requires_php'],
			'tested'       => $m['tested'],
			'icons'        => self::icons(),
		);
	}

	/**
	 * Ajoute la mise à jour au cache de WordPress quand elle n'y figure pas.
	 *
	 * @param mixed $transient Valeur du cache update_plugins.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$file = self::plugin_file();
		if ( isset( $transient->response[ $file ] ) || isset( $transient->no_update[ $file ] ) ) {
			return $transient;
		}
		$cached = get_site_transient( self::CACHE );
		$m      = is_array( $cached ) && ! empty( $cached['version'] ) ? $cached : null;
		if ( ! $m ) {
			return $transient;
		}
		$item              = (object) self::check( false, array(), $file );
		$item->id          = self::HOMEPAGE;
		$item->plugin      = $file;
		$item->new_version = $m['version'];
		if ( version_compare( $m['version'], LWB_MIGRATION_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	/**
	 * Fenêtre « Afficher les détails » de l'écran Extensions.
	 *
	 * @param false|object|array $result Résultat.
	 * @param string             $action Action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::latest();
		if ( ! $m ) {
			return $result;
		}
		$changelog = '';
		foreach ( $m['changelog'] as $line ) {
			$changelog .= '<li>' . esc_html( $line ) . '</li>';
		}
		return (object) array(
			'name'          => 'Lwb Migration',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => '<a href="https://lewebmax.com">LEWEBMAX</a>',
			'homepage'      => self::HOMEPAGE,
			'requires'      => $m['requires'],
			'requires_php'  => $m['requires_php'],
			'tested'        => $m['tested'],
			'last_updated'  => $m['published'],
			'download_link' => $m['zip_url'],
			'icons'         => self::icons(),
			'banners'       => array(),
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Migrez un site WordPress complet (fichiers + base de données) vers n’importe quel serveur, sans configuration : export en un clic, installer.php autonome ou migration directe de serveur à serveur.', 'lwb-migration' ) . '</p>',
				'changelog'   => '<h4>' . esc_html( $m['version'] ) . '</h4><ul>' . $changelog . '</ul>',
			),
		);
	}

	/**
	 * Vérifie l'empreinte SHA-256 du zip avant que WordPress ne l'installe.
	 *
	 * @param bool|string|\WP_Error $reply   Réponse (false = téléchargement par défaut).
	 * @param string                $package URL du paquet.
	 * @param object                $upgrader Instance de l'outil de mise à jour.
	 * @return bool|string|\WP_Error
	 */
	public static function verify_package( $reply, $package, $upgrader ) {
		unset( $upgrader );
		if ( false !== $reply ) {
			return $reply;
		}
		$m = self::latest();
		if ( ! $m || '' === $m['sha256'] || $package !== $m['zip_url'] ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( $m['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			self::flush();
			return new \WP_Error(
				'lwb_migration_checksum',
				__( 'Lwb Migration : le fichier de mise à jour téléchargé est corrompu (empreinte SHA-256 invalide). Réessayez plus tard.', 'lwb-migration' )
			);
		}
		return $file;
	}

	/**
	 * État des mises à jour pour l'interface.
	 *
	 * @param bool $fetch Interroger le serveur si le cache est vide.
	 * @return array
	 */
	public static function status( $fetch = false ) {
		$cached = get_site_transient( self::CACHE );
		$m      = is_array( $cached ) && ! empty( $cached['version'] ) ? $cached : null;
		if ( null === $m && false === $cached && $fetch ) {
			$m = self::latest();
		}
		$file   = self::plugin_file();
		$newer  = $m && version_compare( $m['version'], LWB_MIGRATION_VERSION, '>' );
		$update = '';
		if ( $newer && current_user_can( 'update_plugins' ) ) {
			$update = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
		}
		return array(
			'current'    => LWB_MIGRATION_VERSION,
			'latest'     => $m ? $m['version'] : '',
			'known'      => false !== $cached,
			'has_update' => $newer,
			'update_url' => $update,
			'changelog'  => $m ? $m['changelog'] : array(),
			'homepage'   => self::HOMEPAGE,
		);
	}

	/**
	 * Vérification immédiate (bouton de l'interface) : vide les caches et relance WordPress.
	 *
	 * @return array État.
	 */
	public static function check_now() {
		// L'ordre compte : vider le cache de WordPress déclenche flush(), on relit donc après.
		delete_site_transient( 'update_plugins' );
		self::latest( true );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		wp_update_plugins();
		self::ensure_transient();
		return self::status();
	}

	/**
	 * Recrée le cache des mises à jour de WordPress s'il n'existe pas.
	 *
	 * Quand wordpress.org est injoignable depuis l'hébergement, wp_update_plugins()
	 * s'interrompt sans rien enregistrer : la mise à jour de l'extension resterait
	 * invisible. On enregistre alors un cache minimal, que inject() complète.
	 *
	 * @return void
	 */
	private static function ensure_transient() {
		if ( false !== get_site_transient( 'update_plugins' ) ) {
			return;
		}
		$value               = new \stdClass();
		$value->last_checked = time();
		$value->checked      = array( self::plugin_file() => LWB_MIGRATION_VERSION );
		$value->response     = array();
		$value->translations = array();
		$value->no_update    = array();
		remove_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'flush' ) );
		set_site_transient( 'update_plugins', $value );
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Lien « Vérifier les mises à jour » sous l'extension (écran Extensions).
	 *
	 * @param string[] $links Liens.
	 * @param string   $file  Extension.
	 * @return string[]
	 */
	public static function row_meta( $links, $file ) {
		if ( self::plugin_file() !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lwb_migration_check_updates' ), 'lwb_migration_check_updates' ) ) . '">' . esc_html__( 'Vérifier les mises à jour', 'lwb-migration' ) . '</a>';
		return $links;
	}

	/**
	 * Action du lien de l'écran Extensions.
	 *
	 * @return void
	 */
	public static function handle_row_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'lwb-migration' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lwb_migration_check_updates' );
		self::check_now();
		wp_safe_redirect( admin_url( 'admin.php?page=lwb-migration' ) );
		exit;
	}
}
