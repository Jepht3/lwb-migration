<?php
/**
 * Mises à jour : seules les publications signées avec la clé LEWEBMAX sont acceptées.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Updater;

class UpdaterTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-updater.php';
	}

	private function signed_manifest( $secret_key, array $overrides = array() ) {
		$m              = array_merge(
			array(
				'version' => '1.3.0',
				'zip_url' => 'https://github.com/Jepht3/lwb-migration/releases/download/v1.3.0/lwb-migration.zip',
				'sha256'  => hash( 'sha256', 'zip' ),
			),
			$overrides
		);
		$m['signature'] = base64_encode( sodium_crypto_sign_detached( Updater::signature_message( $m['version'], $m['zip_url'], $m['sha256'] ), $secret_key ) );
		return $m;
	}

	public function test_embedded_public_key_is_a_valid_ed25519_key() {
		$key = base64_decode( Updater::PUBLIC_KEY, true );
		$this->assertNotFalse( $key );
		$this->assertSame( 32, strlen( $key ) );
	}

	public function test_valid_signature_is_accepted() {
		$pair = sodium_crypto_sign_keypair();
		$m    = $this->signed_manifest( sodium_crypto_sign_secretkey( $pair ) );
		$this->assertTrue( Updater::verify_signature( $m, base64_encode( sodium_crypto_sign_publickey( $pair ) ) ) );
	}

	public function test_tampered_or_foreign_releases_are_rejected() {
		$pair   = sodium_crypto_sign_keypair();
		$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
		$m      = $this->signed_manifest( sodium_crypto_sign_secretkey( $pair ) );

		$this->assertFalse( Updater::verify_signature( array_merge( $m, array( 'sha256' => hash( 'sha256', 'autre zip' ) ) ), $public ), 'Empreinte modifiée.' );
		$this->assertFalse( Updater::verify_signature( array_merge( $m, array( 'zip_url' => 'https://example.com/pirate.zip' ) ), $public ), 'Adresse modifiée.' );
		$this->assertFalse( Updater::verify_signature( array_merge( $m, array( 'version' => '9.9.9' ) ), $public ), 'Version modifiée.' );
		$this->assertFalse( Updater::verify_signature( array_merge( $m, array( 'signature' => '' ) ), $public ), 'Signature absente.' );

		$other = sodium_crypto_sign_keypair();
		$this->assertFalse( Updater::verify_signature( $this->signed_manifest( sodium_crypto_sign_secretkey( $other ) ), $public ), 'Autre clé.' );
		$this->assertFalse( Updater::verify_signature( $m, Updater::PUBLIC_KEY ), 'La clé officielle refuse une signature de test.' );
	}
}
