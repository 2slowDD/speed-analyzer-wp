<?php
/**
 * Harness: the Module 5 TBT line must survive as its own line, end to end.
 *
 * Decision D7 puts TBT on a line of its own, because the frozen 1.18.6 Module 5 grammar
 * requires CLS to be followed directly by ", INP:" and then end-of-line - no single-line
 * arrangement carrying a TBT token can match it.
 *
 * Manual runs post both lines as one payload to wp_ajax_wpsa_log_module5. WordPress's
 * sanitize_text_field() runs preg_replace('/[\r\n\t ]+/', ' ', ...) - it COLLAPSES the
 * newline into a space. That produced, live, in Tests #210/#211/#212:
 *
 *   Module 5 Mobile: Performance: 79, ... , INP: N/A Module 5 Mobile TBT: 1
 *
 * which matches NEITHER parser: the Module 5 regex is $-anchored after INP, and the TBT
 * regex is ^-anchored. So the Compare tab showed N/A for LCP, FCP, CLS *and* TBT.
 * sanitize_textarea_field() is the newline-preserving variant and is the correct choice
 * for a multi-line payload.
 *
 * Scheduled runs build their lines server-side with explicit "\n" and were never affected.
 *
 * Second defect, masked by the first: the "drop old Module 5 lines" patterns in
 * diagnostics.php and schedule.php match `Module 5 Mobile:` but NOT `Module 5 Mobile TBT:`
 * (no colon directly after the device). Once TBT is a real line, re-logging a test block
 * would accumulate duplicate TBT lines.
 *
 * Run: php tests/module5-log-line-harness.php
 */

$root = dirname( __DIR__ );

function wpsa_read_norm( $p ) {
	return str_replace( array( "\r\n", "\r" ), "\n", file_get_contents( $p ) );
}

$diag     = wpsa_read_norm( $root . '/includes/diagnostics.php' );
$compare  = wpsa_read_norm( $root . '/includes/compare.php' );
$schedule = wpsa_read_norm( $root . '/includes/schedule.php' );

