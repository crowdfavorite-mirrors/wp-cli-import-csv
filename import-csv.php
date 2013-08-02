<?php 
/**
 * @package WP-CLI ImportCSV Command
 * @author 10up / Jeff Sebring <jeff@10up.com>
 * @link http://10up.com
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPLv3
 */

// Define command
WP_CLI::add_command( 'importcsv', 'ImportCSV_Command' );

class ImportCSV_Command extends WP_CLI_Command {

	/**
	 * parsed import file
	 * @var array
	 */
	public $csv = null;

	/**
	 * import file
	 * @var string
	 */
	public $file = null;

	/**
	 * post author
	 * @var string|integer
	 */
	public $author = null;

	/**
	 * import file data
	 * @var array
	 */
	public $data = array();

	/**
	 * parsed import file header
	 * @var array
	 */
	public $headers = null;

	/**
	 * import post type
	 * @var string
	 */
	public $post_type = null;

	/**
	 * prepending thumbnail path
	 * @var string
	 */
	public $thumbnail_path = null;

	/**
	 * import file data
	 * @synopsis write --post_type=<post>
	 * @access public
	 * @param $args array command arguments
	 * @param $assoc_args associative command arguments
	 * @return bool true on success
	 */
	public function write( $args, $assoc_args ) {

		$this->map( $args, $assoc_args );

		// Confirm data write
		WP_CLI::confirm( 'Ready to write all the things to the database?', '' );

		if ( ! is_array( $this->data ) ) {

			return WP_CLI::error( 'no data to import...' );

		}

		// Loop through mapped data and save each type
		foreach ( $this->data as $k => $v ) {

			if ( isset( $v[ 'post' ] ) ) {

				// All posts need a title, 
				if ( ! isset( $v[ 'post' ][ 'post_title' ] ) ) {

					WP_CLI::warning( 'row #' . $k . ' skipped - needs a post title...' );

					continue;

				}

				if ( ! $post_id = $this->_insert( $v[ 'post' ] ) ) {

					WP_CLI::warning( 'row #' . $k . ' insert/update failed..' );

					continue;

				}

				if ( isset( $post_id ) && isset( $v[ 'meta' ] ) ) {

					$this->_meta( $post_id, $v[ 'meta' ] );

				}

				if ( isset( $v[ 'taxonomy' ] ) ) {

					$this->_taxonomies( $post_id, $v[ 'taxonomy' ] );

				}

				if ( isset( $v[ 'thumbnail' ] ) ) {

					if ( isset( $v[ 'post' ][ 'post_author' ] ) ) {

						$author = $v[ 'post' ][ 'post_author' ];

					} else {

						$author = $this->author;

					}

					// Suppress Imagick::queryFormats strict static method error from WP core
					@$this->_thumbnails( $post_id, $v[ 'thumbnail' ], $v[ 'post' ][ 'post_title' ], $author );

				}

			}

			WP_CLI::success( 'row #' . $k . ' imported successful to post id ' . $post_id . '...' );

		}

		return true;

	}

	/**
	 * Map row data based on csv file headers
	 * @synopsis map --post_type=<post>
	 * @access public
	 * @param $args array command arguments
	 * @param $assoc_args associative command arguments
	 * @return bool true on success
	 */
	public function map( $args, $assoc_args ) {

		$this->check( $args, $assoc_args );

		$num = 1;

		while ( ( $row = fgetcsv( $this->file ) ) !== false ) {

			foreach ( $this->headers as $k => $v ) {

				if ( $v[ 'name' ] == 'blank' ) {

					continue;

				}

				$this->data[ $num ][ $v[ 'type' ] ][ $v[ 'name' ] ][ 'sanitize' ] = $v[ 'sanitize' ];
				$this->data[ $num ][ $v[ 'type' ] ][ $v[ 'name' ] ][ 'value' ] = $row[ $k ];

				if ( $v[ 'type' ] == 'thumbnail' ) {

					WP_CLI::line( 'row #' . $num . ' ' . $v[ 'name' ] . ' will sideloaded from ' . $row[ $k ] . '...' );

				} else {

					WP_CLI::line( 'row #' . $num . ' ' . $v[ 'name' ] . ' mapped to ' . $v[ 'type' ] . ' as ' . $row[ $k ] . '...' );

				}

			}

			$num++;

		}

		fclose( $this->file );

		return true;

	}

