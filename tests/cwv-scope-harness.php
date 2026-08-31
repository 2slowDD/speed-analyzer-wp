<?php
/**
 * Harness: the results-log CWV reader must not discard metrics it already measured.
 *
 * Exercises the REAL shipped code: the regexes are lifted verbatim out of
 * diagnostics.php and editors.php by pattern extraction, and wpsa_parse_cwv_p75_from_line()
 * is lifted by brace matching and executed. Nothing here restates the logic, so a
 * regression in the shipped regex or the shipped parser goes red.
 *
 * Defect fixed 2026-08-31, found in a real results log:
 *   A "Module 5 CWV Page (Mobile): Assessment: N/A | ... | LCP: FAST (p75: 2224ms) ..."
 *   line carries real measured p75 values. The reader matched only PASSED|FAILED, so
 *   $assessment stayed '', the p75 scan was gated off, $p75 collapsed to null, and
 *   cwv-ui.js rendered "--" for every metric - discarding four real measurements.
 *
 * Also pins a pre-existing editors.php defect: diagnostics.php rewrites every healthy
 * page-scope line's label to "URL", but editors.php matched only (Page|Origin), leaving
 * the Posts/Pages CWV column blind to every passing URL-scope line.
 *
 * Run: php tests/cwv-scope-harness.php
 */

$root = dirname( __DIR__ );

/** Lift a function's source out of a file by brace matching (line-ending agnostic). */
function wpsa_lift_fn( $src, $name ) {
	$start = strpos( $src, 'function ' . $name );
	if ( false === $start ) {
		return null;
	}
	$open = strpos( $src, '{', $start );
	if ( false === $open ) {
		return null;
	}
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = $open; $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			$depth++;
		}
		if ( '}' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, $start, $i - $start + 1 );
			}
		}
	}
	return null;
}

function wpsa_read_norm( $path ) {
	return str_replace( array( "\r\n", "\r" ), "\n", file_get_contents( $path ) );
}

$diag    = wpsa_read_norm( $root . '/diagnostics.php' );
$editors = wpsa_read_norm( $root . '/editors.php' );

$parser = wpsa_lift_fn( $diag, 'wpsa_parse_cwv_p75_from_line' );
if ( null === $parser ) {
	fwrite( STDERR, "FAIL: could not locate wpsa_parse_cwv_p75_from_line() in diagnostics.php\n" );
	exit( 1 );
}
$stub = tempnam( sys_get_temp_dir(), 'wpsacwv' ) . '.php';
file_put_contents( $stub, "<?php\n" . $parser . "\n" );
require $stub;
unlink( $stub );

$failed = 0;
function wpsa_check( $label, $got, $want ) {
	global $failed;
	if ( $got === $want ) {
		printf( "  ok    %-56s %s\n", $label, var_export( $got, true ) );
		return;
	}
	printf( "  FAIL  %-56s expected %s, got %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
	$failed++;
}

// A real logged line from a page whose page-scope CrUX record carried no INP: four real
// p75 measurements are present, so the assessment is N/A while the values are not.
$na_line = 'Module 5 CWV Page (Mobile): Assessment: N/A | Overall: AVERAGE'
	. ' | LCP: FAST (p75: 2224ms; 80/13/6) | CLS: FAST (p75: 0.02; 94/4/2)'
	. ' | FCP: AVERAGE (p75: 1919ms; 73/20/6) | TTFB: AVERAGE (p75: 1486ms; 44/39/18)';

echo "-- diagnostics.php reader: must match an Assessment: N/A line --\n";

if ( ! preg_match( '/\$line_re\s*=\s*(.+?);\n/s', $diag, $m ) ) {
	fwrite( STDERR, "FAIL: could not locate \$line_re in diagnostics.php\n" );
	exit( 1 );
}
// Rebuild the shipped expression without eval(): substitute the device label for the
// preg_quote() call, then concatenate the single-quoted segments. Single-quoted PHP
// strings only escape \' and \\, so this reproduces the runtime value exactly.
$expr = preg_replace( '/preg_quote\([^)]*\)/', "'Mobile'", $m[1] );
preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $expr, $parts );
$line_re = '';
foreach ( $parts[1] as $seg ) {
	$line_re .= str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $seg );
}
if ( '' === $line_re ) {
	fwrite( STDERR, "FAIL: could not rebuild \$line_re from diagnostics.php\n" );
	exit( 1 );
}

