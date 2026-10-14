<?php
/**
 * Modèle de l'installeur autonome (installer.php).
 *
 * Ce fichier n'est pas exécutable seul : Installer_Builder le concatène aux
 * bibliothèques autonomes de l'extension et y injecte la configuration du paquet.
 * Il ne dépend pas de WordPress.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Installer;

use LwbMigration\Archive_Reader;
use LwbMigration\Db;
use LwbMigration\Json_Store;
use LwbMigration\Logger;
use LwbMigration\Migration_Exception;
use LwbMigration\Mysqli_Db;
use LwbMigration\Runtime;
use LwbMigration\Search_Replace;
use LwbMigration\Sql_Importer;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.Security, WordPress.PHP.DiscouragedPHPFunctions, WordPress.DateTime.RestrictedFunctions, WordPress.WP.GlobalVariablesOverride -- script autonome hors WordPress.

defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Installeur.
 */
class Installer {

	const COOKIE        = 'lwb_installer_auth';
	const AUTH_TTL      = 43200;
	const DONE_MARKER   = '.lwb-migration-done';
	const MAX_ATTEMPTS  = 10;
	const MAX_GLOBAL    = 100;
	const DIRECT_TTL    = 86400;
	const MANUAL_TTL    = 604800;
	const PHASES        = array( 'extract', 'import', 'replace', 'finalize' );
	const DROPINS       = array( 'advanced-cache.php', 'object-cache.php' );
	const SALT_KEYS     = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
	const CONTENT_DEFS  = array( 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'PLUGINDIR', 'WPMU_PLUGIN_DIR', 'WPMU_PLUGIN_URL' );
	const STORAGE_GUARD = 'Lwb Migration : accès interdit';

	/**
	 * Configuration du paquet (injectée à la génération par Installer_Builder).
	 *
	 * @return array
	 */
	public static function config() {
		return /*LWB_CONFIG_START*/ array(); /*LWB_CONFIG_END*/
	}

	/**
	 * Échappement HTML.
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	public static function h( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Racine du site de destination.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Nom de ce fichier.
	 *
	 * @var string
	 */
	private $self;

	/**
	 * Dossier de travail protégé.
	 *
	 * @var string
	 */
	private $data_dir;

	/**
	 * État persistant.
	 *
	 * @var array
	 */
	private $state = array();

	/**
	 * Journal.
	 *
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Constructeur.
	 *
	 * @param array  $config Configuration.
	 * @param string $file   Chemin de ce script.
	 */
	public function __construct( array $config, $file ) {
		$this->config   = $config;
		$real           = realpath( dirname( $file ) );
		$this->root     = self::normalize( false !== $real ? $real : dirname( $file ) );
		$this->self     = basename( $file );
		$this->data_dir = $this->root . '/lwb-installer-' . substr( hash( 'sha256', $config['secret'] . '|data' ), 0, 16 );
	}

