<?php
/**
 * Text chunking functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text chunker class for splitting long content into API-compatible chunks.
 */
class ListenUp_Text_Chunker {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Text_Chunker
	 */
	private static $instance = null;

	/**
	 * Maximum characters per chunk (with safety buffer).
	 *
	 * @var int
	 */
	private $max_chunk_length = 2800;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Text_Chunker
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
		// No hooks needed for this class.
	}

	/**
	 * Split text into chunks that fit within API limits.
	 *
	 * @param string $text Text to split.
	 * @return array Array of text chunks with metadata.
	 */
	public function split_text_into_chunks( $text ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Starting text chunking process' );
		$debug->info( 'Original text length: ' . strlen( $text ) . ' characters' );

		// Clean and prepare text first.
		$text = $this->prepare_text( $text );
		$text_length = strlen( $text );

		// If text is within limits, return as single chunk.
		if ( $text_length <= $this->max_chunk_length ) {
			$debug->info( 'Text fits in single chunk, no splitting needed' );
			return array(
				array(
					'text' => $text,
					'chunk_number' => 1,
					'total_chunks' => 1,
					'length' => $text_length,
				),
			);
		}

		$debug->info( 'Text exceeds limit, splitting into chunks' );

		// Split by sentences first.
		$chunks = $this->split_by_sentences( $text );

		// If we still have chunks that are too long, split them further by words.
		$final_chunks = array();
		foreach ( $chunks as $index => $chunk ) {
			if ( strlen( $chunk ) <= $this->max_chunk_length ) {
				$final_chunks[] = array(
					'text' => $chunk,
					'chunk_number' => $index + 1,
					'total_chunks' => count( $chunks ),
					'length' => strlen( $chunk ),
				);
			} else {
				// Split by words as fallback.
				$word_chunks = $this->split_by_words( $chunk );
				foreach ( $word_chunks as $word_index => $word_chunk ) {
					$final_chunks[] = array(
						'text' => $word_chunk,
						'chunk_number' => count( $final_chunks ) + 1,
						'total_chunks' => count( $chunks ) + count( $word_chunks ) - 1,
						'length' => strlen( $word_chunk ),
					);
				}
			}
		}

		// Update total_chunks for all chunks.
		$total_chunks = count( $final_chunks );
		foreach ( $final_chunks as $index => $chunk ) {
			$final_chunks[ $index ]['total_chunks'] = $total_chunks;
		}

		$debug->info( 'Text split into ' . $total_chunks . ' chunks' );
		foreach ( $final_chunks as $chunk ) {
			$debug->info( 'Chunk ' . $chunk['chunk_number'] . ': ' . $chunk['length'] . ' characters' );
		}

		return $final_chunks;
	}

	/**
	 * Split text by sentences.
	 *
	 * @param string $text Text to split.
	 * @return array Array of text chunks.
	 */
	private function split_by_sentences( $text ) {
		// Split by sentences using regex that looks for sentence endings.
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		$chunks = array();
		$current_chunk = '';
		$current_length = 0;

		foreach ( $sentences as $sentence ) {
			$sentence_length = strlen( $sentence );

			// If adding this sentence would exceed the limit, start a new chunk.
			if ( $current_length + $sentence_length + 1 > $this->max_chunk_length && ! empty( $current_chunk ) ) {
				$chunks[] = trim( $current_chunk );
				$current_chunk = $sentence;
				$current_length = $sentence_length;
			} else {
				// Add sentence to current chunk.
				$current_chunk .= ( ! empty( $current_chunk ) ? ' ' : '' ) . $sentence;
				$current_length += $sentence_length + ( ! empty( $current_chunk ) ? 1 : 0 );
			}
		}

		// Add the last chunk if it's not empty.
		if ( ! empty( $current_chunk ) ) {
			$chunks[] = trim( $current_chunk );
		}

		return $chunks;
	}

	/**
	 * Split text by words when sentence splitting isn't sufficient.
	 *
	 * @param string $text Text to split.
	 * @return array Array of text chunks.
	 */
	private function split_by_words( $text ) {
		$chunks = array();
		$words = explode( ' ', $text );

		$current_chunk = '';
		$current_length = 0;

		foreach ( $words as $word ) {
			$word_length = strlen( $word );

			// If adding this word would exceed the limit, start a new chunk.
			if ( $current_length + $word_length + 1 > $this->max_chunk_length && ! empty( $current_chunk ) ) {
				$chunks[] = trim( $current_chunk );
				$current_chunk = $word;
				$current_length = $word_length;
			} else {
				// Add word to current chunk.
				$current_chunk .= ( ! empty( $current_chunk ) ? ' ' : '' ) . $word;
				$current_length += $word_length + ( ! empty( $current_chunk ) ? 1 : 0 );
			}
		}

		// Add the last chunk if it's not empty.
		if ( ! empty( $current_chunk ) ) {
			$chunks[] = trim( $current_chunk );
		}

		return $chunks;
	}

	/**
	 * Prepare text for chunking.
	 *
	 * @param string $text Raw text.
	 * @return string Cleaned text.
	 */
	private function prepare_text( $text ) {
		// Remove HTML tags.
		$text = wp_strip_all_tags( $text );

		// Decode HTML entities.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		// Remove extra whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return $text;
	}

	/**
	 * Get the maximum chunk length.
	 *
	 * @return int Maximum characters per chunk.
	 */
	public function get_max_chunk_length() {
		return $this->max_chunk_length;
	}

	/**
	 * Set the maximum chunk length.
	 *
	 * @param int $length Maximum characters per chunk.
	 */
	public function set_max_chunk_length( $length ) {
		$this->max_chunk_length = intval( $length );
	}
}
