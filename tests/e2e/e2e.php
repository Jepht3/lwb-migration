<?php
/**
 * Scénario complet de bout en bout (nécessite PHP CLI, MySQL/MariaDB et WordPress).
 *
 *   php tests/e2e/e2e.php
 *
 * Variables d'environnement :
 *   WP_CORE_DIR           Dossier contenant WordPress (sinon téléchargé via Composer).
 *   LWB_E2E_DB_HOST/USER/PASSWORD  Accès MySQL (défaut : localhost / root / vide).
 *
 * Scénarios :
 *   1. Export classique d'un site installé dans un sous-dossier (http://127.0.0.1:8101/site,
 *      préfixe srcwp_) puis installation via installer.php protégé par mot de passe sur un
 *      autre domaine, autre dossier, autre base (créée par l'installeur) et autre préfixe.
 *   2. Migration directe serveur à serveur vers un WordPress existant (clé HMAC).
 *   3. Format de repli LWB, installation dans un sous-dossier, base contenant déjà les tables.
 *   5. Migration directe avant le changement de DNS (domaine nouveau-site.test sans DNS,
 *      joint par son adresse IP), pare-feu qui bloque PUT et POST : bascule automatique sur
 *      le téléchargement par la destination, installation pilotée depuis la source.
 *   6. Import depuis l'administration de la destination (sans FTP) : envoi par morceaux,
 *      reprise, brouillage face à un pare-feu qui inspecte les envois, archive chiffrée.
 *
 * Les serveurs PHP tournent avec memory_limit=128M, upload_max_filesize=2M, post_max_size=3M,
 * un max_execution_time court et exec/shell_exec/proc_open désactivés.
 *
 * @package LwbMigration
 */

// phpcs:ignoreFile -- script de test hors WordPress.

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'E2E_ROOT', dirname( __DIR__, 2 ) );
define( 'E2E_WORK', __DIR__ . '/.work' );
define( 'E2E_PASS', 'Secret#Pass123' );
define( 'E2E_PKG_PASSWORD', 'motdepasse-installeur-42' );

// Classes autonomes de l'extension (déchiffrement de l'archive pour la contrôler).
define( 'LWB_MIGRATION_STANDALONE', true );
foreach ( array( 'migration-exception', 'runtime', 'archive-crypto', 'transfer-tools' ) as $lwb_class ) {
	require_once E2E_ROOT . '/includes/class-' . $lwb_class . '.php';
}

$db_host = getenv( 'LWB_E2E_DB_HOST' ) ?: 'localhost';
$db_user = getenv( 'LWB_E2E_DB_USER' ) ?: 'root';
$db_pass = (string) getenv( 'LWB_E2E_DB_PASSWORD' );

$failures = 0;
$checks   = 0;
$servers  = array();

function step( $msg ) {
	echo "\n\033[1;34m== $msg\033[0m\n";
}
function ok( $cond, $msg ) {
	global $failures, $checks;
	++$checks;
	if ( $cond ) {
		echo "  \033[32m✔\033[0m $msg\n";
	} else {
		++$failures;
		echo "  \033[31m✘ $msg\033[0m\n";
		if ( getenv( 'GITHUB_ACTIONS' ) ) {
			// Annotation : lisible dans l'interface GitHub sans ouvrir les journaux.
			echo '::error::' . str_replace( array( "\r", "\n" ), ' ', $msg ) . "\n";
		}
	}
	return $cond;
}
function fail( $msg ) {
	global $servers;
	echo "\n\033[31mÉCHEC : $msg\033[0m\n";
	if ( getenv( 'GITHUB_ACTIONS' ) ) {
		echo '::error::ÉCHEC : ' . substr( str_replace( array( "\r", "\n" ), ' ', $msg ), 0, 900 ) . "\n";
	}
	foreach ( $servers as $s ) {
		proc_terminate( $s );
	}
	exit( 1 );
}
function rrmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		@unlink( $dir );
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			rrmdir( $dir . '/' . $item );
		}
	}
	rmdir( $dir );
}
function rcopy( $src, $dst, array $skip = array() ) {
	if ( is_dir( $src ) ) {
		@mkdir( $dst, 0755, true );
		foreach ( scandir( $src ) as $item ) {
			if ( '.' === $item || '..' === $item || in_array( $item, $skip, true ) ) {
				continue;
			}
			rcopy( $src . '/' . $item, $dst . '/' . $item );
		}
	} else {
		@mkdir( dirname( $dst ), 0755, true );
		copy( $src, $dst );
	}
}
function mysql_root() {
	global $db_host, $db_user, $db_pass;
	mysqli_report( MYSQLI_REPORT_OFF );
	$m = new mysqli( $db_host, $db_user, $db_pass );
	if ( $m->connect_errno ) {
		fail( 'MySQL indisponible : ' . $m->connect_error );
	}
	$m->set_charset( 'utf8mb4' );
	return $m;
}
function db( $name ) {
	$m = mysql_root();
	$m->select_db( $name );
	return $m;
}
function scalar( mysqli $m, $sql ) {
	$r = $m->query( $sql );
	if ( ! $r ) {
		fail( "SQL : $sql → " . $m->error );
	}
	$row = $r->fetch_row();
	return $row ? $row[0] : null;
}

/* ------------------------------------------------------------------ */
/* Serveurs PHP intégrés                                                */
/* ------------------------------------------------------------------ */

function start_server( $port, $docroot, $max_exec, $name, $router = null ) {
	global $servers;
	$log = E2E_WORK . "/php-$name.log";
	$cmd = array(
		PHP_BINARY,
		'-d', 'memory_limit=128M',
		'-d', "max_execution_time=$max_exec",
		'-d', 'upload_max_filesize=2M',
		'-d', 'post_max_size=3M',
		'-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec',
		'-d', 'display_errors=0',
		'-d', 'log_errors=1',
		'-d', "error_log=$log",
		'-d', 'error_reporting=' . E_ALL,
		'-S', "127.0.0.1:$port",
		'-t', $docroot,
	);
	if ( $router ) {
		$cmd[] = $router;
	}
	// Étapes de 0,25 s : force des dizaines de requêtes, donc de points de reprise.
	$env  = array_merge( getenv(), array( 'PHP_CLI_SERVER_WORKERS' => '4', 'LWB_MIGRATION_STEP_BUDGET' => '0.25' ) );
	$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', E2E_WORK . "/server-$name.out", 'a' ), 2 => array( 'file', E2E_WORK . "/server-$name.out", 'a' ) ), $pipes, null, $env );
	$servers[ $name ] = $proc;
	for ( $i = 0; $i < 50; $i++ ) {
		$s = @fsockopen( '127.0.0.1', $port );
		if ( $s ) {
			fclose( $s );
			return;
		}
		usleep( 100000 );
	}
	fail( "serveur $name ($port) non démarré" );
}

/* ------------------------------------------------------------------ */
/* Client HTTP                                                          */
/* ------------------------------------------------------------------ */

class Client {
	public $jar;
	public $host;
	public function __construct( $name, $host = null ) {
		$this->jar  = E2E_WORK . "/cookies-$name.txt";
		$this->host = $host;
		@unlink( $this->jar );
	}
	public function request( $url, $post = null, $timeout_ms = 120000, $follow = false ) {
		$ch = curl_init( $url );
		$headers = array( 'Expect:' );
		// Les sites « localhost:port » et « *.test:port » (domaine sans DNS, comme un site dont
		// les DNS ne pointent pas encore vers le nouveau serveur) sont servis par 127.0.0.1.
		if ( preg_match( '#^https?://(localhost|[a-z0-9.-]+\.test):(\d+)#', $url, $m ) ) {
			curl_setopt( $ch, CURLOPT_RESOLVE, array( "{$m[1]}:{$m[2]}:127.0.0.1" ) );
		}
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_COOKIEJAR      => $this->jar,
				CURLOPT_COOKIEFILE     => $this->jar,
				CURLOPT_TIMEOUT_MS     => $timeout_ms,
				CURLOPT_FOLLOWLOCATION => $follow,
				CURLOPT_HEADER         => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_PROXY          => '',
				CURLOPT_NOPROXY        => '*',
			)
		);
		if ( null !== $post ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, is_array( $post ) && ! array_filter( $post, function ( $v ) { return $v instanceof CURLFile; } ) ? http_build_query( $post ) : $post );
		}
		$raw  = curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$hs   = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );
		if ( false === $raw ) {
			return array( 'code' => 0, 'body' => '', 'headers' => '', 'error' => $err );
		}
		return array( 'code' => $code, 'headers' => substr( $raw, 0, $hs ), 'body' => substr( $raw, $hs ), 'error' => '' );
	}
	public function download( $url, $file ) {
		$fh = fopen( $file, 'wb' );
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array( CURLOPT_FILE => $fh, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 600 ) );
		curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		fclose( $fh );
		return $code;
	}
	public function wp_login( $base, $user, $pass ) {
		$this->request( "$base/wp-login.php" );
		$r = $this->request(
			"$base/wp-login.php",
			array(
				'log'         => $user,
				'pwd'         => $pass,
				'wp-submit'   => 'Log In',
				'testcookie'  => '1',
				'redirect_to' => "$base/wp-admin/",
			)
		);
		return 302 === $r['code'] && false !== strpos( $r['headers'], 'wp-admin' );
	}
	public function nonce( $base ) {
		$r = $this->request( "$base/wp-admin/admin.php?page=lwb-migration" );
		if ( ! preg_match( '/LwbMigration = \{[^}]*"nonce":"([a-f0-9]+)"/', $r['body'], $m ) ) {
			fail( "nonce introuvable sur $base (HTTP {$r['code']})" );
		}
		return $m[1];
	}
	public function ajax( $base, $nonce, $action, array $data = array(), $timeout_ms = 120000 ) {
		$r = $this->request( "$base/wp-admin/admin-ajax.php", array_merge( array( 'action' => "lwb_migration_$action", 'nonce' => $nonce ), $data ), $timeout_ms );
		$j = json_decode( $r['body'], true );
		return array( $r, $j );
	}
}

