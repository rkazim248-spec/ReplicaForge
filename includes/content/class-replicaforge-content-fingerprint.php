<?php
/**
 * Phase 14: content fingerprints and entity-matching keys.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the stable keys §20's bulk matching compares on.
 *
 * ### What a fingerprint is for, and what it is not
 *
 * A fingerprint answers "is this the same piece of content?" across two analyses, and
 * across two *sites* in the §41 "reconstruct the design, use my products" workflow. It
 * must therefore be:
 *
 * - **stable** — the same content analysed tomorrow produces the same fingerprint, or
 *   every mapping is invalidated by a re-scan;
 * - **insensitive to presentation** — a change in a product's title does not change its
 *   *image* fingerprint, and a change in whitespace does not change a text fingerprint;
 * - **sensitive to identity** — two different products never share a fingerprint, and
 *   critically, a fingerprint is never a *match*. §20 is explicit that image similarity
 *   alone may not drive an automatic match, and that rule lives in
 *   {@see Content_Limits::IMAGE_ONLY_CEILING} rather than here.
 *
 * ### Normalisation, and the failures it introduces
 *
 * Every normalisation step here trades a false negative for a false positive, and each
 * trade is deliberate:
 *
 * - Case and whitespace are folded, because `"Blue Shirt"` and `"blue  shirt "` are the
 *   same product and a difference would be a false negative.
 * - A leading article is dropped, because *The* Beatles and *Beatles* are the same
 *   entity in a title comparison — but **only** in the *title* normaliser, never in the
 *   display one. The user's product is still called "The Beatles".
 * - Diacritics are folded for *comparison* and preserved for *display*, because two
 *   stores may spell "Café" and "Cafe" for the same product, but ReplicaForge must never
 *   rewrite a name.
 * - Punctuation is folded, because a SKU written `AB-1234` and `AB 1234` is one SKU.
 *
 * ### What it never does
 *
 * It never produces a value. A fingerprint is a hash and a key; it cannot be inverted
 * into the text it was made from, and {@see self::hash()} uses a plain `sha256` rather
 * than a keyed MAC because a *key* would make the fingerprint non-comparable across
 * installations, which is exactly what the §41 workflow needs.
 */
final class Content_Fingerprint {

	/**
	 * Maximum text length folded into a text fingerprint.
	 *
	 * 240 characters is longer than any product title, post title, or service name a
	 * real site uses, so a long paragraph does not get a "more unique" fingerprint than
	 * a title merely by being longer.
	 */
	const MAX_FOLD_LENGTH = 240;

	/**
	 * Return a content fingerprint for a role and value.
	 *
	 * The role is part of the fingerprint. The same text in two roles is two pieces of
	 * content — a button that says "Contact" and a navigation item that says "Contact"
	 * map to different destination fields — and a fingerprint that ignored the role
	 * would let a mapping cross between them.
	 *
	 * @param string $role  Semantic role.
	 * @param string $value Value.
	 * @return string
	 */
	public static function of( $role, $value ) {
		$value = (string) $value;

		// A missing value gets a missing fingerprint. It must not collide with a
		// present-but-empty value either, and it must never be a hash of `''`, because
		// every absent field on the page would then share one identity.
		//
		// Every marker collapses to the *same* missing fingerprint for a given role. That
		// is deliberate and is not the same thing as losing the distinction: a
		// fingerprint is an identity key, and "no identity" is no identity whichever
		// marker records it. The difference between an empty price and an undetected
		// price field is preserved where it actually matters — in
		// `Source_Content_Model`'s `value`, which stores the marker verbatim and reports
		// absences as a separate count.
		if ( Content_Limits::is_missing( $value ) ) {
			return 'fp_missing_' . substr( md5( (string) $role ), 0, 12 );
		}

		return self::hash( (string) $role . '|' . self::fold( $value ) );
	}

	/**
	 * Return a normalised title, for §20 title matching.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function normalise_title( $title ) {
		$title = self::fold( $title );

		// A leading article. A real convention on store listings and a real source of
		// false negatives: "The Widget" and "Widget" are the same product to a human and
		// different strings to a matcher.
		$stripped = preg_replace( '/^(the|a|an)\s+/', '', $title );
		$title    = ( null === $stripped ) ? $title : $stripped;

		return trim( $title );
	}

	/**
	 * Return a normalised SKU.
	 *
	 * @param string $sku SKU.
	 * @return string
	 */
	public static function normalise_sku( $sku ) {
		$sku = strtolower( trim( (string) $sku ) );
		// Separators and zero padding are the two things that differ between systems
		// describing the same SKU. `AB-0012` and `ab 12` are one SKU.
		$sku = (string) preg_replace( '/[\s\-_.]+/', '', $sku );
		$sku = (string) preg_replace( '/^0+(?=\d)/', '', $sku );

		return $sku;
	}

