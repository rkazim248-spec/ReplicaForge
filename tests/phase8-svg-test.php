<?php
/**
 * Phase 8: SVG sanitization.
 *
 * Every hostile construct named in the brief is fed to the sanitizer and the
 * result is inspected, rather than trusting that a pattern list is sufficient.
 *
 * Run: php phase8-svg-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase8-svg-test.php <wp-root>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
require_once $root . '/wp-load.php';

use ReplicaForge\Svg_Sanitizer;

$assertions = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return void
 */
function check( $condition, $message ) {
	global $assertions;
	$assertions++;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
}

echo "--- 0. The DOM extension is required, and its absence is reported ---\n";

if ( ! class_exists( '\DOMDocument' ) ) {
	throw new RuntimeException( 'SKIP: the DOM extension is required to sanitize an SVG, and it is not loaded.' );
}
check( true, 'The DOM extension is loaded, so SVG sanitization can be exercised.' );

echo "--- 1. A clean icon passes through ---\n";

$clean = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">'
	. '<path d="M4 4h16v16H4z" fill="#333333"/></svg>'
);
check( true === $clean['accepted'], 'A clean icon is accepted.' );
check( true === $clean['safe'], 'It is reported as safe.' );
check( true === $clean['was_clean'], 'It is reported as arriving already clean, which is a different statement from having been cleaned.' );
check( is_string( $clean['sanitized'] ) && false !== strpos( $clean['sanitized'], '<path' ), 'The path survives.' );
check( false === stripos( (string) $clean['sanitized'], '<script' ), 'No script is present.' );

echo "--- 2. Script is removed ---\n";

$script = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("https://evil.test")</script><path d="M0 0h1v1z"/></svg>'
);
check( true === $script['accepted'], 'An icon with a script element is still accepted after cleaning.' );
check( false === stripos( (string) $script['sanitized'], 'script' ), 'The script element is gone.' );
check( false === stripos( (string) $script['sanitized'], 'evil.test' ), 'The script body is gone with it.' );
check( false === $script['was_clean'], 'The icon is reported as having needed cleaning.' );
check( ! empty( $script['removed'] ), 'The removal is reported rather than silent.' );
check( false !== stripos( implode( ' ', $script['removed'] ), 'script' ), 'The report names what was removed and why.' );

echo "--- 3. Event handlers are removed, in any spelling ---\n";

foreach ( array( 'onload', 'onclick', 'onerror', 'onmouseover', 'onbegin', 'onfocus' ) as $handler ) {
	$result = Svg_Sanitizer::sanitize(
		'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z" ' . $handler . '="alert(1)"/></svg>'
	);
	check( false === stripos( (string) $result['sanitized'], $handler ), 'The ' . $handler . ' handler is removed.' );
	check( false === stripos( (string) $result['sanitized'], 'alert' ), 'The ' . $handler . ' handler body is gone with it.' );
}

// A handler whose name the deny list does not spell out is still caught, because
// the check is on the prefix rather than on a fixed set of names.
$obscure = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z" onanythingatall="alert(1)"/></svg>'
);
check( false === stripos( (string) $obscure['sanitized'], 'onanythingatall' ), 'A handler name the deny list does not spell out is still removed, because the check is on the prefix.' );

echo "--- 4. External references are removed ---\n";

$external = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg">'
	. '<image href="https://evil.test/track.png" width="1" height="1"/>'
	. '<use href="https://evil.test/x.svg#icon"/>'
	. '<path d="M0 0h1v1z"/></svg>'
);
check( true === $external['accepted'], 'An icon with an external reference is accepted after cleaning.' );
check( false === stripos( (string) $external['sanitized'], 'evil.test' ), 'The external reference is gone.' );
check( false === stripos( (string) $external['sanitized'], 'track.png' ), 'The referenced file is gone with it.' );

