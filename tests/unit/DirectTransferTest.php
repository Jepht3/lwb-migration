<?php
/**
 * Migration directe : l'installeur est construit par la destination, qui ne doit donc
 * pas avoir une version de l'extension plus ancienne que la source.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Direct_Transfer;

class DirectTransferTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-direct-transfer.php';
	}

	/**
	 * @dataProvider versions
	 */
	public function test_destination_outdated( $remote, $local, $expected ) {
		$this->assertSame( $expected, Direct_Transfer::destination_outdated( $remote, $local ) );
	}

	public function versions() {
		return array(
			'destination plus ancienne (cas aea-serratrice.fr)' => array( '1.2.2', '1.2.3', true ),
			'mineure plus ancienne'                             => array( '1.1.9', '1.2.0', true ),
			'chiffres comparés numériquement'                   => array( '1.2.9', '1.2.10', true ),
			'même version'                                      => array( '1.2.4', '1.2.4', false ),
			'destination plus récente'                          => array( '1.3.0', '1.2.4', false ),
			'version absente (destination antérieure)'          => array( '', '1.2.4', true ),
			'version illisible'                                 => array( 'abc', '1.2.4', true ),
			'espaces ignorés'                                   => array( ' 1.2.4 ', '1.2.4', false ),
		);
	}
}