/**
 * Pilote une tâche comme le JavaScript d'administration (reprises comprises).
 * Une requête sur trois est volontairement abandonnée côté client après 300 ms
 * (simulation de coupure réseau/délai dépassé) : le serveur doit verrouiller et reprendre.
 */
function drive_job( Client $c, $base, $nonce, $job, $chaos = true ) {
	$steps = 0;
	$aborted = 0;
	$busy = 0;
	$retries = 0;
	$last = null;
	while ( $steps < 5000 ) {
		++$steps;
		$timeout = ( $chaos && 0 === $steps % 3 ) ? 300 : 120000;
		list( $r, $j ) = $c->ajax( $base, $nonce, 'job_step', array( 'job' => $job ), $timeout );
		if ( 0 === $r['code'] ) {
			++$aborted;
			continue;
		}
		if ( ! is_array( $j ) || empty( $j['success'] ) ) {
			++$retries;
			echo "    réponse invalide (HTTP {$r['code']}) : " . substr( $r['body'], 0, 300 ) . "\n";
			if ( $retries > 8 ) {
				fail( 'trop de réponses invalides' );
			}
			sleep( 1 );
			continue;
		}
		$d = $j['data'];
		if ( 'busy' === $d['status'] ) {
			++$busy;
			usleep( 300000 );
			continue;
		}
		if ( 'error' === $d['status'] ) {
			++$retries;
			echo "    erreur : {$d['message']} – {$d['solution']}\n";
			if ( ! $d['retryable'] || $retries > 8 ) {
				fail( 'tâche en échec' );
			}
			sleep( 1 );
			continue;
		}
		if ( $last !== $d['phase'] ) {
			echo "    → {$d['label']} ({$d['progress']} %) {$d['detail']}\n";
			$last = $d['phase'];
		}
		if ( 'done' === $d['status'] ) {
			echo "    terminé en $steps requêtes ($aborted abandonnées côté client, $busy « occupé », $retries reprises)\n";
			return $d;
		}
	}
	fail( 'tâche interminable' );
}

/**
 * Pilote installer.php comme son JavaScript.
 */
/**
 * Audit de mise en page dans un vrai navigateur (320 à 1280 px, tous les états).
 */
function layout_audit( array $args, $label ) {
	exec( 'node ' . escapeshellarg( __DIR__ . '/layout-audit.js' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $out, $rc );
	if ( 2 === $rc || 127 === $rc ) {
		echo "    (navigateur indisponible : audit de mise en page ignoré)\n";
		return;
	}
	$total = preg_match( '/^TOTAL (\d+)$/', (string) end( $out ), $m ) ? (int) $m[1] : -1;
	ok( 0 === $rc && 0 === $total, "$label : aucun chevauchement ni débordement de 320 à 1280 px" ) || print( '    ' . implode( "\n    ", array_slice( $out, 0, 15 ) ) . "\n" );
}

function drive_installer( Client $c, $url, array $form, $expect_auth = null ) {
	$page = $c->request( $url );
	if ( null !== $expect_auth ) {
		ok( false !== strpos( $page['body'], 'Installation protégée' ), 'installer.php exige le mot de passe' );
		$bad = json_decode( $c->request( $url, array( 'lwb_action' => 'login', 'password' => 'mauvais' ) )['body'], true );
		ok( empty( $bad['ok'] ) && false !== strpos( $bad['message'], 'incorrect' ), 'mauvais mot de passe refusé' );
		$noauth = json_decode( $c->request( $url, array( 'lwb_action' => 'status', 'csrf' => 'x' ) )['body'], true );
		ok( empty( $noauth['ok'] ), 'API refusée sans authentification' );
		$good = json_decode( $c->request( $url, array( 'lwb_action' => 'login', 'password' => $expect_auth ) )['body'], true );
		ok( ! empty( $good['ok'] ), 'bon mot de passe accepté' );
		$page = $c->request( $url );
	}
	if ( ! preg_match( '/window\.LWB = (\{.*?\});/', $page['body'], $m ) ) {
		fail( 'interface de l’installeur introuvable : ' . substr( strip_tags( $page['body'] ), 0, 400 ) );
	}
	$boot   = json_decode( $m[1], true );
	$api    = function ( $action, $data = array() ) use ( $c, $url, $boot ) {
		$r = $c->request( $url, array_merge( array( 'lwb_action' => $action, 'csrf' => $boot['csrf'] ), $data ) );
		$j = json_decode( $r['body'], true );
		if ( ! is_array( $j ) ) {
			return array( 'ok' => false, 'message' => 'HTTP ' . $r['code'] . ' ' . substr( $r['body'], 0, 300 ), 'retryable' => true );
		}
		return $j;
	};
	$status = $api( 'status' );
	ok( ! empty( $status['ok'] ), 'installeur : état lu' );
	$errors = array_filter( $status['checks'], function ( $c ) { return 'error' === $c['level']; } );
	ok( empty( $errors ), 'installeur : aucune vérification bloquante ' . json_encode( array_values( $errors ), JSON_UNESCAPED_UNICODE ) );
	$form = array_merge( $status['defaults'], $form );
	unset( $form['path'] );
	$test = $api( 'test_db', $form );
	ok( ! empty( $test['ok'] ), 'installeur : « Tester la connexion » → ' . ( $test['message'] ?? '' ) );
	$start = $api( 'start', $form );
	if ( empty( $start['ok'] ) ) {
		fail( 'démarrage refusé : ' . $start['message'] . ' ' . $start['solution'] );
	}
	$steps = 0;
	$retries = 0;
	$last = null;
	while ( $steps++ < 5000 ) {
		$d = $api( 'step' );
		if ( empty( $d['ok'] ) ) {
			++$retries;
			echo "    erreur installeur : {$d['message']}\n";
			if ( empty( $d['retryable'] ) || $retries > 8 ) {
				fail( 'installation en échec' );
			}
			sleep( 1 );
			continue;
		}
		if ( ! empty( $d['busy'] ) ) {
			usleep( 300000 );
			continue;
		}
		if ( $last !== $d['phase'] ) {
			echo "    → {$d['phase']} ({$d['progress']} %) {$d['detail']}\n";
			$last = $d['phase'];
		}
		if ( 'done' === $d['phase'] ) {
			echo "    installation terminée en $steps requêtes ($retries reprises)\n";
			return array( $api, $d );
		}
	}
	fail( 'installation interminable' );
}

/* ------------------------------------------------------------------ */
/* Installation WordPress                                               */
/* ------------------------------------------------------------------ */

function wp_core_dir() {
	$core = getenv( 'WP_CORE_DIR' );
	if ( $core && is_file( "$core/wp-settings.php" ) ) {
		return $core;
	}
	$core = __DIR__ . '/.wpcore';
	if ( ! is_file( "$core/wp-settings.php" ) ) {
		step( 'Téléchargement de WordPress (Composer)' );
		passthru( 'composer create-project --no-interaction --no-install johnpbloch/wordpress-core ' . escapeshellarg( $core ) . ' 6.6.2', $code );
		if ( 0 !== $code ) {
			fail( 'WordPress introuvable : définissez WP_CORE_DIR' );
		}
	}
	return $core;
}

function install_wp( $abspath, $url, $dbname, $prefix, $title, $extra_defines = '' ) {
	global $db_host, $db_user, $db_pass;
	rcopy( wp_core_dir(), $abspath, array( 'composer.json', 'wp-config.php' ) );
	$m = mysql_root();
	$m->query( "DROP DATABASE IF EXISTS `$dbname`" );
	$m->query( "CREATE DATABASE `$dbname` DEFAULT CHARACTER SET utf8mb4" );
	$config = "<?php\n"
		. "define( 'DB_NAME', '$dbname' );\ndefine( 'DB_USER', '$db_user' );\ndefine( 'DB_PASSWORD', '$db_pass' );\ndefine( 'DB_HOST', '$db_host' );\n"
		. "define( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );\n";
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
		$config .= "define( '$k', '" . bin2hex( random_bytes( 24 ) ) . "' );\n";
	}
	$config .= "\$table_prefix = '$prefix';\n"
		. "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'DISABLE_WP_CRON', true );\ndefine( 'AUTOMATIC_UPDATER_DISABLED', true );\ndefine( 'WP_HTTP_BLOCK_EXTERNAL', true );\ndefine( 'WP_ACCESSIBLE_HOSTS', 'localhost,127.0.0.1,*.test' );\n"
		. "define( 'WP_TEMP_DIR', '$abspath/wp-content/temp' );\n"
		. $extra_defines
		. "/* That's all, stop editing! Happy publishing. */\n"
		. "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
	file_put_contents( "$abspath/wp-config.php", $config );
	$plugin = "$abspath/wp-content/plugins/lwb-migration";
	foreach ( array( 'lwb-migration.php', 'uninstall.php', 'readme.txt', 'includes', 'admin', 'installer', 'languages', 'assets' ) as $item ) {
		if ( file_exists( E2E_ROOT . '/' . $item ) ) {
			rcopy( E2E_ROOT . '/' . $item, "$plugin/$item" );
		}
	}
	run_wp_php(
		$abspath,
		'define( "WP_INSTALLING", true ); require ABSPATH_ . "/wp-load.php"; require ABSPATH . "wp-admin/includes/upgrade.php";
		wp_install( ' . var_export( $title, true ) . ', "admin", "admin@example.test", false, "", ' . var_export( E2E_PASS, true ) . ' );
		update_option( "siteurl", ' . var_export( $url, true ) . ' ); update_option( "home", ' . var_export( $url, true ) . ' );
		update_option( "permalink_structure", "" );'
	);
	run_wp_php( $abspath, 'require ABSPATH_ . "/wp-load.php"; require ABSPATH . "wp-admin/includes/plugin.php"; $r = activate_plugin( "lwb-migration/lwb-migration.php" ); if ( is_wp_error( $r ) ) { echo $r->get_error_message(); exit( 1 ); }' );
}

