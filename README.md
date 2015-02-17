# WDS WP Large Options

Allow storage of options larger than 1M in a cache-safe manner. Uses a custom post type.

> You may wish to store a larger option value than is recommended on WordPress.com. If your option data will exceed 400K, or is of an unpredictable size (such as an HTML fragment etc.) you should use the wp_large_options plugin to store the option in a cache-safe manner. Failure to do this could result in the option not being cached, and instead fetched repeatedly from the DB, which could cause performance problems.

-- [WordPress VIP](http://vip.wordpress.com/plugins/wp-large-options)

This library is a fork of the [original version](https://github.com/voceconnect/wp-large-options) by [Voce Connect](http://voceplatforms.com/) and this version falls back to the options API if the wp-large-option version cannot be found.

The API is very similar to the [WordPress Options API](http://codex.wordpress.org/Options_API) and is intended to be an optional replacement.

* Get: `wlo_get_option` (use like `[get_option](http://codex.wordpress.org/Function_Reference/get_option)`)
* Add: `wlo_add_option` (use like `[add_option](http://codex.wordpress.org/Function_Reference/add_option)`)
* Update: `wlo_update_option` (use like `[update_option](http://codex.wordpress.org/Function_Reference/update_option)`)
* Delete: `wlo_delete_option` (use like `[delete_option](http://codex.wordpress.org/Function_Reference/delete_option)`)

**Note:** Plugin requires WordPress 4.1 or later.