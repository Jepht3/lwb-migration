<?php
/**
 * Base commune des tests.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Json_Store;
use LwbMigration\Migration_Exception;
use LwbMigration\Mysqli_Db;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/** @var string[] */
	private $tmp_dirs = array();

	/** @var string[] */
	private $databases = array();

	protected function tmp_dir() {
		$dir = sys_get_temp_dir() . '/lwb-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0755, true );
		$this->tmp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Crée une base de test vide (ou ignore le test si MySQL est indisponible).
	 */
	protected function create_db() {
		$host = getenv( 'LWB_TEST_DB_HOST' ) ?: 'localhost';
		$user = getenv( 'LWB_TEST_DB_USER' ) ?: 'root';
		$pass = (string) getenv( 'LWB_TEST_DB_PASSWORD' );
		try {
			$server = new Mysqli_Db( $host, $user, $pass );
		} catch ( Migration_Exception $e ) {
			$this->markTestSkipped( 'MySQL indisponible : ' . $e->getMessage() );
		}
		$name = 'lwb_test_' . bin2hex( random_bytes( 5 ) );
		$server->query( 'CREATE DATABASE `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$server->close();
		$this->databases[] = $name;
		return new Mysqli_Db( $host, $user, $pass, $name );
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_dirs as $dir ) {
			Json_Store::delete_dir( $dir );
		}
		if ( $this->databases ) {
			$server = new Mysqli_Db( getenv( 'LWB_TEST_DB_HOST' ) ?: 'localhost', getenv( 'LWB_TEST_DB_USER' ) ?: 'root', (string) getenv( 'LWB_TEST_DB_PASSWORD' ) );
			foreach ( $this->databases as $name ) {
				$server->query( 'DROP DATABASE IF EXISTS `' . $name . '`' );
			}
			$server->close();
		}
		parent::tearDown();
	}
}
