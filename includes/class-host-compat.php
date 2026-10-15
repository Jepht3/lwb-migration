<?php
/**
 * Particularités des hébergeurs : extensions « must-use » et drop-ins propres à la
 * plateforme, wp-config.php alimenté par des variables d'environnement, HTTPS derrière
 * un proxy, IIS, permissions des fichiers et restriction open_basedir.
 *
 * Fichier autonome : utilisé aussi par installer.php (sans WordPress).
 *
 * @package LwbMigration
 */

namespace LwbMigration;

defined( 'ABSPATH' ) || defined( 'LWB_MIGRATION_STANDALONE' ) || exit;

/**
 * Compatibilité hébergeurs.
 */
class Host_Compat {

	/**
	 * Extensions « must-use » installées par les hébergeurs (dossier ou fichier de mu-plugins/).
	 * Liées à leur plateforme, elles ne fonctionnent nulle part ailleurs.
	 */
	const MU_PLUGINS = array(
		// WP Engine.
		'wpengine-common',
		'wpengine-security-auditor.php',
		'wpe-wp-sign-on-plugin',
		'wpe-wp-sign-on-plugin.php',
		'wpe-cache-plugin',
		'wpe-cache-plugin.php',
		'wpe-update-source-selector',
		'wpe-update-source-selector.php',
		'wpe-elasticpress-autosuggest-logger',
		'wpe-elasticpress-autosuggest-logger.php',
		'force-strong-passwords',
		'slt-force-strong-passwords.php',
		'stop-long-comments.php',
		// Kinsta.
		'kinsta-mu-plugins',
		'kinsta-mu-plugins.php',
		// GoDaddy.
		'gd-system-plugin',
		'gd-system-plugin.php',
		// Pantheon.
		'pantheon',
		'pantheon.php',
		'pantheon-mu-plugin',
		// Bluehost, HostGator (Endurance / Newfold).
		'endurance-page-cache.php',
		'endurance-browser-cache.php',
		'endurance-php-edge.php',
		// WordPress.com (Atomic), Pressable.
		'wpcomsh',
		'wpcomsh-loader.php',
		// Nexcess, Liquid Web.
		'nexcess-mapps',
		'nexcess-mapps.php',
		// Local (Flywheel).
		'local-by-flywheel-live-link-helper.php',
	);

	/**
	 * Préfixes de noms réservés aux hébergeurs dans mu-plugins/.
	 */
	const MU_PREFIXES = array( 'wpengine', 'wpe-', 'kinsta-', 'pantheon-', 'siteground', 'sg-security', 'flywheel', 'hostinger', 'bluehost', 'endurance-', 'pressable', 'pagely', 'raidboxes', 'dreampress', 'cloudways', 'nexcess' );

	/**
	 * Noms génériques qui ne sont propres à l'hébergeur que si leur contenu le dit.
	 */
	const MU_GENERIC = array( 'mu-plugin.php' );

	/**
	 * Drop-ins de wp-content/ (remplacements de fichiers du cœur).
	 */
	const DROPINS = array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php', 'install.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' );

	/**
	 * Signature d'un fichier fourni par un hébergeur (lue dans ses premiers octets).
	 */
	const SIGNATURE = '/\b(?:WP ?Engine|wpengine|Kinsta|Pantheon|GoDaddy|gd-system|Flywheel|SiteGround|Hostinger|Pressable|Nexcess|Pagely|Endurance|Bluehost|wpcomsh|Raidboxes|DreamPress|Cloudways)\b/i';

	/**
	 * Permissions par défaut de WordPress (FS_CHMOD_FILE, FS_CHMOD_DIR).
	 */
	const FILE_MODE = 0644;
	const DIR_MODE  = 0755;

	// ------------------------------------------------------------------
	// Extensions « must-use » et drop-ins des hébergeurs.
	// ------------------------------------------------------------------