	/**
	 * Checks and reads import file
	 * @synopsis check --post_type=<post>
	 * @access public
	 * @param $args array command arguments
	 * @param $assoc_args associative command arguments
	 * @return bool true on success
	 */
	public function check( $args, $assoc_args ) {

		// set thumbnail path
		if ( isset( $assoc_args[ 'thumbnail_path' ] ) ) {

			$this->thumbnail_path = $assoc_args[ 'thumbnail_path' ];

		}

		// set author
		if ( isset( $assoc_args[ 'author' ] ) ) {

			$this->author = $assoc_args[ 'author' ];

		}

		// set verbose switch for success messages
		if ( isset( $assoc_args[ 'verbose' ] ) && $assoc_args[ 'verbose' ] == 'true' ) {

			$this->verbose = true;

		}

		// set filename
		if ( isset( $args[ 0 ] ) ) {

			$this->file = $args[ 0 ];

		} else {

			return WP_CLI::error( 'file not specified...' );

		}

		// Check post type
		if ( post_type_exists( $assoc_args[ 'post_type' ] ) ) {

			$this->post_type = $assoc_args[ 'post_type' ];
			WP_CLI::success( 'file ' . $this->file . ' will be imported as post type ' . $this->post_type );

		} else {

			return WP_CLI::error( $assoc_args[ 'post_type' ] . ' post type does not exist' );

		}

		// parse file headers
		$this->_headers();

		WP_CLI::success( 'congratulations, we can import!' );

		return true;

	}

	/**
	 * parse headers
	 * @access private
	 * @return bool true on success
	 */
	private function _headers() {

		$this->_read();

		$blanks = 0;

		foreach ( $this->csv as $raw_header ) {

			$header = explode( '-', $raw_header );

			if ( in_array( $header[ 0 ], array( 'blank', '' ) ) ) {

				$this->headers[] = array(
					'type' => 'blank',
					'sanitize' => 'esc_attr',
					'name' => 'blank'
				);

				continue;

			}

			if ( ! isset( $header[ 0 ] ) || ! in_array( $header[ 0 ], array( 'post', 'meta', 'taxonomy', 'thumbnail', 'blank' ) ) ) {

				WP_CLI::error( $raw_header . ' - ' . $header[ 0 ] . ' is an invalid field type. possible types are meta, post, taxonomy, thumbnail' );

				continue;

			}

			if ( ! isset( $header[ 1 ] ) ||  ! function_exists( $header[ 1 ] ) ) {

				WP_CLI::error( $raw_header . ' - ' . $header[ 1 ] . ' is an undefined function. ensure your sanitization function exists' );

				continue;

			}

			if ( ! isset( $header[ 0 ] ) || $header[ 0 ] == 'taxonomy' && ! taxonomy_exists( $header[ 2 ] ) ) {

				WP_CLI::error( $raw_header . ' - ' . $header[ 2 ] . ' is an not a registered taxonomy.' );

				continue;

			}

			$validated_header = array(
				'type' => $header[ 0 ],
				'sanitize' => $header[ 1 ],
				'name' => $header[ 2 ]
			);

			$this->headers[] = $validated_header;

			WP_CLI::line( 'header "' . $validated_header[ 'name' ] . '" - "' . $validated_header[ 'type' ] . '" data - sanitized with "' . $validated_header[ 'sanitize' ] . '"' );

		}


		if ( count( $this->csv ) !== count( $this->headers ) ) {

			return WP_CLI::error( 'headers are incorrectly formatted, try again...' );

		}

		WP_CLI::confirm( 'Is this what you had in mind? ', '' );
		WP_CLI::success( 'headers are correctly formatted, great job!' );

		return true;

	}

	/**
	 * read import file
	 * @access private
	 * @return bool true on success
	 */
	private function _read() {

		$this->_open();

		if ( ! $this->csv = fgetcsv( $this->file ) ) {

			return WP_CLI::error( 'unable to read file ' . $this->file . ', check formatting...' );

		}

		WP_CLI::success( 'file ' . $this->file . ' read...' );

		return true;

	}

