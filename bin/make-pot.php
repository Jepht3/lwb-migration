<?php
/**
 * Génère languages/lwb-migration.pot (sans WP-CLI) : chaînes PHP et JavaScript.
 *
 *   php bin/make-pot.php
 *
 * @package LwbMigration
 */

// phpcs:ignoreFile -- outil de développement.

$root      = dirname( __DIR__ );
$functions = array(
	'__'         => 1,
	'_e'         => 1,
	'esc_html__' => 1,
	'esc_html_e' => 1,
	'esc_attr__' => 1,
	'esc_attr_e' => 1,
);
$entries   = array();

$add = function ( $msgid, $ref, $comment ) use ( &$entries ) {
	if ( ! isset( $entries[ $msgid ] ) ) {
		$entries[ $msgid ] = array( 'refs' => array(), 'comments' => array() );
	}
	$entries[ $msgid ]['refs'][] = $ref;
	if ( $comment ) {
		$entries[ $msgid ]['comments'][ $comment ] = true;
	}
};

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = $file->getPathname();
	$rel  = substr( $path, strlen( $root ) + 1 );
	if ( preg_match( '#^(vendor|tests|bin|dist|installer)/#', $rel ) ) {
		continue;
	}
	if ( 'php' === $file->getExtension() ) {
		$tokens  = token_get_all( file_get_contents( $path ) );
		$comment = '';
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && T_COMMENT === $t[0] && false !== stripos( $t[1], 'translators:' ) ) {
				$comment = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $t[1] ) );
				continue;
			}
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $functions[ $t[1] ] ) ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}
			++$j;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( ! is_array( $tokens[ $j ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
				continue;
			}
			$msgid = eval( 'return ' . $tokens[ $j ][1] . ';' );
			$add( $msgid, $rel . ':' . $tokens[ $j ][2], $comment );
			$comment = '';
		}
	} elseif ( 'js' === $file->getExtension() ) {
		foreach ( file( $path ) as $n => $line ) {
			if ( preg_match_all( "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*\\)/u", $line, $m ) ) {
				foreach ( $m[1] as $msgid ) {
					$add( stripcslashes( $msgid ), $rel . ':' . ( $n + 1 ), '' );
				}
			}
		}
	}
}

$escape = function ( $s ) {
	return addcslashes( $s, "\\\"\n\t" );
};
$out  = "# Copyright (C) " . gmdate( 'Y' ) . " Lwb Migration\n";
$out .= "# Ce fichier est distribué sous la même licence que l'extension Lwb Migration (GPL-2.0-or-later).\n";
$out .= "# Langue source : français.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
preg_match( "/Version:\\s*([0-9.]+)/", (string) file_get_contents( dirname( __DIR__ ) . "/lwb-migration.php" ), $lwb_v );
$out .= "\"Project-Id-Version: Lwb Migration " . $lwb_v[1] . "\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://github.com/Jepht3/lwb-migration/issues\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i+0000' ) . "\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"X-Domain: lwb-migration\\n\"\n\n";
foreach ( $entries as $msgid => $e ) {
	foreach ( array_keys( $e['comments'] ) as $c ) {
		$out .= "#. $c\n";
	}
	$out .= '#: ' . implode( ' ', array_slice( $e['refs'], 0, 6 ) ) . "\n";
	if ( preg_match( '/%(\d\$)?[sd]/', $msgid ) ) {
		$out .= "#, php-format\n";
	}
	$out .= 'msgid "' . $escape( $msgid ) . "\"\nmsgstr \"\"\n\n";
}
file_put_contents( $root . '/languages/lwb-migration.pot', $out );
echo count( $entries ) . " chaînes extraites dans languages/lwb-migration.pot\n";
