<?php
/**
 * Settings schema: read, sanitize, save and reset the plugin options.
 *
 * @package robin-image-optimizer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same options and defaults the Factory settings form used, minus the form.
 */
class WRIO_Settings {

	const DELIVERY_MODES = [ 'redirect', 'picture', 'url' ];

	/**
	 * Settings that change the image markup or URLs of the pages: a change purges the page caches.
	 */
	const DELIVERY_KEYS = [ 'webp_delivery_mode', 'convert_webp_format', 'convert_avif_format' ];

	/**
	 * Option name => [ type, default, choices ].
	 *
	 * Types: bool (stored 1/0 like the Factory checkbox), enum, int, list (comma string), patterns (one path
	 * pattern per line), text and textarea (legacy fields only).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema() {
		// A legacy field never replaces a built-in one.
		return self::builtin_schema() + self::legacy_schema();
	}

	/**
	 * The options of the settings page itself.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function builtin_schema() {
		return [
			'image_optimization_level'        => [
				'type'    => 'enum',
				'default' => 'normal',
				'choices' => [ 'normal', 'aggresive', 'ultra', 'googlepage', 'custom' ],
			],
			'image_optimization_level_custom' => [
				'type'    => 'int',
				'default' => 70,
				'min'     => 1,
				'max'     => 100,
			],
			'auto_optimize_when_upload'       => [
				'type'    => 'bool',
				'default' => false,
			],
			'show_media_column'               => [
				'type'    => 'bool',
				'default' => true,
			],
			'backup_origin_images'            => [
				'type'    => 'bool',
				'default' => true,
			],
			'error_log'                       => [
				'type'    => 'bool',
				'default' => false,
			],
			'keep_error_log_on_frontend'      => [
				'type'    => 'bool',
				'default' => false,
			],
			'convert_webp_format'             => [
				'type'    => 'bool',
				'default' => false,
			],
			'convert_avif_format'             => [
				'type'    => 'bool',
				'default' => false,
			],
			'delete_converted_on_uninstall'   => [
				'type'    => 'bool',
				'default' => false,
			],
			'webp_delivery_mode'              => [
				'type'    => 'enum',
				'default' => 'picture',
				'choices' => self::DELIVERY_MODES,
			],
			'save_exif_data'                  => [
				'type'    => 'bool',
				'default' => true,
			],
			'fetchpriority_first_image'       => [
				'type'    => 'bool',
				'default' => false,
			],
			'image_optimization_order'        => [
				'type'    => 'enum',
				'default' => 'asc',
				'choices' => [ 'asc', 'desc', 'largest' ],
			],
			'resize_larger'                   => [
				'type'    => 'bool',
				'default' => false,
			],
			'resize_larger_w'                 => [
				'type'    => 'int',
				'default' => 1600,
				'min'     => 0,
			],
			'resize_larger_h'                 => [
				'type'    => 'int',
				'default' => 1600,
				'min'     => 0,
			],
			'allowed_formats'                 => [
				'type'    => 'list',
				'default' => 'image/jpeg,image/png,image/gif',
				'choices' => [ 'image/jpeg', 'image/png', 'image/gif' ],
			],
			'excluded_paths'                  => [
				'type'    => 'patterns',
				'default' => '',
			],
			'allowed_sizes_thumbnail'         => [
				'type'    => 'list',
				'default' => 'thumbnail,medium',
				'choices' => array_keys( wrio_get_image_sizes() ),
			],
			'image_optimization_type'         => [
				'type'    => 'enum',
				'default' => 'schedule',
				'choices' => [ 'schedule', 'background' ],
			],
			'image_autooptimize_shedule_time' => [
				'type'    => 'enum',
				'default' => 'wio_5_min',
				'choices' => array_keys( WRIO_Cron::schedules() ),
			],
			'image_autooptimize_items_number_per_interation' => [
				'type'    => 'int',
				'default' => 3,
				'min'     => 1,
			],
		];
	}

	/**
	 * Fields that other code added to the old Factory form through the legacy filter. The page shows them in an
	 * "Additional settings" card and saves them like the built-in ones.
	 *
	 * Supported Factory controls: checkbox, dropdown (a list of [ value, label ] pairs), textbox and textarea, also
	 * inside a group's "items". Other controls are ignored.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function legacy_schema() {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- existing hook name, kept for compatibility.
		$options = apply_filters( 'wbcr/rio/settings_page/options', [] );
		$fields  = [];

		foreach ( self::legacy_controls( is_array( $options ) ? $options : [] ) as $control ) {
			$type  = $control['type'];
			$field = [
				'label' => isset( $control['title'] ) ? wp_strip_all_tags( (string) $control['title'] ) : $control['name'],
				'help'  => isset( $control['hint'] ) ? wp_strip_all_tags( (string) $control['hint'] ) : '',
			];

			if ( 'checkbox' === $type ) {
				$field += [
					'type'    => 'bool',
					'default' => ! empty( $control['default'] ),
				];
			} elseif ( 'dropdown' === $type ) {
				$data    = isset( $control['data'] ) ? $control['data'] : [];
				$data    = is_callable( $data ) ? call_user_func( $data ) : $data;
				$choices = [];
				foreach ( is_array( $data ) ? $data : [] as $item ) {
					if ( is_array( $item ) && isset( $item[0] ) && is_scalar( $item[0] ) ) {
						$choices[] = [
							'value' => (string) $item[0],
							'label' => wp_strip_all_tags( (string) ( isset( $item[1] ) ? $item[1] : $item[0] ) ),
						];
					}
				}
				if ( empty( $choices ) ) {
					continue;
				}
				$values = array_column( $choices, 'value' );
				$field += [
					'type'    => 'enum',
					'choices' => $values,
					'labels'  => $choices,
					'default' => isset( $control['default'] ) && in_array( (string) $control['default'], $values, true ) ? (string) $control['default'] : $values[0],
				];
			} else {
				$field += [
					'type'    => 'textarea' === $type ? 'textarea' : 'text',
					'default' => isset( $control['default'] ) && is_scalar( $control['default'] ) ? (string) $control['default'] : '',
				];
			}

			$fields[ $control['name'] ] = $field + [ 'legacy' => true ];
		}

		return $fields;
	}

	/**
	 * The named, supported controls of a Factory form, groups flattened.
	 *
	 * @param array<mixed> $options Factory form options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected static function legacy_controls( array $options ) {
		$controls = [];

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			if ( isset( $option['items'] ) && is_array( $option['items'] ) ) {
				$controls = array_merge( $controls, self::legacy_controls( $option['items'] ) );
				continue;
			}
			// The name as the Factory form saved it: sanitize_key() would lowercase it, and so read another option.
			if ( empty( $option['name'] ) || ! is_string( $option['name'] ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $option['name'] ) || ! isset( $option['type'] ) || ! in_array( $option['type'], [ 'checkbox', 'dropdown', 'textbox', 'textarea' ], true ) ) {
				continue;
			}
			$controls[] = $option;
		}

		return $controls;
	}

	/**
	 * Current values, typed for JSON.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		return self::apply_gates( self::stored() );
	}

	/**
	 * Stored values, typed for JSON, before the free plan and server limits.
	 *
	 * @return array<string, mixed>
	 */
	protected static function stored() {
		$plugin = WRIO_Plugin::app();
		$values = [];

		foreach ( self::schema() as $name => $field ) {
			$values[ $name ] = self::cast( $field, $plugin->getPopulateOption( $name, $field['default'] ) );
		}

		return $values;
	}

