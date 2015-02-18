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

/**
 * A WDS_WP_Large_Option factory/storage
 */
abstract class WDS_WP_Large_Options {

	const POST_TYPE = 'wlo_option';
	const META_KEY  = 'wp-large-option-value';

	/**
	 * Storage for all WDS_WP_Large_Option instances
	 * @var   array
	 * @since 2.0.0
	 */
	protected static $option_instances = array();

	/**
	 * Add WDS_WP_Large_Option instance to the storage
	 * @since 2.0.0
	 * @param WDS_WP_Large_Option object $option_instance
	 */
	protected static function add_instance( WDS_WP_Large_Option $option_instance ) {
		self::$option_instances[ $option_instance->name ] = $option_instance;
	}

	/**
	 * Remove WDS_WP_Large_Option instance from storage
	 * @since 2.0.0
	 * @param string $option_name
	 */
	public static function remove_instace( $option_name ) {
		if ( array_key_exists( $option_name, self::$option_instances ) ) {
			unset( self::$option_instances[ $option_name ] );
		}
	}

	/**
	 * Retrieve a WDS_WP_Large_Option instance from storage
	 * @since  2.0.0
	 * @param  string $option_name
	 * @return WDS_WP_Large_Option object
	 */
	public static function get_instance( $option_name ) {
		$option_name = self::sanitize_option_name( $option_name );
		if ( ! $option_name ) {
			return false;
		}

		if ( empty( self::$option_instances ) || empty( self::$option_instances[ $option_name ] ) ) {
			new WDS_WP_Large_Option( $option_name, true );
		}

		return self::$option_instances[ $option_name ];
	}

	/**
	 * Sanitize option name.
	 * @since  2.0.0
	 * @param  string $option Option name
	 * @return mixed          Sanitized option name
	 */
	protected static function sanitize_option_name( $option_name ) {
		if ( ! $option_name ) {
			return false;
		}

		$clean = sanitize_title( trim( $option_name ) );
		$clean = empty( $clean ) ? false : $clean;

		return $clean;
	}

	abstract protected function add_option( $value, $check_value_exists = true );
	abstract protected function update_option( $new_value );
	abstract protected function delete_option();
	abstract protected function get_option( $default = false );
}

/**
 * An object for setting/getting post-type options
 */
class WDS_WP_Large_Option extends WDS_WP_Large_Options {

	protected $name    = '';
	protected $value   = null;
	protected $post    = null;
	protected $post_id = 0;

	/**
	 * Magic getter for our object.
	 * @param string $property
	 * @throws Exception Throws an exception if the field is invalid.
	 * @return mixed
	 */
	public function __get( $property ) {
		switch ( $property ) {
			case 'name':
			case 'post':
			case 'post_id':
				return $this->{$property};
			default:
				throw new Exception( 'Invalid '. __CLASS__ .' property: ' . $property );
		}
	}

	/**
	 * Initiate a wds option instance
	 * @since 2.0.0
	 * @param string  $option_name Option name to retrieve
	 * @param boolean $sanitized   Whether option name has already been sanitized (default, false)
	 */
	public function __construct( $option_name, $sanitized = false ) {
		$this->name = $sanitized ? $option_name : parent::sanitize_option_name( $option_name );
		parent::add_instance( $this );
	}

	/**
	 * Add a new option.
	 * @param  mixed $value
	 * @param  bool  $check_value_exists
	 * @return bool
	 */
	public function add_option( $value, $check_value_exists = true ) {
		if ( empty( $this->name ) ) {
			return false;
		}

		if ( $check_value_exists && false !== $this->get_option() ) {
			return false;
		}

		// Set up our post args
		$post_args = array(
			'post_type'    => parent::POST_TYPE,
			'post_name'    => $this->name,
			'post_title'   => $this->name,
			'post_status'  => 'publish',
		);

		// Use post_content by default
		if ( $use_post_content = $this->use_post_content() ) {
			$post_args['post_content'] = maybe_serialize( $value );
		}

		// do insert
		$post_id = wp_insert_post( $post_args, true );
		$success = ! is_wp_error( $post_id );

		// If not using post_content, update the post-meta
		if ( ! $use_post_content && $success ) {
			$success = update_post_meta( $post_id, parent::META_KEY, $value );
		}

		if ( ! $success ) {
			return false;
		}

		$this->post_id = $post_id;
		$this->value   = $value;
		wp_cache_set( 'wlo_option_id_' . $this->name, $post_id );

		do_action( "add_wlo_option_{$this->name}", $this->name, $value );
		do_action( 'added_wlo_option', $this->name, $value );

		return true;
	}

