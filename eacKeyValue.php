<?php
/**
 * {eac}KeyValue - key-value pair storage mechanism for WordPress
 *
 * @category    WordPress Plugin
 * @package     {eac}KeyValue
 * @author      Kevin Burkholder <KBurkholder@EarthAsylum.com>
 * @copyright   Copyright (c) 2025 EarthAsylum Consulting <www.earthasylum.com>
 *
 * @wordpress-plugin
 * Plugin Name:         {eac}KeyValue
 * Description:         {eac}KeyValue - key-value pair storage mechanism for WordPress
 * Version:             1.1.0
 * Last Updated:        11-Jun-2025
 * Requires at least:   5.8
 * Tested up to:        6.8
 * Requires PHP:        8.0
 * Author:              EarthAsylum Consulting
 * Author URI:          http://www.earthasylum.com
 * License:             GPLv3 or later
 * License URI:         https://www.gnu.org/licenses/gpl.html
 * Tags:                key-value, object cache, options, transients
 * Github URI:          https://github.com/EarthAsylum/eacKeyValue
 */

/* *****
 *
 * This is a self-contained piece of code - drop in to mu-plugins folder to invoke.
 *
 * This plugin is included with {eac}Doojigger.
 *
 * Developers may include this in their project.
 *
 * see the `readme.md` file for details.
 * https://github.com/EarthAsylum/eacKeyValue/blob/main/readme.md
 *
 * ***** */

namespace EarthAsylumConsulting
{

