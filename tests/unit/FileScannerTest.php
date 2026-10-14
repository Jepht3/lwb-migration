<?php
/**
 * Inventaire des fichiers : exclusions et reprise ; état des tâches.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\File_Scanner;
use LwbMigration\Job;
use LwbMigration\Runtime;

class FileScannerTest extends TestCase {

	private function make_site() {
		$root = $this->tmp_dir();
		foreach ( array( 'wp-admin', 'wp-content/plugins/demo', 'wp-content/cache/page', 'wp-content/uploads/2024/01', 'wp-content/themes/t/node_modules/x', 'wp-content/plugins/lwb-migration', 'empty', '.git/objects' ) as $d ) {
			mkdir( $root . '/' . $d, 0755, true );
		}
		for ( $i = 0; $i < 50; $i++ ) {
			file_put_contents( $root . "/wp-content/uploads/2024/01/img$i.jpg", str_repeat( 'x', $i ) );
		}
		file_put_contents( $root . '/index.php', '<?php' );
		file_put_contents( $root . '/wp-content/debug.log', 'log' );
		file_put_contents( $root . '/wp-content/error.log', 'log' );
		file_put_contents( $root . '/wp-content/cache/page/a.html', 'cache' );
		file_put_contents( $root . '/wp-content/themes/t/node_modules/x/a.js', 'x' );
		file_put_contents( $root . '/wp-content/themes/t/style.css', 'css' );
		file_put_contents( $root . '/wp-content/plugins/lwb-migration/lwb.php', 'self' );
		file_put_contents( $root . '/.git/objects/o', 'git' );
		return $root;
	}

	private function rules( $root ) {
		return array(
			'paths'      => array( $root . '/wp-content/cache', $root . '/wp-content/plugins/lwb-migration' ),
			'names'      => array( '.git', 'node_modules', 'debug.log' ),
			'extensions' => array( 'log' ),
		);
	}

	public function test_scan_with_exclusions_and_resume() {
		$root  = $this->make_site();
		$list  = $this->tmp_dir() . '/files.list';
		$scan  = new File_Scanner( $list, $this->rules( $root ) );
		$scan->add_root( $root, 'files' );
		$state = $scan->get_state();
		$steps = 0;
		while ( true ) {
			$scan = new File_Scanner( $list, $this->rules( $root ), $state );
			$done = $scan->run( new Runtime( 0.0001 ) );
			++$steps;
			if ( $done ) {
				break;
			}
			if ( 0 === $steps % 3 ) {
				file_put_contents( $list, "[\"f\",\"/garbage\",\"files/garbage\",1]\n", FILE_APPEND ); // Plantage.
				continue;
			}
			$state = json_decode( json_encode( $scan->get_state() ), true );
		}
		$this->assertGreaterThan( 1, $steps, "La reprise doit être exercée sur plusieurs étapes." );
		$names = array();
		foreach ( file( $list ) as $line ) {
			$item    = json_decode( $line, true );
			$names[] = $item[2];
		}
		$this->assertSame( count( $names ), count( array_unique( $names ) ), 'Doublons après reprise' );
		$this->assertContains( 'files/index.php', $names );
		$this->assertContains( 'files/empty', $names );
		$this->assertContains( 'files/wp-content/themes/t/style.css', $names );
		$this->assertContains( 'files/wp-content/uploads/2024/01/img49.jpg', $names );
		foreach ( array( 'garbage', 'cache', 'node_modules', '.git', 'lwb-migration', '.log' ) as $needle ) {
			foreach ( $names as $n ) {
				$this->assertStringNotContainsString( $needle, $n );
			}
		}
		$final = $scan->get_state();
		$this->assertSame( 52, $final['files'] );
	}

	public function test_job_state_persistence_and_lock() {
		$base = $this->tmp_dir();
		$job  = Job::create( $base, array( 'phase' => 'scan', 'n' => 1 ) );
		$this->assertFileExists( $job->path( 'state.php' ) );
		$this->assertStringStartsWith( '<?php exit; ?>', file_get_contents( $job->path( 'state.php' ) ) );
		$this->assertFileExists( $job->dir() . '/.htaccess' );

		$job->data['n'] = 2;
		$job->save();
		$again = Job::load( $base, $job->id() );
		$this->assertSame( 2, $again->data['n'] );
		$this->assertNull( Job::load( $base, '../etc' ) );

		$this->assertTrue( $job->lock() );
		$this->assertFalse( $again->lock(), 'Une seconde requête ne doit pas obtenir le verrou' );
		$job->unlock();
		$this->assertTrue( $again->lock() );
		$again->unlock();
	}
}
