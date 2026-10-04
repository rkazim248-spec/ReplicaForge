<?php
/**
 * Phase 8: visual effect extraction.
 *
 * Covers the shorthands and longhands a real page mixes, the gradients a card and a
 * hero use, and the layered shadows that cannot be reproduced exactly.
 *
 * Run: php phase8-visual-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase8-visual-test.php <wp-root>\n" );
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

use ReplicaForge\Visual_Effects;

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

/**
 * Shorthand for a declarations array.
 *
 * @param array<string, mixed> $pairs Property and value pairs.
 * @return array<string, mixed>
 */
function d( array $pairs ) {
	return $pairs;
}

$fx = new Visual_Effects( 16.0, 1440.0 );

echo "--- 1. Linear gradients ---\n";

$linear = $fx->gradient( 'linear-gradient(135deg, #6c63ff 0%, #f5f5f5 100%)' );
check( null !== $linear, 'A linear gradient is recognized.' );
check( 'linear' === $linear['type'], 'It is typed as linear.' );
check( 135.0 === (float) $linear['angle'], 'Its angle is read.' );
check( 2 === count( $linear['stops'] ), 'It has two stops.' );
check( '#6c63ff' === (string) $linear['stops'][0]['color'], 'The first stop color is read.' );
check( 0.0 === (float) $linear['stops'][0]['position'], 'The first stop position is read.' );
check( '#f5f5f5' === (string) $linear['stops'][1]['color'], 'The second stop color is read.' );
check( 1.0 === (float) $linear['stops'][1]['position'], 'The second stop position is read.' );
check( true === (bool) $linear['supported'], 'A two-stop linear gradient is supported.' );
check( null === $linear['limitation'], 'A supported gradient records no limitation.' );

$to_right = $fx->gradient( 'linear-gradient(to right, #000, #fff)' );
check( 90.0 === (float) $to_right['angle'], 'A direction keyword resolves to degrees.' );

echo "--- 2. Implicit stop positions ---\n";

$implicit = $fx->gradient( 'linear-gradient(90deg, red, green, blue)' );
check( 3 === count( $implicit['stops'] ), 'Three stops are read.' );
check( 0.0 === (float) $implicit['stops'][0]['position'], 'The first implicit position is zero.' );
check( 0.5 === (float) $implicit['stops'][1]['position'], 'The middle stop falls at the midpoint.' );
check( 1.0 === (float) $implicit['stops'][2]['position'], 'The last implicit position is one.' );
check( 'interpolated' === (string) $implicit['stops'][1]['position_source'], 'An interpolated position is labelled as derived rather than declared.' );

$declared = $fx->gradient( 'linear-gradient(90deg, red 10%, blue 80%)' );
check( 0.1 === (float) $declared['stops'][0]['position'], 'A declared stop position is read as written.' );
check( 'declaration' === (string) $declared['stops'][0]['position_source'], 'A declared position is not overwritten by interpolation.' );
check( 0.8 === (float) $declared['stops'][1]['position'], 'The second declared position is read.' );

echo "--- 3. Radial and conic gradients ---\n";

$radial = $fx->gradient( 'radial-gradient(circle at 50% 30%, #fff 0%, #000 100%)' );
check( 'radial' === $radial['type'], 'A radial gradient is typed as radial.' );
check( 'circle' === (string) $radial['shape'], 'Its shape is read.' );
check( '50% 30%' === (string) $radial['position'], 'Its position is read.' );
check( 2 === count( $radial['stops'] ), 'Its stops are read.' );
check( true === (bool) $radial['supported'], 'A radial gradient is supported.' );

$conic = $fx->gradient( 'conic-gradient(from 45deg, #f00, #00f)' );
check( 'conic' === $conic['type'], 'A conic gradient is typed as conic.' );
check( false === (bool) $conic['supported'], 'A conic gradient is reported as unsupported.' );
check( false !== strpos( (string) $conic['limitation'], 'conic' ), 'The limitation explains what a conic gradient is.' );

echo "--- 4. Transparent stops are colors ---\n";

$transparent = $fx->gradient( 'linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.05) 100%)' );
check( 2 === count( $transparent['stops'] ), 'A transparent stop counts as a stop, not as an absence.' );
check( 0.0 === (float) $transparent['stops'][0]['alpha'], 'A fully transparent stop reports zero alpha.' );
check( true === (bool) $transparent['supported'], 'A transparent-to-opaque gradient is supported.' );

