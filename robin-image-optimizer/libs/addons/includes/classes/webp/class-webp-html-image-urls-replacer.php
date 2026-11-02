<?php
/**
 * Replaces image URLs in HTML (both src and srcset syntax) with their best converted file: AVIF, then WebP.
 *
 * Based on the ImageUrlReplacer class of rosell-dk/dom-util-for-webp 0.3.1 (MIT License,
 * https://github.com/rosell-dk/dom-util-for-webp).
 *
 * @package robin-image-optimizer
 */

namespace WRIO\WEBP\HTML;

/**
 * Replaces image URLs in HTML (both src and srcset syntax).
 *
 * Reads the markup with WP_HTML_Tag_Processor, which only rewrites the attributes and the <style> text it changes.
 * Behaviour can be customized by overriding the public methods (replace_url, attribute_filter, etc).
 *
 * Default behaviour:
 * - Limits to these tags: <img>, <source>, <input> and <iframe> and the others of $search_in_tags
 * - Limits to these attributes: "src", "srcset" and the data-attributes that look like they hold images (attribute_filter)
 * - Only replaces URLs that ends with "png", "jpg" or "jpeg" (replace_url)
 * - Also replaces background URLs in style attributes and <style> elements
 *
 * @phpstan-consistent-constructor
 */
class Urls_Replacer {

	// Define tags to be searched.
	// The div and li are on the list because these are often used with lazy loading.
	// Should we add <meta> ?
	// Probably not for open graph images or twitter
	// so not these:
	// - <meta property="og:image" content="[url]">
	// - <meta property="og:image:secure_url" content="[url]">
	// - <meta name="twitter:image" content="[url]">
	// Meta can also be used in schema.org micro-formatting, ie:
	// - <meta itemprop="image" content="[url]">
	//
	// How about preloaded images? - yes, suppose we should replace those
	// - <link rel="prefetch" href="[url]">
	// - <link rel="preload" as="image" href="[url]">.
	/**
	 * Tags that are searched for image URLs.
	 *
	 * @var string[]
	 */
	public static $search_in_tags = [ 'img', 'source', 'input', 'iframe', 'div', 'li', 'link', 'a', 'section' ];

	/**
	 * Replace URL with its best converted file: AVIF when it exists, otherwise WebP, otherwise none. A format that is
	 * off, or whose file was skipped or deleted (not smaller), is absent, so the next one is used.
	 *
	 * @param string $url Original image URL.
	 *
	 * @return string|null Converted URL or null if not applicable.
	 */
	public function replace_url( $url ) {
		// Check if URL ends with supported source formats.
		if ( ! preg_match( '~\.(png|jpe?g)($|[?#])~i', $url ) ) {
			return null;
		}

		// AVIF first, then WebP.
		foreach ( \WRIO_Formats::get_enabled_formats() as $format ) {
			$converted = Delivery::get_format_url( $url, $format );
			if ( null !== $converted ) {
				return $converted;
			}
		}

		return null;
	}

	/**
	 * Replace URL with converted format, or return a fallback value.
	 *
	 * @param string $url                    Original image URL.
	 * @param mixed  $return_value_if_denied Value to return if the URL is not replaced.
	 *
	 * @return mixed
	 */
	public function replace_url_or( $url, $return_value_if_denied ) {
		$url = $this->replace_url( $url );

		return ( isset( $url ) ? $url : $return_value_if_denied );
	}

	/**
	 * Handle the value of an attribute that holds one URL.
	 *
	 * @param string $attr_value Attribute value.
	 *
	 * @return string
	 */
	public function handle_src( $attr_value ) {
		return $this->replace_url_or( $attr_value, $attr_value );
	}

	/**
	 * Handle the value of an attribute that has the srcset syntax.
	 *
	 * @param string $attr_value Attribute value, ie "1.jpg 1000w, 2.jpg".
	 *
	 * @return string
	 */
	public function handle_src_set( $attr_value ) {
		preg_match_all( '/[\s,]*([^\s,](?:\S*[^\s,])?)([^,]*)/', $attr_value, $candidates, PREG_SET_ORDER );
		$srcset_arr = [];

		foreach ( $candidates as $candidate ) {
			$descriptor   = trim( $candidate[2] );
			$srcset_arr[] = $this->replace_url_or( $candidate[1], $candidate[1] ) . ( '' === $descriptor ? '' : ' ' . $descriptor );
		}
		return implode( ', ', $srcset_arr );
	}

