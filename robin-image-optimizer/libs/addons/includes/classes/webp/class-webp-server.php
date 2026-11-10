<?php
/**
 * Web server rules for WebP and AVIF delivery.
 *
 * @package Robin_Image_Optimizer
 */

namespace WRIO\WEBP;

/**
 * Writes the web server rules for WebP and AVIF delivery.
 *
 * @version       1.0
 */
class Server {

	/**
	 * Site option set when the .htaccess rules wait for a web request, which knows the server type.
	 */
	const PENDING_SYNC_OPTION = 'wrio_htaccess_sync_pending';

	/**
	 * Returns the server home path.
	 *
	 * @since  1.0.4
	 * @return string
	 */
	public static function get_home_path() {
		$home    = set_url_scheme( get_option( 'home' ), 'http' );
		$siteurl = set_url_scheme( get_option( 'siteurl' ), 'http' );

		if ( ! empty( $home ) && 0 !== strcasecmp( $home, $siteurl ) ) {
			$wp_path_rel_to_home = str_ireplace( $home, '', $siteurl ); /* $siteurl - $home */
			$pos                 = strripos( str_replace( '\\', '/', $_SERVER['SCRIPT_FILENAME'] ), trailingslashit( $wp_path_rel_to_home ) );

			if ( false !== $pos ) {
				$home_path = substr( $_SERVER['SCRIPT_FILENAME'], 0, $pos );
				$home_path = trim( $home_path, '/\\' ) . DIRECTORY_SEPARATOR;

			} else {
				$wp_path_rel_to_home = DIRECTORY_SEPARATOR . trim( $wp_path_rel_to_home, '/\\' ) . DIRECTORY_SEPARATOR;

				$real_apth = realpath( ABSPATH ) . DIRECTORY_SEPARATOR;

				$pos       = strpos( $real_apth, $wp_path_rel_to_home );
				$home_path = substr( $real_apth, 0, $pos );
				$home_path = trim( $home_path, '/\\' ) . DIRECTORY_SEPARATOR;
			}
		} else {
			$home_path = ABSPATH;
		}

		$home_path = trim( $home_path, '\\/ ' );

		// not for windows
		if ( DIRECTORY_SEPARATOR !== '\\' ) {
			$home_path = DIRECTORY_SEPARATOR . $home_path;
		}

		return $home_path;
	}

