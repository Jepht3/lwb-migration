<?php
/**
 * Planification des sauvegardes : prochaine exécution dans le fuseau du site.
 *
 * @package LwbMigration
 */

namespace LwbMigration\Tests;

use LwbMigration\Schedule;

class ScheduleTest extends TestCase {

	private function local_ts( $local, $tz = 'Europe/Paris' ) {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( $tz ) ) )->getTimestamp();
	}

	private function next( array $s, $now_local, $tz = 'Europe/Paris' ) {
		$t = Schedule::next_run( $s, $this->local_ts( $now_local, $tz ), new \DateTimeZone( $tz ) );
		return ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( new \DateTimeZone( $tz ) )->format( 'Y-m-d H:i D' );
	}

	public function test_daily() {
		$s = array( 'frequency' => 'daily', 'hour' => 3 );
		$this->assertSame( '2026-10-29 03:00 Thu', $this->next( $s, '2026-10-29 01:15' ) );
		$this->assertSame( '2026-10-30 03:00 Fri', $this->next( $s, '2026-10-29 03:00' ), 'Strictement après : pas deux fois la même heure.' );
		$this->assertSame( '2026-10-30 03:00 Fri', $this->next( $s, '2026-10-29 22:00' ) );
	}

	public function test_twice_daily() {
		$s = array( 'frequency' => 'twicedaily', 'hour' => 15 );
		$this->assertSame( '2026-10-29 03:00 Thu', $this->next( $s, '2026-10-29 00:30' ) );
		$this->assertSame( '2026-10-29 15:00 Thu', $this->next( $s, '2026-10-29 03:00' ) );
		$this->assertSame( '2026-10-30 03:00 Fri', $this->next( $s, '2026-10-29 16:00' ) );
	}

	public function test_weekly() {
		$s = array( 'frequency' => 'weekly', 'hour' => 4, 'weekday' => 1 ); // Lundi.
		$this->assertSame( '2026-11-02 04:00 Mon', $this->next( $s, '2026-10-29 12:00' ) );
		$this->assertSame( '2026-11-02 04:00 Mon', $this->next( $s, '2026-11-02 03:59' ) );
		$this->assertSame( '2026-11-09 04:00 Mon', $this->next( $s, '2026-11-02 04:00' ) );
		$s['weekday'] = 0; // Dimanche.
		$this->assertSame( '2026-11-01 04:00 Sun', $this->next( $s, '2026-10-29 12:00' ) );
	}

	public function test_monthly_and_year_change() {
		$s = array( 'frequency' => 'monthly', 'hour' => 2, 'monthday' => 15 );
		$this->assertSame( '2026-11-15 02:00 Sun', $this->next( $s, '2026-10-29 12:00' ) );
		$this->assertSame( '2027-01-15 02:00 Fri', $this->next( $s, '2026-12-15 02:00' ) );
		$s['monthday'] = 28;
		$this->assertSame( '2027-02-28 02:00 Sun', $this->next( $s, '2027-01-30 12:00' ) );
		$s['monthday'] = 99; // Borné à 28.
		$this->assertSame( '2026-11-28 02:00 Sat', $this->next( $s, '2026-10-29 12:00' ) );
	}

	public function test_daylight_saving_time() {
		$s = array( 'frequency' => 'daily', 'hour' => 3 );
		// Passage à l'heure d'hiver (25/10/2026) : 3 h existe, une seule fois.
		$this->assertSame( '2026-10-25 03:00 Sun', $this->next( $s, '2026-10-24 23:00' ) );
		// Passage à l'heure d'été (29/03/2026) : 2 h n'existe pas, repoussé à 3 h, jamais oublié.
		$s['hour'] = 2;
		$this->assertSame( '2026-03-29 03:00 Sun', $this->next( $s, '2026-03-28 23:00' ) );
		$this->assertSame( '2026-03-30 02:00 Mon', $this->next( $s, '2026-03-29 03:30' ) );
	}

	public function test_timezones_and_invalid_values() {
		$this->assertSame( '2026-10-29 03:00 Thu', $this->next( array( 'frequency' => 'daily', 'hour' => 3 ), '2026-10-28 20:00', 'America/Montreal' ) );
		$this->assertSame( '2026-10-30 23:00 Fri', $this->next( array( 'frequency' => 'nimporte', 'hour' => 99 ), '2026-10-29 23:30' ), 'Fréquence inconnue → quotidienne ; heure bornée à 23.' );
	}
}
