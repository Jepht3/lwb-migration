<?php
/**
 * Adaptation des CREATE TABLE et des INSERT aux serveurs stricts ou anciens.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Sql_Compat;

class SqlCompatTest extends TestCase {

	const MYISAM = "CREATE TABLE `wp_stats` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  `page` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',\n  `hits` int(11) DEFAULT NULL COMMENT 'ENGINE=MyISAM (texte)',\n  PRIMARY KEY (`id`),\n  KEY `page` (`page`)\n) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=FIXED";

	const ARIA = "CREATE TABLE `wp_aria` (\n  `id` int(11) NOT NULL,\n  `v` text DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci PAGE_CHECKSUM=1 TRANSACTIONAL=1 ROW_FORMAT=PAGE";

	public function test_myisam_and_aria_tables_are_converted_to_innodb() {
		$sql = Sql_Compat::convert_engine( self::MYISAM );
		$this->assertSame( 'innodb', Sql_Compat::engine( $sql ) );
		$this->assertStringContainsString( 'ENGINE=InnoDB AUTO_INCREMENT=12', $sql );
		$this->assertStringNotContainsString( 'ROW_FORMAT=FIXED', $sql );
		$this->assertStringContainsString( "COMMENT 'ENGINE=MyISAM (texte)'", $sql, 'Le contenu des colonnes n’est jamais modifié.' );

		$aria = Sql_Compat::convert_engine( self::ARIA );
		$this->assertSame( 'innodb', Sql_Compat::engine( $aria ) );
		$this->assertDoesNotMatchRegularExpression( '/PAGE_CHECKSUM|TRANSACTIONAL|ROW_FORMAT=PAGE/', $aria );

		$innodb = str_replace( 'ENGINE=MyISAM', 'ENGINE=InnoDB', self::MYISAM );
		$this->assertSame( $innodb, Sql_Compat::convert_engine( $innodb ), 'Table InnoDB inchangée.' );
		$this->assertFalse( Sql_Compat::is_myisam_like( $innodb ) );
	}

	public function test_primary_key_detection() {
		$this->assertTrue( Sql_Compat::has_primary_key( self::MYISAM ) );
		$this->assertTrue( Sql_Compat::has_primary_key( "CREATE TABLE `t` (\n  `id` int NOT NULL PRIMARY KEY,\n  `v` text\n)" ) );
		$this->assertFalse( Sql_Compat::has_primary_key( "CREATE TABLE `t` (\n  `v` text COMMENT 'pas de PRIMARY KEY ici'\n) ENGINE=InnoDB" ) );
	}

	public function test_unique_not_null_key_becomes_the_primary_key() {
		$sql = "CREATE TABLE `wp_map` (\n  `a` bigint(20) NOT NULL,\n  `b` varchar(20) NOT NULL,\n  `c` text,\n  UNIQUE KEY `ab` (`a`,`b`),\n  KEY `c` (`c`(10))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$out = Sql_Compat::add_primary_key( $sql );
		$this->assertStringContainsString( 'PRIMARY KEY (`a`,`b`)', $out );
		$this->assertStringNotContainsString( 'UNIQUE KEY `ab`', $out );
		$this->assertStringNotContainsString( Sql_Compat::ROW_ID, $out );
		$this->assertTrue( Sql_Compat::has_primary_key( $out ) );
	}

	public function test_nullable_unique_key_is_not_used_and_a_row_id_is_added() {
		$sql = "CREATE TABLE `wp_nokey` (\n  `url` text,\n  `slug` varchar(50) DEFAULT NULL,\n  UNIQUE KEY `slug` (`slug`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$out = Sql_Compat::add_primary_key( $sql );
		$this->assertStringContainsString( '`lwb_row_id` bigint unsigned NOT NULL AUTO_INCREMENT,', $out );
		$this->assertStringContainsString( 'PRIMARY KEY (`lwb_row_id`)', $out );
		$this->assertStringContainsString( 'UNIQUE KEY `slug` (`slug`)', $out );
		$this->assertLessThan( strpos( $out, 'PRIMARY KEY' ), strpos( $out, '`slug` varchar' ), 'Colonne ajoutée après les colonnes existantes.' );
		$this->assertStringContainsString( 'AUTO_INCREMENT INVISIBLE', Sql_Compat::add_primary_key( $sql, true ) );
	}

	public function test_auto_increment_column_becomes_the_primary_key() {
		$sql = "CREATE TABLE `wp_log` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  `msg` text,\n  KEY `id` (`id`)\n) ENGINE=MyISAM";
		$out = Sql_Compat::add_primary_key( $sql );
		$this->assertStringContainsString( 'PRIMARY KEY (`id`)', $out );
		$this->assertStringNotContainsString( Sql_Compat::ROW_ID, $out );
	}

	public function test_invisible_columns_need_mysql_8_0_23() {
		$this->assertTrue( Sql_Compat::supports_invisible( '8.0.36' ) );
		$this->assertTrue( Sql_Compat::supports_invisible( '8.4.2-log' ) );
		$this->assertFalse( Sql_Compat::supports_invisible( '8.0.22' ) );
		$this->assertFalse( Sql_Compat::supports_invisible( '5.7.44' ) );
		$this->assertFalse( Sql_Compat::supports_invisible( '10.11.8-MariaDB' ) );
	}

	public function test_indexes_are_shortened_to_767_bytes() {
		$sql = "CREATE TABLE `wp_redirects` (\n  `id` int NOT NULL,\n  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,\n  `latin` varchar(255) CHARACTER SET latin1 NOT NULL,\n  `code` char(3) NOT NULL,\n  `body` text,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `url` (`url`),\n  KEY `latin` (`latin`),\n  KEY `body` (`body`(250)),\n  FULLTEXT KEY `ft` (`body`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
		$out = Sql_Compat::shorten_indexes( $sql, 767 );
		$this->assertStringContainsString( 'UNIQUE KEY `url` (`url`(191))', $out );
		$this->assertStringContainsString( 'KEY `latin` (`latin`)', $out, 'latin1 : 255 octets, sous la limite.' );
		$this->assertStringContainsString( 'KEY `body` (`body`(250))', $out, 'Préfixe explicite sur une colonne TEXT : la longueur de la colonne n’est pas connue, inchangé.' );
		$this->assertStringContainsString( 'FULLTEXT KEY `ft` (`body`)', $out );
		$this->assertStringContainsString( '`url` varchar(255)', $out, 'La colonne garde sa longueur.' );

		$this->assertSame( $sql, Sql_Compat::shorten_indexes( $sql, 3072 ), 'Sous la limite : instruction inchangée.' );
	}

	public function test_composite_index_respects_the_total_key_length() {
		$sql = "CREATE TABLE `wp_meta` (\n  `meta_key` varchar(255) DEFAULT NULL,\n  `meta_value` varchar(200) DEFAULT NULL,\n  `post_id` bigint NOT NULL,\n  KEY `kv` (`meta_key`,`meta_value`,`post_id`)\n) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4";
		$limits = Sql_Compat::index_limits_from_error( 'Specified key was too long; max key length is 1000 bytes', $sql );
		$this->assertSame( array( 1000, 1000 ), $limits );
		$out = Sql_Compat::shorten_indexes( $sql, $limits[0], $limits[1] );
		$this->assertMatchesRegularExpression( '/KEY `kv` \(`meta_key`\((\d+)\),`meta_value`(?:\((\d+)\))?,`post_id`\)/', $out );
		preg_match( '/KEY `kv` \(`meta_key`\((\d+)\),`meta_value`(?:\((\d+)\))?,`post_id`\)/', $out, $m );
		$total = 4 * (int) $m[1] + 4 * ( isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 200 ) + 8;
		$this->assertLessThanOrEqual( 1000, $total );

		$this->assertSame( array( 767, 3072 ), Sql_Compat::index_limits_from_error( 'Index column size too large. The maximum column size is 767 bytes.', str_replace( 'MyISAM', 'InnoDB', $sql ) ) );
		$this->assertNull( Sql_Compat::index_limits_from_error( 'Duplicate entry', $sql ) );
	}

	public function test_large_inserts_are_split_under_max_allowed_packet() {
		$tuples = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$tuples[] = '(' . $i . ",'texte avec ( parenthèses ), virgules, \\' apostrophe et \\\\',0x00ff,NULL,'" . str_repeat( 'x', $i % 50 ) . "')";
		}
		$sql    = 'INSERT INTO `wp_t` (`id`,`txt`,`bin`,`n`,`pad`) VALUES ' . implode( ',', $tuples );
		$pieces = Sql_Compat::split_insert( $sql . ';', 2048 );
		$this->assertGreaterThan( 5, count( $pieces ) );
		$values = array();
		foreach ( $pieces as $piece ) {
			$this->assertLessThanOrEqual( 2048, strlen( $piece ) );
			$this->assertStringStartsWith( 'INSERT INTO `wp_t` (`id`,`txt`,`bin`,`n`,`pad`) VALUES (', $piece );
			$values[] = substr( $piece, strlen( 'INSERT INTO `wp_t` (`id`,`txt`,`bin`,`n`,`pad`) VALUES ' ) );
		}
		$this->assertSame( implode( ',', $tuples ), implode( ',', $values ), 'Toutes les lignes, dans l’ordre, sans altération.' );

		$this->assertSame( array( 'INSERT INTO `t` VALUES (1)' ), Sql_Compat::split_insert( 'INSERT INTO `t` VALUES (1);', 2048 ), 'Instruction courte inchangée.' );
		$this->assertSame( array( 'UPDATE `t` SET a = 1' ), Sql_Compat::split_insert( 'UPDATE `t` SET a = 1', 5 ), 'Autre instruction inchangée.' );
		$big = "INSERT INTO `t` (`a`) VALUES ('" . str_repeat( 'y', 5000 ) . "')";
		$this->assertSame( array( $big ), Sql_Compat::split_insert( $big, 2048 ), 'Ligne unique plus grande que la limite : laissée entière.' );
	}

	public function test_bytes_per_char() {
		$this->assertSame( 4, Sql_Compat::bytes_per_char( 'utf8mb4' ) );
		$this->assertSame( 3, Sql_Compat::bytes_per_char( 'UTF8' ) );
		$this->assertSame( 1, Sql_Compat::bytes_per_char( 'latin1' ) );
	}
}
