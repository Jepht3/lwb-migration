<?php
/**
 * Orchestration de l'export : inventaire → dump SQL → archive → paquet.
 *
 * Chaque appel à step() travaille au plus quelques secondes (budget calculé depuis
 * max_execution_time), sauvegarde l'état et rend la main. Après une erreur, un délai
 * dépassé ou une coupure réseau, l'appel suivant reprend au dernier point de contrôle.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Exporteur.
 */
class Exporter {

	const FORMAT_VERSION = 1;

	/**
	 * Démarre un export.
	 *
	 * @param array $args password (string), direct (array|null : informations du site distant).
	 * @return Job
	 * @throws Migration_Exception Environnement inutilisable.
	 */
	public static function start( array $args ) {
		global $wpdb;
		$settings = Settings::get();
		$storage  = Security::storage_dir();
		$jobs     = Security::storage_dir( 'jobs' );
		Job::cleanup( $jobs, 2 * DAY_IN_SECONDS );
		if ( ! wp_is_writable( $jobs ) ) {
			throw new Migration_Exception(
				__( 'Le dossier de stockage n’est pas accessible en écriture.', 'lwb-migration' ),
				__( 'Donnez les permissions 755 au dossier wp-content/uploads.', 'lwb-migration' )
			);
		}

		$format    = Archiver::choose_format( $settings['format'] );
		$abspath   = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$content   = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$outside   = 0 !== strpos( $content . '/', $abspath . '/' );
		$rules     = Settings::file_rules( $settings, $storage );
		$db_prefix = $settings['all_tables'] ? '' : $wpdb->base_prefix;
		$password  = isset( $args['password'] ) ? (string) $args['password'] : '';
		if ( empty( $args['direct'] ) && '' === $password ) {
			// Un installer.php sans mot de passe serait utilisable par n'importe quel visiteur.
			$password = self::generate_password();
		}

		$job = Job::create(
			$jobs,
			array(
				'type'         => 'export',
				'phase'        => 'scan',
				'direct'       => isset( $args['direct'] ) ? $args['direct'] : null,
				'password'     => $password,
				// Archive chiffrée avec le mot de passe de l'installeur (export classique uniquement).
				'crypto'       => empty( $args['direct'] ) && ! empty( $settings['encrypt'] ) && Archive_Crypto::available()
					? array(
						'salt'       => bin2hex( random_bytes( 16 ) ),
						'iterations' => Archive_Crypto::ITERATIONS,
						'in'         => 0,
					)
					: null,
				'format'       => $format,
				'compress'     => (bool) $settings['compress'],
				'archive_name' => 'archive-' . Runtime::random_hex( 32 ) . '.' . $format,
				'rules'        => $rules,
				'scanner'      => array(),
				'dumper'       => array(),
				'archiver'     => array(),
				'list_cursor'  => 0,
				'archive_sub'  => 'files',
				'extra'        => 0,
				'files_done'   => 0,
				'errors'       => 0,
				'warnings'     => 0,
				'source'       => array(
					'site_url'        => untrailingslashit( site_url() ),
					'home_url'        => untrailingslashit( home_url() ),
					'abspath'         => $abspath,
					'content_dir'     => $content,
					'content_outside' => $outside,
					'content_url'     => untrailingslashit( content_url() ),
					'table_prefix'    => $wpdb->base_prefix,
					'wp_version'      => get_bloginfo( 'version' ),
					'php_version'     => PHP_VERSION,
					'db_version'      => $wpdb->db_version(),
					'db_charset'      => $wpdb->charset,
					'db_collate'      => $wpdb->collate,
					'multisite'       => is_multisite(),
					'site_name'       => get_bloginfo( 'name' ),
				),
			)
		);
		$log = $job->logger();
		$log->info( sprintf( 'Export démarré – Lwb Migration %s, WordPress %s, PHP %s, MySQL %s', LWB_MIGRATION_VERSION, get_bloginfo( 'version' ), PHP_VERSION, $wpdb->db_version() ) );
		$log->info( sprintf( 'Site : %s – racine : %s', site_url(), $abspath ) );
		$log->info( sprintf( 'Limites PHP : max_execution_time=%s, memory_limit=%s, budget par étape=%.1f s', ini_get( 'max_execution_time' ), ini_get( 'memory_limit' ), Runtime::default_budget() ) );
		$log->info( sprintf( 'Format d’archive : %s (ZipArchive %s, zlib %s)', 'zip' === $format ? 'ZIP' : 'LWB (repli)', class_exists( '\ZipArchive' ) ? 'oui' : 'non', Archiver::deflate_available() ? 'oui' : 'non' ) );
		if ( is_multisite() ) {
			$log->warning( 'Multisite détecté : seuls les sites partageant le même domaine sont pris en charge par le rechercher/remplacer.' );
		}

		$scanner = new File_Scanner( $job->path( 'files.list' ), $rules );
		$scanner->add_root( $abspath, 'files' );
		if ( $outside ) {
			$scanner->add_root( $content, 'files/wp-content' );
			$log->info( 'Dossier wp-content situé hors de la racine : il sera restauré dans /wp-content.' );
		}
		$parent_config = dirname( $abspath ) . '/wp-config.php';
		if ( ! is_file( $abspath . '/wp-config.php' ) && is_file( $parent_config ) && ! is_file( dirname( $abspath ) . '/wp-settings.php' ) ) {
			$scanner->add_single_file( $parent_config, 'files/wp-config.php' );
			$log->info( 'wp-config.php situé au-dessus de la racine : il sera restauré à la racine.' );
		}
		$job->data['scanner'] = $scanner->get_state();

		$dumper = new Db_Dumper( new Wpdb_Db( $wpdb ), $job->path( 'database.sql' ) );
		$dumper->init( $db_prefix, $settings['exclude_tables'] );
		$job->data['dumper'] = $dumper->get_state();
		$log->info( sprintf( '%d tables à exporter (≈ %s lignes).', count( $job->data['dumper']['tables'] ), number_format_i18n( $job->data['dumper']['rows_total'] ) ) );
		if ( ! empty( $settings['exclude_tables'] ) ) {
			$log->info( 'Tables exclues : ' . implode( ', ', $settings['exclude_tables'] ) );
		}
		$job->save();
		return $job;
	}

