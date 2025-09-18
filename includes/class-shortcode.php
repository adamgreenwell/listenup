<?php
/**
 * Shortcode functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode class for [listenup] functionality.
 */
class ListenUp_Shortcode {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Shortcode
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Shortcode
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
		add_shortcode( 'listenup', array( $this, 'listenup_shortcode' ) );
	}

	/**
	 * Handle [listenup] shortcode.
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Shortcode content (not used).
	 * @return string Shortcode output.
	 */
	public function listenup_shortcode( $atts, $content = '' ) {
		// Parse shortcode attributes.
		$atts = shortcode_atts( array(
			'post_id' => 0,
		), $atts, 'listenup' );

		$post_id = intval( $atts['post_id'] );

		// If no post_id specified, use current post.
		if ( ! $post_id ) {
			global $post;
			$post_id = $post ? $post->ID : 0;
		}

		if ( ! $post_id ) {
			return '<p class="listenup-error">' . esc_html__( 'No post specified for audio player.', 'listenup' ) . '</p>';
		}

		// Get the frontend instance to generate the player.
		$frontend = ListenUp_Frontend::get_instance();
		return $frontend->get_audio_player_for_shortcode( $post_id );
	}
}
