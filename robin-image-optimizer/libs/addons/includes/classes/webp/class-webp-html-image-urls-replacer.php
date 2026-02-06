<?php

namespace WRIO\WEBP\HTML;

use DOMUtilForWebP\ImageUrlReplacer;

class Urls_Replacer extends ImageUrlReplacer {

	/**
	 * Replace URL with converted format (WebP or AVIF).
	 *
	 * @param string $url Original image URL.
	 *
	 * @return string|null Converted URL or null if not applicable.
	 */
	public function replaceUrl( $url ) {
		// Check if URL ends with supported source formats
		if ( ! preg_match( '#\.(png|jpe?g)($|\?)#i', $url ) ) {
			return null;
		}

		return Delivery::get_converted_url( $url, null );
	}

	public function attributeFilter( $attrName ) {
		// Allow "src", "srcset" and data-attributes that smells like they are used for images
		// The following rule matches all attributes used for lazy loading images that we know of
		return preg_match( '#^(src|srcset|(data-[^=]*(lazy|small|slide|img|large|src|thumb|source|set|bg-url)[^=]*))$#i', $attrName );

		// If you want to limit it further, only allowing attributes known to be used for lazy load,
		// use the following regex instead:
		// return preg_match('#^(src|srcset|data-(src|srcset|cvpsrc|cvpset|thumb|bg-url|large_image|lazyload|source-url|srcsmall|srclarge|srcfull|slide-img|lazy-original))$#i', $attrName);
	}
}
