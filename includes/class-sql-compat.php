<?php
/**
 * Adaptation des instructions SQL aux serveurs stricts ou anciens.
 *
 * - Conversion MyISAM/Aria → InnoDB (options propres à Aria retirées).
 * - Clé primaire ajoutée quand le serveur l'exige (sql_require_primary_key de MySQL 8 :
 *   DigitalOcean, Azure, PlanetScale ; innodb_force_primary_key de MariaDB).
 * - Index raccourcis quand ils dépassent la limite du serveur (767 octets sur MySQL 5.6
 *   ou MariaDB 10.1 : un VARCHAR(255) en utf8mb4 occupe 1 020 octets).
 * - Instructions INSERT multi-lignes découpées sous le max_allowed_packet du serveur.
 *
 * Fonctions pures sur le texte produit par SHOW CREATE TABLE et par Db_Dumper.
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Compatibilité SQL.
 */
class Sql_Compat {

	/**
	 * Colonne ajoutée comme clé primaire aux tables qui n'en ont pas.
	 */
	const ROW_ID = 'lwb_row_id';

	/**
	 * Octets par caractère des jeux de caractères multioctets courants.
	 *
	 * @var array<string,int>
	 */
	private static $charset_bytes = array(
		'utf8mb4'  => 4,
		'utf8mb3'  => 3,
		'utf8'     => 3,
		'utf16'    => 4,
		'utf16le'  => 4,
		'utf32'    => 4,
		'ucs2'     => 2,
		'gb18030'  => 4,
		'ujis'     => 3,
		'eucjpms'  => 3,
		'big5'     => 2,
		'gbk'      => 2,
		'gb2312'   => 2,
		'sjis'     => 2,
		'cp932'    => 2,
		'euckr'    => 2,
		'binary'   => 1,
		'latin1'   => 1,
		'ascii'    => 1,
		'latin2'   => 1,
		'cp1250'   => 1,
		'cp1251'   => 1,
		'cp1256'   => 1,
		'cp1257'   => 1,
		'greek'    => 1,
		'hebrew'   => 1,
		'koi8r'    => 1,
		'koi8u'    => 1,
		'tis620'   => 1,
		'armscii8' => 1,
		'geostd8'  => 1,
		'swe7'     => 1,
		'dec8'     => 1,
		'hp8'      => 1,
		'cp850'    => 1,
		'cp852'    => 1,
		'cp866'    => 1,
		'keybcs2'  => 1,
		'macce'    => 1,
		'macroman' => 1,
		'latin5'   => 1,
		'latin7'   => 1,
	);

	// ------------------------------------------------------------------
	// Lecture d'un CREATE TABLE.
	// ------------------------------------------------------------------

	/**
	 * Est-ce une instruction CREATE TABLE ?
	 *
	 * @param string $sql Instruction.
	 * @return bool
	 */
	public static function is_create_table( $sql ) {
		return (bool) preg_match( '/^\s*CREATE\s+TABLE\s/i', $sql );
	}