	/**
	 * Exécute une étape.
	 *
	 * @param Job $job Tâche.
	 * @return array Réponse pour l'interface.
	 */
	public static function step( Job $job ) {
		Runtime::prepare();
		$rt = new Runtime();
		if ( ! $job->lock() ) {
			return array(
				'status'  => 'busy',
				'message' => __( 'L’étape précédente est encore en cours sur le serveur…', 'lwb-migration' ),
			);
		}
		$log = $job->logger();
		try {
			do {
				$phase = $job->data['phase'];
				if ( 'scan' === $phase ) {
					self::step_scan( $job, $rt );
				} elseif ( 'db' === $phase ) {
					self::step_db( $job, $rt );
				} elseif ( 'archive' === $phase ) {
					self::step_archive( $job, $rt );
				} elseif ( 'encrypt' === $phase ) {
					self::step_encrypt( $job, $rt );
				} elseif ( 'finalize' === $phase ) {
					self::step_finalize( $job );
				} elseif ( 'transfer' === $phase ) {
					Direct_Transfer::send_step( $job, $rt );
				} elseif ( 'remote' === $phase ) {
					Direct_Transfer::remote_finalize( $job );
				} elseif ( 'install' === $phase ) {
					Direct_Transfer::remote_install_step( $job, $rt );
				} else {
					break;
				}
				$job->save();
				if ( $phase === $job->data['phase'] ) {
					break; // Phase non terminée : budget épuisé.
				}
			} while ( ! $rt->should_stop() );
			$job->save();
			$job->unlock();
			return self::status( $job );
		} catch ( Migration_Exception $e ) {
			$log->error( $e->getMessage() . ( $e->get_solution() ? ' – ' . $e->get_solution() : '' ) );
			++$job->data['errors'];
			$job->save();
			$job->unlock();
			return array(
				'status'    => 'error',
				'message'   => $e->getMessage(),
				'solution'  => $e->get_solution(),
				'retryable' => $e->is_retryable(),
				'progress'  => self::progress( $job ),
			);
		} catch ( \Throwable $e ) {
			$log->error( sprintf( 'Erreur PHP : %s (%s:%d)', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
			++$job->data['errors'];
			$job->save();
			$job->unlock();
			return array(
				'status'    => 'error',
				'message'   => sprintf( /* translators: %s: message d'erreur technique. */ __( 'Erreur inattendue : %s', 'lwb-migration' ), $e->getMessage() ),
				'solution'  => __( 'Relancez l’opération ; elle reprendra au dernier point de sauvegarde. Si l’erreur persiste, téléchargez le journal et transmettez-le au support.', 'lwb-migration' ),
				'retryable' => true,
				'progress'  => self::progress( $job ),
			);
		}
	}

	/**
	 * Inventaire des fichiers.
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 */
	private static function step_scan( Job $job, Runtime $rt ) {
		$scanner = new File_Scanner( $job->path( 'files.list' ), $job->data['rules'], $job->data['scanner'] );
		$done    = $scanner->run( $rt );
		self::log_warnings( $job, $scanner->take_warnings() );
		$job->data['scanner'] = $scanner->get_state();
		if ( $done ) {
			$s = $job->data['scanner'];
			$job->logger()->info( sprintf( 'Inventaire terminé : %d fichiers, %d dossiers, %s (%d éléments exclus).', $s['files'], $s['dirs'], Runtime::format_bytes( $s['bytes'] ), $s['excluded'] ) );
			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $job->dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $free && $free < $s['bytes'] * 1.1 ) {
				$job->logger()->warning( sprintf( 'Espace disque faible : %s libres pour environ %s à archiver.', Runtime::format_bytes( $free ), Runtime::format_bytes( $s['bytes'] ) ) );
			}
			$job->data['phase'] = 'db';
		}
	}

	/**
	 * Dump de la base.
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 */
	private static function step_db( Job $job, Runtime $rt ) {
		global $wpdb;
		$dumper              = new Db_Dumper( new Wpdb_Db( $wpdb ), $job->path( 'database.sql' ), $job->data['dumper'] );
		$before              = $dumper->current_table();
		$done                = $dumper->run( $rt );
		$job->data['dumper'] = $dumper->get_state();
		if ( $before !== $dumper->current_table() || $done ) {
			$job->logger()->info( sprintf( 'Base de données : %d/%d tables exportées.', $job->data['dumper']['index'], count( $job->data['dumper']['tables'] ) ) );
		}
		if ( $done ) {
			$job->logger()->info( sprintf( 'Dump SQL terminé : %s lignes, %s.', number_format_i18n( $job->data['dumper']['rows_done'] ), Runtime::format_bytes( (int) @filesize( $job->path( 'database.sql' ) ) ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$job->data['phase'] = 'archive';
		}
	}

	/**
	 * Création de l'archive.
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 * @throws Migration_Exception Liste illisible ou dump absent.
	 * @throws \Throwable Toute erreur, après retour au point de contrôle.
	 */
	private static function step_archive( Job $job, Runtime $rt ) {
		$d        = &$job->data;
		$archiver = new Archiver( $job->path( $d['archive_name'] ), $job->path( 'index.bin' ), $d['format'], $d['archiver'], $d['compress'] );
		$archiver->open();
		$list = @fopen( $job->path( 'files.list' ), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $list ) {
			throw new Migration_Exception( __( 'Liste des fichiers introuvable.', 'lwb-migration' ), __( 'Annulez cet export et relancez-le.', 'lwb-migration' ) );
		}
		fseek( $list, (int) $d['list_cursor'] );
		// Point de contrôle de début d'étape : en cas d'exception, on y revient en bloc pour
		// garder la liste et l'archive cohérentes (l'archive sera tronquée à la reprise).
		$snapshot = array(
			'list_cursor' => $d['list_cursor'],
			'archive_sub' => $d['archive_sub'],
			'extra'       => $d['extra'],
			'files_done'  => $d['files_done'],
			'archiver'    => $d['archiver'],
			'phase'       => $d['phase'],
		);
		$failed   = false;
		try {
			do {
				if ( 'files' === $d['archive_sub'] ) {
					$pos  = ftell( $list );
					$line = fgets( $list );
					if ( false === $line ) {
						$d['archive_sub'] = 'extra';
						continue;
					}
					$item = json_decode( $line, true );
					if ( ! is_array( $item ) || 4 !== count( $item ) ) {
						$d['list_cursor'] = ftell( $list );
						continue;
					}
					list( $type, $abs, $name ) = $item;
					if ( 'd' === $type ) {
						$archiver->add_dir( $name, (int) @filemtime( $abs ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$d['list_cursor'] = ftell( $list );
						continue;
					}
					$status = $archiver->add_file( $abs, $name, $rt );
					if ( Archiver::STATUS_PARTIAL === $status ) {
						$d['list_cursor'] = $pos;
						break;
					}
					$d['list_cursor'] = ftell( $list );
					if ( Archiver::STATUS_DONE === $status ) {
						++$d['files_done'];
					}
					continue;
				}
				// Éléments finaux : base de données, manifeste, fermeture de l'archive.
				if ( 0 === $d['extra'] ) {
					$status = $archiver->add_file( $job->path( 'database.sql' ), 'database.sql', $rt );
					if ( Archiver::STATUS_PARTIAL === $status ) {
						break;
					}
					if ( Archiver::STATUS_SKIPPED === $status ) {
						throw new Migration_Exception( __( 'Le dump SQL est introuvable.', 'lwb-migration' ), __( 'Annulez cet export et relancez-le.', 'lwb-migration' ) );
					}
					$d['extra'] = 1;
				} elseif ( 1 === $d['extra'] ) {
					$archiver->add_string( 'lwb-package.json', (string) wp_json_encode( self::manifest( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
					$d['extra'] = 2;
				} else {
					$archiver->finalize();
					$d['phase'] = empty( $d['crypto'] ) ? 'finalize' : 'encrypt';
					break;
				}
			} while ( ! $rt->should_stop() );
		} catch ( \Throwable $e ) {
			$failed = true;
			throw $e;
		} finally {
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			self::log_warnings( $job, $archiver->take_warnings() );
			$archiver->close();
			if ( $failed ) {
				foreach ( $snapshot as $key => $value ) {
					$d[ $key ] = $value;
				}
			} else {
				$d['archiver'] = $archiver->get_state();
			}
		}
	}

	/**
	 * Manifeste du paquet (lwb-package.json).
	 *
	 * @param Job $job Tâche.
	 * @return array
	 */
	private static function manifest( Job $job ) {
		$d = $job->data;
		return array(
			'generator'      => 'Lwb Migration',
			'version'        => LWB_MIGRATION_VERSION,
			'format_version' => self::FORMAT_VERSION,
			'created'        => gmdate( 'c' ),
			'source'         => $d['source'],
			'files'          => $d['scanner']['files'],
			'files_bytes'    => $d['scanner']['bytes'],
			'tables'         => $d['dumper']['tables'],
			'rows'           => $d['dumper']['rows_done'],
		);
	}

	/**
	 * Chiffrement de l'archive avec le mot de passe de l'installeur (par étapes).
	 *
	 * @param Job     $job Tâche.
	 * @param Runtime $rt  Budget.
	 * @return void
	 */
	private static function step_encrypt( Job $job, Runtime $rt ) {
		$d     = &$job->data;
		$c     = &$d['crypto'];
		$plain = $job->path( $d['archive_name'] );
		$enc   = $plain . Archive_Crypto::EXTENSION;
		if ( ! is_file( $plain ) && null !== Archive_Crypto::read_header( $enc ) ) {
			// Coupure juste après la suppression de l'archive en clair : chiffrement déjà terminé.
			$d['plain_name']   = $d['archive_name'];
			$d['archive_name'] = basename( $enc );
			$d['phase']        = 'finalize';
			return;
		}
		if ( 0 === (int) $c['in'] ) {
			$job->logger()->info( 'Chiffrement de l’archive (AES-256-GCM, clé dérivée du mot de passe de l’installeur).' );
		}
		$state = array(
			'in'         => (int) $c['in'],
			'salt'       => hex2bin( $c['salt'] ),
			'iterations' => (int) $c['iterations'],
		);
		$key   = Archive_Crypto::derive_key( $d['password'], $state['salt'], $state['iterations'] );
		$done  = Archive_Crypto::encrypt_step( $plain, $enc, $key, $state, $rt );

		$c['in'] = $state['in'];
		if ( $done ) {
			@unlink( $plain ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- l'archive en clair ne doit pas rester sur le serveur.
			$d['plain_name']   = $d['archive_name'];
			$d['archive_name'] = basename( $enc );
			$d['phase']        = 'finalize';
			$job->logger()->info( 'Archive chiffrée : ' . $d['archive_name'] . '.' );
		}
	}

	/**
	 * Finalisation : génération de l'installeur et rangement du paquet.
	 *
	 * @param Job $job Tâche.
	 * @return void
	 * @throws Migration_Exception Déplacement impossible.
	 */
	private static function step_finalize( Job $job ) {
		$d       = &$job->data;
		$pkg_dir = Security::storage_dir( 'packages' ) . '/' . $job->id();
		Json_Store::ensure_protected_dir( $pkg_dir );
		$archive = $pkg_dir . '/' . $d['archive_name'];
		if ( is_file( $job->path( $d['archive_name'] ) ) ) {
			if ( ! @rename( $job->path( $d['archive_name'] ), $archive ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				throw new Migration_Exception( __( 'Impossible de déplacer l’archive dans le dossier des paquets.', 'lwb-migration' ), __( 'Vérifiez les permissions du dossier de stockage.', 'lwb-migration' ) );
			}
		}
		$archive_size = (int) filesize( $archive );
		$config       = array(
			'version'        => LWB_MIGRATION_VERSION,
			'format_version' => self::FORMAT_VERSION,
			'package'        => $job->id(),
			'archive'        => $d['archive_name'],
			'archive_size'   => $archive_size,
			'created'        => time(),
			'password_hash'  => '' !== $d['password'] ? password_hash( $d['password'], PASSWORD_DEFAULT ) : '',
			'secret'         => Runtime::random_hex( 64 ),
			'mode'           => 'manual',
			'token_hash'     => '',
			'encryption'     => empty( $d['crypto'] ) ? array() : array(
				'salt'       => $d['crypto']['salt'],
				'iterations' => (int) $d['crypto']['iterations'],
				'plain'      => (string) $d['plain_name'],
			),
			'source'         => $d['source'],
			'target'         => array(),
			'db'             => array(),
		);
		Installer_Builder::build( $pkg_dir . '/installer.dat', $config );
		$protected     = '' !== $d['password'];
		$d['password'] = ''; // Seule l'empreinte bcrypt est conservée (dans installer.php).

		$meta = array(
			'id'           => $job->id(),
			'created'      => time(),
			'archive'      => $d['archive_name'],
			'archive_size' => $archive_size,
			'format'       => $d['format'],
			'files'        => $d['scanner']['files'],
			'tables'       => count( $d['dumper']['tables'] ),
			'site_url'     => $d['source']['site_url'],
			'protected'    => $protected,
			'direct'       => ! empty( $d['direct'] ),
			'warnings'     => $d['warnings'],
		);
		Json_Store::write( $pkg_dir . '/package.php', $meta );
		$job->logger()->info( sprintf( 'Paquet prêt : %s (%s), installer.php généré.', $d['archive_name'], Runtime::format_bytes( $archive_size ) ) );

		foreach ( array( 'files.list', 'database.sql', 'index.bin' ) as $tmp ) {
			@unlink( $job->path( $tmp ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		self::enforce_retention( $job->id() );
		$d['package_dir'] = $pkg_dir;
		$d['phase']       = empty( $d['direct'] ) ? 'done' : 'transfer';
		if ( 'done' === $d['phase'] ) {
			self::archive_log( $job );
		}
	}

	/**
	 * Copie le journal de la tâche dans le dossier du paquet.
	 *
	 * @param Job $job Tâche.
	 * @return void
	 */
	public static function archive_log( Job $job ) {
		if ( empty( $job->data['package_dir'] ) ) {
			return;
		}
		$job->logger()->info( 'Terminé.' );
		@copy( $job->path( 'log.php' ), $job->data['package_dir'] . '/log.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Supprime les paquets les plus anciens au-delà de la limite.
	 *
	 * @param string $keep_id Paquet à conserver.
	 * @return void
	 */
	private static function enforce_retention( $keep_id ) {
		$settings = Settings::get();
		$packages = self::list_packages();
		$excess   = count( $packages ) - (int) $settings['keep_packages'];
		if ( $excess <= 0 ) {
			return;
		}
		$packages = array_reverse( $packages ); // Plus anciens d'abord.
		foreach ( $packages as $p ) {
			if ( $excess <= 0 ) {
				break;
			}
			if ( $p['id'] !== $keep_id ) {
				self::delete_package( $p['id'] );
				--$excess;
			}
		}
	}

	/**
	 * Journalise des avertissements.
	 *
	 * @param Job      $job      Tâche.
	 * @param string[] $warnings Avertissements.
	 * @return void
	 */
	private static function log_warnings( Job $job, array $warnings ) {
		foreach ( $warnings as $w ) {
			$job->logger()->warning( $w );
			++$job->data['warnings'];
		}
	}

	/**
	 * Progression globale (0..100).
	 *
	 * @param Job $job Tâche.
	 * @return float
	 */
	public static function progress( Job $job ) {
		$d     = $job->data;
		$scale = empty( $d['direct'] ) ? 1.0 : 0.6;
		switch ( $d['phase'] ) {
			case 'scan':
				$s    = $d['scanner'];
				$seen = $s['dirs'] + 1;
				$p    = 5 * $seen / ( $seen + count( $s['stack'] ) + 1 );
				break;
			case 'db':
				$p = 5 + 25 * ( $d['dumper']['rows_done'] / max( 1, $d['dumper']['rows_total'] ) );
				break;
			case 'archive':
				$total = $d['scanner']['bytes'] + (int) @filesize( $job->path( 'database.sql' ) ) + 1; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$p     = 30 + 67 * min( 1, ( isset( $d['archiver']['bytes_in'] ) ? $d['archiver']['bytes_in'] : 0 ) / $total );
				break;
			case 'encrypt':
				$p = 97;
				break;
			case 'finalize':
				$p = 98;
				break;
			case 'transfer':
			case 'remote':
				$sent = isset( $d['transfer']['offset'] ) ? $d['transfer']['offset'] : 0;
				$size = isset( $d['transfer']['size'] ) ? $d['transfer']['size'] : 1;
				// Avant le changement de DNS, l'installation pilotée depuis ce site suit le transfert.
				$span = empty( $d['direct']['route'] ) ? 39 : 29;
				return round( 60 + $span * min( 1, $sent / max( 1, $size ) ), 1 );
			case 'install':
				$done = isset( $d['remote_install']['progress'] ) ? (float) $d['remote_install']['progress'] : 0;
				return round( 89 + 10 * min( 1, $done / 100 ), 1 );
			default:
				return 100.0;
		}
		return round( min( 99.0, $p * $scale ), 1 );
	}

	/**
	 * Réponse d'état pour l'interface.
	 *
	 * @param Job $job Tâche.
	 * @return array
	 */
	public static function status( Job $job ) {
		$d      = $job->data;
		$labels = array(
			'scan'     => __( 'Inventaire des fichiers', 'lwb-migration' ),
			'db'       => __( 'Export de la base de données', 'lwb-migration' ),
			'archive'  => __( 'Création de l’archive', 'lwb-migration' ),
			'encrypt'  => __( 'Chiffrement de l’archive', 'lwb-migration' ),
			'finalize' => __( 'Génération de l’installeur', 'lwb-migration' ),
			'transfer' => __( 'Transfert vers le site de destination', 'lwb-migration' ),
			'remote'   => __( 'Préparation du site de destination', 'lwb-migration' ),
			'install'  => __( 'Installation sur le nouveau serveur', 'lwb-migration' ),
			'done'     => __( 'Terminé', 'lwb-migration' ),
		);
		$detail = '';
		switch ( $d['phase'] ) {
			case 'scan':
				/* translators: 1: nombre de fichiers, 2: taille. */
				$detail = sprintf( __( '%1$s fichiers trouvés (%2$s)', 'lwb-migration' ), number_format_i18n( $d['scanner']['files'] ), Runtime::format_bytes( $d['scanner']['bytes'] ) );
				break;
			case 'db':
				$i      = (int) $d['dumper']['index'];
				$tables = $d['dumper']['tables'];
				/* translators: 1: table, 2: rang, 3: total, 4: lignes. */
				$detail = sprintf( __( 'Table %1$s (%2$d/%3$d) – %4$s lignes exportées', 'lwb-migration' ), isset( $tables[ $i ] ) ? $tables[ $i ] : '', min( $i + 1, count( $tables ) ), count( $tables ), number_format_i18n( $d['dumper']['rows_done'] ) );
				break;
			case 'archive':
				/* translators: 1: fichiers traités, 2: total, 3: octets. */
				$detail = sprintf( __( '%1$s / %2$s fichiers archivés (%3$s)', 'lwb-migration' ), number_format_i18n( $d['files_done'] ), number_format_i18n( $d['scanner']['files'] ), Runtime::format_bytes( isset( $d['archiver']['bytes_in'] ) ? $d['archiver']['bytes_in'] : 0 ) );
				break;
			case 'transfer':
				$detail = isset( $d['direct']['mode'] ) && 'pull' === $d['direct']['mode']
					/* translators: 1: reçu, 2: total. */
					? sprintf( __( '%1$s / %2$s téléchargés par la destination', 'lwb-migration' ), Runtime::format_bytes( $d['transfer']['offset'] ), Runtime::format_bytes( $d['transfer']['size'] ) )
					/* translators: 1: envoyé, 2: total. */
					: sprintf( __( '%1$s / %2$s envoyés', 'lwb-migration' ), Runtime::format_bytes( $d['transfer']['offset'] ), Runtime::format_bytes( $d['transfer']['size'] ) );
				break;
			case 'install':
				$detail = isset( $d['remote_install']['detail'] ) ? (string) $d['remote_install']['detail'] : '';
				break;
		}
		$out = array(
			'status'   => 'done' === $d['phase'] ? 'done' : 'running',
			'phase'    => $d['phase'],
			'label'    => isset( $labels[ $d['phase'] ] ) ? $labels[ $d['phase'] ] : $d['phase'],
			'detail'   => $detail,
			'progress' => self::progress( $job ),
			'log'      => $job->logger()->tail( 6 ),
			'warnings' => $d['warnings'],
		);
		if ( 'done' === $d['phase'] && ! empty( $d['remote_result'] ) ) {
			$out['installer_url'] = $d['remote_result']['installer_url'];
			if ( ! empty( $d['remote_result']['installed'] ) ) {
				// Installé avant le changement de DNS : il reste à faire pointer le domaine.
				$route            = isset( $d['direct']['route'] ) ? $d['direct']['route'] : null;
				$site             = ! empty( $d['direct']['final_url'] ) ? $d['direct']['final_url'] : $d['direct']['site_url'];
				$out['installed'] = true;
				$out['site_url']  = $site;
				$out['hosts']     = Route::hosts_line( $route, $site );
			}
		}
		return $out;
	}

	/**
	 * Liste des paquets (plus récents d'abord).
	 *
	 * @return array[]
	 */
	public static function list_packages() {
		$dir   = Security::storage_dir( 'packages' );
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$out   = array();
		foreach ( (array) $items as $item ) {
			if ( ! Security::valid_id( $item ) ) {
				continue;
			}
			$meta = Json_Store::read( $dir . '/' . $item . '/package.php' );
			if ( null === $meta || ! is_file( $dir . '/' . $item . '/' . $meta['archive'] ) ) {
				continue;
			}
			$meta['has_log'] = is_file( $dir . '/' . $item . '/log.php' );
			$out[]           = $meta;
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $out;
	}

	/**
	 * Supprime un paquet.
	 *
	 * @param string $id Identifiant.
	 * @return bool
	 */
	public static function delete_package( $id ) {
		if ( ! Security::valid_id( $id ) ) {
			return false;
		}
		$dir = Security::storage_dir( 'packages' ) . '/' . $id;
		return is_dir( $dir ) && Json_Store::delete_dir( $dir );
	}

	/**
	 * Chemin d'un fichier de paquet à télécharger.
	 *
	 * @param string $id   Identifiant.
	 * @param string $file archive|installer|log.
	 * @return array|null [chemin, nom de téléchargement]
	 */
	public static function package_file( $id, $file ) {
		if ( ! Security::valid_id( $id ) ) {
			return null;
		}
		$dir  = Security::storage_dir( 'packages' ) . '/' . $id;
		$meta = Json_Store::read( $dir . '/package.php' );
		if ( null === $meta ) {
			return null;
		}
		switch ( $file ) {
			case 'archive':
				return array( $dir . '/' . basename( $meta['archive'] ), basename( $meta['archive'] ) );
			case 'installer':
				return array( $dir . '/installer.dat', 'installer.php' );
			case 'log':
				return array( $dir . '/log.php', 'lwb-migration-log-' . gmdate( 'Ymd-His', $meta['created'] ) . '.txt' );
		}
		return null;
	}

	/**
	 * Mot de passe d'installeur aléatoire (16 caractères sans ambiguïté de lecture).
	 *
	 * @return string
	 */
	public static function generate_password() {
		$chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$max   = strlen( $chars ) - 1;
		$out   = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}
}