	/**
	 * Default values, typed for JSON.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$values = [];

		foreach ( self::schema() as $name => $field ) {
			$values[ $name ] = self::cast( $field, $field['default'] );
		}

		return self::apply_gates( $values );
	}

	/**
	 * Sanitize and store the submitted values. Unknown keys are ignored,
	 * missing keys keep their stored value.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 *
	 * @return array<string, mixed>|WP_Error Values after save. An error saves nothing.
	 */
	public static function save( array $input ) {
		$plugin = WRIO_Plugin::app();
		$schema = self::schema();
		$stored = self::stored();
		$input  = array_intersect_key( $input, $schema );

		if ( isset( $input['excluded_paths'] ) ) {
			$input['excluded_paths'] = self::relative_patterns( $input['excluded_paths'], (string) $stored['excluded_paths'] );
		}

		// A missing key keeps its stored value, also one that a free plan locks now: a lapsed license loses no choice.
		$values = array_merge( self::apply_gates( array_merge( $stored, $input ) ), array_diff_key( $stored, $input ) );

		if ( 'background' === $values['image_optimization_type'] && 'background' !== $stored['image_optimization_type'] ) {
			$loopback = wrio_test_background();

			if ( is_wp_error( $loopback ) ) {
				return $loopback;
			}
		}

		foreach ( $schema as $name => $field ) {
			$values[ $name ] = self::cast( $field, $values[ $name ] );
			$plugin->updatePopulateOption( $name, self::to_storage( $field, $values[ $name ] ) );
		}

		self::after_save( self::delivery_changed( $stored, $values ) );

		return self::apply_gates( $values );
	}

