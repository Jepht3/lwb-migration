<?php
/**
 * Points d'entrée AJAX (admin uniquement, nonce + manage_options).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Contrôleur AJAX.
 */
class Ajax {

	/**
	 * Enregistre les actions.
	 *
	 * @return void
	 */
	public static function register() {
		$actions = array( 'export_start', 'job_step', 'job_cancel', 'packages', 'package_delete', 'settings_save', 'key_generate', 'key_revoke', 'direct_start', 'download', 'check_update', 'backup_settings_save', 'backup_start', 'backup_status', 'backup_restore' );
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_lwb_migration_' . $action, array( __CLASS__, $action ) );
		}
		// Import depuis l'administration (onglet « Importer »).
		foreach ( array( 'import_init', 'import_chunk', 'import_finalize', 'import_cancel' ) as $action ) {
			add_action( 'wp_ajax_lwb_migration_' . $action, array( Upload_Import::class, $action ) );
		}
		// Réception de la migration directe : appel de serveur à serveur, authentifié par HMAC.
		add_action( 'wp_ajax_nopriv_lwb_migration_receive', array( Direct_Transfer::class, 'handle_receive' ) );
		add_action( 'wp_ajax_lwb_migration_receive', array( Direct_Transfer::class, 'handle_receive' ) );
		// Téléchargement de l'archive par la destination (mode « pull ») : lien signé.
		add_action( 'wp_ajax_nopriv_lwb_migration_fetch', array( Direct_Transfer::class, 'handle_fetch' ) );
		add_action( 'wp_ajax_lwb_migration_fetch', array( Direct_Transfer::class, 'handle_fetch' ) );
	}

	/**
	 * Réponse d'erreur à partir d'une exception.
	 *
	 * @param \Throwable $e Exception.
	 * @return void
	 */
	private static function fail( \Throwable $e ) {
		wp_send_json_error(
			array(
				'message'  => $e->getMessage(),
				'solution' => $e instanceof Migration_Exception ? $e->get_solution() : __( 'Rechargez la page et réessayez.', 'lwb-migration' ),
			)
		);
	}

	/**
	 * Démarre un export.
	 *
	 * @return void
	 */
	public static function export_start() {
		Security::check_ajax();
		$password = Security::post_raw( 'password' );
		if ( '' !== $password && strlen( $password ) < 8 ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Le mot de passe de l’installeur est trop court.', 'lwb-migration' ),
					'solution' => __( 'Utilisez au moins 8 caractères, ou laissez le champ vide.', 'lwb-migration' ),
				)
			);
		}
		try {
			$args    = array(
				'password' => $password,
				'db_only'  => 'database' === Security::post_text( 'content' ),
			);
			$encrypt = Security::post_text( 'encrypt' );
			if ( '' !== $encrypt ) {
				$args['encrypt'] = '1' === $encrypt;
			}
			$job = Exporter::start( $args );
			wp_send_json_success(
				array_merge(
					array(
						'job'      => $job->id(),
						// Mot de passe saisi, ou généré si le champ était vide : affiché une seule fois.
						'password' => (string) $job->data['password'],
					),
					Exporter::status( $job )
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Charge la tâche demandée ou termine.
	 *
	 * @return Job
	 */
	private static function job_from_request() {
		try {
			$job = Job::load( Security::storage_dir( 'jobs' ), Security::post_text( 'job' ) );
		} catch ( \Throwable $e ) {
			$job = null;
		}
		if ( null === $job ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Cette opération est introuvable ou a expiré.', 'lwb-migration' ),
					'solution' => __( 'Relancez l’export.', 'lwb-migration' ),
				)
			);
		}
		return $job;
	}

	/**
	 * Exécute une étape.
	 *
	 * @return void
	 */
	public static function job_step() {
		Security::check_ajax();
		$job = self::job_from_request();
		wp_send_json_success( Exporter::step( $job ) );
	}

	/**
	 * Annule une tâche.
	 *
	 * @return void
	 */
	public static function job_cancel() {
		Security::check_ajax();
		$job = self::job_from_request();
		if ( ! $job->lock() ) {
			// Étape en cours : on supprimera quand même, l'étape échouera proprement.
			$job->unlock();
		}
		$job->delete();
		wp_send_json_success();
	}

	/**
	 * Liste des paquets.
	 *
	 * @return void
	 */
	public static function packages() {
		Security::check_ajax();
		try {
			wp_send_json_success( self::packages_payload( 'backup' === Security::post_text( 'kind' ) ? 'backup' : 'migration' ) );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Données des paquets pour l'interface.
	 *
	 * @param string $kind migration|backup.
	 * @return array[]
	 */
	public static function packages_payload( $kind = 'migration' ) {
		$out = array();
		foreach ( Exporter::list_packages( $kind ) as $p ) {
			$base  = array(
				'action'  => 'lwb_migration_download',
				'package' => $p['id'],
				'nonce'   => wp_create_nonce( Security::NONCE ),
			);
			$out[] = array(
				'id'        => $p['id'],
				'date'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $p['created'] ),
				'size'      => Runtime::format_bytes( $p['archive_size'] ),
				'files'     => number_format_i18n( $p['files'] ),
				'tables'    => number_format_i18n( $p['tables'] ),
				'format'    => 'lwb' === $p['format'] ? 'LWB' : 'ZIP',
				'protected' => ! empty( $p['protected'] ),
				'direct'    => ! empty( $p['direct'] ),
				'warnings'  => (int) $p['warnings'],
				'archive'   => $p['archive'],
				'filename'  => Exporter::download_name( $p ),
				'trigger'   => $p['trigger'],
				'content'   => $p['content'],
				'password'  => 'backup' === $kind && isset( $p['installer_password'] ) ? (string) $p['installer_password'] : '',
				'urls'      => array(
					'archive'   => add_query_arg( array_merge( $base, array( 'file' => 'archive' ) ), admin_url( 'admin-ajax.php' ) ),
					'installer' => add_query_arg( array_merge( $base, array( 'file' => 'installer' ) ), admin_url( 'admin-ajax.php' ) ),
					'log'       => $p['has_log'] ? add_query_arg( array_merge( $base, array( 'file' => 'log' ) ), admin_url( 'admin-ajax.php' ) ) : '',
				),
			);
		}
		return $out;
	}

	/**
	 * Supprime un paquet.
	 *
	 * @return void
	 */
	public static function package_delete() {
		Security::check_ajax();
		$id   = Security::post_text( 'package' );
		$kind = 'backup' === Security::post_text( 'kind' ) ? 'backup' : 'migration';
		if ( ! Exporter::delete_package( $id ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Suppression impossible.', 'lwb-migration' ),
					'solution' => __( 'Vérifiez les permissions du dossier de stockage.', 'lwb-migration' ),
				)
			);
		}
		wp_send_json_success( self::packages_payload( $kind ) );
	}

	/**
	 * Téléchargement d'un fichier de paquet (flux, sans charger le fichier en mémoire).
	 *
	 * @return void
	 */
	public static function download() {
		if ( ! check_ajax_referer( Security::NONCE, 'nonce', false ) || ! current_user_can( Security::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'lwb-migration' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- vérifié ci-dessus.
		$id   = isset( $_GET['package'] ) ? sanitize_key( wp_unslash( $_GET['package'] ) ) : '';
		$file = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : '';
		// phpcs:enable
		$target = Exporter::package_file( $id, $file );
		if ( null === $target || ! is_file( $target[0] ) ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'lwb-migration' ), '', array( 'response' => 404 ) );
		}
		Runtime::prepare();
		if ( Runtime::function_enabled( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $target[1] . '"' );
		if ( 'log' === $file ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo Logger::read_contents( $target[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichier texte téléchargé.
			exit;
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . filesize( $target[0] ) );
		$h = fopen( $target[0], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		while ( $h && ! feof( $h ) ) {
			echo fread( $h, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- contenu binaire.
			flush();
		}
		if ( $h ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * Enregistre les réglages.
	 *
	 * @return void
	 */
	public static function settings_save() {
		Security::check_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié ; chaque champ est assaini par Settings::save().
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		Settings::save( $raw );
		wp_send_json_success( array( 'message' => __( 'Réglages enregistrés.', 'lwb-migration' ) ) );
	}

	/**
	 * Génère une clé de migration directe (côté destination).
	 *
	 * @return void
	 */
	public static function key_generate() {
		Security::check_ajax();
		$key = Direct_Transfer::generate_key();
		wp_send_json_success(
			array(
				'key'     => $key['key'],
				'expires' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $key['expires'] ),
			)
		);
	}

	/**
	 * Révoque la clé.
	 *
	 * @return void
	 */
	public static function key_revoke() {
		Security::check_ajax();
		Direct_Transfer::revoke();
		wp_send_json_success();
	}

	/**
	 * Vérifie immédiatement la présence d'une nouvelle version.
	 *
	 * @return void
	 */
	public static function check_update() {
		Security::check_ajax();
		wp_send_json_success( Updater::check_now() );
	}

	/**
	 * Démarre une migration directe (côté source).
	 *
	 * @return void
	 * @throws Migration_Exception Adresse définitive invalide (interceptée et renvoyée en JSON).
	 */
	public static function direct_start() {
		Security::check_ajax();
		try {
			$remote = Direct_Transfer::parse_key( Security::post_raw( 'key' ) );

			// Avant le changement de DNS : adresse IP ou URL temporaire du nouveau serveur.
			$route = Route::parse( Security::post_text( 'via' ), $remote['url'], Direct_Transfer::allow_http() );
			if ( null !== $route ) {
				$remote['route'] = $route;
			}
			$final = trim( Security::post_text( 'final_url' ) );
			if ( '' !== $final ) {
				if ( ! preg_match( '#^https?://[^\s/?\#]+[^\s?\#]*$#i', $final ) ) {
					throw new Migration_Exception(
						__( 'Adresse définitive du site invalide.', 'lwb-migration' ),
						__( 'Saisissez une URL complète, par exemple https://www.mon-site.fr, ou laissez le champ vide.', 'lwb-migration' )
					);
				}
				$remote['final_url'] = untrailingslashit( esc_url_raw( $final ) );
			}
			$mode           = Security::post_text( 'mode', 'auto' );
			$remote['mode'] = in_array( $mode, array( 'auto', 'push', 'pull' ), true ) ? $mode : 'auto';
			$info           = Direct_Transfer::hello( $remote );
			$job            = Exporter::start(
				array(
					'password' => '',
					'direct'   => array_merge(
						$remote,
						array(
							'site_url' => esc_url_raw( (string) $info['site_url'] ),
							'chunk'    => (int) $info['chunk'],
						)
					),
				)
			);
			$job->logger()->info( sprintf( 'Migration directe vers %s (WordPress %s, PHP %s, Lwb Migration %s).', $info['site_url'], $info['wp_version'], $info['php_version'], isset( $info['plugin_version'] ) ? $info['plugin_version'] : '?' ) );
			$modes = array(
				'auto' => 'automatique (envoi par la source, téléchargement par la destination si le pare-feu bloque)',
				'push' => 'envoi par la source',
				'pull' => 'téléchargement par la destination',
			);
			$job->logger()->info( 'Mode de transfert : ' . $modes[ $remote['mode'] ] . '.' );
			if ( ! empty( $remote['final_url'] ) ) {
				$job->logger()->info( 'Adresse définitive du site : ' . $remote['final_url'] . '.' );
			}
			wp_send_json_success(
				array_merge(
					array(
						'job'         => $job->id(),
						'destination' => esc_url_raw( (string) $info['site_url'] ),
					),
					Exporter::status( $job )
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Enregistre les réglages des sauvegardes automatiques.
	 *
	 * @return void
	 */
	public static function backup_settings_save() {
		Security::check_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié ; valeurs validées par Backups::save().
		$raw = isset( $_POST['backup'] ) && is_array( $_POST['backup'] ) ? wp_unslash( $_POST['backup'] ) : array();
		try {
			Backups::save( $raw );
			wp_send_json_success(
				array_merge(
					array( 'message' => __( 'Réglages des sauvegardes enregistrés.', 'lwb-migration' ) ),
					self::backup_status_payload()
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Lance une sauvegarde maintenant (pilotée par le navigateur, poursuivie en arrière-plan
	 * si la page est fermée).
	 *
	 * @return void
	 */
	public static function backup_start() {
		Security::check_ajax();
		try {
			$running = Backups::running_job();
			if ( null !== $running ) {
				wp_send_json_success( array_merge( array( 'job' => $running->id() ), Exporter::status( $running ) ) );
			}
			$job = Backups::start_manual();
			wp_send_json_success( array_merge( array( 'job' => $job->id() ), Exporter::status( $job ) ) );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * État des sauvegardes (prochaine, en cours, historique, espace disque).
	 *
	 * @return void
	 */
	public static function backup_status() {
		Security::check_ajax();
		wp_send_json_success( self::backup_status_payload() );
	}

	/**
	 * Données d'état pour l'interface.
	 *
	 * @return array
	 */
	public static function backup_status_payload() {
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$next    = Backups::next_scheduled();
		$running = Backups::running_job();
		$disk    = Backups::disk_usage();
		$history = array();
		foreach ( Backups::state()['history'] as $h ) {
			$history[] = array(
				'date'     => wp_date( $fmt, (int) $h['time'] ),
				'status'   => $h['status'],
				'trigger'  => isset( $h['trigger'] ) ? $h['trigger'] : 'auto',
				'content'  => isset( $h['content'] ) ? $h['content'] : 'full',
				'size'     => ! empty( $h['size'] ) ? Runtime::format_bytes( (int) $h['size'] ) : '',
				'duration' => ! empty( $h['duration'] ) ? human_time_diff( 0, (int) $h['duration'] ) : '',
				'message'  => isset( $h['message'] ) ? (string) $h['message'] : '',
				'log'      => isset( $h['log'] ) ? (string) $h['log'] : '',
			);
		}
		return array(
			'enabled' => Backups::settings()['enabled'],
			'next'    => $next ? wp_date( $fmt, $next ) : '',
			'running' => $running ? array_merge( array( 'job' => $running->id() ), Exporter::status( $running ) ) : null,
			'history' => $history,
			'used'    => Runtime::format_bytes( $disk['used'] ),
			'free'    => null === $disk['free'] ? '' : Runtime::format_bytes( $disk['free'] ),
		);
	}

	/**
	 * Prépare la restauration d'une sauvegarde et renvoie l'adresse de l'installeur.
	 *
	 * @return void
	 */
	public static function backup_restore() {
		Security::check_ajax();
		try {
			wp_send_json_success( array( 'installer_url' => Backups::prepare_restore( Security::post_text( 'package' ) ) ) );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}
}