    if (! class_exists('\EarthAsylumConsulting\eacKeyValue'))
    {
        class eacKeyValue
        {
            /**
             * @var string table suffix and cache group
             */
            public const CACHE_ID           = 'eac_key_value';

            /**
             * @var string when a date is not set
             */
            private const NULL_DATE         = '0000-00-00 00:00:00';


            /**
             * @var string when scheduling purge event, the schedule/interval name
             */
            public static $purge_schedule   = 'daily';

            /**
             * @var string when scheduling purge event, start at this time
             */
            public static $purge_initial    = 'tomorrow 2:15am';

            /**
             * @var int auto-commit record limit
             */
            public static $auto_commit      = 1000;

            /**
             * @var int current site/blog id
             *  0 = standalone site or multisite global
             *  1-n = multisite blog id
             */
            private static $site_id         = 0;

            /**
             * @var string table suffix and cache group
             *  appends '_site' for multisite global
             */
            private static $cache_id        = self::CACHE_ID;

            /**
             * @var array site tables
             */
            private static $site_tables     = [];

            /**
             * @var array keys to be commited to db by site number
             *  time    - to be written to db, with expiration
             *  true    - to be written to db, no expiration
             *  false   - to be deleted from db
             */
            private static $commit_keys     = [ [] ];

            /**
             * @var array keys not found
             */
            private static $missed_keys     = [ [] ];


            /**
             * Initialize
             */
            public static function factory()
            {
                global $wp_object_cache;

                // global and pre-fetch groups (wp-object-cache)
                wp_cache_add_global_groups([
                    self::CACHE_ID.'_site',
                    self::CACHE_ID.'_site_prefetch'
                ]);
                if (wp_cache_supports('prefetch_groups')) {
                    wp_cache_add_prefetch_groups([
                        self::CACHE_ID.'_prefetch',
                        self::CACHE_ID.'_site_prefetch'
                    ]);
                } else
                if (wp_cache_supports('get_group')) {
                    wp_cache_get_group(self::CACHE_ID.'_prefetch');
                    wp_cache_get_group(self::CACHE_ID.'_site_prefetch');
                }

                // max records before triggering auto-commit
                if (defined('EAC_KEYVALUE_AUTO_COMMMIT') && is_int(EAC_KEYVALUE_AUTO_COMMMIT)) {
                    self::$auto_commit      = EAC_KEYVALUE_AUTO_COMMMIT;
                } else if (isset($wp_object_cache->delayed_writes) && is_int($wp_object_cache->delayed_writes)) {
                    self::$auto_commit      = $wp_object_cache->delayed_writes;     // {eac}ObjectCache
                }

                // setting automatic (wp-cron) purging
                if (defined('EAC_KEYVALUE_PURGE_SCHEDULE')) {
                    self::$purge_schedule   = EAC_KEYVALUE_PURGE_SCHEDULE;
                }
                if (defined('EAC_KEYVALUE_PURGE_START')) {
                    self::$purge_initial    = self::expires(EAC_KEYVALUE_PURGE_START);
                }

                // pre-load known tables and missed keys
                if ($parameters = wp_cache_get(__CLASS__,self::CACHE_ID)) {
                    if (isset($parameters['site_tables'])) {
                        self::$site_tables = $parameters['site_tables'];
                    }
                    if (isset($parameters['missed_keys'])) {
                        self::$missed_keys = $parameters['missed_keys'];
                    }
                }

                self::schedule_purge();
            }

            /**
             * Get a key value
             *
             * @param string            $key
             * @param mixed|callable    $default
             * @param string[]          $args - 'transient', 'prefeth', 'sitewide'
             * @return mixed|null       $value
             */
            public static function get(string|int|\Stringable $key, $default=null, ...$args)
            {
                // key, expires, transient, prefetch, sitewide, cache_id
                extract(self::parse_options($key,...$args));

                if (array_key_exists($key,self::$missed_keys[self::$site_id]))
                {
                    // we already know the key doesn't exist
                    $result = null;
                }
                else
                {
                    while (true) {
                        // check the object cache - maybe prefetch group
                        $result = wp_cache_get( $key, self::$cache_id.'_prefetch', false, $found );
                        if ($found) break;
                        // check the object cache
                        $result = wp_cache_get( $key, self::$cache_id, false, $found );
                        if ($found) break;
                        // check the database
	                    if (!$transient || !wp_using_ext_object_cache()) {
    	                    $result  = self::read($key,true);
    	                }
                        break;
                    }
                }

                if (is_null($result))
                {
                    // mark as missing
                    self::mark_commit_key($key,null);
                    $result = (is_callable($default))
                        ? call_user_func($default,$key)
                        : $default;
                }

                return $result;
            }

            /**
             * Read a key value from the db
             *
             * @param string        $key
             * @param bool          $toCache save to object cache after read
             * @param string[]      $args - 'transient', 'prefeth', 'sitewide'
             * @return mixed|null   $value
             */
            public static function read(string|int|\Stringable $key, $toCache=false, ...$args)
            {
                global $wpdb;
                static $query = null;

                if (empty($query)) {
                    $query = "SELECT `value`,`expires` FROM `%i` ".
                             "WHERE `key` = '%s' AND (`expires` = '%s' OR `expires` >= '%s') LIMIT 1";
                }

                // key, expires, transient, prefetch, sitewide, cache_id
                extract(self::parse_options($key,...$args));

                if ($result = $wpdb->get_results(
                        $wpdb->prepare($query,self::get_table(),$key,self::NULL_DATE,self::expires()) )
                ) {
                    $value  = maybe_unserialize($result[0]->value);
                    if ($toCache) {
                        $seconds = ($result[0]->expires != self::NULL_DATE)
                                    ? strtotime($expires) - strtotime('now')
                                    : 0;
                        wp_cache_set( $key, $value, $cache_id, $seconds );
                    }
                    unset(self::$missed_keys[self::$site_id][$key]);
                    return $value;
                }

                return null;
            }

            /**
             * Update cache value
             *
             * @param string        $key
             * @param mixed         $value value or null
             * @param mixed         $expires (timestamp, seconds, datetime, string)
             * @param string[]      $args - 'transient', 'prefeth', 'sitewide'
             * @return mixed        true
             */
            public static function put(string|int|\Stringable $key, $value, $expires=null, ...$args)
            {
                // key, expires, transient, prefetch, sitewide, cache_id
                extract(self::parse_options($key,$expires,...$args));

                if (is_null($value) || ($expires && $expires <= self::expires()))
                {
                    wp_cache_delete( $key, $cache_id );
                    if (!$transient || !wp_using_ext_object_cache()) {
                        // mark for deletion
                        self::mark_commit_key($key,false);
                    }
                }
                else
                {
                    $seconds = (is_string($expires)) ? strtotime($expires) - strtotime('now') : 0;
                    wp_cache_set( $key, $value, $cache_id, $seconds );
                    if (!$transient || !wp_using_ext_object_cache()) {
                        // mark for update
                        self::mark_commit_key($key,$expires ?? true);
                    }
                }

                return true;
            }

            /**
             * Write data to db
             *
             * @param string        $key
             * @param mixed         $value (null = get from cache)
             * @param mixed         $expires (timestamp, seconds, datetime, string)
             * @param string[]      $args - 'transient', 'prefeth', 'sitewide'
             * @return int          rows affected
             */
            public static function write(string|int|\Stringable $key, $value=null, $expires=null, ...$args)
            {
                global $wpdb;
                static $query = null;

                if (empty($query)) {
                    $query = "INSERT INTO `%i` (`key`, `value`, `expires`) VALUES ('%s', '%s', '%s') " .
                             "ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires` = VALUES(`expires`);";
                }

                // key, expires, transient, prefetch, sitewide, cache_id
                extract(self::parse_options($key,$expires,...$args));

                if (is_null($value)) {
                    $value  = wp_cache_get( $key, $cache_id, false, $found );
                    if (!$found) $value = null;
                } else if (is_object($value)) {
                    $value  = clone $value;
                }

                if (!is_null($value))
                {
                    $result = $wpdb->query(
                        $wpdb->prepare($query,self::get_table(),$key,maybe_serialize($value),(string)$expires)
                    );
                    if ($result === false) {
                        self::is_error($wpdb->last_error,__FUNCTION__);
                    } else { // update cache/expires
                        $seconds = (!empty($expires)) ? strtotime($expires) - strtotime('now') : 0;
                        wp_cache_set( $key, $value, $cache_id, $seconds );
                    }
                }

                // clear mark
                self::mark_commit_key($key);
                return $result;
            }

            /**
             * Delete cache value
             *
             * @param string        $key
             * @param string[]      $args - 'transient', 'prefeth', 'sitewide'
             * @return int          rows affected
             */
            public static function delete(string|int|\Stringable $key, ...$args)
            {
                global $wpdb;

                // key, expires, transient, prefetch, sitewide, cache_id
                extract(self::parse_options($key,...$args));

                wp_cache_delete( $key, $cache_id.'_prefetch' );
                wp_cache_delete( $key, $cache_id );
                $result = $wpdb->delete(self::get_table(),['key' => $key]);

                if ($result === false) {
                    self::is_error($wpdb->last_error,__FUNCTION__);
                }

                // mark as deleted
                self::mark_commit_key($key,null);
                return $result;
            }

            /**
             * Parse options
             *
             * @param string        $key
             * @param string[]      $args
             *                      - $expires (timestamp, seconds, datetime, string)
             *                      - 'transient', 'prefeth', 'sitewide'
             * @return array        ['key'=>,'expires'=>,'prefetch'=>,'sitewide'=>, 'cache_id'=>]
             */
            protected static function parse_options($key, ...$args )
            {
                $return = [
                    'key'           => trim( (string)$key ),
                    'expires'       => null,
                    'transient'     => false,
                    'prefetch'      => false,
                    'sitewide'      => false,
                    'cache_id'      => null,
                ];

                if (empty($return['key'])) {
                    self::is_error('invalid key-value key',__FUNCTION__);
                }

                foreach ($args as $options)
                {
                    if (is_bool($options) && $options) {                    // only bool option is prefetch
                        $return['prefetch'] = $options;
                    } else if (is_int($options) || is_object($options)) {   // must be expires secs,time,datetime
                        $return['expires'] = self::expires($options);
                    } else if (is_string($options)) {
                        if ($options == 'transient') {
                            $return['transient'] = true;
                        } else if ($options == 'prefetch') {
                            $return['prefetch'] = true;
                        } else if ($options == 'sitewide') {
                            $return['sitewide'] = true;
                        } else if ($expires = self::expires($options)) {    // maybe expires string
                            $return['expires'] = $expires;
                        }
                    } else if (is_array($options)) {                        // array of options
                        $return = array_merge($return,$options);
                    }
                }

                self::set_site_id($return['sitewide']);

                $return['cache_id'] = ($return['prefetch'])
                    ? self::$cache_id . '_prefetch'
                    : self::$cache_id;

                return $return;
            }

            /**
             * Cache list of missing keys of keys to be updated
             *
             * @param string        $key
             * @param mixed         $value
             *  time    - to be written to db, with expiration
             *  true    - to be written to db, no expiration
             *  false   - to be deleted from db
             *  null    - doesn't exists
             */
            private static function mark_commit_key($key,$value='')
            {
                if (func_num_args() == 1)               // clear key
                {
                    unset(self::$commit_keys[self::$site_id][$key]);
                    unset(self::$missed_keys[self::$site_id][$key]);
                }
                else if (is_null($value))               // does not exist
                {
                    self::$missed_keys[self::$site_id][$key] = null;
                    unset(self::$commit_keys[self::$site_id][$key]);
                }
                else                                    // to be committed
                {
                    self::$commit_keys[self::$site_id][$key] = $value;
                    if ($value) {   // to update
                        unset(self::$missed_keys[self::$site_id][$key]);
                    } else {        // to delete
                        self::$missed_keys[self::$site_id][$key] = null;
                    }

                    $count = count(self::$commit_keys,COUNT_RECURSIVE) - count(self::$commit_keys);
                    if ($count >= self::$auto_commit) {
                        self::commit();
                        self::onShutdown(false);
                    } else {
                        self::onShutdown(true);
                    }
                }
            }

            /**
             * On shutdown action
             */
            public static function onShutdown($set=true)
            {
                if ($set){
                    if (!has_action("shutdown", [self::class,'flush'])) {
                        add_action( "shutdown", [self::class,'flush'], PHP_INT_MAX );
                    }
                } else {
                    remove_action( "shutdown", [self::class,'flush'], PHP_INT_MAX );
                }
            }

            /**
             * Flush cache to db
             */
            public static function flush()
            {
                self::commit();
            }

            /**
             * Commit cache to db
             */
            protected static function commit()
            {
                foreach (self::$commit_keys as $site => $commit_keys)
                {
                    self::set_site_id($site);
                    self::commit_site($commit_keys);
                }
                self::$commit_keys = [];

                $parameters = [
                    'site_tables'   => self::$site_tables,
                    'missed_keys'   => self::$missed_keys,
                ];
                wp_cache_set(__CLASS__,$parameters,self::CACHE_ID);
            }

            /**
             * Commit cache to db for a site
             */
            protected static function commit_site($commit_keys)
            {
                global $wpdb;
                $toWrite = $toDelete = [];

                foreach ($commit_keys as $key => $isCached)
                {
                    if ($isCached === false) {
                        $toDelete[] = [
                            esc_sql($key)
                        ];
                    } else if (!is_null($isCached)) {
                        $toWrite[] = [
                            esc_sql($key),
                            maybe_serialize(
                                wp_cache_get($key,self::$cache_id.'_prefetch' ) ?: wp_cache_get($key,self::$cache_id )
                            ),
                            (is_bool($isCached) ? '' : $isCached)
                        ];
                    }
                }

                if (!empty($toWrite)) {
                    $query =
                        "INSERT INTO `".self::get_table()."` (`key`, `value`, `expires`)" .
                        " VALUES ".rtrim(str_repeat("('%s', '%s', '%s'),", count($toWrite)),',') .
                        " ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires` = VALUES(`expires`);";
                    $result = $wpdb->query( vsprintf($query,array_merge(...$toWrite)) );
                }

                if (!empty($toDelete)) {
                    $query =
                        "DELETE FROM `".self::get_table()."`" .
                        " WHERE `key` IN (".rtrim(str_repeat("'%s',", count($toDelete)),',').")";
                    $result = $wpdb->query( vsprintf($query,array_merge(...$toDelete)) );
                }
            }

            /**
             * multisite - set site id (and cache id)
             *
             * @param bool|int  $sitewide or site id
             * @return int      site id
             */
            protected static function set_site_id( $sitewide=false )
            {
                if (is_multisite())
                {
                    self::$site_id = ($sitewide !== false)
                        ? ( (is_int($sitewide)) ? $sitewide : 0 )
                        : get_current_blog_id();

                    self::$cache_id = (self::$site_id > 0)
                        ? self::CACHE_ID
                        : self::CACHE_ID.'_site';

                    if (!isset(self::$commit_keys[self::$site_id])) {
                        self::$commit_keys[self::$site_id] = [];
                    }
                    if (!isset(self::$missed_keys[self::$site_id])) {
                        self::$missed_keys[self::$site_id] = [];
                    }
                }

                return self::$site_id;
            }

            /**
             * Get expiration timestamp
             *
             * @param mixed     $expires (timestamp, seconds, datetime, string)
             * @return string|null
             */
            protected static function expires($expires='')
            {
                static $utc = null;
                if (!$utc) $utc = new \DateTimeZone('utc');

                if (func_num_args() == 0) {
                    $expires = 'now';
                } else if (!$expires) {
                    return null;
                }

                if (is_string($expires)) {
                    if ($expires != self::NULL_DATE) {
                        $expires = new \DateTime( $expires, wp_timezone() );
                    }
                } else
                if (is_int($expires)) {
                    if ($expires <= YEAR_IN_SECONDS) {
                        $expires += time();
                    }
                    $expires = new \DateTime( "@{$expires}", $utc );
                }

                if (is_a($expires,'DateTime')) {
                    $expires->setTimezone( $utc );
                    return $expires->format('Y-m-d H:i:s');
                }

                return null;
            }

            /**
             * Get table name, create table if needed
             * @return string|false
             */
            protected static function get_table()
            {
                global $wpdb;

                $table = $wpdb->get_blog_prefix(self::$site_id) . self::$cache_id;

                if (!isset(self::$site_tables[$table]))
                {
                    if (! ($wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) == $table) ) {
                        self::$site_tables[$table] = self::create_table($table);
                    }
                }

                return $table;
            }

            /**
             * Create cache table
             */
            protected static function create_table($table)
            {
                global $wpdb;

                $charset_collate = $wpdb->get_charset_collate();

                $result = $wpdb->query(
                    "CREATE TABLE IF NOT EXISTS `{$table}` (
                        `id` bigint(10) unsigned NOT NULL AUTO_INCREMENT,
                        `key` varchar(255) NOT NULL,
                        `value` longtext NOT NULL,
                        `expires` timestamp NOT NULL DEFAULT '".SELF::NULL_DATE."',
                        PRIMARY KEY (`id`), UNIQUE `key` (`key`), key `expires` (expires)
                    ) ENGINE=InnoDB {$charset_collate};",
                );

                if (!$result) {
                    self::is_error($wpdb->last_error,__FUNCTION__);
                }

                return true;
            }

