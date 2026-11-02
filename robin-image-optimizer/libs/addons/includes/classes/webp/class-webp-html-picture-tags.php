<?php

namespace WRIO\WEBP\HTML;

/**
 * Wrap <img> tags in <picture> tags with one <source> for each converted format.
 *
 * Based on PictureTags of rosell-dk/dom-util-for-webp, which used code from the ShortPixel and Responsify WP
 * plugins. Each <source> has the type of its format, so a browser without AVIF skips the AVIF one. A width without
 * a file of the format falls back to the next format, then to the original: no width is dropped from the srcset,
 * where the browser would stretch a smaller image.
 */
class Picture_Tags {

	/**
	 * Replace the <img> tags of a page.
	 *
	 * @param string $html Page HTML.
	 *
	 * @return string
	 */
	public static function replace( $html ): string {
		$html           = (string) $html;
		$template_depth = 0;

		// A <picture> of the theme stays as it is: a picture inside a picture is invalid. A ">" or a "<" in a quoted
		// value does not end an <img> tag. Only a quote after "=" opens a value, and a value never runs into a tag
		// ("<" before a letter, "/" or "!"): a stray apostrophe (alt=it's) cannot swallow the rest of the page. The
		// possessive runs keep the PCRE stack small on a long tag, such as a base64 src. Every other tag is read whole
		// and skipped, so a "<template>" or a "<script>" in one of its attribute values is not taken for a tag.
		$replaced = preg_replace_callback(
			'/<(script|style|textarea|noscript)\b[^>]*>(?:.*?<\/\1\s*>|.*\z)(*SKIP)(*F)|<!--.*?(?:-->|\z)(*SKIP)(*F)|<(?!(?:img|picture|template|script|style|textarea|noscript)\b)[a-z][^\s>\/]*+(?:=\s*"(?:[^"<]++|<(?![a-z\/!]))*+"|=\s*\'(?:[^\'<]++|<(?![a-z\/!]))*+\'|[^>=]++|=)*+>(*SKIP)(*F)|<\/?template\b[^>]*>|<picture\b.*?<\/picture\s*>|<img\b(?:=\s*"(?:[^"<]++|<(?![a-z\/!]))*+"|=\s*\'(?:[^\'<]++|<(?![a-z\/!]))*+\'|[^>=]++|=)*+>/is',
			function ( $found ) use ( &$template_depth ) {
				if ( preg_match( '/^<\/?template\b/i', $found[0] ) ) {
					$template_depth = max( 0, $template_depth + ( '/' === $found[0][1] ? -1 : 1 ) );
					return $found[0];
				}

				return $template_depth > 0 ? $found[0] : self::picture( $found );
			},
			$html
		);

		// A PCRE limit: the page as it is, rather than no page.
		return null === $replaced ? $html : $replaced;
	}

	/**
	 * The candidates of a srcset: URL and descriptor. A URL can have commas; a comma after it ends the candidate.
	 *
	 * @param string $srcset The srcset value.
	 *
	 * @return array<int, array{string, string}>
	 */
	private static function candidates( string $srcset ): array {
		preg_match_all( '/[\s,]*([^\s,](?:\S*[^\s,])?)([^,]*)/', $srcset, $matches, PREG_SET_ORDER );

		return array_map(
			function ( $candidate ) {
				$descriptor = trim( $candidate[2] );

				return [ $candidate[1], '' === $descriptor ? '' : ' ' . $descriptor ];
			},
			$matches
		);
	}

	/**
	 * The <picture> tag of one <img> tag, or the tag itself when the image has no converted file.
	 *
	 * @param string[] $found The <img> tag, or a <picture> tag.
	 *
	 * @return string
	 */
	private static function picture( array $found ): string {
		$img = $found[0];

		if ( 0 === stripos( $img, '<picture' ) || false !== strpos( $img, 'webpexpress-processed' ) ) {
			return $img;
		}

		$attributes = self::attributes( $img );
		$srcset     = self::lazy_get( $attributes, 'srcset' );
		$src        = self::lazy_get( $attributes, 'src' );
		// A lazy URL takes priority over an active srcset.
		$is_srcset = false !== $srcset['value'] && ! ( 'srcset' === $srcset['attrName'] && false !== $src['value'] && 'src' !== $src['attrName'] );
		$using     = $is_srcset ? $srcset : $src;

		if ( false === $using['value'] ) {
			return $img;
		}

		$candidates = $is_srcset ? self::candidates( (string) $using['value'] ) : [ [ (string) $using['value'], '' ] ];

		$lazy_sizes = self::lazy_get( $attributes, 'sizes' );
		$name       = (string) preg_replace( '/src$/', 'srcset', (string) $using['attrName'] );
		$formats    = \WRIO_Formats::get_enabled_formats();
		$sources    = '';
		// A source does not read the sizes of the image: each width-based set needs its own.
		$sizes = $is_srcset && false !== $lazy_sizes['value'] ? [ $lazy_sizes['attrName'] => (string) $lazy_sizes['value'] ] : [];

		$sets = [ $name => $candidates ];
		if ( 'srcset' !== $name ) {
			if ( ! empty( $attributes['srcset'] ) ) {
				$sets['srcset'] = self::candidates( $attributes['srcset'] );
				$sizes         += isset( $attributes['sizes'] ) ? [ 'sizes' => $attributes['sizes'] ] : [];
			} elseif ( ! empty( $attributes['src'] ) ) {
				$sets['srcset'] = [ [ $attributes['src'], '' ] ];
			}
		}

		$sizes = implode( '', array_map( fn( $attribute, $value ) => ' ' . $attribute . '="' . esc_attr( $value ) . '"', array_keys( $sizes ), $sizes ) );

		foreach ( $formats as $index => $format ) {
			// The lazy loader swaps the first set in: a format without a file in it would only show the preview.
			if ( null === self::source_set( $candidates, array_slice( $formats, $index ), $format ) ) {
				continue;
			}

			$source = '';
			foreach ( $sets as $attribute => $entries ) {
				$value = self::source_set( $entries, array_slice( $formats, $index ), $format );
				if ( null !== $value ) {
					$source .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
				}
			}

			$sources .= '<source' . $source . $sizes . ' type="image/' . $format . '">';
		}

		if ( '' === $sources ) {
			return $img;
		}

		$attributes += self::dimensions( $attributes, (string) $src['value'] );

		// A second pass, by this filter or another, leaves the image alone.
		$attributes['class'] = ( isset( $attributes['class'] ) ? $attributes['class'] . ' ' : '' ) . 'webpexpress-processed';

		return '<picture>' . $sources . '<img' . self::attribute_string( $attributes ) . '></picture>';
	}

