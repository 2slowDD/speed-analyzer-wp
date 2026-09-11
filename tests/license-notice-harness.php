<?php
declare( strict_types=1 );
date_default_timezone_set( 'UTC' ); // WordPress runs PHP in UTC; the date checks below assume the same.

// WordPress defines ABSPATH before it loads a plugin file, and the notice file exits
// without it. An exit inside the require would end this run with status 0 and no
// output, which the suite runner scores as a pass, so the shutdown check turns any
// run that stops before the end marker at the bottom into a failure.
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['notice_reached_end'] = false;
register_shutdown_function( function () {
    if ( empty( $GLOBALS['notice_reached_end'] ) ) {
        fwrite( STDERR, "FAIL license notice harness stopped before its end marker (exit, die or a fatal error)\n" );
        exit( 1 );
    }
} );
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

// The states that need the customer's attention are never dismissible: grace,
// expired, not_found, inactive, disabled and sold. Each has its own check here,
// so dropping any one of them from the list in
// wpsa_license_notice_is_dismissible() turns that state's check red.
ok( 'AC-N3 grace not dismissible',    wpsa_license_notice_is_dismissible( 'grace' ),    false );
ok( 'r-B5F2 expired not dismissible', wpsa_license_notice_is_dismissible( 'expired' ),  false );
ok( 'not_found not dismissible', wpsa_license_notice_is_dismissible( 'not_found' ), false );
ok( 'inactive not dismissible',  wpsa_license_notice_is_dismissible( 'inactive' ),  false );
ok( 'disabled not dismissible',  wpsa_license_notice_is_dismissible( 'disabled' ),  false );
ok( 'AC-N6 sold not dismissible',     wpsa_license_notice_is_dismissible( 'sold' ),     false );
ok( 'active is dismissible',          wpsa_license_notice_is_dismissible( 'active' ),   true );

// AC-N5 the migration state never nags
ok( 'AC-N5 unknown silent', wpsa_license_notice_should_show( 'unknown', null, null ), false );
ok( 'free silent',          wpsa_license_notice_should_show( 'free', null, null ),    false );

// non-active states always show
foreach ( array( 'grace', 'expired', 'not_found', 'inactive', 'disabled', 'sold' ) as $s ) {
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

// wpsa_license_notice_template(): copy/button selection, mirrors lpanel.php §5.3.
ok( 'template active',  wpsa_license_notice_template( 'active' ),  array( 'template' => 'active',  'button' => 'renew' ) );
ok( 'template grace',   wpsa_license_notice_template( 'grace' ),   array( 'template' => 'grace',   'button' => 'renew' ) );
ok( 'template expired', wpsa_license_notice_template( 'expired' ), array( 'template' => 'expired', 'button' => 'renew' ) );
ok( 'template inactive -> invalid, renew', wpsa_license_notice_template( 'inactive' ), array( 'template' => 'invalid', 'button' => 'renew' ) );
ok( 'template disabled -> invalid, renew', wpsa_license_notice_template( 'disabled' ), array( 'template' => 'invalid', 'button' => 'renew' ) );
// r-B5F1: a mistyped key is a typo, not a lapse (spec §5.3 line 357) -- no
// action button at all, matching lpanel.php's own no-button treatment of
// this row. Distinguished from the two 'renew' assertions directly above.
ok( 'template not_found -> no button', wpsa_license_notice_template( 'not_found' ), array( 'template' => 'invalid_not_found', 'button' => 'none' ) );
// AC-N6: sold gets Contact and never Renew.
ok( 'AC-N6 template sold -> contact, never renew', wpsa_license_notice_template( 'sold' ), array( 'template' => 'sold', 'button' => 'contact' ) );

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

// Every state the service sends, from decide()'s own answers (tests/_license-display-fixtures.json).
$fx     = json_decode( (string) file_get_contents( __DIR__ . '/_license-display-fixtures.json' ), true );
$states = array();
foreach ( $fx['cases'] as $c ) {
    $states[ $c['answer']['state'] ] = true;
}
$expect = array(
    // state => array( shows with no date and no dismissal, dismissible, template, button )
    'sold'      => array( true,  false, 'sold',              'contact' ),
    'not_found' => array( true,  false, 'invalid_not_found', 'none' ),
    'inactive'  => array( true,  false, 'invalid',           'renew' ),
    'disabled'  => array( true,  false, 'invalid',           'renew' ),
    'grace'     => array( true,  false, 'grace',             'renew' ),
    'expired'   => array( true,  false, 'expired',           'renew' ),
    'active'    => array( false, true,  'active',            'renew' ), // shows only within 10 days (the 10-day checks at the top of this file)
    'free'      => array( false, true,  null,                null ),
);
ok( 'the fixture carries all eight states', count( $states ), 8 );
foreach ( array_keys( $states ) as $s ) {
    ok( "$s is a state this notice knows", isset( $expect[ $s ] ), true );
    if ( ! isset( $expect[ $s ] ) ) {
        continue;
    }
    list( $shows, $dismissible, $tpl, $btn ) = $expect[ $s ];
    ok( "$s shows (no date, never dismissed)", wpsa_license_notice_should_show( $s, null, null ), $shows );
    ok( "$s dismissible", wpsa_license_notice_is_dismissible( $s ), $dismissible );
    if ( null !== $tpl ) {
        ok( "$s template", wpsa_license_notice_template( $s ), array( 'template' => $tpl, 'button' => $btn ) );
    }
}
ok( "'' never shows", wpsa_license_notice_should_show( '', null, null ), false );
// 'unknown' (what an upgrade leaves until the first check) and '' are the two states
// the fixture does not carry. Neither shows a notice; if one ever did, the customer
// could close it.
ok( 'unknown is dismissible', wpsa_license_notice_is_dismissible( 'unknown' ), true );
ok( "'' is dismissible",      wpsa_license_notice_is_dismissible( '' ),        true );

// The notice file's first statement is its direct-access guard, in a shape the
// WordPress.org plugin checker recognises: `defined( 'ABSPATH' ) || exit;` or
// `if ( ! defined( 'ABSPATH' ) ) { exit; }`. The checker reports a guard with any
// extra condition (one that lets the command line through for tests, say) as
// missing, and a guard placed after other code no longer stops a direct request
// before that code runs.
$notice_code = '';
foreach ( token_get_all( (string) file_get_contents( __DIR__ . '/../includes/license-notice.php' ) ) as $t ) {
    if ( is_array( $t ) && in_array( $t[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
        continue;
    }
    $notice_code .= is_array( $t ) ? $t[1] : $t;
}
ok( 'the notice file starts with a direct-access guard the plugin checker recognises',
    0 === strpos( $notice_code, "defined('ABSPATH')||exit;" ) || 0 === strpos( $notice_code, "if(!defined('ABSPATH')){exit;}" ),
    true );

// End marker, set before either exit path below so the shutdown check stays silent on
// a run that reached here, whether it passed or failed.
$GLOBALS['notice_reached_end'] = true;

if ( $fails ) { fwrite( STDERR, "$fails check(s) failed\n" ); exit( 1 ); }
echo "license notice harness passed\n";
