<?php
/**
 * Outils de transfert : brouillage des corps, plages HTTP (mode « pull »), signature des
 * liens de téléchargement, taille des morceaux de l'import depuis l'administration.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Transfer_Tools;

class TransferToolsTest extends TestCase {

	public function test_mask_is_symmetric_and_hides_suspicious_content() {
		$data   = "\xFF\xD8\xFF\xE0<?php eval(base64_decode('ZWNobyAxOw==')); ?>" . str_repeat( 'x', 20000 );
		$secret = str_repeat( 'ab12', 16 );
		$masked = Transfer_Tools::mask( $data, $secret, 'n1' );
		$this->assertSame( strlen( $data ), strlen( $masked ) );
		$this->assertStringNotContainsString( 'eval(base64_decode', $masked );
		$this->assertStringNotContainsString( 'xxxxxxxx', $masked );
		$this->assertSame( $data, Transfer_Tools::mask( $masked, $secret, 'n1' ) );
		$this->assertNotSame( $masked, Transfer_Tools::mask( $data, $secret, 'n2' ), 'un aléa différent donne un flux différent' );
		$this->assertSame( '', Transfer_Tools::mask( '', $secret, 'n1' ) );
	}

	public function test_mask_matches_the_documented_algorithm() {
		// Même calcul que le JavaScript de l'onglet « Importer » (WebCrypto).
		$seed  = hash_hmac( 'sha256', 'mask|n', 'secret', true );
		$first = hash_hmac( 'sha256', pack( 'N', 0 ), $seed, true );
		$this->assertSame( "\0\0\0\0" ^ substr( $first, 0, 4 ), Transfer_Tools::mask( "\0\0\0\0", 'secret', 'n' ) );
	}

	/**
	 * @dataProvider ranges
	 */
	public function test_parse_range( $header, $expected ) {
		$this->assertSame( $expected, Transfer_Tools::parse_range( $header, 1000 ) );
	}

	public static function ranges() {
		return array(
			'sans en-tête : fichier entier'       => array( '', null ),
			'plage simple'                        => array( 'bytes=0-99', array( 0, 99 ) ),
			'reprise jusqu’à la fin'              => array( 'bytes=900-', array( 900, 999 ) ),
			'N derniers octets'                   => array( 'bytes=-100', array( 900, 999 ) ),
			'fin au-delà du fichier : tronquée'   => array( 'bytes=990-2000', array( 990, 999 ) ),
			'début après la fin : 416'            => array( 'bytes=1000-', false ),
			'plage inversée : 416'                => array( 'bytes=5-2', false ),
			'plages multiples : fichier entier'   => array( 'bytes=0-1,5-6', null ),
			'unité inconnue : fichier entier'     => array( 'items=0-1', null ),
			'majuscules tolérées'                 => array( 'Bytes=10-19', array( 10, 19 ) ),
			'suffixe nul : 416'                   => array( 'bytes=-0', false ),
			'en-tête vide après le signe égal'    => array( 'bytes=-', null ),
		);
	}

	public function test_parse_range_on_empty_file() {
		$this->assertFalse( Transfer_Tools::parse_range( 'bytes=0-', 0 ) );
	}

	public function test_content_range_start() {
		$this->assertSame( 8388608, Transfer_Tools::content_range_start( 'bytes 8388608-16777215/40000000' ) );
		$this->assertSame( 0, Transfer_Tools::content_range_start( 'bytes 0-9/*' ) );
		$this->assertNull( Transfer_Tools::content_range_start( 'bytes */1000' ) );
		$this->assertNull( Transfer_Tools::content_range_start( '' ) );
	}

	public function test_fetch_signature_binds_job_file_and_expiry() {
		$sig = Transfer_Tools::fetch_signature( 'secret', 'abc', 'archive', 1800000000 );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $sig );
		$this->assertSame( $sig, Transfer_Tools::fetch_signature( 'secret', 'abc', 'archive', 1800000000 ) );
		$this->assertNotSame( $sig, Transfer_Tools::fetch_signature( 'secret', 'abc', 'installer', 1800000000 ), 'autre fichier' );
		$this->assertNotSame( $sig, Transfer_Tools::fetch_signature( 'secret', 'abd', 'archive', 1800000000 ), 'autre tâche' );
		$this->assertNotSame( $sig, Transfer_Tools::fetch_signature( 'secret', 'abc', 'archive', 1800000001 ), 'expiration prolongée' );
		$this->assertNotSame( $sig, Transfer_Tools::fetch_signature( 'autre', 'abc', 'archive', 1800000000 ), 'autre secret' );
	}

	/**
	 * @dataProvider chunk_limits
	 */
	public function test_import_chunk_size( $upload, $post, $memory, $expected ) {
		$this->assertSame( $expected, Transfer_Tools::import_chunk_size( $upload, $post, $memory ) );
	}

	public static function chunk_limits() {
		$mb = 1048576;
		return array(
			'upload_max_filesize = 2M (limite courante)'  => array( 2 * $mb, 8 * $mb, 128 * $mb, 2 * $mb ),
			'post_max_size plus petit que l’upload'       => array( 64 * $mb, 3 * $mb, 0, 3 * $mb - 65536 ),
			'aucune limite : 8 Mo'                        => array( 0, 0, 0, 8 * $mb ),
			'mémoire très faible'                         => array( 0, 0, 16 * $mb, 2 * $mb ),
			'limites minuscules : plancher de 64 Ko'      => array( 10000, 10000, 0, 65536 ),
			'jamais plus que upload_max_filesize'         => array( 5 * $mb, 100 * $mb, 512 * $mb, 5 * $mb ),
		);
	}

	public function test_archive_kind() {
		$this->assertSame( 'zip', Transfer_Tools::archive_kind( "PK\x03\x04rest" ) );
		$this->assertSame( 'zip', Transfer_Tools::archive_kind( "PK\x05\x06" ) );
		$this->assertSame( 'lwb', Transfer_Tools::archive_kind( 'LWBARC01' ) );
		$this->assertSame( 'encrypted', Transfer_Tools::archive_kind( 'LWBENC01' ) );
		$this->assertSame( '', Transfer_Tools::archive_kind( '<?php // installer.php' ) );
		$this->assertSame( '', Transfer_Tools::archive_kind( '' ) );
	}
}