	/**
	 * Normalise un chemin.
	 *
	 * @param string $path Chemin.
	 * @return string
	 */
	private static function normalize( $path ) {
		$path = preg_replace( '#/+#', '/', str_replace( '\\', '/', $path ) );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	// ------------------------------------------------------------------
	// Point d'entrée.
	// ------------------------------------------------------------------

	/**
	 * Traite la requête.
	 *
	 * @return void
	 */
	public function run() {
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( empty( $this->config['secret'] ) ) {
			$this->fatal_page( 'Installeur non configuré.', 'Générez un nouveau paquet depuis Lwb Migration.' );
		}
		// Ne jamais s'exécuter depuis le dossier de stockage protégé de l'extension.
		$guard = @file_get_contents( $this->root . '/.htaccess' );
		if ( false !== $guard && false !== strpos( $guard, self::STORAGE_GUARD ) ) {
			$this->fatal_page( 'Cet installeur doit être exécuté depuis la racine du site de destination.', 'Téléchargez installer.php et l’archive depuis l’administration, puis déposez-les sur le nouveau serveur.' );
		}

		// Un installeur doit toujours être protégé : mot de passe (export) ou jeton (migration directe).
		if ( 'direct' !== $this->config['mode'] && '' === (string) $this->config['password_hash'] ) {
			$this->fatal_page( 'Installeur non protégé.', 'Cet installer.php n’a pas de mot de passe : refaites l’export depuis Lwb Migration 1.3.0 ou plus récent (un mot de passe est désormais obligatoire).' );
		}

		$this->load_state();
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$this->enforce_expiry();

		// Installation terminée depuis plus d'une heure : on nettoie et on se retire.
		if ( 'done' === $this->phase() && ! empty( $this->state['done_at'] ) && time() - $this->state['done_at'] > 3600 && ! $is_post ) {
			$this->cleanup();
			$this->fatal_page( 'Migration terminée.', 'Les fichiers d’installation ont été supprimés automatiquement.', 'ok' );
		}

		if ( isset( $_GET['lwb_token'] ) ) {
			$this->login_with_token( (string) $_GET['lwb_token'] );
		}
		if ( $is_post ) {
			$this->handle_api();
			return;
		}
		if ( isset( $_GET['lwb_log'] ) ) {
			$this->require_auth_page();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="lwb-migration-installation.txt"' );
			echo Logger::read_contents( $this->data_dir . '/log.php' );
			exit;
		}
		if ( ! $this->is_authenticated() ) {
			$this->render_login();
			return;
		}
		$this->render_app();
	}

	/**
	 * Phase courante.
	 *
	 * @return string
	 */
	private function phase() {
		return isset( $this->state['phase'] ) ? $this->state['phase'] : 'setup';
	}

	// ------------------------------------------------------------------
	// État et journal.
	// ------------------------------------------------------------------

	/**
	 * Charge l'état.
	 *
	 * @return void
	 */
	private function load_state() {
		$state       = is_dir( $this->data_dir ) ? Json_Store::read( $this->data_dir . '/state.php' ) : null;
		$this->state = is_array( $state ) ? $state : array( 'phase' => 'setup' );
	}

	/**
	 * Enregistre l'état.
	 *
	 * @return void
	 */
	private function save_state() {
		Json_Store::ensure_protected_dir( $this->data_dir );
		Json_Store::write( $this->data_dir . '/state.php', $this->state );
	}

	/**
	 * Enregistre immédiatement une partie de l'état (points de contrôle intermédiaires).
	 *
	 * @param string $key   Clé.
	 * @param array  $value Valeur.
	 * @return void
	 */
	public function persist( $key, array $value ) {
		$this->state[ $key ] = $value;
		$this->save_state();
	}

	/**
	 * Journal.
	 *
	 * @return Logger
	 */
	private function log() {
		if ( null === $this->logger ) {
			Json_Store::ensure_protected_dir( $this->data_dir );
			$this->logger = new Logger( $this->data_dir . '/log.php' );
		}
		return $this->logger;
	}

	// ------------------------------------------------------------------
	// Authentification.
	// ------------------------------------------------------------------

	/**
	 * Installeur jamais utilisé et trop ancien : il se retire.
	 *
	 * Migration directe : 24 h après sa création (il contient les accès à la base de
	 * données). Export : 7 jours après sa première ouverture sur le serveur.
	 *
	 * @return void
	 */
	private function enforce_expiry() {
		if ( 'setup' !== $this->phase() ) {
			return; // Installation commencée : elle doit pouvoir reprendre.
		}
		if ( 'direct' === $this->config['mode'] ) {
			$since = (int) $this->config['created'];
			$ttl   = self::DIRECT_TTL;
		} else {
			if ( empty( $this->state['first_seen'] ) ) {
				$this->state['first_seen'] = time();
				try {
					$this->save_state();
				} catch ( Migration_Exception $e ) {
					return; // Dossier non accessible en écriture : signalé par les vérifications.
				}
			}
			$since = (int) $this->state['first_seen'];
			$ttl   = self::MANUAL_TTL;
		}
		if ( $since <= 0 || time() - $since <= $ttl ) {
			return;
		}
		if ( 'direct' === $this->config['mode'] ) {
			@unlink( $this->archive_path() ); // Archive déposée par la migration directe.
		}
		Json_Store::delete_dir( $this->data_dir );
		@unlink( $this->root . '/' . $this->self );
		$this->fatal_page( 'Installeur expiré.', 'Par sécurité, cet installeur inutilisé s’est supprimé. ' . ( 'direct' === $this->config['mode'] ? 'Relancez la migration directe depuis le site source.' : 'Générez un nouveau paquet depuis le site source.' ) );
	}

	/**
	 * Cookie de session valide ?
	 *
	 * @return bool
	 */
	private function is_authenticated() {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		$parts  = explode( '.', $cookie );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		if ( time() - (int) $parts[0] > self::AUTH_TTL ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', 'auth|' . $parts[0], $this->config['secret'] ), $parts[1] );
	}

	/**
	 * Ouvre une session.
	 *
	 * @return void
	 */
	private function set_auth_cookie() {
		$ts                      = (string) time();
		$value                   = $ts . '.' . hash_hmac( 'sha256', 'auth|' . $ts, $this->config['secret'] );
		$_COOKIE[ self::COOKIE ] = $value;
		$path                    = $this->script_dir() . '/';
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => time() + self::AUTH_TTL,
					'path'     => $path,
					'secure'   => $this->is_https(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE, $value, time() + self::AUTH_TTL, $path . '; samesite=Lax', '', $this->is_https(), true );
		}
	}

	/**
	 * Jeton anti-CSRF lié à la session.
	 *
	 * @return string
	 */
	private function csrf_token() {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : 'anonymous';
		return hash_hmac( 'sha256', 'csrf|' . $cookie, $this->config['secret'] );
	}

	/**
	 * Connexion par jeton (migration directe).
	 *
	 * @param string $token Jeton.
	 * @return void
	 */
	private function login_with_token( $token ) {
		if ( 'direct' === $this->config['mode'] && '' !== (string) $this->config['token_hash'] && hash_equals( (string) $this->config['token_hash'], hash( 'sha256', $token ) ) ) {
			$this->set_auth_cookie();
			header( 'Location: ' . $this->self, true, 302 );
			exit;
		}
		$this->fatal_page( 'Lien d’installation invalide.', 'Relancez la migration directe depuis le site source.' );
	}

	/**
	 * Limitation des tentatives de mot de passe.
	 *
	 * @param bool $failed Enregistrer un échec.
	 * @return bool true si l'adresse est bloquée.
	 */
	private function throttled( $failed = false ) {
		$file = $this->data_dir . '/attempts.php';
		$data = Json_Store::read( $file );
		$data = is_array( $data ) ? $data : array();
		$now  = time();
		$ip   = substr( hash_hmac( 'sha256', isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '', $this->config['secret'] ), 0, 16 );
		$all  = 0;
		foreach ( $data as $key => $times ) {
			$times = array_values(
				array_filter(
					is_array( $times ) ? $times : array(),
					function ( $t ) use ( $now ) {
						return $now - (int) $t < 900;
					}
				)
			);
			if ( empty( $times ) ) {
				unset( $data[ $key ] );
				continue;
			}
			$data[ $key ] = $times;

			$all += count( $times );
		}
		if ( $failed ) {
			$mine_list   = isset( $data[ $ip ] ) ? $data[ $ip ] : array();
			$mine_list[] = $now;
			$data[ $ip ] = $mine_list;
			++$all;
			Json_Store::ensure_protected_dir( $this->data_dir );
			Json_Store::write( $file, $data );
		}
		// Par adresse IP : un inconnu ne peut plus bloquer le propriétaire. Plafond global
		// en plus, contre les essais répartis sur de nombreuses adresses.
		$mine = isset( $data[ $ip ] ) ? count( $data[ $ip ] ) : 0;
		return $mine >= self::MAX_ATTEMPTS || $all >= self::MAX_GLOBAL;
	}

	/**
	 * Exige une session (pages GET).
	 *
	 * @return void
	 */
	private function require_auth_page() {
		if ( ! $this->is_authenticated() ) {
			header( 'Location: ' . $this->self, true, 302 );
			exit;
		}
	}

	// ------------------------------------------------------------------
	// API JSON.
	// ------------------------------------------------------------------

	/**
	 * Répond en JSON et termine.
	 *
	 * @param array $data Données.
	 * @param int   $code Code HTTP.
	 * @return void
	 */
	private function json( array $data, $code = 200 ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		http_response_code( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		exit;
	}

	/**
	 * Réponse d'erreur.
	 *
	 * @param string $message   Message.
	 * @param string $solution  Solution.
	 * @param bool   $retryable Réessayable.
	 * @param int    $code      Code HTTP.
	 * @return void
	 */
	private function error( $message, $solution = '', $retryable = false, $code = 200 ) {
		$this->json(
			array(
				'ok'        => false,
				'message'   => $message,
				'solution'  => $solution,
				'retryable' => $retryable,
			),
			$code
		);
	}

	/**
	 * Routeur des actions POST.
	 *
	 * @return void
	 */
	private function handle_api() {
		$action = isset( $_POST['lwb_action'] ) ? (string) $_POST['lwb_action'] : '';
		if ( 'login' === $action ) {
			$this->api_login();
			return;
		}
		if ( ! $this->is_authenticated() ) {
			$this->error( 'Session expirée.', 'Rechargez la page et saisissez à nouveau le mot de passe.', false, 403 );
		}
		$csrf = isset( $_POST['csrf'] ) ? (string) $_POST['csrf'] : '';
		if ( ! hash_equals( $this->csrf_token(), $csrf ) ) {
			$this->error( 'Jeton de sécurité invalide.', 'Rechargez la page.', false, 403 );
		}
		try {
			switch ( $action ) {
				case 'status':
					$this->json( $this->api_status() );
					break;
				case 'test_db':
					$this->json( $this->api_test_db() );
					break;
				case 'start':
					$this->json( $this->api_start() );
					break;
				case 'step':
					$this->json( $this->api_step() );
					break;
				case 'cleanup':
					$this->json( $this->api_cleanup() );
					break;
				case 'plugins':
					$this->json( $this->api_plugins() );
					break;
				case 'plugin_toggle':
					$this->json( $this->api_plugin_toggle() );
					break;
			}
		} catch ( Migration_Exception $e ) {
			if ( 'setup' !== $this->phase() ) {
				$this->log()->error( $e->getMessage() . ( $e->get_solution() ? ' – ' . $e->get_solution() : '' ) );
			}
			$this->error( $e->getMessage(), $e->get_solution(), $e->is_retryable() );
		} catch ( \Throwable $e ) {
			if ( 'setup' !== $this->phase() ) {
				$this->log()->error( sprintf( 'Erreur PHP : %s (%s:%d)', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
			}
			$this->error( 'Erreur inattendue : ' . $e->getMessage(), 'Relancez : l’installation reprendra au dernier point de sauvegarde. Si l’erreur persiste, téléchargez le journal.', true );
		}
		$this->error( 'Action inconnue.', '', false, 400 );
	}

	/**
	 * Connexion par mot de passe.
	 *
	 * @return void
	 */
	private function api_login() {
		if ( '' === (string) $this->config['password_hash'] ) {
			$this->error( 'Aucun mot de passe n’est défini pour cet installeur.' );
		}
		if ( $this->throttled() ) {
			$this->error( 'Trop de tentatives. Réessayez dans 15 minutes.', 'Si vous avez oublié le mot de passe, générez un nouveau paquet.' );
		}
		$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		if ( ! password_verify( $password, (string) $this->config['password_hash'] ) ) {
			$this->throttled( true );
			usleep( 800000 );
			$this->error( 'Mot de passe incorrect.', 'Utilisez le mot de passe affiché à la fin de l’export sur le site source. Perdu ? Refaites l’export.' );
		}
		$this->set_auth_cookie();
		$this->json(
			array(
				'ok'   => true,
				'csrf' => $this->csrf_token(),
			)
		);
	}

	// ------------------------------------------------------------------
	// Détection de l'environnement.
	// ------------------------------------------------------------------

	/**
	 * Requête en HTTPS ?
	 *
	 * @return bool
	 */
	private function is_https() {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			return true;
		}
		return isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'];
	}

	/**
	 * Dossier URL du script (sans barre finale).
	 *
	 * @return string
	 */
	private function script_dir() {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '/' . $this->self;
		$dir    = str_replace( '\\', '/', dirname( $script ) );
		return '/' === $dir || '.' === $dir ? '' : rtrim( $dir, '/' );
	}

	/**
	 * URL détectée du site de destination.
	 *
	 * @return string
	 */
	private function detected_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
		if ( ! preg_match( '/^[A-Za-z0-9.\-:\[\]]+$/', $host ) ) {
			$host = isset( $_SERVER['SERVER_NAME'] ) ? (string) $_SERVER['SERVER_NAME'] : 'localhost';
		}
		return ( $this->is_https() ? 'https' : 'http' ) . '://' . strtolower( $host ) . $this->script_dir();
	}

	/**
	 * URL d'accueil déduite (WordPress installé dans un sous-dossier de l'accueil).
	 *
	 * @param string $site_url Nouvelle URL du site.
	 * @return string
	 */
	private function derive_home( $site_url ) {
		$src_site = rtrim( $this->config['source']['site_url'], '/' );
		$src_home = rtrim( $this->config['source']['home_url'], '/' );
		if ( $src_site === $src_home || 0 !== strpos( $src_site, $src_home ) ) {
			return $site_url;
		}
		$suffix = substr( $src_site, strlen( $src_home ) );
		if ( '' !== $suffix && substr( $site_url, -strlen( $suffix ) ) === $suffix ) {
			return substr( $site_url, 0, -strlen( $suffix ) );
		}
		return $site_url;
	}

	/**
	 * Lit les identifiants d'un wp-config.php existant sur la destination.
	 *
	 * @return array
	 */
	private function existing_db_config() {
		$file = $this->root . '/wp-config.php';
		if ( ! is_file( $file ) ) {
			$file = dirname( $this->root ) . '/wp-config.php';
		}
		$text = is_file( $file ) ? (string) @file_get_contents( $file ) : '';
		if ( '' === $text || false !== strpos( $text, 'LWB_MIGRATION_STANDALONE' ) ) {
			return array();
		}
		$out = array();
		foreach ( array(
			'DB_HOST'     => 'host',
			'DB_NAME'     => 'name',
			'DB_USER'     => 'user',
			'DB_PASSWORD' => 'password',
		) as $const => $key ) {
			$value = self::read_define( $text, $const );
			if ( null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		if ( preg_match( '/\$table_prefix\s*=\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*;/', $text, $m ) ) {
			$out['prefix'] = stripcslashes( $m[2] );
		}
		return $out;
	}

	/**
	 * Mot de passe de base de données déjà connu du serveur (jamais transmis au navigateur).
	 *
	 * @return string
	 */
	private function saved_db_password() {
		if ( isset( $this->config['db']['password'] ) && '' !== (string) $this->config['db']['password'] ) {
			return (string) $this->config['db']['password'];
		}
		$existing = $this->existing_db_config();
		return isset( $existing['password'] ) ? (string) $existing['password'] : '';
	}

	/**
	 * Valeur littérale d'un define() dans un fichier PHP.
	 *
	 * @param string $text     Code.
	 * @param string $constant Constante.
	 * @return string|null
	 */
	private static function read_define( $text, $constant ) {
		$re = '/define\s*\(\s*([\'"])' . preg_quote( $constant, '/' ) . '\1\s*,\s*([\'"])((?:(?!\2)[^\\\\]|\\\\.)*)\2\s*\)/';
		if ( preg_match( $re, $text, $m ) ) {
			return "'" === $m[2] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $m[3] ) : stripcslashes( $m[3] );
		}
		return null;
	}

	/**
	 * Chemin de l'archive.
	 *
	 * @return string
	 */
	private function archive_path() {
		return $this->root . '/' . basename( (string) $this->config['archive'] );
	}

	/**
	 * Vérifications de l'environnement.
	 *
	 * @return array[] label, value, level (ok|warn|error), help.
	 */
	private function checks() {
		$c   = array();
		$add = function ( $label, $value, $level, $help = '' ) use ( &$c ) {
			$c[] = array(
				'label' => $label,
				'value' => $value,
				'level' => $level,
				'help'  => $help,
			);
		};
		$add( 'Version de PHP', PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'error', 'PHP 7.4 ou supérieur est requis : changez de version dans le panneau de votre hébergeur.' );
		$add( 'Extension mysqli', class_exists( '\mysqli' ) ? 'présente' : 'absente', class_exists( '\mysqli' ) ? 'ok' : 'error', 'Activez l’extension mysqli dans la configuration PHP.' );
		$add( 'Extension json', function_exists( 'json_encode' ) ? 'présente' : 'absente', function_exists( 'json_encode' ) ? 'ok' : 'error', 'Activez l’extension json.' );
		$zlib = function_exists( 'gzinflate' );
		$add( 'Extension zlib', $zlib ? 'présente' : 'absente', $zlib ? 'ok' : 'warn', 'Nécessaire pour les archives ZIP compressées. Sans elle, refaites l’export au format LWB.' );

		$archive = $this->archive_path();
		$present = is_file( $archive );
		if ( 'done' === $this->phase() ) {
			$add( 'Archive', 'déjà installée', 'ok' );
		} elseif ( ! $present ) {
			$add( 'Archive', 'introuvable', 'error', 'Déposez l’archive « ' . basename( $archive ) . ' » dans le même dossier que ' . $this->self . '.' );
		} else {
			$size = (int) @filesize( $archive );
			try {
				$reader = new Archive_Reader( $archive );
				$label  = Runtime::format_bytes( $size ) . ', ' . number_format( $reader->count(), 0, ',', ' ' ) . ' éléments (' . strtoupper( $reader->format() ) . ')';
				$level  = ( (int) $this->config['archive_size'] > 0 && (int) $this->config['archive_size'] !== $size ) ? 'error' : 'ok';
				$add( 'Archive', $label, $level, 'Taille inattendue : l’archive est incomplète. Retransférez-la en mode binaire.' );
			} catch ( Migration_Exception $e ) {
				$add( 'Archive', 'illisible', 'error', $e->getMessage() . ' ' . $e->get_solution() );
			}
		}
		$writable = is_writable( $this->root );
		$add( 'Dossier de destination', $writable ? 'accessible en écriture' : 'non accessible en écriture', $writable ? 'ok' : 'error', 'Donnez les droits d’écriture (755) au dossier ' . $this->root . '.' );
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->root ) : false;
		if ( false !== $free && $present ) {
			$need = (int) @filesize( $archive ) * 2;
			$add( 'Espace disque libre', Runtime::format_bytes( $free ), $free >= $need ? 'ok' : 'warn', 'Prévoyez au moins ' . Runtime::format_bytes( $need ) . ' libres (archive + fichiers extraits).' );
		}
		$mem = Runtime::memory_limit_bytes();
		$add( 'Mémoire PHP', $mem <= 0 ? 'illimitée' : (string) ini_get( 'memory_limit' ), ( $mem <= 0 || $mem >= 64 * 1048576 ) ? 'ok' : 'warn', '64 Mo minimum recommandés.' );
		$max = (int) ini_get( 'max_execution_time' );
		$add( 'Durée d’exécution', $max > 0 ? $max . ' s (étapes de ' . number_format( Runtime::default_budget(), 1, ',', ' ' ) . ' s)' : 'illimitée', 'ok' );
		if ( is_file( $this->root . '/wp-settings.php' ) && 'setup' === $this->phase() ) {
			$add( 'WordPress existant', 'détecté', 'warn', 'Les fichiers et les tables portant le même nom seront remplacés.' );
		}
		$add( 'Connexion', $this->is_https() ? 'HTTPS' : 'HTTP (non chiffrée)', $this->is_https() ? 'ok' : 'warn', 'Le site fonctionnera en HTTP. Activez un certificat SSL dès que possible.' );
		return $c;
	}

	/**
	 * État pour l'interface.
	 *
	 * @return array
	 */
	private function api_status() {
		$existing = $this->existing_db_config();
		$db       = ! empty( $this->config['db'] ) ? $this->config['db'] : array();
		$url      = ! empty( $this->config['target']['site_url'] ) ? $this->config['target']['site_url'] : $this->detected_url();
		$defaults = array(
			'host'     => isset( $db['host'] ) ? $db['host'] : ( isset( $existing['host'] ) ? $existing['host'] : 'localhost' ),
			'name'     => isset( $db['name'] ) ? $db['name'] : ( isset( $existing['name'] ) ? $existing['name'] : '' ),
			'user'     => isset( $db['user'] ) ? $db['user'] : ( isset( $existing['user'] ) ? $existing['user'] : '' ),
			// Jamais renvoyé au navigateur : seule sa présence est indiquée (voir saved_db_password()).
			'password' => '',
			'prefix'   => isset( $db['prefix'] ) ? $db['prefix'] : ( isset( $existing['prefix'] ) ? $existing['prefix'] : $this->config['source']['table_prefix'] ),
			'site_url' => $url,
			'home_url' => ! empty( $this->config['target']['home_url'] ) ? $this->config['target']['home_url'] : $this->derive_home( $url ),
			'path'     => $this->root,
		);
		if ( ! empty( $this->state['form'] ) ) {
			$defaults = array_merge( $defaults, $this->state['form'] );
		}
		$defaults['password'] = '';
		return array(
			'ok'             => true,
			'phase'          => $this->phase(),
			'checks'         => $this->checks(),
			'defaults'       => $defaults,
			'password_saved' => '' !== $this->saved_db_password(),
			'existing' => ! empty( $existing ),
			'progress' => $this->progress_payload(),
		);
	}

	// ------------------------------------------------------------------
	// Base de données.
	// ------------------------------------------------------------------

	/**
	 * Lit et valide le formulaire.
	 *
	 * @return array
	 * @throws Migration_Exception Données invalides.
	 */
	private function read_form() {
		$f = array();
		foreach ( array( 'host', 'name', 'user', 'password', 'prefix', 'site_url', 'home_url' ) as $k ) {
			$f[ $k ] = isset( $_POST[ $k ] ) ? trim( (string) $_POST[ $k ] ) : '';
		}
		$f['password'] = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		if ( '' === $f['password'] ) {
			// Champ laissé vide : mot de passe connu côté serveur (migration directe, wp-config existant).
			$f['password'] = $this->saved_db_password();
		}
		$f['create']     = ! empty( $_POST['create'] );
		$f['quarantine'] = ! empty( $_POST['quarantine'] );
		if ( '' === $f['host'] ) {
			$f['host'] = 'localhost';
		}
		if ( '' === $f['name'] || '' === $f['user'] ) {
			throw new Migration_Exception( 'Le nom de la base et l’utilisateur sont obligatoires.', 'Retrouvez-les dans le panneau de votre hébergeur (rubrique « Bases de données »).' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,32}$/', $f['prefix'] ) ) {
			throw new Migration_Exception( 'Préfixe de tables invalide.', 'Utilisez uniquement des lettres, chiffres et « _ » (exemple : wp_).' );
		}
		foreach ( array( 'site_url', 'home_url' ) as $k ) {
			$f[ $k ] = rtrim( $f[ $k ], '/' );
			if ( ! preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $f[ $k ] ) ) {
				throw new Migration_Exception( 'Adresse du site invalide : ' . $f[ $k ], 'Saisissez une URL complète, par exemple https://www.mon-site.fr' );
			}
		}
		return $f;
	}

	/**
	 * Connexion à la base.
	 *
	 * @param array $f Paramètres.
	 * @return Mysqli_Db
	 */
	private function connect( array $f ) {
		return new Mysqli_Db( $f['host'], $f['user'], $f['password'], $f['name'], ! empty( $f['create'] ) );
	}

	/**
	 * Test de connexion.
	 *
	 * @return array
	 * @throws Migration_Exception Échec.
	 */
	private function api_test_db() {
		$f       = $this->read_form();
		$db      = $this->connect( $f );
		$version = $db->server_version();
		$notes   = array();
		if ( false !== stripos( $version, 'mariadb' ) ) {
			$ok = version_compare( preg_replace( '/^(\d+\.\d+\.\d+).*$/', '$1', preg_replace( '/^5\.5\.5-/', '', $version ) ), '10.1', '>=' );
		} else {
			$ok = version_compare( preg_replace( '/^(\d+\.\d+\.\d+).*$/', '$1', $version ), '5.6', '>=' );
		}
		if ( ! $ok ) {
			$notes[] = 'Version ancienne : MySQL 5.6+ ou MariaDB 10.1+ recommandé.';
		}
		$test = $f['prefix'] . 'lwb_privilege_test';
		if ( ! $db->query( 'CREATE TABLE IF NOT EXISTS ' . Db::ident( $test ) . ' (id INT)' ) || ! $db->query( 'DROP TABLE ' . Db::ident( $test ) ) ) {
			throw new Migration_Exception( 'L’utilisateur MySQL ne peut pas créer de tables : ' . $db->last_error(), 'Attribuez tous les privilèges à cet utilisateur sur la base (panneau de l’hébergeur).' );
		}
		$existing = count( $db->list_tables( $f['prefix'] ) );
		if ( $existing > 0 ) {
			$notes[] = $existing . ' table(s) existante(s) avec le préfixe « ' . $f['prefix'] . ' » seront remplacées.';
		}
		$packet = (int) $db->get_var( 'SELECT @@max_allowed_packet' );
		if ( $packet > 0 && $packet < 1048576 * 2 ) {
			$notes[] = 'max_allowed_packet est faible (' . Runtime::format_bytes( $packet ) . ').';
		}
		$db->close();
		return array(
			'ok'      => true,
			'message' => 'Connexion réussie (' . $version . ').',
			'notes'   => $notes,
		);
	}

	/**
	 * Démarre l'installation.
	 *
	 * @return array
	 * @throws Migration_Exception Prérequis non remplis.
	 */
	private function api_start() {
		if ( 'done' === $this->phase() ) {
			throw new Migration_Exception( 'L’installation est déjà terminée.', 'Supprimez les fichiers d’installation.' );
		}
		foreach ( $this->checks() as $check ) {
			if ( 'error' === $check['level'] ) {
				throw new Migration_Exception( $check['label'] . ' : ' . $check['value'], $check['help'] );
			}
		}
		$f  = $this->read_form();
		$db = $this->connect( $f );
		$db->close();
		$src         = $this->config['source'];
		$this->state = array(
			'phase'    => 'extract',
			'form'     => array_diff_key( $f, array( 'password' => 1 ) ),
			'db'       => array(
				'host'     => $f['host'],
				'name'     => $f['name'],
				'user'     => $f['user'],
				'password' => $f['password'],
				'prefix'   => $f['prefix'],
			),
			'target'   => array(
				'site_url' => $f['site_url'],
				'home_url' => $f['home_url'],
				'abspath'  => $this->root,
			),
			'extract'  => array(
				'cursor'  => 0,
				'entry'   => null,
				'prog'    => array(),
				'done'    => 0,
				'skipped' => 0,
			),
			'import'   => array(),
			'replace'  => array(),
			'warnings' => 0,
			'errors'   => 0,
			'started'  => time(),
			'notes'    => array(),
		);
		$this->save_state();
		$log = $this->log();
		$log->info( sprintf( 'Installation démarrée – Lwb Migration %s, PHP %s.', $this->config['version'], PHP_VERSION ) );
		$log->info( sprintf( 'Source : %s (%s). Destination : %s (%s).', $src['site_url'], $src['abspath'], $f['site_url'], $this->root ) );
		$log->info( sprintf( 'Base : %s@%s/%s, préfixe %s (source : %s).', $f['user'], $f['host'], $f['name'], $f['prefix'], $src['table_prefix'] ) );
		return array_merge( array( 'ok' => true ), $this->progress_payload() );
	}

	// ------------------------------------------------------------------
	// Étapes.
	// ------------------------------------------------------------------

	/**
	 * Exécute une étape.
	 *
	 * @return array
	 */
	private function api_step() {
		if ( ! in_array( $this->phase(), self::PHASES, true ) ) {
			return array_merge( array( 'ok' => true ), $this->progress_payload() );
		}
		$lock = @fopen( $this->data_dir . '/lock', 'c' );
		if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return array(
				'ok'   => true,
				'busy' => true,
			);
		}
		Runtime::prepare();
		$rt = new Runtime();
		try {
			do {
				$phase = $this->phase();
				switch ( $phase ) {
					case 'extract':
						$this->step_extract( $rt );
						break;
					case 'import':
						$this->step_import( $rt );
						break;
					case 'replace':
						$this->step_replace( $rt );
						break;
					case 'finalize':
						$this->step_finalize();
						break;
				}
				$this->save_state();
				if ( $phase === $this->phase() ) {
					break;
				}
			} while ( ! $rt->should_stop() && in_array( $this->phase(), self::PHASES, true ) );
		} finally {
			if ( $lock ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
		return array_merge( array( 'ok' => true ), $this->progress_payload() );
	}

	/**
	 * Destination d'une entrée d'archive (null = ignorée).
	 *
	 * @param string $name Nom normalisé.
	 * @return string|null
	 */
	private function target_for( $name ) {
		$special = array(
			'lwb-package.json'    => $this->data_dir . '/package.json',
			'database.sql'        => $this->data_dir . '/database.sql',
			'files/wp-config.php' => $this->data_dir . '/wp-config-source.php',
			'files/.htaccess'     => $this->data_dir . '/htaccess-source.txt',
			'files/.user.ini'     => $this->data_dir . '/user-ini-source.txt',
			'files/php.ini'       => $this->data_dir . '/php-ini-source.txt',
		);
		if ( isset( $special[ $name ] ) ) {
			return $special[ $name ];
		}
		if ( 0 !== strpos( $name, 'files/' ) ) {
			return null;
		}
		$rel = rtrim( substr( $name, 6 ), '/' );
		if ( '' === $rel ) {
			return null;
		}
		$top      = explode( '/', $rel )[0];
		$reserved = array( $this->self, basename( $this->archive_path() ), basename( $this->data_dir ), self::DONE_MARKER );
		if ( in_array( $top, $reserved, true ) ) {
			return null;
		}
		return $this->root . '/' . $rel;
	}

	/**
	 * Extraction des fichiers.
	 *
	 * @param Runtime $rt Budget.
	 * @return void
	 */
	private function step_extract( Runtime $rt ) {
		$st               = &$this->state['extract'];
		$reader           = new Archive_Reader( $this->archive_path() );
		$st['index_size'] = $reader->index_size();
		$st['total']      = $reader->count();
		do {
			if ( empty( $st['entry'] ) ) {
				$cursor = (int) $st['cursor'];
				$entry  = $reader->next_entry( $cursor );
				if ( null === $entry ) {
					$this->log()->info( sprintf( 'Extraction terminée : %d éléments, %d ignorés.', $st['done'], $st['skipped'] ) );
					$this->state['phase'] = 'import';
					return;
				}
				$st['entry'] = $entry;
				$st['next']  = $cursor;
				$st['prog']  = array();
			}
			$entry = $st['entry'];
			$name  = Archive_Reader::safe_name( $entry['name'] );
			$dest  = null === $name ? null : $this->target_for( $name );
			if ( null !== $name && preg_match( '#^files/wp-content/(plugins|themes)/([^/]+)#', $name, $m ) ) {
				// Extensions et thèmes du site source (voir quarantine_leftovers()).
				$st['keep'][ $m[1] ][ $m[2] ] = 1;
			}
			if ( null === $dest ) {
				if ( null === $name ) {
					$this->log()->warning( 'Entrée dangereuse ignorée : ' . $entry['name'] );
					++$this->state['warnings'];
				}
				++$st['skipped'];
				$st['entry']  = null;
				$st['cursor'] = $st['next'];
				continue;
			}
			$prog       = $st['prog'];
			$done       = $reader->extract( $entry, $dest, $prog, $rt );
			$st['prog'] = $prog;
			if ( ! $done ) {
				return; // Gros fichier : suite à l'étape suivante.
			}
			if ( ! empty( $prog['crc_error'] ) ) {
				$this->log()->warning( 'Somme de contrôle incorrecte (fichier modifié pendant l’export ?) : ' . $entry['name'] );
				++$this->state['warnings'];
			}
			++$st['done'];
			$st['entry']  = null;
			$st['prog']   = array();
			$st['cursor'] = $st['next'];
		} while ( ! $rt->should_stop() );
	}

	/**
	 * Connexion avec les identifiants enregistrés.
	 *
	 * @return Mysqli_Db
	 */
	private function db() {
		$d = $this->state['db'];
		return new Mysqli_Db( $d['host'], $d['user'], $d['password'], $d['name'] );
	}

	/**
	 * Import SQL.
	 *
	 * @param Runtime $rt Budget.
	 * @return void
	 * @throws Migration_Exception Dump absent.
	 */
	private function step_import( Runtime $rt ) {
		$file = $this->data_dir . '/database.sql';
		if ( ! is_file( $file ) ) {
			throw new Migration_Exception( 'Le fichier database.sql est absent de l’archive.', 'Refaites l’export depuis le site source.' );
		}
		$db    = $this->db();
		$state = $this->state['import'];
		if ( empty( $state ) ) {
			$state = array(
				'old_prefix' => $this->config['source']['table_prefix'],
				'new_prefix' => $this->state['db']['prefix'],
			);
			if ( $state['old_prefix'] !== $state['new_prefix'] ) {
				$this->log()->info( sprintf( 'Changement de préfixe : %s → %s.', $state['old_prefix'], $state['new_prefix'] ) );
			}
		}
		$self                  = $this;
		$importer              = new Sql_Importer(
			$db,
			$file,
			$state,
			$this->log(),
			function ( $s ) use ( $self ) {
				$self->persist( 'import', $s );
			}
		);
		$done                  = $importer->run( $rt );
		$this->state['import'] = $importer->get_state();
		if ( $done ) {
			$s = $this->state['import'];
			$this->log()->info( sprintf( 'Import SQL terminé : %d requêtes, %d tables, %d erreur(s).', $s['queries'], count( $s['tables'] ), $s['errors'] ) );
			$this->state['errors'] += (int) $s['errors'];
			$this->fix_prefix( $db );
			$this->state['phase'] = 'replace';
		}
		$db->close();
	}

	/**
	 * Met à jour les clés dépendant du préfixe (rôles, capacités des utilisateurs).
	 *
	 * @param Mysqli_Db $db Connexion.
	 * @return void
	 */
	private function fix_prefix( Mysqli_Db $db ) {
		$old = (string) $this->state['import']['old_prefix'];
		$new = (string) $this->state['import']['new_prefix'];
		if ( '' === $old || $old === $new ) {
			return;
		}
		$like = Db::escape( Db::like_escape( $old ) );
		$len  = strlen( $old ) + 1;
		foreach ( $this->state['import']['tables'] as $table ) {
			if ( preg_match( '/options$/', $table ) ) {
				$db->query( 'UPDATE ' . Db::ident( $table ) . " SET option_name = CONCAT('" . Db::escape( $new ) . "', SUBSTRING(option_name, $len)) WHERE option_name LIKE '{$like}%user\_roles'" );
			}
			if ( $new . 'usermeta' === $table ) {
				$db->query( 'UPDATE ' . Db::ident( $table ) . " SET meta_key = CONCAT('" . Db::escape( $new ) . "', SUBSTRING(meta_key, $len)) WHERE meta_key LIKE '{$like}%'" );
			}
		}
		$this->log()->info( 'Clés options/usermeta mises à jour pour le nouveau préfixe.' );
	}

	/**
	 * Paires de remplacement.
	 *
	 * @return array<string,string>
	 */
	private function replace_pairs() {
		$src         = $this->config['source'];
		$tgt         = $this->state['target'];
		$urls        = array(
			$src['site_url'] => $tgt['site_url'],
			$src['home_url'] => $tgt['home_url'],
		);
		$content_url = isset( $src['content_url'] ) ? rtrim( $src['content_url'], '/' ) : '';
		if ( '' !== $content_url && 0 !== strpos( $content_url, rtrim( $src['site_url'], '/' ) . '/' ) && 0 !== strpos( $content_url, rtrim( $src['home_url'], '/' ) . '/' ) ) {
			$urls[ $content_url ] = $tgt['site_url'] . '/wp-content';
		}
		$paths = array( $src['abspath'] => $tgt['abspath'] );
		if ( ! empty( $src['content_outside'] ) ) {
			$paths[ $src['content_dir'] ] = $tgt['abspath'] . '/wp-content';
		}
		return Search_Replace::build_pairs( $urls, $paths );
	}

	/**
	 * Rechercher/remplacer dans toutes les tables importées.
	 *
	 * @param Runtime $rt Budget.
	 * @return void
	 */
	private function step_replace( Runtime $rt ) {
		$st = &$this->state['replace'];
		if ( empty( $st ) ) {
			$pairs = $this->replace_pairs();
			$st    = array(
				'tables'  => $this->state['import']['tables'],
				'index'   => 0,
				'table'   => array(),
				'changed' => 0,
				'pairs'   => count( $pairs ),
			);
			$this->log()->info( sprintf( 'Rechercher/remplacer : %d variantes (URL, JSON, URL-encodées, chemins).', count( $pairs ) ) );
		}
		$sr = new Search_Replace( $this->replace_pairs() );
		$db = $this->db();
		do {
			if ( ! isset( $st['tables'][ $st['index'] ] ) ) {
				$this->log()->info( sprintf( 'Rechercher/remplacer terminé : %d ligne(s) modifiée(s).', $st['changed'] ) );
				$this->state['phase'] = 'finalize';
				break;
			}
			$table       = $st['tables'][ $st['index'] ];
			$ts          = $st['table'];
			$self        = $this;
			$done        = $sr->run_table(
				$db,
				$table,
				$ts,
				$rt,
				function ( $s ) use ( $self, &$st ) {
					$st['table'] = $s;
					$self->persist( 'replace', $st );
				}
			);
			$st['table'] = $ts;
			if ( $done ) {
				if ( ! empty( $ts['changed'] ) ) {
					$this->log()->info( sprintf( 'Table %s : %d ligne(s) modifiée(s).', $table, $ts['changed'] ) );
					$st['changed'] += (int) $ts['changed'];
				}
				++$st['index'];
				$st['table'] = array();
			}
		} while ( ! $rt->should_stop() );
		$db->close();
	}

	/**
	 * Finalisation : wp-config, .htaccess, caches, nettoyage.
	 *
	 * @return void
	 */
	private function step_finalize() {
		$log = $this->log();
		$src = $this->config['source'];
		$tgt = $this->state['target'];
		$db  = $this->db();
		$p   = $this->state['db']['prefix'];

		// Options principales (au cas où elles seraient stockées sous une forme inattendue).
		$options = $p . 'options';
		if ( in_array( $options, $this->state['import']['tables'], true ) ) {
			$db->query( 'UPDATE ' . Db::ident( $options ) . ' SET option_value = ' . Db::literal( $tgt['site_url'] ) . " WHERE option_name = 'siteurl'" );
			$db->query( 'UPDATE ' . Db::ident( $options ) . ' SET option_value = ' . Db::literal( $tgt['home_url'] ) . " WHERE option_name = 'home'" );
			$db->query( 'DELETE FROM ' . Db::ident( $options ) . " WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'" );
			$log->info( 'Transients et règles de réécriture vidés.' );
		}
		$this->purge_builder_caches( $db );
		$this->fix_multisite( $db );
		$this->add_site_notes( $db );
		$db->close();

		$this->write_wp_config();
		$this->write_htaccess();
		$this->restore_ini_files();
		$this->disable_dropins();
		if ( ! empty( $this->state['form']['quarantine'] ) ) {
			$this->quarantine_leftovers();
		}
		// Dossier temporaire de WordPress (exclu de l'export) : indispensable aux mises à jour.
		if ( ! is_dir( $this->root . '/wp-content/upgrade' ) ) {
			@mkdir( $this->root . '/wp-content/upgrade', 0755, true );
		}
		$cache = $this->root . '/wp-content/cache';
		if ( is_dir( $cache ) ) {
			Json_Store::delete_dir( $cache );
			$log->info( 'Cache wp-content/cache vidé.' );
		}

		// Nettoyage immédiat des fichiers lourds ou sensibles.
		@unlink( $this->archive_path() );
		foreach ( array( 'database.sql', 'wp-config-source.php', 'htaccess-source.txt', 'user-ini-source.txt', 'php-ini-source.txt', 'package.json' ) as $tmp ) {
			@unlink( $this->data_dir . '/' . $tmp );
		}
		@file_put_contents( $this->root . '/' . self::DONE_MARKER, json_encode( array( $this->self, basename( $this->data_dir ), basename( $this->archive_path() ) ) ) );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		$this->state['phase']   = 'done';
		$this->state['done_at'] = time();
		$log->info( sprintf( 'Migration terminée en %d s. Avertissements : %d, erreurs SQL : %d.', time() - (int) $this->state['started'], $this->state['warnings'], $this->state['errors'] ) );
	}

	/**
	 * Vide les caches des constructeurs de pages (Elementor).
	 *
	 * Elementor garde dans la base un rendu des éléments (réglages encodés en base64,
	 * donc invisibles pour le rechercher/remplacer) et l'index de ses fichiers CSS,
	 * qui ne sont pas exportés : sans ce nettoyage, le site migré affiche des liens vers
	 * l'ancien domaine et des feuilles de style introuvables. Même effet que
	 * « Elementor › Outils › Régénérer les fichiers et données » : tout est recréé à la
	 * première visite.
	 *
	 * @param Db $db Connexion.
	 * @return void
	 */
	private function purge_builder_caches( Db $db ) {
		$meta_keys = array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_inline_svg' );
		$options   = array( '_elementor_global_css', '_elementor_assets_data', 'elementor-custom-breakpoints-files' );
		$purged    = 0;
		foreach ( $this->state['import']['tables'] as $table ) {
			if ( preg_match( '/postmeta$/', $table ) ) {
				$list = implode( ',', array_map( array( Db::class, 'literal' ), $meta_keys ) );
				if ( $db->query( 'DELETE FROM ' . Db::ident( $table ) . ' WHERE meta_key IN (' . $list . ')' ) ) {
					$purged += max( 0, $db->affected_rows() );
				}
			} elseif ( preg_match( '/options$/', $table ) ) {
				$list = implode( ',', array_map( array( Db::class, 'literal' ), $options ) );
				if ( $db->query( 'DELETE FROM ' . Db::ident( $table ) . ' WHERE option_name IN (' . $list . ')' ) ) {
					$purged += max( 0, $db->affected_rows() );
				}
			}
		}
		// Fichiers CSS générés (exclus de l'export) : ceux de l'ancien site de destination
		// porteraient les mêmes noms (post-123.css) avec un autre contenu.
		$files = glob( $this->root . '/wp-content/uploads/elementor/css/*.css' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( $purged > 0 || ! empty( $files ) ) {
			$this->log()->info( sprintf( 'Caches Elementor vidés (%d entrée(s), %d fichier(s) CSS) : ils seront régénérés à la première visite.', $purged, is_array( $files ) ? count( $files ) : 0 ) );
		}
		// Autres constructeurs dont les fichiers générés sont recréés automatiquement.
		$dirs = array(
			'wp-content/et-cache'                => 'Divi',
			'wp-content/uploads/bb-plugin/cache' => 'Beaver Builder',
			'wp-content/uploads/bb-theme'        => 'Beaver Themer',
		);
		foreach ( $dirs as $rel => $builder ) {
			if ( is_dir( $this->root . '/' . $rel ) ) {
				Json_Store::delete_dir( $this->root . '/' . $rel );
				$this->log()->info( sprintf( 'Cache %s vidé (%s).', $builder, $rel ) );
			}
		}
	}

	/**
	 * Valeur brute d'une option du site installé.
	 *
	 * @param Db     $db   Connexion.
	 * @param string $name Nom de l'option.
	 * @return string|null
	 */
	private function option_value( Db $db, $name ) {
		$table = $this->state['db']['prefix'] . 'options';
		if ( empty( $this->state['import']['tables'] ) || ! in_array( $table, $this->state['import']['tables'], true ) ) {
			return null;
		}
		$value = $db->get_var( 'SELECT option_value FROM ' . Db::ident( $table ) . ' WHERE option_name = ' . Db::literal( $name ) . ' LIMIT 1' );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Extensions actives du site installé.
	 *
	 * @param Db $db Connexion.
	 * @return string[]
	 */
	private function active_plugins( Db $db ) {
		$raw  = $this->option_value( $db, 'active_plugins' );
		$list = null === $raw ? false : @unserialize( $raw, array( 'allowed_classes' => false ) );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * Enregistre la liste des extensions actives.
	 *
	 * @param Db       $db   Connexion.
	 * @param string[] $list Extensions.
	 * @return void
	 */
	private function save_active_plugins( Db $db, array $list ) {
		$table = $this->state['db']['prefix'] . 'options';
		$db->query( 'UPDATE ' . Db::ident( $table ) . ' SET option_value = ' . Db::literal( serialize( array_values( $list ) ) ) . " WHERE option_name = 'active_plugins'" );
	}

	/**
	 * Points à vérifier après la migration, propres au site (affichés à la fin).
	 *
	 * @param Db $db Connexion.
	 * @return void
	 */
	private function add_site_notes( Db $db ) {
		$site = rtrim( $this->state['target']['home_url'], '/' );

		$this->state['login_url'] = rtrim( $this->state['target']['site_url'], '/' ) . '/wp-login.php';
		if ( '0' === $this->option_value( $db, 'blog_public' ) ) {
			$this->state['notes'][] = 'Le site est masqué aux moteurs de recherche (réglage repris du site source). S’il s’agit du site public, décochez « Demander aux moteurs de recherche de ne pas indexer ce site » dans Réglages › Lecture.';
		}
		$plugins = $this->active_plugins( $db );
		$has     = function ( $prefix ) use ( $plugins ) {
			foreach ( $plugins as $plugin ) {
				if ( 0 === strpos( $plugin, $prefix ) ) {
					return true;
				}
			}
			return false;
		};
		if ( $has( 'wps-hide-login/' ) ) {
			$slug = trim( (string) $this->option_value( $db, 'whl_page' ), '/' );
			$slug = '' !== $slug ? $slug : 'login';

			$this->state['login_url'] = $site . '/' . $slug . '/';
			$this->state['notes'][]   = 'Connexion masquée par WPS Hide Login : connectez-vous à l’adresse ' . $site . '/' . $slug . '/ (l’adresse /wp-admin/ affiche une page introuvable, c’est normal).';
		}
		if ( $has( 'wordfence/' ) ) {
			$this->state['notes'][] = 'Wordfence : ouvrez Wordfence › Pare-feu et relancez « Optimiser le pare-feu » (les chemins du serveur ont changé).';
		}
		if ( $has( 'novamira' ) ) {
			$this->state['notes'][] = 'Novamira : les capacités IA se désactivent quand le domaine change. Réactivez-les depuis sa page Configuration.';
		}
		$licensed = array(
			'elementor-pro/'              => 'Elementor Pro',
			'advanced-custom-fields-pro/' => 'ACF Pro',
			'wpforms/'                    => 'WPForms',
			'gravityforms/'               => 'Gravity Forms',
			'wp-rocket/'                  => 'WP Rocket',
			'js_composer/'                => 'WPBakery',
			'updraftplus/'                => 'UpdraftPlus',
			'woocommerce-subscriptions/'  => 'WooCommerce Subscriptions',
			'sitepress-multilingual-cms/' => 'WPML',
		);
		$names    = array();
		foreach ( $licensed as $prefix => $label ) {
			if ( $has( $prefix ) ) {
				$names[] = $label;
			}
		}
		if ( ! empty( $names ) ) {
			$this->state['notes'][] = 'Licences liées au domaine : vérifiez qu’elles sont toujours actives pour ' . implode( ', ', $names ) . '.';
		}
	}

	/**
	 * Met de côté les extensions et thèmes présents sur la destination mais absents du
	 * site source (restes de l'ancienne installation, jamais utilisés par le site migré).
	 *
	 * @return void
	 */
	private function quarantine_leftovers() {
		$keep = isset( $this->state['extract']['keep'] ) ? $this->state['extract']['keep'] : array();
		$base = $this->root . '/wp-content/lwb-mis-de-cote-' . date( 'Ymd-His' );
		$out  = array();
		foreach ( array( 'plugins', 'themes' ) as $type ) {
			if ( empty( $keep[ $type ] ) ) {
				continue; // Archive sans extensions (ou sans thèmes) : on ne touche à rien.
			}
			$dir   = $this->root . '/wp-content/' . $type;
			$items = is_dir( $dir ) ? @scandir( $dir ) : false;
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( '.' === $item[0] || 'index.php' === $item || isset( $keep[ $type ][ $item ] ) || ( 'plugins' === $type && 'lwb-migration' === $item ) ) {
					continue;
				}
				Json_Store::ensure_protected_dir( $base );
				Json_Store::ensure_protected_dir( $base . '/' . $type );
				if ( @rename( $dir . '/' . $item, $base . '/' . $type . '/' . $item ) ) {
					$out[ $type ][] = $item;
				}
			}
		}
		if ( empty( $out ) ) {
			return;
		}
		$list = array_merge( isset( $out['plugins'] ) ? $out['plugins'] : array(), isset( $out['themes'] ) ? $out['themes'] : array() );
		$this->log()->info( 'Mis de côté (absents du site source) : ' . implode( ', ', $list ) . '.' );
		$this->state['notes'][] = sprintf(
			'%d extension(s) et %d thème(s) absents du site source (restes de l’ancienne installation) ont été mis de côté dans wp-content/%s/ : %s. Supprimez ce dossier après vérification.',
			isset( $out['plugins'] ) ? count( $out['plugins'] ) : 0,
			isset( $out['themes'] ) ? count( $out['themes'] ) : 0,
			basename( $base ),
			implode( ', ', array_slice( $list, 0, 12 ) ) . ( count( $list ) > 12 ? '…' : '' )
		);
	}

	/**
	 * Multisite : domaines et chemins des sites du réseau.
	 *
	 * @param Mysqli_Db $db Connexion.
	 * @return void
	 */
	private function fix_multisite( Mysqli_Db $db ) {
		if ( empty( $this->config['source']['multisite'] ) ) {
			return;
		}
		$p       = $this->state['db']['prefix'];
		$old     = parse_url( $this->config['source']['site_url'] );
		$new     = parse_url( $this->state['target']['site_url'] );
		$old_dom = isset( $old['host'] ) ? $old['host'] : '';
		$new_dom = isset( $new['host'] ) ? $new['host'] : '';
		$old_pth = rtrim( isset( $old['path'] ) ? $old['path'] : '', '/' ) . '/';
		$new_pth = rtrim( isset( $new['path'] ) ? $new['path'] : '', '/' ) . '/';
		foreach ( array( $p . 'blogs', $p . 'site' ) as $table ) {
			if ( ! in_array( $table, $this->state['import']['tables'], true ) ) {
				continue;
			}
			$db->query( 'UPDATE ' . Db::ident( $table ) . ' SET domain = ' . Db::literal( $new_dom ) . ' WHERE domain = ' . Db::literal( $old_dom ) );
			if ( $old_pth !== $new_pth ) {
				$db->query( 'UPDATE ' . Db::ident( $table ) . ' SET path = CONCAT(' . Db::literal( $new_pth ) . ', SUBSTRING(path, ' . ( strlen( $old_pth ) + 1 ) . ")) WHERE path LIKE '" . Db::escape( Db::like_escape( $old_pth ) ) . "%'" );
			}
		}
		$this->state['notes'][] = 'Multisite : domaines et chemins du réseau mis à jour. Vérifiez les éventuels domaines personnalisés.';
		$this->log()->info( 'Multisite : tables blogs/site mises à jour.' );
	}

	/**
	 * Remplace (ou ajoute) un define() dans un wp-config.
	 *
	 * @param string $text     Code.
	 * @param string $constant Constante.
	 * @param mixed  $value    Valeur (null = mise en commentaire).
	 * @param bool   $add      Ajouter si absente.
	 * @return string
	 */
	private static function set_define( $text, $constant, $value, $add = true ) {
		$re    = '/^[ \t]*define\s*\(\s*([\'"])' . preg_quote( $constant, '/' ) . '\1\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;]*?)\s*\)\s*;[^\n]*$/m';
		$new   = null === $value
			? '// define( \'' . $constant . '\', … ); // Désactivé par Lwb Migration.'
			: 'define( \'' . $constant . '\', ' . var_export( $value, true ) . ' );'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- génération de code.
		$count = 0;
		$text  = preg_replace_callback(
			$re,
			function () use ( $new ) {
				return $new;
			},
			$text,
			-1,
			$count
		);
		if ( 0 === $count && $add && null !== $value ) {
			$text = self::insert_before_end( $text, $new );
		}
		return $text;
	}

	/**
	 * Insère une ligne avant « That's all, stop editing » ou le chargement de wp-settings.php.
	 *
	 * @param string $text Code.
	 * @param string $line Ligne.
	 * @return string
	 */
	private static function insert_before_end( $text, $line ) {
		foreach ( array( "/* That's all", '/** Absolute path', "if ( ! defined( 'ABSPATH' ) )", "if ( !defined('ABSPATH') )", 'require_once ABSPATH', 'require_once(ABSPATH' ) as $marker ) {
			$pos = strpos( $text, $marker );
			if ( false !== $pos ) {
				return substr( $text, 0, $pos ) . $line . "\n\n" . substr( $text, $pos );
			}
		}
		return rtrim( $text ) . "\n" . $line . "\n";
	}

	/**
	 * Clé aléatoire pour les SALT.
	 *
	 * @return string
	 */
	private static function salt() {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|';
		$out   = '';
		$max   = strlen( $chars ) - 1;
		for ( $i = 0; $i < 64; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * Écrit wp-config.php.
	 *
	 * @return void
	 * @throws Migration_Exception Écriture impossible.
	 */
	private function write_wp_config() {
		$src_file = $this->data_dir . '/wp-config-source.php';
		$text     = is_file( $src_file ) ? (string) file_get_contents( $src_file ) : '';
		if ( '' === trim( $text ) ) {
			$text = "<?php\n/** Configuration générée par Lwb Migration. */\n\n\$table_prefix = 'wp_';\n\n/* That's all, stop editing! Happy publishing. */\n\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n\nrequire_once ABSPATH . 'wp-settings.php';\n";
			$this->log()->warning( 'wp-config.php absent de l’archive : une configuration minimale a été générée.' );
		}
		$src = $this->config['source'];
		$tgt = $this->state['target'];
		$db  = $this->state['db'];

		// Chemins et URL codés en dur (WP_TEMP_DIR, WPCACHEHOME, inclusions…).
		$text = strtr(
			$text,
			array(
				$src['abspath']  => $tgt['abspath'],
				$src['site_url'] => $tgt['site_url'],
				$src['home_url'] => $tgt['home_url'],
			)
		);
		$text = self::set_define( $text, 'DB_NAME', $db['name'] );
		$text = self::set_define( $text, 'DB_USER', $db['user'] );
		$text = self::set_define( $text, 'DB_PASSWORD', $db['password'] );
		$text = self::set_define( $text, 'DB_HOST', $db['host'] );
		foreach ( self::SALT_KEYS as $key ) {
			$text = self::set_define( $text, $key, self::salt() );
		}
		if ( preg_match( '/\$table_prefix\s*=/', $text ) ) {
			$text = preg_replace( '/\$table_prefix\s*=\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;]*)\s*;/', '$table_prefix = ' . str_replace( '$', '\\$', var_export( $db['prefix'], true ) ) . ';', $text, 1 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- génération de code.
		} else {
			$text = self::insert_before_end( $text, '$table_prefix = ' . var_export( $db['prefix'], true ) . ';' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- génération de code.
		}
		foreach ( array(
			'WP_HOME'    => $tgt['home_url'],
			'WP_SITEURL' => $tgt['site_url'],
		) as $const => $value ) {
			if ( null !== self::read_define( $text, $const ) || preg_match( '/define\s*\(\s*[\'"]' . $const . '[\'"]/', $text ) ) {
				$text = self::set_define( $text, $const, $value, false );
			}
		}
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $text ) ) {
			$text = self::set_define( $text, 'WP_CACHE', false, false );
		}
		$text = self::set_define( $text, 'COOKIE_DOMAIN', null, false );
		if ( ! empty( $src['content_outside'] ) ) {
			foreach ( self::CONTENT_DEFS as $const ) {
				$text = self::set_define( $text, $const, null, false );
			}
		}
		if ( ! empty( $src['multisite'] ) ) {
			$host = parse_url( $tgt['site_url'], PHP_URL_HOST );
			$path = rtrim( (string) parse_url( $tgt['site_url'], PHP_URL_PATH ), '/' ) . '/';
			$text = self::set_define( $text, 'DOMAIN_CURRENT_SITE', $host, false );
			$text = self::set_define( $text, 'PATH_CURRENT_SITE', $path, false );
		}
		$file = $this->root . '/wp-config.php';
		if ( false === @file_put_contents( $file, $text ) ) {
			throw new Migration_Exception( 'Impossible d’écrire wp-config.php.', 'Rendez le dossier du site accessible en écriture (755) puis relancez.', true );
		}
		@chmod( $file, 0640 );
		// Un ancien wp-config.php au-dessus de la racine serait chargé en priorité par WordPress.
		$parent = dirname( $this->root ) . '/wp-config.php';
		if ( is_file( $parent ) && ! is_file( dirname( $this->root ) . '/wp-settings.php' ) ) {
			$this->state['notes'][] = 'Un fichier wp-config.php existe dans le dossier parent : il est ignoré, car celui de la racine est prioritaire.';
		}
		$this->log()->info( 'wp-config.php mis à jour (base de données, préfixe, nouvelles clés SALT).' );
	}

	/**
	 * Réinitialise .htaccess (règles WordPress standard).
	 *
	 * @return void
	 */
	private function write_htaccess() {
		$path = (string) parse_url( $this->state['target']['home_url'], PHP_URL_PATH );
		$base = rtrim( $path, '/' ) . '/';
		$htac = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\nRewriteBase {$base}\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . {$base}index.php [L]\n</IfModule>\n# END WordPress\n";
		$orig = $this->data_dir . '/htaccess-source.txt';
		if ( is_file( $orig ) ) {
			@copy( $orig, $this->root . '/.htaccess.lwb-original' );
			$this->state['notes'][] = 'Le .htaccess d’origine a été remplacé par la version WordPress standard. L’original est conservé dans .htaccess.lwb-original (règles de sécurité, redirections…).';
		}
		if ( false === @file_put_contents( $this->root . '/.htaccess', $htac ) ) {
			$this->log()->warning( 'Impossible d’écrire .htaccess.' );
			++$this->state['warnings'];
			return;
		}
		$this->log()->info( '.htaccess réinitialisé (RewriteBase ' . $base . ').' );
	}

	/**
	 * Restaure .user.ini / php.ini avec les nouveaux chemins (pare-feu Wordfence, etc.).
	 *
	 * @return void
	 */
	private function restore_ini_files() {
		$map = array(
			'user-ini-source.txt' => '.user.ini',
			'php-ini-source.txt'  => 'php.ini',
		);
		$src = $this->config['source']['abspath'];
		foreach ( $map as $saved => $target ) {
			$file = $this->data_dir . '/' . $saved;
			if ( ! is_file( $file ) ) {
				continue;
			}
			$text = str_replace( $src, $this->root, (string) file_get_contents( $file ) );
			@file_put_contents( $this->root . '/' . $target, $text );
			$this->log()->info( $target . ' restauré avec les nouveaux chemins.' );
		}
		$waf = $this->root . '/wordfence-waf.php';
		if ( is_file( $waf ) ) {
			@file_put_contents( $waf, str_replace( $src, $this->root, (string) file_get_contents( $waf ) ) );
		}
	}

	/**
	 * Désactive les drop-ins de cache liés à l'ancien serveur.
	 *
	 * @return void
	 */
	private function disable_dropins() {
		foreach ( self::DROPINS as $dropin ) {
			$file = $this->root . '/wp-content/' . $dropin;
			if ( is_file( $file ) && @rename( $file, $file . '.lwb-disabled' ) ) {
				$this->state['notes'][] = 'wp-content/' . $dropin . ' a été désactivé (renommé en .lwb-disabled) : réactivez votre extension de cache après vérification du site.';
				$this->log()->info( 'Drop-in désactivé : ' . $dropin );
			}
		}
	}

	/**
	 * Suppression des fichiers d'installation.
	 *
	 * @return array
	 * @throws Migration_Exception Installation non terminée.
	 */
	private function api_cleanup() {
		if ( 'done' !== $this->phase() ) {
			throw new Migration_Exception( 'L’installation n’est pas terminée.', 'Terminez l’installation avant de supprimer les fichiers.' );
		}
		$admin = rtrim( $this->state['target']['site_url'], '/' ) . '/wp-admin/';
		$this->cleanup();
		return array(
			'ok'       => true,
			'redirect' => $admin,
		);
	}

	/**
	 * Extensions actives du site installé (contrôle après migration).
	 *
	 * @return array
	 * @throws Migration_Exception Installation non terminée.
	 */
	private function api_plugins() {
		if ( 'done' !== $this->phase() ) {
			throw new Migration_Exception( 'L’installation n’est pas terminée.', '' );
		}
		$db   = $this->db();
		$list = $this->active_plugins( $db );
		$db->close();
		return array(
			'ok'       => true,
			'plugins'  => array_values( array_diff( $list, array( 'lwb-migration/lwb-migration.php' ) ) ),
			'disabled' => isset( $this->state['disabled_plugins'] ) ? $this->state['disabled_plugins'] : array(),
		);
	}

	/**
	 * Désactive ou réactive une extension (isolement d'une extension qui fait planter le site).
	 *
	 * @return array
	 * @throws Migration_Exception Installation non terminée ou extension inconnue.
	 */
	private function api_plugin_toggle() {
		if ( 'done' !== $this->phase() ) {
			throw new Migration_Exception( 'L’installation n’est pas terminée.', '' );
		}
		$plugin = isset( $_POST['plugin'] ) ? (string) $_POST['plugin'] : '';
		$active = ! empty( $_POST['active'] );
		if ( ! preg_match( '#^[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)?\.php$#', $plugin ) ) {
			throw new Migration_Exception( 'Extension invalide.', '' );
		}
		$db       = $this->db();
		$list     = $this->active_plugins( $db );
		$disabled = isset( $this->state['disabled_plugins'] ) ? $this->state['disabled_plugins'] : array();
		if ( $active ) {
			if ( ! in_array( $plugin, $disabled, true ) ) {
				$db->close();
				throw new Migration_Exception( 'Seules les extensions désactivées par l’installeur peuvent être réactivées ici.', '' );
			}
			$list[]   = $plugin;
			$disabled = array_values( array_diff( $disabled, array( $plugin ) ) );
		} else {
			if ( ! in_array( $plugin, $list, true ) ) {
				$db->close();
				throw new Migration_Exception( 'Cette extension n’est pas active.', '' );
			}
			$list       = array_diff( $list, array( $plugin ) );
			$disabled[] = $plugin;
		}
		$this->save_active_plugins( $db, array_unique( $list ) );
		$db->close();
		$this->state['disabled_plugins'] = array_values( array_unique( $disabled ) );
		$this->save_state();
		$this->log()->info( ( $active ? 'Extension réactivée : ' : 'Extension désactivée (contrôle du site) : ' ) . $plugin );
		return array(
			'ok'       => true,
			'disabled' => $this->state['disabled_plugins'],
		);
	}

	/**
	 * Supprime installeur, archive, dossier de travail et marqueur.
	 *
	 * @return void
	 */
	private function cleanup() {
		@unlink( $this->archive_path() );
		Json_Store::delete_dir( $this->data_dir );
		@unlink( $this->root . '/' . self::DONE_MARKER );
		@unlink( $this->root . '/' . $this->self );
	}

	// ------------------------------------------------------------------
	// Progression.
	// ------------------------------------------------------------------

	/**
	 * Données de progression.
	 *
	 * @return array
	 */
	private function progress_payload() {
		$phase  = $this->phase();
		$s      = $this->state;
		$pct    = 0;
		$detail = '';
		switch ( $phase ) {
			case 'extract':
				$e      = $s['extract'];
				$ratio  = isset( $e['index_size'] ) && $e['index_size'] > 0 ? $e['cursor'] / $e['index_size'] : 0;
				$pct    = 40 * $ratio;
				$detail = number_format( $e['done'], 0, ',', ' ' ) . ' / ' . number_format( isset( $e['total'] ) ? $e['total'] : 0, 0, ',', ' ' ) . ' éléments extraits';
				if ( ! empty( $e['entry'] ) && ! empty( $e['prog']['done'] ) && $e['entry']['size'] > 0 ) {
					$detail .= ' – ' . basename( $e['entry']['name'] ) . ' (' . round( 100 * $e['prog']['done'] / $e['entry']['size'] ) . ' %)';
				}
				break;
			case 'import':
				$i      = $s['import'];
				$ratio  = ! empty( $i['size'] ) ? $i['offset'] / $i['size'] : 0;
				$pct    = 40 + 40 * $ratio;
				$detail = ( isset( $i['queries'] ) ? number_format( $i['queries'], 0, ',', ' ' ) : 0 ) . ' requêtes exécutées, ' . ( isset( $i['tables'] ) ? count( $i['tables'] ) : 0 ) . ' tables';
				break;
			case 'replace':
				$r      = $s['replace'];
				$total  = isset( $r['tables'] ) ? max( 1, count( $r['tables'] ) ) : 1;
				$index  = isset( $r['index'] ) ? $r['index'] : 0;
				$pct    = 80 + 18 * ( $index / $total );
				$detail = isset( $r['tables'][ $index ] ) ? 'Table ' . $r['tables'][ $index ] . ' (' . ( $index + 1 ) . '/' . $total . ')' : '';
				break;
			case 'finalize':
				$pct = 98;
				break;
			case 'done':
				$pct = 100;
				break;
		}
		$out = array(
			'phase'    => $phase,
			'progress' => round( $pct, 1 ),
			'detail'   => $detail,
		);
		if ( 'setup' !== $phase && is_dir( $this->data_dir ) ) {
			$out['log'] = $this->log()->tail( 6 );
		}
		if ( 'done' === $phase ) {
			$old = array();
			foreach ( array( $this->config['source']['site_url'], $this->config['source']['home_url'] ) as $url ) {
				$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
				if ( '' !== $host ) {
					$old[] = $host;
					$old[] = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
				}
			}
			$new_host      = strtolower( (string) parse_url( $s['target']['home_url'], PHP_URL_HOST ) );
			$out['result'] = array(
				'site_url'  => $s['target']['home_url'],
				'admin'     => rtrim( $s['target']['site_url'], '/' ) . '/wp-admin/',
				'login'     => isset( $s['login_url'] ) ? $s['login_url'] : rtrim( $s['target']['site_url'], '/' ) . '/wp-login.php',
				'old_hosts' => array_values( array_diff( array_unique( $old ), array( $new_host ) ) ),
				'warnings'  => (int) $s['warnings'],
				'errors'    => (int) $s['errors'],
				'notes'     => isset( $s['notes'] ) ? $s['notes'] : array(),
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Pages HTML.
	// ------------------------------------------------------------------

	/**
	 * Logo de l'extension (SVG en ligne).
	 *
	 * @return string
	 */
	private static function brand_logo() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" role="img" aria-label="Lwb Migration"><defs><linearGradient id="lwi-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3B30EA"/><stop offset="1" stop-color="#1B119C"/></linearGradient><linearGradient id="lwi-sh" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".18"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/></linearGradient></defs><rect width="256" height="256" rx="58" fill="url(#lwi-bg)"/><rect width="256" height="256" rx="58" fill="url(#lwi-sh)"/><rect x="40" y="46" width="112" height="84" rx="16" fill="#fff" fill-opacity=".22" stroke="#fff" stroke-opacity=".9" stroke-width="8"/><circle cx="60" cy="66" r="5" fill="#fff"/><circle cx="76" cy="66" r="5" fill="#fff"/><rect x="104" y="126" width="112" height="84" rx="16" fill="#fff"/><circle cx="124" cy="146" r="5" fill="#2A1FD0"/><circle cx="140" cy="146" r="5" fill="#2A1FD0"/><rect x="122" y="166" width="58" height="9" rx="4.5" fill="#E7EBFB"/><rect x="122" y="183" width="40" height="9" rx="4.5" fill="#E7EBFB"/><path d="M70 142 V160 Q70 182 92 182 H96" fill="none" stroke="#FF6B3D" stroke-width="13" stroke-linecap="round" stroke-linejoin="round"/><path d="M90 164 L110 182 L90 200" fill="none" stroke="#FF6B3D" stroke-width="13" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	/**
	 * Police Plus Jakarta Sans embarquée (injectée par le constructeur, licence OFL).
	 *
	 * @return string Règle @font-face ou chaîne vide.
	 */
	private static function font_face() {
		$data = /*LWB_FONT_START*/ ''; /*LWB_FONT_END*/
		if ( '' === $data ) {
			return '';
		}
		return '@font-face { font-family: "Plus Jakarta Sans"; font-style: normal; font-weight: 400 800; font-display: swap; src: url(data:font/woff2;base64,' . $data . ') format("woff2"); }' . "\n";
	}

	/**
	 * Icônes SVG en ligne (aucune ressource externe).
	 *
	 * @param string $name Nom.
	 * @return string
	 */
	private static function icon( $name ) {
		$paths = array(
			'logo'   => '<path d="M7 7h11l-3-3M17 17H6l3 3"/>',
			'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'check'  => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'alert'  => '<path d="M12 8v5M12 16.5v.5"/><circle cx="12" cy="12" r="9"/>',
			'server' => '<rect x="4" y="4" width="16" height="7" rx="2"/><rect x="4" y="13" width="16" height="7" rx="2"/><path d="M8 7.5h.01M8 16.5h.01"/>',
			'db'     => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
			'rocket' => '<path d="M5 15c-1 1-1.5 4-1.5 4s3-.5 4-1.5M9 15l-3-3c1-4 4.5-8 10.5-8.5C16 9.5 12 13 9 15zM14.5 9.5h.01"/>',
			'file'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
		);
		return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( isset( $paths[ $name ] ) ? $paths[ $name ] : '' ) . '</svg>';
	}

	/**
	 * Page d'erreur bloquante.
	 *
	 * @param string $title Titre.
	 * @param string $text  Texte.
	 * @param string $kind  error|ok.
	 * @return void
	 */
	private function fatal_page( $title, $text, $kind = 'error' ) {
		$this->page_header( 'Lwb Migration' );
		echo '<main class="solo"><div class="solo-card"><span class="solo-icon ' . self::h( $kind ) . '">' . self::icon( 'ok' === $kind ? 'check' : 'alert' ) . '</span><h1>' . self::h( $title ) . '</h1><p class="muted">' . self::h( $text ) . '</p></div></main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icônes statiques.
		$this->page_footer();
		exit;
	}

	/**
	 * Formulaire de connexion.
	 *
	 * @return void
	 */
	private function render_login() {
		$this->page_header( 'Installation protégée' );
		if ( 'direct' === $this->config['mode'] ) {
			echo '<main class="solo"><div class="solo-card"><span class="solo-icon error">' . self::icon( 'lock' ) . '</span><h1>Accès réservé</h1><p class="muted">Ouvrez l’installeur depuis le lien affiché sur le site source à la fin de la migration directe.</p></div></main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icône statique.
			$this->page_footer();
			return;
		}
		?>
		<main class="solo">
			<div class="solo-card">
				<span class="solo-icon brand"><?php echo self::brand_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h1>Installation protégée</h1>
				<p class="muted">Saisissez le mot de passe défini lors de l’export du site source.</p>
				<form id="login" autocomplete="off">
					<label for="pw">Mot de passe</label>
					<input type="password" id="pw" name="password" required autofocus placeholder="••••••••••••">
					<div class="msg error" id="login-error" hidden></div>
					<button type="submit" class="btn primary block">Déverrouiller l’installation</button>
				</form>
				<p class="brand-foot"><?php echo self::icon( 'logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Lwb Migration</p>
			</div>
		</main>
		<script>
		document.getElementById( 'login' ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var body = new FormData();
			body.append( 'lwb_action', 'login' );
			body.append( 'password', document.getElementById( 'pw' ).value );
			fetch( <?php echo json_encode( $this->self ); ?>, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					if ( d.ok ) { window.location.reload(); return; }
					var box = document.getElementById( 'login-error' );
					box.textContent = d.message + ( d.solution ? ' ' + d.solution : '' );
					box.hidden = false;
				} );
		} );
		</script>
		<?php
		$this->page_footer();
	}

	/**
	 * Application principale.
	 *
	 * @return void
	 */
	private function render_app() {
		$src   = $this->config['source'];
		$boot  = array(
			'csrf'   => $this->csrf_token(),
			'self'   => $this->self,
			'mode'   => $this->config['mode'],
			'phases' => self::PHASES,
		);
		$steps = array(
			'check'    => array( 'Vérification', 'Serveur et archive' ),
			'db'       => array( 'Base de données', 'Accès MySQL' ),
			'extract'  => array( 'Extraction', 'Fichiers du site' ),
			'import'   => array( 'Import SQL', 'Tables et contenus' ),
			'replace'  => array( 'Rechercher / remplacer', 'URL et chemins' ),
			'finalize' => array( 'Finalisation', 'wp-config, .htaccess' ),
		);
		$this->page_header( 'Installation – ' . $src['site_name'] );
		?>
		<div class="layout">
			<aside class="sidebar" aria-label="Étapes">
				<div class="brand"><span class="logo"><?php echo self::brand_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span>Lwb Migration<small>Assistant d’installation</small></span></div>
				<ol id="steps" class="stepper">
					<?php foreach ( $steps as $key => $step ) : ?>
						<li data-step="<?php echo self::h( $key ); ?>"><span class="step-dot"></span><span class="step-text"><strong><?php echo self::h( $step[0] ); ?></strong><small><?php echo self::h( $step[1] ); ?></small></span></li>
					<?php endforeach; ?>
				</ol>
				<div class="source">
					<span class="source-label">Site source</span>
					<strong><?php echo self::h( $src['site_name'] ); ?></strong>
					<span class="source-url"><?php echo self::h( $src['site_url'] ); ?></span>
					<span class="source-tags"><span>WP <?php echo self::h( $src['wp_version'] ); ?></span><span>PHP <?php echo self::h( $src['php_version'] ); ?></span><span><?php echo self::h( date( 'd/m/Y', (int) $this->config['created'] ) ); ?></span></span>
				</div>
			</aside>

			<main class="content">
				<div class="msg error" id="error" role="alert" hidden></div>

				<section id="panel-check" class="panel" hidden>
					<header class="panel-head">
						<span class="panel-icon"><?php echo self::icon( 'server' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div><span class="eyebrow">Étape 1 sur 6</span><h1>Vérification de l’environnement</h1><p class="muted">Contrôle du serveur de destination et de l’archive.</p></div>
					</header>
					<table class="checks"><tbody id="checks"></tbody></table>
					<div class="actions end"><button type="button" class="btn primary" id="to-db" disabled>Continuer →</button></div>
				</section>

				<section id="panel-db" class="panel" hidden>
					<header class="panel-head">
						<span class="panel-icon"><?php echo self::icon( 'db' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div><span class="eyebrow">Étape 2 sur 6</span><h1>Base de données</h1><p class="muted" id="db-hint">Saisissez les accès fournis par votre hébergeur. La base doit exister (ou cochez « créer »).</p></div>
					</header>
					<form id="db-form" autocomplete="off">
						<div class="grid">
							<div><label for="host">Hôte</label><input id="host" name="host" required placeholder="localhost"></div>
							<div><label for="name">Nom de la base</label><input id="name" name="name" required></div>
							<div><label for="user">Utilisateur</label><input id="user" name="user" required autocomplete="off"></div>
							<div><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password"></div>
							<div><label for="prefix">Préfixe des tables</label><input id="prefix" name="prefix" required pattern="[A-Za-z0-9_]+"></div>
							<div class="check"><label class="toggle"><input type="checkbox" name="create" value="1"><span class="toggle-ui" aria-hidden="true"></span> Créer la base si elle n’existe pas</label></div>
						</div>
						<details class="advanced">
							<summary>Adresse et dossier du nouveau site <span class="muted">— détectés automatiquement</span></summary>
							<div class="grid">
								<div class="full"><label for="site_url">Adresse du site (WordPress)</label><input id="site_url" name="site_url" required></div>
								<div class="full"><label for="home_url">Adresse de la page d’accueil</label><input id="home_url" name="home_url" required></div>
								<div class="full"><label for="path">Dossier sur le serveur</label><input id="path" readonly></div>
								<div class="full check"><label class="toggle"><input type="checkbox" name="quarantine" value="1" checked><span class="toggle-ui" aria-hidden="true"></span> Mettre de côté les extensions et thèmes du serveur absents du site source</label></div>
							</div>
						</details>
						<div class="msg" id="db-result" hidden></div>
						<div class="actions between">
							<button type="button" class="btn" id="test-db">Tester la connexion</button>
							<button type="submit" class="btn primary" id="start"><?php echo self::icon( 'rocket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Lancer l’installation</button>
						</div>
						<p class="muted small">Les tables portant le même nom seront remplacées, ainsi que les fichiers existants du même nom.</p>
					</form>
				</section>

				<section id="panel-run" class="panel" hidden>
					<header class="panel-head">
						<span class="panel-icon spin"><?php echo self::icon( 'file' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div><span class="eyebrow">Installation</span><h1 id="run-title">Installation en cours…</h1><p class="muted">Ne fermez pas cette page. En cas de coupure, rechargez-la : l’installation reprendra là où elle s’était arrêtée.</p></div>
					</header>
					<div class="progress-card">
						<div class="progress-top"><strong id="pct">0 %</strong><span id="detail" class="muted"></span></div>
						<div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><div id="bar-fill"></div></div>
						<p class="muted small" id="retry"></p>
					</div>
					<pre id="log" class="log"></pre>
				</section>

				<section id="panel-done" class="panel done" hidden>
					<div class="success-icon"><?php echo self::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<h1>Migration réussie !</h1>
					<p class="lead">Votre site est en ligne à l’adresse <a id="site-link" target="_blank" rel="noopener"></a></p>
					<p class="muted">Connectez-vous avec les identifiants administrateur du site source.</p>
					<div class="verify" id="verify" hidden><h2>Contrôle du site</h2><ul id="verify-list" class="verify-list"></ul></div>
					<ul id="notes" class="notes"></ul>
					<div class="actions center">
						<button type="button" class="btn primary" id="cleanup">Supprimer les fichiers d’installation et ouvrir l’administration</button>
						<a class="btn" href="?lwb_log=1">Télécharger le journal</a>
					</div>
					<p class="muted small">Par sécurité, l’installeur et son dossier de travail sont supprimés automatiquement une heure après la fin de l’installation.</p>
				</section>
			</main>
		</div>
		<script>window.LWB = <?php echo json_encode( $boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); ?>;</script>
		<script><?php echo self::script(); // phpcs:ignore ?></script>
		<?php
		$this->page_footer();
	}

	/**
	 * Script de l'application.
	 *
	 * @return string
	 */
	private static function script() {
		return <<<'JS'
(function () {
	'use strict';
	var B = window.LWB, MAX_RETRIES = 8, retries = 0;
	function $(id) { return document.getElementById(id); }
	function show(panel) {
		['check', 'db', 'run', 'done'].forEach(function (p) { $('panel-' + p).hidden = p !== panel; });
	}
	function setStep(step) {
		var order = ['check', 'db', 'extract', 'import', 'replace', 'finalize'], idx = 'done' === step ? order.length : order.indexOf(step);
		Array.prototype.forEach.call(document.querySelectorAll('#steps li'), function (li) {
			var i = order.indexOf(li.getAttribute('data-step'));
			li.className = i < idx ? 'done' : (i === idx ? 'current' : '');
		});
	}
	function error(msg, solution) {
		var box = $('error');
		if (!msg) { box.hidden = true; return; }
		box.textContent = '';
		var s = document.createElement('strong'); s.textContent = msg; box.appendChild(s);
		if (solution) { var p = document.createElement('p'); p.textContent = 'Solution : ' + solution; box.appendChild(p); }
		box.hidden = false;
		box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}
	function api(action, data) {
		var body = new FormData();
		body.append('lwb_action', action);
		body.append('csrf', B.csrf);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(B.self, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
			return r.text().then(function (t) {
				var j = null;
				try { j = JSON.parse(t); } catch (e) {}
				if (!j) { var err = new Error('Réponse invalide du serveur (HTTP ' + r.status + ').'); err.retryable = true; throw err; }
				return j;
			});
		}, function () { var err = new Error('Connexion au serveur perdue.'); err.retryable = true; throw err; });
	}
	function formData() {
		var f = $('db-form'), out = {};
		['host', 'name', 'user', 'password', 'prefix', 'site_url', 'home_url'].forEach(function (k) { out[k] = f.elements[k].value; });
		out.create = f.elements.create.checked ? '1' : '';
		out.quarantine = f.elements.quarantine.checked ? '1' : '';
		return out;
	}
	function renderChecks(checks) {
		var body = $('checks'), blocking = false;
		body.textContent = '';
		checks.forEach(function (c) {
			var tr = document.createElement('tr'), td1 = document.createElement('td'), td2 = document.createElement('td');
			tr.className = c.level;
			td1.textContent = c.label;
			var v = document.createElement('span'); v.className = 'pill ' + c.level; v.textContent = c.value; td2.appendChild(v);
			if (c.level !== 'ok' && c.help) { var h = document.createElement('div'); h.className = 'help'; h.textContent = c.help; td2.appendChild(h); }
			tr.appendChild(td1); tr.appendChild(td2); body.appendChild(tr);
			if (c.level === 'error') { blocking = true; }
		});
		$('to-db').disabled = blocking;
		return !blocking;
	}
	function fillForm(d) {
		var f = $('db-form');
		['host', 'name', 'user', 'password', 'prefix', 'site_url', 'home_url'].forEach(function (k) { if (d[k] !== undefined) { f.elements[k].value = d[k]; } });
		$('path').value = d.path || '';
	}
	function updateProgress(d) {
		var pct = Math.max(0, Math.min(100, Number(d.progress) || 0));
		$('bar-fill').style.width = pct + '%';
		$('pct').textContent = pct.toFixed(pct >= 100 ? 0 : 1) + ' %';
		$('detail').textContent = d.detail || '';
		if (d.log) { $('log').textContent = d.log.join('\n'); $('log').scrollTop = $('log').scrollHeight; }
		var titles = { extract: 'Extraction des fichiers…', import: 'Import de la base de données…', replace: 'Remplacement des URL et des chemins…', finalize: 'Finalisation…' };
		if (titles[d.phase]) { $('run-title').textContent = titles[d.phase]; setStep(d.phase); }
	}
	function done(d) {
		setStep('done');
		var r = d.result || {};
		$('site-link').textContent = r.site_url; $('site-link').href = r.site_url;
		var notes = $('notes'); notes.textContent = '';
		(r.notes || []).forEach(function (n) { var li = document.createElement('li'); li.textContent = n; notes.appendChild(li); });
		if (r.errors > 0 || r.warnings > 0) {
			var li = document.createElement('li'); li.className = 'warn';
			li.textContent = r.errors + ' erreur(s) SQL et ' + r.warnings + ' avertissement(s) : consultez le journal.';
			notes.appendChild(li);
		}
		show('done');
		verifySite(r);
	}
	// Contrôle du site migré, depuis le navigateur (même adresse que l'installeur).
	function verifySite(r) {
		var box = $('verify'), list = $('verify-list'), site;
		list.textContent = '';
		box.hidden = false;
		function item(text, level) {
			var li = document.createElement('li'), s = document.createElement('span');
			s.textContent = text; li.appendChild(s); list.appendChild(li);
			if (level) { li.className = level; }
			return { set: function (lvl, t) { li.className = lvl; if (t) { s.textContent = t; } } };
		}
		try { site = new URL(r.site_url, window.location.href); } catch (e) { box.hidden = true; return; }
		if (site.origin !== window.location.origin) {
			item('Contrôle automatique impossible : le site n’a pas la même adresse que cet installeur. Ouvrez-le pour le vérifier.', 'warn');
			return;
		}
		function get(url) {
			return fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + 'lwb_check=' + Date.now(), { credentials: 'omit', cache: 'no-store' })
				.then(function (res) { return res.text().then(function (t) { return { status: res.status, text: t }; }); });
		}
		function crashed(p) { return p.status >= 500 || /critical error|erreur critique|Fatal error/i.test(p.text); }
		function oldLinks(html) {
			var total = 0, found = [];
			(r.old_hosts || []).forEach(function (h) {
				var re = new RegExp('(^|[^a-z0-9.-])' + h.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), n = (html.match(re) || []).length;
				if (n) { total += n; found.push(h); }
			});
			return { total: total, hosts: found };
		}
		function assets(html) {
			var doc = new DOMParser().parseFromString(html, 'text/html'), urls = [];
			Array.prototype.forEach.call(doc.querySelectorAll('link[rel~="stylesheet"][href], script[src]'), function (el) {
				try {
					var u = new URL(el.getAttribute('href') || el.getAttribute('src'), site.href);
					if (u.origin === window.location.origin && urls.indexOf(u.href) < 0) { urls.push(u.href); }
				} catch (e) {}
			});
			urls = urls.slice(0, 40);
			return Promise.all(urls.map(function (u) {
				return fetch(u, { credentials: 'omit', cache: 'no-store' }).then(function (res) { return res.ok ? null : u; }, function () { return u; });
			})).then(function (bad) { return { count: urls.length, missing: bad.filter(Boolean) }; });
		}
		function isolate(url, label) {
			var it = item('Recherche de l’extension qui fait planter ' + label + '…');
			function stillBroken() { return get(url).then(crashed, function () { return true; }); }
			api('plugins').then(function (d) {
				if (!d.ok) { it.set('error', d.message); return; }
				var plugins = d.plugins.slice(), i = 0;
				function next() {
					if (i >= plugins.length) {
						it.set('error', 'Le site plante encore quelle que soit l’extension désactivée : la cause est ailleurs (thème, version de PHP). Consultez le journal d’erreurs de l’hébergeur.');
						return;
					}
					var p = plugins[i++];
					it.set('', 'Test sans l’extension ' + p + ' (' + i + '/' + plugins.length + ')…');
					api('plugin_toggle', { plugin: p, active: '' }).then(function (t) {
						if (!t.ok) { return next(); }
						return stillBroken().then(function (broken) {
							if (!broken) {
								it.set('warn', 'Extension désactivée parce qu’elle faisait planter ' + label + ' : ' + p + '. Mettez-la à jour (ou réinstallez-la) avant de la réactiver.');
								return;
							}
							return api('plugin_toggle', { plugin: p, active: '1' }).then(next);
						});
					}).catch(function () { it.set('error', 'Recherche interrompue : connexion au serveur perdue. Rechargez la page.'); });
				}
				next();
			}).catch(function (e) { it.set('error', e.message); });
		}
		var home = item('Page d’accueil…');
		get(site.href).then(function (p) {
			if (crashed(p)) {
				home.set('error', 'Page d’accueil : erreur critique (HTTP ' + p.status + ').');
				isolate(site.href, 'la page d’accueil');
				return;
			}
			if (p.status >= 400) { home.set('error', 'Page d’accueil : HTTP ' + p.status + '.'); return; }
			home.set('ok', 'Page d’accueil : en ligne (HTTP ' + p.status + ').');
			var links = oldLinks(p.text);
			if (links.total) {
				item(links.total + ' lien(s) vers l’ancien domaine (' + links.hosts.join(', ') + ') sur la page d’accueil : videz les caches de votre constructeur de pages et de votre extension de cache, puis rechargez.', 'warn');
			} else {
				item('Aucun lien vers l’ancien domaine sur la page d’accueil.', 'ok');
			}
			var res = item('Feuilles de style et scripts…');
			assets(p.text).then(function (a) {
				if (a.missing.length) {
					res.set('error', a.missing.length + ' fichier(s) CSS/JS introuvable(s) : ' + a.missing.map(function (u) { return u.replace(window.location.origin, ''); }).slice(0, 5).join(', ') + '. Régénérez les fichiers de votre constructeur de pages (Elementor › Outils › Effacer les fichiers et les données).');
				} else {
					res.set('ok', a.count + ' feuille(s) de style et script(s) chargé(s) sans erreur.');
				}
			});
			var login = item('Page de connexion…');
			get(r.login).then(function (q) {
				if (crashed(q)) { login.set('error', 'Page de connexion : erreur critique (HTTP ' + q.status + ').'); isolate(r.login, 'la page de connexion'); return; }
				if (q.status === 404) { login.set('warn', 'Page de connexion introuvable à l’adresse ' + r.login + ' : vérifiez l’extension qui masque la connexion.'); return; }
				login.set('ok', 'Page de connexion : ' + r.login);
			}, function () { login.set('warn', 'Page de connexion : contrôle impossible.'); });
			item('Administration : après connexion, vérifiez qu’aucune « erreur critique » ne s’affiche (une extension peut ne planter que dans l’administration ; désactivez-la alors dans Extensions).', 'info');
		}, function () { home.set('error', 'Page d’accueil injoignable depuis ce navigateur.'); });
	}
	function run() {
		api('step').then(function (d) {
			if (!d.ok) {
				if (d.retryable && retries < MAX_RETRIES) { return retry(d.message); }
				error(d.message, d.solution);
				addResume();
				return;
			}
			if (d.busy) { $('retry').textContent = 'Le serveur termine l’étape précédente…'; return setTimeout(run, 3000); }
			retries = 0; $('retry').textContent = '';
			updateProgress(d);
			if (d.phase === 'done') { return done(d); }
			setTimeout(run, 50);
		}).catch(function (e) {
			if (e.retryable && retries < MAX_RETRIES) { return retry(e.message); }
			error(e.message, 'Rechargez la page : l’installation reprendra au dernier point de sauvegarde.');
			addResume();
		});
	}
	function retry(msg) {
		retries++;
		var delay = Math.min(30, Math.pow(2, retries));
		$('retry').textContent = msg + ' Nouvelle tentative (' + retries + '/' + MAX_RETRIES + ') dans ' + delay + ' s…';
		setTimeout(run, delay * 1000);
	}
	function addResume() {
		var b = document.createElement('button'); b.className = 'btn primary'; b.textContent = 'Reprendre';
		b.onclick = function () { b.remove(); error(''); retries = 0; run(); };
		$('error').appendChild(b);
	}
	function start() {
		error('');
		$('start').disabled = true;
		api('start', formData()).then(function (d) {
			$('start').disabled = false;
			if (!d.ok) { error(d.message, d.solution); return; }
			show('run'); updateProgress(d); setStep('extract'); run();
		}).catch(function (e) { $('start').disabled = false; error(e.message); });
	}
	$('to-db').onclick = function () { show('db'); setStep('db'); };
	$('test-db').onclick = function () {
		var box = $('db-result'); box.hidden = true; error('');
		$('test-db').disabled = true;
		api('test_db', formData()).then(function (d) {
			$('test-db').disabled = false;
			box.className = 'msg ' + (d.ok ? 'ok' : 'error');
			box.textContent = d.ok ? d.message + (d.notes.length ? ' ' + d.notes.join(' ') : '') : d.message + (d.solution ? ' ' + d.solution : '');
			box.hidden = false;
		}).catch(function (e) { $('test-db').disabled = false; error(e.message); });
	};
	$('db-form').addEventListener('submit', function (e) { e.preventDefault(); start(); });
	$('cleanup').onclick = function () {
		$('cleanup').disabled = true;
		api('cleanup').then(function (d) {
			if (d.ok) { window.location.href = d.redirect; } else { $('cleanup').disabled = false; error(d.message, d.solution); }
		}).catch(function (e) { $('cleanup').disabled = false; error(e.message); });
	};
	api('status').then(function (d) {
		if (!d.ok) { error(d.message, d.solution); return; }
		fillForm(d.defaults);
		if (d.password_saved) { $('password').placeholder = 'Mot de passe enregistré : laissez vide pour le conserver'; }
		if (d.existing && B.mode !== 'direct') { $('db-hint').textContent = 'Identifiants pré-remplis depuis le wp-config.php existant : vérifiez-les.'; }
		if (d.phase === 'done') { done(d.progress); return; }
		if (B.phases.indexOf(d.phase) > -1) { show('run'); updateProgress(d.progress); run(); return; }
		var okEnv = renderChecks(d.checks);
		show('check'); setStep('check');
		if (B.mode === 'direct' && okEnv) {
			$('db-hint').textContent = 'Migration directe : identifiants de la base actuelle utilisés automatiquement.';
			show('db'); setStep('db');
			setTimeout(start, 600);
		}
	}).catch(function (e) { error(e.message, 'Rechargez la page.'); });
})();
JS;
	}

	/**
	 * En-tête HTML.
	 *
	 * @param string $title Titre.
	 * @return void
	 */
	private function page_header( $title ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo self::h( $title ); ?></title>
<style>
		<?php echo self::font_face(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS statique. ?>
:root { --p: #2A1FD0; --p2: #1B119C; --p-soft: #EEF0FD; --acc: #FF6B3D; --acc-soft: #FFF1EC; --ok: #059669; --ok-soft: #ecfdf5; --warn: #d97706; --warn-soft: #fffbeb; --err: #dc2626; --err-soft: #fef2f2; --bg: #F3F5FE; --card: #fff; --text: #0E1033; --muted: #5A607F; --line: #E0E5F6; --field: #fff; --grad: linear-gradient(135deg, var(--p), var(--p2)); }
@media (prefers-color-scheme: dark) { :root { --bg: #0A0B24; --card: #12143A; --text: #E8EAFB; --muted: #A3A8C9; --line: #24275A; --field: #0E1033; --p-soft: rgba(91,80,255,.18); --ok-soft: rgba(16,185,129,.12); --warn-soft: rgba(245,158,11,.12); --err-soft: rgba(239,68,68,.12); } }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
html, body { min-height: 100%; }
body { margin: 0; font: 15px/1.6 "Plus Jakarta Sans", ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; overflow-wrap: break-word; }
a { color: var(--p); }
.layout > *, .panel-head > div, .progress-card, .msg { min-width: 0; }
.help, .msg, .checks td, .log, .notes li, code { overflow-wrap: anywhere; }
h1 { font-size: 24px; line-height: 1.25; letter-spacing: -.01em; margin: 0 0 6px; }
.ico { width: 20px; height: 20px; flex: none; }
.muted { color: var(--muted); } .small { font-size: 13px; }
.layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); min-height: 100vh; }
.sidebar { position: sticky; top: 0; height: 100vh; overflow: auto; padding: 32px 26px; background: radial-gradient(420px 260px at 110% -10%, rgba(255,107,61,.35), transparent 70%), var(--grad); color: #fff; display: flex; flex-direction: column; gap: 28px; }
.sidebar::before { content: ""; position: fixed; width: 300px; height: 300px; left: -120px; bottom: -120px; border-radius: 50%; background: rgba(255,255,255,.07); pointer-events: none; }
.brand { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 17px; }
.brand small { display: block; font-weight: 500; font-size: 12px; opacity: .75; }
.logo { display: inline-flex; width: 44px; height: 44px; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 20px -8px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.25); }
.logo svg { width: 100%; height: 100%; display: block; }
.stepper { list-style: none; margin: 0; padding: 0; position: relative; counter-reset: step; }
.stepper li { counter-increment: step; }
.step-dot::after { content: counter(step); }
.stepper li { position: relative; display: flex; gap: 14px; padding: 0 0 22px; color: rgba(255,255,255,.65); }
.stepper li:not(:last-child)::after { content: ""; position: absolute; left: 13px; top: 30px; bottom: 2px; width: 2px; background: rgba(255,255,255,.2); border-radius: 2px; }
.step-dot { position: relative; z-index: 1; flex: none; width: 28px; height: 28px; border-radius: 50%; border: 2px solid rgba(255,255,255,.4); background: transparent; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; transition: all .25s; }
.step-text strong { display: block; font-size: 14px; color: inherit; }
.step-text small { font-size: 12px; opacity: .8; }
.stepper li.current { color: #fff; }
.stepper li.current .step-dot { background: #fff; border-color: #fff; box-shadow: 0 0 0 6px rgba(255,255,255,.18); }
.stepper li.current .step-dot::after { content: ""; width: 10px; height: 10px; border-radius: 50%; background: var(--p); }
.stepper li.done { color: #fff; }
.stepper li.done .step-dot { background: rgba(255,255,255,.95); border-color: transparent; color: var(--ok); }
.stepper li.done .step-dot::after { content: "✓"; }
.stepper li.done:not(:last-child)::after { background: rgba(255,255,255,.75); }
.source { margin-top: auto; padding: 16px; border-radius: 14px; background: rgba(255,255,255,.12); box-shadow: inset 0 0 0 1px rgba(255,255,255,.18); display: flex; flex-direction: column; gap: 3px; font-size: 13px; }
.source-label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .75; font-weight: 700; }
.source-url { opacity: .85; word-break: break-all; }
.source-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.source-tags span { padding: 2px 8px; border-radius: 999px; background: rgba(255,255,255,.16); font-size: 11.5px; font-weight: 600; }
.content { padding: 48px clamp(16px, 5vw, 64px); max-width: 920px; width: 100%; }
.panel { background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 32px; box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 24px 48px -28px rgba(15,23,42,.25); animation: rise .35s ease; }
@keyframes rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
.panel-head { display: flex; gap: 16px; align-items: flex-start; margin-bottom: 24px; }
.panel-head p { margin: 4px 0 0; }
.panel-icon { flex: none; display: inline-flex; width: 48px; height: 48px; border-radius: 14px; align-items: center; justify-content: center; background: var(--p-soft); color: var(--p); }
.panel-icon .ico { width: 24px; height: 24px; }
.panel-icon.spin .ico { animation: bob 1.4s ease-in-out infinite; }
@keyframes bob { 50% { transform: translateY(-3px); } }
.eyebrow { display: block; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #D9481B; margin-bottom: 2px; }
.checks { width: 100%; border-collapse: separate; border-spacing: 0 6px; margin: 0; }
.checks td { padding: 12px 14px; background: var(--bg); vertical-align: middle; }
.checks td:first-child { width: 42%; font-weight: 600; border-radius: 12px 0 0 12px; }
.checks td:last-child { border-radius: 0 12px 12px 0; }
.checks tr.warn td { background: var(--warn-soft); } .checks tr.error td { background: var(--err-soft); }
.pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 11px; border-radius: 999px; font-size: 13px; font-weight: 600; }
.pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.pill.ok { background: var(--ok-soft); color: var(--ok); } .pill.warn { background: var(--warn-soft); color: var(--warn); } .pill.error { background: var(--err-soft); color: var(--err); }
.help { font-size: 13px; color: var(--muted); margin-top: 6px; }
label { display: block; font-weight: 600; font-size: 13.5px; margin-bottom: 6px; }
input[type=text], input:not([type]), input[type=password] { width: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 12px; font: inherit; background: var(--field); color: var(--text); transition: border-color .15s, box-shadow .15s; }
input:focus { outline: none; border-color: var(--p); box-shadow: 0 0 0 4px rgba(42,31,208,.15); }
input[readonly] { opacity: .7; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 0 0 18px; }
.grid .full { grid-column: 1 / -1; }
.grid .check { display: flex; align-items: flex-end; padding-bottom: 10px; }
.toggle { display: inline-flex; align-items: center; gap: 10px; font-weight: 500; cursor: pointer; margin: 0; }
.toggle input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.toggle-ui { position: relative; width: 42px; height: 24px; border-radius: 999px; background: #cbd5e1; transition: background .2s; flex: none; }
.toggle-ui::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .2s; }
.toggle input:checked + .toggle-ui { background: var(--grad); }
.toggle input:checked + .toggle-ui::after { transform: translateX(18px); }
.toggle input:focus-visible + .toggle-ui { box-shadow: 0 0 0 4px rgba(42,31,208,.2); }
.advanced { margin: 4px 0 18px; border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; background: var(--bg); }
.advanced summary { cursor: pointer; font-weight: 600; }
.advanced .grid { margin: 14px 0 0; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 22px; }
.actions.end { justify-content: flex-end; } .actions.between { justify-content: space-between; } .actions.center { justify-content: center; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--text); font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; transition: transform .12s, box-shadow .15s, border-color .15s; }
.btn:hover { border-color: var(--p); }
.btn.primary { background: var(--grad); border-color: transparent; color: #fff; box-shadow: 0 10px 22px -10px rgba(42,31,208,.8); }
.btn.primary:hover { transform: translateY(-1px); box-shadow: 0 14px 28px -12px rgba(42,31,208,.9); }
.btn.block { width: 100%; }
.btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
.msg { padding: 14px 16px; border-radius: 12px; margin: 14px 0; background: var(--err-soft); color: var(--err); border: 1px solid rgba(220,38,38,.25); }
.msg.ok { background: var(--ok-soft); color: var(--ok); border-color: rgba(5,150,105,.25); }
.msg strong { display: block; } .msg p { margin: 4px 0 0; color: var(--text); }
.msg .btn { margin-top: 10px; }
.progress-card { padding: 22px; border-radius: 16px; background: var(--bg); border: 1px solid var(--line); }
.progress-top { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
#pct { font-size: 40px; font-weight: 800; letter-spacing: -.03em; line-height: 1; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; font-variant-numeric: tabular-nums; }
.bar { height: 14px; background: var(--line); border-radius: 999px; overflow: hidden; }
#bar-fill { position: relative; height: 100%; width: 0; background: var(--grad); border-radius: 999px; transition: width .45s ease; overflow: hidden; }
#bar-fill::after { content: ""; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent); transform: translateX(-100%); animation: shine 1.6s infinite; }
@keyframes shine { to { transform: translateX(100%); } }
.log { background: #0b1020; color: #a5b4fc; font: 12px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; padding: 14px 16px; border-radius: 14px; max-height: 190px; overflow: auto; white-space: pre-wrap; word-break: break-word; margin: 16px 0 0; }
.log:empty { display: none; }
.panel.done { text-align: center; padding: 48px 32px; }
.success-icon { width: 76px; height: 76px; margin: 0 auto 18px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #059669); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 10px var(--ok-soft), 0 18px 36px -14px rgba(5,150,105,.8); animation: pop .5s cubic-bezier(.2,1.6,.4,1); }
.success-icon .ico { width: 38px; height: 38px; stroke-width: 2.5; }
@keyframes pop { from { transform: scale(.4); opacity: 0; } to { transform: none; opacity: 1; } }
.lead { font-size: 17px; }
.notes { list-style: none; padding: 0; margin: 22px auto 0; max-width: 620px; text-align: left; display: grid; gap: 8px; }
.notes li { padding: 12px 14px 12px 40px; border-radius: 12px; background: var(--p-soft); position: relative; font-size: 14px; }
.notes li::before { content: "i"; position: absolute; left: 14px; top: 13px; width: 18px; height: 18px; border-radius: 50%; background: var(--p); color: #fff; font-size: 11px; font-weight: 800; text-align: center; line-height: 18px; }
.notes li.warn { background: var(--warn-soft); } .notes li.warn::before { content: "!"; background: var(--warn); }
.verify { max-width: 620px; margin: 26px auto 0; text-align: left; }
.verify h2 { font-size: 16px; margin: 0 0 10px; }
.verify-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 6px; }
.verify-list li { display: flex; gap: 10px; align-items: flex-start; padding: 10px 14px; border-radius: 12px; background: var(--bg); font-size: 14px; }
.verify-list li::before { content: "…"; flex: none; width: 20px; height: 20px; border-radius: 50%; background: var(--line); color: var(--muted); font-size: 12px; font-weight: 800; text-align: center; line-height: 20px; }
.verify-list li.ok::before { content: "✓"; background: var(--ok); color: #fff; }
.verify-list li.warn { background: var(--warn-soft); } .verify-list li.warn::before { content: "!"; background: var(--warn); color: #fff; }
.verify-list li.error { background: var(--err-soft); } .verify-list li.error::before { content: "×"; background: var(--err); color: #fff; }
.verify-list li span { min-width: 0; overflow-wrap: anywhere; }
.verify-list li.info::before { content: "i"; background: var(--p); color: #fff; }
.solo { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: radial-gradient(1000px 520px at 10% -10%, rgba(42,31,208,.18), transparent), radial-gradient(800px 460px at 110% 110%, rgba(255,107,61,.16), transparent); }
.solo-card { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 22px; padding: 36px 32px; text-align: center; box-shadow: 0 30px 60px -30px rgba(15,23,42,.35); }
.solo-card form { text-align: left; margin-top: 22px; }
.solo-card .btn { margin-top: 14px; }
.solo-icon { display: inline-flex; width: 64px; height: 64px; border-radius: 18px; align-items: center; justify-content: center; background: var(--grad); color: #fff; margin-bottom: 16px; box-shadow: 0 14px 28px -14px rgba(42,31,208,.9); }
.solo-icon.brand { background: none; box-shadow: 0 14px 28px -14px rgba(27,17,156,.8); overflow: hidden; }
.solo-icon.brand svg { width: 100%; height: 100%; }
.solo-icon .ico { width: 30px; height: 30px; }
.solo-icon.error { background: linear-gradient(135deg, #f87171, #dc2626); } .solo-icon.ok { background: linear-gradient(135deg, #10b981, #059669); }
.brand-foot { display: inline-flex; align-items: center; gap: 6px; margin: 22px 0 0; font-size: 12.5px; font-weight: 600; color: var(--muted); }
.brand-foot .ico { width: 16px; height: 16px; }
@media (max-width: 900px) {
	.layout { grid-template-columns: minmax(0, 1fr); }
	.checks, .checks tbody, .checks tr { display: block; width: 100%; }
	.checks tr { margin-bottom: 6px; }
	.sidebar { position: static; height: auto; padding: 20px 16px; gap: 16px; }
	.sidebar::before, .source { display: none; }
	.stepper { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px; }
	.stepper li { padding: 0; flex: none; align-items: center; gap: 8px; }
	.stepper li::after { display: none; }
	.step-text small { display: none; }
	.step-text strong { font-size: 12px; white-space: nowrap; }
	.stepper li:not(.current) .step-text { display: none; }
	.content { padding: 20px 16px 32px; }
	.panel { padding: 22px 18px; border-radius: 16px; }
	.panel-head { flex-direction: column; gap: 12px; }
	.grid { grid-template-columns: 1fr; }
	.checks td { display: block; border-radius: 0 !important; width: auto !important; }
	.checks td:first-child { border-radius: 12px 12px 0 0 !important; padding-bottom: 4px; }
	.checks td:last-child { border-radius: 0 0 12px 12px !important; padding-top: 4px; }
	.actions .btn { width: 100%; }
	#pct { font-size: 32px; }
}
</style>
</head>
<body>
		<?php
	}

	/**
	 * Pied de page HTML.
	 *
	 * @return void
	 */
	private function page_footer() {
		echo "\n</body>\n</html>\n";
	}
}

if ( ! defined( 'LWB_INSTALLER_NO_RUN' ) ) {
	// Script autonome hors WordPress : les erreurs ne doivent jamais s'afficher (réponses JSON).
	@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
	error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
	if ( function_exists( 'date_default_timezone_set' ) ) {
		$lwb_migration_tz = @date_default_timezone_get();
		@date_default_timezone_set( '' !== (string) $lwb_migration_tz ? $lwb_migration_tz : 'UTC' );
	}
	ob_start();
	( new Installer( Installer::config(), __FILE__ ) )->run();
}
