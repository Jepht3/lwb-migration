<?php
/**
 * Inventaire des fichiers à archiver, par lots et reprenable.
 *
 * Le parcours utilise une pile de dossiers sauvegardée dans l'état : un site de
 * plusieurs centaines de milliers de fichiers est listé en plusieurs requêtes.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Scanner de fichiers.
 */
class File_Scanner {

	/**
	 * Fichier liste (une entrée JSON par ligne).
	 *
	 * @var string
	 */
	private $list_file;

	/**
	 * Règles d'exclusion.
	 *
	 * @var array
	 */
	private $rules;

	/**
	 * État.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Avertissements.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructeur.
	 *
	 * @param string $list_file Fichier liste.
	 * @param array  $rules     Règles : paths (chemins absolus exclus), names (noms exclus),
	 *                          extensions (extensions exclues), max_size (octets, 0 = illimité).
	 * @param array  $state     État (reprise).
	 */
	public function __construct( $list_file, array $rules, array $state = array() ) {
		$this->list_file           = $list_file;
		$this->rules               = array_merge(
			array(
				'paths'      => array(),
				'names'      => array(),
				'extensions' => array(),
				'max_size'   => 0,
			),
			$rules
		);
		$this->rules['paths']      = array_map( array( __CLASS__, 'normalize' ), $this->rules['paths'] );
		$this->rules['names']      = array_map( 'strtolower', $this->rules['names'] );
		$this->rules['extensions'] = array_map( 'strtolower', $this->rules['extensions'] );
		$this->state               = array_merge(
			array(
				'stack'       => array(),
				'list_offset' => 0,
				'files'       => 0,
				'dirs'        => 0,
				'bytes'       => 0,
				'excluded'    => 0,
				'done'        => false,
			),
			$state
		);
	}

	/**
	 * Normalise un chemin (barres obliques, sans barre finale).
	 *
	 * @param string $path Chemin.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/**
	 * Ajoute une racine à parcourir.
	 *
	 * @param string $dir    Dossier absolu.
	 * @param string $prefix Préfixe dans l'archive.
	 * @return void
	 */
	public function add_root( $dir, $prefix ) {
		$this->state['stack'][] = array( self::normalize( $dir ), rtrim( $prefix, '/' ) );
	}

