<?php
/**
 * Construit dist/lwb-migration.zip (prêt à installer dans WordPress).
 *
 *   php bin/build.php
 *
 * @package LwbMigration
 */

// phpcs:ignoreFile -- outil de développement.

$root   = dirname( __DIR__ );
$ignore = array_filter( array_map( 'trim', file( $root . '/.distignore' ) ) );
$dist   = $root . '/dist';
@mkdir( $dist );
$target = $dist . '/lwb-migration.zip';
@unlink( $target );

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Impossible de créer $target\n" );
	exit( 1 );
}
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $it as $file ) {
	$rel = substr( $file->getPathname(), strlen( $root ) );
	foreach ( $ignore as $pattern ) {
		if ( 0 === strpos( $rel . '/', rtrim( $pattern, '/' ) . '/' ) ) {
			continue 2;
		}
	}
	$zip->addFile( $file->getPathname(), 'lwb-migration' . $rel );
	++$count;
}
$zip->close();
printf( "%s : %d fichiers, %s Ko\n", $target, $count, number_format( filesize( $target ) / 1024, 1, ',', ' ' ) );
