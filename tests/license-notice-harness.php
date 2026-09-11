<?php
declare( strict_types=1 );
require __DIR__ . '/../includes/license-notice.php';   // pure helpers only, guarded by function_exists

$fails = 0;
function ok( $n, $a, $e ) { global $fails; if ( $a !== $e ) { $fails++; printf( "FAIL %s expected %s actual %s\n", $n, var_export( $e, true ), var_export( $a, true ) ); } }

// AC-N1 thresholds
ok( 'AC-N1 11d silent', wpsa_license_notice_should_show( 'active', 11, null ), false );
ok( 'AC-N1 10d shows',  wpsa_license_notice_should_show( 'active', 10, null ), true );
ok( 'AC-N1 3d shows',   wpsa_license_notice_should_show( 'active', 3, null ),  true );

// AC-N2 dismissal at 8d suppresses at 6d, re-shows at 3d
ok( 'AC-N2 suppressed at 6d', wpsa_license_notice_should_show( 'active', 6, 8 ), false );
ok( 'AC-N2 re-shows at 3d',   wpsa_license_notice_should_show( 'active', 3, 8 ), true );

// AC-N3 grace is never dismissible; AC-N6 sold is never dismissible.
// expired/invalid pinned too (r-B5F2) -- mutation-proved: dropping either
// from the exclusion array in wpsa_license_notice_is_dismissible() left the
// suite green until these two were added.
ok( 'AC-N3 grace not dismissible',    wpsa_license_notice_is_dismissible( 'grace' ),    false );
ok( 'r-B5F2 expired not dismissible', wpsa_license_notice_is_dismissible( 'expired' ),  false );
ok( 'r-B5F2 invalid not dismissible', wpsa_license_notice_is_dismissible( 'invalid' ),  false );
ok( 'AC-N6 sold not dismissible',     wpsa_license_notice_is_dismissible( 'sold' ),     false );
ok( 'active is dismissible',          wpsa_license_notice_is_dismissible( 'active' ),   true );

// AC-N5 the migration state never nags
ok( 'AC-N5 unknown silent', wpsa_license_notice_should_show( 'unknown', null, null ), false );
ok( 'free silent',          wpsa_license_notice_should_show( 'free', null, null ),    false );

// non-active states always show
foreach ( array( 'grace', 'expired', 'invalid', 'sold' ) as $s ) {
    ok( "$s shows", wpsa_license_notice_should_show( $s, null, 1 ), true );
}

// --- Additional coverage below (B5 implementer additions) ---

// AC-N4: the screen gate must reuse the exact literal wp-speed-analyzer.php
// already gates its own asset loading on ('tools_page_speed-analyzer' !==
// $hook, admin_enqueue_scripts) — a drift guard, not just a value check.
ok( 'AC-N4 screen literal', WPSA_LICENSE_NOTICE_SCREEN, 'tools_page_speed-analyzer' );
$main_plugin_src = file_get_contents( __DIR__ . '/../wp-speed-analyzer.php' );
ok(
    'AC-N4 screen literal still present in main file (drift guard)',
    false !== strpos( $main_plugin_src, "'tools_page_speed-analyzer'" ),
    true
);

// wpsa_license_notice_days_left(): pure date math, $now pinned so the test
// does not race the clock.
$jan1_2027 = strtotime( '2027-01-01 00:00:00 UTC' );
ok( 'days_left 10 days out',   wpsa_license_notice_days_left( '2027-01-11', $jan1_2027 ), 10 );
ok( 'days_left exactly today', wpsa_license_notice_days_left( '2027-01-01', $jan1_2027 ), 0 );
ok( 'days_left 4 days past (grace)', wpsa_license_notice_days_left( '2026-12-28', $jan1_2027 ), -4 );
ok( 'days_left empty is null', wpsa_license_notice_days_left( '', $jan1_2027 ), null );
ok( 'days_left unparseable is null', wpsa_license_notice_days_left( 'not-a-date', $jan1_2027 ), null );

// wpsa_license_notice_grace_days_left(): D4's 7-day courtesy window, clamped at 0.
ok( 'grace days_left 4d past expiry -> 3 remaining', wpsa_license_notice_grace_days_left( -4 ), 3 );
ok( 'grace days_left 7d past expiry -> 0 remaining (clamped)', wpsa_license_notice_grace_days_left( -7 ), 0 );
ok( 'grace days_left 10d past expiry -> 0 remaining (clamped, not negative)', wpsa_license_notice_grace_days_left( -10 ), 0 );
ok( 'grace days_left null propagates', wpsa_license_notice_grace_days_left( null ), null );

// wpsa_license_notice_template(): copy/button selection, mirrors lpanel.php §5.3.
ok( 'template active',  wpsa_license_notice_template( 'active', '' ),  array( 'template' => 'active',  'button' => 'renew' ) );
ok( 'template grace',   wpsa_license_notice_template( 'grace', '' ),   array( 'template' => 'grace',   'button' => 'renew' ) );
ok( 'template expired', wpsa_license_notice_template( 'expired', '' ), array( 'template' => 'expired', 'button' => 'renew' ) );
ok( 'template invalid (generic reason)',    wpsa_license_notice_template( 'invalid', 'inactive' ),  array( 'template' => 'invalid',           'button' => 'renew' ) );
ok( 'template invalid (disabled reason)',   wpsa_license_notice_template( 'invalid', 'disabled' ),  array( 'template' => 'invalid',           'button' => 'renew' ) );
// r-B5F1: a mistyped key is a typo, not a lapse (spec §5.3 line 357) -- no
// action button at all, matching lpanel.php's own no-button treatment of
// this row. Distinguished from the two 'renew' assertions directly above.
ok( 'r-B5F1 template invalid (not_found reason) -> no button', wpsa_license_notice_template( 'invalid', 'not_found' ), array( 'template' => 'invalid_not_found', 'button' => 'none' ) );
// AC-N6: sold gets Contact and never Renew.
ok( 'AC-N6 template sold -> contact, never renew', wpsa_license_notice_template( 'sold', '' ), array( 'template' => 'sold', 'button' => 'contact' ) );

// P17 — production activation path: prove the real days-left computation
// (not a hand-picked int) correctly feeds wpsa_license_notice_should_show().
// If someone broke wpsa_license_notice_days_left()'s off-by-one (e.g. used
// ceil() instead of floor(), or compared local time instead of UTC calendar
// dates), the pure should_show() unit tests above would still pass — only a
// round-trip through the real emitter catches it.
$exp_11d = gmdate( 'Y-m-d', $jan1_2027 + 11 * 86400 );
$exp_10d = gmdate( 'Y-m-d', $jan1_2027 + 10 * 86400 );
$exp_3d  = gmdate( 'Y-m-d', $jan1_2027 + 3 * 86400 );
ok(
    'wired AC-N1 11d silent (real expiration -> days_left -> should_show)',
    wpsa_license_notice_should_show( 'active', wpsa_license_notice_days_left( $exp_11d, $jan1_2027 ), null ),
    false
);
ok(
    'wired AC-N1 10d shows (real expiration -> days_left -> should_show)',
    wpsa_license_notice_should_show( 'active', wpsa_license_notice_days_left( $exp_10d, $jan1_2027 ), null ),
    true
);
ok(
    'wired AC-N2 3d re-shows after an 8d dismissal (real expiration -> days_left -> should_show)',
    wpsa_license_notice_should_show( 'active', wpsa_license_notice_days_left( $exp_3d, $jan1_2027 ), 8 ),
    true
);

if ( $fails ) { fwrite( STDERR, "$fails check(s) failed\n" ); exit( 1 ); }
echo "license notice harness passed\n";