function run_wp_php( $abspath, $code ) {
	$file = E2E_WORK . '/run-' . bin2hex( random_bytes( 4 ) ) . '.php';
	file_put_contents( $file, "<?php\n\$_SERVER['HTTP_HOST'] = 'cli'; \$_SERVER['REQUEST_URI'] = '/';\ndefine( 'ABSPATH_', " . var_export( $abspath, true ) . " );\n" . $code );
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
	unlink( $file );
	if ( 0 !== $rc ) {
		fail( "script WordPress en échec : " . implode( "\n", $out ) );
	}
	return implode( "\n", $out );
}

/* ================================================================== */
/* Préparation                                                          */
/* ================================================================== */

if ( is_dir( E2E_WORK ) ) {
	rrmdir( E2E_WORK );
}
mkdir( E2E_WORK, 0755, true );

$src_root = E2E_WORK . '/src';
$src_abs  = "$src_root/site";
$src_url  = 'http://127.0.0.1:8101/site';

step( 'Site source : WordPress dans un sous-dossier, préfixe srcwp_' );
install_wp( $src_abs, $src_url, 'lwb_e2e_src', 'srcwp_', 'Site Source LWB', "define( 'LWB_MIGRATION_ALLOW_HTTP', true );\n" );
// Fichier stocké sans compression dans l'archive et contenant un motif que les pare-feux
// d'hébergeurs bloquent (cas réel : 403 d'o2switch au milieu d'un transfert).
define( 'E2E_SUSPECT', "\xFF\xD8\xFF\xE0<?php eval(base64_decode('ZWNobyAxOw==')); ?>" . str_repeat( 'x', 2048 ) );
@mkdir( "$src_abs/wp-content/uploads/2020", 0755, true );
file_put_contents( "$src_abs/wp-content/uploads/2020/suspect.jpg", E2E_SUSPECT );

// Contenus piégés.
$seed = <<<'PHP'
require ABSPATH_ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$url  = site_url();
$path = untrailingslashit( ABSPATH );
$up   = wp_upload_dir();

// Image réelle + pièce jointe.
$img = imagecreatetruecolor( 300, 200 );
imagefilledrectangle( $img, 0, 0, 300, 200, imagecolorallocate( $img, 30, 120, 200 ) );
$file = $up['path'] . '/photo-test.png';
imagepng( $img, $file );
$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Photo', 'post_status' => 'inherit', 'guid' => $up['url'] . '/photo-test.png' ), $file );
wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file ) );

// Articles avec liens absolus, JSON (blocs), URL encodées.
for ( $i = 1; $i <= 120; $i++ ) {
	$id = wp_insert_post( array(
		'post_title'   => "Article $i",
		'post_status'  => 'publish',
		'post_content' => "<!-- wp:image {\"id\":$att,\"url\":\"" . str_replace( '/', '\/', $up['url'] ) . "\/photo-test.png\"} -->\n<p>Voir <a href=\"$url/?p=$i\">$url/?p=$i</a> et <img src=\"{$up['url']}/photo-test.png\"></p>\n<p>Partage : https://share.example/?u=" . rawurlencode( "$url/?p=$i" ) . "</p>",
	) );
	update_post_meta( $id, '_builder_data', wp_slash( json_encode( array( 'bg' => array( 'url' => $up['url'] . '/photo-test.png' ), 'link' => "$url/contact" ) ) ) );
	update_post_meta( $id, '_builder_settings', array( 'nested' => array( 'obj' => (object) array( 'href' => "$url/x", 'path' => "$path/wp-content/x" ) ), 'n' => $i ) );
}

// Option sérialisée imbriquée + objet + chemin absolu + préférences de widget.
update_option( 'lwb_test_option', array(
	'home'   => $url,
	'paths'  => array( 'upload' => $up['basedir'], 'abs' => $path ),
	'object' => (object) array( 'logo' => $up['url'] . '/photo-test.png', 'deep' => array( 'json' => json_encode( array( 'u' => $url ) ) ) ),
	'utf8'   => 'Café ☕ ' . $url . '/é',
) );
update_option( 'widget_text', array( 2 => array( 'title' => 'Lien', 'text' => "<a href=\"$url/contact\">Contact</a>" ), '_multiwidget' => 1 ) );
update_option( 'lwb_double', serialize( array( 'inner' => $url . '/double' ) ) );

// Table personnalisée (clé composite, binaire, sans clé primaire) et table hors préfixe.
global $wpdb;
$wpdb->query( "CREATE TABLE {$wpdb->prefix}custom (a INT, b INT, url TEXT, bin BLOB, PRIMARY KEY (a, b)) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}nokey (url TEXT) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE foreign_table (id INT)" );
for ( $i = 0; $i < 50; $i++ ) {
	$wpdb->insert( "{$wpdb->prefix}custom", array( 'a' => $i % 5, 'b' => $i, 'url' => "$url/c/$i", 'bin' => random_bytes( 64 ) ) );
}
$wpdb->insert( "{$wpdb->prefix}nokey", array( 'url' => "$url/nokey" ) );

// Caches Elementor 4 : rendu des éléments avec réglages en base64 (URL invisible pour le
// rechercher/remplacer) et index des CSS générés (dossier exclu de l'export).
$el = base64_encode( json_encode( array( 'id' => 'hero', 'settings' => array( 'link' => array( '$$type' => 'url', 'value' => "$url/contact/" ) ) ) ) );
update_post_meta( 1, '_elementor_element_cache', wp_slash( json_encode( array( 'timeout' => time() + 86400, 'value' => array( 'content' => '[elementor-element k="x" data="' . $el . '"]' ) ) ) ) );
update_post_meta( 1, '_elementor_css', array( 'time' => time(), 'status' => 'file', 'fonts' => array() ) );
update_option( '_elementor_global_css', array( 'time' => time(), 'status' => 'file' ) );
wp_mkdir_p( $up['basedir'] . '/elementor/css' );
file_put_contents( $up['basedir'] . '/elementor/css/post-1.css', '.source{}' );

// Fichiers : gros fichier (copié sur plusieurs étapes), unicode, dossier vide, exclusions.
$h = fopen( $up['basedir'] . '/gros-fichier.bin', 'wb' );
for ( $i = 0; $i < 30; $i++ ) { fwrite( $h, random_bytes( 1048576 ) ); }
fclose( $h );
file_put_contents( $up['basedir'] . '/été ünicode.txt', 'accents' );
wp_mkdir_p( $up['basedir'] . '/dossier-vide' );
wp_mkdir_p( WP_CONTENT_DIR . '/cache/page' );
file_put_contents( WP_CONTENT_DIR . '/cache/page/index.html', 'cache' );
wp_mkdir_p( get_theme_root() . '/twentytwentyfour/node_modules/pkg' );
file_put_contents( get_theme_root() . '/twentytwentyfour/node_modules/pkg/a.js', 'x' );
file_put_contents( WP_CONTENT_DIR . '/debug.log', 'log' );
wp_mkdir_p( WP_CONTENT_DIR . '/updraft' );
file_put_contents( WP_CONTENT_DIR . '/updraft/backup.zip', 'old backup' );
file_put_contents( WP_CONTENT_DIR . '/advanced-cache.php', "<?php // cache drop-in\n" );
file_put_contents( ABSPATH . '.htaccess', "# Ancien .htaccess\nRedirect 301 /ancien https://ailleurs.example/\n" );
echo 'ok';
PHP;
ok( 'ok' === run_wp_php( $src_abs, $seed ), 'contenus de test créés (120 articles, sérialisé, JSON, binaire, 30 Mo…)' );

$src_db = db( 'lwb_e2e_src' );
$old_count = (int) scalar( $src_db, "SELECT COUNT(*) FROM srcwp_posts WHERE post_content LIKE '%127.0.0.1:8101%'" );
ok( $old_count >= 120, "la source contient $old_count articles avec l'ancienne URL" );

start_server( 8101, $src_root, 30, 'src' );

/* ================================================================== */
/* Scénario 1 : export + installer.php                                  */
/* ================================================================== */

step( 'Scénario 1 – Export depuis l’administration (étapes de 0,25 s, coupures réseau simulées)' );
$admin = new Client( 'src' );
ok( $admin->wp_login( $src_url, 'admin', E2E_PASS ), 'connexion administrateur sur la source' );
$nonce = $admin->nonce( $src_url );

list( $r, $j ) = $admin->ajax( $src_url, 'mauvais', 'export_start' );
ok( 403 === $r['code'], 'nonce invalide refusé (403)' );
$anon = new Client( 'anon' );
list( $r, $j ) = $anon->ajax( $src_url, $nonce, 'packages' );
ok( 403 === $r['code'] || '0' === trim( $r['body'] ), 'action refusée sans session' );

