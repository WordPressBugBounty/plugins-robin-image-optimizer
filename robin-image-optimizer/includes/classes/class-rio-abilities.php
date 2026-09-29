<?php
/**
 * Registers the plugin abilities with the WordPress Abilities API.
 *
 * @package Robin_Image_Optimizer
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WRIO_Abilities
 *
 * Thin wrappers over the existing media library optimization, conversion,
 * restore and settings code. No-op on WordPress versions without the
 * Abilities API.
 */
class WRIO_Abilities {

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'robin-image-optimizer';

	/**
	 * Prefix of the stateless job reference returned by write abilities.
	 */
	const JOB_PREFIX = 'media:';

	/**
	 * Max attachments accepted per call. The write abilities work through the
	 * list in time-boxed chunks.
	 */
	const MAX_ITEMS = 500;

	/**
	 * Default and maximum time budget of one call, in seconds.
	 */
	const DEFAULT_TIME_BUDGET = 20;
	const MAX_TIME_BUDGET     = 60;

	/**
	 * Hook the registration callbacks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	/**
	 * Register the ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Robin Image Optimizer', 'robin-image-optimizer' ),
				'description' => __( 'Optimize, convert and restore media library images.', 'robin-image-optimizer' ),
			]
		);
	}

	/**
	 * Register the abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$ids_schema = [
			'type'        => 'array',
			'description' => __( 'Attachment IDs.', 'robin-image-optimizer' ),
			'items'       => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'minItems'    => 1,
		];

		$items_schema = [
			'type'  => 'array',
			'items' => [
				'type'                 => 'object',
				'additionalProperties' => true,
			],
		];

		$chunk_input = [
			'cursor'      => [
				'type'        => 'string',
				'description' => __( 'Opaque cursor returned by the previous call. Send it back with the same input to continue.', 'robin-image-optimizer' ),
			],
			'time_budget' => [
				'type'        => 'integer',
				'description' => __( 'Seconds to work before returning a cursor.', 'robin-image-optimizer' ),
				'minimum'     => 1,
				'maximum'     => self::MAX_TIME_BUDGET,
				'default'     => self::DEFAULT_TIME_BUDGET,
			],
		];

		$progress_schema = [
			'type'       => 'object',
			'properties' => [
				'current' => [ 'type' => 'integer' ],
				'total'   => [ 'type' => 'integer' ],
				'message' => [ 'type' => 'string' ],
			],
		];

		$chunk_output = [
			'done'     => [ 'type' => 'boolean' ],
			'cursor'   => [ 'type' => 'string' ],
			'progress' => $progress_schema,
		];

		$task_meta = [
			'mode'        => 'cursor',
			'results_key' => 'items',
		];

		wp_register_ability(
			'robin/optimize-media',
			[
				'label'               => __( 'Optimize media', 'robin-image-optimizer' ),
				'description'         => sprintf(
					// translators: %d is the maximum number of attachments per call.
					__( 'Optimize selected media library images. Without profile and format the images are queued for background optimization with the saved settings (poll robin/get-job with the returned job_id). With a profile the images are re-optimized immediately at that compression mode; with a format (webp or avif) they are converted immediately. Works in time-boxed chunks: while done is false, call again with the same input and the returned cursor. Max %d images.', 'robin-image-optimizer' ),
					self::MAX_ITEMS
				),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => array_merge(
						[
							'media_ids' => array_merge( $ids_schema, [ 'maxItems' => self::MAX_ITEMS ] ),
							'profile'   => [
								'type'        => 'string',
								'description' => __( 'Compression mode: normal (lossless), aggresive (lossy), ultra (high), googlepage or custom (uses the saved custom quality).', 'robin-image-optimizer' ),
								'enum'        => self::get_profiles(),
							],
							'format'    => [
								'type'        => 'string',
								'description' => __( 'Target format to convert to. AVIF requires an active premium license.', 'robin-image-optimizer' ),
								'enum'        => [ 'webp', 'avif' ],
							],
						],
						$chunk_input
					),
					'required'             => [ 'media_ids' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => array_merge(
						[
							'job_id'  => [ 'type' => 'string' ],
							'mode'    => [
								'type' => 'string',
								'enum' => [ 'queued', 'processed' ],
							],
							'queued'  => [ 'type' => 'integer' ],
							'items'   => $items_schema,
							'skipped' => $items_schema,
						],
						$chunk_output
					),
				],
				'execute_callback'    => [ __CLASS__, 'optimize_media' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
					'task'         => array_merge( $task_meta, [ 'status_ability' => 'robin/get-job' ] ),
				],
			]
		);

		wp_register_ability(
			'robin/restore-originals',
			[
				'label'               => __( 'Restore original images', 'robin-image-optimizer' ),
				'description'         => sprintf(
					// translators: %d is the maximum number of attachments per call.
					__( 'Restore optimized media library images from their backups. Only images optimized while "backup originals" was enabled can be restored. Works in time-boxed chunks: while done is false, call again with the same input and the returned cursor. Max %d images.', 'robin-image-optimizer' ),
					self::MAX_ITEMS
				),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => array_merge(
						[
							'media_ids' => array_merge( $ids_schema, [ 'maxItems' => self::MAX_ITEMS ] ),
						],
						$chunk_input
					),
					'required'             => [ 'media_ids' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => array_merge(
						[
							'job_id'   => [ 'type' => 'string' ],
							'restored' => [ 'type' => 'integer' ],
							'items'    => $items_schema,
							'skipped'  => $items_schema,
						],
						$chunk_output
					),
				],
				'execute_callback'    => [ __CLASS__, 'restore_originals' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'show_in_rest' => true,
					'task'         => $task_meta,
				],
			]
		);

		wp_register_ability(
			'robin/get-job',
			[
				'label'               => __( 'Get optimization job', 'robin-image-optimizer' ),
				'description'         => __( 'Read the optimization status, savings and errors of the images of a job (job_id returned by robin/optimize-media or robin/restore-originals) or of an explicit list of attachment IDs, plus the state of the media library queue. state is working while images are still queued or processing.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'job_id'    => [
							'type'        => 'string',
							'description' => __( 'Job reference returned by a write ability.', 'robin-image-optimizer' ),
						],
						'media_ids' => array_merge( $ids_schema, [ 'maxItems' => self::MAX_ITEMS ] ),
					],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'job_id'   => [ 'type' => 'string' ],
						'state'    => [
							'type' => 'string',
							'enum' => [ 'working', 'completed', 'failed', 'cancelled' ],
						],
						'progress' => $progress_schema,
						'complete' => [ 'type' => 'boolean' ],
						'counts'   => [
							'type'                 => 'object',
							'additionalProperties' => [ 'type' => 'integer' ],
						],
						'items'    => $items_schema,
						'queue'    => [
							'type'                 => 'object',
							'additionalProperties' => true,
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'get_job' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$policy_output = [
			'type'       => 'object',
			'properties' => array_merge(
				self::get_policy_schema_properties(),
				[
					'formats'         => [
						'type'        => 'array',
						'description' => __( 'Conversion formats currently in effect.', 'robin-image-optimizer' ),
						'items'       => [ 'type' => 'string' ],
					],
					'premium'         => [ 'type' => 'boolean' ],
					'backup_writable' => [ 'type' => 'boolean' ],
				]
			),
		];

		wp_register_ability(
			'robin/get-optimization-policy',
			[
				'label'               => __( 'Get optimization policy', 'robin-image-optimizer' ),
				'description'         => __( 'Read the optimization settings: compression mode, conversion formats, automatic optimization, backups, resizing and scheduling.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => $policy_output,
				'execute_callback'    => [ __CLASS__, 'get_policy' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		wp_register_ability(
			'robin/update-optimization-policy',
			[
				'label'               => __( 'Update optimization policy', 'robin-image-optimizer' ),
				'description'         => __( 'Update the optimization settings. Only the provided fields change; returns the effective policy. AVIF conversion and the "background" optimization type require an active premium license.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => self::get_policy_schema_properties(),
					'additionalProperties' => false,
				],
				'output_schema'       => $policy_output,
				'execute_callback'    => [ __CLASS__, 'update_policy' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => [
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * Same capability the plugin admin pages and AJAX handlers require.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Supported compression modes, as offered on the settings page.
	 *
	 * @return string[]
	 */
	private static function get_profiles() {
		return [ 'normal', 'aggresive', 'ultra', 'googlepage', 'custom' ];
	}

