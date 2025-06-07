<?php
/**
 * {eac}KeyValueCapture - Capture options and transients to {eac}KeyValue.
 *
 * @category    WordPress Plugin
 * @package     {eac}CronSettings
 * @author      Kevin Burkholder <KBurkholder@EarthAsylum.com>
 * @copyright   Copyright (c) 2025 EarthAsylum Consulting <www.earthasylum.com>
 *
 * @wordpress-plugin
 * Plugin Name:         {eac}KeyValueCapture
 * Description:         {eac}KeyValueCapture - Capture options and transients to {eac}KeyValue
 * Version:             1.0.0
 * Last Updated:        07-Jun-2025
 * Requires at least:   5.8
 * Tested up to:        6.8
 * Requires PHP:        8.0
 * Author:              EarthAsylum Consulting
 * Author URI:          http://www.earthasylum.com
 * License:             GPLv3 or later
 * License URI:         https://www.gnu.org/licenses/gpl.html
 * Tags:                key-value, wp object cache, options, transients
 * Github URI:          https://github.com/EarthAsylum/eacKeyValue
 */

/* *****
 * This is a self-contained piece of code - drop in to plugins or mu-plugins folder to invoke.
 *
 * This plugin uses _{eac}KeyValue_ to capture individual WordPress option or transient API calls
 * to direct them to the Key-Value API.
 *
 * -- This is experimental and is not without issue or risk. --
 *
 * *****
 *
	#### Options

	To capture an option and route it to eacKeyValue:
		`eacKeyValueCapture::option_capture('option_name');`
	Add 'true' to have the original value saved to cache and deleted from WP options:
		`eacKeyValueCapture::option_capture('option_name',true);`
	To release a captured option (restores to WP options):
		`eacKeyValueCapture::option_release('option_name');`

	#### Transients

	To capture a transient and route it to eacKeyValue:
		`eacKeyValueCapture::transient_capture('transient_name');`
	Add 'true' to have the original value saved to cache and deleted from WP options:
		`eacKeyValueCapture::transient_capture('transient_name',true);`
	To release a captured transient:
		`eacKeyValueCapture::transient_release('transient_name');`

	#### Global alias functions:

		 capture_option() -> \EarthAsylumConsulting\eacKeyValueCapture::option_capture()
		 release_option() -> \EarthAsylumConsulting\eacKeyValueCapture::option_release()
		 capture_transient() -> \EarthAsylumConsulting\eacKeyValueCapture::transient_capture()
		 release_transient() -> \EarthAsylumConsulting\eacKeyValueCapture::transient_release()

	#### Notes:

	+   `add_option()` and `delete_option()` can not be circumvented but are captured to eacKeyValue.
	+   If an option doesn't exist in WP options, it won't be deleted from eacKeyValue with `delete_option()`.
	+   `set_transient()` can not be circumvented (but the called update_option is).
	+   WP's `$alloptions`/`$nooptions` caching with a persistent object caching may interfere with pushing an option or transient back to the options table when releasing.
	+   When circumventing functions using "pre" filters, the usual default hooks are not triggered. This could be detrimental to other processes and may be addressed in the future.
 *
 * ***** */

