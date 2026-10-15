<?php
/**
 * Import SQL en flux, instruction par instruction. Reprenable (offset en octets).
 *
 * Gère le changement de préfixe de tables et les collations absentes du serveur de
 * destination (utf8mb4_0900_ai_ci de MySQL 8 sur MariaDB/MySQL 5.x, etc.).
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Importeur SQL.
 */
class Sql_Importer {

	/**
	 * Erreurs fatales (connexion perdue, paquet trop gros).
	 *
	 * @var int[]
	 */
	private static $fatal_errors = array( 1153, 2006, 2013 );

	/**
	 * Connexion.
	 *
	 * @var Db
	 */
	private $db;

	/**
	 * Fichier SQL.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * État.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Journal.
	 *
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Rappel de sauvegarde de l'état (appelé avant d'exécuter des requêtes).
	 *
	 * @var callable|null
	 */
	private $persist;

	/**
	 * Valeur de max_allowed_packet du serveur (octets).
	 *
	 * @var int
	 */
	private $max_packet = 0;

	/**
	 * Version du serveur.
	 *
	 * @var string
	 */
	private $version = '';

	/**
	 * Constructeur.
	 *
	 * @param Db            $db      Connexion.
	 * @param string        $file    Fichier SQL.
	 * @param array         $state   État (offset, old_prefix, new_prefix, collations…).
	 * @param Logger|null   $logger  Journal.
	 * @param callable|null $persist Sauvegarde immédiate de l'état (reçoit l'état). Indispensable
	 *                               pour une reprise sûre après un plantage en cours d'étape.
	 */
	public function __construct( Db $db, $file, array $state = array(), $logger = null, $persist = null ) {
		$this->persist = $persist;
		$this->db      = $db;
		$this->file    = $file;
		$this->logger  = $logger;
		$this->state   = array_merge(
			array(
				'offset'       => 0,
				'size'         => (int) @filesize( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'queries'      => 0,
				'errors'       => 0,
				'old_prefix'   => '',
				'new_prefix'   => '',
				'collations'   => null,
				'tables'       => array(),
				'table_offset' => 0,
				'dirty'        => false,
				'done'         => false,
				// Conversion MyISAM/Aria → InnoDB demandée.
				'innodb'       => false,
				// Adaptations faites : tables converties, clés primaires ajoutées, index raccourcis.
				'converted'    => array(),
				'fixes'        => array(),
				'split'        => 0,
			),
			$state
		);
	}

	/**
	 * État courant.
	 *
	 * @return array
	 */
	public function get_state() {
		return $this->state;
	}

	/**
	 * Progression (0..1).
	 *
	 * @return float
	 */
	public function progress() {
		return $this->state['done'] ? 1.0 : min( 0.99, $this->state['offset'] / max( 1, $this->state['size'] ) );
	}

	/**
	 * Prépare la session SQL.
	 *
	 * @return void
	 */
	private function prepare_session() {
		$this->db->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		$this->db->query( 'SET SESSION foreign_key_checks = 0' );
		$this->db->query( 'SET SESSION unique_checks = 0' );
		$this->db->query( "SET SESSION time_zone = '+00:00'" );
		// Taille réelle autorisée par le serveur : les INSERT plus gros sont découpés.
		$packet           = (int) $this->db->get_var( 'SELECT @@max_allowed_packet' );
		$this->max_packet = $packet > 0 ? $packet : 1048576;
		$this->version    = (string) $this->db->get_var( 'SELECT VERSION()' );
		if ( null === $this->state['collations'] ) {
			$this->state['collations'] = $this->detect_collation_map();
		}
	}

	/**
	 * Remplacements de collations non prises en charge par le serveur.
	 *
	 * @return array<string,string> Motif regex => collation de repli.
	 */
	private function detect_collation_map() {
		$available = array();
		foreach ( (array) $this->db->get_rows( "SHOW COLLATION WHERE Charset IN ('utf8mb4','utf8','utf8mb3')", true ) as $row ) {
			$available[ strtolower( $row['Collation'] ) ] = true;
		}
		$fallback = isset( $available['utf8mb4_unicode_520_ci'] ) ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
		$map      = array();
		if ( ! isset( $available['utf8mb4_0900_ai_ci'] ) ) {
			$map['/utf8mb4_0900_[a-z_]+/'] = $fallback;
		}
		if ( ! isset( $available['utf8mb4_uca1400_ai_ci'] ) ) {
			$map['/utf8mb4_uca1400_[a-z_]+/'] = $fallback;
			$map['/uca1400_[a-z_]+/']         = $fallback;
		}
		if ( ! isset( $available['utf8mb3_general_ci'] ) ) {
			$map['/utf8mb3_/'] = 'utf8_';
			$map['/utf8mb3/']  = 'utf8';
		}
		return $map;
	}

	/**
	 * Exécute une étape d'import.
	 *
	 * @param Runtime $rt Budget.
	 * @return bool true si l'import est terminé.
	 * @throws Migration_Exception Erreur bloquante.
	 */
	public function run( Runtime $rt ) {
		if ( $this->state['done'] ) {
			return true;
		}
		$h = @fopen( $this->file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			throw new Migration_Exception( 'Fichier SQL introuvable.', 'Relancez l’étape d’extraction.' );
		}
		$this->prepare_session();
		if ( $this->state['dirty'] ) {
			// L'étape précédente s'est interrompue en cours de route : certaines requêtes
			// postérieures au dernier point de contrôle ont peut-être été exécutées. On repart
			// du début de la table en cours (DROP + CREATE + INSERT), ce qui est idempotent.
			if ( $this->logger ) {
				$this->logger->warning( 'Reprise après interruption : réimport de la table en cours.' );
			}
			$this->state['offset'] = (int) $this->state['table_offset'];
		}
		$this->state['dirty'] = true;
		if ( $this->persist ) {
			call_user_func( $this->persist, $this->state );
		}
		fseek( $h, (int) $this->state['offset'] );
		$statement = '';
		try {
			while ( true ) {
				$line = fgets( $h );
				if ( false === $line ) {
					if ( '' !== trim( $statement ) ) {
						$this->execute( $statement );
					}
					$this->state['offset'] = ftell( $h );
					$this->state['done']   = true;
					$this->state['dirty']  = false;
					return true;
				}
				if ( '' === $statement ) {
					$trim = ltrim( $line );
					if ( '' === trim( $line ) || 0 === strpos( $trim, '-- ' ) || "--\n" === $trim || 0 === strpos( $trim, '#' ) ) {
						$this->state['offset'] = ftell( $h );
						continue;
					}
				}
				$statement .= $line;
				if ( ';' !== substr( rtrim( $line ), -1 ) ) {
					continue;
				}
				if ( 0 === strncasecmp( $statement, 'DROP TABLE', 10 ) ) {
					$this->state['table_offset'] = $this->state['offset'];
				}
				$this->execute( $statement );
				$statement             = '';
				$this->state['offset'] = ftell( $h );
				if ( $rt->should_stop() ) {
					$this->state['dirty'] = false;
					return false;
				}
			}
		} finally {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Exécute une instruction après transformation.
	 *
	 * @param string $sql Instruction.
	 * @return void
	 * @throws Migration_Exception Erreur fatale.
	 */
	private function execute( $sql ) {
		$sql = $this->transform( rtrim( $sql ) );
		if ( '' === $sql ) {
			return;
		}
		++$this->state['queries'];
		$ok = Sql_Compat::is_create_table( $sql ) ? $this->create_table( $sql ) : $this->run_statement( $sql );
		if ( $ok ) {
			return;
		}
		$errno = $this->db->last_errno();
		$error = $this->db->last_error();
		if ( in_array( $errno, self::$fatal_errors, true ) || false !== stripos( $error, 'max_allowed_packet' ) || false !== stripos( $error, 'gone away' ) ) {
			throw new Migration_Exception(
				sprintf( 'Le serveur MySQL a refusé une requête trop volumineuse (%d : %s).', $errno, $error ),
				'Demandez à votre hébergeur d’augmenter max_allowed_packet (16M minimum), puis relancez : l’import reprendra là où il s’est arrêté.',
				true
			);
		}
		++$this->state['errors'];
		if ( $this->logger ) {
			$this->logger->error( sprintf( 'Requête SQL en échec (%d : %s) : %s', $errno, $error, substr( $sql, 0, 300 ) ) );
		}
	}

	/**
	 * Exécute une instruction, découpée si elle dépasse max_allowed_packet.
	 *
	 * @param string $sql Instruction.
	 * @return bool
	 */
	private function run_statement( $sql ) {
		$limit = $this->max_packet - 1024;
		if ( $limit > 0 && strlen( $sql ) > $limit ) {
			$pieces = Sql_Compat::split_insert( $sql, $limit );
			if ( count( $pieces ) > 1 ) {
				++$this->state['split'];
				foreach ( $pieces as $piece ) {
					if ( ! $this->db->query( $piece ) ) {
						return false;
					}
				}
				return true;
			}
		}
		return $this->db->query( $sql );
	}

	/**
	 * Crée une table en l'adaptant au serveur.
	 *
	 * Conversion InnoDB demandée : appliquée d'emblée (moteur d'origine conservé si InnoDB
	 * refuse la table). Puis, selon l'erreur du serveur : clé primaire ajoutée
	 * (sql_require_primary_key, innodb_force_primary_key), index raccourcis (limite de
	 * 767 octets), conversion InnoDB (moteur Aria ou MyISAM indisponible).
	 *
	 * @param string $sql CREATE TABLE.
	 * @return bool
	 */
	private function create_table( $sql ) {
		$table    = preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`((?:[^`]|``)+)`/i', $sql, $m ) ? str_replace( '``', '`', $m[1] ) : '';
		$original = $sql;
		$attempt  = $sql;
		$fixes    = array();
		$reverted = false;
		if ( ! empty( $this->state['innodb'] ) && Sql_Compat::is_myisam_like( $sql ) ) {
			$attempt = Sql_Compat::convert_engine( $sql );
			$fixes[] = 'innodb';
		}
		for ( $i = 0; $i < 6; $i++ ) {
			if ( $this->db->query( $attempt ) ) {
				$this->record_fixes( $table, array_unique( $fixes ) );
				return true;
			}
			$errno  = $this->db->last_errno();
			$limits = Sql_Compat::index_limits_from_error( $this->db->last_error(), $attempt );
			$next   = null;
			$fix    = '';
			if ( in_array( $errno, array( 3750, 1173 ), true ) && ! Sql_Compat::has_primary_key( $attempt ) ) {
				$next = Sql_Compat::add_primary_key( $attempt, Sql_Compat::supports_invisible( $this->version ) );
				$fix  = 'pk';
			} elseif ( null !== $limits ) {
				$next = Sql_Compat::shorten_indexes( $attempt, $limits[0], $limits[1] );
				$fix  = 'index';
			} elseif ( ! $reverted && Sql_Compat::is_myisam_like( $attempt ) ) {
				$next = Sql_Compat::convert_engine( $attempt ); // Moteur indisponible ou options Aria refusées.
				$fix  = 'innodb';
			} elseif ( ! $reverted && in_array( 'innodb', $fixes, true ) ) {
				$next     = $original; // InnoDB refuse cette table : moteur d'origine.
				$fixes    = array();
				$reverted = true;
			}
			if ( null === $next || $next === $attempt ) {
				return false;
			}
			if ( '' !== $fix ) {
				$fixes[] = $fix;
			}
			$attempt = $next;
		}
		return false;
	}

