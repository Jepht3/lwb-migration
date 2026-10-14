<?php
/**
 * Lecture et extraction par lots des archives (ZIP standard ou format LWB).
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Lecteur d'archive en flux : jamais l'index complet en mémoire.
 */
class Archive_Reader {

	const READ_CHUNK = 1048576;

	/**
	 * Chemin.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Descripteur.
	 *
	 * @var resource
	 */
	private $fh;

	/**
	 * Format (zip|lwb).
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Début de l'index.
	 *
	 * @var int
	 */
	private $index_start = 0;

	/**
	 * Taille de l'index.
	 *
	 * @var int
	 */
	private $index_size = 0;

	/**
	 * Nombre d'entrées.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Ouvre une archive.
	 *
	 * @param string $path Chemin.
	 * @throws Migration_Exception Archive absente ou corrompue.
	 */
	public function __construct( $path ) {
		$this->path = $path;
		$this->fh   = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->fh ) {
			throw new Migration_Exception(
				sprintf( 'Archive introuvable ou illisible : %s', basename( $path ) ),
				'Déposez l’archive dans le même dossier que installer.php et vérifiez ses permissions (644).'
			);
		}
		$magic = (string) fread( $this->fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( 0 === strpos( $magic, "PK\x03\x04" ) || 0 === strpos( $magic, "PK\x05\x06" ) ) {
			$this->format = Archiver::FORMAT_ZIP;
			$this->load_zip_index();
		} elseif ( Archiver::LWB_MAGIC === $magic ) {
			$this->format = Archiver::FORMAT_LWB;
			$this->load_lwb_index();
		} else {
			throw new Migration_Exception( 'Format d’archive inconnu.', 'Vérifiez que le fichier a été transféré en mode binaire (FTP) et qu’il n’est pas tronqué.' );
		}
	}

