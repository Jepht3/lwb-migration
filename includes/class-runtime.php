<?php
/**
 * Gestion du budget de temps et de mémoire d'une étape.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Chronomètre une étape et indique quand il faut s'arrêter pour rendre la main.
 */
class Runtime {

	/**
	 * Durée maximale absolue d'une étape (secondes).
	 */
	const MAX_BUDGET = 18.0;

	/**
	 * Durée minimale d'une étape (secondes).
	 */
	const MIN_BUDGET = 2.0;

	/**
	 * Début de l'étape.
	 *
	 * @var float
	 */
	private $start;

	/**
	 * Budget en secondes.
	 *
	 * @var float
	 */
	private $budget;

	/**
	 * Limite mémoire en octets (0 = illimitée).
	 *
	 * @var int
	 */
	private $memory_limit;

	/**
	 * Constructeur.
	 *
	 * @param float|null $budget Budget imposé (secondes) ou null pour le calcul automatique.
	 */
	public function __construct( $budget = null ) {
		$this->start        = microtime( true );
		$this->budget       = null === $budget ? self::default_budget() : max( 0.0001, (float) $budget );
		$this->memory_limit = self::memory_limit_bytes();
	}

	/**
	 * Budget par défaut, déduit de max_execution_time.
	 *
	 * Avec max_execution_time = 30, on travaille 15 s puis on rend la main.
	 *
	 * @return float
	 */
	public static function default_budget() {
		// Réglage forcé (diagnostic, tests) : constante PHP ou variable d'environnement.
		$forced = defined( 'LWB_MIGRATION_STEP_BUDGET' ) ? constant( 'LWB_MIGRATION_STEP_BUDGET' ) : getenv( 'LWB_MIGRATION_STEP_BUDGET' );
		if ( false !== $forced && '' !== $forced && (float) $forced > 0 ) {
			return min( self::MAX_BUDGET, max( 0.05, (float) $forced ) );
		}
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return self::MAX_BUDGET;
		}
		return min( self::MAX_BUDGET, max( self::MIN_BUDGET, $max * 0.5 ) );
	}

	/**
	 * Prépare l'environnement d'exécution d'une étape.
	 *
	 * @return void
	 */
	public static function prepare() {
		if ( function_exists( 'ignore_user_abort' ) && self::function_enabled( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 && $max < 60 && self::function_enabled( 'set_time_limit' ) ) {
			// Simple marge de sécurité : le budget de l'étape reste calculé sur la valeur d'origine.
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Indique si une fonction existe et n'est pas désactivée par l'hébergeur.
	 *
	 * @param string $name Nom de la fonction.
	 * @return bool
	 */
	public static function function_enabled( $name ) {
		if ( ! function_exists( $name ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );
		return ! in_array( strtolower( $name ), $disabled, true );
	}

	/**
	 * Limite mémoire en octets (0 si illimitée).
	 *
	 * @return int
	 */
	public static function memory_limit_bytes() {
		return self::to_bytes( (string) ini_get( 'memory_limit' ) );
	}

	/**
	 * Convertit une valeur php.ini (« 128M ») en octets.
	 *
	 * @param string $value Valeur.
	 * @return int
	 */
	public static function to_bytes( $value ) {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// Pas de break : cascade volontaire.
			case 'm':
				$number *= 1024;
				// Pas de break : cascade volontaire.
			case 'k':
				$number *= 1024;
		}
		return (int) $number;
	}

	/**
	 * Secondes écoulées depuis le début de l'étape.
	 *
	 * @return float
	 */
	public function elapsed() {
		return microtime( true ) - $this->start;
	}

	/**
	 * Budget de l'étape.
	 *
	 * @return float
	 */
	public function budget() {
		return $this->budget;
	}

	/**
	 * Temps restant (secondes).
	 *
	 * @return float
	 */
	public function remaining() {
		return $this->budget - $this->elapsed();
	}

	/**
	 * Faut-il s'arrêter (temps ou mémoire épuisés) ?
	 *
	 * @return bool
	 */
	public function should_stop() {
		if ( $this->elapsed() >= $this->budget ) {
			return true;
		}
		return $this->memory_pressure();
	}

	/**
	 * La mémoire utilisée dépasse-t-elle 70 % de la limite ?
	 *
	 * @return bool
	 */
	public function memory_pressure() {
		if ( $this->memory_limit <= 0 ) {
			return false;
		}
		return memory_get_usage( true ) > $this->memory_limit * 0.7;
	}

	/**
	 * Formate une taille en octets.
	 *
	 * @param int|float $bytes Taille.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$units = array( 'o', 'Ko', 'Mo', 'Go', 'To' );
		$max   = count( $units ) - 1;
		$i     = 0;
		$bytes = (float) $bytes;
		while ( $bytes >= 1024 && $i < $max ) {
			$bytes /= 1024;
			++$i;
		}
		return ( 0 === $i ? (string) (int) $bytes : number_format( $bytes, 1, ',', ' ' ) ) . ' ' . $units[ $i ];
	}

	/**
	 * Génère une chaîne hexadécimale aléatoire cryptographiquement sûre.
	 *
	 * @param int $length Longueur (nombre de caractères hexadécimaux, pair).
	 * @return string
	 */
	public static function random_hex( $length = 32 ) {
		return bin2hex( random_bytes( (int) max( 1, $length / 2 ) ) );
	}
}
