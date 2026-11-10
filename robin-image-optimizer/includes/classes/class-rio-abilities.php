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

		// One image, as get_item_status() reports it; a skipped image has a reason, a failed one an error.
		$items_schema = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'id'                   => [ 'type' => 'integer' ],
					'status'               => [ 'type' => 'string' ],
					'reason'               => [ 'type' => 'string' ],
					'error'                => [ 'type' => 'string' ],
					'level'                => [ 'type' => 'string' ],
					'original_size'        => [ 'type' => 'integer' ],
					'optimized_size'       => [ 'type' => 'integer' ],
					'saved_percent'        => [ 'type' => 'number' ],
					'thumbnails_optimized' => [ 'type' => 'integer' ],
					'backed_up'            => [ 'type' => 'boolean' ],
					'webp_size'            => [ 'type' => 'integer' ],
					'avif_size'            => [ 'type' => 'integer' ],
					'excluded'             => [ 'type' => 'boolean' ],
					'keep_dimensions'      => [ 'type' => 'boolean' ],
				],
				'required'   => [ 'id' ],
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

		wp_register_ability(
			'robin/optimize-media',
			[
				'label'               => __( 'Optimize media', 'robin-image-optimizer' ),
				'description'         => sprintf(
					// translators: %d is the maximum number of attachments per call.
					__( 'Optimize selected media library images. Without profile and format the images are queued for background optimization with the saved settings (poll robin/get-job with the returned job_id). With a profile the images are re-optimized immediately at that compression mode; with a format (webp or avif) they are converted immediately. Works in time-boxed chunks: while done is false, call again with the same input and the returned cursor. Images per call: %d at most.', 'robin-image-optimizer' ),
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
				'meta'                => self::meta( false, true, false ),
			]
		);

		wp_register_ability(
			'robin/restore-originals',
			[
				'label'               => __( 'Restore original images', 'robin-image-optimizer' ),
				'description'         => sprintf(
					// translators: %d is the maximum number of attachments per call.
					__( 'Restore optimized media library images from their backups. Only images optimized while "backup originals" was enabled can be restored. Works in time-boxed chunks: while done is false, call again with the same input and the returned cursor. Images per call: %d at most.', 'robin-image-optimizer' ),
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
				'meta'                => self::meta( false, true, false ),
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
				'meta'                => self::meta( true, false, true ),
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
				'input_schema'        => self::no_input(),
				'output_schema'       => $policy_output,
				'execute_callback'    => [ __CLASS__, 'get_policy' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
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
				'permission_callback' => [ __CLASS__, 'can_manage_settings' ],
				'meta'                => self::meta( false, false, true ),
			]
		);

		wp_register_ability(
			'robin/reset-optimization-policy',
			[
				'label'               => __( 'Reset optimization policy', 'robin-image-optimizer' ),
				'description'         => __( 'Set every optimization setting back to its default, like "Reset to defaults" on the settings page. Returns the effective policy.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => $policy_output,
				'execute_callback'    => [ __CLASS__, 'reset_policy' ],
				'permission_callback' => [ __CLASS__, 'can_manage_settings' ],
				'meta'                => self::meta( false, true, true ),
			]
		);

		wp_register_ability(
			'robin/update-media-options',
			[
				'label'               => __( 'Update media options', 'robin-image-optimizer' ),
				'description'         => __( 'Set the per-image options of media library images, as in the attachment details: keep_dimensions (never resize the image, it is still compressed) and exclude (bulk and automatic optimization skip the image). Only the provided options change. robin/get-job reports both.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'media_ids'       => array_merge( $ids_schema, [ 'maxItems' => self::MAX_ITEMS ] ),
						'keep_dimensions' => [ 'type' => 'boolean' ],
						'exclude'         => [ 'type' => 'boolean' ],
					],
					'required'             => [ 'media_ids' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'items'   => $items_schema,
						'skipped' => $items_schema,
					],
				],
				'execute_callback'    => [ __CLASS__, 'update_media_options' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, false, true ),
			]
		);

		$scope_input = [
			'type'                 => 'object',
			'properties'           => [
				'scope' => [
					'type'        => 'string',
					'description' => __( 'Image library: media-library, or custom-folders and nextgen-gallery (premium).', 'robin-image-optimizer' ),
					'enum'        => [ 'media-library', 'custom-folders', 'nextgen-gallery' ],
					'default'     => 'media-library',
				],
			],
			'additionalProperties' => false,
			'default'              => [],
		];
		$any_output  = [
			'type'                 => 'object',
			'additionalProperties' => true,
		];
		$bulk_output = [
			'type'       => 'object',
			'properties' => [
				'scope'   => [ 'type' => 'string' ],
				'running' => [
					'type'        => 'object',
					'description' => __( 'cron: a scheduled run of this library is on. process: a background run of this library is on.', 'robin-image-optimizer' ),
					'properties'  => [
						'cron'    => [ 'type' => 'boolean' ],
						'process' => [ 'type' => 'boolean' ],
					],
					'required'    => [ 'cron', 'process' ],
				],
			],
			'required'   => [ 'scope', 'running' ],
		];

		wp_register_ability(
			'robin/get-stats',
			[
				'label'               => __( 'Get optimization statistics', 'robin-image-optimizer' ),
				'description'         => __( 'Read what the bulk optimization page shows for an image library: optimized, unoptimized and failed counts, savings, the last optimized images, the failures by reason, whether a bulk run is running, and the custom folders.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $scope_input,
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'scope'    => [ 'type' => 'string' ],
						'stats'    => [
							'type'        => 'object',
							'description' => __( 'Image counts and sizes of the library.', 'robin-image-optimizer' ),
						],
						'log'      => [
							'type'        => 'array',
							'description' => __( 'The last optimized images.', 'robin-image-optimizer' ),
						],
						'failures' => [
							'type'        => 'object',
							'description' => __( 'Failed images by group; robin/get-failures lists a group.', 'robin-image-optimizer' ),
							'properties'  => [
								'total'      => [ 'type' => 'integer' ],
								'needs_you'  => [ 'type' => 'integer' ],
								'retrying'   => [ 'type' => 'integer' ],
								'next_retry' => [ 'type' => [ 'string', 'null' ] ],
								'groups'     => [ 'type' => 'array' ],
							],
						],
						'running'  => $bulk_output['properties']['running'],
						'percents' => [
							'type'                 => 'object',
							'description'          => __( 'Optimized percent of each library.', 'robin-image-optimizer' ),
							'additionalProperties' => [ 'type' => 'integer' ],
						],
						'features' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'folders'  => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'uid'            => [ 'type' => 'string' ],
									'path'           => [ 'type' => 'string' ],
									'filesCount'     => [ 'type' => 'integer' ],
									'optimizedCount' => [ 'type' => 'integer' ],
								],
							],
						],
					],
					'required'   => [ 'scope', 'stats', 'log', 'failures', 'running', 'percents', 'features', 'folders' ],
				],
				'execute_callback'    => [ __CLASS__, 'get_stats' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/get-failures',
			[
				'label'               => __( 'Get failed images', 'robin-image-optimizer' ),
				'description'         => __( 'Read one page of the images of a failure group (the group keys are in the failures of robin/get-stats). Send next back as after for the next page.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => array_merge(
						$scope_input['properties'],
						[
							'group' => [ 'type' => 'string' ],
							'after' => [
								'type'    => 'string',
								'default' => '',
							],
						]
					),
					'required'             => [ 'group' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'items'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'total'    => [ 'type' => 'integer' ],
						'next'     => [
							'type'        => 'string',
							'description' => __( 'Send it as after for the next page. Empty on the last page.', 'robin-image-optimizer' ),
						],
						'per_page' => [ 'type' => 'integer' ],
					],
					'required'   => [ 'items', 'total', 'next', 'per_page' ],
				],
				'execute_callback'    => [ __CLASS__, 'get_failures' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/start-bulk-optimization',
			[
				'label'               => __( 'Start bulk optimization', 'robin-image-optimizer' ),
				'description'         => __( 'Start optimizing every unoptimized image of a library in the background, with the saved settings (optimization_type and schedule_interval of the policy). Excluded images are skipped. Follow it with robin/get-stats.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $scope_input,
				'output_schema'       => $bulk_output,
				'execute_callback'    => [ __CLASS__, 'start_bulk' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, false, true ),
			]
		);

		wp_register_ability(
			'robin/stop-bulk-optimization',
			[
				'label'               => __( 'Stop bulk optimization', 'robin-image-optimizer' ),
				'description'         => __( 'Stop the background bulk optimization of a library. Optimized images stay optimized.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $scope_input,
				'output_schema'       => $bulk_output,
				'execute_callback'    => [ __CLASS__, 'stop_bulk' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, false, true ),
			]
		);

		wp_register_ability(
			'robin/get-server-status',
			[
				'label'               => __( 'Get optimization server status', 'robin-image-optimizer' ),
				'description'         => __( 'Ask the optimization server whether it answers for this site and license, and read the plan and its limits.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'get_server_status' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/test-delivery',
			[
				'label'               => __( 'Test WebP and AVIF delivery', 'robin-image-optimizer' ),
				'description'         => __( 'Check that visitors get the WebP and AVIF files with the current delivery mode, like the "Test delivery" button. It writes temporary test files to the uploads folder, the site requests them over loopback HTTP requests, and the files are deleted after. A CDN or a cache in front of the site can answer differently for real visitors.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'test_delivery' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/get-backups',
			[
				'label'               => __( 'Get backups', 'robin-image-optimizer' ),
				'description'         => __( 'Read the size of the backup folder and the number of images that have a backup.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count' => [
							'type'        => 'integer',
							'description' => __( 'Images with a backup.', 'robin-image-optimizer' ),
						],
						'size'  => [
							'type'        => 'integer',
							'description' => __( 'Size of the backup folder in bytes.', 'robin-image-optimizer' ),
						],
					],
					'required'   => [ 'count', 'size' ],
				],
				'execute_callback'    => [ __CLASS__, 'get_backups' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/restore-all-backups',
			[
				'label'               => __( 'Restore all backups', 'robin-image-optimizer' ),
				'description'         => __( 'Restore the next batch of backed-up images of every library, like "Restore" in Manage backups. While end is false, call again and send the IDs of every failed list so far as skip.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'skip' => [
							'type'        => 'array',
							'description' => __( 'Attachment IDs that failed in earlier calls.', 'robin-image-optimizer' ),
							'items'       => [ 'type' => 'integer' ],
						],
					],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'restore_all_backups' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, true, false ),
			]
		);

		wp_register_ability(
			'robin/clear-backups',
			[
				'label'               => __( 'Delete all backups', 'robin-image-optimizer' ),
				'description'         => __( 'Delete the backup folder, like "Clear backups" in Manage backups. The optimized images can no longer be restored. This cannot be undone.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'confirm' => [
							'type'        => 'boolean',
							'description' => __( 'true: the agent knows the backups go for good.', 'robin-image-optimizer' ),
							// rest_validate_value_from_schema() knows no const.
							'enum'        => [ true ],
						],
					],
					'required'             => [ 'confirm' ],
					'additionalProperties' => false,
				],
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'clear_backups' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, true, true ),
			]
		);

		wp_register_ability(
			'robin/browse-folders',
			[
				'label'               => __( 'Browse folders', 'robin-image-optimizer' ),
				'description'         => __( 'List the sub-folders of a folder under the site root, to pick a custom folder. Premium.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'dir' => [
							'type'        => 'string',
							'description' => __( 'Folder relative to the site root. Empty for the root.', 'robin-image-optimizer' ),
							'default'     => '',
						],
					],
					'additionalProperties' => false,
					'default'              => [],
				],
				'output_schema'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name' => [ 'type' => 'string' ],
							'path' => [
								'type'        => 'string',
								'description' => __( 'Path relative to the site root, for dir or robin/add-custom-folder.', 'robin-image-optimizer' ),
							],
							'type' => [
								'type' => 'string',
								'enum' => [ 'dir', 'file' ],
							],
						],
					],
				],
				'execute_callback'    => [ __CLASS__, 'browse_folders' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/add-custom-folder',
			[
				'label'               => __( 'Add a custom folder', 'robin-image-optimizer' ),
				'description'         => __( 'Add a folder outside the media library to optimize. Then index its images with robin/scan-custom-folder and optimize them with robin/start-bulk-optimization and the custom-folders scope. Premium.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'path' => [
							'type'        => 'string',
							'description' => __( 'Folder path relative to the site root, as robin/browse-folders gives it.', 'robin-image-optimizer' ),
						],
					],
					'required'             => [ 'path' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'uid'             => [ 'type' => 'string' ],
						'path'            => [ 'type' => 'string' ],
						'files_count'     => [ 'type' => 'integer' ],
						'optimized_count' => [ 'type' => 'integer' ],
						'errors_count'    => [ 'type' => 'integer' ],
						'network'         => [ 'type' => 'boolean' ],
					],
					'required'   => [ 'uid', 'path', 'files_count', 'optimized_count' ],
				],
				'execute_callback'    => [ __CLASS__, 'add_custom_folder' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, false, false ),
			]
		);

		wp_register_ability(
			'robin/scan-custom-folder',
			[
				'label'               => __( 'Scan a custom folder', 'robin-image-optimizer' ),
				'description'         => __( 'Index the next batch of images of a custom folder. While the returned offset is below total, call again with the returned offset and total. Premium.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'uid'    => [
							'type'        => 'string',
							'description' => __( 'Folder uid, from robin/add-custom-folder or robin/get-stats.', 'robin-image-optimizer' ),
						],
						'offset' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
						'total'  => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
					],
					'required'             => [ 'uid' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'offset'   => [ 'type' => 'integer' ],
						'total'    => [ 'type' => 'integer' ],
						'complete' => [ 'type' => 'boolean' ],
						'percent'  => [ 'type' => 'number' ],
					],
					'required'   => [ 'offset', 'total', 'complete', 'percent' ],
				],
				'execute_callback'    => [ __CLASS__, 'scan_custom_folder' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, false, true ),
			]
		);

		wp_register_ability(
			'robin/remove-custom-folder',
			[
				'label'               => __( 'Remove a custom folder', 'robin-image-optimizer' ),
				'description'         => __( 'Stop optimizing a custom folder. Its files stay as they are. Premium.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'uid' => [ 'type' => 'string' ],
					],
					'required'             => [ 'uid' ],
					'additionalProperties' => false,
				],
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'remove_custom_folder' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, true, true ),
			]
		);

		wp_register_ability(
			'robin/get-log',
			[
				'label'               => __( 'Get the error log', 'robin-image-optimizer' ),
				'description'         => __( 'Read the plugin log, as on the Error Log page. It has lines only while the error_log policy field is on.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'get_log' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( true, false, true ),
			]
		);

		wp_register_ability(
			'robin/clear-log',
			[
				'label'               => __( 'Clear the error log', 'robin-image-optimizer' ),
				'description'         => __( 'Delete the plugin log files.', 'robin-image-optimizer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::no_input(),
				'output_schema'       => $any_output,
				'execute_callback'    => [ __CLASS__, 'clear_log' ],
				'permission_callback' => [ __CLASS__, 'can_manage' ],
				'meta'                => self::meta( false, true, true ),
			]
		);
	}

	/**
	 * Input schema of an ability without input. It has no "properties": an
	 * empty PHP array encodes to a JSON array, and JSON Schema wants an object.
	 *
	 * @return array<string, mixed>
	 */
	private static function no_input() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => [],
		];
	}

	/**
	 * Meta of an ability.
	 *
	 * @param bool $read_only   The ability changes nothing.
	 * @param bool $destructive The ability can delete or overwrite data.
	 * @param bool $idempotent  A second call with the same input changes nothing more.
	 *
	 * @return array<string, mixed>
	 */
	private static function meta( $read_only, $destructive, $idempotent ) {
		return [
			'annotations'  => [
				'readonly'    => $read_only,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			],
			'show_in_rest' => true,
			// The MCP adapter lists only the public abilities.
			'mcp'          => [ 'public' => true ],
		];
	}

	/**
	 * Run a route handler of the settings app, so an agent and the app share the code, the checks and the errors.
	 *
	 * @param string               $handler WRIO_Rest_Controller method.
	 * @param array<string, mixed> $params  Request parameters.
	 *
	 * @return mixed|WP_Error The response data.
	 */
	private static function run_route( $handler, array $params = [] ) {
		WRIO_Rest_Controller::load_admin_environment();

		$request = new WP_REST_Request();
		$request->set_query_params( $params );

		/**
		 * Route handler.
		 *
		 * @var callable $method
		 */
		$method   = [ 'WRIO_Rest_Controller', $handler ];
		$response = $method( $request );

		return is_wp_error( $response ) ? $response : rest_ensure_response( $response )->get_data();
	}

	/**
	 * The scope of a scope input.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return string
	 */
	private static function scope( $input ) {
		return is_array( $input ) && isset( $input['scope'] ) ? (string) $input['scope'] : 'media-library';
	}

	/**
	 * A text field of an ability input.
	 *
	 * @param mixed  $input Ability input.
	 * @param string $key   Field name.
	 *
	 * @return string
	 */
	private static function text_input( $input, $key ) {
		return is_array( $input ) ? (string) ( $input[ $key ] ?? '' ) : '';
	}

	/**
	 * Execute robin/reset-optimization-policy.
	 *
	 * @return array<string, mixed>
	 */
	public static function reset_policy() {
		WRIO_Settings::reset();

		return self::get_policy();
	}

	/**
	 * Execute robin/update-media-options.
	 *
	 * @see WRIO_Media_Library::save_attachment_options()
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_media_options( $input ) {
		$input   = is_array( $input ) ? $input : [];
		$ids     = self::sanitize_ids( $input['media_ids'] ?? null, self::MAX_ITEMS );
		$options = array_intersect_key(
			[
				'keep_dimensions' => '_wrio_no_resize',
				'exclude'         => '_wrio_exclude',
			],
			$input
		);

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		if ( empty( $options ) ) {
			return new WP_Error( 'robin_empty_update', __( 'Provide keep_dimensions or exclude.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		$items   = [];
		$skipped = [];

		foreach ( $ids as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				$skipped[] = [
					'id'     => $attachment_id,
					'reason' => 'not_found',
				];
				continue;
			}

			foreach ( $options as $field => $meta ) {
				if ( $input[ $field ] ) {
					update_post_meta( $attachment_id, $meta, 1 );
				} else {
					delete_post_meta( $attachment_id, $meta );
				}
			}

			$items[] = [
				'id'              => $attachment_id,
				'keep_dimensions' => WRIO_Media_Library::keeps_dimensions( $attachment_id ),
				'exclude'         => (bool) get_post_meta( $attachment_id, '_wrio_exclude', true ),
			];
		}

		// The counts leave out the excluded images.
		WRIO_Image_Query::clear_cache();

		return [
			'items'   => $items,
			'skipped' => $skipped,
		];
	}

	/**
	 * Execute robin/get-stats.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function get_stats( $input ) {
		return self::run_route( 'get_stats', [ 'scope' => self::scope( $input ) ] );
	}

	/**
	 * Execute robin/get-failures.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function get_failures( $input ) {
		$input = is_array( $input ) ? $input : [];

		return self::run_route(
			'get_failures',
			[
				'scope' => self::scope( $input ),
				'group' => (string) ( $input['group'] ?? '' ),
				'after' => (string) ( $input['after'] ?? '' ),
			]
		);
	}

	/**
	 * Execute robin/start-bulk-optimization.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function start_bulk( $input ) {
		return self::bulk_state( self::run_route( 'schedule_start', [ 'scope' => self::scope( $input ) ] ), self::scope( $input ) );
	}

	/**
	 * Execute robin/stop-bulk-optimization.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function stop_bulk( $input ) {
		return self::bulk_state( self::run_route( 'schedule_stop', [ 'scope' => self::scope( $input ) ] ), self::scope( $input ) );
	}

	/**
	 * The run state of a library after a start or a stop, as robin/get-stats reports it.
	 *
	 * @see WRIO_App::get_bulk_data()
	 *
	 * @param mixed  $result Route result.
	 * @param string $scope  Library.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function bulk_state( $result, $scope ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$plugin = WRIO_Plugin::app();

		return [
			'scope'   => $scope,
			'running' => [
				'cron'    => $plugin->getPopulateOption( 'cron_running', false ) === $scope,
				'process' => wrio_processing_scope( (string) $plugin->getPopulateOption( 'process_running', false ) ) === wrio_processing_scope( $scope ),
			],
		];
	}

	/**
	 * Execute robin/get-server-status.
	 *
	 * @return mixed|WP_Error
	 */
	public static function get_server_status() {
		return self::run_route( 'server_status' );
	}

	/**
	 * Execute robin/test-delivery: start the test and let the site request the files itself.
	 *
	 * @return mixed|WP_Error
	 */
	public static function test_delivery() {
		$test = self::run_route( 'delivery_test_start' );

		if ( is_wp_error( $test ) ) {
			return $test;
		}

		$id = is_array( $test ) && is_string( $test['id'] ?? null ) ? $test['id'] : '';

		return self::run_route( 'delivery_test_finish', [ 'id' => $id ] );
	}

	/**
	 * Execute robin/get-backups.
	 *
	 * @return mixed|WP_Error
	 */
	public static function get_backups() {
		return self::run_route( 'get_backup' );
	}

	/**
	 * Execute robin/restore-all-backups.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function restore_all_backups( $input ) {
		return self::run_route( 'restore_backup', [ 'skip' => self::positive_ids( (array) ( is_array( $input ) ? $input['skip'] ?? [] : [] ) ) ] );
	}

	/**
	 * Execute robin/clear-backups.
	 *
	 * @return mixed|WP_Error
	 */
	public static function clear_backups() {
		return self::run_route( 'clear_backup' );
	}

	/**
	 * Execute robin/browse-folders.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function browse_folders( $input ) {
		return self::run_route( 'browse_folders', [ 'dir' => self::text_input( $input, 'dir' ) ] );
	}

	/**
	 * Execute robin/add-custom-folder.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function add_custom_folder( $input ) {
		return self::run_route( 'add_folder', [ 'path' => self::text_input( $input, 'path' ) ] );
	}

	/**
	 * Execute robin/scan-custom-folder.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function scan_custom_folder( $input ) {
		$input = is_array( $input ) ? $input : [];

		return self::run_route(
			'scan_folder',
			[
				'uid'    => (string) ( $input['uid'] ?? '' ),
				'offset' => (int) ( $input['offset'] ?? 0 ),
				'total'  => (int) ( $input['total'] ?? 0 ),
			]
		);
	}

	/**
	 * Execute robin/remove-custom-folder.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return mixed|WP_Error
	 */
	public static function remove_custom_folder( $input ) {
		return self::run_route( 'remove_folder', [ 'uid' => self::text_input( $input, 'uid' ) ] );
	}

	/**
	 * Execute robin/get-log.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_log() {
		return WRIO_App::get_log_data();
	}

	/**
	 * Execute robin/clear-log.
	 *
	 * @return mixed|WP_Error
	 */
	public static function clear_log() {
		return self::run_route( 'clear_logs' );
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
	 * Permission check of the settings changes: the settings page needs the same (see WRIO_Rest_Controller).
	 *
	 * @return bool
	 */
	public static function can_manage_settings() {
		return current_user_can( WRIO_Plugin::app()->isNetworkActive() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Supported compression modes, as offered on the settings page.
	 *
	 * @return string[]
	 */
	private static function get_profiles() {
		return WRIO_Settings::schema()['image_optimization_level']['choices'];
	}

	/**
	 * Policy field => setting of WRIO_Settings::schema(). The field names are the ones agents use.
	 *
	 * Every setting of the settings page has a field: test-abilities.php fails when a new setting has none.
	 *
	 * @return array<string, string>
	 */
	public static function get_policy_fields() {
		return [
			'compression_mode'              => 'image_optimization_level',
			'custom_quality'                => 'image_optimization_level_custom',
			'auto_optimize'                 => 'auto_optimize_when_upload',
			'backup_originals'              => 'backup_origin_images',
			'convert_webp'                  => 'convert_webp_format',
			'convert_avif'                  => 'convert_avif_format',
			'delivery_mode'                 => 'webp_delivery_mode',
			// save_exif_data is the "Strip EXIF data" switch: true removes the metadata.
			'strip_exif'                    => 'save_exif_data',
			'optimization_order'            => 'image_optimization_order',
			'resize_larger'                 => 'resize_larger',
			'resize_max_width'              => 'resize_larger_w',
			'resize_max_height'             => 'resize_larger_h',
			'allowed_formats'               => 'allowed_formats',
			'excluded_paths'                => 'excluded_paths',
			'thumbnail_sizes'               => 'allowed_sizes_thumbnail',
			'optimization_type'             => 'image_optimization_type',
			'schedule_interval'             => 'image_autooptimize_shedule_time',
			'images_per_iteration'          => 'image_autooptimize_items_number_per_interation',
			'show_media_column'             => 'show_media_column',
			'error_log'                     => 'error_log',
			'frontend_error_log'            => 'keep_error_log_on_frontend',
			'delete_converted_on_uninstall' => 'delete_converted_on_uninstall',
			'fetchpriority_first_image'     => 'fetchpriority_first_image',
		];
	}

	/**
	 * Descriptions of the policy fields.
	 *
	 * @return array<string, string>
	 */
	private static function get_policy_descriptions() {
		return [
			'compression_mode'              => __( 'Compression mode: normal (lossless), aggresive (lossy), ultra (high), googlepage or custom (uses custom_quality). googlepage requires an active premium license.', 'robin-image-optimizer' ),
			'custom_quality'                => __( 'Quality of the custom compression mode, 1-100.', 'robin-image-optimizer' ),
			'auto_optimize'                 => __( 'Optimize new uploads automatically.', 'robin-image-optimizer' ),
			'backup_originals'              => __( 'Keep a backup of each original before it is optimized, so that it can be restored.', 'robin-image-optimizer' ),
			'convert_webp'                  => __( 'Make a WebP copy of each optimized image.', 'robin-image-optimizer' ),
			'convert_avif'                  => __( 'Make an AVIF copy of each optimized image. Requires an active premium license.', 'robin-image-optimizer' ),
			'delivery_mode'                 => __( 'How visitors get the WebP and AVIF copies: picture (wrap images in picture tags), url (rewrite the image URLs) or redirect (the server picks the format from the Accept header; only on Apache with .htaccess, otherwise picture is used).', 'robin-image-optimizer' ),
			'strip_exif'                    => __( 'Remove EXIF, XMP and IPTC metadata from optimized images. The color profile stays.', 'robin-image-optimizer' ),
			'optimization_order'            => __( 'Order of a bulk run: asc (oldest first), desc (newest first) or largest (largest files first).', 'robin-image-optimizer' ),
			'resize_larger'                 => __( 'Resize originals that are larger than resize_max_width x resize_max_height. An image with keep_dimensions keeps its size.', 'robin-image-optimizer' ),
			'resize_max_width'              => __( 'Maximum width in pixels when resize_larger is on. 0 means no limit.', 'robin-image-optimizer' ),
			'resize_max_height'             => __( 'Maximum height in pixels when resize_larger is on. 0 means no limit.', 'robin-image-optimizer' ),
			'allowed_formats'               => __( 'Image types to optimize.', 'robin-image-optimizer' ),
			'excluded_paths'                => __( 'Bulk and automatic optimization skip the images whose path in the uploads folder contains one of these patterns. * matches any text and ? matches one character, for example 2019/* or banner-?.jpg.', 'robin-image-optimizer' ),
			'thumbnail_sizes'               => __( 'Registered image sizes to optimize along with the main file.', 'robin-image-optimizer' ),
			'optimization_type'             => __( 'How a bulk run works in the background: schedule (WP-Cron) or background (background process; requires an active premium license).', 'robin-image-optimizer' ),
			'schedule_interval'             => __( 'Interval of the scheduled bulk run.', 'robin-image-optimizer' ),
			'images_per_iteration'          => __( 'Images of one scheduled run.', 'robin-image-optimizer' ),
			'show_media_column'             => __( 'Show the Robin column in the Media Library.', 'robin-image-optimizer' ),
			'fetchpriority_first_image'     => __( 'Add fetchpriority="high" to the first image of the post content, so the browser loads it first.', 'robin-image-optimizer' ),
			'error_log'                     => __( 'Write the plugin log and show the Error Log page.', 'robin-image-optimizer' ),
			'frontend_error_log'            => __( 'Also log the WebP and AVIF delivery problems of the frontend. Works only with error_log.', 'robin-image-optimizer' ),
			'delete_converted_on_uninstall' => __( 'Delete the WebP and AVIF files that the plugin made when the plugin is deleted.', 'robin-image-optimizer' ),
		];
	}

	/**
	 * JSON schema properties of the policy fields, from the setting types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_policy_schema_properties() {
		$schema       = WRIO_Settings::schema();
		$descriptions = self::get_policy_descriptions();
		$properties   = [];

		foreach ( self::get_policy_fields() as $field => $name ) {
			$setting  = $schema[ $name ];
			$property = [ 'description' => $descriptions[ $field ] ];

			switch ( $setting['type'] ) {
				case 'bool':
					$property['type'] = 'boolean';
					break;
				case 'int':
					$property['type'] = 'integer';
					foreach ( [
						'min' => 'minimum',
						'max' => 'maximum',
					] as $key => $keyword ) {
						if ( isset( $setting[ $key ] ) ) {
							$property[ $keyword ] = $setting[ $key ];
						}
					}
					break;
				case 'enum':
					$property['type'] = 'string';
					$property['enum'] = array_values( array_map( 'strval', $setting['choices'] ) );
					break;
				case 'list':
					$property['type']  = 'array';
					$property['items'] = [
						'type' => 'string',
						'enum' => array_values( array_map( 'strval', $setting['choices'] ) ),
					];
					break;
				default:
					// patterns: one pattern per item.
					$property['type']  = 'array';
					$property['items'] = [ 'type' => 'string' ];
			}

			$properties[ $field ] = $property;
		}

		return $properties;
	}

	/**
	 * Unique positive integer IDs of a raw list.
	 *
	 * Not absint(): it turns -5 into 5, which is another attachment.
	 *
	 * @param array<mixed> $raw Raw IDs.
	 *
	 * @return int[]
	 */
	private static function positive_ids( $raw ) {
		$ids = array_map( 'intval', array_filter( $raw, 'is_numeric' ) );

		return array_values( array_unique( array_filter( $ids, static fn( $id ) => $id > 0 ) ) );
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
			return new WP_Error( 'robin_invalid_media_ids', __( 'media_ids must be a list of attachment IDs.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		$ids = self::positive_ids( $raw );

		if ( empty( $ids ) ) {
			return new WP_Error( 'robin_invalid_media_ids', __( 'media_ids must contain at least one attachment ID.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		if ( count( $ids ) > $max ) {
			return new WP_Error(
				'robin_batch_too_large',
				// translators: %d is the maximum number of attachments per call.
				sprintf( __( 'Too many attachments. The maximum per call is %d.', 'robin-image-optimizer' ), $max ),
				[ 'status' => 400 ]
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

		$invalid = new WP_Error( 'robin_invalid_cursor', __( 'Invalid cursor. Send the cursor returned by the previous call together with the same input.', 'robin-image-optimizer' ), [ 'status' => 400 ] );

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
				'message' => sprintf( _n( '%1$d of %2$d image processed.', '%1$d of %2$d images processed.', $total, 'robin-image-optimizer' ), $offset, $total ),
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
				sprintf( __( 'Invalid value for "%s".', 'robin-image-optimizer' ), 'profile' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' !== $format ) {
			if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
				return new WP_Error(
					'robin_invalid_format',
					// translators: %s is the field name.
					sprintf( __( 'Invalid value for "%s".', 'robin-image-optimizer' ), 'format' ),
					[ 'status' => 400 ]
				);
			}

			if ( 'avif' === $format && ! wrio_is_avif_available() ) {
				return new WP_Error( 'wrio_premium_required', __( 'AVIF conversion requires an active premium license.', 'robin-image-optimizer' ), [ 'status' => 403 ] );
			}
		}

		// Same guard as the upload hook and the media library "optimize" action.
		$backup_originals = WRIO_Plugin::app()->getPopulateOption( 'backup_origin_images', wrio_get_default( 'backup_origin_images' ) );
		if ( ( '' === $format || '' !== $profile ) && $backup_originals && ! self::is_backup_writable() ) {
			return new WP_Error( 'robin_backup_not_writable', __( 'No access for writing backups.', 'robin-image-optimizer' ), [ 'status' => 409 ] );
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

			\WRIO\V2\Client::$trigger = 'manual';

			try {
				$optimized = '' !== $profile ? $media_library->optimizeAttachment( $attachment_id, $profile ) : null;

				// The restore before the re-optimization failed: the image keeps its optimized files.
				if ( is_wp_error( $optimized ) ) {
					$items[] = [
						'id'     => $attachment_id,
						'status' => 'error',
						'error'  => $optimized->get_error_message(),
					];
					continue;
				}

				// The optimization already converted to the enabled formats.
				if ( '' !== $format && ( '' === $profile || ! in_array( $format, WRIO_Formats::get_enabled_formats(), true ) ) ) {
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
		\WRIO\V2\Client::queue_trigger( $attachment_id, 'manual' );

		$optimize_type = WRIO_Plugin::app()->getPopulateOption( 'image_optimization_type', 'schedule' );

		if ( wrio_is_license_activate() && 'background' === $optimize_type ) {
			$processing = wrio_get_processing_class( 'media-library' );

			if ( $processing instanceof WRIO_Media_Processing && $processing->push_items( [ $attachment_id ] ) ) {
				$processing->save( true )->dispatch();

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
				return new WP_Error( 'robin_invalid_job_id', __( 'Unknown job_id.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
			}

			$raw = explode( ',', substr( $job_id, strlen( self::JOB_PREFIX ) ) );
		}

		if ( isset( $input['media_ids'] ) && is_array( $input['media_ids'] ) ) {
			$raw = array_merge( $raw, $input['media_ids'] );
		}

		$ids = self::positive_ids( $raw );

		if ( empty( $ids ) ) {
			return new WP_Error( 'robin_invalid_job_id', __( 'Provide a job_id or media_ids.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		if ( count( $ids ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'robin_batch_too_large',
				// translators: %d is the maximum number of attachments per call.
				sprintf( __( 'Too many attachments. The maximum per call is %d.', 'robin-image-optimizer' ), self::MAX_ITEMS ),
				[ 'status' => 400 ]
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
		} elseif ( $errors === $total ) {
			$state = 'failed';
		}

		return [
			'job_id'   => self::build_job_id( $ids ),
			'state'    => $state,
			'progress' => [
				'current' => $total - $pending,
				'total'   => $total,
				// translators: %1$d is the number of processed images, %2$d is the total number of images.
				'message' => sprintf( _n( '%1$d of %2$d image processed.', '%1$d of %2$d images processed.', $total, 'robin-image-optimizer' ), $total - $pending, $total ),
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
			'excluded'             => WRIO_Image_Query::get_instance()->is_excluded( $attachment_id ),
			'keep_dimensions'      => WRIO_Media_Library::keeps_dimensions( $attachment_id ),
		];
	}

	/**
	 * Execute robin/get-optimization-policy.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_policy() {
		// The effective values: a free plan and the server limits apply, as on the settings page.
		$settings = WRIO_Settings::get();
		$policy   = [];

		foreach ( self::get_policy_fields() as $field => $name ) {
			$policy[ $field ] = 'excluded_paths' === $name ? array_values( array_filter( explode( "\n", (string) $settings[ $name ] ), static fn( $line ) => '' !== $line ) ) : $settings[ $name ];
		}

		$policy['formats']         = WRIO_Formats::get_enabled_formats();
		$policy['premium']         = (bool) wrio_is_license_activate();
		$policy['backup_writable'] = self::is_backup_writable();

		return $policy;
	}

	/**
	 * Execute robin/update-optimization-policy.
	 *
	 * Saves through WRIO_Settings::save(), like the settings page: the .htaccess rules, the page cache and the
	 * image counts follow the new values.
	 *
	 * @param mixed $input Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_policy( $input ) {
		$input   = is_array( $input ) ? $input : [];
		$fields  = self::get_policy_fields();
		$schema  = WRIO_Settings::schema();
		$unknown = array_diff( array_keys( $input ), array_keys( $fields ) );
		$updates = [];

		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'robin_unknown_field',
				// translators: %s is a comma separated list of field names.
				sprintf( __( 'Unknown policy fields: %s', 'robin-image-optimizer' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $input ) ) {
			return new WP_Error( 'robin_empty_update', __( 'Provide at least one policy field to update.', 'robin-image-optimizer' ), [ 'status' => 400 ] );
		}

		foreach ( $input as $field => $value ) {
			$value = self::sanitize_policy_value( $field, $schema[ $fields[ $field ] ], $value );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$updates[ $fields[ $field ] ] = $value;
		}

		// The settings page locks these on a free plan; WRIO_Settings::save() would quietly drop them.
		if ( ! wrio_is_license_activate() ) {
			if ( ! empty( $updates['convert_avif_format'] ) ) {
				return new WP_Error( 'wrio_premium_required', __( 'AVIF conversion requires an active premium license.', 'robin-image-optimizer' ), [ 'status' => 403 ] );
			}

			if ( isset( $updates['image_optimization_type'] ) && 'background' === $updates['image_optimization_type'] ) {
				return new WP_Error( 'wrio_premium_required', __( 'Background optimization requires an active premium license.', 'robin-image-optimizer' ), [ 'status' => 403 ] );
			}

			if ( isset( $updates['image_optimization_level'] ) && 'googlepage' === $updates['image_optimization_level'] ) {
				return new WP_Error( 'wrio_premium_required', __( 'The googlepage compression mode requires an active premium license.', 'robin-image-optimizer' ), [ 'status' => 403 ] );
			}
		}

		// Nothing is saved unless every field validated. The save also tests the loopback request before it turns
		// Background optimization on, and saves nothing when that test fails.
		$saved = WRIO_Settings::save( $updates );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return self::get_policy();
	}

	/**
	 * Validate one policy value and convert it to the value WRIO_Settings::save() takes.
	 *
	 * @param string               $field   Policy field name.
	 * @param array<string, mixed> $setting Setting schema.
	 * @param mixed                $value   Raw value.
	 *
	 * @return bool|int|string|string[]|WP_Error
	 */
	private static function sanitize_policy_value( $field, $setting, $value ) {
		$invalid = new WP_Error(
			'robin_invalid_value',
			// translators: %s is the field name.
			sprintf( __( 'Invalid value for "%s".', 'robin-image-optimizer' ), $field ),
			[ 'status' => 400 ]
		);

		switch ( $setting['type'] ) {
			case 'bool':
				if ( ! is_bool( $value ) && ! in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					return $invalid;
				}

				return true === $value || 1 === $value || '1' === $value || 'true' === $value;

			case 'int':
				if ( ! is_numeric( $value ) || ( isset( $setting['min'] ) && (int) $value < $setting['min'] ) || ( isset( $setting['max'] ) && (int) $value > $setting['max'] ) ) {
					return $invalid;
				}

				return (int) $value;

			case 'enum':
				return is_string( $value ) && in_array( $value, $setting['choices'], true ) ? $value : $invalid;

			case 'list':
				if ( ! is_array( $value ) ) {
					return $invalid;
				}

				$value = array_values( array_unique( array_map( 'strval', $value ) ) );

				if ( array_diff( $value, array_map( 'strval', $setting['choices'] ) ) || ( 'allowed_formats' === $field && empty( $value ) ) ) {
					return $invalid;
				}

				return $value;

			case 'patterns':
				if ( ! is_array( $value ) || array_filter( $value, static fn( $line ) => ! is_string( $line ) ) ) {
					return $invalid;
				}

				return implode( "\n", $value );
		}

		return $invalid;
	}
}