	/**
	 * Update or add an option
	 * @param  mixed $new_value
	 * @return bool
	 */
	public function update_option( $new_value ) {
		if ( empty( $this->name ) ) {
			return false;
		}

		$old_value = $this->get_option();

		/**
		 * Filter a wlo option before its value is (maybe) serialized and updated.
		 *
		 * @since 3.9.0
		 *
		 * @param mixed  $new_value The new, unserialized option value.
		 * @param string $option    Name of the option.
		 * @param mixed  $old_value The old option value.
		 */
		$new_value = apply_filters( 'pre_update_wlo_option', $new_value, $this->name, $old_value );

		// If the new and old values are the same, no need to update.
		if ( $new_value === $old_value ) {
			return false;
		}

		// If no old value, need to add the option
		if ( false === $old_value ) {
			return $this->add_option( $new_value, false );
		}

		// If no new value, need to delete the option
		if ( ! $new_value ) {
			return $this->delete_option();
		}

		// Something broke?
		if ( ! $this->update_post_value( $new_value ) ) {
			return false;
		}

		do_action( "update_wlo_option_{$this->name}", $old_value, $new_value );
		do_action( 'updated_wlo_option', $this->name, $old_value, $new_value );

		$this->value = $new_value;
		return true;
	}

	/**
	 * Deletes the option
	 * @return bool
	 */
	public function delete_option() {
		if ( empty( $this->name ) ) {
			return false;
		}

		$post_id = $this->get_option_post_id( $this->name );

		if ( $this->use_post_content() ) {
			$deleted = $post_id ? wp_delete_post( $post_id, true ) : false;
		} else {
			$deleted = delete_post_meta( $post_id, parent::META_KEY );
		}

		if ( $deleted ) {
			wp_cache_delete( 'wlo_option_id_' . $this->name );
			$this->value   = null;
			$this->post_id = 0;
		}

		return !! $deleted;
	}

	/**
	 * Returns the option. Falls back to get_option (useful if migrating)
	 * @param  mixed  $default
	 * @return mixed
	 */
	public function get_option( $default = false ) {
		if ( empty( $this->name ) ) {
			return false;
		}

		/**
		 * Filter the value of an existing wlo option before it is retrieved.
		 *
		 * The dynamic portion of the hook name, `$this->name`, refers to the option name.
		 *
		 * Passing a non-false value to the filter will short-circuit retrieving
		 * the option value, returning the passed value instead.
		 *
		 * @since 2.0.0
		 *
		 * @param bool|mixed $pre_option Value to return instead of the option value.
		 *                               Default false to skip it.
		 */
		if ( false !== ( $pre = apply_filters( 'pre_wlo_option_' . $this->name, false ) ) ) {
			return $pre;
		}

		/**
		 * This is in WP's get_option as well
		 * https://core.trac.wordpress.org/ticket/13030
		 */
		if ( defined( 'WP_SETUP_CONFIG' ) ) {
			return false;
		}

		if ( $this->value ) {
			return $this->value;
		}

		if ( $this->get_option_post_id( $this->name ) ) {
			$value = $this->get_post_value( $this->name );
		} else {
			/**
			 * If no post id, should we see if a normal option exists?
			 * to enable get_option fallback checking:
			 * 	add_filter( 'wds_wp_large_options_get_option_fallback', '__return_true' );
			 */
			$value = apply_filters( 'wds_wp_large_options_get_option_fallback', false )
				? get_option( $this->name, $default )
				: $default;
		}

		$this->value = $value ? $value : $default;
		return $this->value;
	}

