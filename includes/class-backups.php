<?php
/**
 * Sauvegardes automatiques : réglages, planification, exécution en arrière-plan,
 * notifications et restauration.
 *
 * Une sauvegarde est un paquet Lwb Migration ordinaire (archive + installer.php) marqué
 * « backup ». WP-Cron la démarre à l'heure prévue ; elle avance ensuite par étapes de
 * quelques secondes, chaque étape relançant la suivante par une requête signée vers le
 * site lui-même (GET, non bloquante). Un événement WP-Cron de secours reprend la main si
 * ces requêtes sont bloquées (pare-feu, hébergeur) : la sauvegarde finit toujours.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Sauvegardes.
 */
class Backups {

	const OPTION        = 'lwb_migration_backups';
	const STATE         = 'lwb_migration_backup_state';
	const HOOK_RUN      = 'lwb_migration_backup';
	const HOOK_STEP     = 'lwb_migration_backup_step';
	const BG_ACTION     = 'lwb_migration_backup_bg';
	const WATCHDOG      = 180;
	const MAX_ATTEMPTS  = 6;
	const HISTORY       = 10;
	const STALE_SECONDS = 1800;

	/**
	 * Crochets (chargés sur toutes les requêtes : WP-Cron et relais en arrière-plan).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK_RUN, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::HOOK_STEP, array( __CLASS__, 'continue_job' ) );
		add_action( 'wp_ajax_nopriv_' . self::BG_ACTION, array( __CLASS__, 'handle_background' ) );
		add_action( 'wp_ajax_' . self::BG_ACTION, array( __CLASS__, 'handle_background' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( 'admin_notices', array( __CLASS__, 'failure_notice' ) );
	}

	// ------------------------------------------------------------------
	// Réglages.
	// ------------------------------------------------------------------

	/**
	 * Valeurs par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'   => false,
			'frequency' => 'daily',
			'weekday'   => 1,
			'monthday'  => 1,
			'hour'      => 3,
			'content'   => 'full',
			'keep'      => 7,
			'notify'    => 'failure',
			'email'     => '',
		);
	}

	/**
	 * Réglages courants.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Valide, enregistre et replanifie.
	 *
	 * @param array $raw Données non fiables.
	 * @return array Réglages enregistrés.
	 * @throws Migration_Exception Adresse e-mail invalide.
	 */
	public static function save( array $raw ) {
		$s              = self::defaults();
		$s['enabled']   = ! empty( $raw['enabled'] );
		$s['frequency'] = isset( $raw['frequency'] ) && in_array( $raw['frequency'], Schedule::FREQUENCIES, true ) ? $raw['frequency'] : 'daily';
		$s['weekday']   = isset( $raw['weekday'] ) ? min( 6, max( 0, (int) $raw['weekday'] ) ) : 1;
		$s['monthday']  = isset( $raw['monthday'] ) ? min( 28, max( 1, (int) $raw['monthday'] ) ) : 1;
		$s['hour']      = isset( $raw['hour'] ) ? min( 23, max( 0, (int) $raw['hour'] ) ) : 3;
		$s['content']   = isset( $raw['content'] ) && 'database' === $raw['content'] ? 'database' : 'full';
		$s['keep']      = isset( $raw['keep'] ) ? min( 60, max( 1, absint( $raw['keep'] ) ) ) : 7;
		$s['notify']    = isset( $raw['notify'] ) && in_array( $raw['notify'], array( 'never', 'failure', 'always' ), true ) ? $raw['notify'] : 'failure';
		$email          = isset( $raw['email'] ) ? sanitize_email( (string) $raw['email'] ) : '';
		if ( isset( $raw['email'] ) && '' !== trim( (string) $raw['email'] ) && ! is_email( $email ) ) {
			throw new Migration_Exception(
				__( 'L’adresse e-mail de notification est invalide.', 'lwb-migration' ),
				__( 'Corrigez-la, ou laissez le champ vide pour utiliser l’adresse de l’administrateur.', 'lwb-migration' )
			);
		}
		$s['email'] = $email;
		update_option( self::OPTION, $s, true );
		self::reschedule();
		return $s;
	}

