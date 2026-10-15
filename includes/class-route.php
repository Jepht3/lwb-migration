<?php
/**
 * Routage de la migration directe avant le changement de DNS.
 *
 * Le nom de domaine du site de destination pointe encore vers l'ancien serveur : les
 * requêtes doivent atteindre le nouveau serveur sans changer d'adresse.
 *
 * - Adresse IP : la requête garde l'URL définitive (nom d'hôte, en-tête Host, SNI et
 *   vérification du certificat sur le vrai nom), seule la résolution DNS est remplacée,
 *   comme `curl --resolve` (CURLOPT_RESOLVE).
 * - URL temporaire (ex. https://serveur.hebergeur.fr/~compte) : la base de l'URL de la
 *   clé est remplacée par l'URL temporaire.
 *
 * Dans les deux cas, le site est installé avec son URL définitive.
 *
 * Classe autonome (sans WordPress) : testée unitairement.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Route vers le nouveau serveur.
 */
class Route {

	/**
	 * Analyse la saisie « adresse du nouveau serveur ».
	 *
	 * @param string $input      Adresse IP (v4, v6, éventuellement entre crochets) ou URL.
	 * @param string $target_url URL de réception de la clé (URL définitive).
	 * @param bool   $allow_http HTTP non chiffré autorisé (tests locaux uniquement).
	 * @return array|null ip, ou url ; null si la saisie est vide.
	 * @throws Migration_Exception Saisie invalide.
	 */
	public static function parse( $input, $target_url, $allow_http = false ) {
		$input = trim( (string) $input );
		if ( '' === $input ) {
			return null;
		}
		$ip = trim( $input, '[]' );
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array( 'ip' => $ip );
		}
		if ( ! preg_match( '#^https?://[^\s/?\#]+[^\s?\#]*$#i', $input ) ) {
			throw new Migration_Exception(
				'Adresse du nouveau serveur invalide : ' . $input,
				'Saisissez son adresse IP (ex. 203.0.113.10, indiquée dans le panneau de l’hébergeur) ou son URL temporaire complète (ex. https://serveur.hebergeur.fr/~compte).'
			);
		}
		if ( 0 !== stripos( $input, 'https://' ) && ! $allow_http ) {
			throw new Migration_Exception(
				'L’URL temporaire doit utiliser HTTPS.',
				'Utilisez l’adresse IP du nouveau serveur : la connexion restera chiffrée et vérifiée sur le vrai nom de domaine.'
			);
		}
		$url = rtrim( $input, '/' );
		if ( strtolower( self::site_base( $target_url ) ) === strtolower( $url ) ) {
			return null; // Même adresse que la clé : aucun routage nécessaire.
		}
		return array( 'url' => $url );
	}

	/**
	 * Base du site à partir de l'URL de réception (…/wp-admin/admin-ajax.php).
	 *
	 * @param string $ajax_url URL de réception.
	 * @return string
	 */
	public static function site_base( $ajax_url ) {
		$url = preg_replace( '#[?\#].*$#', '', (string) $ajax_url );
		return rtrim( (string) preg_replace( '#/wp-admin/admin-ajax\.php$#', '', $url ), '/' );
	}

	/**
	 * Schéma, hôte et port d'une URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( $url ) {
		$p = parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/**
	 * URL à appeler réellement.
	 *
	 * @param array|null $route    Route (null : aucune).
	 * @param string     $url      URL définitive (du site de destination).
	 * @param string     $site_url Base définitive du site de destination.
	 * @return string
	 */
	public static function apply( $route, $url, $site_url ) {
		if ( empty( $route['url'] ) ) {
			return $url;
		}
		$base = rtrim( (string) $site_url, '/' );
		if ( '' !== $base && 0 === strpos( $url, $base ) ) {
			return $route['url'] . substr( $url, strlen( $base ) );
		}
		// URL hors du site (ne devrait pas arriver) : on remplace au moins l'origine.
		$origin = self::origin( $url );
		return '' !== $origin && 0 === stripos( $url, $origin ) ? self::origin( $route['url'] ) . substr( $url, strlen( $origin ) ) : $url;
	}

	/**
	 * Entrée CURLOPT_RESOLVE (« hôte:port:ip ») pour une URL.
	 *
	 * @param array|null $route Route.
	 * @param string     $url   URL appelée.
	 * @return string Chaîne vide si aucune résolution forcée.
	 */
	public static function resolve_entry( $route, $url ) {
		if ( empty( $route['ip'] ) ) {
			return '';
		}
		$p = parse_url( (string) $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		$port = isset( $p['port'] ) ? (int) $p['port'] : ( 'https' === strtolower( (string) $p['scheme'] ) ? 443 : 80 );
		$ip   = (string) $route['ip'];
		if ( false !== strpos( $ip, ':' ) ) {
			$ip = '[' . $ip . ']';
		}
		return trim( $p['host'], '[]' ) . ':' . $port . ':' . $ip;
	}

	/**
	 * Ligne du fichier hosts équivalente (pour ouvrir le nouveau site avant la bascule DNS).
	 *
	 * @param array|null $route Route.
	 * @param string     $url   URL définitive.
	 * @return string
	 */
	public static function hosts_line( $route, $url ) {
		if ( empty( $route['ip'] ) ) {
			return '';
		}
		$host = parse_url( (string) $url, PHP_URL_HOST );
		return $host ? $route['ip'] . ' ' . $host : '';
	}

	/**
	 * Description lisible de la route (journal).
	 *
	 * @param array|null $route Route.
	 * @return string
	 */
	public static function describe( $route ) {
		if ( ! empty( $route['ip'] ) ) {
			return 'adresse IP ' . $route['ip'] . ' (résolution forcée, certificat vérifié sur le vrai nom)';
		}
		if ( ! empty( $route['url'] ) ) {
			return 'URL temporaire ' . $route['url'];
		}
		return 'DNS';
	}

	/**
	 * Lit un cookie dans des en-têtes Set-Cookie bruts.
	 *
	 * @param string|string[] $headers En-têtes Set-Cookie.
	 * @param string          $name    Nom du cookie.
	 * @return string|null
	 */
	public static function cookie_value( $headers, $name ) {
		foreach ( (array) $headers as $header ) {
			$pair = explode( ';', (string) $header, 2 );
			$kv   = explode( '=', trim( $pair[0] ), 2 );
			if ( 2 === count( $kv ) && $kv[0] === $name && '' !== $kv[1] ) {
				return $kv[1];
			}
		}
		return null;
	}
}