	/**
	 * Supported schedule intervals, as offered on the settings page.
	 *
	 * @return string[]
	 */
	private static function get_intervals() {
		return [ 'wio_1_min', 'wio_2_min', 'wio_5_min', 'wio_10_min', 'wio_30_min', 'wio_hourly', 'wio_daily' ];
	}

	/**
	 * Policy field definitions: field => [ option name, type, default ].
	 *
	 * Limited to the options the settings page exposes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed}>
	 */
	private static function get_policy_fields() {
		return [
			'compression_mode'     => [ 'image_optimization_level', 'enum', 'normal' ],
			'custom_quality'       => [ 'image_optimization_level_custom', 'quality', 70 ],
			'auto_optimize'        => [ 'auto_optimize_when_upload', 'bool', false ],
			'backup_originals'     => [ 'backup_origin_images', 'bool', true ],
			'convert_webp'         => [ 'convert_webp_format', 'bool', false ],
			'convert_avif'         => [ 'convert_avif_format', 'bool', false ],
			'keep_exif'            => [ 'save_exif_data', 'bool', true ],
			'optimization_order'   => [ 'image_optimization_order', 'enum', 'asc' ],
			'resize_larger'        => [ 'resize_larger', 'bool', false ],
			'resize_max_width'     => [ 'resize_larger_w', 'int', 1600 ],
			'resize_max_height'    => [ 'resize_larger_h', 'int', 1600 ],
			'allowed_formats'      => [ 'allowed_formats', 'list', 'image/jpeg,image/png,image/gif' ],
			'thumbnail_sizes'      => [ 'allowed_sizes_thumbnail', 'list', 'thumbnail,medium' ],
			'optimization_type'    => [ 'image_optimization_type', 'enum', 'schedule' ],
			'schedule_interval'    => [ 'image_autooptimize_shedule_time', 'enum', 'wio_5_min' ],
			'images_per_iteration' => [ 'image_autooptimize_items_number_per_interation', 'int', 3 ],
		];
	}

