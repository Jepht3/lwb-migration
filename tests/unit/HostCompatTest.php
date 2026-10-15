<?php
/**
 * Particularités des hébergeurs : extensions « must-use », wp-config.php par variables
 * d'environnement, proxy HTTPS, IIS, permissions et open_basedir.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Host_Compat;

class HostCompatTest extends TestCase {

	public function test_host_mu_plugins_are_recognised() {
		foreach ( array( 'wpengine-common', 'kinsta-mu-plugins.php', 'gd-system-plugin.php', 'pantheon.php', 'wpe-wp-sign-on-plugin', 'endurance-page-cache.php', 'hostinger-preview-domain.php', 'Kinsta-MU-Plugins' ) as $name ) {
			$this->assertTrue( Host_Compat::is_host_mu_plugin( $name ), $name );
		}
		foreach ( array( 'mon-extension.php', 'redis-cache', 'wp-rocket', 'query-monitor.php', 'mu-plugin.php' ) as $name ) {
			$this->assertFalse( Host_Compat::is_host_mu_plugin( $name ), $name );
		}
		$this->assertTrue( Host_Compat::is_host_mu_plugin( 'mu-plugin.php', "<?php\n/* Plugin Name: WP Engine System */" ), 'Nom générique : reconnu à son contenu.' );
	}

	public function test_host_files_are_found_for_export_and_protected_on_the_destination() {
		$root = $this->tmp_dir();
		$wpc  = $root . '/wp-content';
		mkdir( $wpc . '/mu-plugins/wpengine-common', 0755, true );
		file_put_contents( $wpc . '/mu-plugins/wpengine-common/plugin.php', '<?php' );
		file_put_contents( $wpc . '/mu-plugins/mu-plugin.php', "<?php\n// WP Engine bootstrap." );
		file_put_contents( $wpc . '/mu-plugins/mon-mu.php', "<?php\n// Réglages du site." );
		file_put_contents( $wpc . '/object-cache.php', "<?php\n/* Object cache – Kinsta */" );
		file_put_contents( $wpc . '/advanced-cache.php', "<?php\n/* WP Rocket */" );

		$paths = Host_Compat::host_paths( $wpc );
		sort( $paths );
		$this->assertSame(
			array(
				str_replace( '\\', '/', $wpc ) . '/mu-plugins/mu-plugin.php',
				str_replace( '\\', '/', $wpc ) . '/mu-plugins/wpengine-common',
				str_replace( '\\', '/', $wpc ) . '/object-cache.php',
			),
			$paths
		);

		$this->assertTrue( Host_Compat::is_protected_destination( 'wp-content/mu-plugins/kinsta-mu-plugins.php', $root ), 'Extension d’hébergeur : jamais installée ni écrasée, même absente de la destination.' );
		$this->assertTrue( Host_Compat::is_protected_destination( 'wp-content/mu-plugins/wpengine-common/sub/file.php', $root ) );
		$this->assertTrue( Host_Compat::is_protected_destination( 'wp-content/mu-plugins/mu-plugin.php', $root ) );
		$this->assertTrue( Host_Compat::is_protected_destination( 'wp-content/object-cache.php', $root ), 'Drop-in de l’hébergeur de destination conservé.' );
		$this->assertFalse( Host_Compat::is_protected_destination( 'wp-content/advanced-cache.php', $root ) );
		$this->assertFalse( Host_Compat::is_protected_destination( 'wp-content/mu-plugins/mon-mu.php', $root ) );
		$this->assertFalse( Host_Compat::is_protected_destination( 'wp-content/db.php', $root ), 'Drop-in absent de la destination : installé.' );
		$this->assertFalse( Host_Compat::is_protected_destination( 'wp-content/plugins/kinsta-x/a.php', $root ) );
	}

	/**
	 * @dataProvider env_configs
	 */
	public function test_env_based_wp_config_is_detected( $text, $expected ) {
		$this->assertSame( $expected, Host_Compat::config_uses_env( $text ) );
	}

	public function env_configs() {
		return array(
			'classique'   => array( "<?php\ndefine( 'DB_NAME', 'base' );\ndefine( 'DB_USER', 'u' );\n\$table_prefix = 'wp_';", false ),
			'Bedrock'     => array( "<?php\nrequire_once dirname( __DIR__ ) . '/config/application.php';\nrequire_once ABSPATH . 'wp-settings.php';", true ),
			'getenv'      => array( "<?php\ndefine( 'DB_NAME', getenv( 'DB_NAME' ) );", true ),
			'Docker'      => array( "<?php\ndefine( 'DB_NAME', getenv_docker( 'WORDPRESS_DB_NAME', 'wordpress' ) );", true ),
			'Pantheon'    => array( "<?php\nif ( isset( \$_ENV['PANTHEON_ENVIRONMENT'] ) ) { require_once 'wp-config-pantheon.php'; }", true ),
			'Platform.sh' => array( "<?php\n\$r = json_decode( base64_decode( getenv( 'PLATFORM_RELATIONSHIPS' ) ), true );", true ),
			'vide'        => array( '', false ),
		);
	}

	public function test_dotenv_and_environment_credentials() {
		$env = Host_Compat::parse_dotenv( "# Bedrock\nDB_NAME='ma_base'\nexport DB_USER=\"mon utilisateur\"\nDB_PASSWORD=p@ss#mot # commentaire\nDB_HOST=db.local\nDB_PREFIX=bk_\nWP_HOME=\"https://x.test\"\nligne invalide\n" );
		$this->assertSame( 'ma_base', $env['DB_NAME'] );
		$this->assertSame( 'mon utilisateur', $env['DB_USER'] );
		$this->assertSame( 'p@ss#mot', $env['DB_PASSWORD'] );
		$this->assertSame(
			array(
				'host'     => 'db.local',
				'name'     => 'ma_base',
				'user'     => 'mon utilisateur',
				'password' => 'p@ss#mot',
				'prefix'   => 'bk_',
			),
			Host_Compat::env_db_config( $env )
		);

		$this->assertSame( 'h:3307', Host_Compat::env_db_config( array( 'DB_HOST' => 'h', 'DB_PORT' => '3307' ) )['host'], 'Pantheon : port séparé.' );
		$docker = Host_Compat::env_db_config( array( 'WORDPRESS_DB_HOST' => 'mysql', 'WORDPRESS_DB_NAME' => 'wp', 'WORDPRESS_DB_USER' => 'w', 'WORDPRESS_DB_PASSWORD' => 's', 'WORDPRESS_TABLE_PREFIX' => 'dk_' ) );
		$this->assertSame( array( 'mysql', 'wp', 'w', 's', 'dk_' ), array_values( $docker ) );
		$url = Host_Compat::env_db_config( array( 'DATABASE_URL' => 'mysql://u%40x:p%2Fw@db.example:3306/base' ) );
		$this->assertSame( array( 'db.example:3306', 'base', 'u@x', 'p/w' ), array( $url['host'], $url['name'], $url['user'], $url['password'] ) );
		$rel = base64_encode( json_encode( array( 'database' => array( array( 'scheme' => 'mysql', 'host' => 'database.internal', 'port' => 3306, 'path' => 'main', 'username' => 'user', 'password' => '' ) ) ) ) );
		$psh = Host_Compat::env_db_config( array( 'PLATFORM_RELATIONSHIPS' => $rel ) );
		$this->assertSame( array( 'database.internal:3306', 'main', 'user', '' ), array( $psh['host'], $psh['name'], $psh['user'], $psh['password'] ) );
	}

	public function test_table_prefix_is_read_and_changed_without_touching_anything_else() {
		$classic = "<?php\ndefine( 'DB_NAME', getenv( 'DB_NAME' ) );\n\$table_prefix = 'wp_';\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$this->assertSame( 'wp_', Host_Compat::table_prefix( $classic ) );
		$out = Host_Compat::set_table_prefix( $classic, 'new_' );
		$this->assertSame( str_replace( "'wp_'", "'new_'", $classic ), $out );

		$bedrock = "<?php\n\$table_prefix = env( 'DB_PREFIX' ) ?: 'wp_';\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$this->assertSame( 'wp_', Host_Compat::table_prefix( $bedrock ) );
		$this->assertSame( 'bk_', Host_Compat::table_prefix( $bedrock, array( 'DB_PREFIX' => 'bk_' ) ) );
		$out = Host_Compat::set_table_prefix( $bedrock, 'x$1_' );
		$this->assertStringContainsString( "\$table_prefix = env( 'DB_PREFIX' ) ?: 'wp_';\n\$table_prefix = 'x\$1_'; // Lwb Migration.\nrequire_once", $out );

		$none = "<?php\nrequire_once dirname( __DIR__ ) . '/config/application.php';\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$this->assertNull( Host_Compat::table_prefix( $none ) );
		$out = Host_Compat::set_table_prefix( $none, 'p_' );
		$this->assertLessThan( strpos( $out, 'wp-settings.php' ), strpos( $out, "\$table_prefix = 'p_';" ) );
		$this->assertGreaterThan( strpos( $out, 'application.php' ), strpos( $out, "\$table_prefix = 'p_';" ) );
		$this->assert_valid_php( $out );
	}

	public function test_https_behind_a_proxy() {
		$this->assertTrue( Host_Compat::https_via_proxy( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ) ) );
		$this->assertTrue( Host_Compat::https_via_proxy( array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ) );
		$this->assertTrue( Host_Compat::https_via_proxy( array( 'HTTP_X_FORWARDED_SSL' => 'on' ) ) );
		$this->assertFalse( Host_Compat::https_via_proxy( array( 'HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => 'https' ) ), 'HTTPS déjà vu par PHP : rien à faire.' );
		$this->assertFalse( Host_Compat::https_via_proxy( array( 'HTTP_X_FORWARDED_PROTO' => 'http' ) ) );

		$config = "<?php\n/** Réglages */\ndefine( 'DB_NAME', 'b' );\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$out    = Host_Compat::add_proxy_https( $config );
		$this->assertStringStartsWith( "<?php\n// Lwb Migration : HTTPS", $out );
		$this->assertSame( $out, Host_Compat::add_proxy_https( $out ), 'Ajouté une seule fois.' );
		$this->assert_valid_php( $out );

		// Exécution : $_SERVER['HTTPS'] est positionné derrière Cloudflare.
		$code = preg_replace( '/^<\?php\s*/', '', Host_Compat::proxy_https_snippet() );
		$run  = function ( array $server ) use ( $code ) {
			$_SERVER = $server;
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			return isset( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : null;
		};
		$backup = $_SERVER;
		$this->assertSame( 'on', $run( array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ) );
		$this->assertNull( $run( array( 'HTTP_CF_VISITOR' => '{"scheme":"http"}' ) ) );
		$_SERVER = $backup;
	}

	public function test_iis_gets_a_web_config() {
		$this->assertTrue( Host_Compat::is_iis( array( 'SERVER_SOFTWARE' => 'Microsoft-IIS/10.0' ) ) );
		$this->assertFalse( Host_Compat::is_iis( array( 'SERVER_SOFTWARE' => 'Apache/2.4.62' ) ) );
		$this->assertFalse( Host_Compat::is_iis( array() ) );
		$xml = simplexml_load_string( Host_Compat::web_config() );
		$this->assertNotFalse( $xml );
		$this->assertSame( 'index.php', (string) $xml->{'system.webServer'}->rewrite->rules->rule->action['url'] );
	}

	public function test_file_modes_follow_fs_chmod_constants() {
		$this->assertSame( array( 0644, 0755 ), Host_Compat::fs_modes( '' ) );
		$this->assertSame( array( 0640, 0750 ), Host_Compat::fs_modes( "define( 'FS_CHMOD_FILE', 0640 );\ndefine('FS_CHMOD_DIR', ( 0750 & ~ umask() ));" ) );
		$this->assertSame( array( 0644, 0755 ), Host_Compat::fs_modes( "define( 'FS_CHMOD_FILE', 0044 );\ndefine( 'FS_CHMOD_DIR', 0644 );" ), 'Valeurs qui rendraient le site illisible ignorées.' );
	}

	public function test_open_basedir_paths() {
		$this->assertTrue( Host_Compat::path_allowed( '/tmp/x', '' ) );
		$sep = PATH_SEPARATOR;
		$this->assertTrue( Host_Compat::path_allowed( '/home/site/www/wp-config.php', "/home/site/www{$sep}/tmp" ) );
		$this->assertTrue( Host_Compat::path_allowed( '/home/site/www', "/home/site/www/{$sep}/tmp/" ) );
		$this->assertFalse( Host_Compat::path_allowed( '/home/site/wp-config.php', "/home/site/www/{$sep}/tmp/" ) );
		$this->assertFalse( Host_Compat::path_allowed( '/tmp', '/home/site/' ) );
		$this->assertTrue( Host_Compat::path_allowed( '/home/sitex', '/home/site' ), 'Comme PHP : sans barre finale, simple préfixe.' );
		$this->assertFalse( Host_Compat::path_allowed( '/home/sitex', '/home/site/' ) );

		$dir = $this->tmp_dir();
		$this->assertSame( str_replace( '\\', '/', $dir ), Host_Compat::temp_dir( array( $dir ) ) );
		$this->assertSame( str_replace( '\\', '/', $dir ), Host_Compat::temp_dir( array( $dir . '/absent', $dir ) ) );
	}

	public function test_no_system_temp_dir_outside_the_allowed_paths() {
		// Garde-fou : un fichier temporaire hors du site échouerait sous open_basedir.
		$root  = dirname( __DIR__, 2 );
		$files = array_merge( glob( $root . '/includes/*.php' ), glob( $root . '/installer/*.php' ) );
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			if ( 'class-host-compat.php' === basename( $file ) || 'class-updater.php' === basename( $file ) ) {
				continue; // Usage contrôlé par path_allowed().
			}
			$this->assertDoesNotMatchRegularExpression( '/\b(sys_get_temp_dir|tempnam|tmpfile)\s*\(/', $code, basename( $file ) );
		}
	}

	private function assert_valid_php( $code ) {
		$file = $this->tmp_dir() . '/check.php';
		file_put_contents( $file, $code );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) . "\n" . $code );
	}
}
