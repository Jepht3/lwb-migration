<?php
/**
 * Dump SQL en flux, table par table, par lots adaptatifs. Reprenable.
 *
 * Aucune dépendance à mysqldump ni à exec() : tout passe par des SELECT paginés.
 * Chaque instruction INSERT tient sur une seule ligne (les retours à la ligne sont
 * échappés) et regroupe plusieurs lignes jusqu'à ~1 Mo.
 *
 * Fichier autonome (adaptateur Db injecté) : testable sans WordPress.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Exporteur de base de données.
 */
class Db_Dumper {

	/**
	 * Taille maximale d'une instruction INSERT (octets).
	 */
	const MAX_STATEMENT = 1000000;

	/**
	 * Volume visé par lot (octets).
	 */
	const TARGET_BATCH_BYTES = 2097152;

	/**
	 * Connexion.
	 *
	 * @var Db
	 */
	private $db;

	/**
	 * Fichier SQL produit.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * État.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Descripteur.
	 *
	 * @var resource|null
	 */
	private $fh;

	/**
	 * Constructeur.
	 *
	 * @param Db     $db    Connexion.
	 * @param string $file  Fichier SQL.
	 * @param array  $state État (reprise) ou tableau vide.
	 */
	public function __construct( Db $db, $file, array $state = array() ) {
		$this->db    = $db;
		$this->file  = $file;
		$this->state = array_merge(
			array(
				'tables'      => array(),
				'estimates'   => array(),
				'index'       => 0,
				'phase'       => 'structure',
				'last'        => null,
				'offset'      => 0,
				'file_offset' => 0,
				'rows_total'  => 0,
				'rows_done'   => 0,
				'table_rows'  => 0,
				'batch'       => 1000,
				'header'      => false,
				'done'        => false,
			),
			$state
		);
	}

	/**
	 * Prépare la liste des tables à exporter.
	 *
	 * @param string   $prefix  Préfixe des tables ('' = toutes).
	 * @param string[] $exclude Tables exclues.
	 * @return void
	 */
	public function init( $prefix, array $exclude = array() ) {
		$tables = array_values( array_diff( $this->db->list_tables( $prefix ), $exclude ) );
		$est    = array();
		$total  = 0;
		$status = $this->db->get_rows( 'SHOW TABLE STATUS', true );
		$map    = array();
		foreach ( (array) $status as $row ) {
			$map[ $row['Name'] ] = (int) $row['Rows'];
		}
		foreach ( $tables as $t ) {
			$est[ $t ] = isset( $map[ $t ] ) ? $map[ $t ] : 0;
			$total    += $est[ $t ];
		}
		$this->state['tables']     = $tables;
		$this->state['estimates']  = $est;
		$this->state['rows_total'] = max( 1, $total );
	}

	/**
	 * État courant.
	 *
	 * @return array
	 */
	public function get_state() {
		return $this->state;
	}

	/**
	 * Progression (0..1).
	 *
	 * @return float
	 */
	public function progress() {
		if ( $this->state['done'] ) {
			return 1.0;
		}
		return min( 0.99, $this->state['rows_done'] / max( 1, $this->state['rows_total'] ) );
	}

	/**
	 * Table en cours.
	 *
	 * @return string
	 */
	public function current_table() {
		$i = (int) $this->state['index'];
		return isset( $this->state['tables'][ $i ] ) ? $this->state['tables'][ $i ] : '';
	}

	/**
	 * Exécute une étape.
	 *
	 * @param Runtime $rt Budget.
	 * @return bool true si le dump est terminé.
	 * @throws Migration_Exception Erreur SQL ou disque.
	 */
	public function run( Runtime $rt ) {
		if ( $this->state['done'] ) {
			return true;
		}
		$this->open();
		// Valeurs TIMESTAMP exportées en UTC, quelle que soit la configuration du serveur.
		$this->db->query( "SET SESSION time_zone = '+00:00'" );
		try {
			if ( ! $this->state['header'] ) {
				$this->write_header();
				$this->state['header'] = true;
				$this->checkpoint();
			}
			while ( ! $rt->should_stop() ) {
				$table = $this->current_table();
				if ( '' === $table ) {
					$this->write( "\nSET foreign_key_checks = 1;\n-- Fin du dump\n" );
					$this->state['done'] = true;
					$this->checkpoint();
					return true;
				}
				if ( 'structure' === $this->state['phase'] ) {
					$this->dump_structure( $table );
					$this->state['phase']      = 'data';
					$this->state['last']       = null;
					$this->state['offset']     = 0;
					$this->state['table_rows'] = 0;
					$this->checkpoint();
					continue;
				}
				if ( $this->dump_batch( $table, $rt ) ) {
					$this->state['rows_done'] += max( 0, (int) $this->state['estimates'][ $table ] - (int) $this->state['table_rows'] );
					++$this->state['index'];
					$this->state['phase'] = 'structure';
				}
				$this->checkpoint();
			}
		} finally {
			$this->close();
		}
		return false;
	}

