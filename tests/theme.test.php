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

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
