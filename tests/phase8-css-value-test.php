<?php
/**
 * Phase 8 foundation: the CSS value parser.
 *
 * Run: php css-value-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php css-value-test.php <wp-root>\n" );
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

use ReplicaForge\Css_Value_Parser as P;

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

echo "--- 1. Splitting ignores separators inside functions ---\n";

check( array( '1px', '2px' ) === P::split( '1px 2px', ' ' ), 'Two space-separated lengths split in two.' );
check( array( '1px', '2px' ) === P::split( '  1px   2px  ', ' ' ), 'Extra whitespace is collapsed, not made into empty items.' );
check( array( 'a,b' ) === P::split( 'a,b', ' ' ), 'A comma inside a space-separated list is kept.' );
check( array( 'red', 'blue' ) === P::split( 'red, blue', ',' ), 'A comma-separated color list splits in two.' );

$rgba = P::split( 'rgba(0, 0, 0, 0.5)', ',' );
check( 1 === count( $rgba ) && 'rgba(0, 0, 0, 0.5)' === $rgba[0], 'A comma inside rgba() does not split the list.' );
check( count( P::split( 'linear-gradient(90deg, #fff, #000)', ',' ) ) >= 1, 'A gradient does not split on its inner commas.' );

$quoted = P::split( '"a b" c', ' ' );
check( array( '"a b"', 'c' ) === $quoted, 'A quoted string is not split on its space.' );

// A slash is a real separator in a radius shorthand and in a grid line range.
check( array( '10px', '20px' ) === P::split( '10px / 20px', '/' ), 'A slash separates an elliptical radius.' );
check( array( '1', '3' ) === P::split( '1 / 3', '/' ), 'A slash separates a grid line range.' );
check( array( 'a', 'b' ) === P::split( 'a|b', '|' ), 'Any single character is accepted as a separator.' );
check( array( '1', '/', '3' ) === P::split( '1 / 3', ' ' ), 'A whitespace split of a range gives three tokens, which is why a caller needing the range matches it directly.' );
check( 1 === preg_match( '/^\s*([+-]?\d+)\s*\/\s*([+-]?\d+)\s*$/', '1 / 3', $m ), 'A range can be matched directly despite the surrounding spaces.' );
check( 2 === ( (int) $m[2] - (int) $m[1] ), 'And the direct match yields the span a grid placement means.' );
check( array( '(b/c) d' ) === P::split( '(b/c) d', '/' ), 'A slash inside a function does not split, and a space is not a separator when the separator is a slash.' );
check( array( 'a', '(b/c)' ) === P::split( 'a/(b/c)', '/' ), 'A top-level slash does split, because it is not inside a function.' );

echo "--- 2. Function arguments ---\n";

$args = P::function_args( 'linear-gradient(90deg, #fff, #000)', 'linear-gradient', ',' );
check( 3 === count( $args ), 'A gradient splits into its angle and two stops.' );
check( '90deg' === $args[0], 'The gradient angle is the first argument, not a stop.' );
check( '#fff' === $args[1], 'The first stop is the second argument.' );
check( array() === P::function_args( 'solid red', 'linear-gradient' ), 'A non-function value has no arguments.' );
check( array() === P::function_args( 'linear-gradient(', 'linear-gradient' ), 'An unterminated function has no arguments.' );
check( array() === P::function_args( 'linear-gradient(90deg, #fff)', 'radial-gradient' ), 'A different function name is not matched.' );

// A space-separated function is a different grammar, and the caller says so.
$space = P::function_args( 'translate(10px, 20px)', 'translate', ' ' );
check( 2 === count( $space ), 'A space-separated function honours the requested separator.' );

// A function mixed with bare keywords is separable.
$mixed = P::first_function( 'linear-gradient(90deg, #fff, #000) no-repeat center / cover' );
check( null !== $mixed, 'A function mixed with keywords is recognized.' );
check( 'linear-gradient' === $mixed['function'], 'The function name is read.' );
check( 3 === count( $mixed['args'] ), 'Its arguments are split from the keywords.' );
check( false !== strpos( implode( ' ', $mixed['rest'] ), 'no-repeat' ), 'The trailing keywords are separated from the function.' );
check( null === P::first_function( 'solid red' ), 'A value with no function returns null.' );

echo "--- 3. Lengths ---\n";

check( 16.0 === P::length( '16px' )['pixels'], 'A pixel length converts.' );
check( 16.0 === P::length( '16' )['pixels'], 'A bare number is treated as pixels.' );
check( 0.0 === P::length( '0' )['pixels'], 'Zero needs no unit.' );
check( 16.0 === round( (float) P::length( '12pt' )['pixels'], 4 ), '12pt is 16px, because a point is a physical unit.' );
check( 16.0 === round( (float) P::length( '1pc' )['pixels'], 4 ), '1pc is 16px.' );
check(
	P::length( '12pt' )['pixels'] === P::length( '12pt', 24.0 )['pixels'],
	'A physical unit does not change with the root font size.'
);
check(
	P::length( '1em' )['pixels'] !== P::length( '1em', 24.0 )['pixels'],
	'An em does change with the font size, which is the difference between the two.'
);
check( 16.0 === P::length( '1rem' )['pixels'], 'Rem converts against the root font size.' );
check( 24.0 === P::length( '1.5em' )['pixels'], 'Em converts against the font size.' );
check( 144.0 === P::length( '10vw' )['pixels'], 'Viewport width converts.' );
check( 96.0 === P::length( '1in' )['pixels'], 'Inches convert.' );

$relative = P::length( '50%' );
check( null === $relative['pixels'], 'A percentage reports no pixel value rather than a fabricated one.' );
check( true === $relative['relative'], 'A percentage is marked relative.' );

$calc = P::length( 'calc(100% - 40px)' );
check( null !== $calc, 'A calc() is recognized.' );
check( null === $calc['pixels'], 'A calc() is not evaluated, because doing it wrong would fabricate a geometry.' );
check( 'calc' === $calc['unit'], 'A calc() is labelled so a caller can record it as a limitation.' );

check( null === P::length( 'inherit' ), 'A keyword is not a length.' );
check( null === P::length( '' ), 'An empty value is not a length.' );
check( null === P::length( 'nonsense' ), 'Nonsense is not a length.' );

echo "--- 4. Colors ---\n";

check( '#ffffff' === P::color( '#fff' )['hex'], 'A three-digit hex expands.' );
check( '#ffffff' === P::color( '#FFFFFF' )['hex'], 'Hex case is normalized.' );
check( '#1a2b3c' === P::color( '#1a2b3c' )['hex'], 'A six-digit hex is preserved.' );
check( 1.0 === P::color( '#ffffff' )['alpha'], 'An opaque hex reports full alpha.' );
check( 0.502 === round( (float) P::color( '#ffffff80' )['alpha'], 3 ), 'An eight-digit hex reports its alpha.' );

check( array( 255, 0, 0 ) === P::color( 'red' )['rgb'], 'A named color resolves.' );
check( '#000000' === P::color( 'black' )['hex'], 'A named color has a hex form.' );
check( 0.0 === P::color( 'transparent' )['alpha'], 'Transparent reports zero alpha.' );

check( array( 255, 0, 0 ) === P::color( 'rgb(255, 0, 0)' )['rgb'], 'An rgb() color parses.' );
check( array( 255, 0, 0 ) === P::color( 'rgb(255 0 0)' )['rgb'], 'A space-separated rgb() parses.' );
check( 0.5 === round( (float) P::color( 'rgba(255, 0, 0, 0.5)' )['alpha'], 2 ), 'An rgba() alpha is read.' );
check( array( 255, 0, 0 ) === P::color( 'rgb(100%, 0%, 0%)' )['rgb'], 'Percentage channels are a share of 255, not a 0-255 number.' );
check( array( 128, 64, 0 ) === P::color( 'rgb(50%, 25%, 0%)' )['rgb'], 'A partial percentage channel scales against 255.' );
check( array( 0, 0, 0 ) === P::color( 'rgb(none, none, none)' )['rgb'], 'A none channel reads as zero.' );
check( null === P::color( 'rgb(calc(1 + 1), 0, 0)' ), 'An unconvertible channel refuses the whole color rather than guessing it.' );

check( array( 255, 0, 0 ) === P::color( 'hsl(0, 100%, 50%)' )['rgb'], 'An hsl() color converts to rgb.' );
check( array( 0, 128, 0 ) === P::color( 'green' )['rgb'], 'A named color resolves to its rgb.' );

check( null === P::color( 'notacolor' ), 'An unknown name is not guessed.' );
check( null === P::color( 'color(display-p3 1 0 0)' ), 'A modern color space is not silently reduced.' );
check( true === P::color( 'currentColor' )['current'], 'currentColor is identified rather than resolved.' );

echo "--- 5. Color comparison keys collapse spellings ---\n";

$white = P::color_key( '#fff' );
check( $white === P::color_key( '#ffffff' ), 'Three-digit and six-digit hex collapse to one key.' );
check( $white === P::color_key( 'rgb(255,255,255)' ), 'A hex and an rgb() of the same color collapse.' );
check( $white === P::color_key( 'white' ), 'A hex and a named color collapse.' );
check( '' === P::color_key( 'nonsense' ), 'An unparsed color has no key, so it cannot become a token.' );
check( P::color_key( 'rgba(0,0,0,0.5)' ) !== P::color_key( 'rgba(0,0,0,1)' ), 'A translucent color is not the same token as an opaque one.' );

echo "--- 6. Angles and percentages ---\n";

check( 90.0 === P::parse_angle( '90deg' ), 'Degrees parse.' );
check( 180.0 === P::parse_angle( '0.5turn' ), 'Half a turn is 180 degrees.' );
check( 360.0 === P::parse_angle( '1turn' ), 'A full turn is 360 degrees.' );
check( 90.0 === P::parse_angle( '100grad' ), 'Gradians convert.' );
check( 180.0 === P::parse_angle( 'to bottom' ), 'A direction keyword maps to degrees.' );
check( 270.0 === P::parse_angle( 'to left' ), 'A leftward gradient maps to 270 degrees.' );
check( 180.0 === round( (float) P::parse_angle( '3.14159rad' ), 0 ), 'Radians convert.' );
check( null === P::parse_angle( 'sideways' ), 'A nonsense angle does not parse.' );
check( null === P::parse_angle( 'sideways' ), 'A nonsense angle does not parse.' );

check( 0.5 === P::percentage( '50%' ), 'A percentage converts to 0–1.' );
check( null === P::percentage( '50' ), 'A bare number is not a percentage.' );

echo "--- 7. Bounding ---\n";

$huge = str_repeat( 'a', \ReplicaForge\Css_Value_Parser::MAX_VALUE_LENGTH + 500 );
check( strlen( P::bound( $huge ) ) <= \ReplicaForge\Css_Value_Parser::MAX_VALUE_LENGTH, 'An oversized value is truncated before parsing.' );
check( null === P::length( $huge ), 'A truncated oversized value does not produce a length.' );
check( null === P::color( $huge ), 'A truncated oversized value does not produce a color.' );

$many = implode( ' ', array_fill( 0, 200, '1px' ) );
check( count( P::split( $many, ' ' ) ) <= \ReplicaForge\Css_Value_Parser::MAX_ITEMS, 'A long list is bounded.' );

check( '' === P::bound( array( 'x' ) ), 'A non-scalar value is rejected rather than stringified.' );
check( null === P::length( null ), 'A null value is not a length.' );
check( null === P::color( null ), 'A null value is not a color.' );

echo "--- 8. Length comparison keys ---\n";

check( P::length_key( '16px' ) === P::length_key( '16' ), 'px and a bare number collapse.' );
check( P::length_key( '1rem' ) === P::length_key( '16px' ), '1rem and 16px collapse against a 16px root.' );
check( P::length_key( '1in' ) === P::length_key( '96px' ), '1in and 96px collapse.' );
check( '' === P::length_key( '50%' ), 'A percentage has no pixel key.' );
check( '' === P::length_key( 'auto' ), 'A keyword has no key.' );
check( P::length_key( '10px' ) !== P::length_key( '20px' ), 'Two different lengths have different keys.' );

echo "\nCSS value parser test passed. Assertions: {$assertions}\n";
