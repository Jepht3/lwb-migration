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

$manifest = array(
	'name'         => 'Lwb Migration',
	'version'      => $version,
	'zip_url'      => "https://github.com/$repo/releases/download/v$version/lwb-migration.zip",
	'sha256'       => hash_file( 'sha256', $zip ),
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