	/**
	 * Allowed values of the enum and list policy fields.
	 *
	 * @param string $field Policy field name.
	 *
	 * @return string[]
	 */
	private static function get_policy_choices( $field ) {
		switch ( $field ) {
			case 'compression_mode':
				return self::get_profiles();
			case 'optimization_order':
				return [ 'asc', 'desc' ];
			case 'optimization_type':
				return [ 'schedule', 'background' ];
			case 'schedule_interval':
				return self::get_intervals();
			case 'allowed_formats':
				return [ 'image/jpeg', 'image/png', 'image/gif' ];
			case 'thumbnail_sizes':
				return array_map( 'strval', array_keys( wrio_get_image_sizes() ) );
		}

		return [];
	}

	/**
	 * JSON schema properties of the policy fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_policy_schema_properties() {
		$bool   = [ 'type' => 'boolean' ];
		$string = [ 'type' => 'string' ];

		return [
			'compression_mode'     => [
				'type' => 'string',
				'enum' => self::get_profiles(),
			],
			'custom_quality'       => [
				'type'        => 'integer',
				'description' => __( 'Custom quality 1-100', 'robin-image-optimizer' ),
				'minimum'     => 1,
				'maximum'     => 100,
			],
			'auto_optimize'        => array_merge( $bool, [ 'description' => __( 'Auto optimization on upload', 'robin-image-optimizer' ) ] ),
			'backup_originals'     => $bool,
			'convert_webp'         => $bool,
			'convert_avif'         => array_merge( $bool, [ 'description' => __( 'AVIF conversion requires an active premium license.', 'robin-image-optimizer' ) ] ),
			'keep_exif'            => $bool,
			'optimization_order'   => [
				'type' => 'string',
				'enum' => [ 'asc', 'desc' ],
			],
			'resize_larger'        => $bool,
			'resize_max_width'     => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'resize_max_height'    => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'allowed_formats'      => [
				'type'        => 'array',
				'description' => __( 'Optimize formats', 'robin-image-optimizer' ),
				'items'       => [
					'type' => 'string',
					'enum' => [ 'image/jpeg', 'image/png', 'image/gif' ],
				],
			],
			'thumbnail_sizes'      => [
				'type'        => 'array',
				'description' => __( 'Registered image sizes to optimize along with the main file.', 'robin-image-optimizer' ),
				'items'       => $string,
			],
			'optimization_type'    => [
				'type'        => 'string',
				'description' => __( 'Background optimization type', 'robin-image-optimizer' ),
				'enum'        => [ 'schedule', 'background' ],
			],
			'schedule_interval'    => [
				'type' => 'string',
				'enum' => self::get_intervals(),
			],
			'images_per_iteration' => [
				'type'    => 'integer',
				'minimum' => 1,
			],
		];
	}

	/**
	 * Normalize an attachment ID list.
	 *
	 * @param mixed $raw Raw input value.
	 * @param int   $max Max number of IDs.
	 *
	 * @return int[]|WP_Error
	 */
	private static function sanitize_ids( $raw, $max ) {
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'robin_invalid_media_ids', __( 'media_ids must be a list of attachment IDs.', 'robin-image-optimizer' ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

		if ( empty( $ids ) ) {
			return new WP_Error( 'robin_invalid_media_ids', __( 'media_ids must contain at least one attachment ID.', 'robin-image-optimizer' ) );
		}

		if ( count( $ids ) > $max ) {
			return new WP_Error(
				'robin_batch_too_large',
				// translators: %d is the maximum number of attachments per call.
				sprintf( __( 'Too many attachments. The maximum per call is %d.', 'robin-image-optimizer' ), $max )
			);
		}

		return $ids;
	}

	/**
	 * Reason an attachment can not be processed, or an empty string.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $check_format  Whether the mime type must be enabled in the settings.
	 *
	 * @return string
	 */
	private static function get_skip_reason( $attachment_id, $check_format = true ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return 'not_found';
		}

		if ( ! $check_format ) {
			return '';
		}

		$allowed = wrio_get_allowed_formats();
		$allowed = is_array( $allowed ) ? $allowed : [];

		if ( ! in_array( get_post_mime_type( $attachment_id ), $allowed, true ) ) {
			return 'unsupported_format';
		}

		return '';
	}