	/**
	 * Retrieve value from post.
	 * Pulls from post_content by default, but can be filtered to use post-meta
	 * @since  2.0.0
	 * @return mixed Value
	 */
	protected function get_post_value() {
		$post_id = $this->get_option_post_id();

		if ( $this->use_post_content() ) {
			$post = get_post( $post_id );
			$value = isset( $post->post_content ) ? maybe_unserialize( $post->post_content ) : false;
		} else {
			$value = get_post_meta( $post_id, parent::META_KEY, 1 );
		}

		return $value;
	}

	/**
	 * Update value to post.
	 * Saves to post_content by default, but can be filtered to use post-meta
	 * @since  2.0.0
	 * @param  mixed $new_value Value to save
	 * @return mixed            If update was successful
	 */
	protected function update_post_value( $new_value ) {
		$post_id = $this->get_option_post_id( $this->name );

		if ( $this->use_post_content() ) {

			remove_all_filters( 'wp_insert_post_data' );
			add_filter( 'wp_insert_post_empty_content', '__return_false' );
			$success = wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_value ? maybe_serialize( $new_value ) : '' ) );
			remove_filter( 'wp_insert_post_empty_content', '__return_false' );

		} else {
			$success = update_post_meta( $post_id, parent::META_KEY, $new_value );
		}

		return !! $success;
	}

	/**
	 * Returns the post id of this specific option
	 * @return bool
	 */
	protected function get_option_post_id() {
		if ( empty( $this->name ) ) {
			return false;
		}

		if ( $this->post_id ) {
			return $this->post_id;
		}

		$this->post_id = absint( wp_cache_get( 'wlo_option_id_' . $this->name ) );
		if ( ! $this->post_id ) {

			$post = get_page_by_title( $this->name, OBJECT, parent::POST_TYPE );

			if ( ! empty( $post ) ) {
				$this->post_id = $post->ID;
				wp_cache_set( 'wlo_option_id_' . $this->name, $this->post_id );
			}
		}

		return $this->post_id;
	}

	/**
	 * Whether plugin should use the post_content as the default storage location
	 * @since  2.0.0
	 * @return bool
	 */
	protected function use_post_content() {
		/**
		 * Use data in the post_content field by default. Cannot store objects
		 * to use post-metat instead:
		 * 	add_action( 'wds_wp_large_options_use_post_content', '__return_false' );
		 */
		return apply_filters( 'wds_wp_large_options_use_post_content', true, $this->name, $this->post_id );
	}

}

/**
 * Register our option replacement cpt
 * @since  2.0.0
 */
function wdswplo_register_post_type() {
	register_post_type( WDS_WP_Large_Options::POST_TYPE, array(
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
add_action( 'init', 'wdswplo_register_post_type', 1 );

/**
 * Get a WDS_WP_Large_Options object by name
 * @since  2.0.0
 * @param  string  $option_name
 * @return WDS_WP_Large_Option object
 */
function wdswplo( $option_name ) {
	return WDS_WP_Large_Options::get_instance( $option_name );
}

/**
 * Add a new option
 * @param  string $option_name
 * @param  mixed  $value
 * @return bool
 */
function wds_add_option( $option_name, $value ) {
	$instance = wdswplo( $option_name );
	return $instance ? $instance->add_option( $value ) : false;
}

/**
 * Update or add an option
 * @param  string $option_name
 * @param  mixed  $new_value
 * @return bool
 */
function wds_update_option( $option_name, $new_value ) {
	$instance = wdswplo( $option_name );
	return $instance ? $instance->update_option( $new_value ) : false;
}

/**
 * Deletes the option
 * @param  string $option_name
 * @return bool
 */
function wds_delete_option( $option_name ) {
	$instance = wdswplo( $option_name );
	return $instance ? $instance->delete_option() : false;
}

/**
 * Returns the option
 * @param  string $option_name
 * @param  mixed  $default
 * @return bool
 */
function wds_get_option( $option_name, $default = false ) {
	$instance = wdswplo( $option_name );
	return $instance ? $instance->get_option() : false;
}
