<?php
/**
 * Met.no reading attachment for the PWA payload.
 *
 * Loads class-api.php outside WordPress and reaches the private methods by
 * reflection, matching tests/solar.test.php. The methods under test touch no
 * WordPress APIs and make no HTTP requests.
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
$attach     = $reflection->getMethod( 'attach_met_no_readings' );
$indices    = $reflection->getMethod( 'met_no_hour_indices' );

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

/**
 * Build 24 hourly entries of local wall-clock stamps for one date.
 *
 * @param string $date Date in YYYY-MM-DD form.
 * @return array Hourly rows.
 */
function ccf_hours( $date ) {
	$hourly = array();
	for ( $h = 0; $h < 24; $h++ ) {
		$hourly[] = array(
			'time'       => $date . 'T' . str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) . ':00',
			'cloud_low'  => 40,
			'cloud_mid'  => 91,
			'cloud_high' => 59,
		);
	}
	return $hourly;
}

/**
 * Build a Met.no map from [utc_timestamp => [low, mid, high]] pairs.
 *
 * @param array $entries Timestamp-keyed layer triples.
 * @return array Map shaped like fetch_met_no_complete()['hourly'].
 */
function ccf_metno( array $entries ) {
	$map = array();
	foreach ( $entries as $ts => $vals ) {
		$map[ gmdate( 'Y-m-d H', $ts ) ] = array(
			'ts'    => $ts,
			'total' => isset( $vals[3] ) ? $vals[3] : 90,
			'low'   => $vals[0],
			'mid'   => $vals[1],
			'high'  => $vals[2],
		);
	}
	return $map;
}

// --- DST: the same wall clock is a different UTC hour ---------------------
// Ireland leaves IST (UTC+1) for GMT (UTC+0) on 2026-10-25.
echo "\nLocal wall-clock to UTC across a DST boundary:\n";

$ist_day = array( array( 'date' => '2026-10-24', 'twilight' => array( 'sunset' => '20:27' ) ) );
$gmt_day = array( array( 'date' => '2026-10-26', 'twilight' => array( 'sunset' => '20:27' ) ) );

// One Met.no sample at 19:00 UTC and one at 20:00 UTC, on each date.
$samples = ccf_metno( array(
	strtotime( '2026-10-24T19:00:00Z' ) => array( 8, 70, 61 ),
	strtotime( '2026-10-24T20:00:00Z' ) => array( 88, 10, 11 ),
	strtotime( '2026-10-26T19:00:00Z' ) => array( 88, 10, 11 ),
	strtotime( '2026-10-26T20:00:00Z' ) => array( 8, 70, 61 ),
) );

$ist = $attach->invoke( $api, ccf_hours( '2026-10-24' ), $ist_day, 'Europe/Dublin', $samples );
$gmt = $attach->invoke( $api, ccf_hours( '2026-10-26' ), $gmt_day, 'Europe/Dublin', $samples );

// 20:00 local on 24 Oct is 19:00 UTC (IST, +1).
$assert( 'IST 20:00 local matches the 19:00 UTC sample', 8 === $ist[20]['met_no']['low'] );
// 20:00 local on 26 Oct is 20:00 UTC (GMT, +0).
$assert( 'GMT 20:00 local matches the 20:00 UTC sample', 8 === $gmt[20]['met_no']['low'] );
$assert(
	'both matched exactly, offset 0',
	0 === $ist[20]['met_no']['offset_hours'] && 0 === $gmt[20]['met_no']['offset_hours']
);

// --- Nearest sample within three hours ------------------------------------
echo "\nNearest-sample selection:\n";
$day = array( array( 'date' => '2026-07-01', 'twilight' => array( 'sunset' => '21:00' ) ) );

// 21:00 IST = 20:00 UTC; the sample is exactly three hours earlier.
$exactly_3h = $attach->invoke(
	$api,
	ccf_hours( '2026-07-01' ),
	$day,
	'Europe/Dublin',
	ccf_metno( array( strtotime( '2026-07-01T17:00:00Z' ) => array( 8, 70, 61 ) ) )
);
$assert( 'a sample exactly 3h away is accepted', isset( $exactly_3h[21]['met_no'] ) );
$assert( 'and reports offset_hours 3', 3 === $exactly_3h[21]['met_no']['offset_hours'] );

$past_3h = $attach->invoke(
	$api,
	ccf_hours( '2026-07-01' ),
	$day,
	'Europe/Dublin',
	ccf_metno( array( strtotime( '2026-07-01T16:59:00Z' ) => array( 8, 70, 61 ) ) )
);
$assert( 'a sample 3h01m away is rejected', ! isset( $past_3h[21]['met_no'] ) );

$tie = $attach->invoke(
	$api,
	ccf_hours( '2026-07-01' ),
	$day,
	'Europe/Dublin',
	ccf_metno( array(
		strtotime( '2026-07-01T18:00:00Z' ) => array( 8, 70, 61 ),
		strtotime( '2026-07-01T22:00:00Z' ) => array( 88, 10, 11 ),
	) )
);
$assert( 'an equidistant tie resolves to the earlier sample', 8 === $tie[21]['met_no']['low'] );

// --- Null layers carry through as null ------------------------------------
echo "\nNull layer handling:\n";
$partial = ccf_metno( array( strtotime( '2026-07-01T20:00:00Z' ) => array( 8, null, 61 ) ) );
$got     = $attach->invoke( $api, ccf_hours( '2026-07-01' ), $day, 'Europe/Dublin', $partial );
$assert( 'a null layer is carried through as null, not zero', null === $got[21]['met_no']['mid'] );
$assert( 'the other layers are present', 8 === $got[21]['met_no']['low'] );

// --- No Met.no data leaves the payload untouched --------------------------
echo "\nMissing Met.no data:\n";
$original  = ccf_hours( '2026-07-01' );
$unchanged = $attach->invoke( $api, $original, $day, 'Europe/Dublin', array() );
$assert( 'an empty Met.no map returns the payload unchanged', $original === $unchanged );

$bad_tz = $attach->invoke(
	$api,
	$original,
	$day,
	'Not/AZone',
	ccf_metno( array( strtotime( '2026-07-01T20:00:00Z' ) => array( 8, 70, 61 ) ) )
);
$assert( 'an invalid timezone returns the payload unchanged', $original === $bad_tz );

// --- Which hours get a reading --------------------------------------------
echo "\nHour window:\n";
$window = $indices->invoke( $api, ccf_hours( '2026-07-01' ), $day );
$assert( 'the window is eventIndex-1 .. eventIndex+2', array( 20, 21, 22, 23 ) === $window );

$two_events = array(
	array(
		'date'     => '2026-07-01',
		'twilight' => array( 'sunrise' => '05:15', 'sunset' => '21:00' ),
	),
);
$both = $indices->invoke( $api, ccf_hours( '2026-07-01' ), $two_events );
$assert(
	'sunrise and sunset both contribute windows',
	array( 4, 5, 6, 7, 20, 21, 22, 23 ) === $both
);

$no_event = $indices->invoke(
	$api,
	ccf_hours( '2026-07-01' ),
	array( array( 'date' => '2026-07-01', 'twilight' => array( 'sunset' => null ) ) )
);
$assert( 'a polar day with no sunset yields no hours', array() === $no_event );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