$single = $fx->gradient( 'linear-gradient(#fff, #000000)' );
check( 2 === count( $single['stops'] ), 'Hex colors without an explicit separator are still two stops.' );
check( '#ffffff' === (string) $single['stops'][0]['color'], 'A three-digit hex stop is expanded.' );

echo "--- 5. Repeating and vendor gradients ---\n";

$repeating = $fx->gradient( 'repeating-linear-gradient(45deg, #fff 0 10px, #000 10px 20px)' );
check( null !== $repeating, 'A repeating gradient is recognized rather than ignored.' );
check( true === (bool) $repeating['repeating'], 'It is marked as repeating.' );
check( false === (bool) $repeating['supported'], 'A repeating gradient is reported as unsupported.' );
check( false !== strpos( (string) $repeating['limitation'], 'tile' ), 'The limitation explains the tiling difference.' );

$vendor = $fx->gradient( '-webkit-linear-gradient(top, #fff, #000)' );
check( null !== $vendor, 'A vendor-prefixed gradient is recognized.' );
check( false !== strpos( (string) $vendor['vendor'], 'webkit' ), 'The vendor prefix is recorded.' );

check( null === $fx->gradient( '#fff' ), 'A color is not a gradient.' );
check( null === $fx->gradient( 'url(/a.png)' ), 'An image is not a gradient.' );
check( null === $fx->gradient( '' ), 'An empty value is not a gradient.' );
check( null === $fx->gradient( 'nonsense' ), 'Nonsense is not a gradient.' );

echo "--- 6. Box shadows ---\n";

$shadow = $fx->shadow( '0 4px 12px rgba(0, 0, 0, 0.15)' );
check( true === (bool) $shadow['present'], 'A box shadow is recognized.' );
check( 1 === $shadow['count'], 'A single shadow is counted as one.' );
check( 0.0 === (float) $shadow['shadows'][0]['offset_x'], 'The x offset is read.' );
check( 4.0 === (float) $shadow['shadows'][0]['offset_y'], 'The y offset is read.' );
check( 12.0 === (float) $shadow['shadows'][0]['blur'], 'The blur radius is read.' );
check( null === $shadow['shadows'][0]['spread'], 'A shadow with three lengths has no spread.' );
check( '#000000' === (string) $shadow['shadows'][0]['color'], 'The shadow color is read.' );
check( 0.15 === (float) $shadow['shadows'][0]['alpha'], 'The shadow alpha is read.' );
check( true === (bool) $shadow['shadows'][0]['complete'], 'A fully resolved shadow is complete.' );

$spread = $fx->shadow( '0 0 0 3px #ff0000' );
check( 3.0 === (float) $spread['shadows'][0]['spread'], 'A four-length shadow has its spread read.' );

$inset = $fx->shadow( 'inset 0 2px 4px #000' );
check( true === (bool) $inset['shadows'][0]['inset'], 'The inset keyword is recognized.' );
check( 2.0 === (float) $inset['shadows'][0]['offset_y'], 'A shadow after inset still reads its lengths.' );

$layered = $fx->shadow( '0 1px 2px #000, 0 4px 8px #000' );
check( 2 === $layered['count'], 'Two shadows are counted as two.' );
check( true === (bool) $layered['elementor']['layered'], 'The layer count is reported.' );
check( false !== strpos( (string) $layered['elementor']['limitation'], 'layered' ), 'The limitation explains that only one shadow survives.' );

$calc_shadow = $fx->shadow( '0 4px calc(2px + 2px) #000' );
check( true === (bool) $calc_shadow['present'], 'A shadow with a calculation is still recognized as present.' );
check( false === (bool) $calc_shadow['shadows'][0]['complete'], 'It is not reported as complete, because the calculation was not evaluated.' );
check( false !== strpos( (string) $calc_shadow['shadows'][0]['limitation'], 'cannot be resolved' ), 'The unevaluated length is explained.' );

check( false === (bool) $fx->shadow( 'none' )['present'], 'none is not a shadow.' );
check( 0 === $fx->shadow( 'none' )['count'], 'none is counted as zero.' );
check( false === (bool) $fx->shadow( '' )['present'], 'An empty value is not a shadow.' );

echo "--- 7. Text shadows ---\n";

$text_shadow = $fx->shadow( '0 1px 2px rgba(0,0,0,0.4)', 'text' );
check( true === (bool) $text_shadow['present'], 'A text shadow is recognized.' );
check( 2.0 === (float) $text_shadow['shadows'][0]['blur'], 'Its blur is read.' );
check( null === $text_shadow['shadows'][0]['spread'], 'A text shadow has no spread even with three lengths.' );