	/**
	 * Convert a source set, keeping each width and its next available format.
	 *
	 * @param array<int, array{string, string}> $candidates URLs and descriptors.
	 * @param string[]                          $formats    Formats in fallback order.
	 * @param string                            $format     Type of this source.
	 * @return string|null The set, or null when this format has no file.
	 */
	private static function source_set( array $candidates, array $formats, string $format ): ?string {
		$found   = false;
		$entries = [];

		foreach ( $candidates as $candidate ) {
			$url = null;
			foreach ( $formats as $fallback ) {
				$url = Delivery::get_format_url( $candidate[0], $fallback );
				if ( null !== $url ) {
					$found = $found || $fallback === $format;
					break;
				}
			}
			$entries[] = ( $url ?? $candidate[0] ) . $candidate[1];
		}

		return $found ? implode( ', ', $entries ) : null;
	}

	/**
	 * The width and height of an image that has neither, from the metadata of its attachment. An image that is not
	 * an attachment gets none: a guessed size would distort it.
	 *
	 * @param array<string, string> $attributes Attributes of the image.
	 * @param string                $url        URL of the image.
	 *
	 * @return array<string, string>
	 */
	private static function dimensions( array $attributes, string $url ): array {
		if ( isset( $attributes['width'] ) || isset( $attributes['height'] ) || '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return [];
		}

		// The lookup replaces the scheme in the whole URL: an "https" folder must not become "http".
		$baseurl = wp_get_upload_dir()['baseurl'];
		// A version query (photo.jpg?v=2) is not part of the file name that the metadata knows.
		$url = set_url_scheme( (string) strtok( $url, '?#' ), (string) wp_parse_url( $baseurl, PHP_URL_SCHEME ) );
		// A path from the site root gets the host of the uploads.
		if ( 0 === strpos( $url, '/' ) ) {
			$url = (string) preg_replace( '#^(\w+://[^/]+).*#', '$1', $baseurl ) . $url;
		}

		// Only an upload: the lookup also matches a path that is relative to nothing, such as "2025/photo.jpg".
		if ( 0 !== strpos( $url, $baseurl . '/' ) ) {
			return [];
		}

		// The class that the editor adds saves the URL lookup, a database query for each image.
		$id = preg_match( '/\bwp-image-(\d+)\b/', $attributes['class'] ?? '', $match ) ? (int) $match[1] : attachment_url_to_postid( $url );
		// A URL of a smaller size: the lookup knows only the URL of the full size.
		$id = $id ? $id : attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );

		$meta       = $id ? wp_get_attachment_metadata( $id ) : false;
		$dimensions = is_array( $meta ) ? wp_image_src_get_dimensions( $url, $meta, $id ) : false;

		return $dimensions ? [
			'width'  => (string) $dimensions[0],
			'height' => (string) $dimensions[1],
		] : [];
	}

	/**
	 * The attributes of an <img> tag, with their entities decoded.
	 *
	 * @param string $html The tag.
	 *
	 * @return array<string, string>
	 */
	private static function attributes( string $html ): array {
		$tag        = new \WP_HTML_Tag_Processor( $html );
		$attributes = [];

		if ( $tag->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
			foreach ( $tag->get_attribute_names_with_prefix( '' ) ?? [] as $name ) {
				$value = $tag->get_attribute( $name );

				// A boolean attribute has no value.
				$attributes[ $name ] = is_string( $value ) ? $value : '';
			}
		}

		return $attributes;
	}

	/**
	 * An attribute, preferring its lazy load names ("data-lazy-src", "data-src") over the plain one.
	 *
	 * @param array<string, string> $attributes Attributes of the element.
	 * @param string                $name       src, srcset or sizes.
	 *
	 * @return array{value: string, attrName: string}|array{value: false, attrName: false}
	 */
	private static function lazy_get( array $attributes, string $name ): array {
		foreach ( [ 'data-lazy-' . $name, 'data-' . $name, $name ] as $attribute ) {
			if ( isset( $attributes[ $attribute ] ) && strlen( $attributes[ $attribute ] ) ) {
				return [
					'value'    => trim( $attributes[ $attribute ] ),
					'attrName' => $attribute,
				];
			}
		}

		return [
			'value'    => false,
			'attrName' => false,
		];
	}

	/**
	 * The attributes as HTML, escaped: the decoded entities must not close the attribute.
	 *
	 * @param array<string, string> $attributes Attributes.
	 *
	 * @return string
	 */
	private static function attribute_string( array $attributes ): string {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			// A lazy load placeholder is a data: URL.
			$html .= ' ' . $name . '="' . ( 'src' === $name ? esc_url( $value, array_merge( wp_allowed_protocols(), [ 'data' ] ) ) : esc_attr( $value ) ) . '"';
		}

		return $html;
	}
}