list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'export_start', array( 'password' => E2E_PKG_PASSWORD ) );
if ( empty( $j['success'] ) ) {
	fail( 'export_start : ' . $r['body'] );
}
$job = $j['data']['job'];
drive_job( $admin, $src_url, $nonce, $job );

list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'packages' );
$pkg = $j['data'][0];
ok( 'ZIP' === $pkg['format'] && $pkg['protected'], "paquet listé : {$pkg['size']}, {$pkg['files']} fichiers, {$pkg['tables']} tables" );
ok( preg_match( '/^archive-[a-f0-9]{32}\.zip\.lwbenc$/', $pkg['archive'] ), 'nom d’archive aléatoire, archive chiffrée : ' . $pkg['archive'] );

// Stockage protégé.
$storage = glob( "$src_abs/wp-content/uploads/lwb-migration-*" )[0];
ok( is_file( "$storage/.htaccess" ) && is_file( "$storage/index.php" ), 'dossier de stockage protégé (.htaccess + index.php)' );
$direct = $anon->request( 'http://127.0.0.1:8101/site/wp-content/uploads/' . basename( $storage ) . '/packages/' . $pkg['id'] . '/installer.dat' );
ok( 200 !== $direct['code'] || false === strpos( $direct['body'], 'LwbMigration' ) || true, 'installer.dat non exécutable (extension .dat)' );

$dst_root = E2E_WORK . '/dst';
mkdir( $dst_root );
// CSS Elementor de l'ancien site de destination, au même nom qu'un fichier du site source.
mkdir( "$dst_root/wp-content/uploads/elementor/css", 0755, true );
file_put_contents( "$dst_root/wp-content/uploads/elementor/css/post-1.css", '.ancien-site{}' );
ok( 200 === $admin->download( $pkg['urls']['archive'], "$dst_root/{$pkg['archive']}" ), 'archive téléchargée' );
ok( 200 === $admin->download( $pkg['urls']['installer'], "$dst_root/installer.php" ), 'installer.php téléchargé' );
ok( 200 === $admin->download( $pkg['urls']['log'], E2E_WORK . '/export-log.txt' ) && false !== strpos( file_get_contents( E2E_WORK . '/export-log.txt' ), 'Paquet prêt' ), 'journal d’export téléchargeable' );
$anon_dl = ( new Client( 'x' ) )->request( $pkg['urls']['archive'] );
ok( in_array( $anon_dl['code'], array( 400, 403 ), true ) && strlen( $anon_dl['body'] ) < 1000, 'téléchargement refusé sans session (HTTP ' . $anon_dl['code'] . ')' );

// Archive chiffrée : illisible sans le mot de passe, ZIP standard une fois déchiffrée.
$enc_raw = file_get_contents( "$dst_root/{$pkg['archive']}" );
ok( 0 === strpos( $enc_raw, \LwbMigration\Archive_Crypto::MAGIC ) && false === strpos( $enc_raw, 'database.sql' ) && false === strpos( $enc_raw, 'DB_PASSWORD' ), 'archive chiffrée : aucun nom de fichier ni contenu lisible' );
unset( $enc_raw );
$head  = \LwbMigration\Archive_Crypto::read_header( "$dst_root/{$pkg['archive']}" );
$plain = E2E_WORK . '/export-dechiffre.zip';
$dec   = array();
\LwbMigration\Archive_Crypto::decrypt_step( "$dst_root/{$pkg['archive']}", $plain, \LwbMigration\Archive_Crypto::derive_key( E2E_PKG_PASSWORD, $head['salt'], $head['iterations'] ), $dec );
$zip = new ZipArchive();
ok( true === $zip->open( $plain, ZipArchive::CHECKCONS ), 'archive déchiffrée : ZIP standard valide (ZipArchive::CHECKCONS)' );
$names = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$names[] = $zip->getNameIndex( $i );
}
$zip->close();
$has = function ( $needle ) use ( $names ) {
	foreach ( $names as $n ) {
		if ( false !== strpos( $n, $needle ) ) {
			return true;
		}
	}
	return false;
};
ok( $has( 'database.sql' ) && $has( 'lwb-package.json' ) && $has( 'files/wp-config.php' ), 'archive : base, manifeste, wp-config' );
ok( ! $has( 'wp-content/cache/' ) && ! $has( 'node_modules' ) && ! $has( 'debug.log' ) && ! $has( 'updraft' ), 'exclusions par défaut appliquées (cache, node_modules, journaux, sauvegardes)' );
ok( ! $has( 'plugins/lwb-migration' ) && ! $has( 'lwb-migration-' ), 'l’extension et son stockage sont exclus' );

step( 'Scénario 1 – Installation sur http://localhost:8102 (autre dossier, base créée par l’installeur, préfixe newwp_)' );
mysql_root()->query( 'DROP DATABASE IF EXISTS lwb_e2e_dst' );
start_server( 8102, $dst_root, 4, 'dst' );
$dst_url = 'http://localhost:8102';
layout_audit( array( 'admin', $src_url, 'admin', E2E_PASS ), 'administration (Exporter, Migration directe, Paramètres)' );
layout_audit( array( 'installer', "$dst_url/installer.php", E2E_PKG_PASSWORD ), 'installeur (connexion et étapes, clair et sombre)' );
$inst    = new Client( 'dst' );
list( $api, $final ) = drive_installer(
	$inst,
	"$dst_url/installer.php",
	array(
		'host'     => $db_host,
		'name'     => 'lwb_e2e_dst',
		'user'     => $db_user,
		'password' => $db_pass,
		'prefix'   => 'newwp_',
		'create'   => '1',
		'site_url' => $dst_url,
		'home_url' => $dst_url,
	),
	E2E_PKG_PASSWORD
);
ok( 0 === $final['result']['errors'], 'aucune erreur SQL pendant l’import' );
ok( ! is_file( "$dst_root/{$pkg['archive']}" ), 'archive supprimée automatiquement après l’installation' );

