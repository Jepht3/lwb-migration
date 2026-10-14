<?php
/**
 * L'installeur généré doit être un fichier PHP valide et autonome.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Installer_Builder;

class InstallerBuilderTest extends TestCase {

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

	public function test_generated_installer_is_valid_php_and_runs_standalone() {
		$dir    = $this->tmp_dir();
		$config = array(
			'version'        => 'test',
			'format_version' => 1,
			'package'        => 'x',
			'archive'        => 'archive-absent.zip',
			'archive_size'   => 0,
			'created'        => time(),
			'password_hash'  => '',
			'secret'         => str_repeat( 'ab', 32 ),
			'mode'           => 'manual',
			'token_hash'     => '',
			'source'         => array( 'site_url' => 'http://a.test', 'home_url' => 'http://a.test', 'abspath' => '/x', 'table_prefix' => "wp_'\"$", 'wp_version' => '6', 'php_version' => '8', 'site_name' => 'Nom </script> é' ),
			'target'         => array(),
			'db'             => array(),
		);
		Installer_Builder::build( $dir . '/installer.php', $config );
		exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $dir . '/installer.php' ) . ' 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );

		// Exécution réelle hors WordPress (page HTML d'accueil).
		$runner = $dir . '/run.php';
		file_put_contents( $runner, '<?php $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "b.test"; $_SERVER["SCRIPT_NAME"] = "/installer.php"; require __DIR__ . "/installer.php";' );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $html, $rc );
		$html = implode( "\n", $html );
		$this->assertSame( 0, $rc, $html );
		$this->assertStringContainsString( 'Vérification de l’environnement', $html );
		$this->assertStringContainsString( 'Nom &lt;/script&gt; é', $html );
	}
}