	/**
	 * Une entrée de mu-plugins/ appartient-elle à l'hébergeur ?
	 *
	 * @param string $name Nom du fichier ou du dossier (premier niveau de mu-plugins/).
	 * @param string $head Début du fichier (pour les noms génériques), '' si inconnu.
	 * @return bool
	 */
	public static function is_host_mu_plugin( $name, $head = '' ) {
		$name = strtolower( (string) $name );
		if ( in_array( $name, self::MU_PLUGINS, true ) ) {
			return true;
		}
		foreach ( self::MU_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return in_array( $name, self::MU_GENERIC, true ) && self::has_host_signature( $head );
	}

	/**
	 * Le contenu porte-t-il la marque d'un hébergeur ?
	 *
	 * @param string $head Début du fichier.
	 * @return bool
	 */
	public static function has_host_signature( $head ) {
		return '' !== (string) $head && (bool) preg_match( self::SIGNATURE, (string) $head );
	}

	/**
	 * Début d'un fichier (8 Ko).
	 *
	 * @param string $file Fichier.
	 * @return string
	 */
	public static function head( $file ) {
		if ( ! self::path_allowed( $file ) || ! is_file( $file ) ) {
			return '';
		}
		$head = @file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $head ? '' : $head;
	}

	/**
	 * Fichiers et dossiers de l'hébergeur à exclure d'un export.
	 *
	 * @param string $content_dir Dossier wp-content.
	 * @return string[] Chemins absolus normalisés.
	 */
	public static function host_paths( $content_dir ) {
		$content = rtrim( str_replace( '\\', '/', (string) $content_dir ), '/' );
		$out     = array();
		$mu      = $content . '/mu-plugins';
		$items   = self::path_allowed( $mu ) && is_dir( $mu ) ? @scandir( $mu ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$head = in_array( strtolower( $item ), self::MU_GENERIC, true ) ? self::head( $mu . '/' . $item ) : '';
			if ( self::is_host_mu_plugin( $item, $head ) ) {
				$out[] = $mu . '/' . $item;
			}
		}
		foreach ( self::DROPINS as $dropin ) {
			if ( self::has_host_signature( self::head( $content . '/' . $dropin ) ) ) {
				$out[] = $content . '/' . $dropin;
			}
		}
		return $out;
	}

	/**
	 * Fichier de la destination à ne jamais écraser (extension ou drop-in de l'hébergeur).
	 *
	 * @param string $rel  Chemin relatif à la racine du site (wp-content/…).
	 * @param string $root Racine du site de destination.
	 * @return bool
	 */
	public static function is_protected_destination( $rel, $root ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( preg_match( '#^wp-content/mu-plugins/([^/]+)#', $rel, $m ) ) {
			$generic = in_array( strtolower( $m[1] ), self::MU_GENERIC, true );
			if ( ! $generic && self::is_host_mu_plugin( $m[1] ) ) {
				return true; // Extension de l'hébergeur (source ou destination) : jamais installée ni écrasée.
			}
			return $generic && self::is_host_mu_plugin( $m[1], self::head( rtrim( $root, '/' ) . '/wp-content/mu-plugins/' . $m[1] ) );
		}
		if ( preg_match( '#^wp-content/([^/]+\.php)$#', $rel, $m ) && in_array( strtolower( $m[1] ), self::DROPINS, true ) ) {
			return self::has_host_signature( self::head( rtrim( $root, '/' ) . '/' . $rel ) );
		}
		return false;
	}

	// ------------------------------------------------------------------
	// wp-config.php.
	// ------------------------------------------------------------------

	/**
	 * Valeur littérale d'un define() dans un fichier PHP.
	 *
	 * @param string $text     Code.
	 * @param string $constant Constante.
	 * @return string|null
	 */
	public static function read_define( $text, $constant ) {
		$re = '/define\s*\(\s*([\'"])' . preg_quote( $constant, '/' ) . '\1\s*,\s*([\'"])((?:(?!\2)[^\\\\]|\\\\.)*)\2\s*\)/';
		if ( preg_match( $re, $text, $m ) ) {
			return "'" === $m[2] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $m[3] ) : stripcslashes( $m[3] );
		}
		return null;
	}

