<?php
/**
 * Création d'archives par lots, reprenable après interruption.
 *
 * Deux formats :
 * - « zip » : ZIP standard (ZIP64 si nécessaire), écrit en flux en PHP pur. Il est
 *   lisible par n'importe quel outil (ZipArchive, unzip, Windows…). On n'utilise pas
 *   ZipArchive pour l'écriture car il réécrit l'archive entière à chaque close() :
 *   inutilisable en traitement par lots sur un gros site avec max_execution_time = 30.
 * - « lwb » : format de repli maison (fichiers concaténés + index JSON ligne par ligne),
 *   sans compression, utilisé quand ni l'extension zip ni zlib ne sont disponibles,
 *   ou sur demande dans les réglages.
 *
 * Reprise : l'état (octets écrits, index, fichier en cours) est sauvegardé à la fin de
 * chaque étape. Au redémarrage, l'archive est tronquée au dernier point de contrôle.
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Écrivain d'archive.
 */
class Archiver {

	const FORMAT_ZIP = 'zip';
	const FORMAT_LWB = 'lwb';

	const LWB_MAGIC   = 'LWBARC01';
	const LWB_TRAILER = 'LWBEND01';

	const READ_CHUNK = 1048576;
	const BIG_FILE   = 8388608;

	const STATUS_DONE    = 1;
	const STATUS_PARTIAL = 2;
	const STATUS_SKIPPED = 3;

	const MAX32 = 0xFFFFFFFF;

	/**
	 * Extensions déjà compressées : stockées sans recompression.
	 *
	 * @var string[]
	 */
	private static $stored_extensions = array(
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'avif',
		'heic',
		'jxl',
		'zip',
		'gz',
		'tgz',
		'bz2',
		'xz',
		'7z',
		'rar',
		'mp3',
		'mp4',
		'm4a',
		'm4v',
		'mov',
		'avi',
		'mkv',
		'webm',
		'ogg',
		'ogv',
		'woff',
		'woff2',
		'wpress',
		'jpa',
		'daf',
	);

	/**
	 * Chemin de l'archive.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Chemin du fichier d'index temporaire (répertoire central / index JSON).
	 *
	 * @var string
	 */
	private $index_path;

	/**
	 * Format.
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Compression activée (format zip).
	 *
	 * @var bool
	 */
	private $compress;

	/**
	 * Descripteur de l'archive.
	 *
	 * @var resource|null
	 */
	private $fh;

	/**
	 * Descripteur de l'index.
	 *
	 * @var resource|null
	 */
	private $ih;

	/**
	 * État persistant.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Avertissements de l'étape en cours.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructeur.
	 *
	 * @param string $path       Archive.
	 * @param string $index_path Fichier d'index temporaire.
	 * @param string $format     Format (zip|lwb).
	 * @param array  $state      État sauvegardé (reprise).
	 * @param bool   $compress   Compression deflate (zip).
	 */
	public function __construct( $path, $index_path, $format, array $state = array(), $compress = true ) {
		$this->path       = $path;
		$this->index_path = $index_path;
		$this->format     = self::FORMAT_LWB === $format ? self::FORMAT_LWB : self::FORMAT_ZIP;
		$this->compress   = $compress && self::FORMAT_ZIP === $this->format && self::deflate_available();
		$this->state      = array_merge(
			array(
				'initialized'  => false,
				'offset'       => 0,
				'index_offset' => 0,
				'count'        => 0,
				'bytes_in'     => 0,
				'current'      => null,
				'finalized'    => false,
			),
			$state
		);
	}

	/**
	 * Choisit le format selon l'environnement.
	 *
	 * @param string $preference auto|zip|lwb.
	 * @return string
	 */
	public static function choose_format( $preference = 'auto' ) {
		if ( self::FORMAT_ZIP === $preference || self::FORMAT_LWB === $preference ) {
			return $preference;
		}
		return ( class_exists( '\ZipArchive' ) || self::deflate_available() ) ? self::FORMAT_ZIP : self::FORMAT_LWB;
	}

