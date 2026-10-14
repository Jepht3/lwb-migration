<?php
/**
 * Génère installer.php : un fichier unique et autonome (sans WordPress).
 *
 * Les bibliothèques autonomes de l'extension (lecture d'archive, import SQL,
 * rechercher/remplacer…) sont intégrées au fichier, suivies du modèle
 * installer/installer-template.php et de la configuration du paquet.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Constructeur d'installeur.
 */
class Installer_Builder {

	/**
	 * Bibliothèques intégrées, dans l'ordre de dépendance.
	 *
	 * @var string[]
	 */
	private static $libraries = array(
		'class-migration-exception.php',
		'class-runtime.php',
		'class-json-store.php',
		'class-logger.php',
		'class-db.php',
		'class-mysqli-db.php',
		'class-archiver.php',
		'class-archive-reader.php',
		'class-sql-importer.php',
		'class-search-replace.php',
	);

	/**
	 * Construit le code de l'installeur.
	 *
	 * @param array $config Configuration injectée.
	 * @return string
	 * @throws Migration_Exception Modèle introuvable.
	 */
	public static function render( array $config ) {
		$code  = "<?php\n";
		$code .= "/**\n * Lwb Migration – installeur autonome.\n *\n * Déposez ce fichier et l'archive à la racine du site de destination,\n * puis ouvrez https://votre-domaine/installer.php dans votre navigateur.\n *\n * Généré le " . gmdate( 'Y-m-d H:i:s' ) . ' UTC par Lwb Migration ' . LWB_MIGRATION_VERSION . ".\n */\n\n";
		$code .= "namespace LwbMigration;\n\n";
		$code .= "if ( ! \\defined( 'LWB_MIGRATION_STANDALONE' ) ) {\n\t\\define( 'LWB_MIGRATION_STANDALONE', true );\n}\n";
		foreach ( self::$libraries as $lib ) {
			$code .= "\n" . self::strip_open_tag( self::read( LWB_MIGRATION_DIR . 'includes/' . $lib ) );
		}
		$template = self::strip_open_tag( self::read( LWB_MIGRATION_DIR . 'installer/installer-template.php' ) );
		$export   = var_export( $config, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- génération de code.
		$count    = 0;
		$template = preg_replace_callback(
			'#/\*LWB_CONFIG_START\*/.*?/\*LWB_CONFIG_END\*/\s*;?#s',
			function () use ( $export ) {
				// Le point-virgule est fourni ici : la mise en forme automatique du modèle peut le
				// déplacer à l'intérieur des marqueurs.
				return $export . ';';
			},
			$template,
			1,
			$count
		);
		if ( 1 !== $count ) {
			throw new Migration_Exception( 'Modèle d’installeur invalide.', 'Réinstallez l’extension Lwb Migration.' );
		}
		return $code . "\n" . $template;
	}

	/**
	 * Écrit l'installeur sur disque.
	 *
	 * @param string $target Chemin.
	 * @param array  $config Configuration.
	 * @return int Taille.
	 * @throws Migration_Exception Écriture impossible.
	 */
	public static function build( $target, array $config ) {
		$code = self::render( $config );
		if ( false === file_put_contents( $target, $code ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new Migration_Exception(
				__( 'Impossible d’écrire installer.php.', 'lwb-migration' ),
				__( 'Vérifiez les permissions d’écriture et l’espace disque.', 'lwb-migration' )
			);
		}
		return strlen( $code );
	}

	/**
	 * Lit un fichier source de l'extension.
	 *
	 * @param string $file Fichier.
	 * @return string
	 * @throws Migration_Exception Fichier absent.
	 */
	private static function read( $file ) {
		$code = is_file( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $code ) {
			throw new Migration_Exception(
				sprintf( 'Fichier de l’extension manquant : %s', basename( $file ) ),
				'Réinstallez l’extension Lwb Migration.'
			);
		}
		return $code;
	}

	/**
	 * Retire la balise d'ouverture PHP initiale.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function strip_open_tag( $code ) {
		return (string) preg_replace( '/^\s*<\?php\s*/', '', $code, 1 );
	}
}
