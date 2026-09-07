<?php
/**
 * Met.no comparison on the shortcode and block path.
 *
 * merge_cloud_cover_rows() served [cloud_cover], the public lookup block and
 * the sunrise/sunset block with no test at all. It did two things wrong.
 *
 * It compared all four cloud levels, but the providers band the sky
 * differently -- Open-Meteo low is 0-3 km against Met.no's 0-2 km, mid is
 * 3-8 km against 2-5 km -- so a gap on those rows is mostly definitional and
 * the "Delta 47%" badge presented a units artefact as a forecast disagreement.
 * Only 'total' means the same thing to both. 'high' bands differ too (8 km+
 * against 5 km+), but there the disagreement is demonstrably real: geometry
 * says Met.no should read higher and it read lower in 15 of 20 probe
 * locations, which band width cannot produce.
 *
 * And it overwrote every level with max(open, met) on every matched hour,
 * unconditionally -- the threshold gated only the badge. Those values feed
 * stats['avg_*'], which feeds rate_photography_conditions(), so every star
 * rating on those three surfaces was biased pessimistic by construction.
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
$merge      = $reflection->getMethod( 'merge_cloud_cover_rows' );

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

$ts = strtotime( '2026-09-07T19:00:00Z' );

/**
 * One Open-Meteo row.
 *
 * @param array $vals total, low, mid, high.
 * @return array Row shaped like fetch_weather_data()'s.
 */
function ccf_row( array $vals ) {
	global $ts;
	return array(
		'ts'    => $ts,
		'total' => $vals[0],
		'low'   => $vals[1],
		'mid'   => $vals[2],
		'high'  => $vals[3],
	);
}

/**
 * One Met.no map entry, keyed the way merge_cloud_cover_rows() looks it up.
 *
 * @param array $vals total, low, mid, high.
 * @return array Map shaped like fetch_met_no_complete()['hourly'].
 */
function ccf_met( array $vals ) {
	global $ts;
	return array(
		gmdate( 'Y-m-d H', $ts ) => array(
			'ts'    => $ts,
			'total' => $vals[0],
			'low'   => $vals[1],
			'mid'   => $vals[2],
			'high'  => $vals[3],
		),
	);
}

// --- Open-Meteo's numbers survive contact with the second source ----------
echo "\nOpen-Meteo's values are never overwritten:\n";
// Met.no reads cloudier on every level. Under max() every one of these was
// replaced, silently, whether or not the gap cleared the badge threshold.
$out = $merge->invoke( $api, array( ccf_row( array( 40, 30, 20, 10 ) ) ), ccf_met( array( 90, 80, 70, 60 ) ), 20 );
$row = $out['rows'][0];
$assert( 'total keeps the Open-Meteo reading', 40 === $row['total'] );
$assert( 'low keeps the Open-Meteo reading', 30 === $row['low'] );
$assert( 'mid keeps the Open-Meteo reading', 20 === $row['mid'] );
$assert( 'high keeps the Open-Meteo reading', 10 === $row['high'] );

echo "\nAnd when Met.no reads clearer, which max() also discarded:\n";
$clearer = $merge->invoke( $api, array( ccf_row( array( 90, 80, 70, 60 ) ) ), ccf_met( array( 10, 10, 10, 10 ) ), 20 );
$assert( 'the row is still Open-Meteo\'s', 90 === $clearer['rows'][0]['total'] );

// --- Only the comparable levels are flagged ------------------------------
echo "\nOnly levels that mean the same thing are compared:\n";
// Every level differs by 50, far above the threshold. Only two may be flagged.
$wide = $merge->invoke( $api, array( ccf_row( array( 20, 20, 20, 20 ) ) ), ccf_met( array( 70, 70, 70, 70 ) ), 20 );
$diff = $wide['rows'][0]['provider_diff'] ?? array();
$assert( 'total is compared, both mean the whole sky', isset( $diff['total'] ) );
$assert( 'high is compared, its disagreement is real', isset( $diff['high'] ) );
$assert( 'low is not, 0-3 km against 0-2 km is not the same measurement', ! isset( $diff['low'] ) );
$assert( 'mid is not, 3-8 km against 2-5 km is not either', ! isset( $diff['mid'] ) );
$assert( 'and the summary counts only the comparable levels', 0 === $wide['summary']['per_level']['low']
	&& 0 === $wide['summary']['per_level']['mid'] );
$assert( 'the row still counts as having a difference', 1 === $wide['summary']['rows_with_differences'] );

echo "\nA row that only differs on an incomparable level is not a difference:\n";
$lowonly = $merge->invoke( $api, array( ccf_row( array( 50, 20, 50, 50 ) ) ), ccf_met( array( 50, 90, 50, 50 ) ), 20 );
$assert( 'no provider_diff is recorded', empty( $lowonly['rows'][0]['provider_diff'] ) );
$assert( 'and the notice will not fire', 0 === $lowonly['summary']['rows_with_differences'] );

// --- The threshold still gates ------------------------------------------
echo "\nThe threshold still decides what counts as a difference:\n";
$under = $merge->invoke( $api, array( ccf_row( array( 50, 50, 50, 50 ) ) ), ccf_met( array( 65, 50, 50, 50 ) ), 20 );
$assert( 'a 15 point gap under a threshold of 20 is not flagged', empty( $under['rows'][0]['provider_diff'] ) );
$over = $merge->invoke( $api, array( ccf_row( array( 50, 50, 50, 50 ) ) ), ccf_met( array( 75, 50, 50, 50 ) ), 20 );
$assert( 'a 25 point gap is', isset( $over['rows'][0]['provider_diff']['total'] ) );
$assert( 'and it reports both readings for the tooltip',
	50 === $over['rows'][0]['provider_diff']['total']['open_meteo']
	&& 75 === $over['rows'][0]['provider_diff']['total']['met_no'] );

// --- Dead payload --------------------------------------------------------
echo "\nNothing unread is written into the row:\n";
$assert( 'source_values is gone, it had no reader anywhere', ! isset( $over['rows'][0]['source_values'] ) );
$assert( 'and provider_diff carries no unread "selected" key',
	! isset( $over['rows'][0]['provider_diff']['total']['selected'] ) );

// --- Rows Met.no does not cover -----------------------------------------
echo "\nAn hour with no Met.no sample is left alone:\n";
$uncovered = $merge->invoke( $api, array( ccf_row( array( 40, 30, 20, 10 ) ) ), array(), 20 );
$assert( 'the row is untouched', 40 === $uncovered['rows'][0]['total']
	&& ! isset( $uncovered['rows'][0]['provider_diff'] ) );
$assert( 'and the summary is empty', 0 === $uncovered['summary']['rows_with_differences'] );

// --- Nulls ---------------------------------------------------------------
echo "\nA missing reading on either side is not a disagreement:\n";
$nulls = $merge->invoke( $api, array( ccf_row( array( 50, 50, 50, null ) ) ), ccf_met( array( null, 50, 50, 50 ) ), 20 );
$assert( 'a null Met.no total is not compared', ! isset( $nulls['rows'][0]['provider_diff']['total'] ) );
$assert( 'a null Open-Meteo high is not compared', ! isset( $nulls['rows'][0]['provider_diff']['high'] ) );
$assert( 'and a null Open-Meteo value is still filled from Met.no',
	50 === $nulls['rows'][0]['high'] );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
