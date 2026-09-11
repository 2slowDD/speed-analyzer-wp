<?php
// Lifts the production bodies of wpsa_check_quota() and
// wpsa_license_unverified_snapshot() out of includes/helpers.php so the harness
// exercises the shipped code, not a copy. Pattern: tests/pdf-quota-harness.php:63-72.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/helpers.php' );

foreach ( array( 'wpsa_check_quota', 'wpsa_license_unverified_snapshot', 'wpsa_get_license_tier' ) as $fn ) {
    if ( ! preg_match( '/function\s+' . $fn . '\s*\((?P<args>[^)]*)\)\s*\{(?P<body>.*?)
\}/s', $source, $m ) ) {
        fwrite( STDERR, "Could not extract $fn from includes/helpers.php
" );
        exit( 1 );
    }
    eval( 'function ' . $fn . '(' . $m['args'] . ') {' . $m['body'] . "
}" );
}

// wpsa_maybe_migrate_license_options() lives in the main plugin file, not
// includes/helpers.php, so it is extracted from its own source rather than
// $source above.
$main_source = file_get_contents( dirname( __DIR__ ) . '/wp-speed-analyzer.php' );

// WPSA_LICENSE_OPTIONS_VERSION is a file-scope `const` in production, sitting
// just above the function. A function-body-only extraction can't see it, so
// it is stubbed here at the same value — kept in sync with production by hand,
// the same way WPSA_GATEKEEPER_URL is stubbed above.
if ( ! defined( 'WPSA_LICENSE_OPTIONS_VERSION' ) ) {
    define( 'WPSA_LICENSE_OPTIONS_VERSION', '1.19.1' );
}

foreach ( array( 'wpsa_maybe_migrate_license_options' ) as $fn ) {
    if ( ! preg_match( '/function\s+' . $fn . '\s*\((?P<args>[^)]*)\)\s*\{(?P<body>.*?)
\}/s', $main_source, $m ) ) {
        fwrite( STDERR, "Could not extract $fn from wp-speed-analyzer.php
" );
        exit( 1 );
    }
    eval( 'function ' . $fn . '(' . $m['args'] . ') {' . $m['body'] . "
}" );
}

// wpsa_license_over_cap_notice() (D16, Task B4) lives in includes/lpanel.php,
// not includes/helpers.php, so it is extracted from its own source — same
// precedent as wpsa_maybe_migrate_license_options() above. Its closing `}`
// is at column 0 in production so the same extraction regex can find it.
$lpanel_source = file_get_contents( dirname( __DIR__ ) . '/includes/lpanel.php' );

foreach ( array( 'wpsa_license_over_cap_notice' ) as $fn ) {
    if ( ! preg_match( '/function\s+' . $fn . '\s*\((?P<args>[^)]*)\)\s*\{(?P<body>.*?)
\}/s', $lpanel_source, $m ) ) {
        fwrite( STDERR, "Could not extract $fn from includes/lpanel.php
" );
        exit( 1 );
    }
    eval( 'function ' . $fn . '(' . $m['args'] . ') {' . $m['body'] . "
}" );
}

// wpsa_schedule_result_icon() picks the scheduled-test email's icon for each
// result. It lives in includes/schedule.php and is pure, so it is lifted and
// run the same way. As above, eval() here only ever sees this repo's own
// source read from disk, in a CLI test: never user or network input. Its
// closing `}` is at column 0 and it holds no closure, which this regex needs.
$schedule_source = file_get_contents( dirname( __DIR__ ) . '/includes/schedule.php' );

foreach ( array( 'wpsa_schedule_result_icon' ) as $fn ) {
    if ( ! preg_match( '/function\s+' . $fn . '\s*\((?P<args>[^)]*)\)\s*\{(?P<body>.*?)\n\}/s', $schedule_source, $m ) ) {
        fwrite( STDERR, "Could not extract $fn from includes/schedule.php\n" );
        exit( 1 );
    }
    eval( 'function ' . $fn . '(' . $m['args'] . ') {' . $m['body'] . "\n}" );
}

// wpsa_get_local_quota_snapshot() is called on the no-key path only; stub it.
// Mirrors production includes/helpers.php:1313 exactly: the tier comes from
// the real (extracted, above) wpsa_get_license_tier() rather than reading
// wpsa_saved_tier directly, so the D10 expiry-demotion rule is exercised
// through this stub too instead of bypassed by it (Task B6).
function wpsa_get_local_quota_snapshot( $operation ) {
    $tier = wpsa_get_license_tier(); // respects saved tier + expiration
    $lim  = ( 'pdf' === $operation ) ? 1 : 10;
    return array( 'allowed' => true, 'tier' => $tier, 'limit' => $lim, 'remaining' => $lim );
}

// wpsa_tier_rank() is called by the legacy (no `v`) branch. It is declared
// inside `if ( ! function_exists(...) )` in production with the closing
// brace not at column 0, so the extraction regex above can't lift it
// cleanly; it is stable, unambiguous logic (not part of the state-machine
// contract under test here), so it is stubbed to match production exactly.
function wpsa_tier_rank( $tier ) {
    switch ( $tier ) {
        case 'premium3': return 3;
        case 'premium2': return 2;
        case 'premium1': return 1;
        default:         return 0; // free/unknown
    }
}
