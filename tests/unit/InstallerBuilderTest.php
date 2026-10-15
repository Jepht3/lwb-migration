<?php
/**
 * L'installeur généré doit être un fichier PHP valide, autonome et toujours protégé.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Installer_Builder;

class InstallerBuilderTest extends TestCase {

	const SECRET = 'abababababababababababababababababababababababababababababababab';

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		if ( ! defined( 'LWB_MIGRATION_DIR' ) ) {
			define( 'LWB_MIGRATION_DIR', dirname( __DIR__, 2 ) . '/' );
			define( 'LWB_MIGRATION_VERSION', 'test' );
		}
		if ( ! function_exists( 'LwbMigration\__' ) ) {
			eval( 'namespace LwbMigration; function __( $s ) { return $s; }' );
		}
		require_once LWB_MIGRATION_DIR . 'includes/class-installer-builder.php';
	}

	private function config( array $overrides = array() ) {
		return array_merge(
			array(
				'version'        => 'test',
				'format_version' => 1,
				'package'        => 'x',
				'archive'        => 'archive-absent.zip',
				'archive_size'   => 0,
				'created'        => time(),
				'password_hash'  => password_hash( 'mot-de-passe-test', PASSWORD_DEFAULT ),
				'secret'         => self::SECRET,
				'mode'           => 'manual',
				'token_hash'     => '',
				'source'         => array( 'site_url' => 'http://a.test', 'home_url' => 'http://a.test', 'abspath' => '/x', 'table_prefix' => "wp_'\"$", 'wp_version' => '6', 'php_version' => '8', 'site_name' => 'Nom </script> é' ),
				'target'         => array(),
				'db'             => array(),
			),
			$overrides
		);
	}

	/**
	 * Exécute installer.php hors WordPress, comme le ferait le serveur web.
	 *
	 * @param string $dir    Dossier de l'installeur.
	 * @param array  $server Variables $_SERVER supplémentaires.
	 * @param array  $cookie $_COOKIE.
	 * @param array  $post   $_POST (requête POST si non vide).
	 * @return string Sortie.
	 */
	private function run_installer( $dir, array $server = array(), array $cookie = array(), array $post = array() ) {
		$server = array_merge(
			array(
				'REQUEST_METHOD' => $post ? 'POST' : 'GET',
				'HTTP_HOST'      => 'b.test',
				'SCRIPT_NAME'    => '/installer.php',
				'REMOTE_ADDR'    => '203.0.113.7',
			),
			$server
		);
		$runner = $dir . '/run-' . bin2hex( random_bytes( 4 ) ) . '.php';
		file_put_contents(
			$runner,
			'<?php $_SERVER = array_merge( $_SERVER, ' . var_export( $server, true ) . ' ); $_COOKIE = ' . var_export( $cookie, true ) . '; $_POST = ' . var_export( $post, true ) . '; require __DIR__ . "/installer.php";'
		);
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $out, $rc );
		unlink( $runner );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );
		return implode( "\n", $out );
	}

	private function auth_cookie() {
		$ts = (string) time();
		return $ts . '.' . hash_hmac( 'sha256', 'auth|' . $ts, self::SECRET );
	}

	public function test_generated_installer_is_valid_php_and_asks_for_password() {
		$dir = $this->tmp_dir();
		Installer_Builder::build( $dir . '/installer.php', $this->config() );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $dir . '/installer.php' ) . ' 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );

		$html = $this->run_installer( $dir );
		$this->assertStringContainsString( 'Installation protégée', $html );
		$this->assertStringNotContainsString( 'Vérification de l’environnement', $html );
	}

	public function test_authenticated_session_opens_the_installer() {
		$dir = $this->tmp_dir();
		Installer_Builder::build( $dir . '/installer.php', $this->config() );
		$html = $this->run_installer( $dir, array(), array( 'lwb_installer_auth' => $this->auth_cookie() ) );
		$this->assertStringContainsString( 'Vérification de l’environnement', $html );
		$this->assertStringContainsString( 'Nom &lt;/script&gt; é', $html );
	}

	public function test_installer_without_password_refuses_to_run() {
		$dir = $this->tmp_dir();
		Installer_Builder::build( $dir . '/installer.php', $this->config( array( 'password_hash' => '' ) ) );
		$html = $this->run_installer( $dir );
		$this->assertStringContainsString( 'Installeur non protégé', $html );
		$this->assertStringNotContainsString( 'Vérification de l’environnement', $html );
	}

	public function test_database_password_is_never_sent_to_the_browser() {
		$dir    = $this->tmp_dir();
		$secret = 'Mdp-Base-Tres-Secret-42';
		Installer_Builder::build(
			$dir . '/installer.php',
			$this->config(
				array(
					'db' => array(
						'host'     => 'localhost',
						'name'     => 'base',
						'user'     => 'utilisateur',
						'password' => $secret,
						'prefix'   => 'wp_',
					),
				)
			)
		);
		$cookie = $this->auth_cookie();
		$json   = $this->run_installer(
			$dir,
			array(),
			array( 'lwb_installer_auth' => $cookie ),
			array(
				'lwb_action' => 'status',
				'csrf'       => hash_hmac( 'sha256', 'csrf|' . $cookie, self::SECRET ),
			)
		);
		$data = json_decode( $json, true );
		$this->assertIsArray( $data, $json );
		$this->assertTrue( $data['ok'] );
		$this->assertTrue( $data['password_saved'] );
		$this->assertSame( '', $data['defaults']['password'] );
		$this->assertSame( 'utilisateur', $data['defaults']['user'] );
		$this->assertStringNotContainsString( $secret, $json );
	}

	private function status( $dir, array $server = array() ) {
		$cookie = $this->auth_cookie();
		$json   = $this->run_installer(
			$dir,
			$server,
			array( 'lwb_installer_auth' => $cookie ),
			array(
				'lwb_action' => 'status',
				'csrf'       => hash_hmac( 'sha256', 'csrf|' . $cookie, self::SECRET ),
			)
		);
		$data = json_decode( $json, true );
		$this->assertIsArray( $data, $json );
		return array( $data, $json );
	}

	public function test_env_based_wp_config_is_read_and_kept_by_default() {
		$dir = $this->tmp_dir();
		file_put_contents(
			$dir . '/wp-config.php',
			"<?php\ndefine( 'DB_NAME', getenv( 'DB_NAME' ) );\ndefine( 'DB_USER', getenv( 'DB_USER' ) );\ndefine( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );\ndefine( 'DB_HOST', getenv( 'DB_HOST' ) );\n\$table_prefix = getenv( 'DB_PREFIX' ) ?: 'env_';\nrequire_once ABSPATH . 'wp-settings.php';\n"
		);
		file_put_contents( $dir . '/.env', "DB_NAME=base_env\nDB_USER=user_env\nDB_PASSWORD='Secret-Env-42'\nDB_HOST=db.env:3307\n" );
		Installer_Builder::build( $dir . '/installer.php', $this->config() );

		list( $data, $json ) = $this->status( $dir );
		$this->assertSame( 'base_env', $data['defaults']['name'] );
		$this->assertSame( 'user_env', $data['defaults']['user'] );
		$this->assertSame( 'db.env:3307', $data['defaults']['host'] );
		$this->assertSame( 'env_', $data['defaults']['prefix'] );
		$this->assertTrue( $data['defaults']['keep_config'], 'Configuration par environnement : conservée par défaut.' );
		$this->assertTrue( $data['defaults']['innodb'] );
		$this->assertTrue( $data['config_exists'] );
		$this->assertTrue( $data['password_saved'] );
		$this->assertStringNotContainsString( 'Secret-Env-42', $json );
		$this->assertContains( 'Configuration existante', array_column( $data['checks'], 'label' ) );
	}

	public function test_classic_wp_config_is_not_kept_by_default() {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/wp-config.php', "<?php\ndefine( 'DB_NAME', 'base' );\ndefine( 'DB_USER', 'u' );\ndefine( 'DB_PASSWORD', 'p' );\n\$table_prefix = 'cl_';\n" );
		Installer_Builder::build( $dir . '/installer.php', $this->config() );
		list( $data ) = $this->status( $dir );
		$this->assertSame( 'base', $data['defaults']['name'] );
		$this->assertSame( 'cl_', $data['defaults']['prefix'] );
		$this->assertFalse( $data['defaults']['keep_config'] );
		$this->assertTrue( $data['config_exists'] );
	}

	public function test_proxy_https_and_iis_are_detected() {
		$dir = $this->tmp_dir();
		Installer_Builder::build( $dir . '/installer.php', $this->config() );
		list( $data ) = $this->status(
			$dir,
			array(
				'HTTP_CF_VISITOR' => '{"scheme":"https"}',
				'SERVER_SOFTWARE' => 'Microsoft-IIS/10.0',
			)
		);
		$checks = array_column( $data['checks'], 'value', 'label' );
		$this->assertSame( 'HTTPS (via un proxy)', $checks['Connexion'] );
		$this->assertSame( 'IIS', $checks['Serveur web'] );
		$this->assertStringStartsWith( 'https://b.test', $data['defaults']['site_url'] );
		$this->assertFalse( $data['config_exists'] );
	}

	public function test_wrong_passwords_are_limited_per_ip_address() {
		$dir = $this->tmp_dir();
		Installer_Builder::build( $dir . '/installer.php', $this->config() );
		$attacker = array( 'REMOTE_ADDR' => '198.51.100.66' );
		for ( $i = 0; $i < 10; $i++ ) {
			$res = json_decode( $this->run_installer( $dir, $attacker, array(), array( 'lwb_action' => 'login', 'password' => 'faux-' . $i ) ), true );
			$this->assertSame( 'Mot de passe incorrect.', $res['message'] );
		}
		$res = json_decode( $this->run_installer( $dir, $attacker, array(), array( 'lwb_action' => 'login', 'password' => 'mot-de-passe-test' ) ), true );
		$this->assertStringContainsString( 'Trop de tentatives', $res['message'] );

		// Le propriétaire, depuis une autre adresse, n'est pas bloqué par l'attaquant.
		$res = json_decode( $this->run_installer( $dir, array(), array(), array( 'lwb_action' => 'login', 'password' => 'mot-de-passe-test' ) ), true );
		$this->assertTrue( $res['ok'] );
	}

	public function test_unused_direct_installer_expires_after_24_hours() {
		$dir = $this->tmp_dir();
		Installer_Builder::build(
			$dir . '/installer.php',
			$this->config(
				array(
					'mode'          => 'direct',
					'password_hash' => '',
					'token_hash'    => hash( 'sha256', 'jeton' ),
					'created'       => time() - 90000,
				)
			)
		);
		$html = $this->run_installer( $dir );
		$this->assertStringContainsString( 'Installeur expiré', $html );
		$this->assertFileDoesNotExist( $dir . '/installer.php' );
	}

	public function test_portable_installer_receives_its_configuration_later() {
		$portable = Installer_Builder::render_portable();
		$this->assertTrue( Installer_Builder::is_portable( $portable ) );

		$dir = $this->tmp_dir();
		Installer_Builder::build( $dir . '/installer.php', $this->config(), $portable );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $dir . '/installer.php' ) . ' 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );
		$this->assertFalse( Installer_Builder::is_portable( (string) file_get_contents( $dir . '/installer.php' ) ), 'Configuration injectée : plus de marqueur.' );
		$this->assertStringContainsString( 'Installation protégée', $this->run_installer( $dir ) );
	}

	/**
	 * @dataProvider invalid_portables
	 */
	public function test_invalid_portable_code_is_rejected( $code ) {
		$this->assertFalse( Installer_Builder::is_portable( $code ) );
	}

	public function invalid_portables() {
		return array(
			'vide'                 => array( '' ),
			'code quelconque'      => array( "<?php\necho 'bonjour';" ),
			'sans emplacement'     => array( "<?php\n\\define( 'LWB_MIGRATION_STANDALONE', true );\nnamespace LwbMigration\\Installer;" ),
			'emplacement en double' => array( "<?php\n\\define( 'LWB_MIGRATION_STANDALONE', true );\nnamespace LwbMigration\\Installer;\n/*LWB_CONFIG_START*/ array(); /*LWB_CONFIG_END*/\n/*LWB_CONFIG_START*/ array(); /*LWB_CONFIG_END*/" ),
		);
	}
}
