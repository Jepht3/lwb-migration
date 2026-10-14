<?php
/**
 * Adaptateur $wpdb (côté plugin).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Accès base de données via $wpdb (compatible avec les drop-ins db.php).
 */
class Wpdb_Db extends Db {

	/**
	 * Instance wpdb.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructeur.
	 *
	 * @param \wpdb $wpdb Instance.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
		$this->wpdb->suppress_errors( true );
	}

	/**
	 * Évite l'accumulation mémoire de SAVEQUERIES pendant un long export.
	 *
	 * @return void
	 */
	private function release() {
		$this->wpdb->queries = array();
		$this->wpdb->flush();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql Requête.
	 * @return bool
	 */
	public function query( $sql ) {
		$res = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->release();
		return false !== $res;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql   Requête.
	 * @param bool   $assoc Associatif.
	 * @return array|false
	 */
	public function get_rows( $sql, $assoc = false ) {
		$rows = $this->wpdb->get_results( $sql, $assoc ? ARRAY_A : ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$err  = $this->wpdb->last_error;
		$this->release();
		$this->wpdb->last_error = $err;
		if ( null === $rows && '' !== $err ) {
			return false;
		}
		return (array) $rows;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Lecture non bufferisée directement sur la connexion mysqli de $wpdb quand elle
	 * existe (cas standard) ; sinon (drop-in db.php exotique) repli bufferisé.
	 *
	 * @param string   $sql      Requête.
	 * @param callable $callback Rappel.
	 * @return bool
	 */
	public function stream_rows( $sql, callable $callback ) {
		if ( $this->wpdb->dbh instanceof \mysqli ) {
			$ok = self::stream_mysqli( $this->wpdb->dbh, $sql, $callback );
			if ( ! $ok ) {
				$this->wpdb->last_error = mysqli_error( $this->wpdb->dbh );
			}
			return $ok;
		}
		return parent::stream_rows( $sql, $callback );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function last_error() {
		return (string) $this->wpdb->last_error;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int
	 */
	public function last_errno() {
		if ( $this->wpdb->dbh instanceof \mysqli ) {
			return (int) mysqli_errno( $this->wpdb->dbh );
		}
		return 0;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int
	 */
	public function affected_rows() {
		return (int) $this->wpdb->rows_affected;
	}
}
