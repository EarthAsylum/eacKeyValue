## {eac}KeyValueCapture - Capture options and transients to {eac}KeyValue
[![EarthAsylum Consulting](https://img.shields.io/badge/EarthAsylum-Consulting-0?&labelColor=6e9882&color=707070)](https://earthasylum.com/)
[![WordPress](https://img.shields.io/badge/WordPress-Plugins-grey?logo=wordpress&labelColor=blue)](https://wordpress.org/plugins/search/EarthAsylum/)


<details><summary>Document Header</summary>

Plugin URI:             https://github.com/EarthAsylum/eacKeyValue  
Author:                 [EarthAsylum Consulting](https://www.earthasylum.com)  
Stable tag:             1.0.0+Beta-1  
Last Updated:           07-Jun-2025  
Requires at least:      5.8  
Tested up to:           6.8  
Requires PHP:           8.1  
Contributors:           [earthasylum](https://github.com/earthasylum),[kevinburkholder](https://profiles.wordpress.org/kevinburkholder)  
License:                GPLv3 or later  
License URI:            https://www.gnu.org/licenses/gpl.html  
GitHub URI:             https://github.com/EarthAsylum/eacKeyValue  

</details>

This plugin uses _{eac}KeyValue_ to capture individual WordPress option or transient API calls to direct them to the Key-Value API.

### Description

+   -- This is experimental and is not without issue or risk. --

#### Options

+   To capture an option and route it to eacKeyValue:  
`eacKeyValueCapture::option_capture('option_name');`  

+   Add 'true' to have the original value saved to cache and deleted from WP options:  
`eacKeyValueCapture::option_capture('option_name',true);`  

+   To release a captured option (restores to WP options):  
`eacKeyValueCapture::option_release('option_name');`  

#### Transients

+   To capture a transient and route it to eacKeyValue:  
`eacKeyValueCapture::transient_capture('transient_name');`  

+   Add 'true' to have the original value saved to cache and deleted from WP options:  
`eacKeyValueCapture::transient_capture('transient_name',true);`  

+   To release a captured transient:  
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

- - -

### Installation

+   Drop the `eacKeyValueCapture.php` file into your `wp-content/mu-plugins` folder or include it in your `functions.php` and add `capture_option()` or `capture_transient()` calls as needed.
