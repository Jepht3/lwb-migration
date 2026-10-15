<?php
/**
 * Couche d'accès base de données minimale, commune au plugin et à l'installeur.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Adaptateur de base de données.
 */
abstract class Db {

	/**
	 * Exécute une requête sans résultat.
	 *
	 * @param string $sql Requête.
	 * @return bool
	 */
	abstract public function query( $sql );

	/**
	 * Exécute une requête et renvoie toutes les lignes.
	 *
	 * @param string $sql   Requête.
	 * @param bool   $assoc Lignes associatives (true) ou indexées (false).
	 * @return array|false
	 */
	abstract public function get_rows( $sql, $assoc = false );

	/**
	 * Dernier message d'erreur.
	 *
	 * @return string
	 */
	abstract public function last_error();

	/**
	 * Dernier code d'erreur MySQL (0 si inconnu).
	 *
	 * @return int
	 */
	abstract public function last_errno();

	/**
	 * Nombre de lignes affectées par la dernière requête.
	 *
	 * @return int
	 */
	abstract public function affected_rows();

	/**
	 * Parcourt les lignes une par une sans les charger toutes en mémoire.
	 *
	 * Implémentation par défaut (bufferisée) ; les adaptateurs mysqli la remplacent
	 * par une lecture non bufferisée. Le rappel ne doit pas exécuter d'autre requête.
	 *
	 * @param string   $sql      Requête.
	 * @param callable $callback Fonction appelée avec chaque ligne (tableau indexé).
	 * @return bool false en cas d'erreur SQL.
	 */
	public function stream_rows( $sql, callable $callback ) {
		$rows = $this->get_rows( $sql );
		if ( false === $rows ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$callback( $row );
		}
		return true;
	}

	/**
	 * Lecture non bufferisée sur une connexion mysqli.
	 *
	 * @param \mysqli  $link     Connexion.
	 * @param string   $sql      Requête.
	 * @param callable $callback Rappel.
	 * @return bool
	 */
	protected static function stream_mysqli( $link, $sql, callable $callback ) {
		$res = $link->query( $sql, MYSQLI_USE_RESULT );
		if ( ! $res instanceof \mysqli_result ) {
			return (bool) $res;
		}
		while ( $row = $res->fetch_row() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$callback( $row );
		}
		$res->free();
		return 0 === (int) $link->errno;
	}

	/**
	 * Première colonne de la première ligne.
	 *
	 * @param string $sql Requête.
	 * @return string|null
	 */
	public function get_var( $sql ) {
		$rows = $this->get_rows( $sql );
		if ( empty( $rows ) || ! isset( $rows[0][0] ) ) {
			return null;
		}
		return $rows[0][0];
	}

	/**
	 * Première colonne de toutes les lignes.
	 *
	 * @param string $sql Requête.
	 * @return array
	 */
	public function get_col( $sql ) {
		$rows = $this->get_rows( $sql );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = $row[0];
		}
		return $out;
	}

	/**
	 * Échappe une valeur pour une chaîne SQL entre apostrophes.
	 *
	 * Échappement déterministe identique à mysqli_real_escape_string pour
	 * utf8/utf8mb4 (la session doit être sans NO_BACKSLASH_ESCAPES).
	 *
	 * @param string $value Valeur.
	 * @return string
	 */
	public static function escape( $value ) {
		return strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * Valeur SQL littérale (NULL, nombre ou chaîne entre apostrophes).
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	public static function literal( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . self::escape( $value ) . "'";
	}

	/**
	 * Échappe un identifiant (table, colonne).
	 *
	 * @param string $name Identifiant.
	 * @return string
	 */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Échappe une valeur pour un motif LIKE.
	 *
	 * @param string $value Valeur.
	 * @return string
	 */
	public static function like_escape( $value ) {
		return addcslashes( (string) $value, '_%\\' );
	}

	/**
	 * Liste les tables de base (hors vues), éventuellement filtrées par préfixe.
	 *
	 * @param string $prefix Préfixe ('' pour toutes).
	 * @return string[]
	 */
	public function list_tables( $prefix = '' ) {
		return $this->list_by_type( 'BASE TABLE', $prefix );
	}

	/**
	 * Liste les vues, éventuellement filtrées par préfixe (non exportées : voir Db_Dumper).
	 *
	 * @param string $prefix Préfixe ('' pour toutes).
	 * @return string[]
	 */
	public function list_views( $prefix = '' ) {
		return $this->list_by_type( 'VIEW', $prefix );
	}

	/**
	 * Liste les objets d'un type (BASE TABLE, VIEW).
	 *
	 * @param string $type   Type.
	 * @param string $prefix Préfixe ('' pour tous).
	 * @return string[]
	 */
	private function list_by_type( $type, $prefix ) {
		$sql = 'SHOW FULL TABLES WHERE Table_type = ' . self::literal( $type );
		$out = array();
		foreach ( (array) $this->get_rows( $sql ) as $row ) {
			$name = (string) $row[0];
			if ( '' === $prefix || 0 === strpos( $name, $prefix ) ) {
				$out[] = $name;
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Décrit les colonnes d'une table.
	 *
	 * @param string $table Table.
	 * @return array[] Liste de colonnes : name, type, key, extra, nullable.
	 */
	public function describe( $table ) {
		$rows = $this->get_rows( 'SHOW COLUMNS FROM ' . self::ident( $table ), true );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'     => (string) $row['Field'],
				'type'     => strtolower( (string) $row['Type'] ),
				'key'      => (string) $row['Key'],
				'extra'    => strtolower( (string) $row['Extra'] ),
				'nullable' => 'YES' === $row['Null'],
			);
		}
		return $out;
	}

	/**
	 * Colonnes de la clé primaire.
	 *
	 * @param array[] $columns Résultat de describe().
	 * @return string[]
	 */
	public static function primary_key( array $columns ) {
		$pk = array();
		foreach ( $columns as $col ) {
			if ( 'PRI' === $col['key'] ) {
				$pk[] = $col['name'];
			}
		}
		return $pk;
	}

	/**
	 * La colonne est-elle générée (virtuelle ou stockée) ?
	 *
	 * @param array $column Colonne.
	 * @return bool
	 */
	public static function is_generated( array $column ) {
		return false !== strpos( $column['extra'], 'generated' );
	}

	/**
	 * Le type est-il numérique ?
	 *
	 * @param string $type Type SQL.
	 * @return bool
	 */
	public static function is_numeric_type( $type ) {
		return (bool) preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/', $type );
	}

	/**
	 * Le type est-il un entier ?
	 *
	 * @param string $type Type SQL.
	 * @return bool
	 */
	public static function is_integer_type( $type ) {
		return (bool) preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type );
	}

	/**
	 * Le type est-il binaire (exporté en hexadécimal) ?
	 *
	 * @param string $type Type SQL.
	 * @return bool
	 */
	public static function is_binary_type( $type ) {
		return (bool) preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type );
	}

	/**
	 * Le type est-il textuel (candidat au rechercher/remplacer) ?
	 *
	 * @param string $type Type SQL.
	 * @return bool
	 */
	public static function is_text_type( $type ) {
		return (bool) preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/', $type );
	}
}