	/**
	 * Moteur de stockage déclaré (en minuscules, '' si absent).
	 *
	 * @param string $sql CREATE TABLE.
	 * @return string
	 */
	public static function engine( $sql ) {
		$parts = self::split_create( $sql );
		$tail  = null === $parts ? (string) $sql : $parts[2];
		return preg_match( '/\bENGINE\s*=\s*`?(\w+)`?/i', $tail, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * Le moteur est-il MyISAM ou Aria ?
	 *
	 * @param string $sql CREATE TABLE.
	 * @return bool
	 */
	public static function is_myisam_like( $sql ) {
		return in_array( self::engine( $sql ), array( 'myisam', 'aria' ), true );
	}

	/**
	 * Découpe la définition en : début (« CREATE TABLE `t` ( »), lignes, fin (« ) ENGINE=… »).
	 *
	 * @param string $sql CREATE TABLE.
	 * @return array|null array( head, lines[], tail ) ou null si le format n'est pas reconnu.
	 */
	private static function split_create( $sql ) {
		$open = strpos( $sql, '(' );
		if ( false === $open ) {
			return null;
		}
		$depth = 0;
		$quote = '';
		$len   = strlen( $sql );
		$close = -1;
		$parts = array();
		$start = $open + 1;
		for ( $i = $open; $i < $len; $i++ ) {
			$c = $sql[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c && '`' !== $quote ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$quote = $c;
			} elseif ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					$close = $i;
					break;
				}
			} elseif ( ',' === $c && 1 === $depth ) {
				$parts[] = substr( $sql, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		if ( $close < 0 ) {
			return null;
		}
		$parts[] = substr( $sql, $start, $close - $start );
		return array( substr( $sql, 0, $open + 1 ), array_map( 'trim', $parts ), substr( $sql, $close ) );
	}

	/**
	 * Recompose un CREATE TABLE au format de SHOW CREATE TABLE.
	 *
	 * @param string   $head  Début.
	 * @param string[] $lines Définitions.
	 * @param string   $tail  Fin.
	 * @return string
	 */
	private static function join_create( $head, array $lines, $tail ) {
		return rtrim( $head ) . "\n  " . implode( ",\n  ", $lines ) . "\n" . ltrim( $tail );
	}

	/**
	 * Une définition est-elle une colonne (et non un index ou une contrainte) ?
	 *
	 * @param string $line Définition.
	 * @return bool
	 */
	private static function is_column( $line ) {
		return '`' === substr( $line, 0, 1 );
	}

	/**
	 * Nom d'une colonne.
	 *
	 * @param string $line Définition de colonne.
	 * @return string
	 */
	private static function column_name( $line ) {
		return preg_match( '/^`((?:[^`]|``)+)`/', $line, $m ) ? str_replace( '``', '`', $m[1] ) : '';
	}

	/**
	 * Jeu de caractères par défaut de la table.
	 *
	 * @param string $tail Fin du CREATE TABLE.
	 * @return string
	 */
	private static function table_charset( $tail ) {
		if ( preg_match( '/CHARSET\s*=\s*(\w+)/i', $tail, $m ) ) {
			return strtolower( $m[1] );
		}
		if ( preg_match( '/COLLATE\s*=\s*([a-z0-9]+)/i', $tail, $m ) ) {
			return strtolower( $m[1] );
		}
		return 'utf8mb4'; // Inconnu : hypothèse la plus prudente pour WordPress.
	}

	/**
	 * Octets par caractère d'un jeu de caractères.
	 *
	 * @param string $charset Jeu de caractères.
	 * @return int
	 */
	public static function bytes_per_char( $charset ) {
		$charset = strtolower( (string) $charset );
		return isset( self::$charset_bytes[ $charset ] ) ? self::$charset_bytes[ $charset ] : 1;
	}

	/**
	 * Colonnes d'un CREATE TABLE : type, longueur, octets par caractère, NOT NULL.
	 *
	 * @param string[] $lines         Définitions.
	 * @param string   $table_charset Jeu de caractères par défaut.
	 * @return array<string,array>
	 */
	private static function columns( array $lines, $table_charset ) {
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! self::is_column( $line ) ) {
				continue;
			}
			$name = self::column_name( $line );
			$rest = substr( $line, strlen( $name ) + 2 + substr_count( $name, '`' ) );
			$rest = preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $rest ); // Commentaires et valeurs par défaut ignorés.
			if ( ! preg_match( '/^\s*(\w+)(?:\((\d+)\))?/', $rest, $t ) ) {
				continue;
			}
			$type    = strtolower( $t[1] );
			$charset = $table_charset;
			if ( preg_match( '/CHARACTER SET\s+(\w+)/i', $rest, $c ) ) {
				$charset = strtolower( $c[1] );
			} elseif ( preg_match( '/COLLATE\s+([a-z0-9]+)/i', $rest, $c ) ) {
				$charset = strtolower( $c[1] );
			}
			$binary       = in_array( $type, array( 'binary', 'varbinary' ), true );
			$out[ $name ] = array(
				'type'     => $type,
				'length'   => isset( $t[2] ) ? (int) $t[2] : 0,
				'bytes'    => $binary ? 1 : self::bytes_per_char( $charset ),
				'string'   => in_array( $type, array( 'char', 'varchar', 'binary', 'varbinary' ), true ),
				'not_null' => (bool) preg_match( '/\bNOT NULL\b/i', $rest ),
				'auto'     => (bool) preg_match( '/\bAUTO_INCREMENT\b/i', $rest ),
			);
		}
		return $out;
	}