	/**
	 * Ouvre le fichier et revient au point de contrôle.
	 *
	 * @return void
	 * @throws Migration_Exception Fichier inaccessible.
	 */
	private function open() {
		$this->fh = @fopen( $this->file, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->fh ) {
			throw new Migration_Exception( 'Impossible de créer le fichier SQL.', 'Vérifiez les permissions du dossier de stockage et l’espace disque.' );
		}
		ftruncate( $this->fh, (int) $this->state['file_offset'] );
		fseek( $this->fh, (int) $this->state['file_offset'] );
	}

	/**
	 * Ferme le fichier.
	 *
	 * @return void
	 */
	private function close() {
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
		}
	}

	/**
	 * Point de contrôle.
	 *
	 * @return void
	 */
	private function checkpoint() {
		fflush( $this->fh );
		$this->state['file_offset'] = ftell( $this->fh );
	}

	/**
	 * Écrit dans le fichier.
	 *
	 * @param string $data Données.
	 * @return void
	 * @throws Migration_Exception Disque plein.
	 */
	public function write( $data ) {
		$len   = strlen( $data );
		$total = 0;
		while ( $total < $len ) {
			$n = fwrite( $this->fh, 0 === $total ? $data : substr( $data, $total ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				throw new Migration_Exception(
					'Écriture du dump SQL impossible : espace disque saturé ?',
					'Libérez de l’espace disque ou excluez des tables volumineuses (journaux, statistiques) dans les Paramètres.',
					true
				);
			}
			$total += $n;
		}
	}

	/**
	 * En-tête du dump.
	 *
	 * @return void
	 */
	private function write_header() {
		$version = (string) $this->db->get_var( 'SELECT VERSION()' );
		$this->write(
			"-- Lwb Migration SQL dump\n"
			. '-- Serveur : ' . str_replace( array( "\n", "\r" ), '', $version ) . "\n"
			. '-- Date : ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n"
			. "/*!40101 SET NAMES utf8mb4 */;\n"
			. "SET foreign_key_checks = 0;\n"
			. "SET unique_checks = 0;\n"
			. "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET time_zone = '+00:00';\n\n"
		);
		// Suppression préalable de toutes les tables : une table homonyme d'une autre structure
		// sur la destination ne peut ainsi pas faire échouer une clé étrangère.
		foreach ( $this->state['tables'] as $table ) {
			$this->write( 'DROP TABLE IF EXISTS ' . Db::ident( $table ) . ";\n" );
		}
	}

	/**
	 * Structure d'une table.
	 *
	 * @param string $table Table.
	 * @return void
	 * @throws Migration_Exception Erreur SQL.
	 */
	private function dump_structure( $table ) {
		$rows = $this->db->get_rows( 'SHOW CREATE TABLE ' . Db::ident( $table ) );
		if ( empty( $rows ) || ! isset( $rows[0][1] ) ) {
			throw new Migration_Exception(
				sprintf( 'Impossible de lire la structure de la table %s : %s', $table, $this->db->last_error() ),
				'Réparez la table depuis phpMyAdmin (« Réparer la table ») ou excluez-la dans les Paramètres.',
				true
			);
		}
		$this->write(
			"\n-- Table " . str_replace( array( "\n", "\r" ), '', $table ) . "\n"
			. 'DROP TABLE IF EXISTS ' . Db::ident( $table ) . ";\n"
			. $rows[0][1] . ";\n"
		);
	}

	/**
	 * Exporte un lot de lignes.
	 *
	 * @param string  $table Table.
	 * @param Runtime $rt    Budget.
	 * @return bool true si la table est terminée.
	 * @throws Migration_Exception Erreur SQL.
	 */
	private function dump_batch( $table, Runtime $rt ) {
		$columns = $this->db->describe( $table );
		$cols    = array();
		$types   = array();
		foreach ( $columns as $c ) {
			if ( Db::is_generated( $c ) ) {
				continue;
			}
			$cols[]  = $c['name'];
			$types[] = $c['type'];
		}
		if ( empty( $cols ) ) {
			return true;
		}
		$pk      = Db::primary_key( $columns );
		$keyset  = 1 === count( $pk ) && Db::is_integer_type( $this->column_type( $columns, $pk[0] ) );
		$key_idx = $keyset ? array_search( $pk[0], $cols, true ) : false;
		$col_sql = implode( ',', array_map( array( Db::class, 'ident' ), $cols ) );
		$insert  = 'INSERT INTO ' . Db::ident( $table ) . ' (' . $col_sql . ') VALUES ';
		// BIT : lu en entier (mysqlnd et libmysqlclient renvoient sinon des formats différents).
		$select = array();
		foreach ( $cols as $i => $name ) {
			$select[] = 0 === strpos( $types[ $i ], 'bit' ) ? '(' . Db::ident( $name ) . '+0)' : Db::ident( $name );
		}
		$select_sql = implode( ',', $select );

		while ( true ) {
			$batch = max( 1, (int) $this->state['batch'] );
			if ( $keyset && false !== $key_idx ) {
				$where = null === $this->state['last'] ? '' : ' WHERE ' . Db::ident( $pk[0] ) . ' > ' . $this->int_literal( $this->state['last'] );
				$sql   = 'SELECT ' . $select_sql . ' FROM ' . Db::ident( $table ) . $where . ' ORDER BY ' . Db::ident( $pk[0] ) . ' ASC LIMIT ' . $batch;
			} else {
				$order = empty( $pk ) ? '' : ' ORDER BY ' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) );
				$sql   = 'SELECT ' . $select_sql . ' FROM ' . Db::ident( $table ) . $order . ' LIMIT ' . (int) $this->state['offset'] . ',' . $batch;
			}
			$count = 0;
			$bytes = 0;
			$buf   = '';
			$last  = null;
			$self  = $this;
			$ok    = $this->db->stream_rows(
				$sql,
				function ( $row ) use ( $self, $types, $insert, $key_idx, &$count, &$bytes, &$buf, &$last ) {
					$values = array();
					foreach ( $row as $i => $v ) {
						$values[] = $self->value_literal( $v, $types[ $i ] );
					}
					$tuple = '(' . implode( ',', $values ) . ')';
					if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) + 2 > Db_Dumper::MAX_STATEMENT ) {
						$self->write( $buf . ";\n" );
						$bytes += strlen( $buf );
						$buf    = '';
					}
					$buf .= ( '' === $buf ? $insert : ',' ) . $tuple;
					if ( false !== $key_idx ) {
						$last = $row[ $key_idx ];
					}
					++$count;
				}
			);
			if ( ! $ok ) {
				throw new Migration_Exception(
					sprintf( 'Lecture impossible de la table %s : %s', $table, $this->db->last_error() ),
					'Réparez la table depuis phpMyAdmin ou excluez-la dans les Paramètres.',
					true
				);
			}
			if ( '' !== $buf ) {
				$this->write( $buf . ";\n" );
				$bytes += strlen( $buf );
			}
			unset( $buf );
			if ( 0 === $count ) {
				return true;
			}
			if ( $keyset && false !== $key_idx ) {
				$this->state['last'] = $last;
			}
			$this->state['offset']     += $count;
			$this->state['table_rows'] += $count;
			$this->state['rows_done']  += $count;

			// Taille de lot adaptative : vise ~2 Mo par lot, bornée entre 10 et 20 000 lignes.
			$per_row              = $bytes / $count;
			$ideal                = (int) ( self::TARGET_BATCH_BYTES / max( 1, $per_row ) );
			$this->state['batch'] = max( 10, min( 20000, $ideal ) );
			if ( $rt->memory_pressure() ) {
				$this->state['batch'] = max( 10, (int) ( $this->state['batch'] / 4 ) );
			}
			$this->checkpoint();
			if ( $count < $batch ) {
				return true;
			}
			if ( $rt->should_stop() ) {
				return false;
			}
		}
	}

	/**
	 * Type d'une colonne.
	 *
	 * @param array[] $columns Colonnes.
	 * @param string  $name    Nom.
	 * @return string
	 */
	private function column_type( array $columns, $name ) {
		foreach ( $columns as $c ) {
			if ( $c['name'] === $name ) {
				return $c['type'];
			}
		}
		return '';
	}

	/**
	 * Littéral entier sûr.
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	private function int_literal( $value ) {
		return preg_match( '/^-?\d+$/', (string) $value ) ? (string) $value : Db::literal( $value );
	}

	/**
	 * Littéral SQL selon le type de colonne.
	 *
	 * @param mixed  $value Valeur.
	 * @param string $type  Type.
	 * @return string
	 */
	public function value_literal( $value, $type ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( Db::is_binary_type( $type ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( ( Db::is_numeric_type( $type ) || 0 === strpos( $type, 'bit' ) ) && is_numeric( $value ) ) {
			return (string) $value;
		}
		return "'" . Db::escape( $value ) . "'";
	}
}