$data_uri = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Lz48L3N2Zz4=" width="1" height="1"/></svg>'
);
check( false === stripos( (string) $data_uri['sanitized'], 'base64' ), 'A data URI carrying a document is removed.' );

echo "--- 5. Fragments are kept, because an icon needs them ---\n";

$fragment = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><defs><symbol id="i"><path d="M0 0h1v1z"/></symbol></defs><use href="#i"/></svg>'
);
check( true === $fragment['accepted'], 'An icon using a same-document symbol is accepted.' );
check( false !== strpos( (string) $fragment['sanitized'], 'href="#i"' ), 'A fragment reference survives, because it is how an icon reuses a symbol.' );

echo "--- 6. Foreign content is removed ---\n";

$foreign = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">'
	. '<img src="x" onerror="alert(1)"/></body></foreignObject><path d="M0 0h1v1z"/></svg>'
);
check( false === stripos( (string) $foreign['sanitized'], 'foreignObject' ), 'A foreignObject is removed.' );
check( false === stripos( (string) $foreign['sanitized'], 'onerror' ), 'The event handler inside it is gone with it.' );
check( false === stripos( (string) $foreign['sanitized'], '<img' ), 'The embedded HTML is gone with it.' );

echo "--- 7. Embedded style and animation are removed ---\n";

$styled = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><style>*{background:url(https://evil.test/x)}</style><path d="M0 0h1v1z" style="fill:url(https://evil.test/y)"/></svg>'
);
check( false === stripos( (string) $styled['sanitized'], 'evil.test' ), 'A stylesheet that fetches is removed.' );
check( false === stripos( (string) $styled['sanitized'], 'style=' ), 'An inline style attribute is removed, because it can carry a fetch.' );

$animated = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"><animate attributeName="href" values="javascript:alert(1)"/></path></svg>'
);
check( false === stripos( (string) $animated['sanitized'], '<animate' ), 'An animation element is removed.' );
check( false === stripos( (string) $animated['sanitized'], 'javascript' ), 'The value it would have set is gone with it.' );

echo "--- 8. Links are removed ---\n";

$link = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><a href="https://evil.test"><path d="M0 0h1v1z"/></a></svg>'
);
check( false === stripos( (string) $link['sanitized'], '<a' ), 'A link element is removed.' );
check( false !== strpos( (string) $link['sanitized'], '<path' ), 'The drawing it contained is lifted out rather than discarded, so the icon still looks right.' );

echo "--- 9. Documents that are refused outright ---\n";

$doctype = Svg_Sanitizer::sanitize(
	'<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"/></svg>'
);
check( false === $doctype['accepted'], 'A document type declaration is refused outright.' );
check( 'doctype_or_entity' === (string) $doctype['reason'], 'The refusal names the reason.' );
check( ! empty( $doctype['removed'] ), 'The refusal reports what was found.' );

$huge = Svg_Sanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( '<path d="M0 0h1v1z"/>', 40000 ) . '</svg>' );
check( false === $huge['accepted'], 'An oversized icon is refused rather than parsed.' );
check( 'too_large' === (string) $huge['reason'], 'The refusal names the size as the reason.' );

$not_svg = Svg_Sanitizer::sanitize( '<html><body>not an icon</body></html>' );
check( false === $not_svg['accepted'], 'A document that is not an SVG is refused.' );
check( 'not_svg' === (string) $not_svg['reason'], 'The refusal says it is not an SVG.' );

$empty = Svg_Sanitizer::sanitize( '' );
check( false === $empty['accepted'], 'An empty value is refused.' );
check( 'empty' === (string) $empty['reason'], 'The refusal says it was empty.' );

$malformed = Svg_Sanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z">' );
check( false === $malformed['accepted'], 'A malformed document is refused rather than repaired.' );
check( 'unparsable' === (string) $malformed['reason'], 'The refusal says it could not be parsed.' );

$not_array = Svg_Sanitizer::sanitize( array( 'svg' ) );
check( false === $not_array['accepted'], 'A non-string value is refused.' );

