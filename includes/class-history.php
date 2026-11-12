<?php
/**
 * Historique des migrations directes et des exports : chaque opération est inscrite dès son
 * démarrage, puis mise à jour (réussite, échec, annulation). Une opération abandonnée en
 * route (navigateur fermé) apparaît comme « interrompue ».
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Historique des opérations (hors sauvegardes automatiques, qui ont le leur).
 */
final class History {

	const OPTION = 'lwb_migration_history';
	const MAX    = 40;
	/** Sans progression depuis ce délai, une opération « en cours » est considérée interrompue. */
	const STALE = 1800;

	/**
	 * Entrées brutes, plus récentes d'abord.
	 *
	 * @return array[]
	 */
	private static function raw() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Enregistre la liste.
	 *
	 * @param array[] $entries Entrées.
	 * @return void
	 */
	private static function save( array $entries ) {
		update_option( self::OPTION, array_slice( array_values( $entries ), 0, self::MAX ), false );
	}

	/**
	 * Ajoute ou met à jour l'entrée d'une tâche.
	 *
	 * @param string $id     Identifiant de la tâche.
	 * @param array  $fields Champs.
	 * @return void
	 */
	private static function put( $id, array $fields ) {
		$list = self::raw();
		foreach ( $list as $i => $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $id ) {
				$list[ $i ] = array_merge( $entry, $fields, array( 'updated' => time() ) );
				self::save( $list );
				return;
			}
		}
		array_unshift(
			$list,
			array_merge(
				array(
					'id'      => $id,
					'started' => time(),
				),
				$fields,
				array( 'updated' => time() )
			)
		);
		self::save( $list );
	}

	/**
	 * Champs décrivant la tâche.
	 *
	 * @param Job $job Tâche.
	 * @return array
	 */
	private static function describe( Job $job ) {
		$d      = $job->data;
		$direct = ! empty( $d['direct'] ) && is_array( $d['direct'] );
		$fields = array(
			'type'     => $direct ? 'direct' : 'export',
			'started'  => (int) $d['created'],
			'content'  => ! empty( $d['db_only'] ) ? 'database' : 'full',
			'warnings' => isset( $d['warnings'] ) ? (int) $d['warnings'] : 0,
		);
		if ( $direct ) {
			$fields['destination'] = isset( $d['direct']['site_url'] ) ? (string) $d['direct']['site_url'] : '';
			$fields['final_url']   = isset( $d['direct']['final_url'] ) ? (string) $d['direct']['final_url'] : '';
			$fields['mode']        = isset( $d['direct']['mode'] ) ? (string) $d['direct']['mode'] : 'auto';
			$fields['via']         = ! empty( $d['direct']['route'] );
		}
		if ( ! empty( $d['package_dir'] ) ) {
			$fields['package'] = $job->id();
			$fields['size']    = (int) @filesize( $d['package_dir'] . '/' . $d['archive_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! empty( $d['remote_result']['installer_url'] ) ) {
			$fields['installer_url'] = (string) $d['remote_result']['installer_url'];
			$fields['installed']     = ! empty( $d['remote_result']['installed'] );
		}
		return $fields;
	}

	/**
	 * Fin du journal de la tâche (pour le diagnostic, même si la tâche est supprimée).
	 *
	 * @param Job $job Tâche.
	 * @return string
	 */
	private static function log_tail( Job $job ) {
		return substr( (string) Logger::read_contents( $job->path( 'log.php' ) ), -4000 );
	}

	/**
	 * Une opération démarre : elle est inscrite tout de suite.
	 *
	 * @param Job $job Tâche.
	 * @return void
	 */
	public static function begin( Job $job ) {
		if ( 'backup' === ( isset( $job->data['kind'] ) ? $job->data['kind'] : '' ) ) {
			return;
		}
		self::put( $job->id(), array_merge( self::describe( $job ), array( 'status' => 'running' ) ) );
	}

	/**
	 * Point d'étape : statut « en cours » rafraîchi (réussite, ou reprise après une erreur).
	 *
	 * @param Job $job Tâche.
	 * @return void
	 */
	public static function progress( Job $job ) {
		if ( 'backup' === ( isset( $job->data['kind'] ) ? $job->data['kind'] : '' ) ) {
			return;
		}
		$done   = 'done' === $job->data['phase'];
		$fields = array_merge(
			self::describe( $job ),
			array(
				'status'  => $done ? 'ok' : 'running',
				'message' => '',
			)
		);
		if ( $done ) {
			$fields['finished'] = time();
			$fields['log']      = '';
		}
		self::put( $job->id(), $fields );
	}

	/**
	 * Échec d'une étape (l'opération peut encore être relancée : une reprise réussie
	 * remettra l'entrée à jour).
	 *
	 * @param Job    $job     Tâche.
	 * @param string $message Raison.
	 * @return void
	 */
	public static function failure( Job $job, $message ) {
		if ( 'backup' === ( isset( $job->data['kind'] ) ? $job->data['kind'] : '' ) ) {
			return;
		}
		if ( ! empty( $job->data['package_dir'] ) ) {
			// Journal téléchargeable avec le paquet, même si la tâche expire ensuite.
			@copy( $job->path( 'log.php' ), $job->data['package_dir'] . '/log.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::put(
			$job->id(),
			array_merge(
				self::describe( $job ),
				array(
					'status'   => 'error',
					'message'  => (string) $message,
					'finished' => time(),
					'log'      => self::log_tail( $job ),
				)
			)
		);
	}

	/**
	 * Annulation par l'utilisateur (avant la suppression de la tâche).
	 *
	 * @param Job $job Tâche.
	 * @return void
	 */
	public static function cancelled( Job $job ) {
		if ( 'backup' === ( isset( $job->data['kind'] ) ? $job->data['kind'] : '' ) ) {
			return;
		}
		$job->logger()->warning( 'Opération annulée par l’utilisateur.' );
		if ( ! empty( $job->data['package_dir'] ) && is_dir( $job->data['package_dir'] ) ) {
			@copy( $job->path( 'log.php' ), $job->data['package_dir'] . '/log.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::put(
			$job->id(),
			array_merge(
				self::describe( $job ),
				array(
					'status'   => 'cancelled',
					'finished' => time(),
					'log'      => self::log_tail( $job ),
				)
			)
		);
	}

	/**
	 * Entrées prêtes à afficher, avec les fichiers encore téléchargeables.
	 *
	 * @param string $type direct|export|'' (toutes).
	 * @return array[]
	 */
	public static function entries( $type = '' ) {
		$out  = array();
		$jobs = Security::storage_dir( 'jobs' );
		foreach ( self::raw() as $e ) {
			if ( empty( $e['id'] ) || ( '' !== $type && ( isset( $e['type'] ) ? $e['type'] : '' ) !== $type ) ) {
				continue;
			}
			$e = array_merge(
				array(
					'type'          => 'export',
					'status'        => 'running',
					'started'       => 0,
					'updated'       => 0,
					'finished'      => 0,
					'content'       => 'full',
					'destination'   => '',
					'final_url'     => '',
					'mode'          => '',
					'via'           => false,
					'package'       => '',
					'size'          => 0,
					'installer_url' => '',
					'installed'     => false,
					'warnings'      => 0,
					'message'       => '',
					'log'           => '',
				),
				$e
			);
			if ( 'running' === $e['status'] ) {
				$job = Job::load( $jobs, $e['id'] );
				if ( null === $job || time() - (int) $job->data['updated'] > self::STALE ) {
					$e['status'] = 'interrupted';
				}
			}
			$e['files'] = array();
			if ( '' !== $e['package'] ) {
				foreach ( array( 'archive', 'installer', 'log' ) as $file ) {
					$target = Exporter::package_file( $e['package'], $file );
					if ( null !== $target && is_file( $target[0] ) ) {
						$e['files'][ $file ] = $target[1];
					}
				}
			}
			$end           = $e['finished'] ? (int) $e['finished'] : (int) $e['updated'];
			$e['duration'] = max( 0, $end - (int) $e['started'] );
			$out[]         = $e;
		}
		return $out;
	}

	/**
	 * Retire une entrée.
	 *
	 * @param string $id Identifiant.
	 * @return void
	 */
	public static function delete( $id ) {
		$list = array_filter(
			self::raw(),
			function ( $e ) use ( $id ) {
				return ! isset( $e['id'] ) || $e['id'] !== $id;
			}
		);
		self::save( $list );
	}

	/**
	 * Libellé du statut.
	 *
	 * @param string $status Statut.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'ok'          => __( 'Réussie', 'lwb-migration' ),
			'running'     => __( 'En cours', 'lwb-migration' ),
			'error'       => __( 'Échec', 'lwb-migration' ),
			'cancelled'   => __( 'Annulée', 'lwb-migration' ),
			'interrupted' => __( 'Interrompue', 'lwb-migration' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Libellé du mode de transfert.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function mode_label( $mode ) {
		$labels = array(
			'auto' => __( 'Automatique', 'lwb-migration' ),
			'push' => __( 'Envoi par ce site', 'lwb-migration' ),
			'pull' => __( 'Téléchargement par la destination', 'lwb-migration' ),
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : '';
	}
}