	/**
	 * Whether a delivery setting changed.
	 *
	 * @param array<string, mixed> $before Values before the save.
	 * @param array<string, mixed> $after  Values after the save.
	 *
	 * @return bool
	 */
	protected static function delivery_changed( array $before, array $after ) {
		foreach ( self::DELIVERY_KEYS as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete every option so the defaults apply again.
	 *
	 * @return array<string, mixed> Values after reset.
	 */
	public static function reset() {
		$plugin = WRIO_Plugin::app();
		$values = self::defaults();
		$stored = self::get();

		// Write them rather than delete them. The plugin reads these options
		// itself, with a default of its own, so a deleted option leaves the
		// screen saying backups are on while the optimizer makes none.
		foreach ( self::schema() as $name => $field ) {
			$plugin->updatePopulateOption( $name, self::to_storage( $field, $values[ $name ] ) );
		}

		self::after_save( self::delivery_changed( $stored, $values ) );

		return $values;
	}

	/**
	 * Things the page needs beyond the values: image sizes, server, backup.
	 *
	 * @return array<string, mixed>
	 */
	public static function meta() {
		$sizes = [];
		foreach ( wrio_get_image_sizes() as $key => $size ) {
			$sizes[] = [
				'key'    => $key,
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
			];
		}

		$server = 'unknown';
		if ( class_exists( 'WRIO\\WEBP\\Server' ) ) {
			if ( WRIO\WEBP\Server::is_apache() ) {
				$server = 'apache';
			} elseif ( WRIO\WEBP\Server::is_nginx() ) {
				$server = 'nginx';
			} elseif ( WRIO\WEBP\Server::is_iss() ) {
				$server = 'iss';
			}
		}

		$schedules = [];
		foreach ( WRIO_Cron::schedules() as $key => $schedule ) {
			$schedules[] = [
				'value' => $key,
				'label' => $schedule['label'],
			];
		}

		return [
			'imageSizes'    => $sizes,
			'schedules'     => $schedules,
			'server'        => $server,
			'allowRedirect' => self::redirect_allowed(),
			'nginxRules'    => 'nginx' === $server ? WRIO\WEBP\Server::nginx_rules() : '',
			'nginxDeny'     => 'nginx' === $server ? WRIO\WEBP\Server::nginx_deny_rules() : '',
			'wpCache'       => defined( 'WP_CACHE' ) && WP_CACHE,
			'legacyFields'  => self::legacy_meta(),
			// The last WRIO\WEBP\Delivery_Test report, with its time.
			'deliveryTest'  => get_option( 'wrio_delivery_test', null ),
		];
	}

	/**
	 * How the page shows the legacy fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected static function legacy_meta() {
		$fields = [];

		foreach ( array_diff_key( self::legacy_schema(), self::builtin_schema() ) as $name => $field ) {
			$fields[] = [
				'name'    => $name,
				'type'    => $field['type'],
				'label'   => $field['label'],
				'help'    => $field['help'],
				'choices' => isset( $field['labels'] ) ? $field['labels'] : [],
			];
		}

		return $fields;
	}

	/**
	 * How much the "Delete all backups" dialog is about to remove.
	 *
	 * @return array{count: int, size: int}
	 */
	public static function backup_stats() {
		$backup = WIO_Backup::get_instance();
		$dir    = $backup->getBackupDir();
		$size   = 0;

		if ( is_string( $dir ) && is_dir( $dir ) ) {
			try {
				// Full directory walk; cache the total if large backup folders make this slow.
				$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $files as $file ) {
					if ( ! $file instanceof SplFileInfo ) {
						continue;
					}
					// The deny files of wrio_protect_dir() are not backups.
					if ( in_array( $file->getFilename(), [ '.htaccess', 'index.php' ], true ) ) {
						continue;
					}
					$size += $file->getSize();
				}
			} catch ( UnexpectedValueException $e ) {
				// An unreadable sub-folder: report what could be measured.
				unset( $e );
			}
		}

		global $wpdb;
		$table = RIO_Process_Queue::table_name();
		// Every kind of backup, not the media library alone: custom folder and
		// NextGEN backups sit under the same folder this dialog measures and
		// the same button clears.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the model.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_backed_up = 1" );

		return [
			'count' => $count,
			'size'  => $size,
		];
	}

	/**
	 * What the Factory template did after saving: flush caches, rewrite .htaccess rules.
	 *
	 * @param bool $delivery_changed Whether a delivery setting changed: only then do the cached pages hold old
	 *                               image markup.
	 *
	 * @return void
	 */
	protected static function after_save( $delivery_changed ) {
		if ( $delivery_changed ) {
			wrio_flush_page_cache();
			// The last delivery test checked the old settings.
			delete_option( 'wrio_delivery_test' );
		}

		// The counts leave out the excluded paths.
		WRIO_Image_Query::clear_cache();

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- existing Factory hook name.
		do_action( 'wbcr/factory/pages/impressive/after_form_save', WRIO_Plugin::app(), (object) [ 'id' => 'rio_settings' ] );
	}

	/**
	 * Free plan limits and server limits, applied on read and on write.
	 *
	 * @param array<string, mixed> $values Typed values.
	 *
	 * @return array<string, mixed>
	 */
	protected static function apply_gates( array $values ) {
		if ( ! wrio_is_license_activate() ) {
			$values['convert_avif_format'] = false;
			if ( 'background' === $values['image_optimization_type'] ) {
				$values['image_optimization_type'] = 'schedule';
			}
			if ( 'googlepage' === $values['image_optimization_level'] ) {
				$values['image_optimization_level'] = 'normal';
			}
		}

		if ( 'redirect' === $values['webp_delivery_mode'] && ! self::redirect_allowed() ) {
			$values['webp_delivery_mode'] = 'picture';
		}

		return $values;
	}

	/**
	 * Redirect delivery needs Apache with .htaccess, where Robin writes the rules, or nginx, where the site owner adds
	 * the rules that the settings page shows.
	 *
	 * @return bool
	 */
	protected static function redirect_allowed() {
		return class_exists( 'WRIO\\WEBP\\Server' ) && ( ( WRIO\WEBP\Server::is_apache() && WRIO\WEBP\Server::server_use_htaccess() ) || WRIO\WEBP\Server::is_nginx() );
	}

	/**
	 * Coerce a raw or submitted value to the field type, falling back to the default.
	 *
	 * @param array<string, mixed> $field Schema entry.
	 * @param mixed                $value Raw value.
	 *
	 * @return mixed
	 */
	protected static function cast( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );

			case 'int':
				// (int) of an array is 1: a resize limit of 1 pixel. An empty field stays 0, which is no limit.
				if ( ! is_scalar( $value ) && null !== $value ) {
					return $field['default'];
				}
				$int = (int) $value;
				if ( isset( $field['min'] ) && $int < $field['min'] ) {
					$int = $field['min'];
				}
				if ( isset( $field['max'] ) && $int > $field['max'] ) {
					$int = $field['max'];
				}
				return $int;

			case 'enum':
				// The option read turns a stored "0" into the integer 0: compare as text, as the choices are text.
				$value = is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : $value;
				return in_array( $value, $field['choices'], true ) ? $value : $field['default'];

			case 'list':
				$items = is_array( $value ) ? $value : explode( ',', (string) $value );
				// An older save can have spaces after the commas.
				return array_values( array_intersect( array_map( 'trim', array_map( 'strval', $items ) ), $field['choices'] ) );

			case 'text':
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $field['default'];

			case 'textarea':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : $field['default'];

			case 'patterns':
				$lines = array_map( fn( $line ) => ltrim( trim( $line ), '/' ), self::lines( $value ) );
				return implode( "\n", array_unique( array_filter( $lines, fn( $line ) => '' !== $line ) ) );
		}

