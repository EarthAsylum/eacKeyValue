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
 * Last Updated:        07-Jun-2025
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
 * *****
 *
    #### Actors *SHOULD* use global functions:

        setKeyValue( $key, $value, [$expires] );            // add/update cache value
        setKeyValue( $key, null );                          // delete cache value
        $value = getKeyValue( $key, $default );             // read cache value

        setSiteKeyValue( $key, $value, [$expires] );        // add/update site-wide cache value
        setSiteKeyValue( $key, null );                      // delete site-wide cache value
        $value = getSiteKeyValue( $key, $default );         // read site-wide cache value

    #### Actors *CAN* use class methods:

        eacKeyValue::put( $key, $value, [$expires] );       // add/update cache value
        eacKeyValue::put( $key, null );                     // delete cache value
        $value = eacKeyValue::get( $key, $default );        // read cache value

        $value = eacKeyValue::read( $key );                 // read db value, bypass object cache
        eacKeyValue::write( $key, $value, [$expires] );     // write (immediate) value to db
        eacKeyValue::delete( $key );                        // delete (immediate) value from db
        eacKeyValue::flush();                               // write cache to db (automatic on shutdown)

    #### Method Parameters:

        $key     stringable         The key to store/access
        $default mixed|callable     default value when $key is not found
        $value   mixed|null         data to be stored (should not be serialized).
        $expires mixed|null         The expiration of the key/value pair.
                                    null            - no expiration
                                    int (<= 1 year) - seconds from now
                                    int ( > 1 year) - timestamp (UTC)
                                    string          - textual datetime, local time (wp_timezone)
                                    DateTime object - converted to utc

    #### Optional constants:

    When scheduling the automatic purge, set the interval to schedule. Must be a valid WP schedule name.
        `define( 'EAC_KEYVALUE_PURGE_SCHEDULE', string|false );  // default: 'daily'`
    When scheduling the automatic purge, set the initial start time as timestamp or strtotime.
        `define( 'EAC_KEYVALUE_PURGE_START', int|string|false ); // default: 'tomorrow 2:15am'`
    Set the maximum number of records to hold before a database commit.
        `define( 'EAC_KEYVALUE_AUTO_COMMMIT', int );             //  default: 1,000`
    >   If the installed object cache has the `delayed_writes` property set (`$wp_object_cache->delayed_writes`), this value will override the default auto commit.
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
             * Maybe optional init method.
             * add_action('init', ['\EarthAsylumConsulting\eacKeyValue','init']);
             */
            public static function init()
            {
                global $wp_object_cache;

                wp_cache_add_global_groups(self::CACHE_ID.'_site');

                if (defined('EAC_KEYVALUE_AUTO_COMMMIT') && is_int(EAC_KEYVALUE_AUTO_COMMMIT)) {
                    self::$auto_commit      = EAC_KEYVALUE_AUTO_COMMMIT;
                } else if (isset($wp_object_cache->delayed_writes) && is_int($wp_object_cache->delayed_writes)) {
                    self::$auto_commit      = $wp_object_cache->delayed_writes;     // {eac}ObjectCache
                }

                if (defined('EAC_KEYVALUE_PURGE_SCHEDULE')) {
                    self::$purge_schedule   = EAC_KEYVALUE_PURGE_SCHEDULE;
                }

                if (defined('EAC_KEYVALUE_PURGE_START')) {
                    self::$purge_initial    = self::expires(EAC_KEYVALUE_PURGE_START);
                }

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
             * @param bool|int          $_site set for site-wide key/value
             * @return mixed|null       $value
             */
            public static function get(string|int|\Stringable $key, $default=null, $_site=false)
            {
                $key = self::validate_site_key($key,$_site);

                if (array_key_exists($key,self::$missed_keys[self::$site_id]))
                {
                    // we already know the key doesn't exist
                    $result = null;
                }
                else
                {
                    // check the object cache
                    $result = wp_cache_get( $key, self::$cache_id, false, $found );
                    // check the database
                    if (!$found) {
                        $result  = self::read($key,true);
                    }
                }

                if (is_null($result))
                {
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
             * @param bool|int      $_site set for site-wide key/value
             * @return mixed|null   $value
             */
            public static function read(string|int|\Stringable $key, $toCache=false, $_site=false)
            {
                global $wpdb;
                static $query = null;

                if (empty($query)) {
                    $query = "SELECT `value`,`expires` FROM `%s` ".
                             "WHERE `key` = '%s' AND (`expires` = '%s' OR `expires` >= '%s') LIMIT 1";
                }

                $key = self::validate_site_key($key,$_site);

                if ($result = $wpdb->get_results(
                        sprintf($query,self::get_table(),$key,self::NULL_DATE,self::expires()) )
                ) {
                    $value   = maybe_unserialize($result[0]->value);
                    if ($toCache) {
                        $seconds = ($result[0]->expires != self::NULL_DATE)
                                    ? strtotime($expires) - strtotime('now')
                                    : 0;
                        wp_cache_set( $key, $value, self::$cache_id, $seconds );
                    }
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
             * @param bool|int      $_site set for site-wide key/value
             * @return mixed        $value
             */
            public static function put(string|int|\Stringable $key, $value, $expires=null, $_site=false)
            {
                $key        = self::validate_site_key($key,$_site);
                $expires    = self::expires($expires);

                if (is_null($value) || ($expires && $expires <= self::expires()))
                {
                    wp_cache_delete( $key, self::$cache_id );
                    // mark for deletion
                    self::mark_commit_key($key,false);
                }
                else
                {
                    $seconds = (is_string($expires)) ? strtotime($expires) - strtotime('now') : 0;
                    wp_cache_set( $key, $value, self::$cache_id, $seconds );
                    // mark for update
                    self::mark_commit_key($key,$expires ?? true);
                }

                return $value;
            }

            /**
             * Write data to db
             *
             * @param string        $key
             * @param mixed         $value (null = get from cache)
             * @param mixed         $expires (timestamp, seconds, datetime, string)
             * @param bool|int      $_site set for site-wide key/value
             * @return int          rows affected
             */
            public static function write(string|int|\Stringable $key, $value=null, $expires=null, $_site=false)
            {
                global $wpdb;
                static $query = null;

                if (empty($query)) {
                    $query = "INSERT INTO `%s` (`key`, `value`, `expires`) VALUES ('%s', '%s', '%s') " .
                             "ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires` = VALUES(`expires`);";
                }

                $key        = self::validate_site_key($key,$_site);
                $expires    = self::expires($expires) ?? '';

                if (is_null($value)) {
                    $value  = wp_cache_get( $key, self::$cache_id, false, $found );
                    if (!$found) $value = null;
                } else if (is_object($value)) {
                    $value  = clone $value;
                }

                if (!is_null($value))
                {
                    $result = $wpdb->query(
                        sprintf($query,self::get_table(),$key,maybe_serialize($value),$expires)
                    );
                    if ($result === false) {
                        self::is_error($wpdb->last_error,__FUNCTION__);
                    } else {
                        $seconds = (!empty($expires)) ? strtotime($expires) - strtotime('now') : 0;
                        wp_cache_set( $key, $value, self::$cache_id, $seconds );
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
             * @param bool|int      $_site set for site-wide key/value
             * @return int          rows affected
             */
            public static function delete(string|int|\Stringable $key, $_site=false)
            {
                global $wpdb;
                static $query = null;

                if (empty($query)) {
                    $query = "DELETE FROM `%s` WHERE `key` = '%s'";
                }

                $key = self::validate_site_key($key,$_site);

                wp_cache_delete( $key, self::$cache_id );
                $result = $wpdb->query( sprintf($query,self::get_table(),$key) );

                if ($result === false) {
                    self::is_error($wpdb->last_error,__FUNCTION__);
                }

                // mark as deleted
                self::mark_commit_key($key,null);
                return $result;
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
                    if ($value) {	// to update
	                    unset(self::$missed_keys[self::$site_id][$key]);
	                } else {		// to delete
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
                $parameters = [
                    'site_tables'   => self::$site_tables,
                    'missed_keys'   => self::$missed_keys,
                ];
                wp_cache_set(__CLASS__,$parameters,self::CACHE_ID);

                foreach (self::$commit_keys as $site => $commit_keys)
                {
                    self::$site_id = $site;
                    if (is_multisite()) {
                        self::$cache_id = (self::$site_id > 0)
                            ? self::CACHE_ID
                            : self::CACHE_ID.'_site';
                    }
                    self::commit_site($commit_keys);
                }
                self::$commit_keys = [];
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
                            $key
                        ];
                    } else if (!is_null($isCached)) {
                        $toWrite[] = [
                            $key, maybe_serialize(wp_cache_get( $key, self::$cache_id )), (is_bool($isCached) ? '' : $isCached)
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
             * validate key, set site
             *
             * @param string        $key
             * @param bool|int      $_site set for site-wide key/value
             * @return string       maybe sets site, returns key
             */
            protected static function validate_site_key( string|int|\Stringable $key, $_site=false )
            {
                $key = trim( (string)$key );
                if (empty($key)) {
                    self::is_error('invalid key-value key',__FUNCTION__);
                }

                self::set_site_id($_site);

                return $key;
            }

            /**
             * set site
             *
             * @param bool|int      $_site set for site-wide/gllobal
             * @return int          site id
             */
            protected static function set_site_id( $_site=false )
            {
                if (is_multisite())
                {
                    self::$site_id = ($_site !== false)
                        ? ( (is_int($_site)) ? $_site : 0 )
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
                        "DELETE FROM `%s` WHERE `expires` > '%s' AND `expires` < %s",
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
    } // class_exists
} // namespace EarthAsylumConsulting


namespace  // global scope
{
    if (! function_exists('getKeyValue'))
    {
        function getKeyValue($key, $default=null) {
            return \EarthAsylumConsulting\eacKeyValue::get($key, $default);
        }
        function getSiteKeyValue($key, $default=null) {
            return \EarthAsylumConsulting\eacKeyValue::get($key, $default, true);
        }
    }
    if (! function_exists('setKeyValue'))
    {
        function setKeyValue($key, $value, $expires=null) {
            return \EarthAsylumConsulting\eacKeyValue::put($key, $value, $expires);
        }
        function setSiteKeyValue($key, $value, $expires=null) {
            return \EarthAsylumConsulting\eacKeyValue::put($key, $value, $expires, true);
        }
    }
    if (!has_action('init', ['\EarthAsylumConsulting\eacKeyValue','init']))
    {
         add_action('init', ['\EarthAsylumConsulting\eacKeyValue','init']);
    }
}