	/**
	 * Whether the backup directory is writable.
	 *
	 * @return bool
	 */
	private static function is_backup_writable() {
		/**
		 * Backup instance.
		 *
		 * @var WIO_Backup $backup
		 */
		$backup = WIO_Backup::get_instance();

		return (bool) $backup->isBackupWritable();
	}

	/**
	 * Build the stateless job reference for a list of attachments.
	 *
	 * @param int[] $ids Attachment IDs.
	 *
	 * @return string
	 */
	private static function build_job_id( $ids ) {
		return self::JOB_PREFIX . implode( ',', $ids );
	}

	/**
	 * Time budget of one call, in seconds.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return int
	 */
	private static function get_time_budget( $input ) {
		$budget = isset( $input['time_budget'] ) ? (int) $input['time_budget'] : self::DEFAULT_TIME_BUDGET;

		return max( 1, min( self::MAX_TIME_BUDGET, $budget ) );
	}

	/**
	 * Build the cursor for the next call: the offset into the ID list plus a
	 * checksum tying it to that list.
	 *
	 * @param int   $offset Number of IDs already handled.
	 * @param int[] $ids    Attachment IDs.
	 *
	 * @return string
	 */
	private static function encode_cursor( $offset, $ids ) {
		$cursor = wp_json_encode(
			[
				'offset' => $offset,
				'ids'    => md5( implode( ',', $ids ) ),
			]
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque cursor, not obfuscation.
		return base64_encode( (string) $cursor );
	}

	/**
	 * Read the offset from the cursor of the previous call.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param int[]                $ids   Attachment IDs.
	 *
	 * @return int|WP_Error
	 */
	private static function decode_cursor( $input, $ids ) {
		if ( ! isset( $input['cursor'] ) || '' === $input['cursor'] ) {
			return 0;
		}

		$invalid = new WP_Error( 'robin_invalid_cursor', __( 'Invalid cursor. Send the cursor returned by the previous call together with the same input.', 'robin-image-optimizer' ) );

		if ( ! is_string( $input['cursor'] ) ) {
			return $invalid;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque cursor, not obfuscation.
		$cursor = json_decode( (string) base64_decode( $input['cursor'], true ), true );

		if ( ! is_array( $cursor ) || ! isset( $cursor['offset'], $cursor['ids'] ) || ! is_int( $cursor['offset'] ) ) {
			return $invalid;
		}

		if ( $cursor['offset'] < 1 || $cursor['offset'] >= count( $ids ) || md5( implode( ',', $ids ) ) !== $cursor['ids'] ) {
			return $invalid;
		}

		return $cursor['offset'];
	}

	/**
	 * Output fields shared by the chunked abilities.
	 *
	 * @param int   $offset Number of IDs handled so far.
	 * @param int[] $ids    Attachment IDs.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_chunk_output( $offset, $ids ) {
		$total = count( $ids );
		$done  = $offset >= $total;

		return [
			'done'     => $done,
			'cursor'   => $done ? '' : self::encode_cursor( $offset, $ids ),
			'progress' => [
				'current' => $offset,
				'total'   => $total,
				// translators: %1$d is the number of processed images, %2$d is the total number of images.
				'message' => sprintf( __( '%1$d of %2$d images processed.', 'robin-image-optimizer' ), $offset, $total ),
			],
		];
	}

	/**
	 * Execute robin/optimize-media.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function optimize_media( $input ) {
		$input   = is_array( $input ) ? $input : [];
		$ids     = self::sanitize_ids( isset( $input['media_ids'] ) ? $input['media_ids'] : null, self::MAX_ITEMS );
		$profile = isset( $input['profile'] ) ? sanitize_key( (string) $input['profile'] ) : '';
		$format  = isset( $input['format'] ) ? sanitize_key( (string) $input['format'] ) : '';

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( '' !== $profile && ! in_array( $profile, self::get_profiles(), true ) ) {
			return new WP_Error(
				'robin_invalid_profile',
				// translators: %s is the field name.
				sprintf( __( 'Invalid value for "%s".', 'robin-image-optimizer' ), 'profile' )
			);
		}

		if ( '' !== $format ) {
			if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
				return new WP_Error(
					'robin_invalid_format',
					// translators: %s is the field name.
					sprintf( __( 'Invalid value for "%s".', 'robin-image-optimizer' ), 'format' )
				);
			}

			if ( ! class_exists( 'WRIO_Format_Converter_Factory' ) ) {
				return new WP_Error( 'robin_conversion_unavailable', __( 'Format conversion is not available on this site.', 'robin-image-optimizer' ) );
			}

			if ( 'avif' === $format && ! wrio_is_avif_available() ) {
				return new WP_Error( 'robin_premium_required', __( 'AVIF conversion requires an active premium license.', 'robin-image-optimizer' ) );
			}
		}

		// Same guard as the upload hook and the media library "optimize" action.
		$backup_originals = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', false );
		if ( ( '' === $format || '' !== $profile ) && $backup_originals && ! self::is_backup_writable() ) {
			return new WP_Error( 'robin_backup_not_writable', __( 'No access for writing backups.', 'robin-image-optimizer' ) );
		}

		$offset = self::decode_cursor( $input, $ids );

		if ( is_wp_error( $offset ) ) {
			return $offset;
		}

		$media_library = WRIO_Media_Library::get_instance();
		$immediate     = '' !== $profile || '' !== $format;
		$items         = [];
		$skipped       = [];
		$queued        = 0;
		$total         = count( $ids );
		$deadline      = microtime( true ) + self::get_time_budget( $input );

		while ( $offset < $total ) {
			// At least one image per call, then stop when the time budget is used up.
			if ( ( ! empty( $items ) || ! empty( $skipped ) ) && microtime( true ) >= $deadline ) {
				break;
			}

			$attachment_id = $ids[ $offset ];
			++$offset;

			$reason = self::get_skip_reason( $attachment_id );

			if ( '' !== $reason ) {
				$skipped[] = [
					'id'     => $attachment_id,
					'reason' => $reason,
				];
				continue;
			}

			if ( ! $immediate ) {
				self::queue_attachment( $attachment_id );
				++$queued;
				$items[] = [
					'id'     => $attachment_id,
					'status' => 'queued',
				];
				continue;
			}

			try {
				$processing = false;

				if ( '' !== $profile ) {
					$result     = $media_library->optimizeAttachment( $attachment_id, $profile );
					$processing = isset( $result['processing'] );
				}

				if ( '' !== $format && ! $processing ) {
					$media_library->webpConvertAttachment( $attachment_id, $format );
				}

				$items[] = self::get_item_status( $attachment_id );
			} catch ( Throwable $throwable ) {
				$media_library->getAttachment( $attachment_id )->mark_and_log_failure( $throwable, 'abilities-api', $profile );

				$items[] = [
					'id'     => $attachment_id,
					'status' => 'error',
					'error'  => $throwable->getMessage(),
				];
			}
		}

		return array_merge(
			[
				'job_id'  => self::build_job_id( $ids ),
				'mode'    => $immediate ? 'processed' : 'queued',
				'queued'  => $queued,
				'items'   => $items,
				'skipped' => $skipped,
			],
			self::get_chunk_output( $offset, $ids )
		);
	}

	/**
	 * Queue one attachment the way the upload hook does.
	 *
	 * @see WRIO_Media_Library::optimize_after_upload()
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	private static function queue_attachment( $attachment_id ) {
		$optimize_type = WRIO_Plugin::app()->getOption( 'image_optimization_type', 'schedule' );

		if ( wrio_is_license_activate() && 'background' === $optimize_type ) {
			$processing = wrio_get_processing_class( 'media-library' );

			if ( $processing instanceof WRIO_Media_Processing && $processing->push_items( [ $attachment_id ] ) ) {
				$processing->save()->dispatch();

				return;
			}
		}

		if ( ! wp_next_scheduled( 'wrio/cron/optimization_process', [ $attachment_id ] ) ) {
			WRIO_Cron::start_single( $attachment_id );
		}
	}

	/**
	 * Execute robin/restore-originals.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_originals( $input ) {
		$input = is_array( $input ) ? $input : [];
		$ids   = self::sanitize_ids( isset( $input['media_ids'] ) ? $input['media_ids'] : null, self::MAX_ITEMS );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$offset = self::decode_cursor( $input, $ids );

		if ( is_wp_error( $offset ) ) {
			return $offset;
		}

		$media_library = WRIO_Media_Library::get_instance();
		$items         = [];
		$skipped       = [];
		$restored      = 0;
		$total         = count( $ids );
		$deadline      = microtime( true ) + self::get_time_budget( $input );

		while ( $offset < $total ) {
			// At least one image per call, then stop when the time budget is used up.
			if ( ( ! empty( $items ) || ! empty( $skipped ) ) && microtime( true ) >= $deadline ) {
				break;
			}

			$attachment_id = $ids[ $offset ];
			++$offset;

			$reason = self::get_skip_reason( $attachment_id, false );

			// Same condition as the media library "restore" action.
			if ( '' === $reason && ! $media_library->getAttachment( $attachment_id )->isOptimized() ) {
				$reason = 'not_optimized';
			}

			if ( '' !== $reason ) {
				$skipped[] = [
					'id'     => $attachment_id,
					'reason' => $reason,
				];
				continue;
			}

			$result = $media_library->restoreAttachment( $attachment_id );

			if ( is_wp_error( $result ) ) {
				$items[] = [
					'id'     => $attachment_id,
					'status' => 'error',
					'error'  => $result->get_error_message(),
				];
				continue;
			}

			++$restored;
			$items[] = [
				'id'     => $attachment_id,
				'status' => 'restored',
			];
		}

		return array_merge(
			[
				'job_id'   => self::build_job_id( $ids ),
				'restored' => $restored,
				'items'    => $items,
				'skipped'  => $skipped,
			],
			self::get_chunk_output( $offset, $ids )
		);
	}

	/**
	 * Execute robin/get-job.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_job( $input ) {
		$input = is_array( $input ) ? $input : [];
		$raw   = [];

		if ( isset( $input['job_id'] ) && '' !== $input['job_id'] ) {
			$job_id = (string) $input['job_id'];

			if ( 0 !== strpos( $job_id, self::JOB_PREFIX ) ) {
				return new WP_Error( 'robin_invalid_job_id', __( 'Unknown job_id.', 'robin-image-optimizer' ) );
			}

			$raw = explode( ',', substr( $job_id, strlen( self::JOB_PREFIX ) ) );
		}

		if ( isset( $input['media_ids'] ) && is_array( $input['media_ids'] ) ) {
			$raw = array_merge( $raw, $input['media_ids'] );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

		if ( count( $ids ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'robin_batch_too_large',
				// translators: %d is the maximum number of attachments per call.
				sprintf( __( 'Too many attachments. The maximum per call is %d.', 'robin-image-optimizer' ), self::MAX_ITEMS )
			);
		}

		$items      = [];
		$counts     = [];
		$pending    = 0;
		$background = self::is_background_queue_active();

		foreach ( $ids as $attachment_id ) {
			$item    = self::get_item_status( $attachment_id );
			$items[] = $item;
			$status  = (string) $item['status'];

			$counts[ $status ] = isset( $counts[ $status ] ) ? $counts[ $status ] + 1 : 1;

			// Images waiting in the background process have no status of their own yet.
			if ( in_array( $status, [ 'queued', 'processing' ], true ) || ( $background && RIO_Process_Queue::STATUS_UNOPTIMIZED === $status ) ) {
				++$pending;
			}
		}

		$total  = count( $ids );
		$errors = isset( $counts[ RIO_Process_Queue::STATUS_ERROR ] ) ? $counts[ RIO_Process_Queue::STATUS_ERROR ] : 0;
		$state  = 'completed';

		if ( $pending > 0 ) {
			$state = 'working';
		} elseif ( $total > 0 && $errors === $total ) {
			$state = 'failed';
		}

		return [
			'job_id'   => self::build_job_id( $ids ),
			'state'    => $state,
			'progress' => [
				'current' => $total - $pending,
				'total'   => $total,
				// translators: %1$d is the number of processed images, %2$d is the total number of images.
				'message' => sprintf( __( '%1$d of %2$d images processed.', 'robin-image-optimizer' ), $total - $pending, $total ),
			],
			'complete' => 0 === $pending,
			'counts'   => $counts,
			'items'    => $items,
			'queue'    => [
				'remaining'          => WRIO_Optimization_Orchestrator::get_instance()->get_total_remaining(),
				'cron_running'       => (bool) WRIO_Plugin::app()->getPopulateOption( 'cron_running', false ),
				'background_running' => $background,
			],
		];
	}

	/**
	 * Whether the media library background process still has work.
	 *
	 * True while a bulk run is flagged as running or while the healthcheck
	 * event of the background process is scheduled (it is cleared when the
	 * queue is empty).
	 *
	 * @see WRIO_Processing
	 *
	 * @return bool
	 */
	private static function is_background_queue_active() {
		if ( WRIO_Plugin::app()->getPopulateOption( 'process_running', false ) ) {
			return true;
		}

		return (bool) wp_next_scheduled( 'wrio_optimize_process_media_library_cron' );
	}

	/**
	 * Status, savings and errors of one attachment.
	 *
	 * Uses the same data as the media library column.
	 *
	 * @see WRIO_Media_Library::calculateMediaLibraryParams()
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_item_status( $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return [
				'id'     => $attachment_id,
				'status' => 'not_found',
			];
		}

		$media_library = WRIO_Media_Library::get_instance();
		$params        = $media_library->calculateMediaLibraryParams( $attachment_id );
		$status        = (string) $media_library->getAttachment( $attachment_id )->getOptimizationData()->get_result_status();

		if ( ! in_array( $status, [ RIO_Process_Queue::STATUS_SUCCESS, RIO_Process_Queue::STATUS_ERROR, RIO_Process_Queue::STATUS_SKIP, RIO_Process_Queue::STATUS_PROCESSING ], true ) ) {
			$status = RIO_Process_Queue::STATUS_UNOPTIMIZED;

			if ( ! $params['is_supported_format'] ) {
				$status = 'unsupported_format';
			} elseif ( wp_next_scheduled( 'wrio/cron/optimization_process', [ $attachment_id ] ) ) {
				$status = 'queued';
			}
		}

		return [
			'id'                   => $attachment_id,
			'status'               => $status,
			'error'                => trim( (string) $params['error_msg'] ),
			'level'                => (string) $params['optimization_level'],
			'original_size'        => (int) $params['original_size'],
			'optimized_size'       => (int) $params['optimized_size'],
			'saved_percent'        => (float) $params['diff_percent_all'],
			'thumbnails_optimized' => (int) $params['thumbnails_optimized'],
			'backed_up'            => (bool) $params['backuped'],
			'webp_size'            => (int) $params['webp_size'],
			'avif_size'            => (int) $params['avif_size'],
		];
	}

	/**
	 * Execute robin/get-optimization-policy.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_policy() {
		$app     = WRIO_Plugin::app();
		$premium = (bool) wrio_is_license_activate();
		$policy  = [];

		foreach ( self::get_policy_fields() as $field => $definition ) {
			list( $option, $type, $default ) = $definition;

			$value = $app->getPopulateOption( $option, $default );

			switch ( $type ) {
				case 'bool':
					$value = true === $value || 1 === $value || '1' === $value || 'true' === $value;
					break;
				case 'int':
				case 'quality':
					$value = (int) $value;
					break;
				case 'list':
					$value = array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
					break;
				default:
					$value = (string) $value;
			}

			$policy[ $field ] = $value;
		}

		// Effective values on installs without a license, as the plugin itself resolves them.
		if ( ! $premium ) {
			$policy['convert_avif'] = false;

			if ( 'background' === $policy['optimization_type'] ) {
				$policy['optimization_type'] = 'schedule';
			}
		}

		$policy['formats']         = class_exists( 'WRIO_Format_Converter_Factory' ) ? WRIO_Format_Converter_Factory::get_enabled_formats() : [];
		$policy['premium']         = $premium;
		$policy['backup_writable'] = self::is_backup_writable();

		return $policy;
	}

	/**
	 * Execute robin/update-optimization-policy.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_policy( $input ) {
		$input   = is_array( $input ) ? $input : [];
		$fields  = self::get_policy_fields();
		$unknown = array_diff( array_keys( $input ), array_keys( $fields ) );
		$updates = [];

		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'robin_unknown_field',
				// translators: %s is a comma separated list of field names.
				sprintf( __( 'Unknown policy fields: %s', 'robin-image-optimizer' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) )
			);
		}

		if ( empty( $input ) ) {
			return new WP_Error( 'robin_empty_update', __( 'Provide at least one policy field to update.', 'robin-image-optimizer' ) );
		}

		foreach ( $input as $field => $value ) {
			list( $option, $type ) = $fields[ $field ];

			$value = self::sanitize_policy_value( $field, $type, $value );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$updates[ $option ] = $value;
		}

		if ( ! wrio_is_license_activate() ) {
			if ( ! empty( $updates['convert_avif_format'] ) ) {
				return new WP_Error( 'robin_premium_required', __( 'AVIF conversion requires an active premium license.', 'robin-image-optimizer' ) );
			}

			if ( isset( $updates['image_optimization_type'] ) && 'background' === $updates['image_optimization_type'] ) {
				return new WP_Error( 'robin_premium_required', __( 'Background optimization requires an active premium license.', 'robin-image-optimizer' ) );
			}
		}

		// Nothing is saved unless every field validated, the same way the settings form saves.
		foreach ( $updates as $option => $value ) {
			WRIO_Plugin::app()->updatePopulateOption( $option, $value );
		}

		return self::get_policy();
	}

	/**
	 * Validate one policy value and convert it to its stored form.
	 *
	 * @param string $field Policy field name.
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 *
	 * @return int|string|WP_Error
	 */
	private static function sanitize_policy_value( $field, $type, $value ) {
		$invalid = new WP_Error(
			'robin_invalid_value',
			// translators: %s is the field name.
			sprintf( __( 'Invalid value for "%s".', 'robin-image-optimizer' ), $field )
		);

		switch ( $type ) {
			case 'bool':
				if ( ! is_bool( $value ) && ! in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					return $invalid;
				}

				return ( true === $value || 1 === $value || '1' === $value || 'true' === $value ) ? 1 : 0;

			case 'int':
			case 'quality':
				if ( ! is_numeric( $value ) || (int) $value < 1 || ( 'quality' === $type && (int) $value > 100 ) ) {
					return $invalid;
				}

				// The settings form stores the resize and quality values as strings.
				return 'images_per_iteration' === $field ? (int) $value : (string) (int) $value;

			case 'enum':
				if ( ! is_string( $value ) || ! in_array( $value, self::get_policy_choices( $field ), true ) ) {
					return $invalid;
				}

				return $value;

			case 'list':
				if ( ! is_array( $value ) ) {
					return $invalid;
				}

				$value = array_values( array_unique( array_map( 'strval', $value ) ) );

				if ( array_diff( $value, self::get_policy_choices( $field ) ) ) {
					return $invalid;
				}

				if ( 'allowed_formats' === $field && empty( $value ) ) {
					return $invalid;
				}

				return implode( ',', $value );
		}

		return $invalid;
	}
}
