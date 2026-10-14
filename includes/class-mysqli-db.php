<?php
/**
 * Adaptateur mysqli autonome (installeur et tests).
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Connexion mysqli.
 */
class Mysqli_Db extends Db {

	/**
	 * Connexion.
	 *
	 * @var \mysqli
	 */
	private $link;

	/**
	 * Ouvre une connexion.
	 *
	 * @param string $host     Hôte (formats WordPress : « localhost », « hote:3307 », « localhost:/chemin.sock »).
	 * @param string $user     Utilisateur.
	 * @param string $password Mot de passe.
	 * @param string $name     Base ('' pour aucune).
	 * @param bool   $create   Créer la base si elle n'existe pas.
	 * @throws Migration_Exception En cas d'échec.
	 */
	public function __construct( $host, $user, $password, $name = '', $create = false ) {
		if ( ! class_exists( '\mysqli' ) ) {
			throw new Migration_Exception( 'L’extension PHP mysqli est absente.', 'Activez l’extension « mysqli » dans la configuration PHP de votre hébergement.' );
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		list( $h, $port, $socket ) = self::parse_host( $host );
		$link                      = mysqli_init();
		$link->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
		$ok = @$link->real_connect( $h, $user, $password, null, $port, $socket ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			$errno = mysqli_connect_errno();
			$error = mysqli_connect_error();
			throw new Migration_Exception(
				sprintf( 'Connexion à MySQL impossible (%d) : %s', $errno, $error ),
				self::connect_solution( $errno ),
				false
			);
		}
		$this->link = $link;
		if ( ! $link->set_charset( 'utf8mb4' ) ) {
			$link->set_charset( 'utf8' );
		}
		if ( '' !== (string) $name ) {
			if ( ! $link->select_db( $name ) ) {
				if ( $create ) {
					$this->query( 'CREATE DATABASE IF NOT EXISTS ' . self::ident( $name ) . ' DEFAULT CHARACTER SET utf8mb4' );
				}
				if ( ! $link->select_db( $name ) ) {
					throw new Migration_Exception(
						sprintf( 'La base de données « %s » est introuvable ou inaccessible : %s', $name, $link->error ),
						$create ? 'Créez la base depuis le panneau de votre hébergeur puis réessayez.' : 'Vérifiez le nom de la base, ou cochez « Créer la base si elle n’existe pas ».'
					);
				}
			}
		}
	}

	/**
	 * Solution adaptée au code d'erreur de connexion.
	 *
	 * @param int $errno Code.
	 * @return string
	 */
	private static function connect_solution( $errno ) {
		switch ( (int) $errno ) {
			case 1045:
				return 'Identifiant ou mot de passe incorrect : vérifiez-les dans le panneau de votre hébergeur.';
			case 1044:
				return 'L’utilisateur n’a pas les droits sur cette base : attribuez-lui tous les privilèges.';
			case 2002:
			case 2003:
			case 2005:
				return 'Serveur MySQL injoignable : vérifiez l’hôte (souvent « localhost », parfois une adresse fournie par l’hébergeur).';
			default:
				return 'Vérifiez l’hôte, l’utilisateur et le mot de passe fournis par votre hébergeur.';
		}
	}

	/**
	 * Décompose un hôte au format WordPress.
	 *
	 * @param string $host Hôte.
	 * @return array [hôte, port|null, socket|null]
	 */
	public static function parse_host( $host ) {
		$host   = trim( (string) $host );
		$port   = null;
		$socket = null;
		if ( 0 === strpos( $host, 'p:' ) ) {
			$host = substr( $host, 2 );
		}
		$sock_pos = strpos( $host, ':/' );
		if ( false !== $sock_pos ) {
			$socket = substr( $host, $sock_pos + 1 );
			$host   = substr( $host, 0, $sock_pos );
		} elseif ( preg_match( '/^\[([^\]]+)\](?::(\d+))?$/', $host, $m ) ) {
			$host = $m[1];
			$port = isset( $m[2] ) ? (int) $m[2] : null;
		} elseif ( 1 === substr_count( $host, ':' ) ) {
			list( $host, $p ) = explode( ':', $host );
			$port             = '' !== $p ? (int) $p : null;
		}
		if ( '' === $host ) {
			$host = 'localhost';
		}
		return array( $host, $port, $socket );
	}

	/**
	 * Connexion brute.
	 *
	 * @return \mysqli
	 */
	public function link() {
		return $this->link;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql Requête.
	 * @return bool
	 */
	public function query( $sql ) {
		$res = $this->link->query( $sql );
		if ( $res instanceof \mysqli_result ) {
			$res->free();
			return true;
		}
		return (bool) $res;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $sql   Requête.
	 * @param bool   $assoc Associatif.
	 * @return array|false
	 */
	public function get_rows( $sql, $assoc = false ) {
		$res = $this->link->query( $sql );
		if ( ! $res instanceof \mysqli_result ) {
			return $res ? array() : false;
		}
		$rows = array();
		if ( $assoc ) {
			while ( $row = $res->fetch_assoc() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$rows[] = $row;
			}
		} else {
			while ( $row = $res->fetch_row() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$rows[] = $row;
			}
		}
		$res->free();
		return $rows;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $sql      Requête.
	 * @param callable $callback Rappel.
	 * @return bool
	 */
	public function stream_rows( $sql, callable $callback ) {
		return self::stream_mysqli( $this->link, $sql, $callback );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string
	 */
	public function last_error() {
		return (string) $this->link->error;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int
	 */
	public function last_errno() {
		return (int) $this->link->errno;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return int
	 */
	public function affected_rows() {
		return (int) $this->link->affected_rows;
	}

	/**
	 * Version du serveur.
	 *
	 * @return string
	 */
	public function server_version() {
		return (string) $this->get_var( 'SELECT VERSION()' );
	}

	/**
	 * Ferme la connexion.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->link ) {
			$this->link->close();
			$this->link = null;
		}
	}
}