	/**
	 * Mémorise (et journalise) les adaptations d'une table.
	 *
	 * @param string   $table Table.
	 * @param string[] $fixes Adaptations : innodb, pk, index.
	 * @return void
	 */
	private function record_fixes( $table, array $fixes ) {
		$labels = array(
			'pk'    => 'clé primaire ajoutée (le serveur l’exige)',
			'index' => 'index raccourcis (longueur maximale du serveur)',
		);
		foreach ( $fixes as $fix ) {
			if ( 'innodb' === $fix ) {
				if ( ! in_array( $table, $this->state['converted'], true ) ) {
					$this->state['converted'][] = $table;
				}
				continue;
			}
			$line = sprintf( 'Table %s : %s.', $table, $labels[ $fix ] );
			if ( ! in_array( $line, $this->state['fixes'], true ) ) {
				$this->state['fixes'][] = $line;
				if ( $this->logger ) {
					$this->logger->info( $line );
				}
			}
		}
	}

	/**
	 * Applique préfixe et collations.
	 *
	 * @param string $sql Instruction.
	 * @return string
	 */
	public function transform( $sql ) {
		$old = (string) $this->state['old_prefix'];
		$new = (string) $this->state['new_prefix'];
		if ( preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|REPLACE INTO|LOCK TABLES|ALTER TABLE)\s+`([^`]+)`/i', $sql, $m ) ) {
			$table = $m[2];
			if ( '' !== $old && $old !== $new && 0 === strpos( $table, $old ) ) {
				$renamed = $new . substr( $table, strlen( $old ) );
				$sql     = substr_replace( $sql, '`' . str_replace( '`', '``', $renamed ) . '`', strpos( $sql, '`' ), strlen( $table ) + 2 );
				$table   = $renamed;
			}
			if ( 0 === stripos( $m[1], 'CREATE' ) ) {
				if ( ! in_array( $table, $this->state['tables'], true ) ) {
					$this->state['tables'][] = $table;
				}
				if ( '' !== $old && $old !== $new ) {
					$sql = preg_replace_callback(
						'/REFERENCES `([^`]+)`/',
						function ( $r ) use ( $old, $new ) {
							return 0 === strpos( $r[1], $old ) ? 'REFERENCES `' . $new . substr( $r[1], strlen( $old ) ) . '`' : $r[0];
						},
						$sql
					);
				}
				foreach ( (array) $this->state['collations'] as $pattern => $replacement ) {
					$sql = preg_replace( $pattern, $replacement, $sql );
				}
			}
		}
		return $sql;
	}
}
