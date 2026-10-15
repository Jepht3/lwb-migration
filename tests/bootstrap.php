<?php
/**
 * Amorçage des tests unitaires : classes autonomes, sans WordPress.
 *
 * Les tests base de données utilisent MySQL/MariaDB via les variables d'environnement
 * LWB_TEST_DB_HOST, LWB_TEST_DB_USER, LWB_TEST_DB_PASSWORD (par défaut localhost/root/vide)
 * et sont ignorés si le serveur est indisponible.
 *
 * @package LwbMigration
 */

define( 'LWB_MIGRATION_STANDALONE', true );

$lwb_includes = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'migration-exception', 'runtime', 'json-store', 'logger', 'db', 'mysqli-db', 'archiver', 'archive-reader', 'archive-crypto', 'sql-importer', 'search-replace', 'db-dumper', 'file-scanner', 'job', 'route', 'transfer-tools' ) as $lwb_class ) {
	require_once $lwb_includes . 'class-' . $lwb_class . '.php';
}
require_once __DIR__ . '/unit/TestCase.php';