	/**
	 * Ferme l'archive.
	 */
	public function __destruct() {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Format détecté.
	 *
	 * @return string
	 */
	public function format() {
		return $this->format;
	}

	/**
	 * Nombre d'entrées.
	 *
	 * @return int
	 */
	public function count() {
		return $this->count;
	}

	/**
	 * Taille de l'index (pour la progression).
	 *
	 * @return int
	 */
	public function index_size() {
		return $this->index_size;
	}

	/**
	 * Erreur d'archive tronquée.
	 *
	 * @return Migration_Exception
	 */
	private static function truncated() {
		return new Migration_Exception(
			'L’archive est incomplète ou corrompue.',
			'Retransférez l’archive en mode binaire (FTP) et vérifiez que sa taille est identique à celle du site source.'
		);
	}

	/**
	 * Lit le répertoire central d'un ZIP (ZIP64 compris).
	 *
	 * @return void
	 * @throws Migration_Exception Archive corrompue.
	 */
	private function load_zip_index() {
		$stat = fstat( $this->fh );
		$size = (int) $stat['size'];
		$read = (int) min( $size, 65557 );
		fseek( $this->fh, $size - $read );
		$tail = (string) fread( $this->fh, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$pos  = strrpos( $tail, "PK\x05\x06" );
		if ( false === $pos || strlen( $tail ) - $pos < 22 ) {
			throw self::truncated();
		}
		$eocd              = unpack( 'Vsig/vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset/vcomment', substr( $tail, $pos, 22 ) );
		$this->count       = (int) $eocd['entries'];
		$this->index_size  = (int) $eocd['cd_size'];
		$this->index_start = (int) $eocd['cd_offset'];
		$eocd_abs          = $size - $read + $pos;
		if ( 0xFFFF === $this->count || 0xFFFFFFFF === $this->index_size || 0xFFFFFFFF === $this->index_start ) {
			if ( $eocd_abs < 20 ) {
				throw self::truncated();
			}
			fseek( $this->fh, $eocd_abs - 20 );
			$loc = (string) fread( $this->fh, 20 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( "PK\x06\x07" !== substr( $loc, 0, 4 ) ) {
				throw self::truncated();
			}
			$z64_offset = self::u64( substr( $loc, 8, 8 ) );
			fseek( $this->fh, $z64_offset );
			$rec = (string) fread( $this->fh, 56 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( "PK\x06\x06" !== substr( $rec, 0, 4 ) ) {
				throw self::truncated();
			}
			$this->count       = self::u64( substr( $rec, 32, 8 ) );
			$this->index_size  = self::u64( substr( $rec, 40, 8 ) );
			$this->index_start = self::u64( substr( $rec, 48, 8 ) );
		}
		if ( $this->index_start + $this->index_size > $size ) {
			throw self::truncated();
		}
	}

	/**
	 * Lit le bloc final d'une archive LWB.
	 *
	 * @return void
	 * @throws Migration_Exception Archive corrompue.
	 */
	private function load_lwb_index() {
		$stat = fstat( $this->fh );
		$size = (int) $stat['size'];
		if ( $size < 40 ) {
			throw self::truncated();
		}
		fseek( $this->fh, $size - 32 );
		$trailer = (string) fread( $this->fh, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( Archiver::LWB_TRAILER !== substr( $trailer, 0, 8 ) ) {
			throw self::truncated();
		}
		$this->index_start = self::u64( substr( $trailer, 8, 8 ) );
		$this->index_size  = self::u64( substr( $trailer, 16, 8 ) );
		$this->count       = self::u64( substr( $trailer, 24, 8 ) );
		if ( $this->index_start + $this->index_size > $size - 32 ) {
			throw self::truncated();
		}
	}

	/**
	 * Décode un entier 64 bits little-endian.
	 *
	 * @param string $bin 8 octets.
	 * @return int
	 */
	private static function u64( $bin ) {
		$v = unpack( 'P', $bin );
		return (int) $v[1];
	}

	/**
	 * Lit l'entrée suivante de l'index.
	 *
	 * @param int $cursor Position dans l'index (mise à jour).
	 * @return array|null Entrée (name, method, crc, csize, size, offset, mtime, dir) ou null en fin d'index.
	 * @throws Migration_Exception Index corrompu.
	 */
	public function next_entry( &$cursor ) {
		if ( $cursor >= $this->index_size ) {
			return null;
		}
		fseek( $this->fh, $this->index_start + $cursor );
		if ( Archiver::FORMAT_LWB === $this->format ) {
			$line = fgets( $this->fh );
			if ( false === $line ) {
				throw self::truncated();
			}
			$cursor += strlen( $line );
			$e       = json_decode( $line, true );
			if ( ! is_array( $e ) || ! isset( $e['n'] ) ) {
				throw self::truncated();
			}
			return array(
				'name'        => (string) $e['n'],
				'method'      => 0,
				'crc'         => (int) $e['c'],
				'csize'       => (int) $e['s'],
				'size'        => (int) $e['s'],
				'data_offset' => (int) $e['o'],
				'mtime'       => (int) $e['m'],
				'dir'         => 'd' === $e['t'],
			);
		}
		$head = (string) fread( $this->fh, 46 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $head ) < 46 || "PK\x01\x02" !== substr( $head, 0, 4 ) ) {
			throw self::truncated();
		}
		$h    = unpack( 'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Voffset', $head );
		$name = $h['name_len'] > 0 ? (string) fread( $this->fh, $h['name_len'] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$ext  = $h['extra_len'] > 0 ? (string) fread( $this->fh, $h['extra_len'] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

		$cursor += 46 + $h['name_len'] + $h['extra_len'] + $h['comment_len'];

		$size    = (int) $h['size'];
		$csize   = (int) $h['csize'];
		$offset  = (int) $h['offset'];
		$p       = 0;
		$ext_len = strlen( $ext );
		while ( $p + 4 <= $ext_len ) {
			$id  = unpack( 'v', substr( $ext, $p, 2 ) );
			$len = unpack( 'v', substr( $ext, $p + 2, 2 ) );
			if ( 0x0001 === $id[1] ) {
				$data = substr( $ext, $p + 4, $len[1] );
				$q    = 0;
				if ( 0xFFFFFFFF === $size ) {
					$size = self::u64( substr( $data, $q, 8 ) );
					$q   += 8;
				}
				if ( 0xFFFFFFFF === $csize ) {
					$csize = self::u64( substr( $data, $q, 8 ) );
					$q    += 8;
				}
				if ( 0xFFFFFFFF === $offset ) {
					$offset = self::u64( substr( $data, $q, 8 ) );
				}
			}
			$p += 4 + $len[1];
		}
		return array(
			'name'   => $name,
			'method' => (int) $h['method'],
			'crc'    => (int) $h['crc'],
			'csize'  => $csize,
			'size'   => $size,
			'offset' => $offset,
			'mtime'  => self::from_dos( (int) $h['time'], (int) $h['date'] ),
			'dir'    => '/' === substr( $name, -1 ),
		);
	}

	/**
	 * Convertit une date DOS en timestamp.
	 *
	 * @param int $time Heure DOS.
	 * @param int $date Date DOS.
	 * @return int
	 */
	private static function from_dos( $time, $date ) {
		return (int) gmmktime( ( $time >> 11 ) & 31, ( $time >> 5 ) & 63, ( $time << 1 ) & 62, ( $date >> 5 ) & 15, $date & 31, ( ( $date >> 9 ) & 127 ) + 1980 );
	}

	/**
	 * Offset des données d'une entrée.
	 *
	 * @param array $entry Entrée.
	 * @return int
	 * @throws Migration_Exception En-tête local invalide.
	 */
	private function data_offset( array $entry ) {
		if ( isset( $entry['data_offset'] ) ) {
			return (int) $entry['data_offset'];
		}
		fseek( $this->fh, $entry['offset'] );
		$lh = (string) fread( $this->fh, 30 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $lh ) < 30 || "PK\x03\x04" !== substr( $lh, 0, 4 ) ) {
			throw self::truncated();
		}
		$l = unpack( 'vname/vextra', substr( $lh, 26, 4 ) );
		return (int) $entry['offset'] + 30 + $l['name'] + $l['extra'];
	}

	/**
	 * Recherche une entrée par nom (parcours de l'index).
	 *
	 * @param string $name Nom exact.
	 * @return array|null
	 */
	public function find( $name ) {
		$cursor = 0;
		while ( null !== ( $e = $this->next_entry( $cursor ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( $e['name'] === $name ) {
				return $e;
			}
		}
		return null;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- exceptions créées par des fabriques (self::truncated()…), non typables par le sniff.
	/**
	 * Lit une petite entrée en mémoire.
	 *
	 * @param array $entry Entrée.
	 * @param int   $max   Taille maximale acceptée.
	 * @return string
	 * @throws Migration_Exception Entrée trop grosse.
	 * @throws Migration_Exception Méthode de compression inconnue.
	 */
	public function read_string( array $entry, $max = 16777216 ) {
		if ( $entry['size'] > $max ) {
			throw new Migration_Exception( 'Entrée trop volumineuse pour être lue en mémoire.', '' );
		}
		fseek( $this->fh, $this->data_offset( $entry ) );
		$raw = $entry['csize'] > 0 ? (string) fread( $this->fh, $entry['csize'] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( 8 === $entry['method'] ) {
			$raw = self::inflate_string( $raw );
		} elseif ( 0 !== $entry['method'] ) {
			throw self::unsupported();
		}
		return $raw;
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Méthode de compression non gérée.
	 *
	 * @return Migration_Exception
	 */
	private static function unsupported() {
		return new Migration_Exception( 'Méthode de compression non prise en charge.', 'Utilisez une archive produite par Lwb Migration.' );
	}

	/**
	 * Décompresse une chaîne deflate brute.
	 *
	 * @param string $raw Données.
	 * @return string
	 * @throws Migration_Exception Zlib absente ou données corrompues.
	 */
	private static function inflate_string( $raw ) {
		if ( function_exists( 'gzinflate' ) ) {
			$out = @gzinflate( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $out ) {
				throw self::truncated();
			}
			return $out;
		}
		throw self::zlib_missing();
	}

	/**
	 * Zlib absente.
	 *
	 * @return Migration_Exception
	 */
	private static function zlib_missing() {
		return new Migration_Exception(
			'L’extension PHP zlib est nécessaire pour décompresser cette archive.',
			'Activez zlib chez votre hébergeur, ou refaites l’export avec le format « LWB (sans compression) » dans les Paramètres.'
		);
	}

	/**
	 * Valide un nom d'entrée (protection « zip slip »).
	 *
	 * @param string $name Nom.
	 * @return string|null Nom normalisé ou null si dangereux.
	 */
	public static function safe_name( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		if ( '' === $name || false !== strpos( $name, "\0" ) || '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
			return null;
		}
		foreach ( explode( '/', $name ) as $seg ) {
			if ( '..' === $seg ) {
				return null;
			}
		}
		return $name;
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- exceptions créées par des fabriques (self::truncated()…), non typables par le sniff.
	/**
	 * Extrait (une partie d') une entrée vers un fichier.
	 *
	 * Les entrées stockées sont reprenables octet par octet ; les entrées compressées
	 * (toujours < 8 Mo dans nos archives) sont extraites en une fois.
	 *
	 * @param array   $entry Entrée.
	 * @param string  $dest  Chemin de destination.
	 * @param array   $prog  Progression (done, crc) mise à jour.
	 * @param Runtime $rt    Budget.
	 * @return bool true si l'entrée est entièrement extraite.
	 * @throws Migration_Exception Méthode de compression inconnue.
	 * @throws Migration_Exception Fichier de destination impossible à créer.
	 * @throws Migration_Exception Archive tronquée (données stockées).
	 * @throws Migration_Exception Archive tronquée (données compressées).
	 * @throws Migration_Exception Données compressées corrompues.
	 */
	public function extract( array $entry, $dest, array &$prog, Runtime $rt ) {
		$prog = array_merge(
			array(
				'done'      => 0,
				'crc'       => 0,
				'crc_error' => false,
			),
			$prog
		);
		if ( $entry['dir'] ) {
			self::mkdir( $dest );
			return true;
		}
		self::mkdir( dirname( $dest ) );
		$offset = $this->data_offset( $entry );
		if ( 0 !== $entry['method'] && 8 !== $entry['method'] ) {
			throw self::unsupported();
		}
		$resume = 0 === $entry['method'] && $prog['done'] > 0;
		$out    = @fopen( $dest, $resume ? 'c+b' : 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			throw new Migration_Exception(
				sprintf( 'Impossible d’écrire le fichier %s.', $entry['name'] ),
				'Vérifiez que le dossier de destination est accessible en écriture (755) et que l’espace disque est suffisant.'
			);
		}
		if ( $resume ) {
			ftruncate( $out, $prog['done'] );
			fseek( $out, $prog['done'] );
		} else {
			$prog['done'] = 0;
			$prog['crc']  = 0;
		}
		$crc = hash_init( 'crc32b' );
		$len = 0;
		if ( 0 === $entry['method'] ) {
			fseek( $this->fh, $offset + $prog['done'] );
			while ( $prog['done'] < $entry['size'] ) {
				$buf = fread( $this->fh, (int) min( self::READ_CHUNK, $entry['size'] - $prog['done'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $buf || '' === $buf ) {
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					throw self::truncated();
				}
				self::write_all( $out, $buf, $entry['name'] );
				hash_update( $crc, $buf );
				$prog['done'] += strlen( $buf );
				$len          += strlen( $buf );
				if ( $rt->should_stop() ) {
					break;
				}
			}
		} else {
			fseek( $this->fh, $offset );
			$left = $entry['csize'];
			if ( function_exists( 'inflate_init' ) ) {
				$z = inflate_init( ZLIB_ENCODING_RAW );
				while ( $left > 0 ) {
					$buf = fread( $this->fh, (int) min( self::READ_CHUNK, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false === $buf || '' === $buf ) {
						fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						throw self::truncated();
					}
					$left -= strlen( $buf );
					$data  = @inflate_add( $z, $buf, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false === $data ) {
						fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						throw self::truncated();
					}
					self::write_all( $out, $data, $entry['name'] );
					hash_update( $crc, $data );
					$len += strlen( $data );
				}
			} else {
				$data = self::inflate_string( $left > 0 ? (string) fread( $this->fh, $left ) : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				self::write_all( $out, $data, $entry['name'] );
				hash_update( $crc, $data );
				$len = strlen( $data );
			}
			$prog['done'] = $entry['size'];
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$prog['crc'] = self::crc32_combine( (int) $prog['crc'], self::hash_to_int( hash_final( $crc, true ) ), $len );
		if ( $prog['done'] < $entry['size'] ) {
			return false;
		}
		if ( $prog['crc'] !== (int) $entry['crc'] ) {
			$prog['crc_error'] = true;
		}
		if ( ! empty( $entry['mtime'] ) ) {
			@touch( $dest, $entry['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return true;
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber

	/**
	 * Écrit intégralement une chaîne.
	 *
	 * @param resource $h    Descripteur.
	 * @param string   $data Données.
	 * @param string   $name Nom (pour le message).
	 * @return void
	 * @throws Migration_Exception Disque plein.
	 */
	private static function write_all( $h, $data, $name ) {
		$len   = strlen( $data );
		$total = 0;
		while ( $total < $len ) {
			$n = fwrite( $h, 0 === $total ? $data : substr( $data, $total ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				throw new Migration_Exception(
					sprintf( 'Écriture impossible pendant l’extraction de %s : espace disque saturé ?', $name ),
					'Libérez de l’espace disque sur le serveur de destination puis relancez.',
					true
				);
			}
			$total += $n;
		}
	}

	/**
	 * Crée un dossier récursivement.
	 *
	 * @param string $dir Dossier.
	 * @return void
	 * @throws Migration_Exception Échec.
	 */
	private static function mkdir( $dir ) {
		if ( is_dir( $dir ) ) {
			return;
		}
		if ( ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			throw new Migration_Exception(
				sprintf( 'Impossible de créer le dossier %s.', $dir ),
				'Vérifiez les permissions du dossier de destination (755).'
			);
		}
	}

	/**
	 * Convertit une empreinte crc32b binaire en entier non signé.
	 *
	 * @param string $raw 4 octets.
	 * @return int
	 */
	private static function hash_to_int( $raw ) {
		$v = unpack( 'N', $raw );
		return (int) $v[1] & 0xFFFFFFFF;
	}

	/**
	 * Combine deux CRC32 : crc(A.B) à partir de crc(A), crc(B) et len(B).
	 *
	 * Portage de crc32_combine() de zlib. Permet de calculer le CRC d'un gros fichier
	 * copié sur plusieurs requêtes sans conserver de contexte de hachage.
	 *
	 * @param int $crc1 CRC de A.
	 * @param int $crc2 CRC de B.
	 * @param int $len2 Longueur de B.
	 * @return int
	 */
	public static function crc32_combine( $crc1, $crc2, $len2 ) {
		if ( $len2 <= 0 ) {
			return $crc1 & 0xFFFFFFFF;
		}
		$odd   = array( 0xEDB88320 );
		$row   = 1;
		$even  = array();
		$crc1 &= 0xFFFFFFFF;
		$crc2 &= 0xFFFFFFFF;
		$len2  = (int) $len2;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::gf2_square( $odd );
		$odd  = self::gf2_square( $even );
		do {
			$even = self::gf2_square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::gf2_square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );
		return ( $crc1 ^ $crc2 ) & 0xFFFFFFFF;
	}

	/**
	 * Produit matrice × vecteur sur GF(2).
	 *
	 * @param int[] $mat Matrice.
	 * @param int   $vec Vecteur.
	 * @return int
	 */
	private static function gf2_times( array $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		while ( $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			++$i;
		}
		return $sum;
	}

	/**
	 * Carré d'une matrice sur GF(2).
	 *
	 * @param int[] $mat Matrice.
	 * @return int[]
	 */
	private static function gf2_square( array $mat ) {
		$sq = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$sq[ $n ] = self::gf2_times( $mat, $mat[ $n ] );
		}
		return $sq;
	}
}