wpsa_check( 'matches Assessment: N/A (Test #203 line)', (bool) preg_match( $line_re, $na_line ), true );
wpsa_check(
	'still matches PASSED',
	(bool) preg_match( $line_re, 'Module 5 CWV URL (Mobile): Assessment: PASSED | Overall: FAST' ),
	true
);
wpsa_check(
	'still matches FAILED',
	(bool) preg_match( $line_re, 'Module 5 CWV Origin (Mobile): Assessment: FAILED | Overall: AVERAGE' ),
	true
);
wpsa_check(
	'still rejects the other device',
	(bool) preg_match( $line_re, 'Module 5 CWV URL (Desktop): Assessment: PASSED | Overall: FAST' ),
	false
);
wpsa_check(
	'still rejects a bare no-data line',
	(bool) preg_match( $line_re, 'Module 5 CWV (Mobile): N/A' ),
	false
);

echo "\n-- the p75 scan must not be gated behind a truthy \$assessment --\n";
// With N/A matching, the gate is what still throws the measurements away.
wpsa_check(
	'no "if ( $assessment ) {" wrapping the p75 scan',
	(bool) preg_match( '/if\s*\(\s*\$assessment\s*\)\s*\{[^}]*Find the CWV summary line/s', $diag ),
	false
);

echo "\n-- the measurements that were being discarded --\n";
$p75 = wpsa_parse_cwv_p75_from_line( $na_line );
wpsa_check( 'LCP recovered from the N/A line', $p75['lcp_ms'], 2224 );
wpsa_check( 'FCP recovered from the N/A line', $p75['fcp_ms'], 1919 );
wpsa_check( 'TTFB recovered from the N/A line', $p75['ttfb_ms'], 1486 );
wpsa_check( 'CLS recovered from the N/A line', $p75['cls'], 0.02 );
wpsa_check( 'INP stays null - it was genuinely absent', $p75['inp_ms'], null );

echo "\n-- editors.php: Posts/Pages column must see URL-scope lines --\n";
preg_match_all( "/'(#\\^Module[^']*#i)'/", $editors, $pm );
$ed_patterns = $pm[1];
wpsa_check( 'found both editors.php CWV patterns', count( $ed_patterns ), 2 );

$ed_mobile = '';
foreach ( $ed_patterns as $p ) {
	if ( false !== strpos( $p, 'Mobile' ) ) {
		$ed_mobile = $p;
	}
}
wpsa_check( 'located the Mobile pattern', '' !== $ed_mobile, true );

wpsa_check(
	'accepts URL scope (what diagnostics.php:591 writes)',
	(bool) preg_match( $ed_mobile, 'Module 5 CWV URL (Mobile): Assessment: PASSED | Overall: FAST' ),
	true
);
wpsa_check(
	'still accepts legacy Page scope',
	(bool) preg_match( $ed_mobile, 'Module 5 CWV Page (Mobile): Assessment: PASSED | Overall: FAST' ),
	true
);
wpsa_check(
	'still accepts Origin scope',
	(bool) preg_match( $ed_mobile, 'Module 5 CWV Origin (Mobile): Assessment: FAILED | Overall: AVERAGE' ),
	true
);

// GUARD - do not "finish" this the way diagnostics.php was finished.
// editors.php:428 collapses the captured verdict with
//   strtolower( $m[2] ) === 'passed' ? 'passed' : 'failed'
// so admitting N/A here would render a false "Failed" badge on the Posts/Pages list
// for every site that simply has no CrUX field data. The correct behaviour is no
// match at all, which leaves the status null and renders "--".
wpsa_check(
	'MUST NOT accept N/A - would print a false "Failed" (see :428)',
	(bool) preg_match( $ed_mobile, $na_line ),
	false
);

echo "\n-- editors.php: the scope allowlist must carry the value it now parses --\n";
// Parsing "URL" is useless if the render-side allowlist drops it back to "--".
if ( ! preg_match( "/in_array\(\s*\\\$r\['cwv_m_scope'\],\s*\[([^\]]*)\]/", $editors, $sm ) ) {
	fwrite( STDERR, "FAIL: could not locate the cwv_m_scope allowlist in editors.php\n" );
	exit( 1 );
}
$allow = $sm[1];
wpsa_check( "scope allowlist admits 'url'", false !== strpos( $allow, "'url'" ), true );
wpsa_check( "scope allowlist still admits 'page'", false !== strpos( $allow, "'page'" ), true );
wpsa_check( "scope allowlist still admits 'origin'", false !== strpos( $allow, "'origin'" ), true );

if ( $failed > 0 ) {
	fwrite( STDERR, "\ncwv scope harness FAILED ({$failed} case(s))\n" );
	exit( 1 );
}
echo "\ncwv scope harness passed\n";
