<?php
/**
 * Theme colours.
 *
 * The critical CSS is inlined in pwa-app.php AFTER the stylesheet link, so its
 * `body` rule beats `body { color: var(--text-primary) }` in forecast-app.css
 * on equal specificity. It therefore has to handle all three theme states, not
 * just the system preference: choosing dark on a light system once left dark
 * text on a dark background for every element that inherits its colour.
 *
 * These assertions check the inline values still match the stylesheet's tokens.
 *
 * @package CloudCoverForecast
 */

$root     = dirname( __DIR__ );
$template = file_get_contents( $root . '/templates/pwa-app.php' );
$stylesheet = file_get_contents( $root . '/assets/css/forecast-app.css' );

$passed = 0;
$failed = 0;
$assert = function ( $name, $ok ) use ( &$passed, &$failed ) {
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . "\n";
	if ( $ok ) {
		$passed++;
	} else {
		$failed++;
	}
};

/** Pull a custom property out of a named block in the stylesheet. */
$token = function ( $selector, $property ) use ( $stylesheet ) {
	if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{(.*?)\}/s', $stylesheet, $block ) ) {
		return null;
	}
	if ( ! preg_match( '/' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+);/', $block[1], $value ) ) {
		return null;
	}
	return strtolower( trim( $value[1] ) );
};

/** Pull a declaration out of a rule in the inlined critical CSS. */
$critical = function ( $selector, $property ) use ( $template ) {
	if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $template, $block ) ) {
		return null;
	}
	if ( ! preg_match( '/(?<![-\w])' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+);/', $block[1], $value ) ) {
		return null;
	}
	return strtolower( trim( $value[1] ) );
};

echo "\nExplicit theme choices are handled at all:\n";
$assert( 'critical CSS has a .dark-mode body rule', null !== $critical( '.dark-mode body', 'color' ) );
$assert( 'critical CSS has a .light-mode body rule', null !== $critical( '.light-mode body', 'color' ) );
$assert( 'and still follows the system for "auto"',
	false !== strpos( $template, '@media (prefers-color-scheme: dark)' ) );

echo "\nInline values match the stylesheet's tokens:\n";
$pairs = array(
	array( 'dark text', '.dark-mode body', 'color', '.dark-mode', '--text-primary' ),
	array( 'dark background', '.dark-mode body', 'background', '.dark-mode', '--bg-primary' ),
	array( 'light text', '.light-mode body', 'color', '.light-mode', '--text-primary' ),
	array( 'light background', '.light-mode body', 'background', '.light-mode', '--bg-primary' ),
);
foreach ( $pairs as $pair ) {
	list( $label, $rule, $property, $selector, $name ) = $pair;
	$inline = $critical( $rule, $property );
	$want   = $token( $selector, $name );
	$assert(
		sprintf( '%s: %s matches %s (%s)', $label, $inline ?? 'missing', $name, $want ?? 'missing' ),
		null !== $inline && $inline === $want
	);
}

echo "\nThe system-preference block matches its tokens too:\n";
$media = null;
if ( preg_match( '/@media \(prefers-color-scheme: dark\) \{(.*?)\n\t\t\}/s', $template, $m ) ) {
	$media = $m[1];
}
$assert( 'media block found', null !== $media );
if ( null !== $media ) {
	preg_match( '/body \{[^}]*color:\s*([^;]+);/', $media, $c );
	$assert(
		'auto dark text matches --text-primary',
		isset( $c[1] ) && strtolower( trim( $c[1] ) ) === $token( '.dark-mode', '--text-primary' )
	);
}

// --- Contrast of the informational ring track ------------------------------
// The dashed track is the only thing telling a sighted viewer a card was
// scored from one source. It shipped inheriting --border-color, which is
// 1.93:1 against the card in dark and 1.47:1 in light -- invisible, so the
// state read as simply absent. WCAG 1.4.11 wants 3:1 for non-text content
// that carries meaning. The plain .score-ring-track is exempt on purpose: it
// only shows how far the ring goes and says nothing.

/** WCAG relative luminance of a #rrggbb string. */
function ccf_luminance( $hex ) {
	$hex = ltrim( trim( $hex ), '#' );
	if ( 6 !== strlen( $hex ) ) {
		return null;
	}
	$channels = array();
	foreach ( array( 0, 2, 4 ) as $offset ) {
		$c = hexdec( substr( $hex, $offset, 2 ) ) / 255;
		$channels[] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** WCAG contrast ratio between two #rrggbb strings. */
function ccf_contrast( $a, $b ) {
	$la = ccf_luminance( $a );
	$lb = ccf_luminance( $b );
	if ( null === $la || null === $lb ) {
		return null;
	}
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

echo "\nThe single-source ring track is actually visible:\n";
foreach ( array( 'is-single-source', 'is-horizon-closed' ) as $state ) {
	$assert(
		sprintf( '.%s uses the token, not --border-color', $state ),
		(bool) preg_match(
			'/\.score-ring-track\.' . preg_quote( $state, '/' ) . '\s*\{[^}]*stroke:\s*var\(\s*--ring-track-uncorroborated\s*\)/s',
			$stylesheet
		)
	);
}
// Both states say "not corroborated". They must stay tellable apart, or the
// second one is just the first one drawn badly.
preg_match( '/\.score-ring-track\.is-single-source\s*\{[^}]*stroke-dasharray:\s*([^;]+);/s', $stylesheet, $one );
preg_match( '/\.score-ring-track\.is-horizon-closed\s*\{[^}]*stroke-dasharray:\s*([^;]+);/s', $stylesheet, $two );
$assert(
	'the two dash rhythms differ',
	isset( $one[1], $two[1] ) && trim( $one[1] ) !== trim( $two[1] )
);
foreach ( array( '.dark-mode', '.light-mode' ) as $mode ) {
	$track = $token( $mode, '--ring-track-uncorroborated' );
	$card  = $token( $mode, '--bg-card' );
	$ratio = ( null === $track || null === $card ) ? null : ccf_contrast( $track, $card );
	$assert(
		sprintf(
			'%s: %s on %s is %s (need 3.0:1)',
			$mode,
			$track ?? 'missing',
			$card ?? 'missing',
			null === $ratio ? 'unmeasurable' : sprintf( '%.2f:1', $ratio )
		),
		null !== $ratio && $ratio >= 3.0
	);
}
// The system-preference block is a fourth copy of the same tokens. If it
// drifts from .dark-mode, a viewer on "auto" gets a different ring.
if ( preg_match( '/@media \(prefers-color-scheme: dark\) \{\s*:root \{(.*?)\n\s*\}/s', $stylesheet, $auto ) ) {
	preg_match( '/--ring-track-uncorroborated:\s*([^;]+);/', $auto[1], $v );
	$assert(
		'the auto-dark block carries the same value as .dark-mode',
		isset( $v[1] ) && strtolower( trim( $v[1] ) ) === $token( '.dark-mode', '--ring-track-uncorroborated' )
	);
} else {
	$assert( 'the auto-dark token block was found', false );
}

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
