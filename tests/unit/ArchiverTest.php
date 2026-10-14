<?php
/**
 * Archives ZIP et format de repli LWB : contenu, compatibilité, reprise après interruption.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Archive_Reader;
use LwbMigration\Archiver;
use LwbMigration\Runtime;

class ArchiverTest extends TestCase {

	/**
	 * Arborescence de test (dont un fichier > BIG_FILE copié sur plusieurs étapes).
	 */
	private function make_source() {
		$src = $this->tmp_dir();
		mkdir( $src . '/sub/deep', 0755, true );
		mkdir( $src . '/vide' );
		file_put_contents( $src . '/a.txt', 'bonjour' );
		file_put_contents( $src . '/vide.txt', '' );
		file_put_contents( $src . '/sub/texte.css', str_repeat( "body { color: red; }\n", 20000 ) );
		file_put_contents( $src . '/sub/deep/été ünicode.txt', 'accents' );
		file_put_contents( $src . '/photo.jpg', random_bytes( 300000 ) );
		$h = fopen( $src . '/big.bin', 'wb' );
		for ( $i = 0; $i < 10; $i++ ) {
			fwrite( $h, random_bytes( 1048576 ) );
		}
		fclose( $h );
		return $src;
	}

	private function entries( $src ) {
		$list = array( array( 'd', $src, 'files' ) );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$rel    = substr( $file->getPathname(), strlen( $src ) + 1 );
			$list[] = array( $file->isDir() ? 'd' : 'f', $file->getPathname(), 'files/' . $rel );
		}
		return $list;
	}

	/**
	 * Construit une archive en simulant des étapes courtes et des plantages.
	 *
	 * @param bool $crash Simuler un plantage (données parasites + retour au point de contrôle précédent).
	 */
	private function build( $format, $src, $crash ) {
		$dir     = $this->tmp_dir();
		$archive = $dir . '/archive.' . $format;
		$index   = $dir . '/index.bin';
		$items   = $this->entries( $src );
		$state   = array();
		$i       = 0;
		$steps   = 0;
		while ( true ) {
			$saved = array( $state, $i );
			$a     = new Archiver( $archive, $index, $format, $state );
			$a->open();
			$rt = new Runtime( 0.0001 );
			while ( $i < count( $items ) ) {
				list( $type, $abs, $name ) = $items[ $i ];
				$status = 'd' === $type ? $a->add_dir( $name ) : $a->add_file( $abs, $name, $rt );
				if ( Archiver::STATUS_PARTIAL === $status ) {
					break;
				}
				++$i;
				if ( $rt->should_stop() ) {
					break;
				}
			}
			if ( $i >= count( $items ) ) {
				$a->add_string( 'lwb-package.json', '{"ok":true}' );
				$a->finalize();
				$a->close();
				break;
			}
			$a->close();
			++$steps;
			if ( $crash && 0 === $steps % 3 ) {
				// Plantage : l'étape a écrit des données mais l'état n'a pas été sauvegardé.
				file_put_contents( $archive, random_bytes( 5000 ), FILE_APPEND );
				file_put_contents( $index, random_bytes( 300 ), FILE_APPEND );
				list( $state, $i ) = $saved;
				continue;
			}
			// Sauvegarde/rechargement JSON comme entre deux requêtes.
			$state = json_decode( json_encode( $a->get_state() ), true );
		}
		$this->assertGreaterThan( 3, $steps, 'Le budget minuscule doit forcer plusieurs étapes.' );
		return $archive;
	}

	private function extract_all( $archive ) {
		$out    = $this->tmp_dir();
		$reader = new Archive_Reader( $archive );
		$cursor = 0;
		$names  = array();
		while ( null !== ( $e = $reader->next_entry( $cursor ) ) ) {
			$names[] = $e['name'];
			$prog    = array();
			$guard   = 0;
			do {
				// Reprise de l'extraction d'un gros fichier sur plusieurs étapes.
				$prog = json_decode( json_encode( $prog ), true );
				$done = $reader->extract( $e, $out . '/' . $e['name'], $prog, new Runtime( 0.001 ) );
			} while ( ! $done && ++$guard < 1000 );
			$this->assertTrue( $done );
			$this->assertFalse( $prog['crc_error'], 'CRC invalide pour ' . $e['name'] );
		}
		return array( $out, $names );
	}

	private function assert_same_tree( $expected, $actual ) {
		foreach ( $this->entries( $expected ) as list( $type, $abs, $name ) ) {
			$rel = substr( $abs, strlen( $expected ) );
			if ( 'd' === $type ) {
				$this->assertDirectoryExists( $actual . $rel );
			} else {
				$this->assertFileExists( $actual . $rel );
				$this->assertSame( md5_file( $abs ), md5_file( $actual . $rel ), $rel );
			}
		}
	}

	public function provider() {
		return array(
			'zip'                 => array( Archiver::FORMAT_ZIP, false ),
			'zip avec plantages'  => array( Archiver::FORMAT_ZIP, true ),
			'lwb'                 => array( Archiver::FORMAT_LWB, false ),
			'lwb avec plantages'  => array( Archiver::FORMAT_LWB, true ),
		);
	}

	/**
	 * @dataProvider provider
	 */
	public function test_roundtrip_with_resume( $format, $crash ) {
		$src     = $this->make_source();
		$archive = $this->build( $format, $src, $crash );
		list( $out, $names ) = $this->extract_all( $archive );
		$this->assert_same_tree( $src, $out . '/files' );
		$this->assertContains( 'files/vide/', $names );
		$this->assertContains( 'lwb-package.json', $names );
		$this->assertSame( '{"ok":true}', file_get_contents( $out . '/lwb-package.json' ) );
		$this->assertSame( $format, ( new Archive_Reader( $archive ) )->format() );
	}

	public function test_zip_is_standard() {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive indisponible.' );
		}
		$src     = $this->make_source();
		$archive = $this->build( Archiver::FORMAT_ZIP, $src, true );
		$zip     = new \ZipArchive();
		$this->assertTrue( $zip->open( $archive, \ZipArchive::CHECKCONS ) );
		$this->assertSame( md5_file( $src . '/big.bin' ), md5( $zip->getFromName( 'files/big.bin' ) ) );
		$this->assertSame( 'accents', $zip->getFromName( 'files/sub/deep/été ünicode.txt' ) );
		$stat = $zip->statName( 'files/sub/texte.css' );
		$this->assertSame( \ZipArchive::CM_DEFLATE, $stat['comp_method'] );
		$this->assertLessThan( $stat['size'] / 10, $stat['comp_size'] );
		$stat = $zip->statName( 'files/photo.jpg' );
		$this->assertSame( \ZipArchive::CM_STORE, $stat['comp_method'] );
		$zip->close();
	}

	public function test_zip64_end_of_central_directory_for_many_entries() {
		$dir = $this->tmp_dir();
		$a   = new Archiver( $dir . '/many.zip', $dir . '/idx', Archiver::FORMAT_ZIP );
		$a->open();
		for ( $i = 0; $i < 65540; $i++ ) {
			$a->add_string( "f/$i.txt", (string) $i );
		}
		$a->finalize();
		$a->close();
		$reader = new Archive_Reader( $dir . '/many.zip' );
		$this->assertSame( 65540, $reader->count() );
		$last = $reader->find( 'f/65539.txt' );
		$this->assertSame( '65539', $reader->read_string( $last ) );
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			$this->assertTrue( $zip->open( $dir . '/many.zip' ) );
			$this->assertSame( 65540, $zip->numFiles );
		}
	}

	public function test_crc32_combine_matches_crc32() {
		$a = random_bytes( 100000 );
		$b = random_bytes( 3333 );
		$this->assertSame( crc32( $a . $b ), Archive_Reader::crc32_combine( crc32( $a ), crc32( $b ), strlen( $b ) ) );
		$this->assertSame( crc32( $b ), Archive_Reader::crc32_combine( 0, crc32( $b ), strlen( $b ) ) );
	}

	public function test_safe_name_blocks_zip_slip() {
		$this->assertNull( Archive_Reader::safe_name( '../etc/passwd' ) );
		$this->assertNull( Archive_Reader::safe_name( 'files/../../x.php' ) );
		$this->assertNull( Archive_Reader::safe_name( '/abs/path' ) );
		$this->assertNull( Archive_Reader::safe_name( 'C:\\win\\x' ) );
		$this->assertNull( Archive_Reader::safe_name( "a\0b" ) );
		$this->assertSame( 'files/a/b..c.txt', Archive_Reader::safe_name( 'files\\a\\b..c.txt' ) );
	}

	public function test_truncated_archive_is_detected() {
		$src     = $this->make_source();
		$archive = $this->build( Archiver::FORMAT_ZIP, $src, false );
		$h       = fopen( $archive, 'r+b' );
		ftruncate( $h, (int) ( filesize( $archive ) / 2 ) );
		fclose( $h );
		$this->expectException( \LwbMigration\Migration_Exception::class );
		new Archive_Reader( $archive );
	}
}