	/**
	 * Adresse de notification.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$s = self::settings();
		return '' !== $s['email'] ? $s['email'] : (string) get_option( 'admin_email' );
	}

	// ------------------------------------------------------------------
	// Planification.
	// ------------------------------------------------------------------

	/**
	 * Replanifie la prochaine sauvegarde (ou la supprime si désactivé).
	 *
	 * @return int|null Horodatage prévu.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return null;
		}
		$next = Schedule::next_run( $s, time(), wp_timezone() );
		wp_schedule_single_event( $next, self::HOOK_RUN );
		return $next;
	}

	/**
	 * Filet de sécurité : sauvegardes activées mais aucun événement prévu (restauration
	 * d'une base, nettoyage par une autre extension…).
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		$s = self::settings();
		if ( $s['enabled'] && ! wp_next_scheduled( self::HOOK_RUN ) ) {
			self::reschedule();
		}
	}

	/**
	 * Prochaine sauvegarde prévue.
	 *
	 * @return int|null
	 */
	public static function next_scheduled() {
		$t = wp_next_scheduled( self::HOOK_RUN );
		return $t ? (int) $t : null;
	}

	/**
	 * Désactivation de l'extension : plus aucun événement.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		wp_unschedule_hook( self::HOOK_STEP );
	}

	// ------------------------------------------------------------------
	// État et historique.
	// ------------------------------------------------------------------

	/**
	 * État courant.
	 *
	 * @return array job, started, attempts, history[].
	 */
	public static function state() {
		$st = get_option( self::STATE, array() );
		return array_merge(
			array(
				'job'      => '',
				'started'  => 0,
				'attempts' => 0,
				'history'  => array(),
			),
			is_array( $st ) ? $st : array()
		);
	}

	/**
	 * Enregistre l'état.
	 *
	 * @param array $st État.
	 * @return void
	 */
	private static function save_state( array $st ) {
		$st['history'] = array_slice( $st['history'], 0, self::HISTORY );
		update_option( self::STATE, $st, false );
	}

	/**
	 * Ajoute une entrée à l'historique.
	 *
	 * @param array $entry time, status (ok|error|skipped), trigger, message, size, duration, package.
	 * @return void
	 */
	private static function add_history( array $entry ) {
		$st = self::state();
		array_unshift( $st['history'], $entry );
		self::save_state( $st );
	}

	/**
	 * Tâche de sauvegarde automatique en cours (null si aucune ou abandonnée).
	 *
	 * @return Job|null
	 */
	public static function running_job() {
		$st = self::state();
		if ( '' === $st['job'] ) {
			return null;
		}
		try {
			$job = Job::load( Security::storage_dir( 'jobs' ), $st['job'] );
		} catch ( \Throwable $e ) {
			$job = null;
		}
		if ( null === $job || 'done' === $job->data['phase'] ) {
			return null;
		}
		return $job;
	}

	// ------------------------------------------------------------------
	// Exécution.
	// ------------------------------------------------------------------

	/**
	 * Événement WP-Cron : sauvegarde à l'heure prévue.
	 *
	 * @return void
	 */
	public static function run_scheduled() {
		// La suivante est planifiée d'abord : un échec n'interrompt jamais le calendrier.
		self::reschedule();
		if ( ! self::settings()['enabled'] ) {
			return;
		}
		$running = self::running_job();
		if ( null !== $running && time() - (int) $running->data['updated'] < self::STALE_SECONDS ) {
			return; // La précédente avance encore.
		}
		if ( null !== $running ) {
			$running->logger()->error( 'Sauvegarde abandonnée (plus de progression depuis 30 minutes) : une nouvelle est lancée.' );
			self::fail( $running, __( 'La sauvegarde ne progressait plus et a été abandonnée.', 'lwb-migration' ) );
		}
		self::start_background( 'auto' );
	}

	/**
	 * Démarre une sauvegarde en arrière-plan.
	 *
	 * @param string $trigger auto|manual.
	 * @return Job|null
	 */
	public static function start_background( $trigger ) {
		try {
			$job = Exporter::start(
				array(
					'kind'    => 'backup',
					'trigger' => $trigger,
					'db_only' => 'database' === self::settings()['content'],
				)
			);
		} catch ( \Throwable $e ) {
			self::add_history(
				array(
					'time'    => time(),
					'status'  => 'error',
					'trigger' => $trigger,
					'message' => $e->getMessage(),
				)
			);
			self::notify( false, $e->getMessage(), null );
			return null;
		}
		$st             = self::state();
		$st['job']      = $job->id();
		$st['started']  = time();
		$st['attempts'] = 0;
		self::save_state( $st );
		self::work( $job->id() );
		return $job;
	}

