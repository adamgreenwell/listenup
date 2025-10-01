<?php
/**
 * Library shortcode functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Library shortcode class for displaying posts with audio.
 */
class ListenUp_Library_Shortcode {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Library_Shortcode
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Library_Shortcode
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
		add_shortcode( 'listenup_library', array( $this, 'render_library' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue library styles.
	 */
	public function enqueue_styles() {
		// Only enqueue if shortcode is present on the page.
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'listenup_library' ) ) {
			wp_enqueue_style(
				'listenup-library',
				LISTENUP_PLUGIN_URL . 'assets/css/library.css',
				array( 'listenup-frontend' ),
				LISTENUP_VERSION
			);

			wp_enqueue_script(
				'listenup-library',
				LISTENUP_PLUGIN_URL . 'assets/js/library.js',
				array( 'jquery', 'listenup-frontend' ),
				LISTENUP_VERSION,
				true
			);
		}
	}

	/**
	 * Render library shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_library( $atts ) {
		// Parse shortcode attributes.
		$atts = shortcode_atts(
			array(
				'posts_per_page' => 10,
				'post_type'      => 'post,page',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'show_excerpt'   => 'yes',
				'show_date'      => 'yes',
				'show_player'    => 'no',
				'columns'        => '1',
			),
			$atts,
			'listenup_library'
		);

		// Sanitize attributes.
		$posts_per_page = intval( $atts['posts_per_page'] );
		$post_types     = array_map( 'trim', explode( ',', sanitize_text_field( $atts['post_type'] ) ) );
		$orderby        = sanitize_text_field( $atts['orderby'] );
		$order          = strtoupper( sanitize_text_field( $atts['order'] ) );
		$show_excerpt   = 'yes' === $atts['show_excerpt'];
		$show_date      = 'yes' === $atts['show_date'];
		$show_player    = 'yes' === $atts['show_player'];
		$columns        = intval( $atts['columns'] );

		// Validate columns.
		if ( $columns < 1 || $columns > 3 ) {
			$columns = 1;
		}

		// Query posts with audio.
		$query_args = array(
			'post_type'      => $post_types,
			'posts_per_page' => $posts_per_page,
			'orderby'        => $orderby,
			'order'          => $order,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_listenup_audio',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_listenup_chunked_audio',
					'compare' => 'EXISTS',
				),
			),
		);

		$audio_posts = new WP_Query( $query_args );

		// Start output buffering.
		ob_start();

		if ( $audio_posts->have_posts() ) {
			?>
			<div class="listenup-library listenup-library-columns-<?php echo esc_attr( $columns ); ?>">
				<?php
				while ( $audio_posts->have_posts() ) {
					$audio_posts->the_post();
					$this->render_library_item( get_the_ID(), $show_excerpt, $show_date, $show_player );
				}
				?>
			</div>
			<?php
			wp_reset_postdata();
		} else {
			?>
			<p class="listenup-library-empty">
				<?php
				/* translators: Message when no posts with audio are found */
				esc_html_e( 'No audio content available at this time.', 'listenup' );
				?>
			</p>
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * Render a single library item.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $show_excerpt Whether to show excerpt.
	 * @param bool $show_date Whether to show date.
	 * @param bool $show_player Whether to show inline player.
	 */
	private function render_library_item( $post_id, $show_excerpt, $show_date, $show_player ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );
		$excerpt   = get_the_excerpt( $post_id );
		$date      = get_the_date( '', $post_id );
		?>
		<article class="listenup-library-item" data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<?php if ( has_post_thumbnail( $post_id ) ) : ?>
				<div class="listenup-library-thumbnail">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php echo get_the_post_thumbnail( $post_id, 'medium' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<div class="listenup-library-content">
				<h3 class="listenup-library-title">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php echo esc_html( $title ); ?>
					</a>
				</h3>

				<?php if ( $show_date ) : ?>
					<time class="listenup-library-date" datetime="<?php echo esc_attr( get_the_date( 'c', $post_id ) ); ?>">
						<?php echo esc_html( $date ); ?>
					</time>
				<?php endif; ?>

				<?php if ( $show_excerpt && ! empty( $excerpt ) ) : ?>
					<div class="listenup-library-excerpt">
						<?php echo wp_kses_post( wpautop( $excerpt ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $show_player ) : ?>
					<div class="listenup-library-player">
						<?php
						$frontend = ListenUp_Frontend::get_instance();
						echo $frontend->get_audio_player_for_shortcode( $post_id );
						?>
					</div>
				<?php else : ?>
					<div class="listenup-library-actions">
						<a href="<?php echo esc_url( $permalink ); ?>" class="listenup-library-listen-link">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 64 64" fill="currentColor">
								<path d="M54.56,31.171l-40-27A1,1,0,0,0,13,5V59a1,1,0,0,0,1.56.829l40-27a1,1,0,0,0,0-1.658Z"/>
							</svg>
							<?php
							/* translators: Link text to listen to audio */
							esc_html_e( 'Listen Now', 'listenup' );
							?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}
}
