<?php
/**
 * Rechercher/remplacer : données sérialisées, JSON, URL-encodées, base de données.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Db;
use LwbMigration\Runtime;
use LwbMigration\Search_Replace;

/** Classe de test avec propriétés de toutes visibilités. */
class Lwb_Fixture_Widget {
	public $title = 'Voir http://old.test/page';
	protected $link = 'http://old.test/contact';
	private $secret = 'aucun lien';
	public $child;
	public function get_link() { return $this->link; }
	public function get_secret() { return $this->secret; }
}

class SearchReplaceTest extends TestCase {

	private function engine() {
		return new Search_Replace(
			Search_Replace::build_pairs(
				array( 'http://old.test' => 'https://new.example/sub' ),
				array( '/var/www/old' => '/home/client/public_html' )
			)
		);
	}

	public function test_plain_string_and_untouched_values() {
		$sr = $this->engine();
		$this->assertSame( 'Lien https://new.example/sub/page', $sr->replace( 'Lien http://old.test/page' ) );
		$this->assertSame( 'rien à voir', $sr->replace( 'rien à voir' ) );
		$this->assertSame( 42, $sr->replace( 42 ) );
		$this->assertNull( $sr->replace( null ) );
	}

	public function test_scheme_variants_json_urlencoded_protocol_relative_and_paths() {
		$sr = $this->engine();
		$this->assertSame( 'https://new.example/sub/a', $sr->replace( 'https://old.test/a' ) );
		$this->assertSame( '{"u":"https:\/\/new.example\/sub\/img.png"}', $sr->replace( '{"u":"http:\/\/old.test\/img.png"}' ) );
		$this->assertSame( 'url=' . rawurlencode( 'https://new.example/sub' ) . '%2Fx', $sr->replace( 'url=' . rawurlencode( 'http://old.test' ) . '%2Fx' ) );
		$this->assertSame( 'src="//new.example/sub/x.js"', $sr->replace( 'src="//old.test/x.js"' ) );
		$this->assertSame( '/home/client/public_html/wp-content/uploads', $sr->replace( '/var/www/old/wp-content/uploads' ) );
		$this->assertSame( '{"p":"\/home\/client\/public_html\/a"}', $sr->replace( '{"p":"\/var\/www\/old\/a"}' ) );
	}

	public function test_new_url_containing_old_url_is_not_replaced_twice() {
		$sr = new Search_Replace( Search_Replace::build_pairs( array( 'http://site.test' => 'http://site.test/blog' ), array() ) );
		$this->assertSame( 'http://site.test/blog/a et //site.test/blog/b', $sr->replace( 'http://site.test/a et //site.test/b' ) );
	}

	public function test_serialized_array_lengths_are_recomputed() {
		$sr   = $this->engine();
		$data = array(
			'url'    => 'http://old.test/é',
			'nested' => array( 'deep' => array( 'http://old.test/1', 'x' ) ),
			'num'    => 3,
			'float'  => 0.1,
			'bool'   => true,
			'null'   => null,
		);
		$out  = $sr->replace( serialize( $data ) );
		$this->assertNotFalse( @unserialize( $out ) );
		$expected                       = $data;
		$expected['url']                = 'https://new.example/sub/é';
		$expected['nested']['deep'][0] = 'https://new.example/sub/1';
		$this->assertSame( $expected, unserialize( $out ) );
	}

	public function test_keys_are_never_replaced() {
		$sr  = $this->engine();
		$out = unserialize( $sr->replace( serialize( array( 'http://old.test' => 'http://old.test' ) ) ) );
		$this->assertSame( array( 'http://old.test' => 'https://new.example/sub' ), $out );
	}

	public function test_nested_objects_keep_class_and_private_properties() {
		$sr            = $this->engine();
		$widget        = new Lwb_Fixture_Widget();
		$widget->child = new \stdClass();
		$widget->child->url  = 'http://old.test/child';
		$widget->child->list = array( new \ArrayObject( array( 'opaque' ) ), 'http://old.test/l' );

		$out = $sr->replace( serialize( array( 'w' => $widget ) ) );
		$obj = unserialize( $out )['w'];
		$this->assertInstanceOf( Lwb_Fixture_Widget::class, $obj );
		$this->assertSame( 'Voir https://new.example/sub/page', $obj->title );
		$this->assertSame( 'https://new.example/sub/contact', $obj->get_link() );
		$this->assertSame( 'aucun lien', $obj->get_secret() );
		$this->assertSame( 'https://new.example/sub/child', $obj->child->url );
		$this->assertSame( 'https://new.example/sub/l', $obj->child->list[1] );
		$this->assertInstanceOf( \ArrayObject::class, $obj->child->list[0] );
	}

