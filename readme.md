## {eac}KeyValue - key-value pair storage mechanism for WordPress
[![EarthAsylum Consulting](https://img.shields.io/badge/EarthAsylum-Consulting-0?&labelColor=6e9882&color=707070)](https://earthasylum.com/)
[![WordPress](https://img.shields.io/badge/WordPress-Plugins-grey?logo=wordpress&labelColor=blue)](https://wordpress.org/plugins/search/EarthAsylum/)


<details><summary>Document Header</summary>

Plugin URI:             https://github.com/EarthAsylum/eacKeyValue  
Author:                 [EarthAsylum Consulting](https://www.earthasylum.com)  
Stable tag:             1.0.0  
Last Updated:           06-Jun-2025  
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

**{eac}KeyValue** Is added to WordPress as a stand-alone, "Must Use" plugin or by including the file in any project or plugin. It provides a simple API for storing and retrieving key-value pairs with any data type. Integrated tightly with the [WordPress object cache](https://developer.wordpress.org/reference/classes/wp_object_cache/) (whether the default or a drop-in persistent cache), _{eac}KeyValue_ provides L1 (memory) caching _and_ L2 (MySQL) permanence as well as Write-Back (delayed writes) or Write-Through updating for greater efficiency.

#### Actors SHOULD use:

     eacKeyValue::put( $key, $value, [$expires] );       // add/update cache value
     eacKeyValue::put( $key, null );                     // delete cache value
     $value = eacKeyValue::get( $key );                  // read cache value

#### Actors CAN use:

     $value = eacKeyValue::read( $key );                 // read db value, bypass object cache
     eacKeyValue::write( $key, $value, [$expires] );     // write (immediate) value to db
     eacKeyValue::delete( $key );                        // delete (immediate) value from db
     eacKeyValue::flush();                               // write cache to db (automatic on shutdown)

#### Method Parameters:

    $key     stringable  The key to store/access
    $value   mixed|null  data to be stored (should not be serialized).
    $expires mixed|null  The expiration of the key/value pair.
                         null            - no expiration
                         int (<= 1 year) - seconds from now
                         int ( > 1 year) - timestamp (UTC)
                         string          - textual datetime, local time (wp_timezone)
                         DateTime object - converted to utc

#### Global alias functions:

     \putKeyValue() -> \EarthAsylumConsulting\eacKeyValue::put()
     \getKeyValue() -> \EarthAsylumConsulting\eacKeyValue::get()

#### Optional constants:

Set the maximum number of records to hold before a commit.
 
     define( 'EAC_KEYVALUE_AUTO_COMMMIT', int );             //  default: 1,000

When scheduling an automatic purge, set the interval to schedule. Must be a valid WP schedule name.

     define( 'EAC_KEYVALUE_PURGE_SCHEDULE', string|false );  // default: 'daily'

When scheduling an automatic purge, set the initial start time as timestamp or strtotime.

     define( 'EAC_KEYVALUE_PURGE_START', int|string|false ); // default: 'tomorrow 2:15am'

>   If the installed object cache has the 'delayed_writes' property set (`$wp_object_cache->delayed_writes`), this value will override the default (1,000) auto commit.


### Installation

-   Drop the `eacKeyValue.php` file into your `wp-content/mu-plugins` folder.