echo "--- 10. Deep nesting is bounded ---\n";

$deep = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( '<g>', 200 ) . '<path d="M0 0h1v1z"/>' . str_repeat( '</g>', 200 ) . '</svg>';
$deep_result = Svg_Sanitizer::sanitize( $deep );
check( is_string( $deep_result['sanitized'] ) || false === $deep_result['accepted'], 'A deeply nested icon is either cleaned or refused, never fatal.' );
if ( true === $deep_result['accepted'] ) {
	check( ! empty( $deep_result['removed'] ), 'Content past the depth limit is reported as removed.' );
}

echo "--- 11. Gradients survive, because they are drawing ---\n";

$gradient = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#000"/></linearGradient></defs>'
	. '<rect width="10" height="10" fill="url(#g)"/></svg>'
);
check( true === $gradient['accepted'], 'An icon using a gradient is accepted.' );
check( false !== strpos( (string) $gradient['sanitized'], 'linearGradient' ), 'The gradient definition survives.' );
check( false !== strpos( (string) $gradient['sanitized'], 'stop-color' ), 'Its stops survive.' );
check( false !== strpos( (string) $gradient['sanitized'], 'url(#g)' ), 'A gradient reference to a fragment survives, because it draws rather than fetches.' );

echo "--- 12. URL values are judged individually ---\n";

check( Svg_Sanitizer::safe_url( '#symbol' ), 'A fragment is safe.' );
check( Svg_Sanitizer::safe_url( 'none' ), 'A paint keyword is safe.' );
check( Svg_Sanitizer::safe_url( 'currentColor' ), 'currentColor is safe.' );
check( Svg_Sanitizer::safe_url( 'url(#gradient)' ), 'A fragment inside url() is safe.' );
check( Svg_Sanitizer::safe_url( '#333333' ), 'A hex color is safe.' );
check( Svg_Sanitizer::safe_url( 'middle' ), 'A keyword is safe.' );
check( Svg_Sanitizer::safe_url( 'rgb(0,0,0)' ), 'A color function is safe.' );

check( ! Svg_Sanitizer::safe_url( 'https://evil.test/x.svg' ), 'An absolute URL is not safe.' );
check( ! Svg_Sanitizer::safe_url( 'http://evil.test/x.svg' ), 'An insecure absolute URL is not safe.' );
check( ! Svg_Sanitizer::safe_url( '//evil.test/x.svg' ), 'A protocol-relative URL is not safe.' );
check( ! Svg_Sanitizer::safe_url( '/local/x.svg' ), 'A rooted relative path is not safe.' );
check( ! Svg_Sanitizer::safe_url( '../x.svg' ), 'A parent-relative path is not safe.' );
check( ! Svg_Sanitizer::safe_url( 'data:image/svg+xml;base64,AAA' ), 'A data URI is not safe.' );
check( ! Svg_Sanitizer::safe_url( 'javascript:alert(1)' ), 'A script scheme is not safe.' );
check( ! Svg_Sanitizer::safe_url( 'file:///etc/passwd' ), 'A file scheme is not safe.' );
check( ! Svg_Sanitizer::safe_url( 'url(https://evil.test/x.png)' ), 'A url() wrapping an absolute URL is not safe.' );

echo "--- 13. Reference classification ---\n";

$refs = array(
	'#icon'                 => array( 'fragment', true ),
	'https://example.com/a' => array( 'remote', true ),
	'/local/a.png'          => array( 'relative', true ),
	'./a.png'               => array( 'relative', true ),
	'data:image/png;base64' => array( 'data', false ),
	'javascript:alert(1)'   => array( 'script', false ),
	'file:///etc/passwd'    => array( 'filesystem', false ),
);

