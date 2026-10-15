<?php
/**
 * Migration avant le changement de DNS : routage vers le nouveau serveur par adresse IP
 * (résolution forcée, comme curl --resolve) ou par URL temporaire.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Migration_Exception;
use LwbMigration\Route;

class RouteTest extends TestCase {

	const KEY_URL = 'https://aea-serratrice.fr/wp-admin/admin-ajax.php';

	public function test_empty_input_means_no_route() {
		$this->assertNull( Route::parse( '', self::KEY_URL ) );
		$this->assertNull( Route::parse( "  \n", self::KEY_URL ) );
	}

	/**
	 * @dataProvider ips
	 */
	public function test_ip_addresses( $input, $expected ) {
		$this->assertSame( array( 'ip' => $expected ), Route::parse( $input, self::KEY_URL ) );
	}

	public static function ips() {
		return array(
			'IPv4'                  => array( '203.0.113.10', '203.0.113.10' ),
			'IPv4 avec espaces'     => array( ' 203.0.113.10 ', '203.0.113.10' ),
			'IPv6'                  => array( '2001:db8::1', '2001:db8::1' ),
			'IPv6 entre crochets'   => array( '[2001:db8::1]', '2001:db8::1' ),
			'adresse locale (test)' => array( '127.0.0.1', '127.0.0.1' ),
		);
	}

	public function test_temporary_url() {
		$this->assertSame( array( 'url' => 'https://srv12.hebergeur.fr/~compte' ), Route::parse( 'https://srv12.hebergeur.fr/~compte/', self::KEY_URL ) );
	}

	public function test_temporary_url_must_use_https() {
		$this->expectException( Migration_Exception::class );
		Route::parse( 'http://srv12.hebergeur.fr/~compte', self::KEY_URL );
	}

	public function test_http_allowed_for_local_tests_only() {
		$this->assertSame( array( 'url' => 'http://127.0.0.1:8105' ), Route::parse( 'http://127.0.0.1:8105', self::KEY_URL, true ) );
	}

	/**
	 * @dataProvider invalid_inputs
	 */
	public function test_invalid_input_is_refused( $input ) {
		$this->expectException( Migration_Exception::class );
		Route::parse( $input, self::KEY_URL );
	}

	public static function invalid_inputs() {
		return array(
			'nom d’hôte seul'    => array( 'srv12.hebergeur.fr' ),
			'IP incomplète'      => array( '203.0.113' ),
			'schéma non HTTP'    => array( 'ftp://203.0.113.10' ),
			'espaces dans l’URL' => array( 'https://srv12 hebergeur.fr' ),
		);
	}

	public function test_same_address_as_the_key_needs_no_route() {
		$this->assertNull( Route::parse( 'https://AEA-serratrice.fr/', self::KEY_URL ) );
	}

	public function test_site_base() {
		$this->assertSame( 'https://aea-serratrice.fr', Route::site_base( self::KEY_URL ) );
		$this->assertSame( 'https://exemple.fr/wordpress', Route::site_base( 'https://exemple.fr/wordpress/wp-admin/admin-ajax.php?action=x' ) );
	}

	public function test_temporary_url_rewrites_the_site_base_only() {
		$route = array( 'url' => 'https://srv12.hebergeur.fr/~compte' );
		$this->assertSame(
			'https://srv12.hebergeur.fr/~compte/wp-admin/admin-ajax.php?action=lwb_migration_receive&op=hello',
			Route::apply( $route, self::KEY_URL . '?action=lwb_migration_receive&op=hello', 'https://aea-serratrice.fr' )
		);
		$this->assertSame(
			'https://srv12.hebergeur.fr/~compte/installer.php?lwb_token=abc',
			Route::apply( $route, 'https://aea-serratrice.fr/installer.php?lwb_token=abc', 'https://aea-serratrice.fr' )
		);
	}

	public function test_ip_route_keeps_the_real_url() {
		// Le nom d'hôte reste dans l'URL : en-tête Host, SNI et certificat vérifié sur le vrai nom.
		$this->assertSame( self::KEY_URL, Route::apply( array( 'ip' => '203.0.113.10' ), self::KEY_URL, 'https://aea-serratrice.fr' ) );
		$this->assertSame( self::KEY_URL, Route::apply( null, self::KEY_URL, 'https://aea-serratrice.fr' ) );
	}

	/**
	 * @dataProvider resolve_entries
	 */
	public function test_resolve_entry( $ip, $url, $expected ) {
		$this->assertSame( $expected, Route::resolve_entry( array( 'ip' => $ip ), $url ) );
	}

	public static function resolve_entries() {
		return array(
			'HTTPS, port 443 implicite' => array( '203.0.113.10', self::KEY_URL, 'aea-serratrice.fr:443:203.0.113.10' ),
			'HTTP, port 80 implicite'   => array( '203.0.113.10', 'http://exemple.fr/', 'exemple.fr:80:203.0.113.10' ),
			'port explicite'            => array( '127.0.0.1', 'http://nouveau-site.test:8105/wp-admin/admin-ajax.php', 'nouveau-site.test:8105:127.0.0.1' ),
			'IPv6 entre crochets'       => array( '2001:db8::1', self::KEY_URL, 'aea-serratrice.fr:443:[2001:db8::1]' ),
		);
	}

	public function test_no_resolve_without_ip_route() {
		$this->assertSame( '', Route::resolve_entry( null, self::KEY_URL ) );
		$this->assertSame( '', Route::resolve_entry( array( 'url' => 'https://srv12.hebergeur.fr' ), self::KEY_URL ) );
	}

	public function test_hosts_line() {
		$this->assertSame( '203.0.113.10 aea-serratrice.fr', Route::hosts_line( array( 'ip' => '203.0.113.10' ), 'https://aea-serratrice.fr' ) );
		$this->assertSame( '', Route::hosts_line( array( 'url' => 'https://srv12.hebergeur.fr' ), 'https://aea-serratrice.fr' ) );
	}

	public function test_cookie_value() {
		$headers = array(
			'other=1; path=/',
			'lwb_installer_auth=1760000000.abcdef; expires=Thu, 16 Oct 2026 10:00:00 GMT; path=/; secure; HttpOnly; SameSite=Lax',
		);
		$this->assertSame( '1760000000.abcdef', Route::cookie_value( $headers, 'lwb_installer_auth' ) );
		$this->assertSame( '1760000000.abcdef', Route::cookie_value( $headers[1], 'lwb_installer_auth' ) );
		$this->assertNull( Route::cookie_value( 'lwb_installer_auth=; path=/', 'lwb_installer_auth' ) );
		$this->assertNull( Route::cookie_value( array(), 'lwb_installer_auth' ) );
	}
}
