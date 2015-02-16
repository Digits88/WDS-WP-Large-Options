# WDS WP Large Options

A replacement for the options API. Original context can be found at the [WordPress VIP site](http://vip.wordpress.com/plugins/wp-large-options)
. This is a fork of the [original version](https://github.com/voceconnect/wp-large-options) by [Voce Connect](http://voceplatforms.com/). This version falls back to the options API if the wp-large-option version cannot be found.

The API is very similar to the [WordPress Options API](http://codex.wordpress.org/Options_API) and is intended to be an optional replacement.

* Get: `wlo_get_option` (use like `[get_option](http://codex.wordpress.org/Function_Reference/get_option)`)
* Add: `wlo_add_option` (use like `[add_option](http://codex.wordpress.org/Function_Reference/add_option)`)
* Update: `wlo_update_option` (use like `[update_option](http://codex.wordpress.org/Function_Reference/update_option)`)
* Delete: `wlo_delete_option` (use like `[delete_option](http://codex.wordpress.org/Function_Reference/delete_option)`)
