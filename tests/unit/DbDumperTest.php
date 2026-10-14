<?php
/**
 * Dump SQL en flux + import : fidélité des données, changement de préfixe, reprise.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Db_Dumper;
use LwbMigration\Logger;
use LwbMigration\Runtime;
use LwbMigration\Sql_Importer;

class DbDumperTest extends TestCase {

	private function seed( $db ) {
		$db->query( "CREATE TABLE wp_posts (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_title TEXT, post_content LONGTEXT, post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00', price DECIMAL(10,2), ratio DOUBLE, flag TINYINT(1), maybe_null VARCHAR(20) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
		$db->query( 'CREATE TABLE wp_blobs (id INT PRIMARY KEY, data LONGBLOB, bits BIT(8), fixed BINARY(4))' );
		$db->query( 'CREATE TABLE wp_term_relationships (object_id BIGINT, term_taxonomy_id BIGINT, term_order INT, PRIMARY KEY (object_id, term_taxonomy_id))' );
		$db->query( 'CREATE TABLE wp_logs (message TEXT, level INT)' );
		$db->query( 'CREATE TABLE wp_generated (id INT PRIMARY KEY, price INT, total INT AS (price * 2) VIRTUAL)' );
		$db->query( 'CREATE TABLE wp_child (id INT PRIMARY KEY, post_id BIGINT UNSIGNED, CONSTRAINT fk_post FOREIGN KEY (post_id) REFERENCES wp_posts (ID))' );
		$db->query( 'CREATE TABLE other_table (id INT PRIMARY KEY)' );
		$db->query( 'INSERT INTO other_table VALUES (1)' );

		$tricky = array(
			"Apostrophe ' et \"guillemets\"",
			"Antislash \\ et \\' et \\n littéral",
			"Retour\nà la ligne\r\n et tab\t ; fin;",
			"Octet nul \0 au milieu et \x1a",
			'Emoji 🚀 et accents éàü 漢字',
			serialize( array( 'url' => 'http://old.test', 'n' => array( 1, 2 ) ) ),
			'-- pas un commentaire',
			'',
		);
		for ( $i = 1; $i <= 1500; $i++ ) {
			$content = $tricky[ $i % count( $tricky ) ] . " #$i";
			if ( 0 === $i % 300 ) {
				$content = str_repeat( 'Gros contenu ', 150000 ) . $i; // ~2 Mo : une instruction à part entière.
			}
			$db->query(
				'INSERT INTO wp_posts (post_title, post_content, post_date, price, ratio, flag, maybe_null) VALUES ('
				. "'Titre $i', '" . \LwbMigration\Db::escape( $content ) . "', '2024-01-0" . ( 1 + $i % 9 ) . " 10:00:00', " . ( $i / 7 ) . ', ' . ( $i / 3 ) . ', ' . ( $i % 2 ) . ', ' . ( 0 === $i % 5 ? 'NULL' : "'v$i'" ) . ')'
			);
			$db->query( 'INSERT INTO wp_term_relationships VALUES (' . $i . ', ' . ( $i % 13 ) . ', 0)' );
			if ( $i <= 200 ) {
				$db->query( "INSERT INTO wp_logs VALUES ('log $i', $i)" );
			}
		}
		$db->query( "UPDATE wp_posts SET post_date = '0000-00-00 00:00:00' WHERE ID = 3" );
		$db->query( "INSERT INTO wp_blobs VALUES (1, X'00FF10275C27', b'10101010', X'00010203'), (2, '', b'0', X'FFFFFFFF'), (3, NULL, NULL, NULL)" );
		$db->query( 'INSERT INTO wp_generated (id, price) VALUES (1, 10), (2, 21)' );
		$db->query( 'INSERT INTO wp_child VALUES (1, 1), (2, 1500)' );
	}

	private function fingerprint( $db, $table ) {
		$rows = $db->get_rows( 'SELECT * FROM `' . $table . '`' );
		$rows = array_map( 'serialize', (array) $rows );
		sort( $rows );
		return md5( implode( "\n", $rows ) ) . ':' . count( $rows );
	}

	/**
	 * Dump avec un budget minuscule ; si $crash, on simule des plantages en cours d'étape.
	 */
	private function dump( $db, $file, $crash ) {
		$dumper = new Db_Dumper( $db, $file );
		$dumper->init( 'wp_' );
		$state = $dumper->get_state();
		$steps = 0;
		while ( true ) {
			$dumper = new Db_Dumper( $db, $file, $state );
			$done   = $dumper->run( new Runtime( 0.0001 ) );
			++$steps;
			if ( $done ) {
				break;
			}
			if ( $crash && 0 === $steps % 4 ) {
				// L'étape a écrit puis « planté » avant la sauvegarde : état précédent conservé.
				file_put_contents( $file, "INSERT INTO `wp_posts` VALUES (garbage", FILE_APPEND );
				continue;
			}
			$state = json_decode( json_encode( $dumper->get_state() ), true );
		}
		$this->assertGreaterThan( 5, $steps );
		return $dumper->get_state();
	}

	private function import( $db, $file, $old, $new, $crash ) {
		$state = array(
			'old_prefix' => $old,
			'new_prefix' => $new,
		);
		$log     = new Logger( $this->tmp_dir() . '/log.php' );
		$steps   = 0;
		$crashes = 0;
		// « Disque » : ce que l'installeur a réellement enregistré.
		$persist = function ( $s ) use ( &$state ) {
			$state = json_decode( json_encode( $s ), true );
		};
		while ( true ) {
			$importer = new Sql_Importer( $db, $file, $state, $log, $persist );
			$done     = $importer->run( new Runtime( 0.0001 ) );
			++$steps;
			if ( $done ) {
				break;
			}
			if ( $crash && in_array( $steps, array( 6, 17, 40 ), true ) ) {
				// Plantage après exécution de requêtes, avant la sauvegarde de fin d'étape :
				// seul l'état « en cours » (dirty) écrit au début de l'étape subsiste.
				$this->assertTrue( $state['dirty'] );
				++$crashes;
				continue;
			}
			$persist( $importer->get_state() );
		}
		$this->assertGreaterThan( 5, $steps );
		if ( $crash ) {
			$this->assertGreaterThan( 0, $crashes );
		}
		$errors = $importer->get_state()['errors'];
		$this->assertSame( 0, $errors, Logger::read_contents( $log->get_file() ) );
		return $importer->get_state();
	}

	public function provider() {
		return array(
			'sans interruption'  => array( false ),
			'avec interruptions' => array( true ),
		);
	}

	/**
	 * @dataProvider provider
	 */
	public function test_dump_and_import_roundtrip_with_prefix_change( $crash ) {
		$src = $this->create_db();
		$this->seed( $src );
		$file  = $this->tmp_dir() . '/database.sql';
		$state = $this->dump( $src, $file, $crash );

		$this->assertSame( array( 'wp_blobs', 'wp_child', 'wp_generated', 'wp_logs', 'wp_posts', 'wp_term_relationships' ), $state['tables'] );
		$multi = false;
		$h     = fopen( $file, 'rb' );
		while ( false !== ( $line = fgets( $h ) ) ) {
			$this->assertStringNotContainsString( 'other_table', $line );
			$this->assertStringNotContainsString( 'garbage', $line );
			$this->assertLessThan( 3 * 1048576, strlen( $line ) );
			$multi = $multi || preg_match( '/^INSERT INTO `wp_posts` \([^)]+\) VALUES \(.+\),\(/', $line );
		}
		fclose( $h );
		$this->assertTrue( (bool) $multi, 'INSERT multi-lignes attendu' );

		$dst = $this->create_db();
		// Une table existante du même nom doit être remplacée.
		$dst->query( 'CREATE TABLE new_posts (x INT)' );
		$imported = $this->import( $dst, $file, 'wp_', 'new_', $crash );
		$this->assertCount( 6, $imported['tables'] );

		foreach ( array( 'posts', 'blobs', 'term_relationships', 'logs', 'generated', 'child' ) as $t ) {
			$this->assertSame( $this->fingerprint( $src, 'wp_' . $t ), $this->fingerprint( $dst, 'new_' . $t ), "Table $t différente" );
		}
		$this->assertSame( '42', $dst->get_var( 'SELECT total FROM new_generated WHERE id = 2' ) );
		$create = $dst->get_rows( 'SHOW CREATE TABLE new_child' );
		$this->assertStringContainsString( 'REFERENCES `new_posts`', $create[0][1] );
		$this->assertSame( '0000-00-00 00:00:00', $dst->get_var( 'SELECT post_date FROM new_posts WHERE ID = 3' ) );
	}

	public function test_importer_transform_collations() {
		$db       = $this->create_db();
		$importer = new Sql_Importer(
			$db,
			'/dev/null',
			array(
				'old_prefix' => 'wp_',
				'new_prefix' => 'x_',
				'collations' => array( '/utf8mb4_0900_[a-z_]+/' => 'utf8mb4_unicode_520_ci' ),
			)
		);
		$out = $importer->transform( 'CREATE TABLE `wp_a` (b TEXT COLLATE utf8mb4_0900_ai_ci) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci' );
		$this->assertSame( 'CREATE TABLE `x_a` (b TEXT COLLATE utf8mb4_unicode_520_ci) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci', $out );
		$this->assertSame( 'INSERT INTO `x_options` VALUES (1)', $importer->transform( 'INSERT INTO `wp_options` VALUES (1)' ) );
		$this->assertSame( 'INSERT INTO `other` VALUES (1)', $importer->transform( 'INSERT INTO `other` VALUES (1)' ) );
	}
}