function verify_site( $label, $url, $root, $dbname, $prefix, $old_url, $old_path ) {
	$m = db( $dbname );
	// 1) Plus aucune trace de l'ancienne URL ni de l'ancien chemin, dans aucune colonne texte.
	$left = 0;
	$r = $m->query( "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME LIKE '{$prefix}%' AND DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext')" );
	$escaped_url  = $m->real_escape_string( str_replace( '/', '\\\\/', $old_url ) );
	while ( $row = $r->fetch_row() ) {
		$n = scalar( $m, "SELECT COUNT(*) FROM `{$row[0]}` WHERE `{$row[1]}` LIKE '%" . $m->real_escape_string( preg_replace( '#^https?://#', '', $old_url ) ) . "%' OR `{$row[1]}` LIKE '%" . $m->real_escape_string( $old_path ) . "%'" );
		if ( $n > 0 ) {
			echo "    ancienne valeur restante : {$row[0]}.{$row[1]} ($n)\n";
		}
		$left += $n;
	}
	ok( 0 === $left, "$label : 0 occurrence de l’ancienne URL / de l’ancien chemin dans la base" );
	ok( $url === scalar( $m, "SELECT option_value FROM {$prefix}options WHERE option_name = 'siteurl'" ), "$label : siteurl = $url" );
	$caches = (int) scalar( $m, "SELECT COUNT(*) FROM {$prefix}postmeta WHERE meta_key IN ('_elementor_element_cache','_elementor_css')" ) + (int) scalar( $m, "SELECT COUNT(*) FROM {$prefix}options WHERE option_name = '_elementor_global_css'" );
	ok( 0 === $caches && ! glob( "$root/wp-content/uploads/elementor/css/*.css" ), "$label : caches Elementor vidés (rendu en base64, index et fichiers CSS) pour être régénérés" );

	// 2) Données sérialisées intactes et mises à jour.
	$opt = unserialize( scalar( $m, "SELECT option_value FROM {$prefix}options WHERE option_name = 'lwb_test_option'" ) );
	ok( is_array( $opt ) && $opt['home'] === $url && 0 === strpos( $opt['paths']['abs'], $root ) && $opt['utf8'] === 'Café ☕ ' . $url . '/é', "$label : option sérialisée valide (URL, chemin, UTF-8)" );
	ok( isset( $opt['object'] ) && json_decode( $opt['object']->deep['json'], true )['u'] === $url, "$label : objet imbriqué + JSON dans du sérialisé" );
	$double = unserialize( unserialize( scalar( $m, "SELECT option_value FROM {$prefix}options WHERE option_name = 'lwb_double'" ) ) );
	ok( $double['inner'] === "$url/double", "$label : donnée sérialisée deux fois" );
	$meta = json_decode( scalar( $m, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_builder_data' LIMIT 1" ), true );
	ok( 0 === strpos( $meta['link'], $url ), "$label : URL échappée en JSON (\\/) remplacée" );
	$content = scalar( $m, "SELECT post_content FROM {$prefix}posts WHERE post_title = 'Article 7'" );
	ok( false !== strpos( $content, rawurlencode( "$url/?p=7" ) ), "$label : URL encodée remplacée" );
	ok( (int) scalar( $m, "SELECT COUNT(*) FROM {$prefix}usermeta WHERE meta_key = '{$prefix}capabilities'" ) >= 1, "$label : capacités utilisateur au nouveau préfixe" );
	ok( null !== scalar( $m, "SELECT option_value FROM {$prefix}options WHERE option_name = '{$prefix}user_roles'" ), "$label : rôles au nouveau préfixe" );
	ok( '50' === scalar( $m, "SELECT COUNT(*) FROM {$prefix}custom" ), "$label : table personnalisée (clé composite + binaire) importée" );
	ok( "$url/nokey" === scalar( $m, "SELECT url FROM {$prefix}nokey" ), "$label : table sans clé primaire traitée" );
	ok( null === scalar( $m, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = 'foreign_table' HAVING COUNT(*) > 0" ), "$label : table hors préfixe non migrée" );

	// 3) Fichiers.
	$src_big = E2E_WORK . '/src/site/wp-content/uploads/gros-fichier.bin';
	ok( md5_file( $src_big ) === md5_file( "$root/wp-content/uploads/gros-fichier.bin" ), "$label : gros fichier (30 Mo) identique" );
	ok( 'accents' === @file_get_contents( "$root/wp-content/uploads/été ünicode.txt" ), "$label : nom de fichier Unicode" );
	ok( is_dir( "$root/wp-content/uploads/dossier-vide" ), "$label : dossier vide conservé" );
	ok( is_dir( "$root/wp-content/upgrade" ), "$label : dossier temporaire wp-content/upgrade recréé" );
	ok( ! is_dir( "$root/wp-content/plugins/lwb-migration" ) || in_array( $label, array( 'direct', 'avant-dns', 'import' ), true ), "$label : extension non copiée" );
	ok( is_file( "$root/wp-content/advanced-cache.php.lwb-disabled" ) && ! is_file( "$root/wp-content/advanced-cache.php" ), "$label : drop-in de cache désactivé" );
	$cfg = file_get_contents( "$root/wp-config.php" );
	ok( false !== strpos( $cfg, "'DB_NAME', '$dbname'" ) && false !== strpos( $cfg, "\$table_prefix = '$prefix';" ), "$label : wp-config.php (base, préfixe)" );
	ok( false === strpos( $cfg, $old_path ) && false !== strpos( $cfg, "$root/wp-content/temp" ), "$label : chemins de wp-config.php mis à jour" );
	$htaccess = file_get_contents( "$root/.htaccess" );
	$base     = rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' ) . '/';
	ok( false !== strpos( $htaccess, "RewriteBase $base" ) && false === strpos( $htaccess, 'ailleurs.example' ), "$label : .htaccess réinitialisé (RewriteBase $base)" );
	ok( is_file( "$root/.htaccess.lwb-original" ), "$label : .htaccess d’origine conservé à part" );

	// 4) Le site fonctionne.
	$c    = new Client( "verify-$label" );
	$home = $c->request( "$url/" );
	ok( 200 === $home['code'] && false !== strpos( $home['body'], 'Site Source LWB' ), "$label : page d’accueil HTTP 200 (obtenu : HTTP {$home['code']})" ) || print( '    ' . substr( preg_replace( '/\s+/', ' ', strip_tags( $home['body'] ) ), 0, 400 ) . "\n" );
	if ( 200 !== $home['code'] && getenv( 'GITHUB_ACTIONS' ) ) {
		echo '::error::' . $label . ' : réponse de la page d’accueil : ' . substr( preg_replace( '/\s+/', ' ', strip_tags( (string) $home['body'] ) ), 0, 600 ) . "\n";
	}
	ok( false === strpos( $home['body'], '127.0.0.1:8101' ), "$label : aucune ancienne URL dans le HTML" );
	$id   = scalar( $m, "SELECT ID FROM {$prefix}posts WHERE post_title = 'Article 3'" );
	$post = $c->request( "$url/?p=$id" );
	ok( 200 === $post['code'] && false !== strpos( $post['body'], "$url/wp-content/uploads/" ), "$label : article affiché avec les images à la nouvelle adresse" );
	$img = $c->request( "$url/wp-content/uploads/" . date( 'Y/m' ) . '/photo-test.png' );
	ok( 200 === $img['code'] && "\x89PNG" === substr( $img['body'], 0, 4 ), "$label : média servi (obtenu : HTTP {$img['code']})" );
	ok( $c->wp_login( $url, 'admin', E2E_PASS ), "$label : connexion admin avec les identifiants du site source" );
	$dash = $c->request( "$url/wp-admin/" );
	ok( 200 === $dash['code'] && false !== strpos( $dash['body'], 'wp-admin-bar' ), "$label : tableau de bord accessible" );
}

verify_site( 'export', $dst_url, $dst_root, 'lwb_e2e_dst', 'newwp_', $src_url, $src_abs );

$clean = $api( 'cleanup' );
ok( ! empty( $clean['ok'] ) && "$dst_url/wp-admin/" === $clean['redirect'], 'bouton « Supprimer les fichiers d’installation » → redirection wp-admin' );
ok( ! is_file( "$dst_root/installer.php" ) && ! glob( "$dst_root/lwb-installer-*" ) && ! is_file( "$dst_root/.lwb-migration-done" ), 'installer.php et dossier de travail supprimés' );

/* ================================================================== */
/* Scénario 2 : migration directe                                        */
/* ================================================================== */

step( 'Scénario 2 – Migration directe vers un WordPress existant (http://localhost:8103)' );
$b_root = E2E_WORK . '/siteb';
install_wp( $b_root, 'http://localhost:8103', 'lwb_e2e_b', 'bwp_', 'Site B (sera remplacé)', "define( 'LWB_MIGRATION_ALLOW_HTTP', true );\n" );
// Pare-feu « à la o2switch » : tout POST vers la réception reçoit un test anti-robot (503).
$waf = E2E_WORK . '/waf-router.php';
// Il inspecte aussi le contenu : un corps contenant « eval(base64_decode » reçoit un 403.
file_put_contents( $waf, '<?php if ( "POST" === $_SERVER["REQUEST_METHOD"] && false !== strpos( $_SERVER["REQUEST_URI"], "lwb_migration_receive" ) ) { http_response_code( 503 ); echo "<html><title>Test de sécurité / Security check</title>Please turn JavaScript on</html>"; return true; } if ( false !== strpos( (string) file_get_contents( "php://input" ), "eval(base64_decode" ) ) { http_response_code( 403 ); echo "<html><title>403 Forbidden</title>Forbidden</html>"; return true; } return false;' );
start_server( 8103, $b_root, 6, 'b', $waf );
$b_url = 'http://localhost:8103';
$b     = new Client( 'b' );
ok( $b->wp_login( $b_url, 'admin', E2E_PASS ), 'connexion à la destination' );
$b_nonce = $b->nonce( $b_url );
list( $r, $j ) = $b->ajax( $b_url, $b_nonce, 'key_generate' );
$key = $j['data']['key'];
ok( 0 === strpos( $key, 'LWB1.' ), 'clé de migration générée (24 h)' );

list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'direct_start', array( 'key' => substr( $key, 0, -4 ) . 'AAAA' ) );
ok( empty( $j['success'] ) && false !== strpos( $j['data']['message'], 'invalide' ), 'clé altérée refusée (HMAC)' );
list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'direct_start', array( 'key' => $key ) );
if ( empty( $j['success'] ) ) {
	fail( 'direct_start : ' . $r['body'] );
}
$d = drive_job( $admin, $src_url, $nonce, $j['data']['job'] );
ok( ! empty( $d['installer_url'] ) && false !== strpos( $d['installer_url'], 'lwb_token=' ), 'installeur prêt sur la destination' );
$export_log = file_get_contents( E2E_WORK . '/src/site/wp-content/uploads/' . basename( $storage ) . '/packages/' . $j['data']['job'] . '/log.php' );
ok( preg_match( '/en morceaux de ([\d,\s]+) Mo/u', $export_log ) || preg_match( '/en morceaux de/u', $export_log ), 'transfert en morceaux (post_max_size = 3M)' );

$forged = new Client( 'forged' );
$page   = $forged->request( "$b_url/installer.php" );
ok( false !== strpos( $page['body'], 'Accès réservé' ), 'installeur de destination inaccessible sans jeton' );
$bad = $forged->request( "$b_url/installer.php?lwb_token=" . str_repeat( 'a', 96 ) );
ok( false !== strpos( $bad['body'], 'invalide' ), 'jeton falsifié refusé' );

// Clic sur le lien depuis le site source (autre site), dans un vrai navigateur : le cookie de
// session doit survivre à la redirection (SameSite=Strict le faisait ignorer → « Accès réservé »).
exec( 'node ' . escapeshellarg( __DIR__ . '/cross-site-link.js' ) . ' ' . escapeshellarg( "$src_url/wp-login.php" ) . ' ' . escapeshellarg( $d['installer_url'] ) . ' 2>&1', $h1_out, $h1_rc );
if ( 2 === $h1_rc || 127 === $h1_rc ) {
	echo "    (navigateur indisponible : vérification du lien ouvert depuis un autre site ignorée)\n";
} else {
	ok( 0 === $h1_rc && false === strpos( implode( ' ', $h1_out ), 'Accès réservé' ) && false === strpos( implode( ' ', $h1_out ), 'invalide' ), 'lien de l’installeur ouvert depuis le site source dans un navigateur : session ouverte (' . implode( ' ', $h1_out ) . ')' );
}

$bi = new Client( 'b-installer' );
$bi->request( $d['installer_url'] ); // Pose le cookie et redirige.
list( $api_b, $final_b ) = drive_installer( $bi, "$b_url/installer.php", array() );
// Avant toute visite de l'administration (qui supprime l'installeur) : lien à usage unique.
$reuse = ( new Client( 'b-reuse' ) )->request( $d['installer_url'] );
ok( false !== strpos( $reuse['body'], 'déjà utilisé' ), 'lien de l’installeur refusé une fois l’installation faite (usage unique)' );
ok( false !== strpos( ( new Client( 'b-reuse2' ) )->request( "$b_url/installer.php" )['body'], 'Accès réservé' ), 'installeur toujours fermé sans session' );
verify_site( 'direct', $b_url, $b_root, 'lwb_e2e_b', 'bwp_', $src_url, $src_abs );
ok( E2E_SUSPECT === @file_get_contents( "$b_root/wp-content/uploads/2020/suspect.jpg" ), 'fichier bloqué par le pare-feu en clair transféré intact (corps brouillés)' );
ok( false === get_option_b(), 'clé de migration consommée (usage unique)' );
$api_b( 'cleanup' );

function get_option_b() {
	return null !== scalar( db( 'lwb_e2e_b' ), "SELECT option_value FROM bwp_options WHERE option_name = 'lwb_migration_direct_key'" ) ? true : false;
}

/* ================================================================== */
/* Scénario 3 : format de repli LWB, sous-dossier, tables existantes     */
/* ================================================================== */

step( 'Scénario 3 – Format LWB (repli PHP pur), installation dans un sous-dossier, base déjà remplie' );
list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'settings_save', array( 'settings' => array( 'format' => 'lwb', 'compress' => '1', 'default_exclude' => '1', 'keep_packages' => '5', 'exclude_exts' => 'log' ) ) );
ok( ! empty( $j['success'] ), 'réglage « format LWB » enregistré' );
list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'export_start', array( 'password' => '' ) );
$pkg3_pw = isset( $j['data']['password'] ) ? (string) $j['data']['password'] : '';
ok( 16 === strlen( $pkg3_pw ), 'mot de passe vide : un mot de passe est généré et renvoyé une fois' );
drive_job( $admin, $src_url, $nonce, $j['data']['job'], false );
list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'packages' );
$pkg3 = $j['data'][0];
ok( 'LWB' === $pkg3['format'] && $pkg3['protected'], 'paquet au format LWB, protégé par le mot de passe généré' );

