<?php
/**
 * Chiffrement de l'archive : aller-retour, reprise après coupure, falsifications détectées.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Archive_Crypto;
use LwbMigration\Migration_Exception;

class ArchiveCryptoTest extends TestCase {

	protected function setUp(): void {
		if ( ! Archive_Crypto::available() ) {
			$this->markTestSkipped( 'OpenSSL (aes-256-gcm) indisponible.' );
		}
	}

	private function encrypt( $plain_file, $enc_file, $password, $salt, $budget = null ) {
		$state = array(
			'in'         => 0,
			'salt'       => $salt,
			'iterations' => 1000,
		);
		$key   = Archive_Crypto::derive_key( $password, $salt, 1000 );
		$steps = 0;
		do {
			$done = Archive_Crypto::encrypt_step( $plain_file, $enc_file, $key, $state, $budget ? $this->spent_runtime() : null );
			++$steps;
		} while ( ! $done && $steps < 100 );
		$this->assertTrue( $done );
		return $steps;
	}

	private function decrypt( $enc_file, $plain_file, $password ) {
		$head  = Archive_Crypto::read_header( $enc_file );
		$key   = Archive_Crypto::derive_key( $password, $head['salt'], $head['iterations'] );
		$state = array();
		$steps = 0;
		do {
			$done = Archive_Crypto::decrypt_step( $enc_file, $plain_file, $key, $state, $this->spent_runtime() );
			++$steps;
		} while ( ! $done && $steps < 100 );
		return $steps;
	}

	/**
	 * @dataProvider sizes
	 */
	public function test_round_trip_by_steps( $size ) {
		$dir  = $this->tmp_dir();
		$data = random_bytes( $size );
		file_put_contents( "$dir/a.zip", $data );
		$steps = $this->encrypt( "$dir/a.zip", "$dir/a.enc", 'mot de passe', random_bytes( 16 ), true );
		$this->assertGreaterThanOrEqual( max( 1, (int) ceil( $size / Archive_Crypto::CHUNK ) ), $steps, 'Un bloc par étape quand le budget est épuisé.' );

		$enc = file_get_contents( "$dir/a.enc" );
		$this->assertSame( 0, strpos( $enc, Archive_Crypto::MAGIC ) );
		$this->assertFalse( $size > 64 && false !== strpos( $enc, substr( $data, 0, 64 ) ), 'Aucune donnée en clair.' );

		$this->decrypt( "$dir/a.enc", "$dir/b.zip", 'mot de passe' );
		$this->assertSame( md5( $data ), md5_file( "$dir/b.zip" ) );
	}

	public function sizes() {
		return array(
			'petit'               => array( 1000 ),
			'exactement un bloc'  => array( Archive_Crypto::CHUNK ),
			'plusieurs blocs'     => array( 2 * Archive_Crypto::CHUNK + 12345 ),
		);
	}

	public function test_interrupted_encryption_resumes_from_last_full_block() {
		$dir  = $this->tmp_dir();
		$data = random_bytes( 3 * Archive_Crypto::CHUNK + 7 );
		file_put_contents( "$dir/a.zip", $data );
		$salt  = random_bytes( 16 );
		$key   = Archive_Crypto::derive_key( 'pw', $salt, 1000 );
		$state = array(
			'in'         => 0,
			'salt'       => $salt,
			'iterations' => 1000,
		);
		Archive_Crypto::encrypt_step( "$dir/a.zip", "$dir/a.enc", $key, $state, $this->spent_runtime() );
		// Coupure simulée : octets écrits sans que l'état n'ait été enregistré.
		file_put_contents( "$dir/a.enc", random_bytes( 5000 ), FILE_APPEND );
		do {
			$done = Archive_Crypto::encrypt_step( "$dir/a.zip", "$dir/a.enc", $key, $state, $this->spent_runtime() );
		} while ( ! $done );
		$this->decrypt( "$dir/a.enc", "$dir/b.zip", 'pw' );
		$this->assertSame( md5( $data ), md5_file( "$dir/b.zip" ) );
	}

	public function test_wrong_password_is_rejected() {
		$dir = $this->tmp_dir();
		file_put_contents( "$dir/a.zip", random_bytes( 5000 ) );
		$this->encrypt( "$dir/a.zip", "$dir/a.enc", 'bon', random_bytes( 16 ) );
		$this->expectException( Migration_Exception::class );
		$this->decrypt( "$dir/a.enc", "$dir/b.zip", 'mauvais' );
	}

	/**
	 * @dataProvider tamperings
	 */
	public function test_tampered_archive_is_rejected( $tamper ) {
		$dir = $this->tmp_dir();
		file_put_contents( "$dir/a.zip", random_bytes( 2 * Archive_Crypto::CHUNK + 100 ) );
		$this->encrypt( "$dir/a.zip", "$dir/a.enc", 'pw', random_bytes( 16 ) );
		$enc   = file_get_contents( "$dir/a.enc" );
		$block = Archive_Crypto::CHUNK + Archive_Crypto::IV_LEN + Archive_Crypto::TAG_LEN;
		switch ( $tamper ) {
			case 'octet modifié':
				$enc[ Archive_Crypto::HEADER_LEN + 100 ] = chr( ord( $enc[ Archive_Crypto::HEADER_LEN + 100 ] ) ^ 1 );
				break;
			case 'tronquée après un bloc complet':
				$enc = substr( $enc, 0, Archive_Crypto::HEADER_LEN + $block );
				break;
			case 'blocs inversés':
				$b1  = substr( $enc, Archive_Crypto::HEADER_LEN, $block );
				$b2  = substr( $enc, Archive_Crypto::HEADER_LEN + $block, $block );
				$enc = substr( $enc, 0, Archive_Crypto::HEADER_LEN ) . $b2 . $b1 . substr( $enc, Archive_Crypto::HEADER_LEN + 2 * $block );
				break;
		}
		file_put_contents( "$dir/a.enc", $enc );
		$this->expectException( Migration_Exception::class );
		$this->decrypt( "$dir/a.enc", "$dir/b.zip", 'pw' );
	}

	public function tamperings() {
		return array(
			'octet modifié'                  => array( 'octet modifié' ),
			'tronquée après un bloc complet' => array( 'tronquée après un bloc complet' ),
			'blocs inversés'                 => array( 'blocs inversés' ),
		);
	}

	public function test_plain_file_is_not_recognised_as_encrypted() {
		$dir = $this->tmp_dir();
		file_put_contents( "$dir/a.zip", "PK\x03\x04" . str_repeat( 'x', 100 ) );
		$this->assertNull( Archive_Crypto::read_header( "$dir/a.zip" ) );
	}
}