            /**
             * Schedule cache purge
             */
            public static function schedule_purge()
            {
                if (self::$purge_schedule && self::$purge_initial)
                {
                    $when       = self::$purge_schedule;
                    $eventName  = "eacKeyValue_{$when}_purge";
                    if (!wp_get_scheduled_event( $eventName )) {
                        $eventTime = new \DateTime(self::$purge_initial, wp_timezone());
                        wp_schedule_event($eventTime->getTimestamp(),$when,$eventName);
                    }
                    add_action( $eventName, [self::class, 'purge_expired_keys'] );
                }
            }

            /**
             * Purge expired cache records.
             */
            public static function purge_expired_keys()
            {
                global $wpdb;
                $sites = [];

                $sites[ self::set_site_id(false) ]  = self::get_table();    // current blog
                $sites[ self::set_site_id(true) ]   = self::get_table();    // maybe site

                foreach ($sites as $site => $table)
                {
                    $result = $wpdb->query(sprintf(
                        "DELETE FROM `%s` WHERE `expires` > '%s' AND `expires` < '%s'",
                        $table, self::NULL_DATE, self::expires() )
                    );

                    if (!$result) {
                        self::is_error($wpdb->last_error,__FUNCTION__);
                    }
                }
            }

            /**
             * register error
             *
             * @param string    $message
             * @param string    $source (__FUNCTION__)
             */
            protected static function is_error( $message, $source )
            {
                $classname  = basename(str_replace('\\', '/', __CLASS__));
                $source     = $classname . '::' . $source;
                error_log($source.': '.$message);
                throw new \Exception($source.': '.$message);
            }
        } // class
        /*
         * Initialize the class
         */
        eacKeyValue::factory();
    } // class_exists
} // namespace EarthAsylumConsulting


