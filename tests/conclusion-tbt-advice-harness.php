<?php
/**
 * Harness: section 8.4 must render an explanation and advice for TBT, on both devices.
 *
 * TBT is graded per form factor (decision D4/D6), so its copy lives under the
 * device-suffixed keys 'tbt_mobile' and 'tbt_desktop'. There is deliberately no
 * module_5['tbt'] key.
 *
 * The card printer built $map_key correctly and used it to classify the value - which is
 * why the card was coloured and ticked correctly - but then looked the COPY up under
 * $metric ('tbt'), a key that does not exist. Both the explanation and the advice
 * resolved to null and rendered as empty strings, so every TBT card showed a bare
 * "Advice:" with nothing after it.
 *
 * This lifts the real map and the real $classify closure out of conclusion.php and
 * exercises the lookup the renderer performs, so the thresholds and the copy are both
 * checked against the shipped source.
 *
 * Run: php tests/conclusion-tbt-advice-harness.php
 */

$root = dirname( __DIR__ );
$src  = str_replace( array( "\r\n", "\r" ), "\n", file_get_contents( $root . '/includes/conclusion.php' ) );

/** Lift a balanced bracketed expression starting at $needle. */
function wpsa_lift_expr( $src, $needle, $open, $close ) {
	$start = strpos( $src, $needle );
	if ( false === $start ) {
		return null;
	}
	$o = strpos( $src, $open, $start );
	if ( false === $o ) {
		return null;
	}
	$d = 0;
	for ( $i = $o; $i < strlen( $src ); $i++ ) {
		if ( $src[ $i ] === $open ) {
			$d++;
		}
		if ( $src[ $i ] === $close ) {
			$d--;
			if ( 0 === $d ) {
				return substr( $src, $o, $i - $o + 1 );
			}
		}
	}
	return null;
}

$map_literal = wpsa_lift_expr( $src, '$wpsa_conclusion_map = [', '[', ']' );
$classify_fn = wpsa_lift_expr( $src, '$classify = function(', '{', '}' );
if ( null === $map_literal || null === $classify_fn ) {
	fwrite( STDERR, "FAIL: could not lift the map or the classify closure from conclusion.php\n" );
	exit( 1 );
}

$stub = tempnam( sys_get_temp_dir(), 'wpsacon' ) . '.php';
file_put_contents( $stub, "<?php\n\$MAP = " . $map_literal . ";\n\$classify = function( \$val, \$map ) " . $classify_fn . ";\n" );
require $stub;
unlink( $stub );

$failed = 0;
function wpsa_check( $label, $got, $want ) {
	global $failed;
	if ( $got === $want ) {
		printf( "  ok    %-56s %s\n", $label, is_string( $got ) ? "'" . mb_strimwidth( $got, 0, 42, '...' ) . "'" : var_export( $got, true ) );
		return;
	}
	printf( "  FAIL  %-56s expected %s, got %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
	$failed++;
}

$m5 = $MAP['module_5'];

echo "-- the map shape TBT actually uses --\n";
wpsa_check( 'tbt_mobile table exists', isset( $m5['tbt_mobile'] ), true );
wpsa_check( 'tbt_desktop table exists', isset( $m5['tbt_desktop'] ), true );
wpsa_check( "there is deliberately NO module_5['tbt'] key", isset( $m5['tbt'] ), false );

echo "\n-- the renderer must look the copy up by the device-suffixed key --\n";
// The defect was $metric here instead of $map_key: the classification line used $map_key,
// so the card coloured correctly while the text silently resolved to null.
$explain_line = '';
$advice_line  = '';
// Scope to the module_5 card printer. Other sections (cache_status, module_1, module_4)
// have their own lookups on the same map and must not be picked up here.
foreach ( explode( "\n", $src ) as $l ) {
	if ( false === strpos( $l, "wpsa_conclusion_map['module_5']" ) ) {
		continue;
	}
	if ( false !== strpos( $l, "['explanation']" ) ) {
		$explain_line = $l;
	}
	if ( false !== strpos( $l, "['advice']" ) ) {
		$advice_line = $l;
	}
}
wpsa_check( 'located the module_5 explanation lookup', '' !== $explain_line, true );
wpsa_check( 'located the module_5 advice lookup', '' !== $advice_line, true );
wpsa_check( 'explanation lookup uses $map_key', false !== strpos( $explain_line, '$map_key' ), true );
wpsa_check( 'advice lookup uses $map_key', false !== strpos( $advice_line, '$map_key' ), true );
wpsa_check( 'explanation lookup no longer uses $metric', false !== strpos( $explain_line, '[ $metric ]' ), false );
wpsa_check( 'advice lookup no longer uses $metric', false !== strpos( $advice_line, '[ $metric ]' ), false );

echo "\n-- behavioural: every band on both devices yields real copy --\n";
// Values chosen to sit inside each band of the D4 thresholds:
// mobile good<=200 / medium<=600 / bad>600 ; desktop good<=150 / medium<=350 / bad>350.
$cases = array(
	array( 'Mobile', 0, 'good' ),
	array( 'Mobile', 300, 'medium' ),
	array( 'Mobile', 700, 'bad' ),
	array( 'Desktop', 59, 'good' ),
	array( 'Desktop', 200, 'medium' ),
	array( 'Desktop', 400, 'bad' ),
);
foreach ( $cases as $c ) {
	list( $device, $val, $expect_sev ) = $c;
	$map_key = 'tbt_' . strtolower( $device );
	$sev     = $classify( $val, $m5[ $map_key ] );
	wpsa_check( sprintf( '%s %d ms classifies as %s', $device, $val, $expect_sev ), $sev, $expect_sev );

	$expl = $m5[ $map_key ][ $sev ]['explanation'] ?? '';
	$adv  = $m5[ $map_key ][ $sev ]['advice'] ?? '';
	wpsa_check( sprintf( '  %s %d ms has an explanation', $device, $val ), '' !== trim( $expl ), true );
	wpsa_check( sprintf( '  %s %d ms has advice', $device, $val ), '' !== trim( $adv ), true );
}

echo "\n-- the regression itself: the old key yields nothing --\n";
// This is the guard. Looking up by 'tbt' is what produced the blank "Advice:".
$broken = $m5['tbt']['good']['advice'] ?? '';
wpsa_check( "lookup by 'tbt' resolves to empty (the bug)", '' === $broken, true );

echo "\n-- mobile and desktop copy must differ where the thresholds differ --\n";
wpsa_check(
	'good-band explanations quote different thresholds',
	$m5['tbt_mobile']['good']['explanation'] !== $m5['tbt_desktop']['good']['explanation'],
	true
);
wpsa_check( 'mobile good band quotes 200 ms', false !== strpos( $m5['tbt_mobile']['good']['explanation'], '200' ), true );
wpsa_check( 'desktop good band quotes 150 ms', false !== strpos( $m5['tbt_desktop']['good']['explanation'], '150' ), true );

if ( $failed > 0 ) {
	fwrite( STDERR, "\nconclusion tbt advice harness FAILED ({$failed} case(s))\n" );
	exit( 1 );
}
echo "\nconclusion tbt advice harness passed\n";