	/**
	 * open import file
	 * @access private
	 * @return bool true on success
	 */
	private function _open() {

		$this->_exists();

		if ( ! $this->file = fopen( $this->file, 'r' ) ) {

			return WP_CLI::error( 'unable to open file ' . $this->file . ', check permissions...' );

		}

		WP_CLI::success( 'file ' . $this->file . ' opened...' );

		return true;

	}

	/**
	 * Check if import file exists
	 * @access private
	 * @return bool true on success
	 */
	private function _exists() {

		if ( ! file_exists( $this->file ) ) {

			return WP_CLI::error( 'file ' . $this->file . ' does not exist...' );

		}

		WP_CLI::success( 'file ' . $this->file . ' exists...' );

		return true;

	}

	/**
	 * insert row post data
	 * @access private
	 * @param  $post_data post data to be saved
	 * @return bool true on success
	 */
	private function _insert( $post_data ) {

		$post[ 'post_title' ] = $post_data[ 'post_title' ];

		if ( isset( $post_data[ 'post_type' ] ) && post_type_exists( $post_data[ 'post_type' ] ) ) {

			WP_CLI::warning( 'post type ' . $post_data[ 'post_type' ] . ' validated and over-riding ' . $this->post_type  );

			$post[ 'post_type' ] = $post_data[ 'post_type' ];

		} else {

			$post[ 'post_type' ] = $this->post_type;

		}

		// Post Field Validation array
		$post_fields = array(
			'post_author',
			'post_category',
			'post_content',
			'post_date',
			'post_date_gmt',
			'post_excerpt',
			'post_name',
			'post_password',
			'post_status',
			'post_title',
			'ID',
			'menu_order',
			'comment_status',
			'ping_status',
			'pinged',
			'tags_input',
			'to_ping',
			'tax_input'
		);

		foreach ( $post_data as $k => $v ) {

			if ( in_array( $k, $post_fields ) ) {

				$sanitize = $v[ 'sanitize' ];
				$post[ $k ] = $sanitize( $v[ 'value' ] );

			}

		}

		if ( isset( $post[ 'post_author' ] ) && is_int( $post[ 'post_author' ] ) ) {

			// wheeee

		} elseif ( isset($post[ 'post_author' ] ) && is_string( $post[ 'post_author' ] ) && ( $author_id = username_exists( $post[ 'post_author' ] ) ) ) {

			$post[ 'post_author' ] = $author_id;

		} elseif ( isset( $this->author ) && is_int( $this->author ) ) {

			$post[ 'post_author' ] = $this->author;

		} elseif ( $this->author && is_string( $this->author ) && ( $author_id = username_exists( $this->author ) ) ) {

			$post[ 'post_author' ] = $author_id;

		}

		if ( ! is_wp_error( $post_id = wp_insert_post( $post ) ) ) {

			WP_CLI::success( 'post id ' . $post_id . ' created' );

			foreach ( $post as $k => $v ) {

				WP_CLI::success( 'post id ' . $post_id . ' ' . $k . ' inserted as ' . $v );

			}

		} elseif ( ! is_wp_error( $post_id = wp_update_post( $post, true ) ) ) {

			WP_CLI::success( 'post id ' . $post_id . ' updated' );

			foreach ( $post as $k => $v ) {

				WP_CLI::success( 'post id ' . $post_id . ' ' . $k . ' updated as ' . $v );

			}

		} elseif ( is_wp_error( $post_id ) ) {

			foreach ( $post_id->errors as $error ) {

				WP_CLI::warning( $error[ 0 ] );

			}

			return false;

		}

		return $post_id;

	}

	/**
	 * save row metadata
	 * @access private
	 * @param $post_id post id to attach thumbnails to
	 * @param $meta_data meta data to be saved
	 * @return bool true on success
	 */
	private function _meta( $post_id, $meta_data ) {

		foreach ( $meta_data as $k => $v ) {

			$sanitize = $v[ 'sanitize' ];

			if ( update_post_meta( $post_id, $k, $sanitize( $v[ 'value' ] ), get_post_meta( $post_id, $k, true ) ) ) {

				WP_CLI::success( 'post id ' . $post_id . ' meta key ' . $k . ' added as ' . $v[ 'value' ] );

			} else {

				WP_CLI::warning( 'post id ' . $post_id . ' meta key ' . $k . ' could not be added as ' . $v[ 'value' ] );

			}

		}

		return true;

	}