foreach ( $refs as $value => $expected ) {
	$inspect = Svg_Sanitizer::inspect_reference( $value );
	check(
		$expected[0] === (string) $inspect['kind'] && $expected[1] === (bool) $inspect['allowed'],
		'A reference to ' . ( 40 > strlen( $value ) ? $value : substr( $value, 0, 37 ) . '...' ) . ' is classified as ' . $expected[0] . ' and ' . ( $expected[1] ? 'allowed' : 'refused' ) . '.'
	);
	if ( false === $expected[1] ) {
		check( ! empty( $inspect['reason'] ), 'A refused reference says why.' );
	}
}

echo "--- 14. A malformed document is refused rather than repaired ---\n";

/* Repairing malformed markup by guessing produces a document that is not what was
   written, so a document that does not parse is refused. The earlier refusal is
   covered above; this is the case where a hostile construct makes the document
   malformed as well. */
$malformed_hostile = Svg_Sanitizer::sanitize(
	'<svg xmlns="http://www.w3.org/2000/svg"><image href="data:text/html,<script>alert(1)</script>"/></svg>'
);
check( false === $malformed_hostile['accepted'], 'A document that is malformed as well as hostile is refused.' );
check( 'unparsable' === (string) $malformed_hostile['reason'], 'The refusal says it could not be parsed rather than claiming it was cleaned.' );
check( null === $malformed_hostile['sanitized'], 'No output is produced from a document that could not be parsed.' );

echo "--- 15. The sanitized output is itself well formed ---\n";

$hostile = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
	. '<script>alert(2)</script>'
	. '<foreignObject><script>alert(3)</script></foreignObject>'
	. '<a href="https://evil.test"><rect width="4" height="4" fill="#f00"/></a>'
	. '<image href="data:text/html;base64,PHNjcmlwdD5hbGVydCg0KTwvc2NyaXB0Pg==" width="1" height="1"/>'
	. '<path d="M0 0h8v8H0z" fill="url(#g)" onclick="alert(5)"/>'
	. '<style>@import url(https://evil.test/x.css);</style>'
	. '</svg>';

$cleaned = Svg_Sanitizer::sanitize( $hostile );
$output  = (string) $cleaned['sanitized'];

check( true === $cleaned['accepted'], 'A hostile icon is cleaned rather than refused outright.' );

// The result must parse, or a browser would not render it at all.
$reparsed = Svg_Sanitizer::sanitize( $output );
check( true === $reparsed['accepted'], 'The cleaned output is itself a well-formed SVG.' );
check( true === $reparsed['was_clean'], 'The cleaned output is stable: sanitizing it again changes nothing.' );

foreach ( array( 'script', 'onload', 'onclick', 'foreignObject', 'evil.test', 'data:text', '@import', '<style', '<image', '<a ' ) as $needle ) {
	check( false === stripos( $output, $needle ), 'The cleaned output contains no ' . $needle . '.' );
}
check( false !== strpos( $output, '<rect' ), 'The drawing the hostile parts wrapped is still present.' );
check( false !== strpos( $output, '<path' ), 'The path is still present.' );

echo "--- 16. Nothing executable survives, checked mechanically ---\n";

// A final sweep that does not depend on the specific payloads above: whatever
// came out must contain no script element, no event handler attribute, and no
// external reference.
$forbidden = array( '<script', '<foreignobject', '<iframe', '<embed', '<object', '<animate', '<set', '<handler' );
foreach ( $forbidden as $needle ) {
	check( false === stripos( strtolower( $output ), $needle ), 'No ' . $needle . ' element survives.' );
}

preg_match_all( '/\son[a-z]+\s*=/i', $output, $handlers_found );
check( empty( $handlers_found[0] ), 'No event handler attribute survives: ' . wp_json_encode( $handlers_found[0] ) );

preg_match_all( '/(?:href|src)\s*=\s*"\s*(?:https?:)?\/\//i', $output, $external_found );
check( empty( $external_found[0] ), 'No external reference survives: ' . wp_json_encode( $external_found[0] ) );

echo "\nSVG sanitizer test passed. Assertions: {$assertions}\n";