echo "--- 8. Borders ---\n";

$border = $fx->border( d( array(
	'border'         => '1px solid #e5e7eb',
	'border-radius'  => '8px',
) ) );
check( true === (bool) $border['present'], 'A shorthand border is recognized as present.' );
check( true === (bool) $border['uniform'], 'A shorthand sets all four sides uniformly.' );
check( 1.0 === (float) $border['sides']['top']['width'], 'The border width is read from the shorthand.' );
check( 'solid' === (string) $border['sides']['top']['style'], 'The border style is read from the shorthand.' );
check( '#e5e7eb' === (string) $border['sides']['top']['color'], 'The border color is read from the shorthand.' );
check( 8.0 === (float) $border['radius']['value'], 'A uniform border radius is read.' );
check( true === (bool) $border['radius']['uniform'], 'A single radius value is reported as uniform.' );
check( false === (bool) $border['radius']['pill'], 'A small radius is not a pill.' );

$mixed_shorthand = $fx->border( d( array(
	'border'         => '1px solid #000000',
	'border-top'     => '4px double #ff0000',
) ) );
check( 4.0 === (float) $mixed_shorthand['sides']['top']['width'], 'A per-side shorthand wins over the bare one.' );
check( 'double' === (string) $mixed_shorthand['sides']['top']['style'], 'The per-side style wins.' );
check( 1.0 === (float) $mixed_shorthand['sides']['right']['width'], 'Sides without their own shorthand fall back to the bare one.' );

$longhand = $fx->border( d( array(
	'border-top-width'    => '2px',
	'border-top-style'    => 'solid',
	'border-top-color'    => '#111111',
	'border-right-width'  => '1px',
	'border-right-style'  => 'dashed',
	'border-right-color'  => '#222222',
) ) );
check( false === (bool) $longhand['uniform'], 'Different longhands on different sides are not uniform.' );
check( 2.0 === (float) $longhand['sides']['top']['width'], 'The top width is read from its longhand.' );
check( 1.0 === (float) $longhand['sides']['right']['width'], 'The right width is read from its longhand.' );
check( 'dashed' === (string) $longhand['sides']['right']['style'], 'The right style is read.' );
check( false !== strpos( (string) $longhand['elementor']['limitation'], 'different border' ), 'The per-side limitation is recorded.' );

$none = $fx->border( d( array( 'border' => 'none' ) ) );
check( false === (bool) $none['present'], 'A border of none draws nothing and is not present.' );
check( false === (bool) $none['sides']['top']['draws'], 'A none border is marked as not drawing.' );

$implicit = $fx->border( d( array( 'border-style' => 'solid', 'border-color' => '#000' ) ) );
check( null !== $implicit['sides']['top']['draws'], 'A style without a width still draws.' );
check( 'medium_default' === (string) $implicit['sides']['top']['width_source'], 'An absent width is recorded as the CSS default rather than a declaration.' );
check( 3.0 === (float) $implicit['elementor']['width'], 'An undeclared border width is the CSS medium default of three pixels, not one.' );
check( false !== strpos( (string) $implicit['elementor']['limitation'], 'three pixels' ), 'The assumed width is named so a reader knows it was assumed.' );

$bare_longhand = $fx->border( d( array( 'border-style' => 'solid', 'border-color' => '#ff0000' ) ) );
check( true === (bool) $bare_longhand['present'], 'A bare border-style and border-color still draw a border.' );
check( '#ff0000' === (string) $bare_longhand['sides']['top']['color'], 'The bare border-color is read.' );
check( 3.0 === (float) $bare_longhand['elementor']['width'], 'The bare form also uses the medium default width.' );

$per_side_beats_bare = $fx->border( d( array(
	'border-style' => 'solid',
	'border-top-style' => 'dotted',
) ) );
check( 'dotted' === (string) $per_side_beats_bare['sides']['top']['style'], 'A per-side longhand wins over the bare one.' );
check( 'solid' === (string) $per_side_beats_bare['sides']['right']['style'], 'The other sides keep the bare value.' );

echo "--- 9. Border radius shapes ---\n";

$pill = $fx->border( d( array( 'border-radius' => '9999px' ) ) );
check( true === (bool) $pill['radius']['pill'], 'A very large radius is recognized as a pill.' );
check( 9999.0 === (float) $pill['radius']['value'], 'The pill radius value is still reported.' );
check( true === (bool) $pill['elementor']['pill'], 'The pill shape is passed through to the Elementor mapping.' );