	/**
	 * Return a normalised slug.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function normalise_slug( $slug ) {
		return (string) preg_replace( '/[^a-z0-9]+/', '', self::fold( $slug ) );
	}

	/**
	 * Return a normalised image reference for comparison.
	 *
	 * A filename, not a download. §20 forbids using image similarity alone for a final
	 * automatic match, and this is the key that comparison would use — so it is
	 * deliberately the *cheapest possible* image key: the basename, which is what two
	 * stores importing the same manufacturer's photo will actually share.
	 *
	 * The full URL is never hashed, because two stores host the same photo at completely
	 * different addresses and a URL key would never match.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public static function image_key( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || Content_Limits::is_missing( $url ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = strtolower( basename( (string) $path ) );

		// Strip the format and the size suffix WordPress-style filenames carry, so
		// `blue-shirt-300x400.jpg` and `blue-shirt.jpg` are one key. A photo resampled
		// by a CDN is still the same photo.
		$name = (string) preg_replace( '/\.(jpe?g|png|gif|webp|avif)$/i', '', $name );
		$name = (string) preg_replace( '/[-_]\d{2,4}x\d{2,4}$/', '', $name );

		// A query string is dropped: a cache-busted URL of the same image is the same
		// image, and `?v=3` would otherwise make every match fail.
		return self::fold( $name );
	}

	/**
	 * Return a normalised category path.
	 *
	 * @param string $path Category path.
	 * @return string
	 */
	public static function normalise_category( $path ) {
		$parts = preg_split( '/[\/>]+/', (string) $path );
		$clean = array();
		foreach ( (array) $parts as $part ) {
			$part = self::fold( (string) $part );
			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}
		sort( $clean );
		return implode( '|', $clean );
	}

	/**
	 * Fold text for comparison.
	 *
	 * Case, whitespace, and diacritics. Deliberately does **not** strip punctuation —
	 * that would fold "C++" and "C" together, which is a false positive in a product
	 * catalogue where those are different products.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function fold( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		// Invalid UTF-8 is *not* hypothetical: a source page's title or class name can
		// contain a truncated multi-byte sequence, and every `u`-flagged PCRE pattern
		// then returns `null` rather than a string. Without this guard, one malformed
		// byte sequence makes `fold()` return `null`, every fingerprint of that content
		// collapses to one value, and two genuinely different products become
		// indistinguishable — which is the exact failure §13 forbids. The check is
		// therefore a correctness requirement, not tidiness.
		if ( ! self::valid_utf8( $value ) ) {
			$value = self::scrub_utf8( $value );
		}

		// `strtolower` rather than `mb_strtolower`: this PHP build has no mbstring and
		// WordPress does not polyfill `mb_strtolower`. ASCII case is folded correctly;
		// non-ASCII case folding would need the extension, and a Turkish dotless `i` in a
		// product name is not a matching problem.
		$value = strtolower( $value );

		// Diacritics folded *for comparison only*. The stored value is untouched.
		$diacritics = preg_replace( '/\p{Mn}+/u', '', $value );
		$value      = ( null === $diacritics ) ? $value : $diacritics;

		$spaced = preg_replace( '/\s+/u', ' ', $value );
		$value  = ( null === $spaced ) ? $value : $spaced;

		if ( strlen( $value ) > self::MAX_FOLD_LENGTH ) {
			$value = substr( $value, 0, self::MAX_FOLD_LENGTH );
		}

		return trim( $value );
	}

	/**
	 * Return whether a string is valid UTF-8.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function valid_utf8( $value ) {
		return (bool) preg_match( '//u', (string) $value );
	}

	/**
	 * Replace invalid UTF-8 sequences with a replacement character.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function scrub_utf8( $value ) {
		// Kept as a function rather than an inline call because `mb_convert_encoding`
		// does not exist without mbstring, and `htmlspecialchars_decode` would change
		// entities. The character-class form below is the one available path.
		$clean = preg_replace( '/[\x80-\xFF]/', '?', $value );
		if ( is_string( $clean ) ) {
			return $clean;
		}

		// `preg_replace` with the `u` flag also returns null on invalid input, so the
		// fallback is a byte-level filter with no `u` flag at all.
		$out = '';
		foreach ( str_split( (string) $value ) as $byte ) {
			$out .= ( 1 === preg_match( '/^[\x20-\x7E]$/', $byte ) ) ? $byte : ' ';
		}
		return preg_replace( '/\s+/', ' ', $out );
	}

	/**
	 * Hash a value into a stable fingerprint.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function hash( $value ) {
		return 'fp_' . substr( hash( 'sha256', (string) $value ), 0, 24 );
	}

	/**
	 * Return whether two fingerprints refer to the same content.
	 *
	 * Two empty-or-missing fingerprints are *not* equal. Two products that both lack a
	 * SKU are two different products, and treating "we do not know either" as "they are
	 * the same" is precisely how a mapper invents a match out of an absence — which is
	 * the failure §13 exists to prevent.
	 *
	 * @param string $left  Left fingerprint.
	 * @param string $right Right fingerprint.
	 * @return bool
	 */
	public static function same( $left, $right ) {
		$left  = (string) $left;
		$right = (string) $right;

		if ( '' === $left || '' === $right ) {
			return false;
		}
		if ( 0 === strpos( $left, 'fp_missing_' ) || 0 === strpos( $right, 'fp_missing_' ) ) {
			// Only equal when the *roles* are equal too, so two missing fields of the
			// same role across two analyses are the same absence.
			return $left === $right;
		}

		return $left === $right;
	}
}