	/**
	 * Server signature; empty on CLI, cron and test runs where the header is absent.
	 *
	 * @return string
	 */
	public static function server_software() {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Return if the server run Apache
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function is_apache() {
		$is_apache = ( strpos( self::server_software(), 'Apache' ) !== false || strpos( self::server_software(), 'LiteSpeed' ) !== false );

		return $is_apache;
	}

	/**
	 * Return if the server run on nginx
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function is_nginx() {
		$is_nginx = ( strpos( self::server_software(), 'nginx' ) !== false );

		return $is_nginx;
	}

	/**
	 * Regular expression that matches an Accept header which rejects a format with "q=0".
	 *
	 * @param string $format webp or avif.
	 *
	 * @return string
	 */
	private static function rejects( $format ) {
		return '(^|,)[ \t]*image/' . $format . '[ \t]*(;[^,;]*)*;[ \t]*q[ \t]*=[ \t]*0(\.0*)?[ \t]*(;|,|$)';
	}

	/**
	 * Rules that serve the AVIF and WebP files at the original JPEG and PNG URLs on nginx, which reads no .htaccess
	 * file: the site owner adds them to the server block. Only the Accept header decides, and every answer sends
	 * Vary: Accept.
	 *
	 * @return string
	 */
	public static function nginx_rules() {
		$avif = \WRIO_Formats::is_avif_enabled();
		// Only enabled formats, as in the Apache rules: converted files can stay on disk after conversion is turned off.
		$webp = \WRIO_Formats::is_webp_enabled();

		return implode(
			"\n",
			array_filter(
				[
					'# BEGIN Robin Image Optimizer: WebP and AVIF by the Accept header',
					// Server level: an "if" in a location answers with its own configuration, which has no try_files.
					// A suffix no file has: an empty one would make the next candidate the original, which exists.
					$avif ? 'set $wrio_avif ".wrio-none";' : '',
					$webp ? 'set $wrio_webp ".wrio-none";' : '',
					$avif ? 'if ($http_accept ~* "image/avif") { set $wrio_avif ".avif"; }' : '',
					// "q=0" rejects a format, as in the Apache rules.
					$avif ? 'if ($http_accept ~* "' . self::rejects( 'avif' ) . '") { set $wrio_avif ".wrio-none"; }' : '',
					$webp ? 'if ($http_accept ~* "image/webp") { set $wrio_webp ".webp"; }' : '',
					$webp ? 'if ($http_accept ~* "' . self::rejects( 'webp' ) . '") { set $wrio_webp ".wrio-none"; }' : '',
					'location ~* \.(?:jpe?g|png)$ {',
					'    add_header Vary Accept;',
					'    try_files ' . ( $avif ? '$uri$wrio_avif ' : '' ) . ( $webp ? '$uri$wrio_webp ' : '' ) . '$uri =404;',
					'}',
					$avif ? '# nginx before 1.24 needs "image/avif avif;" in mime.types.' : '',
					'# END Robin Image Optimizer',
				]
			)
		);
	}

	/**
	 * Rules that deny web access to the backups and the logs on nginx, which ignores the .htaccess files of
	 * wrio_protect_dir(). A "^~" prefix location wins over the regular expression locations, also the image one above.
	 *
	 * @return string
	 */
	public static function nginx_deny_rules() {
		// ponytail: the folders of this site only; a "wbcr/rio/backup/backup_dir" filter outside uploads needs its own rule.
		$uploads = (string) wp_parse_url( wp_upload_dir( null, false )['baseurl'], PHP_URL_PATH );
		// A prefix without the secret key of the log folder: it covers every key and the old folder without one.
		$logs = (string) wp_parse_url( content_url( 'logs/wbcr_image_optimizer' ), PHP_URL_PATH );

		return implode(
			"\n",
			[
				'# BEGIN Robin Image Optimizer: deny web access to backups and logs',
				'location ^~ ' . untrailingslashit( $uploads ) . '/' . \WIO_Backup::BACKUP_DIR_NAME . '/ { deny all; }',
				'location ^~ ' . $logs . ' { deny all; }',
				'# END Robin Image Optimizer',
			]
		);
	}

	/**
	 * Return if the server run on IIS
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function is_iss() {
		$is_IIS = ! static::is_apache() && ( strpos( self::server_software(), 'Microsoft-IIS' ) !== false || strpos( self::server_software(), 'ExpressionDevServer' ) !== false );

		return $is_IIS;
	}

	/**
	 * Return if the server run on IIS version 7 and up
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function is_iis7() {
		$is_iis7 = static::is_iss() && intval( substr( self::server_software(), strpos( self::server_software(), 'Microsoft-IIS/' ) + 14 ) ) >= 7;

		return $is_iis7;
	}

	/**
	 * Is permalink enabled?
	 *
	 * @since  1.0.4
	 * @return bool
	 * @global \WP_Rewrite $wp_rewrite
	 */
	public static function is_permalink() {
		global $wp_rewrite;

		if ( ! isset( $wp_rewrite ) || ! is_object( $wp_rewrite ) || ! $wp_rewrite->using_permalinks() ) {
			return false;
		}

		return true;
	}

	/**
	 * Return whatever the htaccess config file is writable
	 *
	 * @param string $htaccess_file Path to the .htaccess file.
	 * @since  1.0.4
	 * @return bool
	 */
	public static function is_writable_htaccess( $htaccess_file ) {
		if ( ( ! file_exists( $htaccess_file ) && static::is_permalink() ) || is_writable( $htaccess_file ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return whatever the web.config config file is writable
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function is_writable_webconfig_file() {
		$home_path       = static::get_home_path();
		$web_config_file = $home_path . 'web.config';

		if ( ( ! file_exists( $web_config_file ) && self::is_permalink() ) || win_is_writable( $web_config_file ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks whether the mod_rewrite Apache module is loaded.
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function got_mod_rewrite() {
		if ( self::apache_mod_loaded( 'mod_rewrite', true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Does the specified module exist in the Apache config?
	 *
	 * @since  1.0.4
	 *
	 * @param string $mod       The module, e.g. mod_rewrite.
	 * @param bool   $default   Optional. The default return value if the module is not found. Default false.
	 *
	 * @return bool Whether the specified module is loaded.
	 * @global bool  $is_apache
	 */
	public static function apache_mod_loaded( $mod, $default = false ) {
		if ( ! static::is_apache() ) {
			return false;
		}

		if ( function_exists( 'apache_get_modules' ) ) {
			$mods = apache_get_modules();
			if ( in_array( $mod, $mods, true ) ) {
				return true;
			}
		} elseif ( getenv( 'HTTP_MOD_REWRITE' ) !== false ) {
			$mod_found = getenv( 'HTTP_MOD_REWRITE' ) === 'On' ? true : false;

			return $mod_found;
		} elseif ( function_exists( 'phpinfo' ) && false === strpos( ini_get( 'disable_functions' ), 'phpinfo' ) ) {
			ob_start();
			phpinfo( 8 );
			$phpinfo = ob_get_clean();
			if ( false !== strpos( $phpinfo, $mod ) ) {
				return true;
			}
		}

		return $default;
	}

	/**
	 * Return whatever server using the .htaccess config file
	 *
	 * @since  1.0.4
	 * @return bool
	 */
	public static function server_use_htaccess() {
		$home_path     = static::get_home_path();
		$htaccess_file = $home_path . DIRECTORY_SEPARATOR . '.htaccess';

		if ( ( ! file_exists( $htaccess_file ) && is_writable( $home_path ) && static::is_permalink() ) || is_writable( $htaccess_file ) ) {
			if ( static::got_mod_rewrite() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * After the settings are saved: write the WebP redirect rules when the
	 * redirect delivery mode is active on Apache, clear them otherwise.
	 *
	 * @param mixed $plugin Plugin that saved.
	 * @param mixed $page   Page object with an id.
	 *
	 * @return void
	 */
	public static function sync_htaccess_rules_after_save( $plugin, $page ) {
		if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'getPluginName' ) || \WRIO_Plugin::app()->getPluginName() !== $plugin->getPluginName() ) {
			return;
		}
		if ( ! is_object( $page ) || ! isset( $page->id ) || 'rio_settings' !== $page->id ) {
			return;
		}
		self::sync_htaccess_rules();
	}

	/**
	 * The types of the converted files. Apache names a file by each of its extensions: without these, a server whose
	 * mime.types has no AVIF serves photo.jpg.avif as image/jpeg.
	 */
	const MIME_RULES = '<IfModule mod_mime.c>
  AddType image/webp .webp
  AddType image/avif .avif
</IfModule>';

	/**
	 * Write the redirect rules when the redirect delivery mode is active on Apache, and only the types of the
	 * converted files in the other modes, which link those files. Clear them when delivery is off. A license change
	 * adds or removes the AVIF rule.
	 *
	 * @return void
	 */
	public static function sync_htaccess_rules(): void {
		// WP-CLI and system cron have no server signature, so the server type is unknown there. A plugin update
		// from WP-CLI runs the migrations in such a process: the next web request writes the rules.
		if ( '' === self::server_software() ) {
			update_site_option( self::PENDING_SYNC_OPTION, 1 );
			return;
		}

		if ( ! self::is_apache() || ! self::server_use_htaccess() ) {
			return;
		}

		global $wpdb;

		// One request writes the rules at a time, else a request that read the settings before a save can write
		// its rules after the rules of the save. A request that finds the lock leaves the sync to the next request.
		// MySQL frees the lock when the request ends. Only 0 means another request holds it: SQLite sites return
		// other values and run without the lock.
		$lock = 'wrio_htaccess_' . md5( $wpdb->dbname . $wpdb->base_prefix );

		if ( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			update_site_option( self::PENDING_SYNC_OPTION, 1 );
			return;
		}

		try {
			// Read the settings that another request saved after this one loaded the options.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );

			foreach ( [ 'convert_webp_format', 'convert_avif_format', 'webp_delivery_mode' ] as $option ) {
				wp_cache_delete( \WRIO_Plugin::app()->getOptionName( $option ), 'options' );
				wp_cache_delete( get_current_network_id() . ':' . \WRIO_Plugin::app()->getOptionName( $option ), 'site-options' );
			}

			if ( ! HTML\Delivery::should_use_converted_images() ) {
				self::htaccess_clear_webp_rules();
			} elseif ( HTML\Delivery::is_redirect_delivery_mode() ) {
				self::htaccess_update_webp_rules();
			} else {
				static::write_rules( self::MIME_RULES );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Writes the rules that a process with no server signature could not write.
	 *
	 * @return void
	 */
	public static function sync_pending_htaccess_rules(): void {
		if ( '' === self::server_software() || ! get_site_option( self::PENDING_SYNC_OPTION ) ) {
			return;
		}

		delete_site_option( self::PENDING_SYNC_OPTION );
		self::sync_htaccess_rules();
	}

	/**
	 * Cleans webp rules from htaccess file. Use when deactivating a plugin
	 * or turn off webp support option.
	 *
	 * @since  1.0.4
	 * @return void
	 */
	public static function htaccess_clear_webp_rules() {
		static::write_rules( '' );
	}


	/**
	 * Add webp rules in htaccess file. Use when activating a plugin
	 * or turn on webp support option.
	 *
	 * @since  @since  1.0.4
	 *
	 * @return void
	 */
	public static function htaccess_update_webp_rules() {
		// Only enabled formats: converted files can stay on disk after conversion is turned off.
		$avif = ! \WRIO_Formats::is_avif_enabled() ? '' : '
  ##### AVIF first, for the browsers that accept it (ex. test.jpg.avif) #####
  RewriteCond %{HTTP_ACCEPT} image/avif [NC]
  RewriteCond %{HTTP_ACCEPT} "!(^|,)[ \t]*image/avif[ \t]*(;[^,;]*)*;[ \t]*q[ \t]*=[ \t]*0(?:\.0*)?[ \t]*(;|,|$)" [NC]
  RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png)$ [NC]
  RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.avif -f
  RewriteRule ^(.+)$ $1.avif [NC,T=image/avif,L]
';

		$webp = ! \WRIO_Formats::is_webp_enabled() ? '' : '
  ##### TRY FIRST the file appended with .webp (ex. test.jpg.webp) #####
  # Does the browser accept webp? Only the Accept header decides: Vary: Accept
  # cannot describe a choice made from the user agent.
  RewriteCond %{HTTP_ACCEPT} image/webp [NC]
  RewriteCond %{HTTP_ACCEPT} "!(^|,)[ \t]*image/webp[ \t]*(;[^,;]*)*;[ \t]*q[ \t]*=[ \t]*0(?:\.0*)?[ \t]*(;|,|$)" [NC]
  # AND is the request a jpg or png?
  RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png)$ [NC]
  # AND does a .ext.webp image exist?
  RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f
  # THEN send the webp image
  RewriteRule ^(.+)$ $1.webp [NC,T=image/webp,L]

  ##### IF NOT, try the file with replaced extension (test.webp) #####
  RewriteCond %{HTTP_ACCEPT} image/webp [NC]
  RewriteCond %{HTTP_ACCEPT} "!(^|,)[ \t]*image/webp[ \t]*(;[^,;]*)*;[ \t]*q[ \t]*=[ \t]*0(?:\.0*)?[ \t]*(;|,|$)" [NC]
  # AND is the request a jpg or png? (also grab the basepath %1 to match in the next rule)
  RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png)$ [NC]
  # AND does a .ext.webp image exist?
  RewriteCond %{DOCUMENT_ROOT}/%1.webp -f
  # THEN send the webp image
  RewriteRule (.+)\.(?:jpe?g|png)$ $1.webp [NC,T=image/webp,L]
';

		$rules = '
<IfModule mod_rewrite.c>
  RewriteEngine On

  # Every JPEG or PNG answer depends on the Accept header, also the original. A rule that reads it
  # and always applies makes mod_rewrite send Vary: Accept, also on servers without mod_headers.
  RewriteCond %{HTTP_ACCEPT} ^(.*)$
  RewriteRule \.(?:jpe?g|png)$ - [NC,E=WRIO_ACCEPT:%1]
' . $avif . '
' . $webp . '

</IfModule>
<IfModule mod_headers.c>
  # The answer for a JPEG or PNG URL depends on the Accept header, also when the original is served,
  # so caches and CDNs must keep one copy per format. It does not depend on a rewrite env var, which
  # some servers (LiteSpeed) do not set.
  <FilesMatch "(?i)\.(jpe?g|png|webp|avif)$">
    Header merge Vary Accept
  </FilesMatch>
</IfModule>

' . self::MIME_RULES . '
        ';

		static::write_rules( $rules );
	}

	/**
	 * Write the rules block of the plugin into the .htaccess files of the site root, wp-content and uploads. Empty
	 * rules remove the block.
	 *
	 * @param string $rules Rules between the markers.
	 *
	 * @return void
	 */
	protected static function write_rules( $rules ) {
		$wp_upload_dir = wp_upload_dir();

		static::insert_with_markers( static::get_home_path() . DIRECTORY_SEPARATOR . '.htaccess', $rules );

		if ( isset( $wp_upload_dir['error'] ) && false !== $wp_upload_dir['error'] ) {
			\WRIO_Plugin::app()->logger->error( 'It is not possible to update webp rules for htaccess, because upload dir is not writable.' );
		} else {
			static::insert_with_markers( trailingslashit( $wp_upload_dir['basedir'] ) . '.htaccess', $rules );
		}

		static::insert_with_markers( trailingslashit( WP_CONTENT_DIR ) . '.htaccess', $rules );
	}

	/**
	 * Writes the plugin's rules block into an .htaccess file, logging why when it cannot.
	 *
	 * @param string $file_path Path to the .htaccess file.
	 * @param string $content   Rules to place between the markers; empty removes them.
	 *
	 * @return void
	 */
	public static function insert_with_markers( $file_path, $content ) {
		// Settings are saved over REST too, where wp-admin helpers are not loaded.
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! static::is_writable_htaccess( $file_path ) ) {
			\WRIO_Plugin::app()->logger->error( sprintf( 'It is not possible to update webp rules for htaccess, because file (%s) is not writable.', $file_path ) );
		} else {
			if ( ! static::got_mod_rewrite() ) {
				\WRIO_Plugin::app()->logger->error( "It isn't possible to update webp rules for htaccess, because mode rewrite is unsupported." );

				return;
			}

			if ( ! insert_with_markers( $file_path, 'Robin Image Optimizer Webp', $content ) ) {
				\WRIO_Plugin::app()->logger->error( 'Failed write webp rules to htaccess file (%s)' );
			}
		}
	}
}