$circle = $fx->border( d( array( 'border-radius' => '50%' ) ) );
check( 4 === count( $circle['radius']['corners'] ), 'A percentage radius is expanded to four corners.' );
check( null === $circle['radius']['value'], 'A percentage radius has no pixel value, and none is invented.' );

$ellipse = $fx->border( d( array( 'border-radius' => '10px / 20px' ) ) );
check( 10.0 === (float) $ellipse['radius']['corners']['top-left']['horizontal'], 'An elliptical radius reads its horizontal part.' );
check( 20.0 === (float) $ellipse['radius']['corners']['top-left']['vertical'], 'An elliptical radius reads its vertical part.' );
check( true === (bool) $ellipse['radius']['corners']['top-left']['elliptical'], 'An elliptical radius is marked as one.' );

$three = $fx->border( d( array( 'border-radius' => '4px 8px 12px' ) ) );
check( 4.0 === (float) $three['radius']['corners']['top-left']['horizontal'], 'A three-value radius assigns the first to the top left.' );
check( 8.0 === (float) $three['radius']['corners']['top-right']['horizontal'], 'The second value is the top right.' );
check( 8.0 === (float) $three['radius']['corners']['bottom-left']['horizontal'], 'In the three-value form the second value is shared by the top right and the bottom left.' );
check( 12.0 === (float) $three['radius']['corners']['bottom-right']['horizontal'], 'The third value is the bottom right.' );
check( false === (bool) $three['radius']['uniform'], 'A three-value radius is not uniform.' );

$per_corner = $fx->border( d( array(
	'border-top-left-radius'     => '16px',
	'border-bottom-right-radius' => '4px',
) ) );
check( false === (bool) $per_corner['radius']['uniform'], 'Two differing corners are not uniform.' );
check( null === $per_corner['radius']['value'], 'A partial radius does not report a single value.' );

echo "--- 10. Backgrounds ---\n";

$hero = $fx->background( d( array(
	'background-image'  => 'linear-gradient(180deg, rgba(0,0,0,0.6), rgba(0,0,0,0.2))',
	'background-color'  => '#111827',
	'background-size'   => 'cover',
	'background-position' => 'center',
) ), array( 'role' => 'hero', 'has_text' => true ) );
check( true === (bool) $hero['present'], 'A background is recognized.' );
check( 2 === count( $hero['layers'] ), 'A gradient over a color is two layers: a gradient is not also an image layer.' );
check( 'gradient' === (string) $hero['layers'][0]['kind'], 'The gradient is the topmost layer.' );
check( 'color' === (string) $hero['layers'][1]['kind'], 'The color is the bottom layer.' );
check( 'hero' === (string) $hero['class'], 'A background on a hero is classified as a hero background.' );
check( 0.2 === (float) $hero['overlay']['alpha'], 'The weakest gradient stop is the one that most reduces contrast with the text.' );
check( 'gradient' === (string) $hero['overlay']['from'], 'The overlay strength is read from the topmost translucent layer, not from the color beneath it.' );
check( true === (bool) $hero['overlay']['derived'], 'The overlay alpha is marked as derived rather than declared.' );
check( 'low_contrast_risk' === (string) $hero['overlay']['risk'], 'A translucent gradient over an opaque color is still flagged, because the color underneath is not what the text is read through.' );

// An opaque background is not a contrast risk, and a translucent one with no text
// is not either, because there is nothing to read.
$opaque = $fx->background( d( array( 'background-color' => '#ffffff' ) ), array( 'has_text' => true ) );
check( 1.0 === (float) $opaque['overlay']['alpha'], 'An opaque color reports full opacity.' );
check( null === $opaque['overlay']['risk'], 'An opaque background behind text is not a contrast risk.' );

$translucent_no_text = $fx->background( d( array( 'background-color' => 'rgba(0,0,0,0.4)' ) ), array( 'has_text' => false ) );
check( null === $translucent_no_text['overlay']['risk'], 'A translucent background with no text is not a contrast risk.' );
check( 'low_contrast_risk' === (string) $hero['overlay']['risk'], 'A translucent background behind text is flagged as a contrast risk.' );

$card = $fx->background( d( array( 'background-image' => 'url(/img/texture.png)' ) ), array( 'role' => 'card', 'has_text' => false, 'area' => 4000 ) );
check( 'card' === (string) $card['class'], 'A background on a card is classified as a card background.' );
check( '/img/texture.png' === (string) $card['image'], 'A background image URL is read.' );

