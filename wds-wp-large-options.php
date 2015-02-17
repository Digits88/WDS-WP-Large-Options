<?php

/*
  Plugin Name: WDS WP Large Options
  Plugin URI: http://webdevstudios.com
  Description: Allows larger options to be stored in custom post type to prevent
  all_options from overflowing 1MB value limit.
  Author: webdevstudios, prettyboymp, voceplatforms
  Version: 2.0.0
  Author URI: http://webdevstudios.com
  Requires at least: 4.1.0

  GNU General Public License, Free Software Foundation <http://creativecommons.org/licenses/GPL/2.0/>

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

class WDS_WP_Large_Options {

	const POST_TYPE = 'wlo_option';
	const META_KEY  = 'wp-large-option-value';

	protected $sanitized = array();
	protected $post_ids  = array();
	protected static $single_instance = null;

	/**
	 * Creates or returns an instance of this class.
	 * @since  0.1.0
	 * @return WDS_WP_Large_Options A single instance of this class.
	 */
	public static function get_instance() {
		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;
	}

	protected function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ), 1 );
	}

	/**
	 * Add a new option.
	 * @param  string $option
	 * @param  mixed  $value
	 * @return bool
	 */
	public function add_option( $option, $value ) {
		$option = $this->sanitize_option_name( $option );

		if ( empty( $option ) ) {
			return false;
		}

		if ( false !== $this->get_option( $option ) ) {
			return false;
		}

		// Set up our post args
		$post_args = array(
			'post_type'    => self::POST_TYPE,
			'post_name'    => $option,
			'post_title'   => $option,
			'post_status'  => 'publish',
		);

		// Use post_content by default
		if ( $use_post_content = $this->use_post_content( $option ) ) {
			$post_args['post_content'] = wp_json_encode( $value );
		}

		// do insert
		$post_id = wp_insert_post( $post_args, true );
		$success = ! is_wp_error( $post_id );

		// If not using post_content, update the post-meta
		if ( ! $use_post_content && $success ) {
			$success = update_post_meta( $post_id, self::META_KEY, $value );
		}

		if ( ! $success ) {
			return false;
		}

		$this->post_ids[ $option ] = $post_id;
		wp_cache_set( 'wlo_option_id_' . $option, $post_id );
		do_action( "add_wlo_option_{$option}", $option, $value );
		do_action( 'added_wlo_option', $option, $value );

		return true;
	}

	/**
	 * Update or add an option
	 * @param  string $option
	 * @param  mixed  $newvalue
	 * @return bool
	 */
	public function update_option( $option, $newvalue ) {
		$option = $this->sanitize_option_name( $option );
		if ( empty( $option ) ) {
			return false;
		}

		$oldvalue = $this->get_option( $option );

		/**
		 * Filter a wlo option before its value is (maybe) serialized and updated.
		 *
		 * @since 3.9.0
		 *
		 * @param mixed  $newvalue  The new, unserialized option value.
		 * @param string $option    Name of the option.
		 * @param mixed  $old_value The old option value.
		 */
		$value = apply_filters( 'pre_update_wlo_option', $newvalue, $option, $old_value );

		// If the new and old values are the same, no need to update.
		if ( $newvalue === $oldvalue ) {
			return false;
		}

		if ( false === $oldvalue ) {
			return $this->add_option( $option, $newvalue );
		}

		$post_id = $this->get_option_post_id( $option );

		if ( ! $this->update_post_value( $post_id, $newvalue ) ) {
			return false;
		}

		do_action( "update_wlo_option_{$option}", $oldvalue, $newvalue );
		do_action( 'updated_wlo_option', $option, $oldvalue, $newvalue );

		return true;
	}

	/**
	 * Deletes the option
	 * @param  string $option
	 * @return bool
	 */
	public function delete_option( $option ) {
		return ( $post_id = $this->get_option_post_id( $option ) )
			? wp_delete_post( $post_id, true )
			: false;
	}

	/**
	 * Returns the option. Falls back to get_option (useful if migrating)
	 * @param  string $option
	 * @param  mixed  $default
	 * @return mixed
	 */
	public function get_option( $option, $default = false ) {
		$option = $this->sanitize_option_name( $option );
		if ( empty( $option ) ) {
			return false;
		}

		/**
		 * Filter the value of an existing wlo option before it is retrieved.
		 *
		 * The dynamic portion of the hook name, `$option`, refers to the option name.
		 *
		 * Passing a non-false value to the filter will short-circuit retrieving
		 * the option value, returning the passed value instead.
		 *
		 * @since 2.0.0
		 *
		 * @param bool|mixed $pre_option Value to return instead of the option value.
		 *                               Default false to skip it.
		 */
		if ( false !== ( $pre = apply_filters( 'pre_wlo_option_' . $option, false ) ) ) {
			return $pre;
		}

		/**
		 * This is in WP's get_option as well
		 * https://core.trac.wordpress.org/ticket/13030
		 */
		if ( defined( 'WP_SETUP_CONFIG' ) ) {
			return false;
		}

		$post_id = $this->get_option_post_id( $option );

		if ( ! $post_id ) {
			/**
			 * If no post id, let's see if a normal option exists
			 * to disable get_option checking:
			 * 	add_action( 'wds_wp_large_options_get_option_fallback', '__return_false' );
			 */
			return apply_filters( 'wds_wp_large_options_get_option_fallback', true )
				? get_option( $option, $default )
				: $default;
		}

		$value = $this->get_post_value( $post_id, $option );

		return $value ? $value : $default;
	}

	/**
	 * Retrieve value from post.
	 * Pulls from post_content by default, but can be filtered to use post-meta
	 * @since  2.0.0
	 * @param  int   $post_id Post id of post to retrieve value from
	 * @return mixed          Value
	 */
	protected function get_post_value( $post_id, $option ) {

		if ( $this->use_post_content( $option, $post_id ) ) {
			$value = get_post_field( 'post_content', $post_id, 'raw' );
			$value = $value ? json_decode( $value ) : false;
		} else {
			$value = get_post_meta( $post_id, self::META_KEY, 1 );
		}

		return $value;
	}

	/**
	 * Update value to post.
	 * Saves to post_content by default, but can be filtered to use post-meta
	 * @since  2.0.0
	 * @param  int   $post_id Post id of post to retrieve value from
	 * @param  mixed $value   Value to save
	 * @return mixed          If update was successful
	 */
	protected function update_post_value( $post_id, $option ) {

		if ( $this->use_post_content( $option, $post_id ) ) {

			remove_all_filters( 'wp_insert_post_data' );
			add_filter( 'wp_insert_post_empty_content', '__return_false' );
			$success = wp_update_post( array( 'ID' => $post_id, 'post_content' => $option ? wp_json_encode( $option ) : '' ) );
			remove_filter( 'wp_insert_post_empty_content', '__return_false' );

		} else {
			$success = update_post_meta( $post_id, self::META_KEY, $newvalue );
		}

		return !! $success;
	}

	/**
	 * Returns the post that is storing the specific option
	 * @param  string $option
	 * @return bool|object
	 */
	protected function get_option_post_id( $option ) {
		$option = $this->sanitize_option_name( $option );
		if ( empty( $option ) ) {
			return false;
		}

		if ( array_key_exists( $option, $this->post_ids ) ) {
			return $this->post_ids[ $option ];
		}

		if ( false === ( $post_id = wp_cache_get( 'wlo_option_id_' . $option ) ) ) {
			$posts = get_posts( array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'name'           => $option,
				'fields'         => 'ids',
			) );

			if ( ! empty( $posts ) && 1 === count( $posts ) ) {
				$post_id = $posts[0];
				wp_cache_set( 'wlo_option_id_' . $option, $post_id );
			}
		}

		// We'll store the post_id to the object
		$this->post_ids[ $option ] = $post_id;
		return $post_id;
	}

	/**
	 * Sanitize option name. Because sanitize_title can be expensive,
	 * We'll store the results to the object
	 * @since  2.0.0
	 * @param  string $option Option name
	 * @return mixed          Sanitized option name
	 */
	protected function sanitize_option_name( $option ) {
		if ( ! $option ) {
			return false;
		}

		if ( array_key_exists( $option, $this->sanitized ) ) {
			return $this->sanitized[ $option ];
		}

		$clean = sanitize_title( trim( $option ) );
		$clean = empty( $clean ) ? false : $clean;

		$this->sanitized[ $option ] = $clean;
		return $clean;
	}

	protected function use_post_content( $option, $post_id = 0 ) {
		/**
		 * Use json_encoded data in the post_content field by default. Cannot store objects
		 * to use post-metat instead:
		 * 	add_action( 'wds_wp_large_options_use_post_content', '__return_false' );
		 */
		return apply_filters( 'wds_wp_large_options_use_post_content', true, $option, $post_id );
	}

	public function register_post_type() {
		register_post_type( self::POST_TYPE, array(
			'publicly_queryable'  => false,
			'capability_type'     => 'wlo_debug',
			'public'              => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
			'has_archive'         => false,
			'query_var'           => false,
			'taxonomies'          => array(),
			'show_ui'             => false,
			'can_export'          => true,
			'show_in_nav_menus'   => false,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
			'delete_with_user'    => false,
			'labels'              => array(
				'name'          => 'Large Options',
				'singular_name' => 'Large Option',
			),
		) );
	}

}

function wdswplo() {
	return WDS_WP_Large_Options::get_instance();
}

function wds_add_option( $option, $value ) {
	return wdswplo()->add_option( $option, $value );
}

function wds_update_option( $option, $newvalue ) {
	return wdswplo()->update_option( $option, $newvalue );
}

function wds_delete_option( $option ) {
	return wdswplo()->delete_option( $option );
}

function wds_get_option( $option, $default = false ) {
	return wdswplo()->get_option( $option );
}

wdswplo(); // Kick it off
