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

When scheduling the automatic purge, set the interval to schedule. Must be a valid WP schedule name.

     define( 'EAC_KEYVALUE_PURGE_SCHEDULE', string|false );  // default: 'daily'

When scheduling the automatic purge, set the initial start time as timestamp or strtotime.

     define( 'EAC_KEYVALUE_PURGE_START', int|string|false ); // default: 'tomorrow 2:15am'

Set the maximum number of records to hold before a database commit.
 
     define( 'EAC_KEYVALUE_AUTO_COMMMIT', int );             //  default: 1,000

>   If the installed object cache has the `delayed_writes` property set (`$wp_object_cache->delayed_writes`), this value will override the default auto commit.


### {eac}KeyValueCapture

This plugin uses _{eac}KeyValue_ to capture WordPress options and transients to direct them to the key-value API.

_This is experimental_


To capture an option and route it to eacKeyValue:

     eacKeyValueCapture::option_capture('option_name');

Add 'true' to have the original value saved to cache and deleted from WP options:

     eacKeyValueCapture::option_capture('option_name',true);

To release a captured option (restores to WP options):

     eacKeyValueCapture::option_release('option_name');

To capture a transient and route it to eacKeyValue:

     eacKeyValueCapture::transient_capture('transient_name');

Add 'true' to have the original value saved to cache and deleted from WP options:

     eacKeyValueCapture::transient_capture('transient_name',true);

To release a captured transient:

     eacKeyValueCapture::transient_release('transient_name');

Global alias functions:

     capture_option() -> \EarthAsylumConsulting\eacKeyValueCapture::option_capture()
     release_option() -> \EarthAsylumConsulting\eacKeyValueCapture::option_release()
     capture_transient() -> \EarthAsylumConsulting\eacKeyValueCapture::transient_capture()
     release_transient() -> \EarthAsylumConsulting\eacKeyValueCapture::transient_release()

Notes:

- `add_option()` and `delete_option()` can not be circumvented but are captured to eacKeyValue.
- If an option doesn't exist in WP options, it won't be deleted from eacKeyValue with `delete_option()`.
- Since we circumvent `update_option()` but not `add_option()`, an option that was updated but never added, can't be deleted from eacKeyValue.

- `set_transient()` can not be circumvented (but the called update_option is).

- WP's `$alloptions`/`$nooptions` caching with a persistent object caching may interfere with pushing an option or transient back to the options table when releasing.

- When circumventing functions using "pre" filters, the usual default hooks are not triggered. This could be detrimental to other processes and may be addressed in the future.


### Installation

**{eac}KeyValue**
-   Drop the `eacKeyValue.php` file into your `wp-content/mu-plugins` folder.

**{eac}KeyValueCapture**
-   Drop the `eacKeyValueCapture.php` file into your `wp-content/mu-plugins` folder or include it in your `functions.php` and add `capture_option()` or `capture_transient()` calls as needed.

