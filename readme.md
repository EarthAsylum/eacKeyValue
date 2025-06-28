## {eac}KeyValue - key-value pair storage mechanism for WordPress
[![EarthAsylum Consulting](https://img.shields.io/badge/EarthAsylum-Consulting-0?&labelColor=6e9882&color=707070)](https://earthasylum.com/)
[![WordPress](https://img.shields.io/badge/WordPress-Plugins-grey?logo=wordpress&labelColor=blue)](https://wordpress.org/plugins/search/EarthAsylum/)


<details><summary>Document Header</summary>

Plugin URI:             https://github.com/EarthAsylum/eacKeyValue  
Author:                 [EarthAsylum Consulting](https://www.earthasylum.com)  
Stable tag:             1.1.0  
Last Updated:           27-Jun-2025  
Requires at least:      5.8  
Tested up to:           6.8  
Requires PHP:           8.1  
Contributors:           [earthasylum](https://github.com/earthasylum),[kevinburkholder](https://profiles.wordpress.org/kevinburkholder)  
License:                GPLv3 or later  
License URI:            https://www.gnu.org/licenses/gpl.html  
GitHub URI:             https://github.com/EarthAsylum/eacKeyValue  

</details>

An easy to use, efficient, key-value pair storage mechanism for WordPress that takes advatage of the WP Object Cache.
Similar to WP options/transients with less overhead and greater efficiency (and fewer hooks).

### Description

**{eac}KeyValue** Is added to WordPress as a stand-alone, *Must Use* plugin or by including the file in any project or plugin. It provides a simple API for storing and retrieving key/value pairs with any data type. Integrated tightly with the [WordPress object cache](https://developer.wordpress.org/reference/classes/wp_object_cache/) (whether the default or a drop-in persistent cache), _{eac}KeyValue_ provides L1 (memory) caching _and_ L2 (MySQL) permanence as well as Write-Back (delayed) or Write-Through (immediate) updating for greater efficiency.

#### Actors *SHOULD* use global functions:

    set_key_value( $key, $value, [$expires] );          // add/update key/value
    set_key_value( $key, null );                        // delete key/value
    $value = get_key_value( $key, [$default] );         // read key/value

    set_site_key_value( $key, $value, [$expires] );     // add/update site-wide key/value
    set_site_key_value( $key, null );                   // delete site-wide key/value
    $value = get_site_key_value( $key, [$default] );    // read site-wide key/value

#### Actors *CAN* use class methods:

    eacKeyValue::put( $key, $value, [$expires] );       // add/update key/value
    eacKeyValue::put( $key, null );                     // delete key/value
    $value = eacKeyValue::get( $key, [$default] );      // read key/value

    $value = eacKeyValue::read( $key, [$toCache] );     // read db value, bypass object cache [and add to cache]
    eacKeyValue::write( $key, $value, [$expires] );     // write (immediate) value to db
    eacKeyValue::delete( $key );                        // delete (immediate) value from db
    eacKeyValue::flush();                               // write cache to db (automatic on shutdown)

#### Method Arguments:
<pre>
$key        stringable          The key to store/access
$default    mixed|callable      default value when $key is not found (null)
$value      mixed|null          data to be stored (should not be serialized).
$expires    mixed|null          The expiration of the key/value pair.
                                null            - no expiration
                                int (<= 1 year) - seconds from now
                                int ( > 1 year) - timestamp (UTC)
                                string          - textual datetime, local time (wp_timezone)
                                DateTime object - converted to UTC
</pre>

>   Passing `$expires` with `$default` to `get_key_value()` will save the key/value if the default value is used.

#### Optional Parameters

These parameters alter functionality and are used to determine group keys. As such, they must be used both when setting and when getting a key.

`sitewide` - For multisite installations, indicates this is a site-wide key/value. Site-wide items apply to all blogs in a multisite environment. `sitewide` has no effect on a single site installation.

    set_key_value( $key, $value, [$expires], "sitewide" );
    get_key_value( $key, $default, "sitewide" );

>   `set_site_key_value()` and `get_site_key_value()` automatically add the `sitewide` option.

    set_site_key_value( $key, $value, [$expires] );
    get_site_key_value( $key, $default );

`transient` - Treat this key/value as transient. When using an external object cache, the key/value is not stored in the key-value table, assuming that the object cache will store it.

    set_key_value( $key, $value, [$expires], "transient" );
    get_key_value( $key, $default, "transient" );

`nocache` - Marks the key/value as "non-persistent" so an external object cache will not store the key/value. It is stored in the key-value table.

    set_key_value( $key, $value, [$expires], "nocache" );
    get_key_value( $key, $default, "nocache" );

`prefetch` - If the object cache supports pre-fetching, indicates this should be a pre-fetched key/value. Pre-fetched items are loaded and cached in a single operation at the start of a request.

    set_key_value( $key, $value, [$expires], "prefetch" );
    get_key_value( $key, $default, "prefetch" );

`encrypt` or `decrypt` - Uses [{eac}Doojigger](https://eacdoojigger.earthasylum.com) (with encryption extension) to encrypt the value when storing or caching and decrypt the value when retrieving.

    set_key_value( $key, $value, [$expires], "encrypt" );
    get_key_value( $key, $default, "decrypt" );
    
Optional parameters (including $expires) may be combined in any order.

    // set_key_value: $key, $value must be the first 2 function arguments
    set_key_value( $key, $value, $expires, "prefetch", "transient" );
    set_key_value( $key, $value, "prefetch", "transient" );
    set_key_value( $key, $value, "transient", "prefetch", $expires );

    // get_key_value: $key, $default must be the first 2 function arguments
    get_key_value( $key, $default, "transient", "sitewide" );
    get_key_value( $key, null, "sitewide", "transient" );
    
#### Examples:

Store a permanent key/value:

    set_key_value( 'my_permanent_key', $value );

Retrieve a key/value:

    $value = get_key_value( 'my_permanent_key' );

Store a key/value with an expiration:

    set_key_value( 'my_temporary_key', $value, HOUR_IN_SECONDS );
    set_key_value( 'my_temporary_key', $value, time() + HOUR_IN_SECONDS );
    set_key_value( 'my_temporary_key', $value, '1 hour' );

Set a site-wide, transient key/value:

    set_site_key_value('my_transient_key', $value, HOUR_IN_SECONDS, 'transient');
    set_key_value('my_transient_key', $value, HOUR_IN_SECONDS, 'transient', 'sitewide');

Retrieve a key with a default value:

    $value = get_key_value( 'my_not_found_key', 'default_value' );

Using a callback when retrieving a key:

    $value = get_key_value( 'my_not_found_key', function($key, ...$args)
        {
            // do something to generate $value, and save it
            set_key_value( $key, $value, HOUR_IN_SECONDS );
            return $value;
        }
    );

    $value = get_key_value( 'my_not_found_key', function($key, ...$args)
        {
            // do something to generate $value
            return $value;
        },
        HOUR_IN_SECONDS
    );

Store/Retrieve an encrypted key/value:

    set_key_value( 'my_encrypted_key', $value, 'encrypt' );
    get_key_value( 'my_encrypted_key', null, 'decrypt' );

Delete a key/value:

    set_key_value( 'my_permanent_key', null );

#### Optional constants:

Constants may be defined in wp-config.php.

+   When scheduling the automatic purge, set the interval to schedule. Must be a valid WP schedule name.  
`define( 'EAC_KEYVALUE_PURGE_SCHEDULE', string|false );     // default: 'daily'`  

+   When scheduling the automatic purge, set the initial start time as timestamp or strtotime.  
`define( 'EAC_KEYVALUE_PURGE_START', int|string|false );    // default: 'tomorrow 2:15am'`  

+   Set the maximum number of records to hold before a database commit.  
`define( 'EAC_KEYVALUE_AUTO_COMMMIT', int );                //  default: 1,000`  

>   If the installed object cache has the `delayed_writes` property set (`$wp_object_cache->delayed_writes`), this value will override the default auto commit.

+   Override (force) the storage of transient keys when using an external object cache.  
`define( 'EAC_KEYVALUE_PERSIST_TRANSIENTS', true );         //  default: false unless no external object cache`
  
- - -

### Installation

+   [Download {eac}KeyValue](https://github.com/EarthAsylum/eacKeyValue/archive/refs/heads/main.zip) from the (GitHub repository)[https://github.com/EarthAsylum/eacKeyValue].

+   Drop the `eacKeyValue.php` file into your `wp-content/mu-plugins` folder and add `set_key_value()` and `get_key_value()` calls as needed.
  
- - -

### Other Notes

This plugin is included with and used by [{eac}Doojigger](https://eacdoojigger.earthasylum.com), An advanced rapid plugin development platform.

See also: [{eac}ObjectCache](https://eacdoojigger.earthasylum.com/eacobjectcache/) - a persistent object cache using a SQLite database to cache WordPress objects; A drop-in replacement to the WP_Object_Cache used by WordPress.
  
- - -

### Changelog

#### Version 1.1.0 – June 27, 2025

+   Made this {eac}Doojigger helper a stand-alone MU-Plugin.
+   Allow `$expires` with `get_key_value()` to `set_key_value()` the `$default` value.
+   Added `encrypt` option.
+   Added `transient` option.
+   Added `nocache` option (non-persistent).
+   Added `sitewide` option for multi-site support.
+   Added `EAC_KEYVALUE_PERSIST_TRANSIENTS` constant.
+   Support and parse variable argument signatures.
+   Cache eacKeyValue dynamic settings (tables & missed keys).
+   Global functions `get_key_value()` / `get_site_key_value()` / `set_key_value()` / `set_site_key_value()`