$decorative = $fx->background( d( array( 'background-image' => 'url(/img/dot.png)', 'background-repeat' => 'repeat' ) ), array( 'area' => 400 ) );
check( 'decorative' === (string) $decorative['class'], 'A small background with no text is classified as decorative, so it is not imported as a content image.' );

$section = $fx->background( d( array( 'background-image' => 'url(/img/wide.jpg)' ) ), array( 'area' => 900000 ) );
check( 'section' === (string) $section['class'], 'A large background is a section background rather than a decoration.' );

$fixed = $fx->background( d( array( 'background-attachment' => 'fixed' ) ) );
check( false !== strpos( (string) $fixed['elementor']['limitation'], 'fixed' ), 'A fixed background records that it cannot scroll the same way.' );

// A page that sets both an image and a gradient produces three layers, in the
	// order a browser paints them.
$both = $fx->background( d( array(
	'background-image' => 'url(/a.png), linear-gradient(90deg, #000, #fff)',
) ) );
check( 2 === count( $both['layers'] ), 'A gradient and an image in one declaration are two layers.' );
check( 'image' === (string) $both['layers'][0]['kind'], 'The image is the topmost layer.' );
check( 'gradient' === (string) $both['layers'][1]['kind'], 'The gradient sits below the image.' );

$none_bg = $fx->background( d( array() ) );
check( false === (bool) $none_bg['present'], 'An element with no background has none.' );
check( 'none' === (string) $none_bg['class'], 'It is classified as none.' );

echo "--- 11. Background shorthand ---\n";

$shorthand = $fx->background( d( array(
	'background' => 'url("/img/hero.jpg") no-repeat center / cover',
) ) );
check( '/img/hero.jpg' === (string) $shorthand['image'], 'An image URL in a shorthand is read.' );
check( 'center' === (string) $shorthand['position'], 'A position in a shorthand is read.' );
check( 'cover' === (string) $shorthand['size'], 'A size after a slash is read as the size, not the position.' );
check( 'no-repeat' === (string) $shorthand['repeat'], 'A repeat in a shorthand is read.' );
check( '/img/hero.jpg' === (string) $shorthand['image'], 'The observed settings are exposed alongside the layers, not only inside the Elementor mapping.' );

$with_color = $fx->background( d( array( 'background' => 'url(/a.png) #123456' ) ) );
check( '#123456' === (string) $with_color['color'], 'A color mixed into a shorthand is read.' );
check( '/a.png' === (string) $with_color['image'], 'The image in a mixed shorthand is read.' );

$repeat_x = $fx->background( d( array( 'background-repeat' => 'repeat-x' ) ) );
check( 'repeat-x' === (string) $repeat_x['repeat'], 'A longhand repeat is read.' );
check( false !== strpos( (string) $repeat_x['elementor']['limitation'], 'one axis' ), 'A single-axis repeat records that Elementor cannot express it.' );

$longhand_size = $fx->background( d( array( 'background-image' => 'url(/a.png)', 'background-size' => 'contain' ) ) );
check( 'contain' === (string) $longhand_size['size'], 'A longhand background-size is read, which is the form a framework usually uses.' );
check( '/a.png' === (string) $longhand_size['image'], 'The longhand image is read alongside a longhand size.' );

$data_uri = $fx->background( d( array( 'background-image' => 'url(data:image/gif;base64,R0lGOD)' ) ) );
check( null === $data_uri['image'], 'A data URI is not treated as an external asset.' );

echo "--- 12. Longhand background layers ---\n";

$layers = $fx->background_layers( d( array(
	'background-image' => 'url(/a.png), url(/b.png)',
) ) );
check( 2 === count( $layers ), 'Two comma-separated background images are two layers.' );
check( '/a.png' === (string) $layers[0]['url'], 'The first layer URL is read.' );
check( '/b.png' === (string) $layers[1]['url'], 'The second layer URL is read.' );

$gradient_layers = $fx->background_layers( d( array( 'background-image' => 'linear-gradient(90deg, #fff, #000)' ) ) );
check( 1 === count( $gradient_layers ), 'A gradient is one layer.' );
check( 'gradient' === (string) $gradient_layers[0]['kind'], 'It is typed as a gradient layer.' );

check( array() === $fx->background_layers( d( array() ) ), 'An element with no background image has no layers.' );

echo "\nVisual effects test passed. Assertions: {$assertions}\n";