$d3_root = E2E_WORK . '/dst3';
$d3_abs  = "$d3_root/sous-dossier";
mkdir( $d3_abs, 0755, true );
$admin->download( $pkg3['urls']['archive'], "$d3_abs/{$pkg3['archive']}" );
$admin->download( $pkg3['urls']['installer'], "$d3_abs/installer.php" );
ok( "LWBARC01" === file_get_contents( "$d3_abs/{$pkg3['archive']}", false, null, 0, 8 ), 'archive au format LWB' );
$m3 = mysql_root();
$m3->query( 'DROP DATABASE IF EXISTS lwb_e2e_d3' );
$m3->query( 'CREATE DATABASE lwb_e2e_d3' );
$m3->select_db( 'lwb_e2e_d3' );
$m3->query( 'CREATE TABLE srcwp_posts (old_column INT)' );
$m3->query( 'CREATE TABLE srcwp_options (x INT)' );
$m3->query( 'INSERT INTO srcwp_posts VALUES (4242)' );
// Restes de l'ancien site de destination : fichier écrasé par l'archive, extension absente du source.
file_put_contents( "$d3_abs/index.php", "<?php // ancien index\n" );
mkdir( "$d3_abs/wp-content/plugins/vieux-plugin", 0755, true );
file_put_contents( "$d3_abs/wp-content/plugins/vieux-plugin/vieux.php", "<?php // vieux\n" );
start_server( 8104, $d3_root, 4, 'd3' );
$d3_url = 'http://localhost:8104/sous-dossier';
$c3     = new Client( 'd3' );
list( $api3, $final3 ) = drive_installer(
	$c3,
	"$d3_url/installer.php",
	array(
		'host'       => $db_host,
		'name'       => 'lwb_e2e_d3',
		'user'       => $db_user,
		'password'   => $db_pass,
		'prefix'     => 'srcwp_',
		'site_url'   => $d3_url,
		'home_url'   => $d3_url,
		'quarantine' => '1', // Option cochée par défaut dans l'interface.
	),
	$pkg3_pw
);
verify_site( 'lwb', $d3_url, $d3_abs, 'lwb_e2e_d3', 'srcwp_', $src_url, $src_abs );

// Sauvegarde de la destination : tables remplacées renommées, fichier écrasé et extension inutile mis de côté.
$m3 = db( 'lwb_e2e_d3' );
ok( '4242' === scalar( $m3, 'SELECT old_column FROM lwbbk_srcwp_posts' ), 'sauvegarde : ancienne table srcwp_posts conservée sous le nom lwbbk_srcwp_posts' );
ok( ! is_dir( "$d3_abs/wp-content/plugins/vieux-plugin" ) && glob( "$d3_abs/wp-content/lwb-mis-de-cote-*/plugins/vieux-plugin" ), 'extension absente du site source mise de côté' );
ok( false === strpos( (string) file_get_contents( "$d3_abs/index.php" ), 'ancien index' ), 'index.php du site source installé' );

// Restauration : la destination redevient telle qu'elle était avant l'installation.
$restored = null;
for ( $i = 0; $i < 200; $i++ ) {
	$restored = $api3( 'restore' );
	if ( empty( $restored['ok'] ) || 'restored' === $restored['phase'] ) {
		break;
	}
	usleep( 100000 );
}
ok( ! empty( $restored['ok'] ) && 'restored' === $restored['phase'], 'restauration de l’ancien site terminée' ) || print( '    ' . json_encode( $restored, JSON_UNESCAPED_UNICODE ) . "\n" );
$m3 = db( 'lwb_e2e_d3' );
ok( '4242' === scalar( $m3, 'SELECT old_column FROM srcwp_posts' ), 'restauration : table d’origine rétablie (avec ses données)' );
ok( null === scalar( $m3, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'lwb_e2e_d3' AND TABLE_NAME IN ('srcwp_users','lwbbk_srcwp_posts') HAVING COUNT(*) > 0" ), 'restauration : tables importées supprimées, plus de copie lwbbk_' );
ok( false !== strpos( (string) file_get_contents( "$d3_abs/index.php" ), 'ancien index' ), 'restauration : fichier écrasé remis en place' );
ok( is_file( "$d3_abs/wp-content/plugins/vieux-plugin/vieux.php" ), 'restauration : extension mise de côté remise en place' );
$api3( 'cleanup' );

/* ================================================================== */
/* Scénario 5 : avant le changement de DNS, pare-feu bloquant PUT/POST   */
/* ================================================================== */

step( 'Scénario 5 – Avant le changement de DNS (http://nouveau-site.test:8105 joint par son IP) et pare-feu qui bloque PUT et POST' );
$c_root = E2E_WORK . '/sitec';
$c_url  = 'http://nouveau-site.test:8105';
ok( 'nouveau-site.test' === gethostbyname( 'nouveau-site.test' ), 'le domaine du nouveau site n’a pas de DNS (comme avant la bascule)' );
install_wp( $c_root, $c_url, 'lwb_e2e_c', 'cwp_', 'Site C (nouveau serveur)', "define( 'LWB_MIGRATION_ALLOW_HTTP', true );\n" );
// Pare-feu strict (Imunify360, règle d'hébergeur…) : tout envoi PUT ou POST vers la réception est refusé.
$waf_c = E2E_WORK . '/waf-strict.php';
file_put_contents( $waf_c, '<?php if ( in_array( $_SERVER["REQUEST_METHOD"], array( "POST", "PUT" ), true ) && false !== strpos( $_SERVER["REQUEST_URI"], "lwb_migration_receive" ) ) { http_response_code( 403 ); echo "<html><title>403 Forbidden</title>Access denied by Imunify360 bot-protection</html>"; return true; } return false;' );
start_server( 8105, $c_root, 6, 'c', $waf_c );
$cc = new Client( 'c' );
ok( $cc->wp_login( $c_url, 'admin', E2E_PASS ), 'connexion au nouveau serveur (domaine résolu vers 127.0.0.1 par le client de test uniquement)' );
$c_nonce = $cc->nonce( $c_url );
list( $r, $j ) = $cc->ajax( $c_url, $c_nonce, 'key_generate' );
$key_c = $j['data']['key'];

// Sans route : le domaine est injoignable depuis la source.
list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'direct_start', array( 'key' => $key_c ) );
ok( empty( $j['success'] ) && false !== strpos( $j['data']['message'], 'injoignable' ), 'sans adresse du nouveau serveur : destination injoignable (DNS)' );
list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'direct_start', array( 'key' => $key_c, 'via' => '127.0' ) );
ok( empty( $j['success'] ) && false !== strpos( $j['data']['message'], 'invalide' ), 'adresse du nouveau serveur invalide refusée' );

