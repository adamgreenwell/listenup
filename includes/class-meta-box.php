<?php
/**
 * Meta box functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta box class for audio generation.
 */
class ListenUp_Meta_Box {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Meta_Box
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Meta_Box
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'wp_ajax_listenup_generate_audio', array( $this, 'ajax_generate_audio' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Add meta box to post edit screens.
	 */
	public function add_meta_box() {
		$post_types = array( 'post', 'page' );
		
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'listenup-audio-generation',
				/* translators: Meta box title */
				__( 'ListenUp', 'listenup' ),
				array( $this, 'meta_box_callback' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Meta box callback.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function meta_box_callback( $post ) {
		// Add nonce for security.
		wp_nonce_field( 'listenup_meta_box', 'listenup_meta_box_nonce' );

		// Check if audio already exists.
		$cache = ListenUp_Cache::get_instance();
		$cached_audio = $cache->get_cached_audio( $post->ID );

		?>
		<div id="listenup-meta-box">
			<?php if ( $cached_audio ) : ?>
				<div class="listenup-audio-exists">
					<p><strong><?php /* translators: Status message when audio exists */ esc_html_e( 'Audio Available', 'listenup' ); ?></strong></p>
					<p><?php /* translators: Description when audio exists */ esc_html_e( 'Audio has been generated for this content.', 'listenup' ); ?></p>
					<button type="button" id="listenup-regenerate" class="button button-secondary">
						<?php /* translators: Button to regenerate audio */ esc_html_e( 'Regenerate Audio', 'listenup' ); ?>
					</button>
					<button type="button" id="listenup-delete" class="button button-link-delete">
						<?php /* translators: Button to delete audio */ esc_html_e( 'Delete Audio', 'listenup' ); ?>
					</button>
				</div>
			<?php else : ?>
				<div class="listenup-no-audio">
					<p><?php /* translators: Message when no audio exists */ esc_html_e( 'No audio has been generated for this content yet.', 'listenup' ); ?></p>
					<button type="button" id="listenup-generate" class="button button-primary">
						<?php /* translators: Button to generate audio */ esc_html_e( 'Generate Audio', 'listenup' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<div id="listenup-status" class="listenup-status" style="display: none;">
				<p class="listenup-loading">
					<span class="spinner is-active"></span>
					<?php /* translators: Loading message during audio generation */ esc_html_e( 'Generating audio...', 'listenup' ); ?>
				</p>
			</div>

			<div id="listenup-messages" class="listenup-messages"></div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for audio generation.
	 */
	public function ajax_generate_audio() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'listenup_meta_box' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'listenup' ) );
		}

		// Check user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'listenup' ) );
		}

		// Validate and sanitize input.
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( __( 'Invalid post ID.', 'listenup' ) );
		}
		$post_id = intval( $_POST['post_id'] );
		
		if ( ! isset( $_POST['action_type'] ) ) {
			wp_send_json_error( __( 'Invalid action.', 'listenup' ) );
		}
		$action = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'listenup' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( __( 'Post not found.', 'listenup' ) );
		}

		switch ( $action ) {
			case 'generate':
				$this->handle_generate_audio( $post );
				break;
			case 'regenerate':
				$this->handle_regenerate_audio( $post );
				break;
			case 'delete':
				$this->handle_delete_audio( $post );
				break;
			default:
				wp_send_json_error( __( 'Invalid action.', 'listenup' ) );
		}
	}

	/**
	 * Handle audio generation.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function handle_generate_audio( $post ) {
		// Get post content.
		$content = $post->post_content;
		
		// Remove shortcodes and apply content filters.
		$content = do_shortcode( $content );
		$content = apply_filters( 'the_content', $content );
		
		// Generate audio.
		$api = ListenUp_API::get_instance();
		$result = $api->generate_audio( $content, $post->ID );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'message' => __( 'Audio generated successfully!', 'listenup' ),
			'audio_url' => $result['audio_url'],
		) );
	}

	/**
	 * Handle audio regeneration.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function handle_regenerate_audio( $post ) {
		// Clear existing cache.
		$cache = ListenUp_Cache::get_instance();
		$cache->clear_post_cache( $post->ID );

		// Generate new audio.
		$this->handle_generate_audio( $post );
	}

	/**
	 * Handle audio deletion.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function handle_delete_audio( $post ) {
		$cache = ListenUp_Cache::get_instance();
		$result = $cache->clear_post_cache( $post->ID );

		if ( $result ) {
			wp_send_json_success( array(
				'message' => __( 'Audio deleted successfully.', 'listenup' ),
			) );
		} else {
			wp_send_json_error( __( 'Failed to delete audio.', 'listenup' ) );
		}
	}

	/**
	 * Enqueue admin scripts for meta box.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		global $post_type;

		// Only load on post edit screens.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Only load for supported post types.
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'listenup-meta-box',
			LISTENUP_PLUGIN_URL . 'admin-ui/assets/js/meta-box.js',
			array( 'jquery' ),
			LISTENUP_VERSION,
			true
		);

		wp_localize_script( 'listenup-meta-box', 'listenupMetaBox', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'listenup_meta_box' ),
			'postId' => get_the_ID(),
			'strings' => array(
				'generating' => __( 'Generating audio...', 'listenup' ),
				'success' => __( 'Audio generated successfully!', 'listenup' ),
				'error' => __( 'Error generating audio. Please try again.', 'listenup' ),
				'deleting' => __( 'Deleting audio...', 'listenup' ),
				'deleted' => __( 'Audio deleted successfully.', 'listenup' ),
			),
		) );

		wp_enqueue_style(
			'listenup-meta-box',
			LISTENUP_PLUGIN_URL . 'admin-ui/assets/css/meta-box.css',
			array(),
			LISTENUP_VERSION
		);
	}
}