		return $value;
	}

	/**
	 * The lines of a patterns value.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	protected static function lines( $value ) {
		return explode( "\n", str_replace( "\r", '', sanitize_textarea_field( WRIO_Image_Query::patterns_to_string( $value ) ) ) );
	}

	/**
	 * Make the new lines of submitted patterns relative to the uploads folder: "wp-content/uploads/2019/*" is
	 * "2019/*". A line that is already stored stays as it is, so a save of another setting changes no pattern.
	 *
	 * @param mixed  $input  Submitted patterns.
	 * @param string $stored Stored patterns.
	 *
	 * @return string
	 */
	protected static function relative_patterns( $input, $stored ) {
		$kept  = explode( "\n", $stored );
		$lines = array_map(
			function ( $line ) use ( $kept ) {
				$line = ltrim( trim( $line ), '/' );

				return in_array( $line, $kept, true ) ? $line : (string) preg_replace( '#^(wp-content/)?uploads/#', '', $line );
			},
			self::lines( $input )
		);

		return implode( "\n", $lines );
	}

	/**
	 * Same storage format the Factory controls used, so nothing else in the plugin changes.
	 *
	 * @param array<string, mixed> $field Schema entry.
	 * @param mixed                $value Typed value.
	 *
	 * @return mixed
	 */
	protected static function to_storage( array $field, $value ) {
		if ( 'bool' === $field['type'] ) {
			return $value ? 1 : 0;
		}
		if ( 'list' === $field['type'] ) {
			return implode( ',', (array) $value );
		}

		return $value;
	}
}