namespace  // global scope
{
    if (! function_exists('getKeyValue'))
    {
        /**
         * Get a key-value pair
         *
         * @param Stringable        $key
         * @param mixed|callable    $default (if key is not found)
         * @param string[]          $args - 'transient', 'prefeth', 'sitewide'
         * @return mixed            unserialized value
         */
        function getKeyValue($key, $default=null, ...$args)
        {
            return \EarthAsylumConsulting\eacKeyValue::get($key, $default, ...$args);
        }
    }

    if (! function_exists('setKeyValue'))
    {
        /**
         * Set a key-value pair
         *
         * @param Stringable        $key
         * @param mixed             $value
         * @param mixed             $expires (timestamp, seconds, datetime, string)
         * @param string[]          $args - 'transient', 'prefeth', 'sitewide'
         * @return bool             true
         */
        function setKeyValue($key, $value, $expires=null, ...$args)
        {
            return \EarthAsylumConsulting\eacKeyValue::put($key, $value, $expires, ...$args);
        }
    }

    /*
     * simple tests/examples
     */

    /*
    add_action('admin_init',function()
    {
        // get/set a key
        if ($value = getKeyValue('key_value_test')) {
            echo "<div class='notice'><pre>get key_value_test ".var_export($value,true)."</pre></div>";
            setKeyValue('key_value_test',null);
        } else {
            setKeyValue('key_value_test',wp_date('c'),HOUR_IN_SECONDS);
        }

        // get/set a key using a callback
		$value = getKeyValue( 'key_value_callback', function($key)
			{
				setKeyValue( $key, wp_date('c'), MINUTE_IN_SECONDS );
				return $value;
			}
		);

        // get/set a transient key
        if ($value = getKeyValue('key_value_transient','transient')) {
            echo "<div class='notice'><pre>get key_value_transient ".var_export($value,true)."</pre></div>";
            setKeyValue('key_value_transient',null);
        } else {
            setKeyValue('key_value_transient',wp_date('c'),HOUR_IN_SECONDS,'transient');
        }

        // get/set a prefetch key
        if ($value = getKeyValue('key_value_prefetch')) {
            echo "<div class='notice'><pre>get key_value_prefetch ".var_export($value,true)."</pre></div>";
            setKeyValue('key_value_prefetch',null);
        } else {
            setKeyValue('key_value_prefetch',wp_date('c'),HOUR_IN_SECONDS,'prefetch');
        }

        // get/set a sitewide key
        if ($value = getKeyValue('key_value_site',null,'sitewide')) {
            echo "<div class='notice'><pre>get key_value_site ".var_export($value,true)."</pre></div>";
            setKeyValue('key_value_site',null,'sitewide');
        } else {
            setKeyValue('key_value_site',wp_date('c'),HOUR_IN_SECONDS,'sitewide');
        }

        // get/set a sitewide-prefetch key
        if ($value = getKeyValue('key_value_site_prefetch',null,'sitewide')) {
            echo "<div class='notice'><pre>get key_value_site_prefetch ".var_export($value,true)."</pre></div>";
            setKeyValue('key_value_site_prefetch',null,'sitewide');
        } else {
            setKeyValue('key_value_site_prefetch',wp_date('c'),HOUR_IN_SECONDS,'prefetch','sitewide');
        }
    });
    */
}
