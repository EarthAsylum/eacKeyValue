## {eac}KeyValue - key-value pair storage mechanism for WordPress
[![EarthAsylum Consulting](https://img.shields.io/badge/EarthAsylum-Consulting-0?&labelColor=6e9882&color=707070)](https://earthasylum.com/)
[![WordPress](https://img.shields.io/badge/WordPress-Plugins-grey?logo=wordpress&labelColor=blue)](https://wordpress.org/plugins/search/EarthAsylum/)


<details><summary>Document Header</summary>

Plugin URI:             https://github.com/EarthAsylum/eacKeyValue  
Author:                 [EarthAsylum Consulting](https://www.earthasylum.com)  
Stable tag:             1.1.0  
Last Updated:           11-Jun-2025  
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

**{eac}KeyValue** Is added to WordPress as a stand-alone, *Must Use* plugin or by including the file in any project or plugin. It provides a simple API for storing and retrieving key-value pairs with any data type. Integrated tightly with the [WordPress object cache](https://developer.wordpress.org/reference/classes/wp_object_cache/) (whether the default or a drop-in persistent cache), _{eac}KeyValue_ provides L1 (memory) caching _and_ L2 (MySQL) permanence as well as Write-Back (delayed) or Write-Through (immediate) updating for greater efficiency.

#### Actors *SHOULD* use global functions:

    setKeyValue( $key, $value, [$expires] );            // add/update cache value
    setKeyValue( $key, null );                          // delete cache value
    $value = getKeyValue( $key, [$default] );           // read cache value

#### Actors *CAN* use class methods:

    eacKeyValue::put( $key, $value, [$expires] );       // add/update cache value
    eacKeyValue::put( $key, null );                     // delete cache value
    $value = eacKeyValue::get( $key, [$default] );      // read cache value

    $value = eacKeyValue::read( $key, [$toCache] );     // read db value, bypass object cache [and add to cache]
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
                                DateTime object - converted to UTC

#### Optional Parameters

`sitewide` - For multisite, indicates this is a site-wide key/value. Site-wide items apply to all blogs in a multisite environment.

    setKeyValue( $key, $value, [$expires], "sitewide");
    getKeyValue( $key, $default, "sitewide");

`transient` - Treat this key/value as transient. When using an external object cache, the key/value is not stored in the key-value table (assuming that the object cache stores it). `transient` has no effect without an external object cache.

    setKeyValue( $key, $value, [$expires], "transient");

`prefetch` - If the object cache supports pre-fetching, indicates this should be a pre-fetched key/value. Pre-fetched items are loaded and cached in a single operation at the start of a request.

    setKeyValue( $key, $value, [$expires], "prefetch");
    
Optional parameters may be combined in any order.

    setKeyValue( $key, $value, [$expires], "sitewide", "transient");
    setKeyValue( $key, $value, [$expires], "prefetch", "sitewide");
    setKeyValue( $key, $value, [$expires], "transient", "prefetch", "sitewide");
    
#### Examples:

+   Store a permanent key/value:
```php
setKeyValue( 'my_permanent_key', $value );
```

+   Retrieve a key/value:
```php
$value = getKeyValue( 'my_permanent_key' );
```

+   Store a key/value with an expiration:
```php
setKeyValue( 'my_temporary_key', $value, HOUR_IN_SECONDS );
setKeyValue( 'my_temporary_key', $value, time() + HOUR_IN_SECONDS );
setKeyValue( 'my_temporary_key', $value, '1 hour' );
```

+   Set a site-wide, transient key/value:
```php
setKeyValue('my_transient_key', $value, HOUR_IN_SECONDS, 'transient', 'sitewide');
```

+   Retrieve a key with a default value:
```php
$value = getKeyValue( 'my_not_found_key', time() );
```

+   Using a callback when retrieving a key:
```php
$value = getKeyValue( 'my_not_found_key', function($key)
    {
        // do something to generate $value
        setKeyValue( $key, $value, HOUR_IN_SECONDS );
        return $value;
    }
);
```

+   Delete a key/value:
```php
setKeyValue( 'my_permanent_key', null );
```

#### Optional constants:

+   When scheduling the automatic purge, set the interval to schedule. Must be a valid WP schedule name.  
`define( 'EAC_KEYVALUE_PURGE_SCHEDULE', string|false );  // default: 'daily'`  

+   When scheduling the automatic purge, set the initial start time as timestamp or strtotime.  
`define( 'EAC_KEYVALUE_PURGE_START', int|string|false ); // default: 'tomorrow 2:15am'`  

+   Set the maximum number of records to hold before a database commit.  
`define( 'EAC_KEYVALUE_AUTO_COMMMIT', int );             //  default: 1,000`  

>   If the installed object cache has the `delayed_writes` property set (`$wp_object_cache->delayed_writes`), this value will override the default auto commit.

- - -

### Installation

+   Drop the `eacKeyValue.php` file into your `wp-content/mu-plugins` folder and add `setKeyValue()` and `getKeyValue()` calls as needed.

- - -

### Other Notes

See [{eac}ObjectCache](https://eacdoojigger.earthasylum.com/eacobjectcache/) - a persistent object cache using a SQLite database to cache WordPress objects; A drop-in replacement to the WP_Object_Cache used by WordPress.

- - -

### Changelog

#### Version 1.1.0 – June 11, 2025

+   Added multi-site support.
+   Support variable arguments.
+   Added 'transient' option.
+   Cache eacKeyValue dynamic settings (tables & missed keys).