	/**
	 * La compression deflate est-elle disponible ?
	 *
	 * @return bool
	 */
	public static function deflate_available() {
		return function_exists( 'deflate_init' ) || function_exists( 'gzdeflate' );
	}

	/**
	 * Ouvre l'archive et revient au dernier point de contrôle.
	 *
	 * @return void
	 * @throws Migration_Exception En cas d'erreur d'accès.
	 */
	public function open() {
		$this->fh = $this->open_at( $this->path, (int) $this->state['offset'] );
		$this->ih = $this->open_at( $this->index_path, (int) $this->state['index_offset'] );
		if ( ! $this->state['initialized'] ) {
			if ( self::FORMAT_LWB === $this->format ) {
				$this->write( self::LWB_MAGIC );
			}
			$this->state['initialized'] = true;
		}
	}

	/**
	 * Ouvre un fichier en lecture/écriture et le tronque à l'offset donné.
	 *
	 * @param string $file   Fichier.
	 * @param int    $offset Offset du point de contrôle.
	 * @return resource
	 * @throws Migration_Exception En cas d'erreur.
	 */
	private function open_at( $file, $offset ) {
		$h = @fopen( $file, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			throw new Migration_Exception(
				sprintf( 'Impossible d’ouvrir %s en écriture.', basename( $file ) ),
				'Vérifiez les permissions du dossier de stockage et l’espace disque disponible.'
			);
		}
		$stat = fstat( $h );
		if ( $stat['size'] < $offset ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Migration_Exception(
				sprintf( 'Le fichier %s est plus court que prévu : il a été modifié ou supprimé pendant l’export.', basename( $file ) ),
				'Supprimez cet export et relancez-le.'
			);
		}
		if ( $stat['size'] > $offset ) {
			ftruncate( $h, $offset );
		}
		fseek( $h, $offset );
		return $h;
	}