	/**
	 * Test if attribute value looks like it has srcset syntax.
	 *
	 * "image.jpg 100w" does for example. And "image.jpg 1x". Also "image1.jpg, image2.jpg 1x"
	 * Mixing x and w is invalid (according to
	 * https://stackoverflow.com/questions/26928828/html5-srcset-mixing-x-and-w-syntax)
	 * But we accept it anyway.
	 * It is not the job of this function to see if the first part is an image URL.
	 * That will be done in handle_src_set.
	 *
	 * @param string $value Attribute value.
	 *
	 * @return bool
	 */
	public function looks_like_src_set( $value ) {
		if ( preg_match( '#\s\d*(w|x)#', $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Handle the value of an attribute that may hold a URL or a srcset.
	 *
	 * @param string $value Attribute value.
	 *
	 * @return string
	 */
	public function handle_attribute( $value ) {
		if ( $this->looks_like_src_set( $value ) ) {
			return $this->handle_src_set( $value );
		}
		return $this->handle_src( $value );
	}

	/**
	 * Tell if the attribute can hold an image URL.
	 *
	 * @param string $attr_name Attribute name.
	 *
	 * @return 0|1|false
	 */
	public function attribute_filter( $attr_name ) {
		// Allow "src", "srcset" and data-attributes that smells like they are used for images
		// The following rule matches all attributes used for lazy loading images that we know of.
		return preg_match( '#^(src|srcset|(data-[^=]*(lazy|small|slide|img|large|src|thumb|source|set|bg-url)[^=]*))$#i', $attr_name );

		// If you want to limit it further, only allowing attributes known to be used for lazy load,
		// use the following regex instead:
		// return preg_match('#^(src|srcset|data-(src|srcset|cvpsrc|cvpset|thumb|bg-url|large_image|lazyload|source-url|srcsmall|srclarge|srcfull|slide-img|lazy-original))$#i', $attr_name);.
	}

	/**
	 * Replace the URL of a "url()" match of a CSS declaration.
	 *
	 * @param array<int, string> $matches Matches of the "url()" regular expression.
	 *
	 * @return string
	 */
	public function process_css_regex_callback( $matches ) {
		list($all, $pre, $quote, $url, $post) = $matches;
		return $pre . $this->replace_url_or( $url, $url ) . $post;
	}

	/**
	 * Replace the image URLs in the background declarations of a CSS string.
	 *
	 * @param string $css CSS.
	 *
	 * @return string
	 */
	public function process_css( $css ) {
		$declarations = explode( ';', $css );
		foreach ( $declarations as $i => &$declaration ) {
			if ( preg_match( '#(background(-image)?)\\s*:#', $declaration ) ) {
				// https://regexr.com/46qdg.
				$parts = explode( ',', $declaration );
				foreach ( $parts as &$part ) {
					$regex = '#(url\\s*\\(([\\"\\\']?))([^\\\'\\";\\)]*)(\\2\\s*\\))#';
					$part  = preg_replace_callback( $regex, [ $this, 'process_css_regex_callback' ], $part );
				}
				$declarations[ $i ] = implode( ',', $parts );
			}
		}
		return implode( ';', $declarations );
	}

	/**
	 * Replace the image URLs in the markup.
	 *
	 * @param string $html Markup.
	 *
	 * @return string
	 */
	public function replace_html( $html ) {
		$tags        = new \WP_HTML_Tag_Processor( (string) $html );
		$search_tags = array_map( 'strtoupper', self::$search_in_tags );

		while ( $tags->next_tag() ) {
			$tag_name = $tags->get_tag();

			// Attributes (src, srcset, data-src, etc).
			if ( in_array( $tag_name, $search_tags, true ) ) {
				foreach ( $tags->get_attribute_names_with_prefix( '' ) ?? [] as $attr_name ) {
					$value = $tags->get_attribute( $attr_name );

					if ( is_string( $value ) && $this->attribute_filter( $attr_name ) ) {
						$this->update_attribute( $tags, $attr_name, $value, $this->handle_attribute( $value ) );
					}
				}
			}

			$style = $tags->get_attribute( 'style' );

			if ( is_string( $style ) ) {
				$this->update_attribute( $tags, 'style', $style, $this->process_css( $style ) );
			}

			if ( 'STYLE' === $tag_name ) {
				$css     = $tags->get_modifiable_text();
				$new_css = $this->process_css( $css );

				if ( $new_css !== $css ) {
					$this->set_style_text( $tags, $new_css );
				}
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * Write a changed attribute value.
	 *
	 * @param \WP_HTML_Tag_Processor $tags      Processor on the tag.
	 * @param string                 $name      Attribute name.
	 * @param string                 $old_value Value in the page.
	 * @param string                 $new_value Value with the replaced URLs.
	 */
	private function update_attribute( \WP_HTML_Tag_Processor $tags, string $name, string $old_value, string $new_value ): void {
		// The processor refuses to write a name with syntax characters, which the page can hold.
		if ( $new_value !== $old_value && strlen( $name ) === strcspn( $name, '"\'<>&/= ' ) ) {
			$tags->set_attribute( $name, $new_value );
		}
	}

	/**
	 * Write the text of the <style> element the processor is on.
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processor on the <style> tag.
	 * @param string                 $css  New CSS.
	 */
	protected function set_style_text( \WP_HTML_Tag_Processor $tags, string $css ): void {
		// The stubs are for WordPress 6.7+, the plugin supports WordPress 6.6.
		if ( method_exists( $tags, 'set_modifiable_text' ) ) { // @phpstan-ignore function.alreadyNarrowedType
			$tags->set_modifiable_text( $css );
			return;
		}

		$this->queue_style_text( $tags, $css );
	}

	/**
	 * Write the text of a <style> element on WordPress 6.6, which has no set_modifiable_text().
	 *
	 * Queues the same replacement as set_modifiable_text() of WordPress 6.7, with the offsets that the
	 * 6.6 processor keeps private. Remove when the minimum WordPress version is 6.7.
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processor on the <style> tag.
	 * @param string                 $css  New CSS.
	 */
	protected function queue_style_text( \WP_HTML_Tag_Processor $tags, string $css ): void {
		// Like core, keep the CSS from closing the element.
		$css = (string) preg_replace( '~</(style)~i', '\\\\3c\\\\2f$1', $css );

		$queue = \Closure::bind(
			static function ( \WP_HTML_Tag_Processor $tags, string $css ): void {
				// The WordPress stubs leave out the private offsets.
				// @phpstan-ignore property.notFound, property.notFound
				$tags->lexical_updates['modifiable text'] = new \WP_HTML_Text_Replacement( $tags->text_starts_at, $tags->text_length, $css );
			},
			null,
			\WP_HTML_Tag_Processor::class
		);
		$queue( $tags, $css );
	}

	/**
	 * Main replacer function.
	 *
	 * @param string $html Markup.
	 *
	 * @return string
	 */
	public static function replace( $html ) {
		$iur = new static();

		return $iur->replace_html( $html );
	}
}
