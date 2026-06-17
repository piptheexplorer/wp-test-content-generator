<?php
/**
 * Plugin Name: WP Test Content Generator
 * Description: Generate fake test content for posts, pages, and custom post types, including featured images and taxonomy terms.
 * Version:     1.1.0
 * Author:      Phil York
 * License:     GPL-2.0-or-later
 * Text Domain: wp-test-content-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPTCG_Test_Content_Generator {

	const VERSION              = '1.1.0';
	const OPTION_NAME          = 'wptcg_settings';
	const GENERATED_META       = '_wptcg_generated';
	const GENERATED_IMAGE_META = '_wptcg_generated_image';
	const GENERATION_LIMIT     = 500;
	const TERM_CREATION_LIMIT  = 50;

	/**
	 * Initialise the plugin.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_wptcg_generate_posts', array( __CLASS__, 'generate_posts' ) );
		add_action( 'admin_post_wptcg_delete_posts', array( __CLASS__, 'delete_generated_posts' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_admin_notice' ) );
	}

	/**
	 * Register the settings page under Tools.
	 */
	public static function register_admin_page() {
		add_management_page(
			__( 'Test Content Generator', 'wp-test-content-generator' ),
			__( 'Test Content Generator', 'wp-test-content-generator' ),
			'edit_posts',
			'wptcg-test-content-generator',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Load media-library and admin assets on this plugin page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'tools_page_wptcg-test-content-generator' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'wptcg-admin',
			plugin_dir_url( __FILE__ ) . 'assets/admin.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'wptcg-admin',
			plugin_dir_url( __FILE__ ) . 'assets/admin.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'wptcg-admin',
			'wptcgAdmin',
			array(
				'frameTitle'  => __( 'Choose a featured image', 'wp-test-content-generator' ),
				'buttonLabel' => __( 'Use this image', 'wp-test-content-generator' ),
			)
		);
	}

	/**
	 * Return post types that can receive generated content.
	 *
	 * @return WP_Post_Type[]
	 */
	private static function get_available_post_types() {
		$post_types = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);

		$excluded = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		);

		foreach ( $excluded as $post_type ) {
			unset( $post_types[ $post_type ] );
		}

		uasort(
			$post_types,
			static function ( $a, $b ) {
				return strcasecmp( $a->labels->singular_name, $b->labels->singular_name );
			}
		);

		return $post_types;
	}

	/**
	 * Return assignable taxonomies for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return WP_Taxonomy[]
	 */
	private static function get_assignable_taxonomies( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy_name => $taxonomy ) {
			if ( ! $taxonomy->show_ui || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				unset( $taxonomies[ $taxonomy_name ] );
			}
		}

		return $taxonomies;
	}

	/**
	 * Render the admin page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-test-content-generator' ) );
		}

		$settings = wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			array(
				'post_type'             => 'post',
				'post_count'            => 10,
				'post_status'           => 'publish',
				'featured_image_mode'   => 'generated',
				'featured_image_id'     => 0,
				'taxonomy_settings'     => array(),
			)
		);

		$post_types       = self::get_available_post_types();
		$generated_count = self::get_generated_post_count();
		$image_count     = self::get_generated_image_count();
		$image_id        = absint( $settings['featured_image_id'] );
		?>
		<div class="wrap wptcg-wrap">
			<h1><?php esc_html_e( 'WP Test Content Generator', 'wp-test-content-generator' ); ?></h1>

			<p class="wptcg-intro">
				<?php esc_html_e( 'Create realistic placeholder content for posts, pages, and custom post types. Generated items are marked so they can be removed safely.', 'wp-test-content-generator' ); ?>
			</p>

			<div class="wptcg-card">
				<h2><?php esc_html_e( 'Generate test content', 'wp-test-content-generator' ); ?></h2>

				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="wptcg_generate_posts">
					<?php wp_nonce_field( 'wptcg_generate_posts', 'wptcg_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wptcg_post_type"><?php esc_html_e( 'Post type', 'wp-test-content-generator' ); ?></label>
								</th>
								<td>
									<select id="wptcg_post_type" name="post_type">
										<?php foreach ( $post_types as $post_type ) : ?>
											<option
												value="<?php echo esc_attr( $post_type->name ); ?>"
												data-supports-thumbnail="<?php echo post_type_supports( $post_type->name, 'thumbnail' ) ? '1' : '0'; ?>"
												<?php selected( $settings['post_type'], $post_type->name ); ?>
											>
												<?php echo esc_html( $post_type->labels->singular_name . ' (' . $post_type->name . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Choose the WordPress post type that should receive the generated content.', 'wp-test-content-generator' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="wptcg_post_count"><?php esc_html_e( 'Number of posts', 'wp-test-content-generator' ); ?></label>
								</th>
								<td>
									<input
										id="wptcg_post_count"
										name="post_count"
										type="number"
										min="1"
										max="<?php echo esc_attr( self::GENERATION_LIMIT ); ?>"
										value="<?php echo esc_attr( $settings['post_count'] ); ?>"
										class="small-text"
										required
									>
									<p class="description">
										<?php
										printf(
											/* translators: %d: maximum number of posts. */
											esc_html__( 'Create between 1 and %d posts at a time.', 'wp-test-content-generator' ),
											(int) self::GENERATION_LIMIT
										);
										?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="wptcg_post_status"><?php esc_html_e( 'Post status', 'wp-test-content-generator' ); ?></label>
								</th>
								<td>
									<select id="wptcg_post_status" name="post_status">
										<option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'wp-test-content-generator' ); ?></option>
										<option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'wp-test-content-generator' ); ?></option>
										<option value="pending" <?php selected( $settings['post_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'wp-test-content-generator' ); ?></option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>

					<div class="wptcg-section">
						<h2><?php esc_html_e( 'Featured image', 'wp-test-content-generator' ); ?></h2>
						<p id="wptcg-thumbnail-warning" class="notice notice-warning inline">
							<?php esc_html_e( 'The selected post type does not currently support featured images. Image settings will be ignored unless thumbnail support is enabled.', 'wp-test-content-generator' ); ?>
						</p>

						<label for="wptcg_featured_image_mode" class="wptcg-field-label"><?php esc_html_e( 'Image source', 'wp-test-content-generator' ); ?></label>
						<select id="wptcg_featured_image_mode" name="featured_image_mode">
							<option value="generated" <?php selected( $settings['featured_image_mode'], 'generated' ); ?>><?php esc_html_e( 'Generate a placeholder image for each post', 'wp-test-content-generator' ); ?></option>
							<option value="selected" <?php selected( $settings['featured_image_mode'], 'selected' ); ?>><?php esc_html_e( 'Use one selected Media Library image', 'wp-test-content-generator' ); ?></option>
							<option value="random_existing" <?php selected( $settings['featured_image_mode'], 'random_existing' ); ?>><?php esc_html_e( 'Use random existing Media Library images', 'wp-test-content-generator' ); ?></option>
							<option value="none" <?php selected( $settings['featured_image_mode'], 'none' ); ?>><?php esc_html_e( 'Do not add a featured image', 'wp-test-content-generator' ); ?></option>
						</select>

						<p class="description">
							<?php esc_html_e( 'Generated placeholders are copied into the Media Library and are removed by the cleanup tool.', 'wp-test-content-generator' ); ?>
						</p>

						<div id="wptcg-selected-image-settings" class="wptcg-image-picker">
							<input type="hidden" id="wptcg_featured_image_id" name="featured_image_id" value="<?php echo esc_attr( $image_id ); ?>">
							<div id="wptcg-image-preview" class="wptcg-image-preview">
								<?php if ( $image_id ) : ?>
									<?php echo wp_kses_post( wp_get_attachment_image( $image_id, 'medium' ) ); ?>
								<?php endif; ?>
							</div>
							<button type="button" class="button" id="wptcg-select-image"><?php esc_html_e( 'Choose Image', 'wp-test-content-generator' ); ?></button>
							<button type="button" class="button-link-delete" id="wptcg-remove-image"><?php esc_html_e( 'Remove selection', 'wp-test-content-generator' ); ?></button>
						</div>
					</div>

					<div class="wptcg-section">
						<h2><?php esc_html_e( 'Categories and taxonomy terms', 'wp-test-content-generator' ); ?></h2>
						<p><?php esc_html_e( 'Select existing terms, create new terms, and choose how they should be assigned to each generated post.', 'wp-test-content-generator' ); ?></p>

						<?php foreach ( $post_types as $post_type ) : ?>
							<?php
							$taxonomies        = self::get_assignable_taxonomies( $post_type->name );
							$post_type_settings = isset( $settings['taxonomy_settings'][ $post_type->name ] ) && is_array( $settings['taxonomy_settings'][ $post_type->name ] )
								? $settings['taxonomy_settings'][ $post_type->name ]
								: array();
							?>
							<div class="wptcg-taxonomy-panel" data-post-type="<?php echo esc_attr( $post_type->name ); ?>">
								<?php if ( empty( $taxonomies ) ) : ?>
									<p class="description"><?php esc_html_e( 'No assignable taxonomies are registered for this post type.', 'wp-test-content-generator' ); ?></p>
								<?php else : ?>
									<?php foreach ( $taxonomies as $taxonomy ) : ?>
										<?php
										$taxonomy_settings = isset( $post_type_settings[ $taxonomy->name ] ) && is_array( $post_type_settings[ $taxonomy->name ] )
											? $post_type_settings[ $taxonomy->name ]
											: array();
										$taxonomy_settings = wp_parse_args(
											$taxonomy_settings,
											array(
												'mode'     => 'none',
												'term_ids' => array(),
											)
										);
										$selected_term_ids = array_map( 'absint', (array) $taxonomy_settings['term_ids'] );
										$terms             = get_terms(
											array(
												'taxonomy'   => $taxonomy->name,
												'hide_empty' => false,
												'orderby'    => 'name',
												'order'      => 'ASC',
												'number'     => 500,
											)
										);
										?>
										<div class="wptcg-taxonomy-box">
											<h3><?php echo esc_html( $taxonomy->labels->name . ' (' . $taxonomy->name . ')' ); ?></h3>

											<label class="wptcg-field-label" for="wptcg-mode-<?php echo esc_attr( $post_type->name . '-' . $taxonomy->name ); ?>"><?php esc_html_e( 'Assignment method', 'wp-test-content-generator' ); ?></label>
											<select
												id="wptcg-mode-<?php echo esc_attr( $post_type->name . '-' . $taxonomy->name ); ?>"
												name="taxonomy_settings[<?php echo esc_attr( $post_type->name ); ?>][<?php echo esc_attr( $taxonomy->name ); ?>][mode]"
											>
												<option value="none" <?php selected( $taxonomy_settings['mode'], 'none' ); ?>><?php esc_html_e( 'Do not assign terms', 'wp-test-content-generator' ); ?></option>
												<option value="all" <?php selected( $taxonomy_settings['mode'], 'all' ); ?>><?php esc_html_e( 'Assign all selected terms', 'wp-test-content-generator' ); ?></option>
												<option value="random_one" <?php selected( $taxonomy_settings['mode'], 'random_one' ); ?>><?php esc_html_e( 'Assign one random selected term', 'wp-test-content-generator' ); ?></option>
												<option value="random_two" <?php selected( $taxonomy_settings['mode'], 'random_two' ); ?>><?php esc_html_e( 'Assign up to two random selected terms', 'wp-test-content-generator' ); ?></option>
											</select>

											<label class="wptcg-field-label" for="wptcg-terms-<?php echo esc_attr( $post_type->name . '-' . $taxonomy->name ); ?>"><?php esc_html_e( 'Existing terms', 'wp-test-content-generator' ); ?></label>
											<?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
												<select
													id="wptcg-terms-<?php echo esc_attr( $post_type->name . '-' . $taxonomy->name ); ?>"
													name="taxonomy_settings[<?php echo esc_attr( $post_type->name ); ?>][<?php echo esc_attr( $taxonomy->name ); ?>][terms][]"
													multiple
													size="<?php echo esc_attr( min( 8, max( 3, count( $terms ) ) ) ); ?>"
												>
													<?php foreach ( $terms as $term ) : ?>
														<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $selected_term_ids, true ) ); ?>>
															<?php echo esc_html( $term->name ); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<p class="description"><?php esc_html_e( 'Hold Ctrl on Windows or Command on Mac to select multiple terms.', 'wp-test-content-generator' ); ?></p>
											<?php else : ?>
												<p class="description"><?php esc_html_e( 'No existing terms were found.', 'wp-test-content-generator' ); ?></p>
											<?php endif; ?>

											<?php if ( current_user_can( $taxonomy->cap->manage_terms ) ) : ?>
												<label class="wptcg-field-label" for="wptcg-new-terms-<?php echo esc_attr( $post_type->name . '-' . $taxonomy->name ); ?>"><?php esc_html_e( 'Create new terms', 'wp-test-content-generator' ); ?></label>
												<textarea
													id="wptcg-new-terms-<?php echo esc_attr( $post_type->name . '-' . $taxonomy->name ); ?>"
													name="taxonomy_settings[<?php echo esc_attr( $post_type->name ); ?>][<?php echo esc_attr( $taxonomy->name ); ?>][new_terms]"
													rows="3"
													placeholder="<?php esc_attr_e( 'News, Guides, Featured', 'wp-test-content-generator' ); ?>"
												></textarea>
												<p class="description"><?php esc_html_e( 'Separate term names with commas or new lines. New terms are created once, then assigned using the method above.', 'wp-test-content-generator' ); ?></p>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>

					<?php submit_button( __( 'Generate Test Posts', 'wp-test-content-generator' ), 'primary large' ); ?>
				</form>
			</div>

			<div class="wptcg-card wptcg-cleanup-card">
				<h2><?php esc_html_e( 'Generated content cleanup', 'wp-test-content-generator' ); ?></h2>

				<p>
					<?php
					printf(
						/* translators: 1: generated posts, 2: generated images. */
						esc_html__( 'Currently tracked: %1$d generated posts and %2$d generated featured images.', 'wp-test-content-generator' ),
						(int) $generated_count,
						(int) $image_count
					);
					?>
				</p>

				<?php if ( $generated_count > 0 || $image_count > 0 ) : ?>
					<form
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						method="post"
						onsubmit="return confirm('<?php echo esc_js( __( 'Delete all posts and placeholder images created by this plugin? This cannot be undone.', 'wp-test-content-generator' ) ); ?>');"
					>
						<input type="hidden" name="action" value="wptcg_delete_posts">
						<?php wp_nonce_field( 'wptcg_delete_posts', 'wptcg_delete_nonce' ); ?>
						<?php submit_button( __( 'Delete All Generated Content', 'wp-test-content-generator' ), 'delete', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Generate posts from the submitted form.
	 */
	public static function generate_posts() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to generate posts.', 'wp-test-content-generator' ) );
		}

		check_admin_referer( 'wptcg_generate_posts', 'wptcg_nonce' );

		$post_type           = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$post_count          = isset( $_POST['post_count'] ) ? absint( $_POST['post_count'] ) : 1;
		$post_status         = isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : 'draft';
		$featured_image_mode = isset( $_POST['featured_image_mode'] ) ? sanitize_key( wp_unslash( $_POST['featured_image_mode'] ) ) : 'none';
		$featured_image_id   = isset( $_POST['featured_image_id'] ) ? absint( $_POST['featured_image_id'] ) : 0;

		$post_count = max( 1, min( self::GENERATION_LIMIT, $post_count ) );

		$allowed_statuses = array( 'publish', 'draft', 'pending' );
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			$post_status = 'draft';
		}

		$allowed_image_modes = array( 'generated', 'selected', 'random_existing', 'none' );
		if ( ! in_array( $featured_image_mode, $allowed_image_modes, true ) ) {
			$featured_image_mode = 'none';
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object || ! $post_type_object->show_ui ) {
			self::redirect_with_notice( 'invalid_post_type' );
		}

		if ( ! current_user_can( $post_type_object->cap->edit_posts ) ) {
			wp_die( esc_html__( 'You do not have permission to create this post type.', 'wp-test-content-generator' ) );
		}

		if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
			$featured_image_mode = 'none';
		}

		if ( 'selected' === $featured_image_mode && ! wp_attachment_is_image( $featured_image_id ) ) {
			$featured_image_mode = 'none';
			$featured_image_id   = 0;
		}

		$submitted_taxonomies = array();
		if ( isset( $_POST['taxonomy_settings'][ $post_type ] ) && is_array( $_POST['taxonomy_settings'][ $post_type ] ) ) {
			$submitted_taxonomies = wp_unslash( $_POST['taxonomy_settings'][ $post_type ] );
		}

		$taxonomy_assignments = self::prepare_taxonomy_assignments( $post_type, $submitted_taxonomies );
		$settings             = get_option( self::OPTION_NAME, array() );
		$all_taxonomy_settings = isset( $settings['taxonomy_settings'] ) && is_array( $settings['taxonomy_settings'] )
			? $settings['taxonomy_settings']
			: array();
		$all_taxonomy_settings[ $post_type ] = $taxonomy_assignments;

		update_option(
			self::OPTION_NAME,
			array(
				'post_type'           => $post_type,
				'post_count'          => $post_count,
				'post_status'         => $post_status,
				'featured_image_mode' => $featured_image_mode,
				'featured_image_id'   => $featured_image_id,
				'taxonomy_settings'   => $all_taxonomy_settings,
			)
		);

		$random_image_ids = array();
		if ( 'random_existing' === $featured_image_mode ) {
			$random_image_ids = self::get_existing_image_ids();
		}

		$created       = 0;
		$failed        = 0;
		$images_added  = 0;
		$image_failures = 0;

		for ( $index = 1; $index <= $post_count; $index++ ) {
			$content = self::build_fake_post_content( $index );

			$post_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_status'  => $post_status,
					'post_title'   => $content['title'],
					'post_excerpt' => $content['excerpt'],
					'post_content' => $content['content'],
					'post_author'  => get_current_user_id(),
					'meta_input'   => array(
						self::GENERATED_META => 1,
						'_wptcg_batch'        => gmdate( 'Y-m-d H:i:s' ),
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$failed++;
				continue;
			}

			self::assign_taxonomy_terms( $post_id, $taxonomy_assignments );

			$image_result = self::set_featured_image(
				$post_id,
				$content['title'],
				$featured_image_mode,
				$featured_image_id,
				$random_image_ids,
				$index
			);

			if ( true === $image_result ) {
				$images_added++;
			} elseif ( is_wp_error( $image_result ) ) {
				$image_failures++;
			}

			$created++;
		}

		$url = add_query_arg(
			array(
				'page'          => 'wptcg-test-content-generator',
				'wptcg'         => 'generated',
				'created'       => $created,
				'failed'        => $failed,
				'images_added'  => $images_added,
				'image_failed'  => $image_failures,
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Validate taxonomy choices, create requested terms, and return saved assignments.
	 *
	 * @param string $post_type Post type name.
	 * @param array  $submitted Submitted taxonomy settings.
	 * @return array
	 */
	private static function prepare_taxonomy_assignments( $post_type, $submitted ) {
		$assignments = array();
		$taxonomies  = self::get_assignable_taxonomies( $post_type );
		$valid_modes = array( 'none', 'all', 'random_one', 'random_two' );

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy_input = isset( $submitted[ $taxonomy->name ] ) && is_array( $submitted[ $taxonomy->name ] )
				? $submitted[ $taxonomy->name ]
				: array();

			$mode = isset( $taxonomy_input['mode'] ) ? sanitize_key( $taxonomy_input['mode'] ) : 'none';
			if ( ! in_array( $mode, $valid_modes, true ) ) {
				$mode = 'none';
			}

			$submitted_ids = isset( $taxonomy_input['terms'] ) ? array_map( 'absint', (array) $taxonomy_input['terms'] ) : array();
			$submitted_ids = array_values( array_unique( array_filter( $submitted_ids ) ) );
			$valid_ids     = array();

			if ( ! empty( $submitted_ids ) ) {
				$found_ids = get_terms(
					array(
						'taxonomy'   => $taxonomy->name,
						'hide_empty' => false,
						'fields'     => 'ids',
						'include'    => $submitted_ids,
					)
				);

				if ( ! is_wp_error( $found_ids ) ) {
					$valid_ids = array_map( 'absint', $found_ids );
				}
			}

			if ( 'none' !== $mode && current_user_can( $taxonomy->cap->manage_terms ) && ! empty( $taxonomy_input['new_terms'] ) ) {
				$new_term_names = preg_split( '/[\r\n,]+/', (string) $taxonomy_input['new_terms'] );
				$new_term_names = array_slice( array_filter( array_map( 'sanitize_text_field', $new_term_names ) ), 0, self::TERM_CREATION_LIMIT );

				foreach ( $new_term_names as $new_term_name ) {
					$existing_term = term_exists( $new_term_name, $taxonomy->name );

					if ( $existing_term ) {
						$term_id = is_array( $existing_term ) ? absint( $existing_term['term_id'] ) : absint( $existing_term );
					} else {
						$inserted_term = wp_insert_term( $new_term_name, $taxonomy->name );
						if ( is_wp_error( $inserted_term ) ) {
							continue;
						}
						$term_id = absint( $inserted_term['term_id'] );
					}

					if ( $term_id ) {
						$valid_ids[] = $term_id;
					}
				}
			}

			$valid_ids = array_values( array_unique( array_filter( array_map( 'absint', $valid_ids ) ) ) );

			if ( empty( $valid_ids ) ) {
				$mode = 'none';
			}

			$assignments[ $taxonomy->name ] = array(
				'mode'     => $mode,
				'term_ids' => $valid_ids,
			);
		}

		return $assignments;
	}

	/**
	 * Assign configured taxonomy terms to a generated post.
	 *
	 * @param int   $post_id     Post ID.
	 * @param array $assignments Prepared taxonomy assignments.
	 */
	private static function assign_taxonomy_terms( $post_id, $assignments ) {
		foreach ( $assignments as $taxonomy_name => $assignment ) {
			$term_ids = isset( $assignment['term_ids'] ) ? array_values( array_map( 'absint', (array) $assignment['term_ids'] ) ) : array();
			$mode     = isset( $assignment['mode'] ) ? sanitize_key( $assignment['mode'] ) : 'none';

			if ( 'none' === $mode || empty( $term_ids ) || ! taxonomy_exists( $taxonomy_name ) ) {
				continue;
			}

			shuffle( $term_ids );

			if ( 'random_one' === $mode ) {
				$term_ids = array_slice( $term_ids, 0, 1 );
			} elseif ( 'random_two' === $mode ) {
				$term_ids = array_slice( $term_ids, 0, min( 2, count( $term_ids ) ) );
			}

			wp_set_object_terms( $post_id, $term_ids, $taxonomy_name, false );
		}
	}

	/**
	 * Set the post's featured image according to the selected mode.
	 *
	 * @param int    $post_id             Generated post ID.
	 * @param string $title               Generated post title.
	 * @param string $mode                Featured image mode.
	 * @param int    $selected_image_id   Selected Media Library image ID.
	 * @param array  $random_image_ids    Existing image pool.
	 * @param int    $index               Post index.
	 * @return true|false|WP_Error
	 */
	private static function set_featured_image( $post_id, $title, $mode, $selected_image_id, $random_image_ids, $index ) {
		if ( 'none' === $mode ) {
			return false;
		}

		$image_id = 0;

		if ( 'selected' === $mode ) {
			$image_id = absint( $selected_image_id );
		} elseif ( 'random_existing' === $mode && ! empty( $random_image_ids ) ) {
			$image_id = absint( $random_image_ids[ array_rand( $random_image_ids ) ] );
		} elseif ( 'generated' === $mode ) {
			$image_id = self::create_placeholder_attachment( $post_id, $title, $index );
			if ( is_wp_error( $image_id ) ) {
				return $image_id;
			}
		}

		if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
			return new WP_Error( 'wptcg_invalid_image', __( 'A valid featured image could not be found.', 'wp-test-content-generator' ) );
		}

		return (bool) set_post_thumbnail( $post_id, $image_id );
	}

	/**
	 * Import one bundled placeholder image as an attachment.
	 *
	 * @param int    $post_id Parent post ID.
	 * @param string $title   Attachment title.
	 * @param int    $index   Generated item index.
	 * @return int|WP_Error
	 */
	private static function create_placeholder_attachment( $post_id, $title, $index ) {
		$placeholder_files = glob( plugin_dir_path( __FILE__ ) . 'assets/placeholders/*.jpg' );

		if ( empty( $placeholder_files ) ) {
			return new WP_Error( 'wptcg_missing_placeholders', __( 'No bundled placeholder images were found.', 'wp-test-content-generator' ) );
		}

		$source_file = $placeholder_files[ ( max( 1, absint( $index ) ) - 1 ) % count( $placeholder_files ) ];
		$upload_dir  = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'wptcg_upload_error', $upload_dir['error'] );
		}

		$filename         = wp_unique_filename( $upload_dir['path'], 'test-content-' . $post_id . '.jpg' );
		$destination_file = trailingslashit( $upload_dir['path'] ) . $filename;

		if ( ! copy( $source_file, $destination_file ) ) {
			return new WP_Error( 'wptcg_copy_failed', __( 'The placeholder image could not be copied into the uploads directory.', 'wp-test-content-generator' ) );
		}

		$filetype = wp_check_filetype( $filename, null );

		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => trailingslashit( $upload_dir['url'] ) . $filename,
				'post_mime_type' => $filetype['type'],
				'post_title'     => sanitize_text_field( $title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
			),
			$destination_file,
			$post_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $destination_file );
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $destination_file );
		if ( ! is_wp_error( $attachment_metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $attachment_metadata );
		}

		update_post_meta( $attachment_id, self::GENERATED_IMAGE_META, 1 );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );

		return $attachment_id;
	}

	/**
	 * Fetch image attachments available for random assignment.
	 *
	 * @return int[]
	 */
	private static function get_existing_image_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'     => self::GENERATED_IMAGE_META,
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Delete posts and placeholder images generated by the plugin.
	 */
	public static function delete_generated_posts() {
		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete posts.', 'wp-test-content-generator' ) );
		}

		check_admin_referer( 'wptcg_delete_posts', 'wptcg_delete_nonce' );

		$post_ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::GENERATED_META,
				'meta_value'     => 1,
				'no_found_rows'  => true,
			)
		);

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::GENERATED_IMAGE_META,
				'meta_value'     => 1,
				'no_found_rows'  => true,
			)
		);

		$deleted_posts  = 0;
		$deleted_images = 0;

		foreach ( $post_ids as $post_id ) {
			$post_type_object = get_post_type_object( get_post_type( $post_id ) );

			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->delete_post, $post_id ) ) {
				continue;
			}

			if ( wp_delete_post( $post_id, true ) ) {
				$deleted_posts++;
			}
		}

		if ( current_user_can( 'delete_posts' ) ) {
			foreach ( $attachment_ids as $attachment_id ) {
				if ( current_user_can( 'delete_post', $attachment_id ) && wp_delete_attachment( $attachment_id, true ) ) {
					$deleted_images++;
				}
			}
		}

		$url = add_query_arg(
			array(
				'page'           => 'wptcg-test-content-generator',
				'wptcg'          => 'deleted',
				'deleted'        => $deleted_posts,
				'deleted_images' => $deleted_images,
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Build a varied title, excerpt, and post body.
	 *
	 * @param int $index Item number in the current batch.
	 * @return array
	 */
	private static function build_fake_post_content( $index ) {
		$adjectives = array( 'Practical', 'Creative', 'Modern', 'Essential', 'Simple', 'Useful', 'Fresh', 'Complete', 'Flexible', 'Thoughtful' );
		$subjects   = array(
			'Ideas for Better Digital Projects',
			'Ways to Improve Your Workflow',
			'Notes from a Growing Business',
			'Guide to Planning Your Next Project',
			'Approaches to Better Customer Experiences',
			'Tips for Building a Stronger Website',
			'Lessons from Everyday Problem Solving',
			'Steps Towards Smarter Online Services',
			'Insights for Small Business Growth',
			'Methods for Creating Useful Content',
		);

		$introductions = array(
			'Good projects usually begin with a clear goal and a realistic plan. Taking time to understand the audience makes every later decision easier.',
			'Digital work is most effective when design, content, and technology move in the same direction. Small improvements can create surprisingly large results.',
			'A useful website should answer questions, remove friction, and guide visitors towards a sensible next step. Clarity often outperforms unnecessary complexity.',
			'Planning does not need to become a maze of meetings and documents. A focused outline can give a project enough structure without slowing it down.',
			'Every business has information worth sharing. The challenge is presenting it in a way that feels useful, human, and easy to explore.',
		);

		$middle_sections = array(
			'<h2>Start with the main objective</h2><p>Define what the page, service, or campaign should achieve. A single clear objective helps shape the layout, wording, calls to action, and measurements of success.</p>',
			'<h2>Keep the experience straightforward</h2><p>Visitors should not need a treasure map to find important information. Use descriptive headings, concise paragraphs, and obvious routes through the content.</p>',
			'<h2>Build with future updates in mind</h2><p>Content and features will evolve over time. Reusable components and sensible content structures make those future changes quicker and safer.</p>',
			'<h2>Review real user needs</h2><p>Useful decisions come from understanding common questions, concerns, and goals. Feedback can reveal where an experience feels smooth and where it develops sharp corners.</p>',
			'<h2>Measure what matters</h2><p>Choose a small number of meaningful signals, such as enquiries, bookings, downloads, or completed purchases. Vanity numbers can sparkle while saying very little.</p>',
		);

		$conclusions = array(
			'The strongest approach is usually the one that balances ambition with clarity. Begin with the essentials, learn from the results, and improve from there.',
			'A steady series of useful changes is often more valuable than one enormous redesign. Progress becomes easier to manage and simpler to measure.',
			'Good digital experiences feel calm on the surface because the complicated thinking has already happened behind the scenes.',
			'The next step is to choose one improvement, put it into practice, and see what it teaches you.',
			'With a clear purpose and a flexible structure, the project has room to grow without losing its shape.',
		);

		$adjective    = $adjectives[ array_rand( $adjectives ) ];
		$subject      = $subjects[ array_rand( $subjects ) ];
		$introduction = $introductions[ array_rand( $introductions ) ];
		$middle_one   = $middle_sections[ array_rand( $middle_sections ) ];
		$middle_two   = $middle_sections[ array_rand( $middle_sections ) ];
		$conclusion   = $conclusions[ array_rand( $conclusions ) ];

		while ( $middle_two === $middle_one && count( $middle_sections ) > 1 ) {
			$middle_two = $middle_sections[ array_rand( $middle_sections ) ];
		}

		$title = sprintf( '%1$s %2$s #%3$d', $adjective, $subject, (int) $index );

		$content = sprintf(
			'<p>%1$s</p>%2$s%3$s<h2>Moving forward</h2><p>%4$s</p>',
			esc_html( $introduction ),
			wp_kses_post( $middle_one ),
			wp_kses_post( $middle_two ),
			esc_html( $conclusion )
		);

		return array(
			'title'   => $title,
			'excerpt' => wp_trim_words( $introduction, 24, '…' ),
			'content' => $content,
		);
	}

	/**
	 * Count generated posts.
	 *
	 * @return int
	 */
	private static function get_generated_post_count() {
		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::GENERATED_META,
				'meta_value'     => 1,
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Count generated placeholder attachments.
	 *
	 * @return int
	 */
	private static function get_generated_image_count() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::GENERATED_IMAGE_META,
				'meta_value'     => 1,
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Display result notices after redirects.
	 */
	public static function display_admin_notice() {
		if ( empty( $_GET['page'] ) || 'wptcg-test-content-generator' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$notice = isset( $_GET['wptcg'] ) ? sanitize_key( wp_unslash( $_GET['wptcg'] ) ) : '';

		if ( 'generated' === $notice ) {
			$created       = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
			$failed        = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
			$images_added  = isset( $_GET['images_added'] ) ? absint( $_GET['images_added'] ) : 0;
			$image_failed  = isset( $_GET['image_failed'] ) ? absint( $_GET['image_failed'] ) : 0;

			$message = sprintf(
				/* translators: 1: created count, 2: failed count, 3: images added, 4: image failures. */
				__( 'Created %1$d test posts. %2$d posts failed. Added %3$d featured images, with %4$d image failures.', 'wp-test-content-generator' ),
				$created,
				$failed,
				$images_added,
				$image_failed
			);

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( 'deleted' === $notice ) {
			$deleted        = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0;
			$deleted_images = isset( $_GET['deleted_images'] ) ? absint( $_GET['deleted_images'] ) : 0;

			$message = sprintf(
				/* translators: 1: deleted posts, 2: deleted images. */
				__( 'Deleted %1$d generated posts and %2$d generated featured images.', 'wp-test-content-generator' ),
				$deleted,
				$deleted_images
			);

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( 'invalid_post_type' === $notice ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'The selected post type is not available.', 'wp-test-content-generator' )
			);
		}
	}

	/**
	 * Redirect back to the plugin page with a notice.
	 *
	 * @param string $notice Notice code.
	 */
	private static function redirect_with_notice( $notice ) {
		$url = add_query_arg(
			array(
				'page'  => 'wptcg-test-content-generator',
				'wptcg' => sanitize_key( $notice ),
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}

WPTCG_Test_Content_Generator::init();
