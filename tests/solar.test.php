<?php
/**
 * Solar phase times.
 *
 * Loads class-api.php outside WordPress and reaches the private methods by
 * reflection: they touch no WordPress APIs and no plugin state, only maths.
 *
 * Ground truth is the Alpenglow iOS app for Durrus, West Cork on 2026-08-31.
 *
 * @package CloudCoverForecast
 */

define( 'ABSPATH', __DIR__ );
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'MONTH_IN_SECONDS'  => 2592000,
	'YEAR_IN_SECONDS'   => 31536000,
) as $name => $value ) {
	define( $name, $value );
}
require dirname( __DIR__ ) . '/includes/class-api.php';

$reflection = new ReflectionClass( 'Cloud_Cover_Forecast_API' );
$api        = $reflection->newInstanceWithoutConstructor();
$twilight   = $reflection->getMethod( 'calculate_twilight_times' );
$solar      = $reflection->getMethod( 'solar_event_times' );

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

// --- Against Alpenglow, Durrus 2026-08-31 -------------------------------
echo "\nDurrus 2026-08-31 vs Alpenglow:\n";
$got      = $twilight->invoke( $api, 51.6236, -9.5236, '2026-08-31', 'Europe/Dublin' );
$expected = array(
	'astronomical_dawn'      => '04:42',
	'blue_hour_dawn_start'   => '06:14',
	'blue_hour_dawn_end'     => '06:27',
	'golden_hour_dawn_start' => '06:27',
	'sunrise'                => '06:49',
	'golden_hour_dawn_end'   => '07:34',
	'golden_hour_dusk_start' => '19:42',
	'sunset'                 => '20:27',
	'golden_hour_dusk_end'   => '20:48',
	'blue_hour_dusk_start'   => '20:48',
	'blue_hour_dusk_end'     => '21:02',
	'astronomical_dusk'      => '22:33',
);
$worst = 0;
foreach ( $expected as $field => $want ) {
	$have = $got[ $field ] ?? null;
	if ( null === $have ) {
		echo '    ' . str_pad( $field, 24 ) . "MISSING\n";
		$worst = 999;
		continue;
	}
	$delta = ( strtotime( "1970-01-01 $have UTC" ) - strtotime( "1970-01-01 $want UTC" ) ) / 60;
	$worst = max( $worst, abs( $delta ) );
	printf( "    %-24s %-6s vs %-6s %+d min\n", $field, $have, $want, $delta );
}
$assert( 'every boundary within a minute of Alpenglow', $worst <= 1 );

// --- Chronological ordering, worldwide, all year ------------------------
$sequence = array(
	array( -18.0, 'rise' ), array( -12.0, 'rise' ), array( -6.0, 'rise' ),
	array( -4.0, 'rise' ), array( -0.833, 'rise' ), array( 6.0, 'rise' ),
	array( 6.0, 'set' ), array( -0.833, 'set' ), array( -4.0, 'set' ),
	array( -6.0, 'set' ), array( -12.0, 'set' ), array( -18.0, 'set' ),
);
$places = array(
	array( 'Durrus', 51.6236, -9.5236, 'Europe/Dublin' ),
	array( 'Auckland', -36.8485, 174.7633, 'Pacific/Auckland' ),
	array( 'Singapore', 1.3521, 103.8198, 'Asia/Singapore' ),
	array( 'Reykjavik', 64.1466, -21.9426, 'Atlantic/Reykjavik' ),
	array( 'Sydney', -33.8688, 151.2093, 'Australia/Sydney' ),
	array( 'Los Angeles', 37.7749, -122.4194, 'America/Los_Angeles' ),
	array( 'Tromso', 69.6496, 18.9560, 'Europe/Oslo' ),
	array( 'Ushuaia', -54.8019, -68.3030, 'America/Argentina/Ushuaia' ),
	array( 'Kiritimati', 1.8721, -157.4278, 'Pacific/Kiritimati' ),
	array( 'South Pole', -89.9, 0.0, 'UTC' ),
);

$days           = 0;
$out_of_order   = 0;
$wrong_date     = 0;
$after_midnight = 0;
foreach ( $places as $place ) {
	list( $label, $lat, $lon, $zone ) = $place;
	$tz = new DateTimeZone( $zone );
	for ( $offset = 0; $offset < 365; $offset++ ) {
		$date = gmdate( 'Y-m-d', strtotime( '2026-01-01 +' . $offset . ' days' ) );
		$noon = ( new DateTime( "$date 12:00:00", $tz ) )->getTimestamp();
		$days++;

		$previous = null;
		foreach ( $sequence as $event ) {
			$times = $solar->invoke( $api, $lat, $lon, $noon, $event[0] );
			$stamp = $times[ $event[1] ];
			if ( null === $stamp ) {
				continue;
			}
			if ( null !== $previous && $stamp < $previous ) {
				$out_of_order++;
			}
			$local = ( new DateTime( '@' . $stamp ) )->setTimezone( $tz )->format( 'Y-m-d' );
			if ( $local > $date ) {
				$after_midnight++;
			}
			$previous = $stamp;
		}

		// Sunrise, where it exists, must fall on the requested local date.
		$sunrise = $solar->invoke( $api, $lat, $lon, $noon, -0.833 );
		if ( null !== $sunrise['rise'] ) {
			$local = ( new DateTime( '@' . $sunrise['rise'] ) )->setTimezone( $tz )->format( 'Y-m-d' );
			if ( $local !== $date ) {
				$wrong_date++;
			}
		}
	}
}

printf( "\n%d location-days across %d locations, every day of 2026:\n", $days, count( $places ) );
$assert( 'phases are always chronological', 0 === $out_of_order );
$assert( 'sunrise always falls on the requested local date', 0 === $wrong_date );
printf( "    (%d events legitimately land after local midnight)\n", $after_midnight );

// --- Polar edge cases ---------------------------------------------------
echo "\nPolar edge cases:\n";
$midnight_sun = $twilight->invoke( $api, 69.6496, 18.9560, '2026-06-21', 'Europe/Oslo' );
$assert( 'Tromso in June has no sunrise or sunset', null === $midnight_sun['sunrise'] && null === $midnight_sun['sunset'] );
$polar_night = $twilight->invoke( $api, 69.6496, 18.9560, '2026-12-21', 'Europe/Oslo' );
$assert( 'Tromso in December has no golden hour', null === $polar_night['golden_hour_dawn_end'] );
$irish_june = $twilight->invoke( $api, 51.6236, -9.5236, '2026-06-21', 'Europe/Dublin' );
$assert( 'an Irish June has no astronomical twilight', null === $irish_june['astronomical_dawn'] );
$assert( 'but still has a golden hour', null !== $irish_june['golden_hour_dusk_start'] );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
