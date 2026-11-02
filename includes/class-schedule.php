<?php
/**
 * Calcul des dates de sauvegarde automatique (sans dépendance à WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Planification.
 */
class Schedule {

	const FREQUENCIES = array( 'twicedaily', 'daily', 'weekly', 'monthly' );

	/**
	 * Prochaine exécution strictement après $now.
	 *
	 * @param array         $s   frequency, hour (0-23), weekday (0 = dimanche … 6), monthday (1-28).
	 * @param int           $now Horodatage Unix.
	 * @param \DateTimeZone $tz  Fuseau du site.
	 * @return int Horodatage Unix.
	 */
	public static function next_run( array $s, $now, \DateTimeZone $tz ) {
		$hour  = min( 23, max( 0, (int) ( isset( $s['hour'] ) ? $s['hour'] : 3 ) ) );
		$local = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
		$at    = function ( \DateTimeImmutable $day, $h ) {
			return $day->setTime( $h, 0, 0 );
		};
		$freq  = isset( $s['frequency'] ) && in_array( $s['frequency'], self::FREQUENCIES, true ) ? $s['frequency'] : 'daily';

		switch ( $freq ) {
			case 'twicedaily':
				$hours = array( $hour % 12, $hour % 12 + 12 );
				for ( $d = 0; $d < 2; $d++ ) {
					$day = $local->modify( '+' . $d . ' day' );
					foreach ( $hours as $h ) {
						$t = $at( $day, $h );
						if ( $t->getTimestamp() > $now ) {
							return $t->getTimestamp();
						}
					}
				}
				break;
			case 'weekly':
				$wd = min( 6, max( 0, (int) ( isset( $s['weekday'] ) ? $s['weekday'] : 1 ) ) );
				for ( $d = 0; $d <= 7; $d++ ) {
					$day = $local->modify( '+' . $d . ' day' );
					if ( (int) $day->format( 'w' ) === $wd ) {
						$t = $at( $day, $hour );
						if ( $t->getTimestamp() > $now ) {
							return $t->getTimestamp();
						}
					}
				}
				break;
			case 'monthly':
				$md = min( 28, max( 1, (int) ( isset( $s['monthday'] ) ? $s['monthday'] : 1 ) ) );
				for ( $m = 0; $m <= 1; $m++ ) {
					$first = $local->setDate( (int) $local->format( 'Y' ), (int) $local->format( 'n' ) + $m, 1 );
					$t     = $at( $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $md ), $hour );
					if ( $t->getTimestamp() > $now ) {
						return $t->getTimestamp();
					}
				}
				break;
			default:
				for ( $d = 0; $d <= 1; $d++ ) {
					$t = $at( $local->modify( '+' . $d . ' day' ), $hour );
					if ( $t->getTimestamp() > $now ) {
						return $t->getTimestamp();
					}
				}
		}
		// Changement d'heure exotique : repli sûr, dans 24 h.
		return (int) $now + 86400;
	}
}
