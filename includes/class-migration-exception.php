<?php
/**
 * Exception métier avec une solution proposée à l'utilisateur.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Erreur de migration lisible par un humain.
 */
class Migration_Exception extends \RuntimeException {

	/**
	 * Solution proposée.
	 *
	 * @var string
	 */
	private $solution;

	/**
	 * Indique si l'étape peut être relancée sans risque.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Constructeur.
	 *
	 * @param string          $message   Message d'erreur (déjà traduit).
	 * @param string          $solution  Solution proposée (déjà traduite).
	 * @param bool            $retryable L'étape peut être relancée.
	 * @param \Throwable|null $previous  Exception d'origine.
	 */
	public function __construct( $message, $solution = '', $retryable = false, $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->solution  = (string) $solution;
		$this->retryable = (bool) $retryable;
	}

	/**
	 * Solution proposée.
	 *
	 * @return string
	 */
	public function get_solution() {
		return $this->solution;
	}

	/**
	 * L'erreur est-elle transitoire ?
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return $this->retryable;
	}
}
