<?php
/**
 * Harness: wpsa_debug_log() must stay silent unless the site owner has opted in
 * via BOTH WP_DEBUG and WP_DEBUG_LOG.
 *
 * This exercises the real production path: the function is called with only a
 * message, so the constant lookups inside it actually execute. There is no
 * injected flag or override argument that could make the test pass while the
 * real gate is broken.
 *
 * Run: php tests/debug-log-gate-harness.php
 */

$root = dirname( __DIR__ );
$src  = file_get_contents( $root . '/helpers.php' );
// helpers.php ships CRLF; normalise so the extraction regex is line-ending agnostic.
$src  = str_replace( array( "\r\n", "\r" ), "\n", $src );

// Pull the real function body out of helpers.php (the file itself needs WordPress).
if ( ! preg_match( '/\n(\s*function wpsa_debug_log\s*\(.*?\n    \}\n)/s', $src, $m ) ) {
    fwrite( STDERR, "FAIL: could not locate wpsa_debug_log() in helpers.php\n" );
    exit( 1 );
}
$fn_source = $m[1];

if ( strpos( $fn_source, 'WP_DEBUG_LOG' ) === false || strpos( $fn_source, 'WP_DEBUG' ) === false ) {
    fwrite( STDERR, "FAIL: wpsa_debug_log() no longer consults WP_DEBUG / WP_DEBUG_LOG\n" );
    exit( 1 );
}

/**
 * Runs the extracted function in a fresh PHP process under a given constant
 * combination and reports whether anything reached the error log.
 */
function wpsa_probe( $fn_source, $debug, $debug_log ) {
    $log  = tempnam( sys_get_temp_dir(), 'wpsalog' );
    $stub = tempnam( sys_get_temp_dir(), 'wpsafn' ) . '.php';

    $prelude  = "<?php\n";
    $prelude .= $debug     === null ? '' : "define('WP_DEBUG', " . ( $debug ? 'true' : 'false' ) . ");\n";
    $prelude .= $debug_log === null ? '' : "define('WP_DEBUG_LOG', " . ( $debug_log ? 'true' : 'false' ) . ");\n";
    $prelude .= "ini_set('log_errors','1');\n";
    $prelude .= "ini_set('error_log'," . var_export( $log, true ) . ");\n";
    $prelude .= $fn_source . "\n";
    $prelude .= "wpsa_debug_log('CANARY-MESSAGE');\n";

    file_put_contents( $stub, $prelude );
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $stub ) . ' 2>&1', $out, $rc );

    $written = file_exists( $log ) ? file_get_contents( $log ) : '';
    @unlink( $stub );
    @unlink( $log );

    return array(
        'logged' => strpos( $written, 'CANARY-MESSAGE' ) !== false,
        'rc'     => $rc,
        'out'    => implode( "\n", (array) $out ),
    );
}

$cases = array(
    // label,                        WP_DEBUG, WP_DEBUG_LOG, expect logged
    array( 'both undefined',         null,  null,  false ),
    array( 'WP_DEBUG off',           false, true,  false ),
    array( 'WP_DEBUG on, LOG off',   true,  false, false ),
    array( 'WP_DEBUG on, LOG undef', true,  null,  false ),
    array( 'both on (opted in)',     true,  true,  true  ),
);

$failed = 0;
foreach ( $cases as $case ) {
    list( $label, $debug, $debug_log, $expect ) = $case;
    $r = wpsa_probe( $fn_source, $debug, $debug_log );

    if ( 0 !== $r['rc'] ) {
        printf( "  FAIL  %-24s php exited %d: %s\n", $label, $r['rc'], $r['out'] );
        $failed++;
        continue;
    }
    if ( $r['logged'] !== $expect ) {
        printf(
            "  FAIL  %-24s expected logged=%s, got logged=%s\n",
            $label,
            $expect ? 'yes' : 'no',
            $r['logged'] ? 'yes' : 'no'
        );
        $failed++;
        continue;
    }
    printf( "  ok    %-24s logged=%s\n", $label, $r['logged'] ? 'yes' : 'no' );
}

if ( $failed > 0 ) {
    fwrite( STDERR, "debug log gate harness FAILED ({$failed} case(s))\n" );
    exit( 1 );
}
echo "debug log gate harness passed\n";