	public function test_unknown_classes_are_preserved_without_instantiation() {
		$sr  = $this->engine();
		$in  = 'O:18:"Plugin_Unknown_Cls":1:{s:3:"url";s:20:"http://old.test/abcd";}';
		$out = $sr->replace( $in );
		$this->assertSame( 'O:18:"Plugin_Unknown_Cls":1:{s:3:"url";s:28:"https://new.example/sub/abcd";}', $out );
	}

	public function test_double_serialized_and_json_inside_serialized() {
		$sr    = $this->engine();
		$inner = serialize( array( 'u' => 'http://old.test/in', 'j' => json_encode( array( 'img' => 'http://old.test/i.png' ) ) ) );
		$out   = $sr->replace( serialize( $inner ) );
		$back  = unserialize( unserialize( $out ) );
		$this->assertSame( 'https://new.example/sub/in', $back['u'] );
		$this->assertSame( array( 'img' => 'https://new.example/sub/i.png' ), json_decode( $back['j'], true ) );
	}

	public function test_references_and_special_floats() {
		$sr       = $this->engine();
		$a        = array( 'x' => 'http://old.test', 'inf' => INF, 'neg' => -1.5e-10 );
		$a['ref'] = &$a['x'];
		$out      = $sr->replace( serialize( $a ) );
		$back     = unserialize( $out );
		$this->assertSame( 'https://new.example/sub', $back['x'] );
		$this->assertSame( INF, $back['inf'] );
		$this->assertSame( -1.5e-10, $back['neg'] );
	}

	/**
	 * @requires PHP 8.1
	 */
	public function test_enum_tokens_are_kept() {
		$sr  = $this->engine();
		$in  = 'a:2:{i:0;E:11:"Suit:Hearts";i:1;s:15:"http://old.test";}';
		$out = $sr->replace( $in );
		$this->assertSame( 'a:2:{i:0;E:11:"Suit:Hearts";i:1;s:23:"https://new.example/sub";}', $out );
	}

	public function test_malformed_serialized_falls_back_to_plain_replace() {
		$sr = $this->engine();
		$this->assertSame( 's:99:"https://new.example/sub";', $sr->replace( 's:99:"http://old.test";' ) );
	}

	public function test_minimal_needles() {
		$needles = $this->engine()->get_needles();
		$this->assertContains( '//old.test', $needles );
		$this->assertNotContains( 'http://old.test', $needles );
	}

	public function test_run_table_with_pk_composite_and_no_pk_and_resume() {
		$db = $this->create_db();
		$db->query( 'CREATE TABLE wp_options (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191), option_value LONGTEXT)' );
		$db->query( 'CREATE TABLE wp_rel (a INT, b INT, v TEXT, PRIMARY KEY (a,b))' );
		$db->query( 'CREATE TABLE wp_nopk (v TEXT, n INT)' );
		$serialized = serialize( array( 'home' => 'http://old.test', 'path' => '/var/www/old/x' ) );
		for ( $i = 1; $i <= 300; $i++ ) {
			$db->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('o$i', " . Db::literal( 0 === $i % 3 ? $serialized : "valeur $i" ) . ')' );
			$db->query( 'INSERT INTO wp_rel VALUES (' . ( $i % 7 ) . ", $i, " . Db::literal( "http://old.test/$i" ) . ')' );
		}
		$db->query( "INSERT INTO wp_nopk VALUES ('http://old.test/a', 1), ('http://old.test/a', 1), ('autre', 2)" );

		$sr = $this->engine();
		foreach ( array( 'wp_options', 'wp_rel', 'wp_nopk' ) as $table ) {
			$state = array( 'batch' => 7 );
			$saved = $state;
			$steps = 0;
			do {
				// Reprise : on repart à chaque fois de l'état sauvegardé (JSON) avec un budget minuscule.
				$state = json_decode( json_encode( $saved ), true );
				$done  = $sr->run_table(
					$db,
					$table,
					$state,
					$this->spent_runtime(),
					function ( $s ) use ( &$saved ) {
						$saved = $s;
					}
				);
				$saved = $state;
				++$steps;
			} while ( ! $done && $steps < 2000 );
			$this->assertTrue( $done, "Table $table non terminée" );
		}
		$this->assertSame( '0', $db->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%old.test%' OR option_value LIKE '%/var/www/old%'" ) );
		$this->assertSame( '100', $db->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%new.example/sub%'" ) );
		$value = $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'o3'" );
		$this->assertSame( array( 'home' => 'https://new.example/sub', 'path' => '/home/client/public_html/x' ), unserialize( $value ) );
		$this->assertSame( '0', $db->get_var( "SELECT COUNT(*) FROM wp_rel WHERE v LIKE '%old.test%'" ) );
		$this->assertSame( '300', $db->get_var( 'SELECT COUNT(*) FROM wp_rel' ) );
		$this->assertSame( '2', $db->get_var( "SELECT COUNT(*) FROM wp_nopk WHERE v = 'https://new.example/sub/a'" ) );
	}
}