$failed = 0;
function wpsa_check( $label, $got, $want ) {
	global $failed;
	if ( $got === $want ) {
		printf( "  ok    %-58s %s\n", $label, var_export( $got, true ) );
		return;
	}
	printf( "  FAIL  %-58s expected %s, got %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
	$failed++;
}

/** WordPress core _sanitize_text_fields(), reduced to the behaviour under test. */
function wpsa_wp_sanitize( $str, $keep_newlines ) {
	$f = strip_tags( (string) $str );
	if ( ! $keep_newlines ) {
		$f = preg_replace( '/[\r\n\t ]+/', ' ', $f );
	}
	return trim( $f );
}

// Exactly what admin-scripts.js posts for a manual run (Test #211, mobile).
$payload = "Module 5 Mobile: Performance: 79, LCP: 4, FCP: 3.1, CLS: 0, INP: N/A\n"
	. "Module 5 Mobile TBT: 1\n";

echo "-- the shipped handler must use a newline-preserving sanitizer --\n";
// Lift the two assignments out of the handler rather than restating them.
preg_match_all( "/\\\$(?:mobile|desktop)\s*=\s*isset\(\s*\\\$_POST\[[^\]]+\]\s*\)\s*\?\s*([a-z_]+)\(/", $diag, $sm );
$sanitizers = array_values( array_unique( $sm[1] ) );
wpsa_check(
	'mobile/desktop sanitizer preserves newlines',
	implode( ',', $sanitizers ),
	'sanitize_textarea_field'
);

echo "\n-- end to end: payload -> sanitizer -> the two shipped parsers --\n";
// Lift compare.php's real Module 5 Mobile regex and its TBT regex.
if ( ! preg_match( "/'(\/\^Module\\\\s\+5\\\\s\+Mobile:.*?)'\s*\n?\s*,\s*\\\$ln/s", $compare, $rm ) ) {
	// Fall back to locating it by its distinctive opening.
	preg_match( "/'(\/\^Module\\\\s\+5\\\\s\+Mobile:[^']*)'/", $compare, $rm );
}
preg_match( "/'(\/\^Module\\\\s\+5\\\\s\+Mobile\\\\s\+TBT:[^']*)'/", $compare, $tm );
wpsa_check( 'located compare.php TBT regex', isset( $tm[1] ) && '' !== $tm[1], true );
$tbt_re = $tm[1];

$sanitized = wpsa_wp_sanitize( $payload, true ); // what the FIX produces
$lines     = preg_split( '/\R/', $sanitized );
wpsa_check( 'sanitized payload is still two lines', count( $lines ), 2 );

$tbt_seen = null;
foreach ( $lines as $l ) {
	if ( preg_match( $tbt_re, trim( $l ), $x ) ) {
		$tbt_seen = $x[1];
	}
}
wpsa_check( 'compare.php parses the TBT line', $tbt_seen, '1' );

// The frozen Module 5 grammar must still match its own line (the $ anchor is the point).
$m5_re = '/^Module\s+5\s+Mobile:\s*Performance:\s*([0-9]+|N\/A)\s*,\s*LCP:\s*(N\/A|[0-9]+(?:\.[0-9]+)?)'
	. '\s*,\s*FCP:\s*(N\/A|[0-9]+(?:\.[0-9]+)?)(?:\s*,\s*CLS:\s*(N\/A|[0-9]+(?:\.[0-9]+)?))?'
	. '(?:\s*,\s*INP:\s*(N\/A|[0-9]+))?\s*$/i';
wpsa_check( 'the frozen Module 5 line still parses', (bool) preg_match( $m5_re, trim( $lines[0] ) ), true );

echo "\n-- the regression itself: the collapsed form must break both parsers --\n";
// This is the guard. If a future change reintroduces sanitize_text_field, the line below
// is what lands in the log, and neither parser can read it.
$collapsed = wpsa_wp_sanitize( $payload, false );
wpsa_check( 'collapsed payload is one line (the bug)', count( preg_split( '/\R/', $collapsed ) ), 1 );
wpsa_check( 'and the Module 5 parser rejects it', (bool) preg_match( $m5_re, $collapsed ), false );
wpsa_check( 'and the TBT parser rejects it', (bool) preg_match( $tbt_re, $collapsed ), false );

echo "\n-- re-logging a block must drop OLD TBT lines, not stack them --\n";
$old_tbt = 'Module 5 Mobile TBT: 42';
$old_m5  = 'Module 5 Mobile: Performance: 79, LCP: 4, FCP: 3.1, CLS: 0, INP: N/A';

// diagnostics.php has TWO device patterns and they must behave DIFFERENTLY:
//   - the $ok() payload validator keeps ':' + 'Performance:' - it validates the first
//     line of an incoming payload and must NOT accept a bare TBT line;
//   - the block-rewrite drop pattern must match BOTH, or old TBT lines stack up.
preg_match_all( "/'(\/\^\\\\s\*Module\\\\s\+5\\\\s\+\(Mobile\|Desktop\)[^']*)'/", $diag, $dm_all );
$validator = '';
$dropper   = '';
foreach ( $dm_all[1] as $re ) {
	if ( false !== strpos( $re, 'Performance' ) ) {
		$validator = $re;
	} else {
		$dropper = $re;
	}
}
wpsa_check( 'located diagnostics.php payload validator', '' !== $validator, true );
wpsa_check( 'located diagnostics.php drop pattern', '' !== $dropper, true );
wpsa_check( 'validator accepts the Module 5 line', (bool) preg_match( $validator, $old_m5 ), true );
wpsa_check( 'validator REJECTS a bare TBT line', (bool) preg_match( $validator, $old_tbt ), false );
$dm = array( 1 => $dropper );
wpsa_check( 'diagnostics.php drops the old Module 5 line', (bool) preg_match( $dm[1], $old_m5 ), true );
wpsa_check( 'diagnostics.php ALSO drops the old TBT line', (bool) preg_match( $dm[1], $old_tbt ), true );

// schedule.php strip pattern (scheduled runs already write real TBT lines today).
preg_match( "/'(\/\^Module\\\\s\+5\\\\s\+\(Mobile\|Desktop\)[^']*)'/", $schedule, $scm );
wpsa_check( 'located schedule.php drop pattern', isset( $scm[1] ), true );
wpsa_check( 'schedule.php drops the old Module 5 line', (bool) preg_match( $scm[1], $old_m5 ), true );
wpsa_check( 'schedule.php ALSO drops the old TBT line', (bool) preg_match( $scm[1], $old_tbt ), true );

// And neither may swallow the CWV line, which has its own drop rule.
$cwv = 'Module 5 CWV Page (Mobile): Assessment: N/A | Overall: N/A';
wpsa_check( 'diagnostics.php device pattern does not match CWV', (bool) preg_match( $dm[1], $cwv ), false );
wpsa_check( 'schedule.php device pattern does not match CWV', (bool) preg_match( $scm[1], $cwv ), false );

if ( $failed > 0 ) {
	fwrite( STDERR, "\nmodule5 log line harness FAILED ({$failed} case(s))\n" );
	exit( 1 );
}
echo "\nmodule5 log line harness passed\n";
