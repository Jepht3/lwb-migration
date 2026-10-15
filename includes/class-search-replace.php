<?php
/**
 * Rechercher/remplacer sûr pour les données sérialisées PHP, JSON et URL-encodées.
 *
 * Les chaînes sérialisées sont analysées puis reconstruites récursivement : chaque
 * valeur de type chaîne est remplacée (y compris dans les objets imbriqués et les
 * chaînes sérialisées plusieurs fois) et sa longueur « s:N » est recalculée. Aucun
 * objet n'est instancié (pas de risque d'injection d'objet, classes inconnues et
 * propriétés privées conservées à l'identique).
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Moteur de remplacement.
 */
class Search_Replace {

	const MAX_DEPTH = 512;

	/**
	 * Paires recherche => remplacement (appliquées en une passe, plus longue d'abord).
	 *
	 * @var array<string,string>
	 */
	private $pairs = array();

	/**
	 * Aiguilles utilisées pour le pré-filtrage.
	 *
	 * @var string[]
	 */
	private $needles = array();

	/**
	 * Constructeur.
	 *
	 * @param array<string,string> $pairs Paires.
	 */
	public function __construct( array $pairs ) {
		foreach ( $pairs as $from => $to ) {
			$from = (string) $from;
			if ( '' !== $from && $from !== (string) $to ) {
				$this->pairs[ $from ] = (string) $to;
			}
		}
		$this->needles = self::minimal_needles( array_keys( $this->pairs ) );
	}

	/**
	 * Paires actives.
	 *
	 * @return array<string,string>
	 */
	public function get_pairs() {
		return $this->pairs;
	}

	/**
	 * Aiguilles minimales : toute valeur contenant une paire contient l'une d'elles.
	 *
	 * @return string[]
	 */
	public function get_needles() {
		return $this->needles;
	}

	/**
	 * Construit toutes les variantes de remplacement pour une migration.
	 *
	 * @param array<string,string> $urls  Ancienne URL => nouvelle URL (site, accueil…).
	 * @param array<string,string> $paths Ancien chemin absolu => nouveau chemin.
	 * @return array<string,string>
	 */
	public static function build_pairs( array $urls, array $paths ) {
		$pairs = array();
		$add   = function ( $from, $to ) use ( &$pairs ) {
			if ( '' !== $from && $from !== $to && ! isset( $pairs[ $from ] ) ) {
				$pairs[ $from ] = $to;
			}
		};
		foreach ( $urls as $old => $new ) {
			$old = rtrim( (string) $old, '/' );
			$new = rtrim( (string) $new, '/' );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			// Même site avec ou sans « www. » (contenus saisis sous l'autre forme du domaine).
			$hosts = array( $old );
			$alt   = self::toggle_www( $old );
			// Sauf si cette autre forme est (le début de) la nouvelle adresse.
			if ( null !== $alt && 0 !== strpos( strtolower( $new ) . '/', strtolower( rtrim( $alt, '/' ) ) . '/' ) ) {
				$hosts[] = $alt;
			}
			$new_rel = self::strip_scheme( $new );
			foreach ( $hosts as $host_variant ) {
				$variants = array( $host_variant );
				if ( 0 === strpos( $host_variant, 'https://' ) ) {
					$variants[] = 'http://' . substr( $host_variant, 8 );
				} elseif ( 0 === strpos( $host_variant, 'http://' ) ) {
					$variants[] = 'https://' . substr( $host_variant, 7 );
				}
				foreach ( $variants as $variant ) {
					$add( $variant, $new );
					$add( self::json_escape( $variant ), self::json_escape( $new ) );
					$add( rawurlencode( $variant ), rawurlencode( $new ) );
				}
				// URL relative au protocole (//domaine.tld/chemin).
				$old_rel = self::strip_scheme( $host_variant );
				if ( null !== $old_rel && null !== $new_rel ) {
					$add( $old_rel, $new_rel );
					$add( self::json_escape( $old_rel ), self::json_escape( $new_rel ) );
					$add( rawurlencode( $old_rel ), rawurlencode( $new_rel ) );
				}
			}
		}
		foreach ( $paths as $old => $new ) {
			$old = rtrim( (string) $old, '/\\' );
			$new = rtrim( (string) $new, '/\\' );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$add( $old, $new );
			$add( self::json_escape( $old ), self::json_escape( $new ) );
			if ( false !== strpos( $old, '\\' ) ) {
				// Chemin Windows : variantes avec barres obliques.
				$add( str_replace( '\\', '/', $old ), str_replace( '\\', '/', $new ) );
				$add( str_replace( '\\', '\\\\', $old ), str_replace( '\\', '\\\\', $new ) );
			}
		}
		return $pairs;
	}