	/**
	 * Sauvegarde lancée depuis l'interface : le navigateur fait avancer les étapes ; si la
	 * page est fermée, l'événement de secours la termine en arrière-plan.
	 *
	 * @return Job
	 * @throws Migration_Exception Environnement inutilisable.
	 */
	public static function start_manual() {
		$job            = Exporter::start(
			array(
				'kind'    => 'backup',
				'trigger' => 'manual',
				'db_only' => 'database' === self::settings()['content'],
			)
		);
		$st             = self::state();
		$st['job']      = $job->id();
		$st['started']  = time();
		$st['attempts'] = 0;
		self::save_state( $st );
		self::dispatch( $job->id(), self::WATCHDOG, false );
		return $job;
	}

	/**
	 * Événement de secours : reprend une sauvegarde si le relais s'est interrompu.
	 *
	 * @param string $job_id Tâche.
	 * @return void
	 */
	public static function continue_job( $job_id ) {
		self::work( (string) $job_id );
	}

	/**
	 * Relais en arrière-plan (requête du site vers lui-même, signée).
	 *
	 * @return void
	 */
	public static function handle_background() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authentification HMAC ci-dessous.
		$job = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		$ts  = isset( $_GET['t'] ) ? absint( $_GET['t'] ) : 0;
		$sig = isset( $_GET['sig'] ) ? sanitize_key( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable
		if ( abs( time() - $ts ) > HOUR_IN_SECONDS || ! hash_equals( self::signature( $job, $ts ), $sig ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Réponse immédiate : l'étape continue sans faire attendre l'appelant.
			echo '{"success":true}';
			fastcgi_finish_request();
		}
		self::work( $job );
		exit;
	}

	/**
	 * Signature d'un relais.
	 *
	 * @param string $job Tâche.
	 * @param int    $ts  Horodatage.
	 * @return string
	 */
	private static function signature( $job, $ts ) {
		return hash_hmac( 'sha256', 'backup|' . $job . '|' . $ts, wp_salt( 'nonce' ) . '|' . (string) get_option( Security::OPT_SECRET ) );
	}

	/**
	 * Fait avancer une sauvegarde d'une étape, puis organise la suite.
	 *
	 * @param string $job_id Tâche.
	 * @return void
	 */
	public static function work( $job_id ) {
		$st = self::state();
		if ( '' === $job_id || $st['job'] !== $job_id ) {
			return; // Tâche inconnue, annulée ou déjà terminée.
		}
		try {
			$job = Job::load( Security::storage_dir( 'jobs' ), $job_id );
		} catch ( \Throwable $e ) {
			$job = null;
		}
		if ( null === $job ) {
			$st['job'] = '';
			self::save_state( $st );
			wp_clear_scheduled_hook( self::HOOK_STEP, array( $job_id ) );
			return;
		}
		$res = Exporter::step( $job );
		$job = Job::load( Security::storage_dir( 'jobs' ), $job_id );
		if ( null === $job ) {
			return;
		}
		if ( 'done' === $res['status'] ) {
			wp_clear_scheduled_hook( self::HOOK_STEP, array( $job_id ) );
			return; // Exporter::step a appelé record_success().
		}
		if ( 'busy' === $res['status'] ) {
			self::dispatch( $job_id, self::WATCHDOG, false ); // Une autre requête travaille déjà.
			return;
		}
		$st = self::state();
		if ( 'error' === $res['status'] ) {
			++$st['attempts'];
			self::save_state( $st );
			if ( empty( $res['retryable'] ) || $st['attempts'] >= self::MAX_ATTEMPTS ) {
				self::fail( $job, (string) $res['message'] . ( ! empty( $res['solution'] ) ? ' – ' . $res['solution'] : '' ) );
				return;
			}
			// Nouvel essai plus tard, au dernier point de reprise.
			self::dispatch( $job_id, 60 * $st['attempts'], false );
			return;
		}
		if ( $st['attempts'] > 0 ) {
			$st['attempts'] = 0;
			self::save_state( $st );
		}
		self::dispatch( $job_id, self::WATCHDOG, true );
	}

	/**
	 * Organise l'étape suivante : relais immédiat + événement de secours.
	 *
	 * @param string $job_id   Tâche.
	 * @param int    $delay    Délai de l'événement de secours.
	 * @param bool   $loopback Relancer immédiatement par une requête vers le site.
	 * @return void
	 */
	private static function dispatch( $job_id, $delay, $loopback ) {
		wp_clear_scheduled_hook( self::HOOK_STEP, array( $job_id ) );
		wp_schedule_single_event( time() + max( 30, (int) $delay ), self::HOOK_STEP, array( $job_id ) );
		if ( ! $loopback || ! apply_filters( 'lwb_migration_backup_loopback', true ) ) {
			return;
		}
		$ts  = time();
		$url = add_query_arg(
			array(
				'action' => self::BG_ACTION,
				'job'    => $job_id,
				't'      => $ts,
				'sig'    => self::signature( $job_id, $ts ),
			),
			admin_url( 'admin-ajax.php' )
		);
		// GET : les pare-feux anti-robot des hébergeurs bloquent souvent les POST de serveur à serveur.
		wp_remote_get(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre de WordPress (requêtes vers soi-même).
				'cookies'   => array(),
			)
		);
	}