	/**
	 * save row taxomies
	 * @access private
	 * @param $post_id post id to attach thumbnails to
	 * @param $taxonomies taxonomy data to be saved
	 * @return bool true on success
	 */
	private function _taxonomies( $post_id, $taxonomies ) {

		foreach ( $taxonomies as $k => $v ) {

			if ( $v[ 'value' ] == '' ) {

				WP_CLI::warning( $k . ' value was blank for post id ' . $post_id );

				continue;

			}

			$sanitize = $v[ 'sanitize' ];
			wp_set_object_terms( $post_id, $sanitize( $v[ 'value' ] ), $k );

			WP_CLI::success( 'post id ' . $post_id . ' added to ' . $k . ' as ' . $v[ 'value' ] );

		}

		return true;

	}

	/**
	 * save row thumbnails
	 * @access private
	 * @param $post_id post id to attach thumbnails to
	 * @param $thumbnails thumbnails to be imported
	 * @return bool true on success
	 */
	private function _thumbnails( $post_id, $thumbnails, $title = null, $author = null ) {

		foreach ( $thumbnails as $k => $v ) {

			if ( $v[ 'value' ] == '' ) {

				continue;

			}

			$image = $this->thumbnail_path . $v[ 'value' ];

			// This is very similar to media_sideload_image(),
			// but adds some additional checks and data,
			// as well as multi featured image sizes

			// Download featured image from url to temp location
			$tmp_image = download_url( $image );

			// Set variables for storage
			// fix file filename for query strings
			preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $image, $matches );
			$file_array[ 'name' ] = basename( $matches[ 0 ] );
			$file_array[ 'tmp_name' ] = $tmp_image;

			// If error storing temporarily, unlink
			if ( is_wp_error( $tmp_image ) ) {

				@unlink( $file_array[ 'tmp_name' ] );
				$file_array[ 'tmp_name' ] = '';

				WP_CLI::warning( $image . ' could not be downloaded from ' . $k . ' for post id ' . $post_id );

				foreach ( $tmp_image->errors as $error ) {

					WP_CLI::warning( $error[ 0 ] );

				}

				continue;

			} else {

				WP_CLI::success( 'post id ' . $post_id . ' thumbnail ' . $k . ' downloaded from ' . $image );

			}

			// Image Metadata
			if ( $title ) {

				$image_meta[ 'post_title' ] = wp_kses_post( $title );

			}

			$image_meta[ 'post_parent' ] = $post_id;

			if ( isset( $author ) && is_int( $author ) ) {

				$image_meta[ 'post_author' ] = $author;

			} elseif ( isset( $author ) && is_string( $author ) && ( $author_id = username_exists( $author ) ) ) {

				$image_meta[ 'post_author' ] = $author_id;

			}

			if ( is_wp_error( $attachment_id = media_handle_sideload( $file_array, $post_id, wp_kses_post( $image_meta[ 'post_title' ] ), $image_meta ) ) ) {

				WP_CLI::warning( $image . ' could not be attached to post id ' . $post_id );

				foreach ( $attachment_id->errors as $error ) {

					WP_CLI::warning( 'WP Error: ' . $error[ 0 ] );

				}

				continue;

			}

			if ( ( $k == 'featured_image' ) && set_post_thumbnail( $post_id, $attachment_id ) ) {

				WP_CLI::success( 'post id ' . $post_id . ' thumbnail ' . $k . ' attached from ' . $v[ 'value' ] );

			} elseif ( add_post_meta( $post_id, $this->post_type . '_' . $k . '_thumbnail_id', $attachment_id ) ) {

				WP_CLI::success( 'post id ' . $post_id . ' meta key ' . $k . ' attached from ' . $v[ 'value' ] );

			} else {

				WP_CLI::warning( 'post id ' . $post_id . ' thumbnail key ' . $k . ' could not be attached as ' . $v[ 'value' ] );

			}

		}

		return true;

	}

}