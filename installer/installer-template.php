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

		$this->load_state();
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

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
	 * Une authentification est-elle exigée ?
	 *
	 * @return bool
	 */
	private function needs_auth() {
		return '' !== (string) $this->config['password_hash'] || 'direct' === $this->config['mode'];
	}

	/**
	 * Cookie de session valide ?
	 *
	 * @return bool
	 */
	private function is_authenticated() {
		if ( ! $this->needs_auth() ) {
			return true;
		}
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
					'samesite' => 'Strict',
				)
			);
		} else {
			setcookie( self::COOKIE, $value, time() + self::AUTH_TTL, $path . '; samesite=Strict', '', $this->is_https(), true );
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
		$data = array_values(
			array_filter(
				$data,
				function ( $t ) use ( $now ) {
					return $now - (int) $t < 900;
				}
			)
		);
		if ( $failed ) {
			$data[] = $now;
			Json_Store::ensure_protected_dir( $this->data_dir );
			Json_Store::write( $file, $data );
		}
		return count( $data ) >= self::MAX_ATTEMPTS;
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
			$this->error( 'Mot de passe incorrect.', 'Utilisez le mot de passe défini lors de l’export (affiché dans la liste des paquets du site source).' );
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
			'password' => isset( $db['password'] ) ? $db['password'] : ( isset( $existing['password'] ) ? $existing['password'] : '' ),
			'prefix'   => isset( $db['prefix'] ) ? $db['prefix'] : ( isset( $existing['prefix'] ) ? $existing['prefix'] : $this->config['source']['table_prefix'] ),
			'site_url' => $url,
			'home_url' => ! empty( $this->config['target']['home_url'] ) ? $this->config['target']['home_url'] : $this->derive_home( $url ),
			'path'     => $this->root,
		);
		if ( ! empty( $this->state['form'] ) ) {
			$defaults = array_merge( $defaults, $this->state['form'] );
		}
		return array(
			'ok'       => true,
			'phase'    => $this->phase(),
			'checks'   => $this->checks(),
			'defaults' => $defaults,
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
		$f['create']   = ! empty( $_POST['create'] );
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
			while ( ! $rt->should_stop() && in_array( $this->phase(), self::PHASES, true ) ) {
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
			}
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
		while ( ! $rt->should_stop() ) {
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
		}
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
		while ( ! $rt->should_stop() ) {
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
		}
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
		$this->fix_multisite( $db );
		$db->close();

		$this->write_wp_config();
		$this->write_htaccess();
		$this->restore_ini_files();
		$this->disable_dropins();
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
			$out['result'] = array(
				'site_url' => $s['target']['home_url'],
				'admin'    => rtrim( $s['target']['site_url'], '/' ) . '/wp-admin/',
				'warnings' => (int) $s['warnings'],
				'errors'   => (int) $s['errors'],
				'notes'    => isset( $s['notes'] ) ? $s['notes'] : array(),
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Pages HTML.
	// ------------------------------------------------------------------

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
		echo '<main class="card narrow"><div class="msg ' . self::h( $kind ) . '"><strong>' . self::h( $title ) . '</strong><p>' . self::h( $text ) . '</p></div></main>';
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
			echo '<main class="card narrow"><div class="msg error"><strong>Accès réservé.</strong><p>Ouvrez l’installeur depuis le lien affiché sur le site source à la fin de la migration directe.</p></div></main>';
			$this->page_footer();
			return;
		}
		?>
		<main class="card narrow">
			<h1>Installation protégée</h1>
			<p class="muted">Saisissez le mot de passe défini lors de l’export du site.</p>
			<form id="login" autocomplete="off">
				<label for="pw">Mot de passe</label>
				<input type="password" id="pw" name="password" required autofocus>
				<div class="msg error" id="login-error" hidden></div>
				<button type="submit" class="btn primary">Continuer</button>
			</form>
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
		$src  = $this->config['source'];
		$boot = array(
			'csrf'   => $this->csrf_token(),
			'self'   => $this->self,
			'mode'   => $this->config['mode'],
			'phases' => self::PHASES,
		);
		$this->page_header( 'Installation – ' . $src['site_name'] );
		?>
		<div class="layout">
			<aside class="steps" aria-label="Étapes">
				<div class="brand"><span class="logo" aria-hidden="true">⇄</span> Lwb Migration</div>
				<ol id="steps">
					<li data-step="check">Vérification</li>
					<li data-step="db">Base de données</li>
					<li data-step="extract">Extraction</li>
					<li data-step="import">Import SQL</li>
					<li data-step="replace">Rechercher / remplacer</li>
					<li data-step="finalize">Finalisation</li>
				</ol>
				<div class="source">
					<strong>Site source</strong>
					<span><?php echo self::h( $src['site_url'] ); ?></span>
					<span>WordPress <?php echo self::h( $src['wp_version'] ); ?> · PHP <?php echo self::h( $src['php_version'] ); ?></span>
					<span>Paquet du <?php echo self::h( date( 'd/m/Y H:i', (int) $this->config['created'] ) ); ?></span>
				</div>
			</aside>

			<main class="card">
				<div class="msg error" id="error" role="alert" hidden></div>

				<section id="panel-check" hidden>
					<h1>1. Vérification de l’environnement</h1>
					<p class="muted">Contrôle du serveur de destination et de l’archive.</p>
					<table class="checks"><tbody id="checks"></tbody></table>
					<div class="actions"><button type="button" class="btn primary" id="to-db" disabled>Continuer</button></div>
				</section>

				<section id="panel-db" hidden>
					<h1>2. Base de données</h1>
					<p class="muted" id="db-hint">Saisissez les accès fournis par votre hébergeur. La base doit exister (ou cochez « créer »).</p>
					<form id="db-form" autocomplete="off">
						<div class="grid">
							<div><label for="host">Hôte</label><input id="host" name="host" required placeholder="localhost"></div>
							<div><label for="name">Nom de la base</label><input id="name" name="name" required></div>
							<div><label for="user">Utilisateur</label><input id="user" name="user" required autocomplete="off"></div>
							<div><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="new-password"></div>
							<div><label for="prefix">Préfixe des tables</label><input id="prefix" name="prefix" required pattern="[A-Za-z0-9_]+"></div>
							<div class="check"><label><input type="checkbox" name="create" value="1"> Créer la base si elle n’existe pas</label></div>
						</div>
						<details>
							<summary>Adresse et dossier du nouveau site (détectés automatiquement)</summary>
							<div class="grid">
								<div class="full"><label for="site_url">Adresse du site (WordPress)</label><input id="site_url" name="site_url" required></div>
								<div class="full"><label for="home_url">Adresse de la page d’accueil</label><input id="home_url" name="home_url" required></div>
								<div class="full"><label for="path">Dossier sur le serveur</label><input id="path" readonly></div>
							</div>
						</details>
						<div class="msg" id="db-result" hidden></div>
						<div class="actions">
							<button type="button" class="btn" id="test-db">Tester la connexion</button>
							<button type="submit" class="btn primary" id="start">Lancer l’installation</button>
						</div>
						<p class="muted small">Les tables du même nom seront remplacées. Les fichiers existants portant le même nom seront écrasés.</p>
					</form>
				</section>

				<section id="panel-run" hidden>
					<h1 id="run-title">Installation en cours…</h1>
					<p class="muted">Ne fermez pas cette page. En cas de coupure, rechargez-la : l’installation reprendra là où elle s’était arrêtée.</p>
					<div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><div id="bar-fill"></div></div>
					<p class="progress-line"><strong id="pct">0 %</strong> <span id="detail"></span></p>
					<p class="muted small" id="retry"></p>
					<pre id="log" class="log"></pre>
				</section>

				<section id="panel-done" hidden>
					<div class="success-icon" aria-hidden="true">✓</div>
					<h1>Migration réussie !</h1>
					<p>Votre site est disponible à l’adresse <a id="site-link" target="_blank" rel="noopener"></a>.</p>
					<p class="muted">Connectez-vous avec les identifiants administrateur du site source.</p>
					<ul id="notes" class="notes"></ul>
					<div class="actions">
						<button type="button" class="btn primary" id="cleanup">Supprimer les fichiers d’installation et ouvrir l’administration</button>
						<a class="btn" href="?lwb_log=1">Télécharger le journal</a>
					</div>
					<p class="muted small">Par sécurité, l’installeur et son dossier de travail sont supprimés automatiquement une heure après la fin de l’installation s’ils sont encore là.</p>
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
		var order = ['check', 'db', 'extract', 'import', 'replace', 'finalize'], idx = order.indexOf(step);
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
:root { --accent: #2563eb; --accent-d: #1d4ed8; --ok: #16a34a; --warn: #b45309; --err: #dc2626; --bg: #f1f5f9; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; }
@media (prefers-color-scheme: dark) { :root { --bg: #0b1120; --card: #111827; --text: #e5e7eb; --muted: #94a3b8; --line: #1f2937; } }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
body { margin: 0; font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); }
a { color: var(--accent); }
h1 { font-size: 22px; margin: 0 0 6px; }
.layout { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 24px; max-width: 1080px; margin: 32px auto; padding: 0 16px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.card.narrow { max-width: 460px; margin: 64px auto; }
.steps { position: sticky; top: 24px; align-self: start; }
.brand { font-weight: 700; font-size: 18px; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
.logo { display: inline-flex; width: 32px; height: 32px; border-radius: 8px; background: var(--accent); color: #fff; align-items: center; justify-content: center; }
.steps ol { list-style: none; margin: 0; padding: 0; counter-reset: s; }
.steps li { counter-increment: s; padding: 9px 12px; margin-bottom: 4px; border-radius: 8px; color: var(--muted); display: flex; align-items: center; gap: 10px; }
.steps li::before { content: counter(s); display: inline-flex; width: 24px; height: 24px; border-radius: 50%; border: 2px solid var(--line); align-items: center; justify-content: center; font-size: 12px; font-weight: 600; flex: none; }
.steps li.current { background: var(--card); color: var(--text); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
.steps li.current::before { border-color: var(--accent); color: var(--accent); }
.steps li.done { color: var(--text); }
.steps li.done::before { content: "✓"; background: var(--ok); border-color: var(--ok); color: #fff; }
.source { margin-top: 20px; font-size: 13px; color: var(--muted); display: flex; flex-direction: column; gap: 2px; word-break: break-all; }
.source strong { color: var(--text); }
.muted { color: var(--muted); } .small { font-size: 13px; }
.checks { width: 100%; border-collapse: collapse; margin: 16px 0; }
.checks td { padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
.checks td:first-child { width: 40%; font-weight: 500; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; }
.pill.ok { background: #dcfce7; color: #166534; } .pill.warn { background: #fef3c7; color: #92400e; } .pill.error { background: #fee2e2; color: #991b1b; }
.help { font-size: 13px; color: var(--muted); margin-top: 4px; }
label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 4px; }
input[type=text], input:not([type]), input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: var(--card); color: var(--text); }
input:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
input[readonly] { opacity: .7; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 16px 0; }
.grid .full { grid-column: 1 / -1; }
.grid .check { display: flex; align-items: flex-end; } .grid .check label { font-weight: 400; display: flex; gap: 8px; align-items: center; }
details { margin: 8px 0 16px; border: 1px solid var(--line); border-radius: 8px; padding: 10px 14px; }
summary { cursor: pointer; font-weight: 600; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
.btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 18px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); color: var(--text); font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.btn:hover { border-color: var(--accent); }
.btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn.primary:hover { background: var(--accent-d); }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.msg { padding: 12px 14px; border-radius: 8px; margin: 12px 0; border-left: 4px solid var(--err); background: rgba(220,38,38,.08); }
.msg.ok { border-color: var(--ok); background: rgba(22,163,74,.1); }
.msg p { margin: 4px 0 0; }
.msg .btn { margin-top: 10px; }
.bar { height: 14px; background: var(--line); border-radius: 7px; overflow: hidden; margin-top: 18px; }
#bar-fill { height: 100%; width: 0; background: linear-gradient(90deg, var(--accent), #60a5fa); transition: width .4s ease; }
.progress-line { display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
.log { background: #0f172a; color: #cbd5e1; font-size: 12px; padding: 10px 12px; border-radius: 8px; max-height: 170px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.log:empty { display: none; }
.success-icon { width: 56px; height: 56px; border-radius: 50%; background: var(--ok); color: #fff; font-size: 30px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.notes { padding-left: 20px; } .notes li { margin-bottom: 6px; } .notes li.warn { color: var(--warn); }
@media (max-width: 800px) { .layout { grid-template-columns: 1fr; margin: 16px auto; } .steps { position: static; } .steps ol { display: flex; flex-wrap: wrap; gap: 4px; } .steps li { font-size: 13px; padding: 6px 8px; } .source { display: none; } .grid { grid-template-columns: 1fr; } .card { padding: 20px; } }
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