	/**
	 * Sauvegarde réussie (appelé par l'exporteur quand une tâche « backup » se termine).
	 *
	 * @param Job $job Tâche terminée.
	 * @return void
	 */
	public static function record_success( Job $job ) {
		$st   = self::state();
		$size = 0;
		if ( ! empty( $job->data['package_dir'] ) ) {
			$size = (int) @filesize( $job->data['package_dir'] . '/' . $job->data['archive_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( $st['job'] === $job->id() ) {
			$st['job']      = '';
			$st['attempts'] = 0;
			self::save_state( $st );
			wp_clear_scheduled_hook( self::HOOK_STEP, array( $job->id() ) );
		}
		$entry = array(
			'time'     => time(),
			'status'   => 'ok',
			'trigger'  => $job->data['trigger'],
			'content'  => ! empty( $job->data['db_only'] ) ? 'database' : 'full',
			'size'     => $size,
			'duration' => time() - (int) $job->data['created'],
			'package'  => $job->id(),
			'warnings' => (int) $job->data['warnings'],
			'message'  => '',
		);
		self::add_history( $entry );
		self::notify( true, '', $entry );
	}

	/**
	 * Sauvegarde en échec.
	 *
	 * @param Job    $job     Tâche.
	 * @param string $message Raison.
	 * @return void
	 */
	private static function fail( Job $job, $message ) {
		$st = self::state();
		if ( $st['job'] === $job->id() ) {
			$st['job'] = '';
			self::save_state( $st );
		}
		wp_clear_scheduled_hook( self::HOOK_STEP, array( $job->id() ) );
		$job->logger()->error( 'Sauvegarde abandonnée : ' . $message );
		// Le journal est conservé pour le diagnostic ; les fichiers temporaires sont supprimés.
		$log = Logger::read_contents( $job->path( 'log.php' ) );
		$job->delete();
		$entry = array(
			'time'    => time(),
			'status'  => 'error',
			'trigger' => isset( $job->data['trigger'] ) ? $job->data['trigger'] : 'auto',
			'content' => ! empty( $job->data['db_only'] ) ? 'database' : 'full',
			'message' => $message,
			'log'     => substr( (string) $log, -4000 ),
		);
		self::add_history( $entry );
		self::notify( false, $message, $entry );
	}

	/**
	 * Notification par e-mail selon les réglages.
	 *
	 * @param bool       $ok      Réussite.
	 * @param string     $message Raison de l'échec.
	 * @param array|null $entry   Entrée d'historique.
	 * @return void
	 */
	private static function notify( $ok, $message, $entry ) {
		$s = self::settings();
		if ( 'never' === $s['notify'] || ( $ok && 'always' !== $s['notify'] ) ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url  = admin_url( 'admin.php?page=' . Admin::SLUG . '&tab=backups' );
		if ( $ok ) {
			/* translators: %s: nom du site. */
			$subject = sprintf( __( '[%s] Sauvegarde réussie', 'lwb-migration' ), $site );
			$body    = sprintf(
				/* translators: 1: site, 2: date, 3: taille, 4: durée, 5: lien. */
				__( "La sauvegarde de %1\$s s’est terminée avec succès.\n\nDate : %2\$s\nTaille : %3\$s\nDurée : %4\$s\n\nGérer les sauvegardes : %5\$s", 'lwb-migration' ),
				home_url(),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ),
				Runtime::format_bytes( $entry['size'] ),
				human_time_diff( 0, max( 1, $entry['duration'] ) ),
				$url
			);
		} else {
			/* translators: %s: nom du site. */
			$subject = sprintf( __( '[%s] Échec de la sauvegarde automatique', 'lwb-migration' ), $site );
			$body    = sprintf(
				/* translators: 1: site, 2: raison, 3: lien. */
				__( "La sauvegarde automatique de %1\$s a échoué.\n\nRaison : %2\$s\n\nLa prochaine sauvegarde aura lieu comme prévu. Vous pouvez aussi en lancer une tout de suite et consulter le journal ici : %3\$s", 'lwb-migration' ),
				home_url(),
				$message,
				$url
			);
		}
		wp_mail( self::notify_email(), $subject, $body );
	}

	/**
	 * Avis dans l'administration quand la dernière sauvegarde automatique a échoué.
	 *
	 * @return void
	 */
	public static function failure_notice() {
		if ( ! current_user_can( Security::CAPABILITY ) || ! self::settings()['enabled'] ) {
			return;
		}
		$history = self::state()['history'];
		if ( empty( $history ) || 'error' !== $history[0]['status'] ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_' . Admin::SLUG === $screen->id ) {
			return; // L'onglet Sauvegardes l'affiche déjà.
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Lwb Migration : la dernière sauvegarde automatique a échoué.', 'lwb-migration' ),
			esc_html( $history[0]['message'] ),
			esc_url( admin_url( 'admin.php?page=' . Admin::SLUG . '&tab=backups' ) ),
			esc_html__( 'Voir les sauvegardes', 'lwb-migration' )
		);
	}

	// ------------------------------------------------------------------
	// Restauration.
	// ------------------------------------------------------------------

	/**
	 * Prépare la restauration d'une sauvegarde sur ce site : l'archive est placée à la
	 * racine et un installeur à jeton est généré, base et adresses préremplies.
	 *
	 * @param string $id Paquet.
	 * @return string URL de l'installeur.
	 * @throws Migration_Exception Paquet introuvable ou racine non inscriptible.
	 */
	public static function prepare_restore( $id ) {
		$file = Exporter::package_file( $id, 'archive' );
		if ( null === $file || ! is_file( $file[0] ) ) {
			throw new Migration_Exception( __( 'Sauvegarde introuvable.', 'lwb-migration' ), __( 'Rechargez la page.', 'lwb-migration' ) );
		}
		Runtime::prepare();
		// Copie de travail à côté de la sauvegarde : lien physique d'abord (instantané, sans
		// espace disque), copie sinon. install_package() la déplace à la racine et l'installeur
		// la supprime à la fin : la sauvegarde d'origine reste intacte.
		$part = Security::storage_dir( 'jobs' ) . '/restore-' . $id . '.part';
		@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! ( function_exists( 'link' ) && @link( $file[0], $part ) ) && ! @copy( $file[0], $part ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new Migration_Exception(
				__( 'Impossible de préparer la restauration.', 'lwb-migration' ),
				__( 'Vérifiez l’espace disque disponible sur le serveur.', 'lwb-migration' )
			);
		}
		try {
			return Direct_Transfer::install_package( $part, $file[1], $id );
		} finally {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Espace disque : occupé par les sauvegardes, libre sur le serveur.
	 *
	 * @return array used, free (null si inconnu).
	 */
	public static function disk_usage() {
		$used = 0;
		foreach ( Exporter::list_packages( 'backup' ) as $p ) {
			$used += (int) $p['archive_size'];
		}
		$free = null;
		if ( function_exists( 'disk_free_space' ) && Runtime::function_enabled( 'disk_free_space' ) ) {
			try {
				$f    = @disk_free_space( Security::storage_dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$free = false === $f ? null : (float) $f;
			} catch ( \Throwable $e ) {
				$free = null;
			}
		}
		return array(
			'used' => $used,
			'free' => $free,
		);
	}
}
