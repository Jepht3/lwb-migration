<?php
/**
 * Tâche longue (export, transfert) : état JSON persistant, verrou et journal.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Tâche reprenable.
 */
class Job {

	/**
	 * Identifiant.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Dossier de travail.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Descripteur du verrou.
	 *
	 * @var resource|null
	 */
	private $lock;

	/**
	 * Journal.
	 *
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Données persistantes.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Constructeur privé.
	 *
	 * @param string $base Dossier des tâches.
	 * @param string $id   Identifiant.
	 */
	private function __construct( $base, $id ) {
		$this->id  = $id;
		$this->dir = rtrim( $base, '/' ) . '/' . $id;
	}

	/**
	 * Crée une tâche.
	 *
	 * @param string $base Dossier des tâches.
	 * @param array  $data Données initiales.
	 * @return Job
	 */
	public static function create( $base, array $data ) {
		$job = new self( $base, Runtime::random_hex( 24 ) );
		Json_Store::ensure_protected_dir( $job->dir );
		$job->data = array_merge(
			array(
				'created' => time(),
				'updated' => time(),
			),
			$data
		);
		$job->save();
		return $job;
	}

	/**
	 * Charge une tâche existante.
	 *
	 * @param string $base Dossier des tâches.
	 * @param string $id   Identifiant.
	 * @return Job|null
	 */
	public static function load( $base, $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{24}$/', $id ) ) {
			return null;
		}
		$job  = new self( $base, $id );
		$data = Json_Store::read( $job->path( 'state.php' ) );
		if ( null === $data ) {
			return null;
		}
		$job->data = $data;
		return $job;
	}

	/**
	 * Identifiant.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Dossier de travail.
	 *
	 * @return string
	 */
	public function dir() {
		return $this->dir;
	}

	/**
	 * Chemin d'un fichier de la tâche.
	 *
	 * @param string $name Nom.
	 * @return string
	 */
	public function path( $name ) {
		return $this->dir . '/' . $name;
	}

	/**
	 * Journal de la tâche.
	 *
	 * @return Logger
	 */
	public function logger() {
		if ( null === $this->logger ) {
			$this->logger = new Logger( $this->path( 'log.php' ) );
		}
		return $this->logger;
	}

	/**
	 * Enregistre l'état.
	 *
	 * @return void
	 */
	public function save() {
		$this->data['updated'] = time();
		Json_Store::write( $this->path( 'state.php' ), $this->data );
	}

	/**
	 * Prend le verrou exclusif (non bloquant).
	 *
	 * Si une requête précédente tourne encore (délai dépassé côté navigateur), la
	 * nouvelle requête est refusée au lieu d'écrire en parallèle dans les mêmes fichiers.
	 *
	 * @return bool
	 */
	public function lock() {
		$this->lock = @fopen( $this->path( 'lock' ), 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->lock ) {
			return true; // Système de fichiers sans verrou : on continue sans.
		}
		if ( ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $this->lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->lock = null;
			return false;
		}
		return true;
	}

	/**
	 * Libère le verrou.
	 *
	 * @return void
	 */
	public function unlock() {
		if ( $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->lock = null;
		}
	}

	/**
	 * Supprime la tâche et ses fichiers.
	 *
	 * @return void
	 */
	public function delete() {
		$this->unlock();
		Json_Store::delete_dir( $this->dir );
	}

	/**
	 * Supprime les tâches abandonnées.
	 *
	 * @param string $base    Dossier des tâches.
	 * @param int    $max_age Âge maximal (secondes).
	 * @return void
	 */
	public static function cleanup( $base, $max_age ) {
		$items = @scandir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( ! preg_match( '/^[a-f0-9]{24}$/', $item ) ) {
				continue;
			}
			$state = $base . '/' . $item . '/state.php';
			$mtime = is_file( $state ) ? (int) filemtime( $state ) : 0;
			if ( time() - $mtime > $max_age ) {
				Json_Store::delete_dir( $base . '/' . $item );
			}
		}
	}
}