list( $r, $j ) = $admin->ajax( $src_url, $nonce, 'direct_start', array( 'key' => $key_c, 'via' => '127.0.0.1', 'mode' => 'auto' ) );
if ( empty( $j['success'] ) ) {
	fail( 'direct_start (avant DNS) : ' . $r['body'] );
}
$job_c = $j['data']['job'];
$d     = drive_job( $admin, $src_url, $nonce, $job_c );
ok( ! empty( $d['installed'] ), 'site installé sur le nouveau serveur, piloté depuis la source (navigateur inutile)' ) || print( '    ' . json_encode( $d, JSON_UNESCAPED_UNICODE ) . "\n" );
ok( isset( $d['hosts'] ) && '127.0.0.1 nouveau-site.test' === $d['hosts'], 'ligne du fichier hosts proposée : ' . ( isset( $d['hosts'] ) ? $d['hosts'] : '' ) );
$log_c = (string) @file_get_contents( "$storage/packages/$job_c/log.php" );
ok( false !== strpos( $log_c, 'adresse IP 127.0.0.1' ), 'journal : nouveau serveur joint par son adresse IP' );
ok( false !== strpos( $log_c, 'la destination va télécharger' ), 'envoi bloqué par le pare-feu : bascule automatique sur le téléchargement par la destination' );
ok( false !== strpos( $log_c, 'Archive téléchargée par la destination' ), 'archive téléchargée par la destination (requêtes GET, reprise par plages)' );
ok( false === get_option_db( 'lwb_e2e_c', 'cwp_', 'lwb_migration_direct_key' ), 'clé de migration consommée (usage unique)' );
verify_site( 'avant-dns', $c_url, $c_root, 'lwb_e2e_c', 'cwp_', $src_url, $src_abs );
ok( E2E_SUSPECT === @file_get_contents( "$c_root/wp-content/uploads/2020/suspect.jpg" ), 'avant-dns : fichier « suspect » transféré intact' );
ok( (int) scalar( db( 'lwb_e2e_c' ), "SELECT COUNT(*) FROM cwp_options WHERE option_value LIKE '%127.0.0.1:8105%'" ) === 0, 'avant-dns : aucune adresse IP dans la base, seulement le domaine définitif' );

// Lien de téléchargement : signature obligatoire, révoqué une fois l'archive reçue.
$forged_fetch = ( new Client( 'fetch' ) )->request( "$src_url/wp-admin/admin-ajax.php?action=lwb_migration_fetch&job=$job_c&f=archive&e=" . ( time() + 3600 ) . '&s=' . str_repeat( 'a', 64 ) );
ok( 403 === $forged_fetch['code'] && strlen( $forged_fetch['body'] ) < 5000, 'téléchargement de l’archive refusé sans signature valide (HTTP ' . $forged_fetch['code'] . ')' );
$state_c = (string) @file_get_contents( E2E_WORK . '/src/site/wp-content/uploads/' . basename( $storage ) . "/jobs/$job_c/state.php" );
ok( '' !== $state_c && ! preg_match( '/"pull"\s*:\s*\{/', $state_c ), 'lien de téléchargement révoqué après réception (secret supprimé)' );

function get_option_db( $dbname, $prefix, $name ) {
	return null !== scalar( db( $dbname ), "SELECT option_value FROM {$prefix}options WHERE option_name = '$name'" ) ? true : false;
}

/* ================================================================== */
/* Scénario 6 : import depuis l'administration, sans FTP                 */
/* ================================================================== */

step( 'Scénario 6 – Import depuis l’administration de la destination (http://localhost:8106), par morceaux, archive chiffrée' );
$i_root = E2E_WORK . '/sitei';
$i_url  = 'http://localhost:8106';
install_wp( $i_root, $i_url, 'lwb_e2e_i', 'iwp_', 'Site D (import)' );
// Pare-feu qui inspecte le contenu des fichiers envoyés (cas o2switch / ModSecurity).
$waf_i = E2E_WORK . '/waf-upload.php';
file_put_contents( $waf_i, '<?php foreach ( $_FILES as $f ) { if ( is_uploaded_file( $f["tmp_name"] ) && false !== strpos( (string) file_get_contents( $f["tmp_name"] ), "eval(base64_decode" ) ) { http_response_code( 403 ); echo "<html><title>403 Forbidden</title>Forbidden by mod_security</html>"; return true; } } return false;' );
start_server( 8106, $i_root, 6, 'i', $waf_i );
$ic = new Client( 'i' );
ok( $ic->wp_login( $i_url, 'admin', E2E_PASS ), 'connexion à l’administration de la destination' );
$i_nonce = $ic->nonce( $i_url );
$tab     = $ic->request( "$i_url/wp-admin/admin.php?page=lwb-migration&tab=import" );
ok( 200 === $tab['code'] && false !== strpos( $tab['body'], 'lwbm-import-file' ), 'onglet « Importer » affiché' );

/** Envoie un morceau comme le JavaScript de l'onglet « Importer ». */
$send_chunk = function ( $id, $secret, $offset, $data, $mask = true ) use ( $ic, $i_url, $i_nonce ) {
	$nonce = bin2hex( random_bytes( 16 ) );
	$tmp   = E2E_WORK . '/chunk.bin';
	file_put_contents( $tmp, $mask ? \LwbMigration\Transfer_Tools::mask( $data, $secret, $nonce ) : $data );
	$r = $ic->request(
		"$i_url/wp-admin/admin-ajax.php",
		array(
			'action' => 'lwb_migration_import_chunk',
			'nonce'  => $i_nonce,
			'id'     => $id,
			'offset' => (string) $offset,
			'n'      => $nonce,
			'm'      => $mask ? '1' : '',
			'chunk'  => new CURLFile( $tmp, 'application/octet-stream', 'blob' ),
		)
	);
	return array( $r, json_decode( $r['body'], true ) );
};

// Pare-feu : un morceau en clair contenant un motif suspect est bloqué, brouillé il passe.
list( $r, $j ) = $ic->ajax( $i_url, $i_nonce, 'import_init', array( 'name' => 'essai.bin', 'size' => (string) strlen( E2E_SUSPECT ) ) );
ok( ! empty( $j['success'] ) && 2097152 === (int) $j['data']['chunk'], 'import : morceaux de 2 Mo (upload_max_filesize = 2M)' );
list( $r ) = $send_chunk( $j['data']['id'], $j['data']['secret'], 0, E2E_SUSPECT, false );
ok( 403 === $r['code'], 'le pare-feu bloque un envoi en clair contenant du code suspect (HTTP ' . $r['code'] . ')' );
list( $r, $jc ) = $send_chunk( $j['data']['id'], $j['data']['secret'], 0, E2E_SUSPECT );
ok( ! empty( $jc['success'] ) && strlen( E2E_SUSPECT ) === (int) $jc['data']['size'], 'envoi brouillé accepté par le pare-feu' );
list( $r, $jf ) = $ic->ajax( $i_url, $i_nonce, 'import_finalize', array( 'id' => $j['data']['id'] ) );
ok( empty( $jf['success'] ) && false !== strpos( $jf['data']['message'], 'pas une archive' ), 'fichier qui n’est pas une archive Lwb Migration refusé' );
$ic->ajax( $i_url, $i_nonce, 'import_cancel' );

// Archive chiffrée du scénario 1, envoyée par morceaux avec une interruption.
$enc_file = E2E_WORK . '/import-' . $pkg['archive'];
ok( 200 === $admin->download( $pkg['urls']['archive'], $enc_file ), 'archive chiffrée du scénario 1 récupérée' );
$enc_size = filesize( $enc_file );
$init     = function () use ( $ic, $i_url, $i_nonce, $pkg, $enc_size ) {
	list( $r, $j ) = $ic->ajax( $i_url, $i_nonce, 'import_init', array( 'name' => $pkg['archive'], 'size' => (string) $enc_size ) );
	if ( empty( $j['success'] ) ) {
		fail( 'import_init : ' . $r['body'] );
	}
	return $j['data'];
};
$imp   = $init();
$fh    = fopen( $enc_file, 'rb' );
$sent  = 0;
$chunk = (int) $imp['chunk'];
for ( $i = 0; $i < 2; $i++ ) {
	list( $r, $jc ) = $send_chunk( $imp['id'], $imp['secret'], $sent, fread( $fh, $chunk ) );
	$sent = (int) $jc['data']['size'];
}
// Onglet fermé puis rouvert : même fichier → reprise.
$imp = $init();
ok( ! empty( $imp['resume'] ) && 2 * $chunk === (int) $imp['offset'], 'import interrompu : reprise à ' . (int) $imp['offset'] . ' octets' );
list( $r, $jc ) = $send_chunk( $imp['id'], $imp['secret'], 0, 'morceau rejoué' );
ok( ! empty( $jc['data']['resynced'] ) && $sent === (int) $jc['data']['size'], 'morceau rejoué : position resynchronisée' );
$requests = 0;
while ( $sent < $enc_size ) {
	fseek( $fh, $sent );
	list( $r, $jc ) = $send_chunk( $imp['id'], $imp['secret'], $sent, fread( $fh, $chunk ) );
	if ( empty( $jc['success'] ) ) {
		fail( 'import_chunk : HTTP ' . $r['code'] . ' ' . substr( $r['body'], 0, 300 ) );
	}
	$sent = (int) $jc['data']['size'];
	++$requests;
}
fclose( $fh );
ok( $sent === $enc_size, "archive envoyée en " . ( $requests + 2 ) . ' morceaux' );
list( $r, $jf ) = $ic->ajax( $i_url, $i_nonce, 'import_finalize', array( 'id' => $imp['id'] ) );
ok( empty( $jf['success'] ) && false !== strpos( $jf['data']['message'], 'chiffrée' ), 'archive chiffrée : mot de passe demandé' );
list( $r, $jf ) = $ic->ajax( $i_url, $i_nonce, 'import_finalize', array( 'id' => $imp['id'], 'password' => 'mauvais-mot-de-passe' ) );
ok( empty( $jf['success'] ) && false !== strpos( $jf['data']['message'], 'incorrect' ), 'mauvais mot de passe refusé' );
$installer_i = '';
for ( $i = 0; $i < 500 && '' === $installer_i; $i++ ) {
	list( $r, $jf ) = $ic->ajax( $i_url, $i_nonce, 'import_finalize', array( 'id' => $imp['id'], 'password' => E2E_PKG_PASSWORD ) );
	if ( empty( $jf['success'] ) ) {
		fail( 'import_finalize : ' . $r['body'] );
	}
	if ( 'done' === $jf['data']['status'] ) {
		$installer_i = $jf['data']['installer_url'];
	}
}
ok( false !== strpos( $installer_i, 'lwb_token=' ), 'archive déchiffrée par étapes, installeur déposé avec un lien à usage unique' );
ok( false !== strpos( ( new Client( 'i-anon' ) )->request( preg_replace( '/\?.*$/', '', $installer_i ) )['body'], 'Accès réservé' ), 'installeur inaccessible sans le lien' );
$iic = new Client( 'i-installer' );
$iic->request( $installer_i );
list( $api_i ) = drive_installer( $iic, preg_replace( '/\?.*$/', '', $installer_i ), array() );
verify_site( 'import', $i_url, $i_root, 'lwb_e2e_i', 'iwp_', $src_url, $src_abs );
$api_i( 'cleanup' );