namespace EarthAsylumConsulting
{
    if (! class_exists('\EarthAsylumConsulting\eacKeyValueCapture'))
    {
        class eacKeyValueCapture
        {
            /**
             * Capture (redirect/circumvent) a WP option.
             * We can't circumvent add/delete
             *  good news:  option is still updated/added/deleted from KeyValue.
             *  bad news:   option is still added to option table and caches and may become outdated.
             *              an updated option (never 'add'ed) can't be deleted.
             *
             * @param string    $option option name
             * @param bool      $auto automatically transfer option
             * @return string|null
             */
            public static function option_capture($option,$auto=false)
            {
                $current = ($auto) ? \get_option($option) : false;
                if ($current) {
                    eacKeyValue::put($option,$value);
                    \delete_option($option);
                }

                // before getting an option
                add_filter( "pre_option_{$option}",         [self::class,'_pre_option'],10,3 );
                // before updating an option
                add_filter( "pre_update_option_{$option}",  [self::class,'_pre_update_option'],10,3 );
                // after adding an option (there is no "pre_")
                add_action( "add_option_{$option}",         [self::class,'_add_option'],10,2 );
                // after deleting an option (there is no "pre_")
                add_action( "delete_option_{$option}",      [self::class,'_delete_option'],10,1 );
            }

            /**
             * option_capture callbacks
             */
            public static function _pre_option($return, $option, $default_value)
            {
                $result = eacKeyValue::get($option);
                return (is_null($result)) ? $return : $result;  // circumvent 'get_option' with $result
            }
            public static function _pre_update_option($value, $old_value, $option)
            {
                if ($old_value === false) return $value;        // force 'add'
                $result = eacKeyValue::put($option,$value);
                return (empty($result)) ? $value : $old_value;  // circumvent 'update_option' wiith $old_value
            }
            public static function _add_option($option, $value)
            {
                $result = eacKeyValue::put($option,$value);     // no circumvention
            }
            public static function _delete_option($option)
            {
                $result = eacKeyValue::put($option,null);       // no circumvention
            }

            /**
             * Release a captured WP option.
             *
             * @param string    $option option name
             * @return string|null
             */
            public static function option_release($option)
            {
                $current = eacKeyValue::get($option);

                // before getting an option
                remove_filter( "pre_option_{$option}",          [self::class,'_pre_option'],10 );
                // before updating an option
                remove_filter( "pre_update_option_{$option}",   [self::class,'_pre_update_option'],10 );
                // after adding an option (there is no "pre_")
                remove_action( "add_option_{$option}",          [self::class,'_add_option'],10 );
                // after deleting an option (there is no "pre_")
                remove_action( "delete_option_{$option}",       [self::class,'_delete_option'],10 );

                eacKeyValue::put($option,null);
                if ($current) {
                    \add_option($option,$current);
                }
            }

            /**
             * Capture (redirect/circumvent) a WP transient.
             *
             * @param string    $transient transient name
             * @param bool      $auto automatically transfer option
             * @return string|null
             */
            public static function transient_capture($transient,$auto=false)
            {
                // before getting a transient
                add_filter( "pre_transient_{$transient}",       [self::class,'_pre_transient'],10,2 );
                // after updating a transient (does not circumvent)
                add_action( "set_transient_{$transient}",       [self::class,'_set_transient'],10,3 );

                self::option_capture("_transient_{$transient}",$auto);
            //  self::option_capture("_transient_timeout_{$transient}",$auto);
            }

            /**
             * transient_capture callbacks
             */
            public static function _pre_transient($return, $transient)
            {
                $result = eacKeyValue::get("_transient_{$transient}");
                return (is_null($result)) ? $return : $result;  // circumvent 'get_transient' with $result
            }
            public static function _set_transient($value, $expiration, $transient)
            {
                $result = eacKeyValue::put("_transient_{$transient}",$value,$expiration);
            }

            /**
             * Release a captured WP transient.
             *
             * @param string    $transient transient name
             * @return string|null
             */
            public static function transient_release($transient)
            {
                self::option_release("_transient_{$transient}");
            //  self::option_release("_transient_timeout_{$transient}");

                // before getting a transient
                remove_filter( "pre_transient_{$transient}",    [self::class,'_pre_transient'],10 );
                // after updating a transient (does not circumvent)
                remove_action( "set_transient_{$transient}",    [self::class,'_set_transient'],10 );
            }
        } // class
    } // class_exists
} // namespace EarthAsylumConsulting


namespace  // global scope
{
    if (! function_exists('capture_option'))
    {
        function capture_option($key,$auto=false) {
            return \EarthAsylumConsulting\eacKeyValueCapture::option_capture($key,$auto);
        }
    }
    if (! function_exists('capture_transient'))
    {
        function capture_transient($key,$auto=false) {
            return \EarthAsylumConsulting\eacKeyValueCapture::transient_capture($key,$auto);
        }
    }
    if (! function_exists('release_option'))
    {
        function release_option($key) {
            return \EarthAsylumConsulting\eacKeyValueCapture::option_release($key);
        }
    }
    if (! function_exists('release_transient'))
    {
        function release_transient($key) {
            return \EarthAsylumConsulting\eacKeyValueCapture::transient_release($key);
        }
    }

/*
    add_action('init', function()
    {
        // - testing / example - \\
        capture_option('keyvalue_test',true);
        capture_transient('keyvalue_test',true);

        update_option('keyvalue_test',wp_date('c'));
        set_transient('keyvalue_test',wp_date('c'),HOUR_IN_SECONDS);

        echo "<div class='notice'><pre>get_option ".var_export(get_option('keyvalue_test'),true)."</pre></div>";
        echo "<div class='notice'><pre>get_transient ".var_export(get_transient('keyvalue_test'),true)."</pre></div>";

        release_option('keyvalue_test');
        release_transient('keyvalue_test');

        echo "<div class='notice'><pre>get_option ".var_export(get_option('keyvalue_test'),true)."</pre></div>";
        echo "<div class='notice'><pre>get_transient ".var_export(get_transient('keyvalue_test'),true)."</pre></div>";
    });
*/
}