	/**
	 * Ajoute directement un fichier isolé à la liste.
	 *
	 * @param string $file Fichier.
	 * @param string $name Nom dans l'archive.
	 * @return void
	 */
	public function add_single_file( $file, $name ) {
		$h = $this->open_list();
		$this->emit( $h, 'f', $file, $name, (int) @filesize( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->close_list( $h );
	}

	/**
	 * État.
	 *
	 * @return array
	 */
	public function get_state() {
		return $this->state;
	}

	/**
	 * Avertissements.
	 *
	 * @return string[]
	 */
	public function take_warnings() {
		$w              = $this->warnings;
		$this->warnings = array();
		return $w;
	}

	/**
	 * Ouvre la liste au point de contrôle.
	 *
	 * @return resource
	 * @throws Migration_Exception Fichier inaccessible.
	 */
	private function open_list() {
		$h = @fopen( $this->list_file, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			throw new Migration_Exception( 'Impossible de créer la liste des fichiers.', 'Vérifiez les permissions du dossier de stockage.' );
		}
		ftruncate( $h, (int) $this->state['list_offset'] );
		fseek( $h, (int) $this->state['list_offset'] );
		return $h;
	}

	/**
	 * Ferme la liste et enregistre le point de contrôle.
	 *
	 * @param resource $h Descripteur.
	 * @return void
	 */
	private function close_list( $h ) {
		fflush( $h );
		$this->state['list_offset'] = ftell( $h );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Écrit une entrée.
	 *
	 * @param resource $h    Descripteur.
	 * @param string   $type f|d.
	 * @param string   $abs  Chemin absolu.
	 * @param string   $name Nom dans l'archive.
	 * @param int      $size Taille.
	 * @return void
	 * @throws Migration_Exception Disque plein.
	 */
	private function emit( $h, $type, $abs, $name, $size ) {
		$line = json_encode( array( $type, $abs, $name, $size ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $line ) {
			$this->warnings[] = sprintf( 'Nom de fichier non UTF-8 ignoré : %s', $abs );
			return;
		}
		if ( false === fwrite( $h, $line . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			throw new Migration_Exception( 'Écriture de la liste des fichiers impossible.', 'Libérez de l’espace disque.', true );
		}
		if ( 'f' === $type ) {
			++$this->state['files'];
			$this->state['bytes'] += $size;
		} else {
			++$this->state['dirs'];
		}
	}

	/**
	 * Le chemin est-il exclu ?
	 *
	 * @param string $abs    Chemin absolu normalisé.
	 * @param string $base   Nom de fichier.
	 * @param bool   $is_dir Dossier.
	 * @return bool
	 */
	private function is_excluded( $abs, $base, $is_dir ) {
		if ( in_array( strtolower( $base ), $this->rules['names'], true ) ) {
			return true;
		}
		foreach ( $this->rules['paths'] as $p ) {
			if ( $abs === $p || 0 === strpos( $abs, $p . '/' ) ) {
				return true;
			}
		}
		if ( ! $is_dir && ! empty( $this->rules['extensions'] ) ) {
			$ext = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
			if ( '' !== $ext && in_array( $ext, $this->rules['extensions'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parcourt des dossiers jusqu'à épuisement du budget.
	 *
	 * @param Runtime $rt Budget.
	 * @return bool true si l'inventaire est terminé.
	 */
	public function run( Runtime $rt ) {
		$h     = $this->open_list();
		$first = true;
		while ( ! empty( $this->state['stack'] ) ) {
			// Au moins un dossier par étape, même si le budget est déjà entamé : sinon pas de progrès.
			if ( ! $first && $rt->should_stop() ) {
				$this->close_list( $h );
				return false;
			}
			$first                = false;
			list( $dir, $prefix ) = array_pop( $this->state['stack'] );
			$dh                   = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $dh ) {
				$this->warnings[] = sprintf( 'Dossier illisible ignoré : %s', $dir );
				continue;
			}
			$this->emit( $h, 'd', $dir, $prefix, 0 );
			$subdirs = array();
			while ( false !== ( $item = readdir( $dh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$abs    = $dir . '/' . $item;
				$name   = $prefix . '/' . $item;
				$is_dir = is_dir( $abs );
				if ( $this->is_excluded( $abs, $item, $is_dir ) ) {
					++$this->state['excluded'];
					continue;
				}
				if ( $is_dir ) {
					if ( is_link( $abs ) ) {
						$this->warnings[] = sprintf( 'Lien symbolique vers un dossier ignoré : %s', $name );
						continue;
					}
					$subdirs[] = array( $abs, $name );
					continue;
				}
				$size = @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $size || ! is_readable( $abs ) ) {
					$this->warnings[] = sprintf( 'Fichier illisible ignoré : %s', $name );
					continue;
				}
				if ( $this->rules['max_size'] > 0 && $size > $this->rules['max_size'] ) {
					$this->warnings[] = sprintf( 'Fichier exclu (taille %s) : %s', Runtime::format_bytes( $size ), $name );
					++$this->state['excluded'];
					continue;
				}
				$this->emit( $h, 'f', $abs, $name, (int) $size );
			}
			closedir( $dh );
			// Ordre stable (alphabétique) : on empile à l'envers pour dépiler dans l'ordre.
			usort(
				$subdirs,
				function ( $a, $b ) {
					return strcmp( $b[1], $a[1] );
				}
			);
			foreach ( $subdirs as $sd ) {
				$this->state['stack'][] = $sd;
			}
		}
		$this->state['done'] = true;
		$this->close_list( $h );
		return true;
	}
}