	/**
	 * Échappe les barres obliques comme json_encode.
	 *
	 * @param string $value Valeur.
	 * @return string
	 */
	private static function json_escape( $value ) {
		return str_replace( '/', '\\/', $value );
	}

	/**
	 * Supprime le schéma d'une URL (http://a.b → //a.b).
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private static function strip_scheme( $url ) {
		$pos = strpos( $url, '://' );
		if ( false === $pos ) {
			return null;
		}
		return substr( $url, $pos + 1 );
	}

	/**
	 * Même URL avec ou sans « www. » devant le domaine (null pour une IP ou localhost).
	 *
	 * @param string $url URL absolue.
	 * @return string|null
	 */
	public static function toggle_www( $url ) {
		if ( ! preg_match( '#^(https?://)([^/:?\#]+)(.*)$#i', (string) $url, $m ) ) {
			return null;
		}
		$host = strtolower( $m[2] );
		if ( false === strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		if ( false === strpos( $host, '.' ) ) {
			return null; // « www.localhost » → « localhost » : pas un vrai domaine.
		}
		return $m[1] . $host . $m[3];
	}

	/**
	 * Réduit une liste d'aiguilles à celles qui ne contiennent aucune autre aiguille.
	 *
	 * @param string[] $candidates Aiguilles.
	 * @return string[]
	 */
	private static function minimal_needles( array $candidates ) {
		usort(
			$candidates,
			function ( $a, $b ) {
				return strlen( $a ) - strlen( $b );
			}
		);
		$out = array();
		foreach ( $candidates as $candidate ) {
			$covered = false;
			foreach ( $out as $kept ) {
				if ( false !== strpos( $candidate, $kept ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				$out[] = $candidate;
			}
		}
		return $out;
	}

	/**
	 * La valeur contient-elle au moins une aiguille ?
	 *
	 * @param string $value Valeur.
	 * @return bool
	 */
	public function matches( $value ) {
		foreach ( $this->needles as $needle ) {
			if ( false !== strpos( $value, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remplace dans une valeur (sérialisée ou non).
	 *
	 * @param mixed $value Valeur.
	 * @return mixed
	 */
	public function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value || empty( $this->pairs ) || ! $this->matches( $value ) ) {
			return $value;
		}
		return $this->replace_string( $value, 0 );
	}

	/**
	 * Remplace dans une chaîne, en détectant les données sérialisées.
	 *
	 * @param string $value Chaîne.
	 * @param int    $depth Profondeur.
	 * @return string
	 */
	private function replace_string( $value, $depth ) {
		if ( $depth < self::MAX_DEPTH && self::looks_serialized( $value ) ) {
			$pos = 0;
			$out = $this->parse( $value, $pos, $depth + 1, false );
			if ( null !== $out && strlen( $value ) === $pos ) {
				return $out;
			}
		}
		return strtr( $value, $this->pairs );
	}

	/**
	 * Test rapide : la chaîne ressemble-t-elle à une valeur sérialisée ?
	 *
	 * @param string $value Chaîne.
	 * @return bool
	 */
	public static function looks_serialized( $value ) {
		if ( strlen( $value ) < 2 ) {
			return false;
		}
		if ( 'N;' === $value ) {
			return true;
		}
		return ':' === $value[1] && false !== strpos( 'aOsbidCE', $value[0] );
	}

	/**
	 * Analyse une valeur sérialisée et la reconstruit avec les remplacements.
	 *
	 * @param string $s      Chaîne complète.
	 * @param int    $pos    Position (mise à jour).
	 * @param int    $depth  Profondeur.
	 * @param bool   $is_key La valeur est une clé (jamais remplacée).
	 * @return string|null Valeur reconstruite, ou null si la chaîne n'est pas sérialisée valide.
	 */
	private function parse( $s, &$pos, $depth, $is_key ) {
		$len = strlen( $s );
		if ( $pos >= $len || $depth > self::MAX_DEPTH ) {
			return null;
		}
		$type = $s[ $pos ];
		switch ( $type ) {
			case 'N':
				if ( ';' !== ( $s[ $pos + 1 ] ?? '' ) ) {
					return null;
				}
				$pos += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				if ( ':' !== ( $s[ $pos + 1 ] ?? '' ) ) {
					return null;
				}
				$end = strpos( $s, ';', $pos + 2 );
				if ( false === $end ) {
					return null;
				}
				$token = substr( $s, $pos, $end - $pos + 1 );
				if ( ! preg_match( '/^[bidrR]:[0-9eE.+\-INFAinfa]+;$/', $token ) ) {
					return null;
				}
				$pos = $end + 1;
				return $token;

			case 's':
				$str = self::read_length_string( $s, $pos );
				if ( null === $str || ';' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				if ( ! $is_key && '' !== $str && $this->matches( $str ) ) {
					$str = $this->replace_string( $str, $depth );
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';

			case 'E':
				$start = $pos;
				$str   = self::read_length_string( $s, $pos );
				if ( null === $str || ';' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				return substr( $s, $start, $pos - $start );

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] );
				$body = $this->parse_members( $s, $pos, (int) $m[1], $depth );
				return null === $body ? null : 'a:' . $m[1] . ':{' . $body . '}';

			case 'O':
				$start = $pos;
				$class = self::read_length_string( $s, $pos );
				if ( null === $class || ! preg_match( '/\G:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$head = substr( $s, $start, $pos - $start );
				$pos += strlen( $m[0] );
				$body = $this->parse_members( $s, $pos, (int) $m[1], $depth );
				return null === $body ? null : $head . ':' . $m[1] . ':{' . $body . '}';

			case 'C':
				// Sérialisation personnalisée (Serializable) : contenu opaque conservé tel quel.
				$start = $pos;
				$class = self::read_length_string( $s, $pos );
				if ( null === $class || ! preg_match( '/\G:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] ) + (int) $m[1];
				if ( '}' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				return substr( $s, $start, $pos - $start );
		}
		return null;
	}

	/**
	 * Analyse N paires clé/valeur puis l'accolade fermante.
	 *
	 * @param string $s     Chaîne.
	 * @param int    $pos   Position.
	 * @param int    $count Nombre de paires.
	 * @param int    $depth Profondeur.
	 * @return string|null
	 */
	private function parse_members( $s, &$pos, $count, $depth ) {
		$parts = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$key = $this->parse( $s, $pos, $depth + 1, true );
			if ( null === $key ) {
				return null;
			}
			$val = $this->parse( $s, $pos, $depth + 1, false );
			if ( null === $val ) {
				return null;
			}
			$parts[] = $key . $val;
		}
		if ( '}' !== ( $s[ $pos ] ?? '' ) ) {
			return null;
		}
		++$pos;
		return implode( '', $parts );
	}

	/**
	 * Lit « X:LEN:"..." » à partir de $pos et renvoie le contenu.
	 *
	 * @param string $s   Chaîne.
	 * @param int    $pos Position (placée après le guillemet fermant).
	 * @return string|null
	 */
	private static function read_length_string( $s, &$pos ) {
		if ( ! preg_match( '/\G[sOCE]:(\d+):"/', $s, $m, 0, $pos ) ) {
			return null;
		}
		$n     = (int) $m[1];
		$start = $pos + strlen( $m[0] );
		if ( $start + $n + 1 > strlen( $s ) || '"' !== $s[ $start + $n ] ) {
			return null;
		}
		$pos = $start + $n + 1;
		return substr( $s, $start, $n );
	}

	/**
	 * Traite une table par lots. Reprenable : l'état est mis à jour au fil de l'eau.
	 *
	 * @param Db            $db    Connexion.
	 * @param string        $table Table.
	 * @param array         $state État de la table (modifié).
	 * @param Runtime       $rt      Budget.
	 * @param callable|null $persist Sauvegarde de l'état (après chaque lot et toutes les 50
	 *                               mises à jour) : limite les lignes retraitées après un plantage.
	 * @return bool true si la table est terminée.
	 * @throws Migration_Exception Erreur SQL bloquante.
	 */
	public function run_table( Db $db, $table, array &$state, Runtime $rt, $persist = null ) {
		if ( ! isset( $state['columns'] ) ) {
			$cols     = $db->describe( $table );
			$text     = array();
			$all      = array();
			$col_type = array();
			foreach ( $cols as $c ) {
				$all[]                  = $c['name'];
				$col_type[ $c['name'] ] = $c['type'];
				if ( Db::is_text_type( $c['type'] ) && ! Db::is_generated( $c ) ) {
					$text[] = $c['name'];
				}
			}
			$state            = array_merge(
				array(
					'columns' => $text,
					'all'     => $all,
					'pk'      => Db::primary_key( $cols ),
					'last'    => null,
					'offset'  => 0,
					'rows'    => 0,
					'changed' => 0,
					'batch'   => 100,
				),
				$state
			);
			$state['columns'] = $text;
		}
		if ( empty( $state['columns'] ) || empty( $this->pairs ) ) {
			return true;
		}
		$pk = $state['pk'];
		do {
			$batch = max( 10, (int) $state['batch'] );
			$t0    = microtime( true );
			if ( ! empty( $pk ) ) {
				$select = array_values( array_unique( array_merge( $pk, $state['columns'] ) ) );
				$where  = array( $this->like_filter( $state['columns'] ) );
				if ( null !== $state['last'] ) {
					$where[] = self::keyset_condition( $pk, $state['last'] );
				}
				$sql = 'SELECT ' . implode( ',', array_map( array( Db::class, 'ident' ), $select ) ) . ' FROM ' . Db::ident( $table )
					. ' WHERE ' . implode( ' AND ', $where )
					. ' ORDER BY ' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) ) . ' LIMIT ' . $batch;
			} else {
				$select = $state['all'];
				$sql    = 'SELECT ' . implode( ',', array_map( array( Db::class, 'ident' ), $select ) ) . ' FROM ' . Db::ident( $table )
					. ' LIMIT ' . (int) $state['offset'] . ',' . $batch;
			}
			$rows = $db->get_rows( $sql, true );
			if ( false === $rows ) {
				throw new Migration_Exception(
					sprintf( 'Erreur SQL sur la table %s : %s', $table, $db->last_error() ),
					'Vérifiez que l’utilisateur MySQL a les droits SELECT et UPDATE.',
					true
				);
			}
			$bytes = 0;
			foreach ( $rows as $row ) {
				foreach ( $row as $v ) {
					$bytes += strlen( (string) $v );
				}
				$set = array();
				foreach ( $state['columns'] as $col ) {
					if ( ! isset( $row[ $col ] ) ) {
						continue;
					}
					$new = $this->replace( $row[ $col ] );
					if ( $new !== $row[ $col ] ) {
						$set[] = Db::ident( $col ) . '=' . Db::literal( $new );
					}
				}
				if ( ! empty( $set ) ) {
					if ( ! empty( $pk ) ) {
						$cond = array();
						foreach ( $pk as $k ) {
							$cond[] = Db::ident( $k ) . '=' . Db::literal( $row[ $k ] );
						}
						$sql = 'UPDATE ' . Db::ident( $table ) . ' SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $cond ) . ' LIMIT 1';
					} else {
						$cond = array();
						foreach ( $state['all'] as $k ) {
							$cond[] = Db::ident( $k ) . ' <=> ' . Db::literal( $row[ $k ] );
						}
						$sql = 'UPDATE ' . Db::ident( $table ) . ' SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $cond ) . ' LIMIT 1';
					}
					if ( ! $db->query( $sql ) ) {
						throw new Migration_Exception(
							sprintf( 'Mise à jour impossible dans %s : %s', $table, $db->last_error() ),
							'Vérifiez les droits UPDATE de l’utilisateur MySQL et la valeur de max_allowed_packet.',
							true
						);
					}
					++$state['changed'];
				}
				++$state['rows'];
				if ( ! empty( $pk ) ) {
					$last = array();
					foreach ( $pk as $k ) {
						$last[] = $row[ $k ];
					}
					$state['last'] = $last;
				} else {
					++$state['offset'];
				}
				if ( $persist && ! empty( $set ) && 0 === $state['changed'] % 50 ) {
					call_user_func( $persist, $state );
				}
			}
			$fetched = count( $rows );
			unset( $rows );
			// Lot adaptatif : ~4 Mo de données lues et < 2 s par lot (lignes très volumineuses comprises).
			if ( $fetched > 0 ) {
				$ideal          = (int) ( 4194304 / max( 1, $bytes / $fetched ) );
				$elapsed        = microtime( true ) - $t0;
				$ideal          = $elapsed > 2 ? min( $ideal, (int) ( $batch / 2 ) ) : $ideal;
				$state['batch'] = max( 10, min( 5000, $ideal ) );
			}
			if ( $persist ) {
				call_user_func( $persist, $state );
			}
			if ( $fetched < $batch ) {
				return true;
			}
		} while ( ! $rt->should_stop() );
		return false;
	}

	/**
	 * Filtre SQL LIKE pour ne lire que les lignes candidates.
	 *
	 * @param string[] $columns Colonnes texte.
	 * @return string
	 */
	private function like_filter( array $columns ) {
		$or = array();
		foreach ( $columns as $col ) {
			foreach ( $this->needles as $needle ) {
				// Caractère d'échappement « | » : l'antislash des URL échappées en JSON (http:\/\/)
				// reste un caractère ordinaire (MySQL 5.7 ne trouvait pas « \\ » dans un motif LIKE).
				$pattern = str_replace( array( '|', '%', '_' ), array( '||', '|%', '|_' ), $needle );
				$or[]    = Db::ident( $col ) . " LIKE '%" . Db::escape( $pattern ) . "%' ESCAPE '|'";
			}
		}
		return '(' . implode( ' OR ', $or ) . ')';
	}

	/**
	 * Condition de pagination par clé : (a,b) > (x,y).
	 *
	 * @param string[] $pk   Colonnes de clé.
	 * @param array    $last Dernières valeurs.
	 * @return string
	 */
	private static function keyset_condition( array $pk, array $last ) {
		if ( 1 === count( $pk ) ) {
			return Db::ident( $pk[0] ) . ' > ' . Db::literal( $last[0] );
		}
		return '(' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) ) . ') > ('
			. implode( ',', array_map( array( Db::class, 'literal' ), $last ) ) . ')';
	}
}
