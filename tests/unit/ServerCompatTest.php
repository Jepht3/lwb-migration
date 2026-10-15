<?php
/**
 * Import sur des serveurs stricts : clé primaire obligatoire, index limités, moteurs,
 * max_allowed_packet réduit, vues signalées à l'export.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Db_Dumper;
use LwbMigration\Mysqli_Db;
use LwbMigration\Runtime;
use LwbMigration\Sql_Importer;

class ServerCompatTest extends TestCase {

	/**
	 * Importe un dump et renvoie l'état final de l'importeur.
	 */
	private function import( $db, $sql, array $state = array() ) {
		$file = $this->tmp_dir() . '/dump.sql';
		file_put_contents( $file, $sql );
		$importer = new Sql_Importer( $db, $file, $state );
		$guard    = 0;
		while ( ! $importer->run( new Runtime( 5 ) ) && $guard++ < 50 ) {
			continue;
		}
		return $importer->get_state();
	}

	private function engine( $db, $table ) {
		return strtolower( (string) $db->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'" ) );
	}

	public function test_myisam_tables_are_converted_to_innodb_on_request() {
		$db  = $this->create_db();
		$sql = "DROP TABLE IF EXISTS `wp_stats`;\nCREATE TABLE `wp_stats` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  `page` varchar(100) NOT NULL DEFAULT '',\n  PRIMARY KEY (`id`),\n  FULLTEXT KEY `page` (`page`)\n) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;\nINSERT INTO `wp_stats` (`id`,`page`) VALUES (1,'accueil'),(2,'contact');\n";

		$state = $this->import( $db, $sql, array( 'innodb' => true ) );
		$this->assertSame( 0, $state['errors'] );
		$this->assertSame( 'innodb', $this->engine( $db, 'wp_stats' ) );
		$this->assertSame( array( 'wp_stats' ), $state['converted'] );
		$this->assertSame( '2', (string) $db->get_var( 'SELECT COUNT(*) FROM wp_stats' ) );

		$state = $this->import( $db, $sql );
		$this->assertSame( 'myisam', $this->engine( $db, 'wp_stats' ), 'Sans la conversion, le moteur d’origine est conservé.' );
		$this->assertSame( array(), $state['converted'] );
	}

	public function test_too_long_indexes_are_shortened() {
		$db = $this->create_db();
		// MyISAM : 1 000 octets par index (2 000 sur MariaDB 10.5+), sur tous les serveurs. InnoDB
		// limité à 767 octets : voir la CI MySQL 5.7 (format COMPACT). Clé primaire : un index
		// ordinaire trop long est tronqué par le serveur lui-même (avertissement 1071) et MariaDB
		// 10.4+ accepte les index UNIQUE longs (empreinte cachée).
		$sql = "CREATE TABLE `wp_long` (\n  `id` int(11) NOT NULL,\n  `a` varchar(255) NOT NULL,\n  `b` varchar(255) NOT NULL,\n  `c` varchar(255) NOT NULL,\n  PRIMARY KEY (`a`,`b`,`c`),\n  KEY `id` (`id`)\n) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;\nINSERT INTO `wp_long` (`id`,`a`,`b`,`c`) VALUES (1,'" . str_repeat( 'é', 255 ) . "','b','c');\n";

		$state = $this->import( $db, $sql );
		$this->assertSame( 0, $state['errors'], 'Index raccourci au lieu d’une table manquante.' );
		$this->assertSame( 'myisam', $this->engine( $db, 'wp_long' ) );
		$this->assertSame( str_repeat( 'é', 255 ), $db->get_var( 'SELECT a FROM wp_long WHERE id = 1' ), 'Données intactes : seul l’index est raccourci.' );
		$this->assertNotEmpty( $state['fixes'] );
		$this->assertStringContainsString( 'wp_long', $state['fixes'][0] );
	}

	public function test_primary_key_is_added_when_the_server_requires_it() {
		$db = $this->create_db();
		if ( ! $db->query( 'SET SESSION sql_require_primary_key = 1' ) ) {
			$this->markTestSkipped( 'sql_require_primary_key indisponible (MySQL 8.0.13+ requis) : ' . $db->last_error() );
		}
		$sql = "CREATE TABLE `wp_nokey` (\n  `url` text,\n  `n` int(11) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\nINSERT INTO `wp_nokey` (`url`,`n`) VALUES ('http://a.test',1),('http://b.test',2);\n"
			. "CREATE TABLE `wp_uniq` (\n  `k` varchar(50) NOT NULL,\n  `v` text,\n  UNIQUE KEY `k` (`k`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\nINSERT INTO `wp_uniq` (`k`,`v`) VALUES ('x','1');\n";

		$state = $this->import( $db, $sql );
		$this->assertSame( 0, $state['errors'] );
		$this->assertSame( '2', (string) $db->get_var( 'SELECT COUNT(*) FROM wp_nokey' ) );
		$this->assertSame( 'http://b.test', $db->get_var( 'SELECT url FROM wp_nokey WHERE n = 2' ) );
		$pk = $db->get_col( "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_nokey' AND CONSTRAINT_NAME = 'PRIMARY'" );
		$this->assertSame( array( 'lwb_row_id' ), $pk );
		$pk = $db->get_col( "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_uniq' AND CONSTRAINT_NAME = 'PRIMARY'" );
		$this->assertSame( array( 'k' ), $pk, 'Index unique sur colonne NOT NULL promu en clé primaire.' );
		$this->assertCount( 2, $state['fixes'] );
	}

	public function test_inserts_are_split_under_the_real_max_allowed_packet() {
		$root = $this->create_db();
		$old  = (int) $root->get_var( 'SELECT @@GLOBAL.max_allowed_packet' );
		if ( ! $root->query( 'SET GLOBAL max_allowed_packet = 1048576' ) ) {
			$this->markTestSkipped( 'Réglage global impossible : ' . $root->last_error() );
		}
		try {
			// Nouvelle connexion : la valeur de session est copiée de la valeur globale.
			$name = (string) $root->get_var( 'SELECT DATABASE()' );
			$db   = new Mysqli_Db( getenv( 'LWB_TEST_DB_HOST' ) ?: 'localhost', getenv( 'LWB_TEST_DB_USER' ) ?: 'root', (string) getenv( 'LWB_TEST_DB_PASSWORD' ), $name );
			$this->assertSame( '1048576', (string) $db->get_var( 'SELECT @@max_allowed_packet' ) );
			$tuples = array();
			for ( $i = 1; $i <= 3000; $i++ ) {
				$tuples[] = '(' . $i . ",'" . str_repeat( chr( 97 + $i % 26 ), 900 ) . "')";
			}
			$sql   = "CREATE TABLE `wp_big` (\n  `id` int(11) NOT NULL,\n  `v` text,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\nINSERT INTO `wp_big` (`id`,`v`) VALUES " . implode( ',', $tuples ) . ";\n";
			$state = $this->import( $db, $sql );
			$this->assertSame( 0, $state['errors'] );
			$this->assertSame( 1, $state['split'] );
			$this->assertSame( '3000', (string) $db->get_var( 'SELECT COUNT(*) FROM wp_big' ) );
			$this->assertSame( str_repeat( chr( 97 + 3000 % 26 ), 900 ), $db->get_var( 'SELECT v FROM wp_big WHERE id = 3000' ) );
			$db->close();
		} finally {
			$root->query( 'SET GLOBAL max_allowed_packet = ' . max( 1048576, $old ) );
		}
	}

	public function test_json_escaped_urls_are_found_by_the_sql_filter() {
		$db = $this->create_db();
		$db->query( 'CREATE TABLE wp_postmeta (meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, meta_value LONGTEXT) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci' );
		$db->query( "INSERT INTO wp_postmeta (meta_value) VALUES ('" . \LwbMigration\Db::escape( json_encode( array( 'link' => 'http://old.test/site/contact', 'pct' => '100%_x|y' ) ) ) . "')" );
		$sr    = new \LwbMigration\Search_Replace( \LwbMigration\Search_Replace::build_pairs( array( 'http://old.test/site' => 'https://new.test' ), array() ) );
		$state = array();
		$guard = 0;
		while ( ! $sr->run_table( $db, 'wp_postmeta', $state, new Runtime( 5 ) ) && $guard++ < 10 ) {
			continue;
		}
		$value = json_decode( (string) $db->get_var( 'SELECT meta_value FROM wp_postmeta' ), true );
		$this->assertSame( 'https://new.test/contact', $value['link'], 'URL échappée en JSON (http:\/\/) remplacée sur tous les serveurs.' );
		$this->assertSame( '100%_x|y', $value['pct'] );
	}

	public function test_views_are_listed_but_not_exported() {
		$db = $this->create_db();
		$db->query( 'CREATE TABLE wp_posts (ID INT NOT NULL PRIMARY KEY, post_title TEXT)' );
		$db->query( 'CREATE VIEW wp_titles AS SELECT post_title FROM wp_posts' );
		$db->query( 'CREATE VIEW other_view AS SELECT ID FROM wp_posts' );
		$this->assertSame( array( 'wp_titles' ), $db->list_views( 'wp_' ) );
		$this->assertSame( array( 'wp_posts' ), $db->list_tables( 'wp_' ) );

		$dumper = new Db_Dumper( $db, $this->tmp_dir() . '/db.sql' );
		$dumper->init( 'wp_' );
		$state = $dumper->get_state();
		$this->assertSame( array( 'wp_posts' ), $state['tables'] );
		$this->assertSame( array( 'wp_titles' ), $state['views'] );
	}
}