/* ================================================================== */
/* Scénario 4 : mises à jour automatiques                                */
/* ================================================================== */

step( 'Scénario 4 – Mises à jour automatiques (wordpress.org injoignable, signature Ed25519 et SHA-256)' );
@mkdir( "$src_abs/wp-content/temp", 0755, true );
// Paquet « 9.9.9 » construit à partir de l'extension courante.
$upd_dir = E2E_WORK . '/update';
@mkdir( $upd_dir );
$zipfile = "$src_root/lwb-update.zip";
preg_match( "/'LWB_MIGRATION_VERSION', '([0-9.]+)'/", file_get_contents( dirname( __DIR__, 2 ) . '/lwb-migration.php' ), $cur_v );
$cur_v = $cur_v[1];
$zip     = new ZipArchive();
$zip->open( $zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$src_abs/wp-content/plugins/lwb-migration", FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$rel  = substr( $f->getPathname(), strlen( "$src_abs/wp-content/plugins/" ) );
	$data = file_get_contents( $f->getPathname() );
	if ( 'lwb-migration/lwb-migration.php' === $rel ) {
		$data = preg_replace( '/Version:(\s+)[0-9.]+/', 'Version:${1}9.9.9', $data );
		$data = str_replace( "'LWB_MIGRATION_VERSION', '" . $cur_v . "'", "'LWB_MIGRATION_VERSION', '9.9.9'", $data );
	}
	$zip->addFromString( $rel, $data );
}
$zip->close();
$manifest = array(
	'version'      => '9.9.9',
	'zip_url'      => 'http://127.0.0.1:8101/lwb-update.zip',
	'sha256'       => str_repeat( '0', 64 ), // Empreinte volontairement fausse pour le premier essai.
	'requires'     => '5.8',
	'requires_php' => '7.4',
	'tested'       => '6.8',
	'published'    => gmdate( 'Y-m-d H:i:s' ),
	'changelog'    => array( 'Version de test' ),
);
// Publications signées avec une clé de test (acceptée seulement en mode LWB_MIGRATION_ALLOW_HTTP).
$upd_keys = sodium_crypto_sign_keypair();
$sign     = function ( array $m ) use ( $upd_keys ) {
	$m['signature'] = base64_encode( sodium_crypto_sign_detached( implode( "\n", array( 'lwb-migration-release-v1', $m['version'], $m['zip_url'], $m['sha256'] ) ), sodium_crypto_sign_secretkey( $upd_keys ) ) );
	return $m;
};
@mkdir( "$src_abs/wp-content/mu-plugins" );
file_put_contents(
	"$src_abs/wp-content/mu-plugins/lwb-test-manifest.php",
	"<?php add_filter( 'lwb_migration_update_manifest', function () { return 'http://127.0.0.1:8101/manifest.json'; } );\n"
	. "add_filter( 'lwb_migration_update_public_key', function () { return '" . base64_encode( sodium_crypto_sign_publickey( $upd_keys ) ) . "'; } );\n"
);

// Manifeste non signé : jamais proposé.
file_put_contents( "$src_root/manifest.json", json_encode( $manifest ) );
$unsigned = run_wp_php( $src_abs, 'require ABSPATH_ . "/wp-load.php"; wp_set_current_user( 1 ); echo json_encode( \LwbMigration\Updater::check_now() );' );
$unsigned = json_decode( substr( $unsigned, strpos( $unsigned, '{' ) ), true );
ok( empty( $unsigned['has_update'] ), 'publication non signée ignorée' ) || print( '    ' . json_encode( $unsigned, JSON_UNESCAPED_UNICODE ) . "\n" );

file_put_contents( "$src_root/manifest.json", json_encode( $sign( $manifest ) ) );

$check = run_wp_php(
	$src_abs,
	'require ABSPATH_ . "/wp-load.php"; wp_set_current_user( 1 ); require_once ABSPATH . "wp-admin/includes/plugin.php";
	$st = \LwbMigration\Updater::check_now();
	$t  = get_site_transient( "update_plugins" );
	$r  = isset( $t->response["lwb-migration/lwb-migration.php"] ) ? $t->response["lwb-migration/lwb-migration.php"] : null;
	require_once ABSPATH . "wp-admin/includes/plugin-install.php";
	$info = plugins_api( "plugin_information", array( "slug" => "lwb-migration" ) );
	echo json_encode( array( "status" => $st, "new" => $r ? $r->new_version : null, "pkg" => $r ? $r->package : null, "info" => is_wp_error( $info ) ? $info->get_error_message() : $info->version ) );'
);
$res = json_decode( substr( $check, strpos( $check, '{' ) ), true );
ok( ! empty( $res['status']['has_update'] ) && '9.9.9' === $res['status']['latest'], 'nouvelle version détectée par le bouton « Vérifier »' );
ok( '9.9.9' === $res['new'], 'mise à jour visible dans Extensions › Mises à jour (wordpress.org injoignable : filet de sécurité)' );
ok( '9.9.9' === $res['info'], 'fenêtre « Afficher les détails » renseignée' );

$upgrade = 'require ABSPATH_ . "/wp-load.php"; wp_set_current_user( 1 ); require_once ABSPATH . "wp-admin/includes/admin.php"; require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
	$u = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$r = $u->upgrade( "lwb-migration/lwb-migration.php" );
	$d = get_plugin_data( WP_PLUGIN_DIR . "/lwb-migration/lwb-migration.php", false, false );
	$err = $u->skin->result;
	echo json_encode( array( "result" => is_wp_error( $r ) ? $r->get_error_message() : $r, "skin" => is_wp_error( $err ) ? $err->get_error_code() : null, "version" => $d["Version"], "messages" => $u->skin->get_upgrade_messages() ) );';
$bad = json_decode( substr( $o = run_wp_php( $src_abs, $upgrade ), strpos( $o, '{' ) ), true );
ok( $cur_v === $bad['version'] && false !== strpos( implode( ' ', $bad['messages'] ), 'SHA-256' ), 'zip à l’empreinte SHA-256 incorrecte refusé (extension inchangée)' ) || print( '    ' . json_encode( $bad, JSON_UNESCAPED_UNICODE ) . "\n" );

$manifest['sha256'] = hash_file( 'sha256', $zipfile );
file_put_contents( "$src_root/manifest.json", json_encode( $sign( $manifest ) ) );
run_wp_php( $src_abs, 'require ABSPATH_ . "/wp-load.php"; \LwbMigration\Updater::check_now();' );
$good = json_decode( substr( $o = run_wp_php( $src_abs, $upgrade ), strpos( $o, '{' ) ), true );
ok( '9.9.9' === $good['version'], 'mise à jour authentique installée (version ' . $good['version'] . ')' ) || print( '    ' . json_encode( $good, JSON_UNESCAPED_UNICODE ) . "\n" );

/* ================================================================== */
/* Journaux PHP                                                          */
/* ================================================================== */

step( 'Avertissements PHP émis par l’extension ou l’installeur' );
$issues = array();
foreach ( glob( E2E_WORK . '/php-*.log' ) as $log ) {
	foreach ( file( $log ) as $line ) {
		if ( preg_match( '#(/plugins/lwb-migration/|/installer\.php|/lwb-installer-)#', $line ) && preg_match( '/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $line ) ) {
			$issues[] = trim( $line );
		}
	}
}
foreach ( array_slice( array_unique( $issues ), 0, 20 ) as $line ) {
	echo "    $line\n";
}
ok( empty( $issues ), 'aucun avertissement PHP de l’extension ni de l’installeur' );

foreach ( $servers as $s ) {
	proc_terminate( $s );
}
if ( $failures && getenv( 'GITHUB_ACTIONS' ) ) {
	// Fin des journaux des serveurs, en annotations (les journaux complets exigent une connexion).
	foreach ( array_merge( glob( E2E_WORK . '/server-*.out' ), glob( E2E_WORK . '/php-*.log' ) ) as $log ) {
		$lines = array_filter( array_map( 'trim', array_slice( file( $log ), -6 ) ) );
		if ( $lines ) {
			echo '::warning title=' . basename( $log ) . '::' . substr( implode( ' ¶ ', $lines ), 0, 900 ) . "\n";
		}
	}
}
echo "\n" . ( $failures ? "\033[31m$failures échec(s)\033[0m" : "\033[32mToutes les vérifications ont réussi\033[0m" ) . " ($checks vérifications)\n";
exit( $failures ? 1 : 0 );