	/**
	 * Enregistre la position courante (à appeler avant de sauvegarder l'état).
	 *
	 * @return array État.
	 */
	public function checkpoint() {
		if ( $this->fh ) {
			fflush( $this->fh );
			$this->state['offset'] = ftell( $this->fh );
		}
		if ( $this->ih ) {
			fflush( $this->ih );
			$this->state['index_offset'] = ftell( $this->ih );
		}
		return $this->state;
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
	 * Avertissements collectés depuis le dernier appel.
	 *
	 * @return string[]
	 */
	public function take_warnings() {
		$w              = $this->warnings;
		$this->warnings = array();
		return $w;
	}

	/**
	 * Un gros fichier est-il en cours d'écriture ?
	 *
	 * @return bool
	 */
	public function has_pending() {
		return ! empty( $this->state['current'] );
	}

	/**
	 * Ferme les descripteurs.
	 *
	 * @return void
	 */
	public function close() {
		$this->checkpoint();
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
		}
		if ( $this->ih ) {
			fclose( $this->ih ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->ih = null;
		}
	}

	/**
	 * Ajoute un dossier (préserve les dossiers vides).
	 *
	 * @param string $name  Nom dans l'archive.
	 * @param int    $mtime Date de modification.
	 * @return int Statut.
	 */
	public function add_dir( $name, $mtime = 0 ) {
		$name   = rtrim( $name, '/' ) . '/';
		$offset = $this->tell();
		if ( self::FORMAT_ZIP === $this->format ) {
			$this->write( $this->local_header( $name, 0, 0, 0, 0, $mtime ) );
		}
		$this->add_index_entry( $name, 0, 0, 0, 0, $offset, $mtime, true );
		return self::STATUS_DONE;
	}

	/**
	 * Ajoute une entrée depuis une chaîne en mémoire (petits contenus).
	 *
	 * @param string $name Nom dans l'archive.
	 * @param string $data Contenu.
	 * @return int Statut.
	 */
	public function add_string( $name, $data ) {
		$crc    = self::crc_of( $data );
		$offset = $this->tell();
		$size   = strlen( $data );
		$method = 0;
		$out    = $data;
		if ( $this->compress && $size > 64 ) {
			$out    = self::deflate_string( $data );
			$method = 8;
		}
		if ( self::FORMAT_ZIP === $this->format ) {
			$this->write( $this->local_header( $name, $method, $crc, strlen( $out ), $size, time() ) );
		}
		$data_offset = $this->tell();
		$this->write( $out );
		$this->add_index_entry( $name, $method, $crc, strlen( $out ), $size, self::FORMAT_ZIP === $this->format ? $offset : $data_offset, time(), false );
		return self::STATUS_DONE;
	}

	/**
	 * Ajoute un fichier. Les gros fichiers sont copiés sur plusieurs étapes.
	 *
	 * @param string  $src  Fichier source.
	 * @param string  $name Nom dans l'archive.
	 * @param Runtime $rt   Budget de l'étape.
	 * @return int STATUS_DONE, STATUS_PARTIAL (à rappeler avec le même fichier) ou STATUS_SKIPPED.
	 * @throws Migration_Exception En cas d'incohérence.
	 */
	public function add_file( $src, $name, Runtime $rt ) {
		if ( ! empty( $this->state['current'] ) ) {
			if ( $this->state['current']['name'] !== $name ) {
				throw new Migration_Exception( 'Reprise incohérente de l’archive.', 'Supprimez cet export et relancez-le.' );
			}
			return $this->continue_big( $rt );
		}
		if ( ! is_file( $src ) || ! is_readable( $src ) ) {
			$this->warnings[] = sprintf( 'Fichier illisible ignoré : %s', $name );
			return self::STATUS_SKIPPED;
		}
		$size  = (int) @filesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mtime = (int) @filemtime( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size > self::BIG_FILE ) {
			return $this->start_big( $src, $name, $size, $mtime, $rt );
		}
		return $this->add_small( $src, $name, $mtime );
	}

	/**
	 * Ajoute un petit fichier en une fois (compressé si utile).
	 *
	 * @param string $src   Source.
	 * @param string $name  Nom.
	 * @param int    $mtime Date.
	 * @return int
	 */
	private function add_small( $src, $name, $mtime ) {
		$h = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			$this->warnings[] = sprintf( 'Fichier illisible ignoré : %s', $name );
			return self::STATUS_SKIPPED;
		}
		$method = ( $this->compress && self::is_compressible( $name ) ) ? 8 : 0;
		$header = $this->tell();
		if ( self::FORMAT_ZIP === $this->format ) {
			$this->write( $this->local_header( $name, $method, 0, 0, 0, $mtime ) );
		}
		$data_offset = $this->tell();
		$crc         = hash_init( 'crc32b' );
		$read        = 0;
		$written     = 0;
		$stream      = ( 8 === $method && function_exists( 'deflate_init' ) ) ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;
		$buffer      = '';
		while ( ! feof( $h ) ) {
			$buf = fread( $h, self::READ_CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$read += strlen( $buf );
			hash_update( $crc, $buf );
			if ( 0 === $method ) {
				$written += $this->write( $buf );
			} elseif ( $stream ) {
				$written += $this->write( deflate_add( $stream, $buf, ZLIB_NO_FLUSH ) );
			} else {
				$buffer .= $buf;
			}
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( 8 === $method ) {
			$written += $this->write( $stream ? deflate_add( $stream, '', ZLIB_FINISH ) : self::deflate_string( $buffer ) );
		}
		$crc_value = self::hash_to_int( hash_final( $crc, true ) );
		if ( self::FORMAT_ZIP === $this->format ) {
			$end = $this->tell();
			fseek( $this->fh, $header + 14 );
			$this->write( pack( 'VVV', $crc_value, $written, $read ) );
			fseek( $this->fh, $end );
		}
		$this->add_index_entry( $name, $method, $crc_value, $written, $read, self::FORMAT_ZIP === $this->format ? $header : $data_offset, $mtime, false );
		$this->state['bytes_in'] += $read;
		return self::STATUS_DONE;
	}

	/**
	 * Démarre l'écriture d'un gros fichier (stocké sans compression).
	 *
	 * @param string  $src   Source.
	 * @param string  $name  Nom.
	 * @param int     $size  Taille déclarée.
	 * @param int     $mtime Date.
	 * @param Runtime $rt    Budget.
	 * @return int
	 */
	private function start_big( $src, $name, $size, $mtime, Runtime $rt ) {
		$header = $this->tell();
		if ( self::FORMAT_ZIP === $this->format ) {
			$this->write( $this->local_header( $name, 0, 0, $size, $size, $mtime ) );
		}
		$this->state['current'] = array(
			'name'        => $name,
			'src'         => $src,
			'size'        => $size,
			'mtime'       => $mtime,
			'read'        => 0,
			'crc'         => 0,
			'header'      => $header,
			'data_offset' => $this->tell(),
		);
		return $this->continue_big( $rt );
	}

	/**
	 * Poursuit la copie d'un gros fichier.
	 *
	 * @param Runtime $rt Budget.
	 * @return int
	 */
	private function continue_big( Runtime $rt ) {
		$cur = $this->state['current'];
		$h   = @fopen( $cur['src'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $h && $cur['read'] > 0 ) {
			fseek( $h, $cur['read'] );
		}
		$crc    = hash_init( 'crc32b' );
		$len    = 0;
		$padded = false;
		while ( $cur['read'] < $cur['size'] ) {
			$want = (int) min( self::READ_CHUNK, $cur['size'] - $cur['read'] );
			$buf  = $h ? fread( $h, $want ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buf || '' === $buf ) {
				// Fichier raccourci pendant l'export : on complète pour garder une archive cohérente.
				$buf    = str_repeat( "\0", $want );
				$padded = true;
			}
			hash_update( $crc, $buf );
			$n            = strlen( $buf );
			$cur['read'] += $n;
			$len         += $n;
			$this->write( $buf );
			if ( $rt->should_stop() ) {
				break;
			}
		}
		if ( $h ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $padded ) {
			$this->warnings[] = sprintf( 'Le fichier %s a changé pendant l’export (complété par des zéros).', $cur['name'] );
		}
		$cur['crc']               = Archive_Reader::crc32_combine( $cur['crc'], self::hash_to_int( hash_final( $crc, true ) ), $len );
		$this->state['bytes_in'] += $len;
		if ( $cur['read'] < $cur['size'] ) {
			$this->state['current'] = $cur;
			return self::STATUS_PARTIAL;
		}
		if ( self::FORMAT_ZIP === $this->format ) {
			$end = $this->tell();
			fseek( $this->fh, $cur['header'] + 14 );
			$this->write( pack( 'V', $cur['crc'] ) );
			fseek( $this->fh, $end );
		}
		$this->add_index_entry(
			$cur['name'],
			0,
			$cur['crc'],
			$cur['size'],
			$cur['size'],
			self::FORMAT_ZIP === $this->format ? $cur['header'] : $cur['data_offset'],
			$cur['mtime'],
			false
		);
		$this->state['current'] = null;
		return self::STATUS_DONE;
	}

	/**
	 * Termine l'archive (répertoire central ou index + bloc final).
	 *
	 * @return void
	 * @throws Migration_Exception Si un fichier est encore en cours.
	 */
	public function finalize() {
		if ( $this->has_pending() ) {
			throw new Migration_Exception( 'Archive incomplète : un fichier est encore en cours de copie.', 'Relancez l’export.' );
		}
		if ( $this->state['finalized'] ) {
			return;
		}
		fflush( $this->ih );
		$index_size = ftell( $this->ih );
		$start      = $this->tell();
		$src        = @fopen( $this->index_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $src ) {
			throw new Migration_Exception( 'Index de l’archive illisible.', 'Relancez l’export.' );
		}
		$copied = 0;
		while ( $copied < $index_size ) {
			$buf = fread( $src, (int) min( self::READ_CHUNK, $index_size - $copied ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$copied += strlen( $buf );
			$this->write( $buf );
		}
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$count = (int) $this->state['count'];
		if ( self::FORMAT_LWB === $this->format ) {
			$this->write( self::LWB_TRAILER . pack( 'P', $start ) . pack( 'P', $index_size ) . pack( 'P', $count ) );
		} else {
			$need64 = $count >= 0xFFFF || $start >= self::MAX32 || $index_size >= self::MAX32;
			if ( $need64 ) {
				$z64 = $this->tell();
				$this->write(
					pack( 'V', 0x06064b50 ) . pack( 'P', 44 ) . pack( 'vvVV', 45, 45, 0, 0 )
					. pack( 'P', $count ) . pack( 'P', $count ) . pack( 'P', $index_size ) . pack( 'P', $start )
				);
				$this->write( pack( 'VV', 0x07064b50, 0 ) . pack( 'P', $z64 ) . pack( 'V', 1 ) );
			}
			$this->write(
				pack(
					'VvvvvVVv',
					0x06054b50,
					0,
					0,
					min( $count, 0xFFFF ),
					min( $count, 0xFFFF ),
					min( $index_size, self::MAX32 ),
					min( $start, self::MAX32 ),
					0
				)
			);
		}
		$this->state['finalized'] = true;
		$this->checkpoint();
	}

	/**
	 * Position courante dans l'archive.
	 *
	 * @return int
	 */
	private function tell() {
		return (int) ftell( $this->fh );
	}

	/**
	 * Écrit dans l'archive en gérant les écritures partielles.
	 *
	 * @param string $data Données.
	 * @return int Octets écrits.
	 * @throws Migration_Exception Disque plein.
	 */
	private function write( $data ) {
		return self::write_all( $this->fh, $data );
	}

	/**
	 * Écrit intégralement une chaîne.
	 *
	 * @param resource $h    Descripteur.
	 * @param string   $data Données.
	 * @return int
	 * @throws Migration_Exception Disque plein.
	 */
	private static function write_all( $h, $data ) {
		$len   = strlen( $data );
		$total = 0;
		while ( $total < $len ) {
			$n = fwrite( $h, 0 === $total ? $data : substr( $data, $total ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				throw new Migration_Exception(
					'Écriture de l’archive impossible : l’espace disque est probablement saturé.',
					'Libérez de l’espace (anciens exports, sauvegardes) ou excluez les gros dossiers dans les Paramètres.',
					true
				);
			}
			$total += $n;
		}
		return $total;
	}

	/**
	 * Construit un en-tête local ZIP.
	 *
	 * @param string $name   Nom.
	 * @param int    $method Méthode (0 ou 8).
	 * @param int    $crc    CRC32.
	 * @param int    $csize  Taille compressée.
	 * @param int    $usize  Taille réelle.
	 * @param int    $mtime  Date.
	 * @return string
	 */
	private function local_header( $name, $method, $crc, $csize, $usize, $mtime ) {
		list( $time, $date ) = self::dos_time( $mtime );
		$extra               = '';
		$version             = 20;
		if ( $usize >= self::MAX32 || $csize >= self::MAX32 ) {
			$extra   = pack( 'vv', 0x0001, 16 ) . pack( 'P', $usize ) . pack( 'P', $csize );
			$csize   = self::MAX32;
			$usize   = self::MAX32;
			$version = 45;
		}
		return pack( 'VvvvvvVVVvv', 0x04034b50, $version, 0x0800, $method, $time, $date, $crc, $csize, $usize, strlen( $name ), strlen( $extra ) )
			. $name . $extra;
	}

	/**
	 * Ajoute une entrée à l'index (répertoire central ZIP ou ligne JSON LWB).
	 *
	 * @param string $name   Nom.
	 * @param int    $method Méthode.
	 * @param int    $crc    CRC32.
	 * @param int    $csize  Taille compressée.
	 * @param int    $usize  Taille réelle.
	 * @param int    $offset Offset de l'en-tête local (zip) ou des données (lwb).
	 * @param int    $mtime  Date.
	 * @param bool   $is_dir Dossier.
	 * @return void
	 */
	private function add_index_entry( $name, $method, $crc, $csize, $usize, $offset, $mtime, $is_dir ) {
		++$this->state['count'];
		if ( self::FORMAT_LWB === $this->format ) {
			$line = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'n' => $name,
					'o' => $offset,
					's' => $usize,
					'c' => $crc,
					'm' => $mtime,
					't' => $is_dir ? 'd' : 'f',
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			);
			self::write_all( $this->ih, $line . "\n" );
			return;
		}
		list( $time, $date ) = self::dos_time( $mtime );
		$extra               = '';
		if ( $usize >= self::MAX32 ) {
			$extra .= pack( 'P', $usize );
			$usize  = self::MAX32;
		}
		if ( $csize >= self::MAX32 ) {
			$extra .= pack( 'P', $csize );
			$csize  = self::MAX32;
		}
		if ( $offset >= self::MAX32 ) {
			$extra .= pack( 'P', $offset );
			$offset = self::MAX32;
		}
		$version = 20;
		if ( '' !== $extra ) {
			$extra   = pack( 'vv', 0x0001, strlen( $extra ) ) . $extra;
			$version = 45;
		}
		$attr = $is_dir ? ( ( 040755 << 16 ) | 0x10 ) : ( 0100644 << 16 );
		self::write_all(
			$this->ih,
			pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | $version, $version, 0x0800, $method, $time, $date, $crc, $csize, $usize, strlen( $name ), strlen( $extra ), 0, 0, 0, $attr, $offset )
			. $name . $extra
		);
	}

	/**
	 * Convertit un timestamp en date/heure DOS.
	 *
	 * @param int $ts Timestamp.
	 * @return int[] [heure, date]
	 */
	private static function dos_time( $ts ) {
		$ts = $ts > 315532800 ? (int) $ts : 315532800;
		$y  = (int) gmdate( 'Y', $ts );
		if ( $y > 2107 ) {
			$ts = 4354819199;
			$y  = 2107;
		}
		$time = ( (int) gmdate( 'G', $ts ) << 11 ) | ( (int) gmdate( 'i', $ts ) << 5 ) | ( (int) gmdate( 's', $ts ) >> 1 );
		$date = ( ( $y - 1980 ) << 9 ) | ( (int) gmdate( 'n', $ts ) << 5 ) | (int) gmdate( 'j', $ts );
		return array( $time, $date );
	}

	/**
	 * Le fichier mérite-t-il d'être compressé ?
	 *
	 * @param string $name Nom.
	 * @return bool
	 */
	private static function is_compressible( $name ) {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		return ! in_array( $ext, self::$stored_extensions, true );
	}

	/**
	 * Compresse une chaîne en deflate brut.
	 *
	 * @param string $data Données.
	 * @return string
	 */
	private static function deflate_string( $data ) {
		if ( function_exists( 'gzdeflate' ) ) {
			return (string) gzdeflate( $data, 6 );
		}
		$z = deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) );
		return deflate_add( $z, $data, ZLIB_FINISH );
	}

	/**
	 * CRC32 d'une chaîne (entier non signé).
	 *
	 * @param string $data Données.
	 * @return int
	 */
	private static function crc_of( $data ) {
		return self::hash_to_int( hash( 'crc32b', $data, true ) );
	}

	/**
	 * Convertit une empreinte crc32b binaire en entier non signé.
	 *
	 * @param string $raw 4 octets big-endian.
	 * @return int
	 */
	private static function hash_to_int( $raw ) {
		$v = unpack( 'N', $raw );
		return (int) $v[1] & 0xFFFFFFFF;
	}
}