	/**
	 * Analyse une définition d'index.
	 *
	 * @param string $line Définition.
	 * @return array|null kind (primary|unique|key|fulltext|spatial), prefix (texte avant la liste),
	 *                    items (colonnes : name, length, raw), suffix.
	 */
	private static function parse_key( $line ) {
		if ( ! preg_match( '/^(PRIMARY KEY|UNIQUE(?: KEY| INDEX)?|KEY|INDEX|FULLTEXT(?: KEY| INDEX)?|SPATIAL(?: KEY| INDEX)?)\s*(`(?:[^`]|``)*`\s*)?\(/i', $line, $m ) ) {
			return null;
		}
		$start = strlen( $m[0] );
		$depth = 1;
		$len   = strlen( $line );
		$end   = -1;
		for ( $i = $start; $i < $len; $i++ ) {
			if ( '`' === $line[ $i ] ) {
				$next = strpos( $line, '`', $i + 1 );
				$i    = false === $next ? $len : $next;
				continue;
			}
			if ( '(' === $line[ $i ] ) {
				++$depth;
			} elseif ( ')' === $line[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}
		if ( $end < 0 ) {
			return null;
		}
		$items = array();
		foreach ( self::split_top_level( substr( $line, $start, $end - $start ) ) as $raw ) {
			$raw = trim( $raw );
			if ( preg_match( '/^`((?:[^`]|``)+)`(?:\((\d+)\))?(.*)$/s', $raw, $c ) ) {
				$items[] = array(
					'name'   => str_replace( '``', '`', $c[1] ),
					'length' => '' !== $c[2] ? (int) $c[2] : 0,
					'rest'   => $c[3],
					'raw'    => $raw,
				);
			} else {
				$items[] = array(
					'name'   => '',
					'length' => 0,
					'rest'   => '',
					'raw'    => $raw, // Index fonctionnel (expression) : laissé tel quel.
				);
			}
		}
		$kind = strtolower( strtok( $m[1], ' ' ) );
		return array(
			'kind'   => $kind,
			'prefix' => substr( $line, 0, $start ),
			'items'  => $items,
			'suffix' => substr( $line, $end ),
		);
	}

	/**
	 * Découpe une liste sur les virgules de premier niveau.
	 *
	 * @param string $items Liste.
	 * @return string[]
	 */
	private static function split_top_level( $items ) {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $items );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $items[ $i ];
			if ( '`' === $c ) {
				$next = strpos( $items, '`', $i + 1 );
				$next = false === $next ? $len - 1 : $next;
				$cur .= substr( $items, $i, $next - $i + 1 );
				$i    = $next;
				continue;
			}
			if ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$out[] = $cur;
				$cur   = '';
				continue;
			}
			$cur .= $c;
		}
		$out[] = $cur;
		return $out;
	}

	/**
	 * Recompose une définition d'index.
	 *
	 * @param array $key Index analysé.
	 * @return string
	 */
	private static function build_key( array $key ) {
		$items = array();
		foreach ( $key['items'] as $item ) {
			if ( '' === $item['name'] ) {
				$items[] = $item['raw'];
				continue;
			}
			$items[] = Db::ident( $item['name'] ) . ( $item['length'] > 0 ? '(' . $item['length'] . ')' : '' ) . $item['rest'];
		}
		return $key['prefix'] . implode( ',', $items ) . $key['suffix'];
	}

	// ------------------------------------------------------------------
	// Transformations.
	// ------------------------------------------------------------------

	/**
	 * Convertit une table MyISAM ou Aria en InnoDB.
	 *
	 * Les options propres à Aria (PAGE_CHECKSUM, TRANSACTIONAL) sont refusées par MySQL et
	 * ROW_FORMAT=FIXED/PAGE par InnoDB en mode strict : elles sont retirées.
	 *
	 * @param string $sql CREATE TABLE.
	 * @return string
	 */
	public static function convert_engine( $sql ) {
		if ( ! self::is_myisam_like( $sql ) ) {
			return $sql;
		}
		$parts = self::split_create( $sql );
		if ( null === $parts ) {
			return $sql;
		}
		$tail = preg_replace( '/\bENGINE\s*=\s*`?(?:MyISAM|Aria)`?/i', 'ENGINE=InnoDB', $parts[2] );
		$tail = preg_replace( '/\s*\b(?:PAGE_CHECKSUM|TRANSACTIONAL|DELAY_KEY_WRITE)\s*=\s*\w+/i', '', $tail );
		$tail = preg_replace( '/\s*\bROW_FORMAT\s*=\s*(?:FIXED|PAGE)\b/i', '', $tail );
		return substr( $sql, 0, strlen( $sql ) - strlen( $parts[2] ) ) . $tail;
	}

	/**
	 * La table a-t-elle une clé primaire ?
	 *
	 * @param string $sql CREATE TABLE.
	 * @return bool
	 */
	public static function has_primary_key( $sql ) {
		$parts = self::split_create( $sql );
		if ( null === $parts ) {
			return (bool) preg_match( '/\bPRIMARY\s+KEY\b/i', $sql );
		}
		foreach ( $parts[1] as $line ) {
			// Commentaires et valeurs par défaut (entre apostrophes) ignorés.
			$code = preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $line );
			if ( preg_match( '/^PRIMARY\s+KEY\b/i', $code ) || ( self::is_column( $code ) && preg_match( '/\sPRIMARY\s+KEY\b/i', $code ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ajoute une clé primaire à une table qui n'en a pas.
	 *
	 * Par ordre de préférence : un index UNIQUE sur des colonnes NOT NULL devient la clé
	 * primaire, puis la colonne AUTO_INCREMENT, sinon une colonne lwb_row_id est ajoutée
	 * (invisible quand le serveur le permet : les requêtes « SELECT * » sont inchangées).
	 *
	 * @param string $sql       CREATE TABLE.
	 * @param bool   $invisible Colonne invisible (MySQL 8.0.23 ou plus).
	 * @return string
	 */
	public static function add_primary_key( $sql, $invisible = false ) {
		if ( self::has_primary_key( $sql ) ) {
			return $sql;
		}
		$parts = self::split_create( $sql );
		if ( null === $parts ) {
			return $sql;
		}
		list( $head, $lines, $tail ) = $parts;
		$columns                     = self::columns( $lines, self::table_charset( $tail ) );
		foreach ( $lines as $i => $line ) {
			$key = self::parse_key( $line );
			if ( null === $key || 'unique' !== $key['kind'] ) {
				continue;
			}
			$usable = ! empty( $key['items'] );
			foreach ( $key['items'] as $item ) {
				if ( '' === $item['name'] || $item['length'] > 0 || empty( $columns[ $item['name'] ]['not_null'] ) ) {
					$usable = false;
				}
			}
			if ( $usable ) {
				$key['prefix'] = 'PRIMARY KEY (';
				$lines[ $i ]   = self::build_key( $key );
				return self::join_create( $head, $lines, $tail );
			}
		}
		foreach ( $columns as $name => $col ) {
			if ( $col['auto'] ) {
				$lines[] = 'PRIMARY KEY (' . Db::ident( $name ) . ')';
				return self::join_create( $head, $lines, $tail );
			}
		}
		$first = 0;
		foreach ( $lines as $i => $line ) {
			if ( ! self::is_column( $line ) ) {
				break;
			}
			$first = $i + 1;
		}
		array_splice( $lines, $first, 0, array( Db::ident( self::ROW_ID ) . ' bigint unsigned NOT NULL AUTO_INCREMENT' . ( $invisible ? ' INVISIBLE' : '' ) ) );
		array_splice( $lines, $first + 1, 0, array( 'PRIMARY KEY (' . Db::ident( self::ROW_ID ) . ')' ) );
		return self::join_create( $head, $lines, $tail );
	}

	/**
	 * Raccourcit les index qui dépassent la limite du serveur (préfixes de colonnes).
	 *
	 * @param string $sql       CREATE TABLE.
	 * @param int    $col_limit Octets maximum par colonne indexée (767 sur InnoDB COMPACT).
	 * @param int    $key_limit Octets maximum par index (3 072 sur InnoDB, 1 000 sur MyISAM).
	 * @return string
	 */
	public static function shorten_indexes( $sql, $col_limit, $key_limit = 0 ) {
		$col_limit = max( 1, (int) $col_limit );
		$key_limit = $key_limit > 0 ? (int) $key_limit : max( $col_limit, 3072 );
		$parts     = self::split_create( $sql );
		if ( null === $parts ) {
			return $sql;
		}
		list( $head, $lines, $tail ) = $parts;
		$columns                     = self::columns( $lines, self::table_charset( $tail ) );
		$changed                     = false;
		foreach ( $lines as $i => $line ) {
			$key = self::parse_key( $line );
			if ( null === $key || in_array( $key['kind'], array( 'fulltext', 'spatial' ), true ) ) {
				continue;
			}
			$total = 0;
			foreach ( $key['items'] as $n => $item ) {
				$col = '' !== $item['name'] && isset( $columns[ $item['name'] ] ) ? $columns[ $item['name'] ] : null;
				if ( null === $col || ! $col['string'] ) {
					$total += 8;
					continue;
				}
				$chars = $item['length'] > 0 ? $item['length'] : $col['length'];
				$max   = (int) floor( $col_limit / $col['bytes'] );
				if ( $chars > $max ) {
					$key['items'][ $n ]['length'] = $max;
					$chars                        = $max;
				}
				$total += $chars * $col['bytes'];
			}
			// Index composé trop long : on réduit la colonne la plus longue jusqu'à la limite.
			$guard = 0;
			while ( $total > $key_limit && $guard++ < 64 ) {
				$longest = -1;
				$bytes   = 0;
				foreach ( $key['items'] as $n => $item ) {
					$col = '' !== $item['name'] && isset( $columns[ $item['name'] ] ) ? $columns[ $item['name'] ] : null;
					if ( null === $col || ! $col['string'] ) {
						continue;
					}
					$len = ( $item['length'] > 0 ? $item['length'] : $col['length'] ) * $col['bytes'];
					if ( $len > $bytes ) {
						$bytes   = $len;
						$longest = $n;
					}
				}
				if ( $longest < 0 ) {
					break;
				}
				$col    = $columns[ $key['items'][ $longest ]['name'] ];
				$chars  = (int) ( $bytes / $col['bytes'] );
				$reduce = (int) ceil( ( $total - $key_limit ) / $col['bytes'] );
				$new    = max( 1, $chars - $reduce );

				$key['items'][ $longest ]['length'] = $new;

				$total -= ( $chars - $new ) * $col['bytes'];
				if ( 1 === $new ) {
					break;
				}
			}
			$rebuilt = self::build_key( $key );
			if ( $rebuilt !== $line ) {
				$lines[ $i ] = $rebuilt;
				$changed     = true;
			}
		}
		return $changed ? self::join_create( $head, $lines, $tail ) : $sql;
	}

	/**
	 * Limites d'index annoncées par un message d'erreur MySQL (1071, 1709).
	 *
	 * @param string $error Message.
	 * @param string $sql   CREATE TABLE (moteur).
	 * @return array|null array( limite par colonne, limite par index ) ou null.
	 */
	public static function index_limits_from_error( $error, $sql ) {
		if ( ! preg_match( '/max(?:imum)?\s+(?:key\s+length|column\s+size)\s+is\s+(\d+)/i', (string) $error, $m ) ) {
			return null;
		}
		$limit = (int) $m[1];
		if ( self::is_myisam_like( $sql ) ) {
			return array( $limit, $limit );
		}
		// InnoDB : 767 octets par colonne (format COMPACT), 3 072 par index.
		return array( min( $limit, 3072 ), 3072 );
	}

	/**
	 * Découpe un INSERT multi-lignes en instructions plus petites que $max octets.
	 *
	 * Format attendu : celui de Db_Dumper, « INSERT INTO `t` (`a`,`b`) VALUES (…),(…) ».
	 * Une ligne seule plus grande que $max reste dans sa propre instruction.
	 *
	 * @param string $sql Instruction (sans « ; » final).
	 * @param int    $max Taille maximale (octets).
	 * @return string[]
	 */
	public static function split_insert( $sql, $max ) {
		$sql = rtrim( $sql, " \t\r\n;" );
		if ( strlen( $sql ) <= $max || ! preg_match( '/^(INSERT\s+(?:IGNORE\s+)?INTO\s+`(?:[^`]|``)+`\s*(?:\((?:[^()`]|`(?:[^`]|``)*`)*\))?\s*VALUES\s*)\(/i', $sql, $m ) ) {
			return array( $sql );
		}
		$head   = $m[1];
		$len    = strlen( $sql );
		$out    = array();
		$buf    = '';
		$depth  = 0;
		$quote  = false;
		$tstart = strlen( $head );
		for ( $i = $tstart; $i < $len; $i++ ) {
			$c = $sql[ $i ];
			if ( $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( "'" === $c ) {
					if ( $i + 1 < $len && "'" === $sql[ $i + 1 ] ) {
						++$i; // Apostrophe doublée.
					} else {
						$quote = false;
					}
				}
				continue;
			}
			if ( "'" === $c ) {
				$quote = true;
			} elseif ( '(' === $c ) {
				if ( 0 === $depth ) {
					$tstart = $i;
				}
				++$depth;
			} elseif ( ')' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					$tuple = substr( $sql, $tstart, $i - $tstart + 1 );
					if ( '' !== $buf && strlen( $head ) + strlen( $buf ) + 1 + strlen( $tuple ) > $max ) {
						$out[] = $head . $buf;
						$buf   = '';
					}
					$buf .= ( '' === $buf ? '' : ',' ) . $tuple;
				}
			}
		}
		if ( 0 !== $depth || $quote ) {
			return array( $sql ); // Format inattendu : instruction laissée intacte.
		}
		if ( '' !== $buf ) {
			$out[] = $head . $buf;
		}
		return $out;
	}

	/**
	 * Le serveur accepte-t-il les colonnes invisibles avec AUTO_INCREMENT ? (MySQL 8.0.23+)
	 *
	 * @param string $version Résultat de SELECT VERSION().
	 * @return bool
	 */
	public static function supports_invisible( $version ) {
		$version = (string) $version;
		if ( false !== stripos( $version, 'mariadb' ) ) {
			return false;
		}
		return version_compare( preg_replace( '/^(\d+\.\d+\.\d+).*$/', '$1', $version ), '8.0.23', '>=' );
	}
}
