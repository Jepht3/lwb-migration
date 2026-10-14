<?php
/**
 * Génère dist/latest.json et dist/notes.md pour une release.
 *
 *   php bin/manifest.php v1.1.0
 *
 * latest.json est lu par l'extension (classe Updater) pour proposer les mises à jour.
 *
 * @package LwbMigration
 */

// phpcs:ignoreFile -- outil de développement.

$root = dirname( __DIR__ );
$tag  = isset( $argv[1] ) ? $argv[1] : '';
$repo = getenv( 'GITHUB_REPOSITORY' ) ?: 'Jepht3/lwb-migration';

$main = file_get_contents( $root . '/lwb-migration.php' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $v );
preg_match( '/^\s*\*\s*Requires at least:\s*(\S+)/m', $main, $wp );
preg_match( '/^\s*\*\s*Requires PHP:\s*(\S+)/m', $main, $php );
$readme = file_get_contents( $root . '/readme.txt' );
preg_match( '/^Tested up to:\s*(\S+)/m', $readme, $tested );
$version = $v[1];

if ( '' !== $tag && ltrim( $tag, 'v' ) !== $version ) {
	fwrite( STDERR, "Le tag $tag ne correspond pas à la version de l'extension ($version).\n" );
	exit( 1 );
}

// Journal : section « = x.y.z = » du readme.txt.
$changelog = array();
if ( preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\s*\n(.*?)(?=^= |\z)/ms', $readme, $m ) ) {
	foreach ( preg_split( '/\r?\n/', trim( $m[1] ) ) as $line ) {
		if ( preg_match( '/^\*\s+(.+)$/', trim( $line ), $item ) ) {
			$changelog[] = $item[1];
		}
	}
}
if ( empty( $changelog ) ) {
	fwrite( STDERR, "Ajoutez la section « = $version = » au Changelog de readme.txt.\n" );
	exit( 1 );
}

$zip = $root . '/dist/lwb-migration.zip';
if ( ! is_file( $zip ) ) {
	fwrite( STDERR, "dist/lwb-migration.zip introuvable : lancez d'abord php bin/build.php.\n" );
	exit( 1 );
}

// Signature Ed25519 : graine de 32 octets (base64) dans le secret GitHub LWB_SIGNING_SEED.
$seed = base64_decode( (string) getenv( 'LWB_SIGNING_SEED' ), true );
if ( false === $seed || 32 !== strlen( $seed ) ) {
	fwrite( STDERR, "Secret LWB_SIGNING_SEED absent ou invalide : impossible de signer la publication.\n" );
	exit( 1 );
}
$pair       = sodium_crypto_sign_seed_keypair( $seed );
$public_b64 = base64_encode( sodium_crypto_sign_publickey( $pair ) );
$updater    = file_get_contents( $root . '/includes/class-updater.php' );
if ( ! preg_match( "/const PUBLIC_KEY = '([^']+)'/", $updater, $pk ) || $pk[1] !== $public_b64 ) {
	fwrite( STDERR, "LWB_SIGNING_SEED ne correspond pas à Updater::PUBLIC_KEY : les sites refuseraient cette mise à jour.\n" );
	exit( 1 );
}

$zip_url = "https://github.com/$repo/releases/download/v$version/lwb-migration.zip";
$sha256  = hash_file( 'sha256', $zip );
$message = implode( "\n", array( 'lwb-migration-release-v1', $version, $zip_url, $sha256 ) );

$manifest = array(
	'name'         => 'Lwb Migration',
	'version'      => $version,
	'zip_url'      => $zip_url,
	'sha256'       => $sha256,
	'signature'    => base64_encode( sodium_crypto_sign_detached( $message, sodium_crypto_sign_secretkey( $pair ) ) ),
	'size'         => filesize( $zip ),
	'requires'     => $wp[1],
	'requires_php' => $php[1],
	'tested'       => $tested[1],
	'published'    => gmdate( 'Y-m-d H:i:s' ),
	'homepage'     => 'https://lewebmax.com/lwb-migration/',
	'changelog'    => $changelog,
);
file_put_contents( $root . '/dist/latest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
file_put_contents( $root . '/dist/notes.md', "## Nouveautés\n\n- " . implode( "\n- ", $changelog ) . "\n\n**Téléchargement :** [lwb-migration.zip](https://github.com/$repo/releases/latest/download/lwb-migration.zip) · [Page de l’extension](https://lewebmax.com/lwb-migration/)\n\nLes sites équipés de Lwb Migration 1.1.0+ se voient proposer cette mise à jour automatiquement (empreinte SHA-256 : `{$manifest['sha256']}`).\n" );
echo "latest.json : version $version, sha256 {$manifest['sha256']}\n";