	/**
	 * Le wp-config.php lit-il ses accès à la base ailleurs qu'en clair (variables
	 * d'environnement, fichier .env, fichier inclus) ? Bedrock, Pantheon, Platform.sh, Docker…
	 *
	 * @param string $text Contenu de wp-config.php.
	 * @return bool
	 */
	public static function config_uses_env( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return false;
		}
		if ( preg_match( '/\bgetenv(?:_docker)?\s*\(|\$_ENV\s*\[|\$_SERVER\s*\[\s*[\'"](?:DB_|MYSQL|DATABASE|PLATFORM_)|\benv\s*\(|Dotenv|PLATFORM_RELATIONSHIPS|PANTHEON_ENVIRONMENT/i', $text ) ) {
			return true;
		}
		return null === self::read_define( $text, 'DB_NAME' );
	}

	/**
	 * Analyse un fichier .env (KEY=valeur, guillemets, « export », commentaires).
	 *
	 * @param string $text Contenu.
	 * @return array<string,string>
	 */
	public static function parse_dotenv( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( ! preg_match( '/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m ) ) {
				continue;
			}
			$value = trim( $m[2] );
			if ( '' !== $value && ( '"' === $value[0] || "'" === $value[0] ) ) {
				$quote = $value[0];
				$end   = strrpos( $value, $quote );
				$value = $end > 0 ? substr( $value, 1, $end - 1 ) : substr( $value, 1 );
				if ( '"' === $quote ) {
					$value = str_replace( array( '\\n', '\\"', '\\\\' ), array( "\n", '"', '\\' ), $value );
				}
			} else {
				$value = trim( preg_replace( '/\s+#.*$/', '', $value ) );
			}
			$out[ $m[1] ] = $value;
		}
		return $out;
	}

	/**
	 * Accès à la base fournis par l'environnement (variables, .env, Platform.sh, Docker).
	 *
	 * @param array $env Variables (getenv(), $_ENV, puis contenu du .env).
	 * @return array host, name, user, password, prefix (clés présentes seulement si connues).
	 */
	public static function env_db_config( array $env ) {
		$get = function ( array $keys ) use ( $env ) {
			foreach ( $keys as $key ) {
				if ( isset( $env[ $key ] ) && '' !== (string) $env[ $key ] ) {
					return (string) $env[ $key ];
				}
			}
			return null;
		};
		$out = array();
		// Platform.sh / Upsun : relations encodées en base64 (JSON).
		if ( ! empty( $env['PLATFORM_RELATIONSHIPS'] ) ) {
			$rel = json_decode( (string) base64_decode( (string) $env['PLATFORM_RELATIONSHIPS'], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- format imposé par la plateforme.
			if ( is_array( $rel ) ) {
				foreach ( $rel as $services ) {
					$db = is_array( $services ) && isset( $services[0] ) && is_array( $services[0] ) ? $services[0] : array();
					if ( isset( $db['scheme'] ) && 'mysql' === $db['scheme'] ) {
						$out = array(
							'host'     => $db['host'] . ( ! empty( $db['port'] ) ? ':' . $db['port'] : '' ),
							'name'     => isset( $db['path'] ) ? (string) $db['path'] : '',
							'user'     => isset( $db['username'] ) ? (string) $db['username'] : '',
							'password' => isset( $db['password'] ) ? (string) $db['password'] : '',
						);
						break;
					}
				}
			}
		}
		// Bedrock : DATABASE_URL=mysql://utilisateur:motdepasse@hote:port/base.
		$url = $get( array( 'DATABASE_URL' ) );
		if ( empty( $out ) && null !== $url ) {
			$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fichier autonome.
			if ( is_array( $p ) && isset( $p['host'] ) ) {
				$out = array(
					'host'     => $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ),
					'name'     => isset( $p['path'] ) ? ltrim( $p['path'], '/' ) : '',
					'user'     => isset( $p['user'] ) ? rawurldecode( $p['user'] ) : '',
					'password' => isset( $p['pass'] ) ? rawurldecode( $p['pass'] ) : '',
				);
			}
		}
		$map = array(
			'host'     => array( 'DB_HOST', 'WORDPRESS_DB_HOST', 'MYSQL_HOST' ),
			'name'     => array( 'DB_NAME', 'WORDPRESS_DB_NAME', 'MYSQL_DATABASE' ),
			'user'     => array( 'DB_USER', 'WORDPRESS_DB_USER', 'MYSQL_USER' ),
			'password' => array( 'DB_PASSWORD', 'WORDPRESS_DB_PASSWORD', 'MYSQL_PASSWORD' ),
			'prefix'   => array( 'DB_PREFIX', 'WORDPRESS_TABLE_PREFIX', 'TABLE_PREFIX' ),
		);
		foreach ( $map as $key => $names ) {
			$value = $get( $names );
			if ( null !== $value && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $value;
			}
		}
		$port = $get( array( 'DB_PORT' ) );
		if ( null !== $port && isset( $out['host'] ) && false === strpos( $out['host'], ':' ) ) {
			$out['host'] .= ':' . $port; // Pantheon : hôte et port séparés.
		}
		return $out;
	}

	/**
	 * Préfixe de tables d'un wp-config.php (littéral, valeur de repli ou environnement).
	 *
	 * @param string $text Contenu de wp-config.php.
	 * @param array  $env  Variables d'environnement.
	 * @return string|null
	 */
	public static function table_prefix( $text, array $env = array() ) {
		if ( preg_match( '/\$table_prefix\s*=\s*([\'"])([A-Za-z0-9_]*)\1\s*;/', (string) $text, $m ) ) {
			return $m[2];
		}
		foreach ( array( 'DB_PREFIX', 'WORDPRESS_TABLE_PREFIX', 'TABLE_PREFIX' ) as $key ) {
			if ( ! empty( $env[ $key ] ) && preg_match( '/^[A-Za-z0-9_]+$/', (string) $env[ $key ] ) ) {
				return (string) $env[ $key ];
			}
		}
		if ( preg_match( '/\$table_prefix\s*=[^;]*?\?:\s*([\'"])([A-Za-z0-9_]+)\1/', (string) $text, $m ) ) {
			return $m[2]; // « env( 'DB_PREFIX' ) ?: 'wp_' » sans variable définie.
		}
		return null;
	}

	/**
	 * Insère une ligne avant « That's all, stop editing » ou le chargement de wp-settings.php.
	 *
	 * @param string $text Code.
	 * @param string $line Ligne.
	 * @return string
	 */
	public static function insert_before_end( $text, $line ) {
		foreach ( array( "/* That's all", '/** Absolute path', "if ( ! defined( 'ABSPATH' ) )", "if ( !defined('ABSPATH') )", 'require_once ABSPATH', 'require_once(ABSPATH' ) as $marker ) {
			$pos = strpos( $text, $marker );
			if ( false !== $pos ) {
				return substr( $text, 0, $pos ) . $line . "\n\n" . substr( $text, $pos );
			}
		}
		return rtrim( $text ) . "\n" . $line . "\n";
	}

	/**
	 * Fixe le préfixe de tables sans rien changer d'autre au wp-config.php.
	 *
	 * Affectation littérale : remplacée. Affectation calculée (variable d'environnement) :
	 * complétée par une affectation littérale juste après. Absente : ajoutée avant le
	 * chargement de WordPress.
	 *
	 * @param string $text   Contenu de wp-config.php.
	 * @param string $prefix Préfixe.
	 * @return string
	 */
	public static function set_table_prefix( $text, $prefix ) {
		$line = '$table_prefix = ' . var_export( (string) $prefix, true ) . ';'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- génération de code.
		if ( preg_match( '/\$table_prefix\s*=\s*([\'"])[A-Za-z0-9_]*\1\s*;/', $text ) ) {
			return preg_replace( '/\$table_prefix\s*=\s*([\'"])[A-Za-z0-9_]*\1\s*;/', str_replace( '$', '\\$', $line ), $text, 1 );
		}
		if ( preg_match( '/\$table_prefix\s*=[^;]*;[^\n]*/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$end = $m[0][1] + strlen( $m[0][0] );
			return substr( $text, 0, $end ) . "\n" . $line . ' // Lwb Migration.' . substr( $text, $end );
		}
		return self::insert_before_end( $text, $line );
	}

	// ------------------------------------------------------------------
	// HTTPS derrière un proxy, IIS.
	// ------------------------------------------------------------------

	/**
	 * La requête arrive-t-elle en HTTPS par un proxy (Cloudflare en SSL flexible,
	 * répartiteur de charge) alors que PHP la voit en HTTP ?
	 *
	 * @param array $server Variables $_SERVER.
	 * @return bool
	 */
	public static function https_via_proxy( array $server ) {
		if ( ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] ) ) {
			return false;
		}
		return self::proxy_says_https( $server );
	}

	/**
	 * Les en-têtes d'un proxy annoncent-ils HTTPS ?
	 *
	 * @param array $server Variables $_SERVER.
	 * @return bool
	 */
	public static function proxy_says_https( array $server ) {
		if ( isset( $server['HTTP_X_FORWARDED_PROTO'] ) && false !== stripos( (string) $server['HTTP_X_FORWARDED_PROTO'], 'https' ) ) {
			return true;
		}
		if ( isset( $server['HTTP_CF_VISITOR'] ) && false !== stripos( (string) $server['HTTP_CF_VISITOR'], 'https' ) ) {
			return true;
		}
		if ( isset( $server['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( (string) $server['HTTP_X_FORWARDED_SSL'] ) ) {
			return true;
		}
		return isset( $server['HTTP_FRONT_END_HTTPS'] ) && 'on' === strtolower( (string) $server['HTTP_FRONT_END_HTTPS'] );
	}

	/**
	 * Code à placer dans wp-config.php pour que WordPress reconnaisse HTTPS derrière un proxy.
	 *
	 * @return string
	 */
	public static function proxy_https_snippet() {
		return "// Lwb Migration : HTTPS assuré par un proxy (Cloudflare, répartiteur de charge), évite les boucles de redirection.\n"
			. "if ( ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && false !== stripos( \$_SERVER['HTTP_X_FORWARDED_PROTO'], 'https' ) ) || ( isset( \$_SERVER['HTTP_CF_VISITOR'] ) && false !== stripos( \$_SERVER['HTTP_CF_VISITOR'], 'https' ) ) || ( isset( \$_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( \$_SERVER['HTTP_X_FORWARDED_SSL'] ) ) ) {\n"
			. "\t\$_SERVER['HTTPS'] = 'on';\n"
			. "}\n";
	}

	/**
	 * Ajoute la reconnaissance du HTTPS par proxy au wp-config.php (si absente).
	 *
	 * @param string $text Contenu de wp-config.php.
	 * @return string
	 */
	public static function add_proxy_https( $text ) {
		if ( preg_match( '/HTTP_X_FORWARDED_PROTO|HTTP_CF_VISITOR|HTTP_X_FORWARDED_SSL/', $text ) ) {
			return $text; // Déjà géré.
		}
		$count = 0;
		$out   = preg_replace( '/^<\?php[ \t]*\r?\n/', "<?php\n" . str_replace( '$', '\\$', self::proxy_https_snippet() ) . "\n", $text, 1, $count );
		return 1 === $count ? $out : self::insert_before_end( $text, self::proxy_https_snippet() );
	}

	/**
	 * Le serveur web est-il IIS ?
	 *
	 * @param array $server Variables $_SERVER.
	 * @return bool
	 */
	public static function is_iis( array $server ) {
		$software = isset( $server['SERVER_SOFTWARE'] ) ? (string) $server['SERVER_SOFTWARE'] : '';
		return false !== stripos( $software, 'Microsoft-IIS' ) || false !== stripos( $software, 'ExpressionDevServer' );
	}

	/**
	 * web.config de WordPress pour IIS (module URL Rewrite).
	 *
	 * @return string
	 */
	public static function web_config() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. "<configuration>\n"
			. "  <system.webServer>\n"
			. "    <rewrite>\n"
			. "      <rules>\n"
			. "        <rule name=\"WordPress\" patternSyntax=\"Wildcard\">\n"
			. "          <match url=\"*\" />\n"
			. "          <conditions>\n"
			. "            <add input=\"{REQUEST_FILENAME}\" matchType=\"IsFile\" negate=\"true\" />\n"
			. "            <add input=\"{REQUEST_FILENAME}\" matchType=\"IsDirectory\" negate=\"true\" />\n"
			. "          </conditions>\n"
			. "          <action type=\"Rewrite\" url=\"index.php\" />\n"
			. "        </rule>\n"
			. "      </rules>\n"
			. "    </rewrite>\n"
			. "  </system.webServer>\n"
			. "</configuration>\n";
	}

	// ------------------------------------------------------------------
	// Permissions et open_basedir.
	// ------------------------------------------------------------------

	/**
	 * Permissions des fichiers et dossiers (FS_CHMOD_FILE / FS_CHMOD_DIR du wp-config.php).
	 *
	 * @param string $text Contenu de wp-config.php ('' : valeurs par défaut).
	 * @return int[] array( fichiers, dossiers ).
	 */
	public static function fs_modes( $text ) {
		$modes = array(
			'FS_CHMOD_FILE' => self::FILE_MODE,
			'FS_CHMOD_DIR'  => self::DIR_MODE,
		);
		foreach ( array_keys( $modes ) as $const ) {
			if ( preg_match( '/define\s*\(\s*[\'"]' . $const . '[\'"]\s*,\s*([^;]*?)\)\s*;/', (string) $text, $m ) && preg_match( '/\b0([0-7]{3})\b/', $m[1], $o ) ) {
				$mode = octdec( $o[1] );
				// Le propriétaire doit pouvoir lire et écrire (et traverser les dossiers).
				$need = 'FS_CHMOD_DIR' === $const ? 0700 : 0600;
				if ( ( $mode & $need ) === $need ) {
					$modes[ $const ] = $mode;
				}
			}
		}
		return array( $modes['FS_CHMOD_FILE'], $modes['FS_CHMOD_DIR'] );
	}

	/**
	 * Le chemin est-il accessible malgré la restriction open_basedir ?
	 *
	 * @param string      $path        Chemin absolu.
	 * @param string|null $restriction Valeur d'open_basedir (null : réglage courant).
	 * @return bool
	 */
	public static function path_allowed( $path, $restriction = null ) {
		$restriction = null === $restriction ? (string) ini_get( 'open_basedir' ) : (string) $restriction;
		if ( '' === trim( $restriction ) ) {
			return true;
		}
		$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		foreach ( explode( PATH_SEPARATOR, $restriction ) as $dir ) {
			$dir = str_replace( '\\', '/', trim( $dir ) );
			if ( '' === $dir ) {
				continue;
			}
			if ( '.' === $dir ) {
				$cwd = getcwd();
				$dir = false === $cwd ? '' : str_replace( '\\', '/', $cwd ) . '/';
				if ( '' === $dir ) {
					continue;
				}
			}
			// Comme PHP : simple préfixe, sauf avec une barre finale (dossier exact).
			$subject = '/' === substr( $dir, -1 ) ? $path . '/' : $path;
			if ( 0 === strpos( $subject, $dir ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dossier temporaire utilisable (jamais hors des dossiers autorisés par open_basedir).
	 *
	 * @param string[] $preferred Dossiers essayés en premier.
	 * @return string '' si aucun.
	 */
	public static function temp_dir( array $preferred = array() ) {
		$candidates   = $preferred;
		$candidates[] = sys_get_temp_dir();
		$candidates[] = (string) ini_get( 'upload_tmp_dir' );
		foreach ( $candidates as $dir ) {
			$dir = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
			if ( '' !== $dir && self::path_allowed( $dir ) && is_dir( $dir ) && is_writable( $dir ) ) {
				return $dir;
			}
		}
		return '';
	}
}
