<?php
/**
 * Focused harness for wpsa_check_quota()'s state machine.
 * Runs outside WordPress by extracting the production function body and
 * stubbing only what it calls. The HTTP stub returns a JSON STRING so the
 * real decoding path executes (P17 — no injected arrays).
 */
declare( strict_types=1 );
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }
date_default_timezone_set( 'UTC' ); // WordPress runs PHP in UTC; the date checks below assume the same.

// ── Early-termination guard + assertion-count gate (fix-round 5).
//
// Two failure modes this file could not previously see, BOTH of which report
// success:
//
//   1. `exit;` or `die()` inside one of the eval'd production snippets ends
//      this process with status 0, no output and no assertion count — and the
//      suite runner (`php "$f" || echo "FAIL $f"`) scores exit 0 as a pass.
//      Round 4's try/catch( Throwable ) cannot help: exit and die are
//      language constructs, not exceptions. That is the one cost the move
//      from source inspection to execution imported, and it is not a
//      one-assertion miss — it converts EVERY later assertion in the run into
//      a pass too. Reachability is narrow: the realistic refactor
//      (`wp_safe_redirect( … ); exit;`) is already caught, because
//      wp_safe_redirect() is undefined in this harness and throws first. The
//      silent path needs a bare `exit;`/`die()` with no intervening undefined
//      WP call — i.e. exactly the shape of an ordinary guard clause.
//   2. The printed assertion count was a manual eyeball. Nothing in the file
//      compared it to anything, so a run that skipped a whole block still
//      printed "passed" — with a smaller number nobody was checking.
//
// The shutdown function below asserts the script reached its own end marker;
// the gate at the bottom of the file asserts the final count is exactly
// FR_EXPECTED_CHECKS. Between them, any early termination (exit, die, or a
// fatal) and any silent drift in coverage becomes a non-zero exit with a
// named message. Verified against PHP 8.5.1: calling exit( 1 ) from inside a
// shutdown function overrides the status 0 that a bare `exit;`/`die()` set.
//
// ▲ WHEN YOU LEGITIMATELY ADD OR REMOVE AN ASSERTION, UPDATE THIS NUMBER. ▲
// It is the only place the expected count is written down.
define( 'FR_EXPECTED_CHECKS', 258 );

$GLOBALS['fr_reached_end'] = false;
register_shutdown_function( function () {
    if ( ! empty( $GLOBALS['fr_reached_end'] ) ) {
        return; // Normal end, pass or fail — the run already reported for itself.
    }
    global $checks;
    $err   = error_get_last();
    $fatal = ( is_array( $err ) && in_array( $err['type'],
            array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) )
        ? sprintf( "\n  Last PHP error: %s in %s:%d", $err['message'], $err['file'], (int) $err['line'] )
        : '';
    fwrite( STDERR, sprintf(
        "FAIL harness terminated before its end marker (exit / die / fatal inside the run)\n"
        . "  %d of %d expected assertion(s) had run; every later assertion never executed.\n"
        . "  Without this guard PHP would have exited 0 with no output and the suite\n"
        . "  runner would have scored this run a PASS.%s\n",
        (int) $checks, FR_EXPECTED_CHECKS, $fatal ) );
    exit( 1 );
} );

define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WPSA_GATEKEEPER_URL', 'https://gk.test' );
// WordPress defines ABSPATH before it loads a plugin file; includes/license-notice.php
// exits without it, which the shutdown check above reports as a failed run.
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['opts'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array( 'code' => 200, 'body' => '', 'error' => false );

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $v ) ); }
function esc_url_raw( $v ) { return $v; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function home_url() { return 'https://example.com'; }
function is_wp_error( $t ) { return $t instanceof WP_Error_Stub; }
function wp_remote_get( $url, $args = array() ) {
    if ( $GLOBALS['http']['error'] ) { return new WP_Error_Stub(); }
    return array( 'code' => $GLOBALS['http']['code'], 'body' => $GLOBALS['http']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) $r['body'] : ''; }
class WP_Error_Stub {}
function wpsa_get_pdf_usage() { return 0; }
// Simplified WP _n(): the 'speed-analyzer' domain has no MO file loaded in
// this CLI harness, which is exactly how WP's own NOOP translations object
// behaves for an untranslated domain — standard English pluralization.
function _n( $single, $plural, $number, $domain = 'default' ) { return ( 1 === (int) $number ) ? $single : $plural; }
function wpsa_get_daily_usage_record() { return array( 'count' => 0 ); }

require __DIR__ . '/_license-state-subject.php'; // extracted production functions

function body( array $over = array() ) {
    return wp_json_encode( array_merge( array(
        'v' => 4, 'status' => 'ok', 'fetched_at' => gmdate( 'c' ), 'age_s' => 0,
        'tier' => 'premium3', 'state' => 'active',
        'allowed' => true, 'limit' => 700, 'remaining' => 700,
        'expires_at' => null, 'days_left' => null, 'grace_until' => null,
        'sites' => array( 'max' => 100, 'used' => 1, 'remaining' => 99, 'active' => true ),
    ), $over ) );
}
function wp_json_encode( $v ) { return json_encode( $v ); }

$fails  = 0;
$checks = 0; // fix-round 3: a run "passing" with fewer assertions than
             // expected (e.g. because eval() of a truncated snippet fataled
             // partway through and skipped everything after it) IS a failure
             // mode here, so the count is printed on every run rather than
             // trusting the exit code alone.
             // fix-round 5 correction: PRINTING it was not enough. The print
             // happens at the end of the file, which an exit/die inside an
             // eval'd snippet never reaches, and nothing compared the number
             // to anything anyway. The FR_EXPECTED_CHECKS gate and the
             // shutdown guard at the top of this file are what actually make
             // a short run fail.
function ok( $name, $actual, $expected ) {
    global $fails, $checks;
    $checks++;
    if ( $actual !== $expected ) {
        $fails++;
        printf( "FAIL %s\n  expected %s\n  actual   %s\n", $name,
            var_export( $expected, true ), var_export( $actual, true ) );
    }
}
function reset_state( array $opts = array() ) {
    $GLOBALS['opts'] = array_merge( array( 'wpsa_license_key' => 'PREKEY' ), $opts );
    $GLOBALS['transients'] = array();
    $GLOBALS['http'] = array( 'code' => 200, 'body' => body(), 'error' => false );
}

// AC-P1 confirmed free downgrades, but the expiry SURVIVES
reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_expiration' => '2027-01-01' ) );
$GLOBALS['http']['body'] = body( array( 'tier' => 'free', 'state' => 'disabled', 'expires_at' => null ) );
$r = wpsa_check_quota( 'ttfb' );
ok( 'AC-P1 tier', $r['tier'], 'free' );
ok( 'AC-P1 last_paid', get_option( 'wpsa_last_paid_tier' ), 'premium3' );
ok( 'AC-P1 expiry retained', get_option( 'wpsa_license_expiration' ), '2027-01-01' );

// AC-P2 503 unverified with a known future expiry -> tier HELD, no writes but status
reset_state( array( 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS ) ) );
$GLOBALS['http']['code'] = 503;
$GLOBALS['http']['body'] = body( array( 'status' => 'unverified', 'tier' => 'free', 'state' => 'not_found' ) );
$r = wpsa_check_quota( 'ttfb' );
ok( 'AC-P2 tier held', $r['tier'], 'premium3' );
ok( 'AC-P2 saved_tier untouched', get_option( 'wpsa_saved_tier' ), 'premium3' );
ok( 'AC-P2 status recorded', get_option( 'wpsa_license_status' ), 'unverified' );

// Unverified, with only an expiry stored and that date ten days past: the hold has ended -> free
reset_state( array( 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ) ) );
$GLOBALS['http']['code'] = 503;
$GLOBALS['http']['body'] = body( array( 'status' => 'unverified', 'tier' => 'free' ) );
ok( 'AC-P3 bound exceeded', wpsa_check_quota( 'ttfb' )['tier'], 'free' );

// AC-P4 perpetual, last_verified 20d old -> free (14d cap)
reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_expiration' => '',
                    'wpsa_license_last_verified' => time() - 20 * DAY_IN_SECONDS ) );
$GLOBALS['http']['code'] = 503;
$GLOBALS['http']['body'] = body( array( 'status' => 'unverified', 'tier' => 'free' ) );
ok( 'AC-P4 14d cap', wpsa_check_quota( 'ttfb' )['tier'], 'free' );

// AC-P5 transport error with a cached premium3 transient -> premium3 (F3 fixed)
reset_state( array( 'wpsa_saved_tier' => 'premium3' ) );
$GLOBALS['transients']['wpsa_gk_quota_ttfb'] = array(
    'allowed' => true, 'tier' => 'premium3', 'limit' => 700, 'remaining' => 700,
    'state' => 'active', 'status' => 'ok',
);
$GLOBALS['http']['error'] = true;
ok( 'AC-P5 cached paid honoured', wpsa_check_quota( 'ttfb' )['tier'], 'premium3' );

// AC-P6 legacy body (no v) never downgrades, but may upgrade
reset_state( array( 'wpsa_saved_tier' => 'premium3' ) );
$GLOBALS['http']['body'] = json_encode( array( 'allowed' => true, 'tier' => 'free', 'limit' => 10, 'remaining' => 10 ) );
ok( 'AC-P6 legacy no downgrade', wpsa_check_quota( 'ttfb' )['tier'], 'premium3' );
reset_state( array( 'wpsa_saved_tier' => 'free' ) );
$GLOBALS['http']['body'] = json_encode( array( 'allowed' => true, 'tier' => 'premium3', 'limit' => 700, 'remaining' => 700 ) );
ok( 'AC-P6 legacy upgrade ok', wpsa_check_quota( 'ttfb' )['tier'], 'premium3' );

// AC-P7 stale is authoritative
reset_state( array( 'wpsa_saved_tier' => 'free' ) );
$GLOBALS['http']['body'] = body( array( 'status' => 'stale' ) );
$r = wpsa_check_quota( 'ttfb' );
ok( 'AC-P7 stale syncs', $r['tier'], 'premium3' );
ok( 'AC-P7 status stored', get_option( 'wpsa_license_status' ), 'stale' );

// AC-P9 last_verified comes from fetched_at, NOT receipt time
reset_state( array( 'wpsa_saved_tier' => 'free' ) );
$six_days_ago = gmdate( 'c', time() - 6 * DAY_IN_SECONDS );
$GLOBALS['http']['body'] = body( array( 'status' => 'stale', 'fetched_at' => $six_days_ago ) );
wpsa_check_quota( 'ttfb' );
ok( 'AC-P9 anchored to fetched_at',
    abs( (int) get_option( 'wpsa_license_last_verified' ) - ( time() - 6 * DAY_IN_SECONDS ) ) < 120, true );

// AC-P12 a sold answer does not write last_paid_tier
reset_state( array( 'wpsa_saved_tier' => 'free' ) );
$GLOBALS['http']['body'] = body( array( 'tier' => 'free', 'state' => 'sold' ) );
wpsa_check_quota( 'ttfb' );
ok( 'AC-P12 state', get_option( 'wpsa_license_state' ), 'sold' );
ok( 'AC-P12 no last_paid', get_option( 'wpsa_last_paid_tier' ), false );

// AC-P15 an out-of-contract 200 carrying status:'unverified' must NOT be
// treated as authoritative. The 503 check cannot catch this one, so this AC
// is what makes the status guard load-bearing rather than decorative.
reset_state( array( 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS ) ) );
$GLOBALS['http']['code'] = 200;                       // NOTE: 200, not 503
$GLOBALS['http']['body'] = body( array( 'status' => 'unverified', 'tier' => 'free', 'state' => 'not_found' ) );
$r = wpsa_check_quota( 'ttfb' );
ok( 'AC-P15 tier held on 200+unverified', $r['tier'], 'premium3' );
ok( 'AC-P15 saved_tier untouched', get_option( 'wpsa_saved_tier' ), 'premium3' );

// And the same shape with an unrecognised status value.
reset_state( array( 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS ) ) );
$GLOBALS['http']['body'] = body( array( 'status' => 'weird', 'tier' => 'free' ) );
ok( 'AC-P15 unknown status is not authoritative', wpsa_check_quota( 'ttfb' )['tier'], 'premium3' );

// AC-P16 a confirmed perpetual (active, no expires_at) clears a stale date...
reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_expiration' => '2027-01-01' ) );
$GLOBALS['http']['body'] = body( array( 'state' => 'active', 'expires_at' => null ) );
wpsa_check_quota( 'ttfb' );
ok( 'AC-P16 perpetual clears stale date', get_option( 'wpsa_license_expiration' ), '' );
// ...but an answer without an expires_at still retains it, as the first checks in this file also show.
reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_expiration' => '2027-01-01' ) );
$GLOBALS['http']['body'] = body( array( 'tier' => 'free', 'state' => 'disabled', 'expires_at' => null ) );
wpsa_check_quota( 'ttfb' );
ok( 'AC-P16 free answer retains date', get_option( 'wpsa_license_expiration' ), '2027-01-01' );

// ── Answers the write block must not trust leave the stored licence alone.
//
// Each case below feeds a raw body through the HTTP stub, so the real decoding
// path runs, and then checks three things:
//   * the four values an authoritative write would change are unchanged;
//   * the answer went to the unverified snapshot, whose one write is the status;
//   * PHP raised no warning or notice. A body the write block reads blindly
//     shows up first as "Undefined array key".
function fr_run_untrusted( $name, array $start, $code, $raw_body ) {
    reset_state( $start );
    $GLOBALS['http']['code'] = $code;
    $GLOBALS['http']['body'] = $raw_body;
    $keys   = array( 'wpsa_saved_tier', 'wpsa_last_paid_tier', 'wpsa_license_expiration', 'wpsa_license_state' );
    $before = array();
    foreach ( $keys as $k ) {
        $before[ $k ] = get_option( $k );
    }
    $raised = array();
    set_error_handler( function ( $errno, $errstr ) use ( &$raised ) {
        $raised[] = $errstr;
        return true;
    } );
    try {
        $r = wpsa_check_quota( 'ttfb' );
    } finally {
        restore_error_handler();
    }
    foreach ( $keys as $k ) {
        ok( "$name: $k unchanged", get_option( $k ), $before[ $k ] );
    }
    ok( "$name: answered by the unverified snapshot",
        array( is_array( $r ) ? ( $r['status'] ?? null ) : null, get_option( 'wpsa_license_status' ) ),
        array( 'unverified', 'unverified' ) );
    ok( "$name: no PHP warning or notice", $raised, array() );
}
$fr_paid = array(
    'wpsa_saved_tier'         => 'premium3',
    'wpsa_last_paid_tier'     => 'premium3',
    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS ),
    'wpsa_license_state'      => 'active',
    'wpsa_license_status'     => 'ok',
);

// An answer missing any one of the four fields the write reads. The answer
// otherwise says "confirmed free", so an unguarded write would downgrade.
foreach ( array( 'allowed', 'tier', 'limit', 'remaining' ) as $fr_field ) {
    $fr_b = json_decode( body( array( 'tier' => 'free', 'state' => 'disabled' ) ), true );
    unset( $fr_b[ $fr_field ] );
    fr_run_untrusted( "Malformed answer, no '$fr_field'", $fr_paid, 200, json_encode( $fr_b ) );
}
// A tier that is present but null, and one this plugin has never heard of.
fr_run_untrusted( 'Malformed answer, tier null', $fr_paid, 200, body( array( 'tier' => null ) ) );
fr_run_untrusted( "Malformed answer, tier 'premium9'", $fr_paid, 200, body( array( 'tier' => 'premium9' ) ) );

// A legacy body (no `v`) on HTTP 500 must not be taken as an upgrade. The site
// starts on Free so an upgrade would be visible.
fr_run_untrusted( 'Legacy body on HTTP 500', array( 'wpsa_saved_tier' => 'free' ), 500,
    json_encode( array( 'allowed' => true, 'tier' => 'premium3', 'limit' => 700, 'remaining' => 700 ) ) );

// The two legs of the status gate nothing else reaches: a body that is not
// JSON at all (an HTML error page on HTTP 200), and a well-formed answer on a
// code other than 200 or 503. That answer says "confirmed free", so taking it
// as authoritative would downgrade.
fr_run_untrusted( 'HTML error page on HTTP 200', $fr_paid, 200, '<html><body><h1>502 Bad Gateway</h1></body></html>' );
fr_run_untrusted( 'Well-formed answer on HTTP 500', $fr_paid, 500,
    body( array( 'tier' => 'free', 'state' => 'disabled' ) ) );

// ── Task B6 / D10 — deactivation honours the customer's remaining paid days,
// then falls back to free. wpsa_get_license_tier() must apply this ONLY when
// no licence key is stored; with a key present it must return the saved tier
// unconditionally, because the Gatekeeper is authority via wpsa_check_quota()
// on that path (controller Ruling 1 — the brief's D10 table, read literally,
// would demote every paying customer between upgrade and their first /check).
// wpsa_get_local_quota_snapshot() must inherit the rule rather than duplicate
// it (Ruling 2), so each row also asserts the snapshot's 'tier' matches.

// AC-D10-1 no key, expiry in the future -> paid tier kept
reset_state( array( 'wpsa_license_key' => '', 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ) );
ok( 'AC-D10-1 tier() keeps paid tier before expiry', wpsa_get_license_tier(), 'premium3' );
ok( 'AC-D10-1 snapshot() inherits it', wpsa_get_local_quota_snapshot( 'ttfb' )['tier'], 'premium3' );
// The licence panel prints its Expiration Date row from this answer alone, so the
// answer must carry the date those paid days run to; without it a deactivated site
// reads "No expiry" while it is still on a paid plan.
ok( 'AC-D10-1 snapshot() carries the date the paid days run to',
    wpsa_get_local_quota_snapshot( 'ttfb' )['expires_at'] ?? null,
    gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) );

// AC-D10-2 no key, expiry passed -> free
reset_state( array( 'wpsa_license_key' => '', 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ) ) );
ok( 'AC-D10-2 tier() falls to free past expiry', wpsa_get_license_tier(), 'free' );
ok( 'AC-D10-2 snapshot() inherits it', wpsa_get_local_quota_snapshot( 'ttfb' )['tier'], 'free' );
ok( 'AC-D10-2 snapshot() reports no date once the paid days have run out',
    wpsa_get_local_quota_snapshot( 'ttfb' )['expires_at'] ?? null, '' );

// AC-D10-3 no key, no expiry stored at all (perpetual key, deactivated) ->
// free immediately; nothing was paid for beyond the key itself.
reset_state( array( 'wpsa_license_key' => '', 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => '' ) );
ok( 'AC-D10-3 tier() no-expiry perpetual falls to free', wpsa_get_license_tier(), 'free' );
ok( 'AC-D10-3 snapshot() inherits it', wpsa_get_local_quota_snapshot( 'ttfb' )['tier'], 'free' );
ok( 'AC-D10-3 snapshot() reports no date when none was stored',
    wpsa_get_local_quota_snapshot( 'ttfb' )['expires_at'] ?? null, '' );

// AC-D10-4 (Ruling 1, the load-bearing guard) — a STORED KEY with no expiry
// must still return the paid tier. This is exactly the post-upgrade state
// (Task B3 deletes wpsa_license_expiration; key stays; tier is unknown until
// the first successful /check): if this AC is wrong, a paying Agency customer
// silently loses is_unlocked and PDF branding across all 8 call sites.
reset_state( array( 'wpsa_license_key' => 'PREKEY', 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_expiration' => '' ) );
ok( 'AC-D10-4 key present + no expiry still returns paid tier', wpsa_get_license_tier(), 'premium3' );

// The answer built when the licence service cannot be reached and nothing is cached
// reaches the same panel row, so it carries the date too.
$fr_unv_exp = gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS );
reset_state( array( 'wpsa_license_key' => 'PREKEY', 'wpsa_saved_tier' => 'premium1',
                    'wpsa_license_expiration' => $fr_unv_exp ) );
$fr_unv = wpsa_license_unverified_snapshot( 'ttfb', 'wpsa_gk_quota_ttfb' );
ok( 'Unreachable service, nothing cached: the paid tier and its date',
    array( $fr_unv['tier'], $fr_unv['expires_at'] ?? null ), array( 'premium1', $fr_unv_exp ) );
reset_state( array( 'wpsa_license_key' => 'PREKEY', 'wpsa_saved_tier' => 'free',
                    'wpsa_license_expiration' => '' ) );
ok( 'Unreachable service on a free site: no date',
    wpsa_license_unverified_snapshot( 'ttfb', 'wpsa_gk_quota_ttfb' )['expires_at'] ?? null, '' );

// AC-P8 (D14) the duplicate sync block must be GONE from the admin path.
// Asserted by source inspection because the block is inline in a render
// function; a functional test of wpsa_check_quota() cannot see it.
$main = file_get_contents( __DIR__ . '/../wp-speed-analyzer.php' );
// Every source-inspection assertion below cuts a slice out of this string.
// A read that silently returned false or '' would make every NEGATIVE-form
// assertion (`must NOT contain X`) pass vacuously, so the read itself is
// pinned first — the cheapest guard against the whole "assertion went green
// because its input vanished" failure class that fix-round 4 is about.
ok( 'AC-B6F0 wp-speed-analyzer.php source was read',
    ( is_string( $main ) && strlen( $main ) > 10000 ), true );

// M9 (fix-round 1) — tightened from `<= 2` to `=== 1`. The `<= 2` slack was
// reserved for a deactivate-branch saved_tier writer that D10 then decided
// AGAINST (deactivation leaves saved_tier in place; wpsa_get_license_tier()
// bounds it by expiry on read, not on write). That slack is dead now and
// silently admits the exact D14 defect this AC exists to guard — a second
// writer in the admin path competing with wpsa_check_quota()'s sole write.
ok( 'AC-P8 exactly one saved_tier writer',
    ( substr_count( $main, "update_option( 'wpsa_saved_tier'" ) === 1 ), true );
ok( 'AC-P8 no rank-based downgrade block',
    fr_contains( $main, 'wpsa_tier_rank( $incoming ) < wpsa_tier_rank( $current )' ), false );

// ─────────────────────────────────────────────────────────────────────────
// Task B6 fix-round 4 — the EXTRACTION layer.
//
// wpsa_handle_license_form() cannot be functionally tested as a whole (it is
// laced with wp_die/exit and returns nothing), so the assertions below reach
// into its source. Rounds 1–3 hardened how the extracted text was TOKENIZED
// but never validated how it was SLICED, and four separate fails-OPEN
// defects lived in that unvalidated arithmetic:
//
//   4a  Each pin took the FIRST strpos() match of its anchor. An ordinary
//       comment naming `delete_option( 'wpsa_license_key' )` was matched
//       instead of the call itself, so the guard measured the comment's
//       nesting depth (1) and stayed green while the real call sat inside a
//       conditional. Full suite GREEN on provably broken code.
//   4b  The deactivate slice ended at a bare strpos() for 'Activate branch'.
//       One cross-reference comment containing that text ABOVE the deactivate
//       block put the end anchor before the start anchor; substr() with the
//       resulting negative length counts back from the end of the string and
//       returned 38,610 bytes of a 52,514-byte file. Every
//       `strpos( $fr_deactivate_branch, ... )` assertion silently stopped
//       being scoped to the branch and carried on reporting green.
//   4c  Breaking the 'admin_post_wpsa_save_pdf_custom' end anchor expanded
//       the activate slice through that same negative-length path, silently.
//   4d  Each slice STARTED at its anchor, which sat inside a `//` comment, so
//       `// ─── ` was cut away and the rest of that comment line was
//       tokenized as live code — an apostrophe there
//       (`// ─── Deactivate branch (don't touch) ───`) opened a string
//       literal that swallowed the block's `{` and reddened CORRECT code.
//
// Two rules replace all of that, applied per assertion rather than uniformly:
//
//   1. ANCHOR LAYER. Every anchor must occur EXACTLY ONCE in the string being
//      cut; zero or two is a named failing assertion, never a silent wrong
//      match. Ordering is asserted explicitly, so substr() is never handed a
//      negative length. Slices are bounded by real CODE tokens rather than by
//      comment text, and where a text anchor survives it is checked, not
//      trusted. A benign comment that happens to duplicate an anchor now
//      reddens loudly instead of hijacking the slice.
//   2. EXECUTION OVER INSPECTION. Where an invariant can be checked by
//      RUNNING the extracted code against the harness stubs, it is (the
//      fr_run_* helpers below). A behavioural assertion has no anchor to
//      hijack, no brace to miscount and no comment to trip over, and it
//      catches the two shapes brace-counting never could — `if ( … ) : …
//      endif;` and a brace-less `if ( … ) delete_option( … );` — because it
//      does not care what the conditional looks like, only whether the option
//      is gone afterwards.
//
// eval() safety note (unchanged from fix-round 1): this file is a CLI test
// harness, never web-reachable. Every eval() below runs a snippet read
// straight from this repo's own source on disk — never user or network input
// — the same "lift the production body verbatim" pattern
// tests/_license-state-subject.php already uses. Every eval() is now wrapped
// in try/catch: a snippet that will not parse used to be a hard fatal that
// killed the whole run mid-suite (loud, but it named nothing and lost every
// assertion after it); it is now a named failing assertion instead.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Byte offset of $needle in $hay, but ONLY when it occurs exactly once.
 * Records a named assertion carrying the actual count either way, so a
 * duplicated or vanished anchor is impossible to miss, and returns null (not
 * a wrong offset) so the caller slices nothing rather than slicing garbage.
 */
function fr_anchor_once( $hay, $needle, $label ) {
    $count = is_string( $hay ) && '' !== $hay ? substr_count( $hay, $needle ) : -1;
    ok( $label . ": anchor " . var_export( $needle, true ) . ' must occur exactly once', $count, 1 );
    return ( 1 === $count ) ? strpos( $hay, $needle ) : null;
}

/**
 * Presence test that can never fail open. Returns null — never true, never
 * false — when the haystack is not a usable non-empty string, so BOTH forms
 * of assertion redden on a broken extraction:
 *   ok( '… contains X',     fr_contains( $s, 'X' ), true  );
 *   ok( '… must not have X', fr_contains( $s, 'X' ), false );
 * The second is the trap this fixes: `false === strpos( '', 'X' )` is TRUE,
 * so the old negative-form assertions silently succeeded whenever their
 * slice came back empty.
 */
function fr_contains( $hay, $needle ) {
    if ( ! is_string( $hay ) || '' === $hay ) {
        return null;
    }
    return ( false !== strpos( $hay, $needle ) );
}

/**
 * Extracts the complete `if ( … ) { … }` statement whose opening line is
 * $anchor. The START comes from an exactly-once anchor over real code; the
 * END comes from fr_extract_if_else_stmt() walking the statement's own
 * tokens. Nothing about the surrounding file can move either bound — this is
 * what retires the 'Deactivate branch' / 'Activate branch' /
 * 'admin_post_wpsa_save_pdf_custom' comment anchors and defects 4b, 4c, 4d
 * with them. Always emits exactly two assertions, pass or fail, so the
 * suite's assertion count stays a stable baseline.
 */
function fr_extract_stmt_by_anchor( $hay, $anchor, $label ) {
    $pos  = fr_anchor_once( $hay, $anchor, $label );
    $stmt = ( null === $pos ) ? null : fr_extract_if_else_stmt( $hay, $pos );
    ok( $label . ': statement is extractable', ( is_string( $stmt ) && '' !== $stmt ), true );
    return $stmt;
}

/**
 * The inside of $stmt's outermost brace block: everything between its first
 * STRUCTURAL `{` and the matching final `}`. Token-driven, so a brace inside
 * a comment, an ordinary string, a heredoc/nowdoc or a `"{$var}"`
 * interpolation cannot move either boundary.
 */
function fr_block_body( $stmt ) {
    if ( ! is_string( $stmt ) || '' === $stmt ) {
        return null;
    }
    $offset = -6; // length of the injected '<?php ' prefix
    $depth  = 0;
    $stack  = array();
    $open   = null;
    foreach ( token_get_all( '<?php ' . $stmt ) as $tok ) {
        $text    = is_array( $tok ) ? $tok[1] : $tok;
        $before  = $offset;
        $offset += strlen( $text );
        if ( '{' === $tok ) {
            $stack[] = 'struct';
            $depth++;
            if ( 1 === $depth && null === $open ) {
                $open = $offset;
            }
            continue;
        }
        if ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
            $stack[] = 'interp';
            continue;
        }
        if ( '}' !== $tok ) {
            continue;
        }
        if ( 'struct' !== array_pop( $stack ) ) {
            continue;
        }
        $depth--;
        if ( 0 === $depth && null !== $open ) {
            return substr( $stmt, $open, $before - $open );
        }
    }
    return null;
}

/**
 * Slice of $hay running from the start anchor up to the start of the end
 * anchor. Both anchors exactly-once, start asserted to precede end, and the
 * result asserted non-empty — four assertions, always, pass or fail.
 * substr() is never reached with a negative length (defect 4b).
 */
function fr_slice_between( $hay, $start_anchor, $end_anchor, $label ) {
    $s = fr_anchor_once( $hay, $start_anchor, $label . ' start' );
    $e = fr_anchor_once( $hay, $end_anchor, $label . ' end' );
    $ordered = ( null !== $s && null !== $e && $s < $e );
    ok( $label . ': start anchor precedes end anchor', $ordered, true );
    $slice = $ordered ? substr( $hay, $s, $e - $s ) : null;
    ok( $label . ': slice is non-empty', ( is_string( $slice ) && '' !== $slice ), true );
    return $slice;
}

/** Option-absence probe that cannot fail open: null, never true, on a run that never happened. */
function fr_opt_absent( $opts, $key ) {
    if ( ! is_array( $opts ) ) {
        return null;
    }
    return ! array_key_exists( $key, $opts );
}

/** Option-value probe; null when the run never happened or the option is unset. */
function fr_opt_value( $opts, $key ) {
    return ( is_array( $opts ) && array_key_exists( $key, $opts ) ) ? $opts[ $key ] : null;
}

/** One field of the Nth notice a snippet raised; null when the run never happened. */
function fr_notice_field( $run, $i, $field ) {
    return isset( $run['notices'][ $i ][ $field ] ) ? $run['notices'][ $i ][ $field ] : null;
}

// Stubs used only by the fix-round 4 execution assertions below. None of them
// existed before, so defining them cannot change any pre-existing result.
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['fr_post_calls']++;
    $GLOBALS['fr_post_url']  = $url;
    $GLOBALS['fr_post_body'] = isset( $args['body'] ) ? (string) $args['body'] : '';
    return $GLOBALS['fr_post_result'];
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
    $GLOBALS['fr_settings_errors'][] = array( 'code' => $code, 'message' => $message, 'type' => $type );
}
function __( $text, $domain = 'default' ) { return $text; }

/**
 * Runs the deactivate branch's BODY — everything from the branch's own `{`
 * down to (not including) the closing set_transient() call, which is where
 * the branch stops doing work and starts doing wp_safe_redirect()/exit — for
 * a controlled option state and a controlled wp_remote_post() answer. Reports
 * the resulting option table AND the notice the branch raised.
 *
 * Deliberately ONE chunk. The first version of this round ran the notice
 * if/else separately with $deactivate_failed INJECTED, which is exactly the
 * "hand the code the switch already flipped" anti-pattern: it proves the
 * notice's own if/else and nothing about whether a transport failure actually
 * reaches it. Here $deactivate_failed is computed by production code from the
 * scenario's wp_remote_post() answer, so the notice assertions exercise the
 * real wiring from transport outcome to customer-facing copy.
 *
 * This replaces fix-round 3's brace-nesting-depth pins on the two
 * delete_option() calls. Those measured the SHAPE of the source ahead of each
 * call, anchored on the call's own text, and took the FIRST match: a comment
 * naming the call was matched instead of the call, depth read 1 off the
 * comment, and the suite stayed green with the real call inside a conditional
 * (defect 4a). Running the code has nothing to hijack — if the deletes do not
 * happen under a scenario, the assertion is red — and it covers the two
 * shapes brace-counting explicitly could not: alternate `if ( … ) : … endif;`
 * syntax and a brace-less `if ( … ) delete_option( … );`.
 *
 * Returns null-carrying sentinels (never an empty option table) when the
 * snippet could not be extracted or would not parse, so the "option is
 * absent" assertions cannot pass vacuously on a run that never happened.
 */
function fr_run_deactivate_body( $snippet, array $start_opts, $post_result ) {
    $out = array( 'opts' => null, 'posts' => null, 'failed' => null, 'body' => null,
                  'notices' => null, 'error' => '' );
    if ( ! is_string( $snippet ) || '' === $snippet ) {
        $out['error'] = 'snippet was not extracted';
        return $out;
    }
    $GLOBALS['opts']               = $start_opts;
    $GLOBALS['fr_post_result']     = $post_result;
    $GLOBALS['fr_post_calls']      = 0;
    $GLOBALS['fr_post_body']       = '';
    $GLOBALS['fr_settings_errors'] = array();
    $deactivate_failed             = null;
    try {
        eval( $snippet );
    } catch ( Throwable $e ) {
        $out['error'] = get_class( $e ) . ': ' . $e->getMessage();
        return $out;
    }
    $out['opts']    = $GLOBALS['opts'];
    $out['posts']   = $GLOBALS['fr_post_calls'];
    $out['failed']  = $deactivate_failed;
    $out['body']    = $GLOBALS['fr_post_body'];
    $out['notices'] = $GLOBALS['fr_settings_errors'];
    return $out;
}

/**
 * Runs the activation branch's two-statement last_verified derivation against
 * a controlled $body['fetched_at'] and returns what it stored.
 */
function fr_run_last_verified_snippet( $snippet, $fetched_at_iso ) {
    if ( ! is_string( $snippet ) || '' === $snippet ) {
        return array( 'value' => null, 'error' => 'snippet was not extracted' );
    }
    $body = array( 'fetched_at' => $fetched_at_iso ); // read by the eval'd snippet
    delete_option( 'wpsa_license_last_verified' );
    try {
        eval( $snippet );
    } catch ( Throwable $e ) {
        return array( 'value' => null, 'error' => get_class( $e ) . ': ' . $e->getMessage() );
    }
    return array( 'value' => get_option( 'wpsa_license_last_verified' ), 'error' => '' );
}

/**
 * Runs one extracted slice of the activation branch with the given variables in
 * scope ($resp for the failure slice; $key and $body for the success slice) and a
 * controlled option table. Returns the resulting options and the two variables the
 * failure slice sets. Carries null sentinels when the slice was not extracted or
 * would not run, so the assertions downstream go red instead of passing on a run
 * that never happened. A PHP warning, notice or deprecation raised inside the
 * slice counts as a failed run too: it is thrown, reported in 'error', and returns
 * the same sentinels, so a slice that reads a variable it was never given cannot
 * pass.
 */
function fr_run_activate_slice( $snippet, array $vars, array $start_opts ) {
    $out = array( 'opts' => null, 'reason' => null, 'msg' => null, 'error' => '' );
    if ( ! is_string( $snippet ) || '' === $snippet ) {
        $out['error'] = 'snippet was not extracted';
        return $out;
    }
    $GLOBALS['opts'] = $start_opts;
    $resp   = array_key_exists( 'resp', $vars ) ? $vars['resp'] : null; // read by the eval'd slice
    $key    = array_key_exists( 'key', $vars ) ? $vars['key'] : '';     // read by the eval'd slice
    $body   = array_key_exists( 'body', $vars ) ? $vars['body'] : null; // read by the eval'd slice
    $reason = null;
    $msg    = null;
    set_error_handler( function ( $errno, $errstr ) {
        throw new ErrorException( $errstr, 0, $errno );
    } );
    try {
        eval( $snippet );
    } catch ( Throwable $e ) {
        $out['error'] = get_class( $e ) . ': ' . $e->getMessage();
        return $out;
    } finally {
        restore_error_handler();
    }
    $out['opts']   = $GLOBALS['opts'];
    $out['reason'] = $reason;
    $out['msg']    = $msg;
    return $out;
}

/**
 * Evals the extracted /deactivate response-handling snippet against a
 * caller-supplied $resp. Returns the resulting $deactivate_failed AND
 * whether the else-branch's body actually ran ($deact_code stops being the
 * initialised null sentinel). The second signal is what discriminates
 * "is_wp_error() short-circuited correctly" from "the check was neutered but
 * the code<300 fallback coincidentally still flagged failure anyway" — a raw
 * WP_Error always coerces to response code 0 via
 * wp_remote_retrieve_response_code(), so $deactivate_failed alone is true
 * either way and cannot tell the two apart. Both fields come back null on a
 * failed extraction or a snippet that will not parse, so every assertion
 * downstream reddens rather than passing on a sentinel.
 */
function fr_run_deactivate_resp_snippet( $snippet, $resp ) {
    if ( ! is_string( $snippet ) || '' === $snippet ) {
        return array( 'failed' => null, 'entered_else' => null );
    }
    $deactivate_failed = false;
    $deact_code        = null;
    try {
        eval( $snippet );
    } catch ( Throwable $e ) {
        return array( 'failed' => null, 'entered_else' => null );
    }
    return array( 'failed' => $deactivate_failed, 'entered_else' => ( null !== $deact_code ) );
}

/**
 * Extracts one complete if/elseif/else statement starting at $start_pos
 * (which must point at the statement's leading `if`), using PHP's own
 * tokenizer to find its TRUE end: the position where STRUCTURAL brace depth
 * returns to 0 AND the next non-trivial token is not `else`/`elseif` (which
 * would mean the statement continues). A text end-anchor plus a "strip the
 * trailing brace" heuristic was tried first and broke the moment unrelated
 * text (e.g. a new comment) was inserted after the statement, moving what
 * counted as "the last character" — a real false-positive class, caught by
 * fix-round 2's own false-positive drill. Finding the end from STRUCTURE
 * instead of from what happens to sit afterward in the file is what fixes it,
 * and fix-round 4 leans on the same property to retire the branch slices'
 * comment-text end anchors entirely.
 *
 * fix-round 3: distinguishes a structural `{`/`}` from PHP string
 * INTERPOLATION braces (`"{$var}"` opens as T_CURLY_OPEN, `"${var}"` opens as
 * T_DOLLAR_OPEN_CURLY_BRACES — but BOTH close as the exact same bare '}'
 * string token a structural close uses; verified against PHP 8.5.1). A stack
 * of open-marker types ('struct'|'interp') resolves every bare '}' against
 * whichever kind of open is most recently pending, so an interpolated string
 * anywhere in the statement (e.g. `error_log( "id {$token}" );`) can no
 * longer close a structural block early.
 */
function fr_extract_if_else_stmt( $code, $start_pos ) {
    if ( false === $start_pos || null === $start_pos || ! is_string( $code ) ) {
        return null;
    }
    $tail    = substr( $code, $start_pos );
    $tokens  = token_get_all( '<?php ' . $tail );
    $offset  = -6; // length of the injected '<?php ' prefix
    $depth   = 0;
    $entered = false;
    $stack   = array(); // LIFO of 'struct' | 'interp' open markers
    $n       = count( $tokens );
    for ( $i = 0; $i < $n; $i++ ) {
        $tok  = $tokens[ $i ];
        $text = is_array( $tok ) ? $tok[1] : $tok;
        $offset += strlen( $text );
        if ( '{' === $tok ) {
            $depth++;
            $entered = true;
            $stack[] = 'struct';
            continue;
        }
        if ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
            $stack[] = 'interp';
            continue;
        }
        if ( '}' !== $tok ) {
            continue;
        }
        $kind = array_pop( $stack );
        if ( 'struct' !== $kind ) {
            // An interpolation close (or a stray '}' with nothing pending,
            // which cannot happen in valid PHP) — never a structural close,
            // so it neither changes $depth nor can end the statement.
            continue;
        }
        // Only a STRUCTURAL '}' can bring depth back to 0 — check for a
        // continuing else/elseif right at THAT transition, not on every
        // later token while depth happens to still read 0 (fix-round 2's own
        // bug: re-checking on the `else` keyword token itself, mid-way
        // through `} else {`, wrongly saw a bare '{' next and called it "no
        // continuation").
        $depth--;
        if ( $entered && 0 === $depth ) {
            $j = $i + 1;
            while ( $j < $n && is_array( $tokens[ $j ] )
                    && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                $j++;
            }
            $next      = $j < $n ? $tokens[ $j ] : null;
            $continues = is_array( $next ) && in_array( $next[0], array( T_ELSE, T_ELSEIF ), true );
            if ( ! $continues ) {
                return substr( $code, $start_pos, $offset );
            }
        }
    }
    return null;
}

/**
 * Net STRUCTURAL brace-nesting depth of $code, measured with PHP's own
 * tokenizer. A '{' or '}' inside a comment or an ordinary (non-interpolating)
 * string literal is emitted as part of a T_COMMENT /
 * T_CONSTANT_ENCAPSED_STRING (etc.) array token, never as a bare
 * single-character '{'/'}' string token, so counting bare tokens cannot be
 * thrown off by a brace sitting inside one of those. PHP string
 * INTERPOLATION is the exception and the original version got it wrong
 * (verified against PHP 8.5.1): `"{$var}"` opens with the array token
 * T_CURLY_OPEN and `"${var}"` with T_DOLLAR_OPEN_CURLY_BRACES, but BOTH close
 * with the same bare '}' a structural close uses — a net -1 per interpolated
 * variable. The open-marker stack below fixes that: every open (structural OR
 * interpolation) pushes a marker, a bare '}' pops it, and $depth moves only
 * when the popped marker is 'struct'.
 *
 * SCOPE (fix-round 4). This function is now used for ONE thing: asserting
 * that an extracted snippet is brace-balanced before it is eval'd. It is no
 * longer used to prove anything about production control flow. Fix-round 3
 * used it for that — "the source ahead of this delete_option() call has brace
 * depth exactly 1, so the call is not wrapped in a conditional" — and that
 * use carried two documented blind spots (`if ( … ) : … endif;` and a
 * brace-less `if ( … ) delete_option( … );`, neither of which changes the
 * brace count) plus one undocumented and much worse one: the position it
 * measured up to came from a first-match strpos(), so a comment mentioning
 * the call was measured instead of the call (defect 4a). All three are gone
 * because that job moved to fr_run_deactivate_body(), which executes the
 * branch instead of reading its shape and therefore does not care what the
 * conditional is written like — only whether the option is gone afterwards.
 */
function fr_token_brace_depth( $code ) {
    $depth = 0;
    $stack = array(); // LIFO of 'struct' | 'interp' open markers
    foreach ( token_get_all( '<?php ' . $code ) as $tok ) {
        if ( '{' === $tok ) {
            $depth++;
            $stack[] = 'struct';
        } elseif ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
            $stack[] = 'interp';
        } elseif ( '}' === $tok ) {
            $kind = array_pop( $stack );
            if ( 'struct' === $kind ) {
                $depth--;
            }
        }
    }
    return $depth;
}

// ── The two branch slices, cut from real code rather than from comment text.
$fr_deactivate_branch = fr_extract_stmt_by_anchor(
    $main, "if ( isset( \$_POST['wpsa_deactivate_license'] ) ) {", 'AC-B6F-SLICE deactivate branch' );
$fr_activate_branch = fr_extract_stmt_by_anchor(
    $main, "if ( isset( \$_POST['wpsa_activate_license'] ) ) {", 'AC-B6F-SLICE activate branch' );

// ── The executable chunk of the deactivate branch: the whole local-cleanup
// sequence AND the notice it raises, stopping at the set_transient() call
// where the branch stops doing work and starts doing wp_safe_redirect()/exit.
$fr_deact_body = fr_block_body( $fr_deactivate_branch );
ok( 'AC-B6F-SLICE deactivate branch body is non-empty',
    ( is_string( $fr_deact_body ) && '' !== $fr_deact_body ), true );
$fr_deact_end = fr_anchor_once( is_string( $fr_deact_body ) ? $fr_deact_body : '',
    'set_transient(', 'AC-B6F-SLICE deactivate cleanup chunk' );
$fr_deact_pre = ( null === $fr_deact_end ) ? null : substr( $fr_deact_body, 0, $fr_deact_end );
ok( 'AC-B6F-SLICE deactivate cleanup chunk is brace-balanced',
    ( is_string( $fr_deact_pre ) && 0 === fr_token_brace_depth( $fr_deact_pre ) ), true );

// Important 2 + Important 3 (fix-round 4: executed, not string-matched).
// Scenario 1 — a stored token and an unreachable worker. The local cleanup is
// specified to run REGARDLESS of the outcome, so this is the scenario that
// catches `if ( ! $deactivate_failed ) { …deletes… }` in any syntax.
$fr_s1 = fr_run_deactivate_body( $fr_deact_pre,
    array(
        'wpsa_license_key'               => 'PREKEY',
        'wpsa_license_activation_token'  => 'TOK',
        'wpsa_saved_tier'                => 'premium3',
        'wpsa_license_expiration'        => '2027-01-01',
        'wpsa_license_state'             => 'expired',
    ),
    new WP_Error_Stub() );
ok( 'AC-B6F3 S1 worker-unreachable body ran without error', $fr_s1['error'], '' );
ok( 'AC-B6F3 S1 licence key deleted even though /deactivate failed',
    fr_opt_absent( $fr_s1['opts'], 'wpsa_license_key' ), true );
ok( 'AC-B6F3 S1 activation token deleted even though /deactivate failed',
    fr_opt_absent( $fr_s1['opts'], 'wpsa_license_activation_token' ), true );
ok( 'AC-B6F2 S1 deactivation sets state=free', fr_opt_value( $fr_s1['opts'], 'wpsa_license_state' ), 'free' );
ok( 'AC-B6F3 S1 transport failure is recorded as a failure', $fr_s1['failed'], true );
ok( 'AC-B6F3 S1 D10 leaves saved_tier in place', fr_opt_value( $fr_s1['opts'], 'wpsa_saved_tier' ), 'premium3' );
ok( 'AC-B6F3 S1 D10 leaves the stored expiry in place',
    fr_opt_value( $fr_s1['opts'], 'wpsa_license_expiration' ), '2027-01-01' );
// Important 3's customer-facing copy, reached through the real failure path
// rather than by injecting $deactivate_failed: the WP_Error above is what
// production turned into this notice.
ok( 'AC-B6F3 S1 failure notice carries the operator-approved string verbatim',
    fr_notice_field( $fr_s1, 0, 'message' ),
    'Licence removed from this site, but the licence service could not be reached — the slot may still be in use. Please try again in a moment.' );
ok( 'AC-B6F3 S1 failure notice is an error notice', fr_notice_field( $fr_s1, 0, 'type' ), 'error' );

// Scenario 2 — an EMPTY stored token, so the whole remote block is skipped.
// This is the scenario that catches either delete being moved inside
// `if ( '' !== $token )`, in any syntax. The token option is present but
// empty rather than missing, because that is a real production state (the
// activate branch stores '' when the worker's answer carries no token), and
// because it makes the token delete load-bearing here: a delete that only
// runs when a token is non-empty would leave this key behind.
$fr_s2 = fr_run_deactivate_body( $fr_deact_pre,
    array( 'wpsa_license_key' => 'PREKEY', 'wpsa_license_activation_token' => '',
           'wpsa_license_state' => 'expired' ), null );
ok( 'AC-B6F3 S2 empty-token body ran without error', $fr_s2['error'], '' );
ok( 'AC-B6F3 S2 licence key deleted with no usable token',
    fr_opt_absent( $fr_s2['opts'], 'wpsa_license_key' ), true );
ok( 'AC-B6F3 S2 an empty activation token is still deleted',
    fr_opt_absent( $fr_s2['opts'], 'wpsa_license_activation_token' ), true );
ok( 'AC-B6F2 S2 deactivation sets state=free', fr_opt_value( $fr_s2['opts'], 'wpsa_license_state' ), 'free' );
ok( 'AC-B6F3 S2 no /deactivate call is made without a token', $fr_s2['posts'], 0 );

// Scenario 3 — the worker accepts. Proves the happy path clears the same
// state and that the stored token is what actually goes on the wire.
$fr_s3 = fr_run_deactivate_body( $fr_deact_pre,
    array( 'wpsa_license_key' => 'PREKEY', 'wpsa_license_activation_token' => 'TOK',
           'wpsa_license_state' => 'active' ),
    array( 'code' => 200, 'body' => json_encode( array( 'success' => true ) ) ) );
ok( 'AC-B6F3 S3 worker-accepts body ran without error', $fr_s3['error'], '' );
ok( 'AC-B6F3 S3 licence key deleted', fr_opt_absent( $fr_s3['opts'], 'wpsa_license_key' ), true );
ok( 'AC-B6F3 S3 activation token deleted', fr_opt_absent( $fr_s3['opts'], 'wpsa_license_activation_token' ), true );
ok( 'AC-B6F2 S3 deactivation sets state=free', fr_opt_value( $fr_s3['opts'], 'wpsa_license_state' ), 'free' );
ok( 'AC-B6F3 S3 a successful /deactivate is not reported as a failure', $fr_s3['failed'], false );
ok( 'AC-B6F3 S3 the stored token is sent to /deactivate',
    fr_contains( $fr_s3['body'], '"token":"TOK"' ), true );
ok( 'AC-B6F3 S3 success notice copy is unchanged',
    fr_notice_field( $fr_s3, 0, 'message' ), 'License deactivated successfully.' );
ok( 'AC-B6F3 S3 success notice is an updated notice', fr_notice_field( $fr_s3, 0, 'type' ), 'updated' );

// The AC-W14 carve-out's source text. Belt-and-braces beside the functional
// no_token leg below: this one pins that the carve-out is written against
// $deact_reason specifically, which the behavioural test cannot distinguish
// from any other way of reaching the same answer.
ok( 'AC-B6F3 no_token is carved out of the failure branch',
    fr_contains( $fr_deactivate_branch, "'no_token' !== \$deact_reason" ), true );

// Important 3 (fix-round 2) — the three failure legs (WP_Error, non-2xx,
// success-falsy) were each reachable via a single string match, so neutering
// any one leg survived. This extracts the exact response-handling statement
// and evals it against each leg in isolation. fix-round 4 replaced its bare
// strpos() anchor with an exactly-once one, and restructured the block so the
// same seven assertions run whether or not the extraction succeeded — a
// failed extraction now names seven reds instead of silently shrinking the
// suite by four.
$fr_resp_start   = fr_anchor_once( is_string( $fr_deactivate_branch ) ? $fr_deactivate_branch : '',
    'if ( is_wp_error( $resp ) )', 'AC-B6F3b response handling' );
$fr_resp_snippet = ( null === $fr_resp_start ) ? null : fr_extract_if_else_stmt( $fr_deactivate_branch, $fr_resp_start );
ok( 'AC-B6F3b response-handling snippet is extractable',
    ( is_string( $fr_resp_snippet ) && '' !== $fr_resp_snippet ), true );
ok( 'AC-B6F3b response-handling snippet is brace-balanced',
    ( is_string( $fr_resp_snippet ) && 0 === fr_token_brace_depth( $fr_resp_snippet ) ), true );

$fr_wpe = fr_run_deactivate_resp_snippet( $fr_resp_snippet, new WP_Error_Stub() );
ok( 'AC-B6F3b WP_Error leg marks failure', $fr_wpe['failed'], true );
ok( 'AC-B6F3b WP_Error leg short-circuits before the else branch', $fr_wpe['entered_else'], false );

// Isolated from the success-falsy leg with success:true in the body — only
// the response CODE is wrong here.
$fr_502 = fr_run_deactivate_resp_snippet( $fr_resp_snippet,
    array( 'code' => 500, 'body' => json_encode( array( 'success' => true ) ) ) );
ok( 'AC-B6F3b non-2xx leg marks failure', $fr_502['failed'], true );

// Isolated from the non-2xx leg with code 200 — only the body's success flag
// (and a non-no_token reason) is wrong here.
$fr_falsy = fr_run_deactivate_resp_snippet( $fr_resp_snippet,
    array( 'code' => 200, 'body' => json_encode( array( 'success' => false, 'reason' => 'weird' ) ) ) );
ok( 'AC-B6F3b success-falsy leg marks failure', $fr_falsy['failed'], true );

// The AC-W14 carve-out, exercised functionally.
$fr_notoken = fr_run_deactivate_resp_snippet( $fr_resp_snippet,
    array( 'code' => 200, 'body' => json_encode( array( 'success' => false, 'reason' => 'no_token' ) ) ) );
ok( 'AC-B6F3b no_token leg is NOT a failure', $fr_notoken['failed'], false );

// Important 1 (fix-round 2: strengthened from presence-only to exact-value).
// §5.1 requires wpsa_license_last_verified := body.fetched_at, NEVER time().
// A presence-only check ("some update_option call exists") does not catch
// storing time() instead of the derived value, nor storing the raw ISO STRING
// instead of an int ((int) on an ISO string like "2026-09-09T…" evaluates to
// 2026 — nowhere near a real unix timestamp, and in
// wpsa_license_unverified_snapshot() that reads as ages in the past, forcing
// a perpetual licence to free minutes after a successful activation: this
// release's headline defect, reintroduced). So this extracts the EXACT
// derivation statements (not a hand-retyped copy) from the activate branch
// and evals them against a controlled $body, using a fetched_at six years in
// the past — far enough that neither wrong implementation can coincide with
// the correct one.
//
// Both anchors are scoped to the activate branch and asserted exactly-once
// there. That scoping is load-bearing, not decoration:
// `update_option( 'wpsa_license_last_verified'` occurs TWICE in
// wp-speed-analyzer.php (the other is B3's upgrade migration, which
// deliberately writes 0), so a file-wide anchor would have been ambiguous —
// the exact shape the exactly-once rule exists to refuse.
$fr_act          = is_string( $fr_activate_branch ) ? $fr_activate_branch : '';
$fr_lv_start     = fr_anchor_once( $fr_act, '$fetched = isset(', 'AC-B6F1 derivation start' );
$fr_lv_call_pos  = fr_anchor_once( $fr_act, "update_option( 'wpsa_license_last_verified'", 'AC-B6F1 store call' );
$fr_lv_ordered   = ( null !== $fr_lv_start && null !== $fr_lv_call_pos && $fr_lv_start < $fr_lv_call_pos );
ok( 'AC-B6F1 derivation precedes the store call', $fr_lv_ordered, true );
$fr_lv_snippet   = null;
if ( $fr_lv_ordered ) {
    $fr_lv_call_end = strpos( $fr_act, ');', $fr_lv_call_pos );
    if ( false !== $fr_lv_call_end ) {
        $fr_lv_snippet = substr( $fr_act, $fr_lv_start, $fr_lv_call_end + 2 - $fr_lv_start );
    }
}
ok( 'AC-B6F1 last_verified derivation snippet is extractable',
    ( is_string( $fr_lv_snippet ) && '' !== $fr_lv_snippet ), true );

$fr_far_past_iso = '2020-03-15T10:00:00Z';
$fr_expected_ts  = strtotime( $fr_far_past_iso );
$fr_lv           = fr_run_last_verified_snippet( $fr_lv_snippet, $fr_far_past_iso );
$fr_lv_actual    = $fr_lv['value'];
ok( 'AC-B6F1 last_verified is an integer', is_int( $fr_lv_actual ), true );
ok( 'AC-B6F1 last_verified equals strtotime( fetched_at ) exactly', $fr_lv_actual, $fr_expected_ts );
ok( 'AC-B6F1 last_verified is not time() (far-past fetched_at proves it)',
    ( is_int( $fr_lv_actual ) && abs( $fr_lv_actual - time() ) > 30 * DAY_IN_SECONDS ), true );

// ── The activation branch, executed: two slices of the real source.
//
// wpsa_handle_license_form() cannot run whole here (wp_die/exit), so the
// activation branch is cut at two points that do no redirecting:
//  (i)  the worker's answer and the failure-message choice: from `$body = ` through
//       the `$msg = …;` statement, closed with a `}` for the failure `if` it
//       opens. The if-condition and the message table run for real.
//  (ii) the success writes: from the licence-key store up to the success notice.
// Not executed: the transients, the redirects and `exit`, the handler's
// registration on admin_post_wpsa_save_license, and its nonce and capability
// checks. Only the registration and those two checks are pinned, by presence
// and order, below the activation checks; nothing pins the transients, the
// redirects or `exit` (the one `set_transient(` anchor only bounds the
// deactivate branch's chunk).
// The accepted residuals further down give the complete list.
$fr_act_fail_start = fr_anchor_once( $fr_act, '$body = is_wp_error( $resp )', 'Activation failure slice start' );
$fr_act_msg_pos    = fr_anchor_once( $fr_act, '$msg = isset( $messages[ $reason ] )', 'Activation failure slice end' );
$fr_act_fail_snip  = null;
if ( null !== $fr_act_fail_start && null !== $fr_act_msg_pos && $fr_act_fail_start < $fr_act_msg_pos ) {
    $fr_msg_end = strpos( $fr_act, ';', $fr_act_msg_pos );
    if ( false !== $fr_msg_end ) {
        $fr_act_fail_snip = substr( $fr_act, $fr_act_fail_start, $fr_msg_end + 1 - $fr_act_fail_start ) . "\n}";
    }
}
ok( 'Activation failure slice is extractable and brace-balanced',
    ( is_string( $fr_act_fail_snip ) && 0 === fr_token_brace_depth( $fr_act_fail_snip ) ), true );

$fr_act_ok_start = fr_anchor_once( $fr_act, "update_option( 'wpsa_license_key', \$key );", 'Activation success slice start' );
$fr_act_ok_note  = fr_anchor_once( $fr_act, "'activated'", 'Activation success notice' );
$fr_act_ok_snip  = null;
if ( null !== $fr_act_ok_start && null !== $fr_act_ok_note && $fr_act_ok_start < $fr_act_ok_note ) {
    $fr_note_call = strrpos( substr( $fr_act, 0, $fr_act_ok_note ), 'add_settings_error(' );
    if ( false !== $fr_note_call && $fr_note_call > $fr_act_ok_start ) {
        $fr_act_ok_snip = substr( $fr_act, $fr_act_ok_start, $fr_note_call - $fr_act_ok_start );
    }
}
ok( 'Activation success slice is extractable and brace-balanced',
    ( is_string( $fr_act_ok_snip ) && 0 === fr_token_brace_depth( $fr_act_ok_snip ) ), true );

// Each failure code the service sends selects its own message.
foreach ( array(
    'unverified'      => 'Could not reach the licence service. Please try again in a moment.',
    'slots'           => 'All licence slots are in use. Deactivate another site, or upgrade.',
    'activate_failed' => 'The licence service could not activate this key. Please try again.',
    'not_found'       => "We don't recognise that licence key — check it for typos.",
) as $fr_code => $fr_text ) {
    $fr_run = fr_run_activate_slice( $fr_act_fail_snip,
        array( 'resp' => array( 'code' => 409, 'body' => json_encode( array( 'success' => false, 'reason' => $fr_code ) ) ) ), array() );
    ok( "Activation failure '$fr_code' selects its message",
        array( $fr_run['error'], $fr_run['reason'], $fr_run['msg'] ), array( '', $fr_code, $fr_text ) );
}
$fr_run = fr_run_activate_slice( $fr_act_fail_snip,
    array( 'resp' => array( 'code' => 409, 'body' => json_encode( array( 'success' => false ) ) ) ), array() );
ok( 'Activation failure without a reason falls back to the unverified message',
    $fr_run['msg'], 'Could not reach the licence service. Please try again in a moment.' );
$fr_run = fr_run_activate_slice( $fr_act_fail_snip, array( 'resp' => new WP_Error_Stub() ), array() );
ok( 'Activation transport error falls back to the unverified message',
    $fr_run['msg'], 'Could not reach the licence service. Please try again in a moment.' );
$fr_run = fr_run_activate_slice( $fr_act_fail_snip,
    array( 'resp' => array( 'code' => 200, 'body' => json_encode( array( 'success' => true ) ) ) ), array() );
ok( 'Activation success selects no failure message', array( $fr_run['error'], $fr_run['msg'] ), array( '', null ) );

// The success writes store the service's answer as received.
$fr_ok_body = array( 'success' => true, 'token' => 'TOK-A', 'tier' => 'premium3', 'expires_at' => '2027-09-09',
    'grace_until' => '2027-09-16', 'state' => 'active', 'status' => 'ok', 'fetched_at' => '2026-09-11T08:00:00Z' );
$fr_run = fr_run_activate_slice( $fr_act_ok_snip, array( 'key' => 'PREKEY-A', 'body' => $fr_ok_body ), array() );
ok( 'Activation success slice ran without error', $fr_run['error'], '' );
ok( 'Activation stores the key, token, tier, expiry, state and status as received',
    array( fr_opt_value( $fr_run['opts'], 'wpsa_license_key' ), fr_opt_value( $fr_run['opts'], 'wpsa_license_activation_token' ),
           fr_opt_value( $fr_run['opts'], 'wpsa_saved_tier' ), fr_opt_value( $fr_run['opts'], 'wpsa_license_expiration' ),
           fr_opt_value( $fr_run['opts'], 'wpsa_license_state' ), fr_opt_value( $fr_run['opts'], 'wpsa_license_status' ) ),
    array( 'PREKEY-A', 'TOK-A', 'premium3', '2027-09-09', 'active', 'ok' ) );
ok( "Activation's last_verified comes from the answer's fetched_at",
    fr_opt_value( $fr_run['opts'], 'wpsa_license_last_verified' ), strtotime( '2026-09-11T08:00:00Z' ) );
$fr_again = fr_run_activate_slice( $fr_act_ok_snip, array( 'key' => 'PREKEY-A', 'body' => $fr_ok_body ),
    is_array( $fr_run['opts'] ) ? $fr_run['opts'] : array() );
// What an activation leaves stored, and the days the licence screens show from it on
// one fixed day, eight days before the expiry. The display helper lives in the notice
// file, which the render checks further down load too.
require_once __DIR__ . '/../includes/license-notice.php';
$fr_act_day  = strtotime( '2027-09-01 12:00:00 UTC' );
$fr_act_seen = function ( $opts ) use ( $fr_act_day ) {
    $exp   = fr_opt_value( $opts, 'wpsa_license_expiration' );
    $grace = fr_opt_value( $opts, 'wpsa_license_grace_until' );
    $shown = wpsa_license_display( (string) fr_opt_value( $opts, 'wpsa_license_state' ),
        (string) fr_opt_value( $opts, 'wpsa_saved_tier' ), '', (string) $exp, (string) $grace, $fr_act_day );
    return array( $exp, $grace, $shown['days'] );
};
ok( 'A second activation with the same answer keeps the expiry, the grace date and the days shown',
    array_merge( $fr_act_seen( $fr_run['opts'] ), $fr_act_seen( $fr_again['opts'] ) ),
    array( '2027-09-09', '2027-09-16', 8, '2027-09-09', '2027-09-16', 8 ) );
// Paired with the check above: a later answer with new dates must replace the stored
// ones. Its dates are earlier than the stored ones, so neither code that keeps whatever
// is already stored nor a rule that never shortens the stored date can pass both checks.
// Its grace date is deliberately not a week after its expiry, so a store that computes
// its own grace date fails too.
$fr_later = fr_run_activate_slice( $fr_act_ok_snip,
    array( 'key' => 'PREKEY-A', 'body' => array_merge( $fr_ok_body, array( 'expires_at' => '2026-12-09', 'grace_until' => '2026-12-20' ) ) ),
    is_array( $fr_again['opts'] ) ? $fr_again['opts'] : array() );
ok( "A later activation stores the service's new expiry and grace dates",
    array( fr_opt_value( $fr_later['opts'], 'wpsa_license_expiration' ), fr_opt_value( $fr_later['opts'], 'wpsa_license_grace_until' ) ),
    array( '2026-12-09', '2026-12-20' ) );
ok( 'Activation no longer writes the reason option',
    is_array( $fr_run['opts'] ) && ! array_key_exists( 'wpsa_license_reason', $fr_run['opts'] ), true );
$fr_run = fr_run_activate_slice( $fr_act_ok_snip, array( 'key' => 'PREKEY-A', 'body' => $fr_ok_body ), array() );
ok( 'Activation stores the grace date beside the expiry', fr_opt_value( $fr_run['opts'], 'wpsa_license_grace_until' ), '2027-09-16' );
$fr_run = fr_run_activate_slice( $fr_act_ok_snip,
    array( 'key' => 'PREKEY-A', 'body' => array_merge( $fr_ok_body, array( 'expires_at' => null, 'grace_until' => null ) ) ), array() );
ok( 'A perpetual activation stores neither date',
    array( fr_opt_value( $fr_run['opts'], 'wpsa_license_expiration' ), fr_opt_value( $fr_run['opts'], 'wpsa_license_grace_until' ) ),
    array( '', '' ) );
// Two activation answers edited by hand, each over a stale stored pair: one without a grace
// date, and one with an empty expiry that still carries a grace date. Neither may leave a
// grace date stored, and none may be computed here.
$fr_act_stale = array( 'wpsa_license_expiration' => '2027-01-01', 'wpsa_license_grace_until' => '2027-01-08' );
$fr_no_grace  = fr_run_activate_slice( $fr_act_ok_snip,
    array( 'key' => 'PREKEY-A', 'body' => array_merge( $fr_ok_body, array( 'grace_until' => null ) ) ), $fr_act_stale );
$fr_no_exp    = fr_run_activate_slice( $fr_act_ok_snip,
    array( 'key' => 'PREKEY-A', 'body' => array_merge( $fr_ok_body, array( 'expires_at' => '' ) ) ), $fr_act_stale );
ok( 'Activation answers edited by hand: no grace date stores none, and an empty expiry stores no grace date',
    array( fr_opt_value( $fr_no_grace['opts'], 'wpsa_license_expiration' ), fr_opt_value( $fr_no_grace['opts'], 'wpsa_license_grace_until' ),
           fr_opt_value( $fr_no_exp['opts'], 'wpsa_license_expiration' ), fr_opt_value( $fr_no_exp['opts'], 'wpsa_license_grace_until' ) ),
    array( '2027-09-09', '', '', '' ) );

// The licence form handler is registered on its admin-post action, and its nonce and
// capability checks come before both branches. Presence and order only: the handler
// cannot run here (wp_die/exit).
$fr_hf_body = fr_fn_body( $main, 'wpsa_handle_license_form' );
$fr_hf_at   = function ( $needle ) use ( $fr_hf_body ) {
    return is_string( $fr_hf_body ) ? strpos( $fr_hf_body, $needle ) : false;
};
$fr_hf_deact = $fr_hf_at( "if ( isset( \$_POST['wpsa_deactivate_license'] ) )" );
$fr_hf_act   = $fr_hf_at( "if ( isset( \$_POST['wpsa_activate_license'] ) )" );
$fr_hf_first = function ( $at ) use ( $fr_hf_deact, $fr_hf_act ) {
    return is_int( $at ) && is_int( $fr_hf_deact ) && is_int( $fr_hf_act ) && $at < $fr_hf_deact && $at < $fr_hf_act;
};
ok( 'Licence form handler: registered, with its nonce and capability checks before both branches',
    array( fr_contains( $main, "add_action( 'admin_post_wpsa_save_license', 'wpsa_handle_license_form' );" ),
           $fr_hf_first( $fr_hf_at( "check_admin_referer( 'wpsa_license_action', 'wpsa_license_nonce' );" ) ),
           $fr_hf_first( $fr_hf_at( "if ( ! current_user_can( 'manage_options' ) ) {" ) ) ),
    array( true, true, true ) );

// M6 — the headline defect of this entire release (F5, reproduced live by the
// operator) must never come back: activation must never fabricate an expiry.
// Nothing else in the automated suite catches its return, because AC-P11
// tests the upgrade-migration path, not the activation-store path. Whole-file
// scope on purpose — the fabrication is forbidden anywhere, and $main is
// pinned non-empty by AC-B6F0 above so this negative form cannot go green on
// an empty haystack.
ok( 'AC-B6F-M6 activation never re-fabricates a +1 month expiry',
    fr_contains( $main, "strtotime( '+1 month' )" ), false );

// M5 — wpsa_get_local_quota_snapshot() in PRODUCTION must derive its tier
// through wpsa_get_license_tier(), not by reading wpsa_saved_tier directly.
// The AC-D10-* assertions above now run the SHIPPED function: the subject file
// brace-matches it out of includes/helpers.php instead of stubbing it. These
// source pins stay as a second line of defence on the two properties that a
// passing functional test could still leave ambiguous — which helper the tier
// comes from, and that the option is not read directly — and they cost nothing,
// but on a slice whose two anchors are now exactly-once and order-checked,
// and read through fr_contains() so the "does not read" assertion reddens
// instead of passing vacuously if that slice ever comes back empty.
$fr_helpers = file_get_contents( __DIR__ . '/../includes/helpers.php' );
ok( 'AC-B6F0 includes/helpers.php source was read',
    ( is_string( $fr_helpers ) && strlen( $fr_helpers ) > 10000 ), true );
$fr_snapshot_fn = fr_slice_between( $fr_helpers,
    'function wpsa_get_local_quota_snapshot', 'function wpsa_get_conservative_quota_snapshot',
    'AC-B6F-M5 snapshot slice' );
ok( 'AC-B6F-M5 production snapshot delegates to wpsa_get_license_tier()',
    fr_contains( $fr_snapshot_fn, 'wpsa_get_license_tier()' ), true );
ok( 'AC-B6F-M5 production snapshot does not read wpsa_saved_tier directly',
    fr_contains( $fr_snapshot_fn, "get_option( 'wpsa_saved_tier'" ), false );

// ── Accepted residuals, fix-round 4. An accurate statement of what these
// guards do NOT cover, replacing fix-round 3's note — that note described the
// brace-depth mechanism, which no longer guards production control flow at all.
//
//  * NO LONGER residuals: `if ( … ) : … endif;` and a brace-less
//    `if ( … ) delete_option( … );` around the deletes. Both were listed as
//    deliberate limits while the guard counted braces; fr_run_deactivate_body()
//    executes the branch instead, so any syntax that skips a delete under a
//    scenario reddens. Both shapes were probed in this round and both go red.
//    They remain out of reach only for the text pins (the no_token carve-out,
//    M5, M6, AC-P8), which assert wording, not flow.
//  * The executed chunk runs from the deactivate branch's own `{` to its
//    set_transient() call. Everything after that — the transient write, the
//    wp_safe_redirect() and the exit — is not executed and not asserted;
//    wp_die/exit make the real handler unrunnable in-process, and that has
//    been true of this file since AC-P8.
//  * The handler's registration on admin_post_wpsa_save_license is pinned by
//    presence only. Nothing proves that control reaches the deactivate branch:
//    a change that returns earlier in the function is invisible to these
//    assertions.
//  * The scenario set is finite: transport error, empty stored token, and a
//    2xx success. A conditional wrapped around the cleanup that happens to be
//    TRUE in all three would pass — e.g. `if ( true )`. Every realistic
//    variant of the defect this guards (keyed on $deactivate_failed, on
//    $token, or on an undefined/absent function) is caught, but the guard is
//    scenario-bounded, not exhaustive.
//  * The activate branch runs in part. Its failure-message choice and its
//    success writes execute through fr_run_activate_slice(), and the
//    last_verified derivation also runs alone through
//    fr_run_last_verified_snippet(). Not executed: the key's read from the
//    form and its prefix check, the /activate request (the runner supplies
//    the answer), the notices, the transients, the redirects and exits, the
//    handler's registration on admin_post_wpsa_save_license, and its nonce
//    and capability checks. Of these, the success notice, the registration and
//    the nonce and capability checks are pinned, by presence only: the
//    notice's 'activated' code is the success slice's end anchor, and the
//    other three are checked just after the activation checks.
//  * Duplicating an anchor's text inside the string it is searched in is a
//    LOUD, named failure rather than a silent wrong match — deliberately.
//    That is the accepted cost of the exactly-once rule: a benign comment CAN
//    redden the suite, and the fix is to reword the comment. It is the right
//    trade, because the alternative is what defects 4a and 4b actually did —
//    pass green on broken code. Three text anchors are still searched over a
//    whole file rather than a slice: `wpsa_tier_rank( $incoming ) < …`,
//    `strtotime( '+1 month' )`, and `update_option( 'wpsa_saved_tier'`. All
//    three are absence-or-count assertions, so for them a duplicate IS the
//    signal rather than a hazard.
//  * wpsa_get_local_quota_snapshot() (M5) is still asserted by source
//    inspection rather than by execution: it is not liftable by
//    tests/_license-state-subject.php's extraction regex and would need a
//    wider stub surface than the assertion is worth. Its slice anchors are
//    exactly-once and order-checked, and it is read through fr_contains(), so
//    it fails closed — but it pins wording, not behaviour.
// AC-P11 (§5.1a) the fabricated expiry is discarded on upgrade.
$GLOBALS['opts'] = array(
    'wpsa_license_key'         => 'PREKEY',
    'wpsa_saved_tier'          => 'premium3',
    'wpsa_license_expiration'  => gmdate( 'Y-m-d', time() + 25 * DAY_IN_SECONDS ), // fiction
    'wpsa_license_grace_until' => gmdate( 'Y-m-d', time() + 32 * DAY_IN_SECONDS ),
    'wpsa_options_version'     => '1.19.0',
);
wpsa_maybe_migrate_license_options();
ok( 'AC-P11 expiry discarded', get_option( 'wpsa_license_expiration' ), false );
ok( 'Upgrade discards the grace date with the expiry', get_option( 'wpsa_license_grace_until' ), false );
ok( 'AC-P11 state unknown',    get_option( 'wpsa_license_state' ), 'unknown' );
ok( 'AC-P11 last_verified 0',  (int) get_option( 'wpsa_license_last_verified' ), 0 );
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array( 'code' => 503, 'body' => body( array( 'status' => 'unverified', 'tier' => 'free' ) ), 'error' => false );
ok( 'AC-P11 unverified after migration is free', wpsa_check_quota( 'ttfb' )['tier'], 'free' );
ok( 'AC-P11 idempotent', ( wpsa_maybe_migrate_license_options() === false ), true );

// AC-P11 the latch value written must be the one the gate reads, or the
// migration re-runs on every admin_init and keeps wiping the expiry.
ok( 'AC-P11 latch matches gate',
    get_option( 'wpsa_options_version' ), WPSA_LICENSE_OPTIONS_VERSION );
ok( 'AC-P11 second run is a no-op', wpsa_maybe_migrate_license_options(), false );
// And prove it stays latched across a fresh read, with a licence expiry stored
// in between — the state a real site is in on its second admin page load.
update_option( 'wpsa_license_expiration', '2027-05-01', false );
wpsa_maybe_migrate_license_options();
ok( 'AC-P11 does not re-wipe a stored expiry',
    get_option( 'wpsa_license_expiration' ), '2027-05-01' );

// AC-P14 (D16, Task B4) wpsa_license_over_cap_notice() must name the cap in
// the rendered sentence, not just signal a boolean — and must stay silent
// once the requesting site is itself the active one, or once allowed is true.
$over_cap_quota = array(
    'allowed' => false,
    'tier'    => 'premium1',
    'state'   => 'active',
    'sites'   => array( 'max' => 1, 'used' => 1, 'remaining' => 0, 'active' => false ),
);
ok( 'AC-P14 over-cap notice names the cap',
    wpsa_license_over_cap_notice( $over_cap_quota ),
    'This licence is already in use on its 1 allowed site. Deactivate it on another site, or upgrade to run more.' );

$over_cap_quota_active_site = $over_cap_quota;
$over_cap_quota_active_site['sites']['active'] = true;
ok( 'AC-P14 empty when the requesting site is the active one',
    wpsa_license_over_cap_notice( $over_cap_quota_active_site ), '' );

$over_cap_quota_allowed = $over_cap_quota;
$over_cap_quota_allowed['allowed'] = true;
ok( 'AC-P14 empty when allowed is true',
    wpsa_license_over_cap_notice( $over_cap_quota_allowed ), '' );

// ── The site limit is named where a site is actually blocked.
//
// The assertions above hand the helper an array built in PHP. The Tests tab,
// the PDF button and scheduled tests hand it whatever wpsa_check_quota()
// returned, so these feed a /check body through the HTTP stub as a JSON string
// and pass the PARSED answer on. A parser that stopped carrying `sites`
// through would leave every blocked site on the daily-limit message; these go
// red when that happens, the hand-built arrays above do not.
reset_state( array( 'wpsa_saved_tier' => 'premium1' ) );
$GLOBALS['http']['body'] = body( array(
    'tier' => 'premium1', 'allowed' => false, 'limit' => 30, 'remaining' => 30,
    'sites' => array( 'max' => 1, 'used' => 1, 'remaining' => 0, 'active' => false ),
) );
ok( 'Over the site limit (1 site): parsed answer yields the singular sentence',
    wpsa_license_over_cap_notice( wpsa_check_quota( 'ttfb' ) ),
    'This licence is already in use on its 1 allowed site. Deactivate it on another site, or upgrade to run more.' );

reset_state( array( 'wpsa_saved_tier' => 'premium2' ) );
$GLOBALS['http']['body'] = body( array(
    'tier' => 'premium2', 'allowed' => false, 'limit' => 100, 'remaining' => 100,
    'sites' => array( 'max' => 2, 'used' => 2, 'remaining' => 0, 'active' => false ),
) );
ok( 'Over the site limit (2 sites): parsed answer yields the plural sentence',
    wpsa_license_over_cap_notice( wpsa_check_quota( 'ttfb' ) ),
    'This licence is already in use on its 2 allowed sites. Deactivate it on another site, or upgrade to run more.' );

// A site that is itself one of the licence's active sites and is refused only
// because today's tests ran out: the helper must stay silent, so every
// blocking point keeps its daily-limit message. The parsed `allowed` and
// `active` are checked in the same assertion so the empty string cannot come
// from a parse that lost them.
reset_state( array( 'wpsa_saved_tier' => 'premium1' ) );
$GLOBALS['http']['body'] = body( array(
    'tier' => 'premium1', 'allowed' => false, 'limit' => 30, 'remaining' => 0,
    'sites' => array( 'max' => 1, 'used' => 1, 'remaining' => 0, 'active' => true ),
) );
$fr_daily = wpsa_check_quota( 'ttfb' );
ok( 'Daily limit only: parsed answer is a block for an active site, and no site-limit sentence',
    array( $fr_daily['allowed'] ?? null, $fr_daily['sites']['active'] ?? null, wpsa_license_over_cap_notice( $fr_daily ) ),
    array( false, true, '' ) );

// One pin per place a site is blocked, each failing if that place stops
// asking the helper. These are presence checks, nothing more: they catch the
// call being deleted, but not the call being made and its result ignored, and
// a comment that happened to name the call would satisfy them. The round
// trips above are what check the sentence itself.
//
// Same by-name regex tests/_license-state-subject.php extracts with: the body
// runs to the first `}` at column 0. Read only here, never eval'd.
function fr_fn_body( $src, $fn ) {
    return ( is_string( $src )
        && preg_match( '/function\s+' . $fn . '\s*\((?P<args>[^)]*)\)\s*\{(?P<body>.*?)\n\}/s', $src, $m ) )
        ? $m['body'] : null;
}
// wpsa_render_tool_page() draws the whole admin page, so this pin is scoped to
// that function, not to the Tests-tab branch: it stays green if the call moves
// elsewhere in the page.
ok( 'Tests tab: wpsa_render_tool_page() consults the site-limit helper',
    fr_contains( fr_fn_body( $main, 'wpsa_render_tool_page' ), 'wpsa_license_over_cap_notice(' ), true );
ok( 'PDF: wpsa_ajax_pdf_report() consults the site-limit helper',
    fr_contains( fr_fn_body( $main, 'wpsa_ajax_pdf_report' ), 'wpsa_license_over_cap_notice(' ), true );

// The scheduler's blocking point sits in wpsa_run_scheduled_tests(), which the
// regex above cannot lift: a closure inside it ends on a column-0 `};`, so the
// match stops well short of the function's real end. schedule.php makes
// exactly one wpsa_check_quota() call, so the file is this pin's scope.
$fr_schedule = file_get_contents( __DIR__ . '/../includes/schedule.php' );
ok( 'Scheduler: schedule.php consults the site-limit helper',
    fr_contains( $fr_schedule, 'wpsa_license_over_cap_notice(' ), true );
// URLs skipped later in the same run are not re-checked; they reuse the
// message the blocking point chose. The daily-limit sentence must therefore be
// written exactly once, in $daily_limit_message, which both the default and
// the blocking point's choice read. A second copy means a message field is
// hard-coded again, and an over-limit site would be told its daily limit ran
// out; zero copies would lose the daily-limit message itself. A different
// hard-coded sentence would not be caught.
ok( 'Scheduler: the daily-limit sentence is written once, as the default',
    is_string( $fr_schedule ) ? substr_count( $fr_schedule, "'Daily limit reached before running this test.'" ) : null, 1 );
// The blocking point picks between the two sentences in one statement, so the
// message it hands on can never be one left over from an earlier assignment.
// A presence pin, with the same limits as the ones above.
ok( 'Scheduler: the blocking point picks the site-limit or the daily-limit sentence',
    is_string( $fr_schedule ) ? substr_count( $fr_schedule, "\$limit_message = ( '' !== \$over_cap ) ? \$over_cap : \$daily_limit_message;" ) : null, 1 );

// ── The scheduled-test email's stop icon for a run that a limit stopped.
//
// The email chose 🛑 by finding "daily limit" in the message. The site-limit
// sentence is translated, so matching its words would fail on non-English
// sites. The scheduler now flags limit-stopped results instead, and
// wpsa_schedule_result_icon() (lifted from includes/schedule.php and run by
// tests/_license-state-subject.php) reads the flag, keeping the text match
// only for results stored before the flag existed.
$fr_over_cap_sentence = 'This licence is already in use on its 1 allowed site. Deactivate it on another site, or upgrade to run more.';
ok( 'Email icon: a test that ran',
    wpsa_schedule_result_icon( true, 'Test ran successfully.', false ), '✅' );
ok( 'Email icon: flagged site-limit result',
    wpsa_schedule_result_icon( false, $fr_over_cap_sentence, true ), '🛑' );
ok( 'Email icon: daily-limit result stored before the flag existed',
    wpsa_schedule_result_icon( false, 'Daily limit reached before running this test.', false ), '🛑' );
ok( 'Email icon: any other failure',
    wpsa_schedule_result_icon( false, 'Some other failure.', false ), '❌' );

// The flag is a stored field. Items sit in the wpsa_schedule_state option
// between cron ticks, and the finished batch is copied to
// wpsa_schedule_last_results; WordPress stores option arrays with serialize().
// So an item shaped the way the scheduler writes one goes through
// serialize()/unserialize(), and its fields are read back the way the email
// reads them. This proves the flag survives storage and, by itself, earns the
// stop icon for the site-limit sentence, which the text match cannot. It does
// not prove the scheduler writes the flag; the pins below do that.
$fr_stored = unserialize( serialize( array( 'ran_at' => 0, 'items' => array( array(
    'url'         => 'https://example.com/',
    'success'     => false,
    'test_no'     => null,
    'message'     => $fr_over_cap_sentence,
    'limit_block' => true,
) ) ) ) );
$fr_item = ( isset( $fr_stored['items'][0] ) && is_array( $fr_stored['items'][0] ) ) ? $fr_stored['items'][0] : array();
ok( 'Email icon: flagged site-limit item after a serialize() round trip',
    wpsa_schedule_result_icon( ! empty( $fr_item['success'] ),
        isset( $fr_item['message'] ) ? (string) $fr_item['message'] : '',
        ! empty( $fr_item['limit_block'] ) ),
    '🛑' );

// Presence pins, with the same limits as the ones above: they catch a call or
// a flag being deleted, not one written and then ignored, and a comment
// carrying the same text would satisfy them. They count across the whole of
// includes/schedule.php because neither enclosing function can be lifted by
// name: wpsa_schedule_send_batch_email() holds an `if` block that opens and
// closes at column 0, so the regex stops before either icon line, and
// wpsa_run_scheduled_tests() is the case described above. Each pin covers two
// places, so deleting the call or the flag at either one reddens it.
ok( 'Email: both icon lines pick the icon through the helper, passing the flag',
    is_string( $fr_schedule ) ? substr_count( $fr_schedule, "wpsa_schedule_result_icon( \$success, \$message, ! empty( \$item['limit_block'] ) )" ) : null, 2 );
ok( 'Scheduler: the blocking point and the skip branch both flag their item',
    is_string( $fr_schedule ) ? substr_count( $fr_schedule, "'limit_block' => true," ) : null, 2 );

// ── The licence panel and the expiry notice: one plan name, one days number.
//
// Both surfaces take their plan name and days number from
// wpsa_license_display() (includes/license-notice.php). The inputs below are the
// answers the real decide() in cloudflare/gatekeeper/decide.js returns, stored
// once in tests/_license-display-fixtures.json. tests/gatekeeper-decide-harness.js
// checks that decide() still returns exactly those answers, so they cannot drift
// from their producer.
require_once __DIR__ . '/../includes/license-notice.php';
$fr_fx       = json_decode( (string) file_get_contents( __DIR__ . '/_license-display-fixtures.json' ), true );
$fr_fx_cases = array();
foreach ( ( is_array( $fr_fx ) && isset( $fr_fx['cases'] ) && is_array( $fr_fx['cases'] ) ) ? $fr_fx['cases'] : array() as $fr_c ) {
    $fr_fx_cases[ $fr_c['id'] ] = $fr_c;
}
ok( 'Display fixture: the eleven shared decide() answers were read', array_keys( $fr_fx_cases ),
    array( 'grace_day_2', 'expired_40_days', 'boundary_10_days_1800', 'boundary_10_days_0000', 'active_200_days',
           'active_perpetual', 'sold_perpetual', 'not_found_foreign_product', 'inactive_perpetual', 'disabled_dated', 'free_no_key' ) );

// body()'s hand-built default is the v4 answer decide() gives a perpetual AGENCY key.
// Pinned to the producer's own answer, so the default cannot drift from it.
$fr_default = json_decode( body(), true );
$fr_perp    = isset( $fr_fx_cases['active_perpetual'] ) ? $fr_fx_cases['active_perpetual']['answer'] : array();
unset( $fr_default['fetched_at'], $fr_default['age_s'], $fr_perp['fetched_at'], $fr_perp['age_s'] );
ksort( $fr_default );
ksort( $fr_perp );
ok( "body()'s default equals decide()'s answer for a perpetual AGENCY key", $fr_default, $fr_perp );

// Every state the service can send is stored as received, and the old reason option
// is never written. The answers come from decide() through the shared fixture.
foreach ( $fr_fx_cases as $fr_id => $fr_c ) {
    if ( 'free_no_key' === $fr_id ) {
        continue; // no key stored: the plugin never asks the service
    }
    reset_state( array( 'wpsa_saved_tier' => 'premium3' ) );
    $GLOBALS['http']['body'] = json_encode( $fr_c['answer'] );
    wpsa_check_quota( 'ttfb' );
    ok( "State stored as received, $fr_id",
        array( get_option( 'wpsa_license_state' ), array_key_exists( 'wpsa_license_reason', $GLOBALS['opts'] ) ),
        array( $fr_c['answer']['state'], false ) );
}

/** The helper's answer for one fixture case, counted from that case's own "now". */
function fr_display_for( array $case, $last_paid ) {
    $a = $case['answer'];
    return wpsa_license_display( (string) $a['state'], (string) $a['tier'], $last_paid,
        (string) $a['expires_at'], (string) $a['grace_until'], intdiv( (int) $case['input']['nowMs'], 1000 ) );
}
foreach ( array(
    // Two days into grace. The service's own days_left is 0; five days of access are left.
    'grace_day_2'           => array( 'premium3', array( 'label' => 'AGENCY', 'days' => 5 ) ),
    // Forty days expired. The service reports free; the plan that lapsed is named.
    'expired_40_days'       => array( 'premium3', array( 'label' => 'AGENCY', 'days' => -40 ) ),
    // Ten calendar days out, at 18:00 and at 00:00 UTC. The service says 11 and 10;
    // both surfaces count calendar days, so both read 10.
    'boundary_10_days_1800' => array( '', array( 'label' => 'AGENCY', 'days' => 10 ) ),
    'boundary_10_days_0000' => array( '', array( 'label' => 'AGENCY', 'days' => 10 ) ),
    'active_200_days'       => array( '', array( 'label' => 'AGENCY', 'days' => 200 ) ),
) as $fr_id => $fr_w ) {
    ok( "Display helper, $fr_id: plan name and days",
        isset( $fr_fx_cases[ $fr_id ] ) ? fr_display_for( $fr_fx_cases[ $fr_id ], $fr_w[0] ) : null, $fr_w[1] );
}
ok( 'Display helper, expired_40_days with no lapsed plan stored: falls back to the tier',
    isset( $fr_fx_cases['expired_40_days'] ) ? fr_display_for( $fr_fx_cases['expired_40_days'], '' )['label'] : null, 'Free' );
ok( 'Display helper, grace: a passed grace date reads 0, never negative; no grace date reads null',
    array( wpsa_license_display( 'grace', 'premium3', '', '2027-06-13', '2027-06-20', strtotime( '2027-06-23 12:00:00 UTC' ) )['days'],
           wpsa_license_display( 'grace', 'premium3', '', '2027-06-13', '', strtotime( '2027-06-15 12:00:00 UTC' ) )['days'] ),
    array( 0, null ) );

// ── Both render sites, driven through the real producer chain.
//
// Each fixture answer goes as JSON through the HTTP stub into the real
// wpsa_check_quota(). The real licence panel then renders from what that call
// returns, and the real notice from the options that call wrote. The answer's two
// dates and fetched_at move by whole days, so the case reads the same today as on
// the fixture's own date; nothing else in the answer changes. A render site that
// stops asking wpsa_license_display() prints the service's clamped days_left or
// the free tier's name here, and its assertion goes red.
//
// WordPress stand-ins for the two render functions. PHP defines every top-level
// function in this file before the first line runs, so these exist for the
// checks above as well.
function get_current_user_id() { return 1; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function esc_html_e( $t, $d = 'default' ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = 'default' ) { echo esc_attr( $t ); }
function esc_html__( $t, $d = 'default' ) { return esc_html( $t ); }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function wp_nonce_field( $a = -1, $n = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $n ) . '" value="nonce">'; }
function wp_kses( $s, $allowed ) { return (string) $s; }
function date_i18n( $f, $ts ) { return gmdate( 'Y-m-d', (int) $ts ); }
function get_current_screen() { return (object) array( 'id' => 'tools_page_speed-analyzer' ); }
function current_user_can( $cap ) { return true; }
function get_user_meta( $uid, $key, $single = false ) { return ''; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }

// The panel's render function, lifted from includes/lpanel.php by the same
// by-name regex as fr_fn_body() above and run for real. As with every eval() in
// this file, it only ever sees this repo's own source read from disk, in a CLI
// test: never user or network input.
$fr_lpanel     = file_get_contents( __DIR__ . '/../includes/lpanel.php' );
$fr_panel_body = fr_fn_body( $fr_lpanel, 'wpsa_render_license_panel_ui' );
$fr_panel_ok   = false;
if ( is_string( $fr_panel_body ) && ! function_exists( 'wpsa_render_license_panel_ui' ) ) {
    try {
        eval( 'function wpsa_render_license_panel_ui() {' . $fr_panel_body . "\n}" );
        $fr_panel_ok = true;
    } catch ( Throwable $e ) {
        $fr_panel_ok = false;
    }
}
ok( 'Panel: the real render function was lifted from includes/lpanel.php', $fr_panel_ok, true );

/** Runs one render function; returns its output and any PHP warning, notice or error it raised. */
function fr_capture( $fn ) {
    $raised = array();
    set_error_handler( function ( $errno, $errstr ) use ( &$raised ) {
        $raised[] = $errstr;
        return true;
    } );
    ob_start();
    try {
        if ( ! function_exists( $fn ) ) {
            throw new RuntimeException( "$fn is not defined" );
        }
        $fn();
    } catch ( Throwable $e ) {
        $raised[] = get_class( $e ) . ': ' . $e->getMessage();
    } finally {
        $html = ob_get_clean();
        restore_error_handler();
    }
    return array( 'html' => $html, 'raised' => $raised );
}

/** Stores one fixture answer through the real check, then renders the panel and the notice. */
function fr_render_both( array $case, array $start ) {
    $a     = $case['answer'];
    $then  = intdiv( (int) $case['input']['nowMs'], 1000 );
    $shift = strtotime( gmdate( 'Y-m-d', time() ) . ' 00:00:00 UTC' ) - strtotime( gmdate( 'Y-m-d', $then ) . ' 00:00:00 UTC' );
    foreach ( array( 'expires_at', 'grace_until' ) as $k ) {
        if ( is_string( $a[ $k ] ) ) {
            $a[ $k ] = gmdate( 'Y-m-d', strtotime( $a[ $k ] . ' 00:00:00 UTC' ) + $shift );
        }
    }
    $a['fetched_at'] = gmdate( 'c', strtotime( (string) $a['fetched_at'] ) + $shift );
    reset_state( $start );
    $GLOBALS['http']['body'] = json_encode( $a );
    $panel  = fr_capture( 'wpsa_render_license_panel_ui' );
    $notice = fr_capture( 'wpsa_render_license_notice' );
    return array( 'panel' => $panel['html'], 'notice' => $notice['html'],
                  'raised' => array_merge( $panel['raised'], $notice['raised'] ) );
}

$fr_paying = array( 'wpsa_saved_tier' => 'premium3' );
foreach ( array(
    'grace_day_2'           => 'AGENCY — licence expired; 5 days of access remaining',
    'expired_40_days'       => 'Licence expired — renew to restore AGENCY',
    'boundary_10_days_1800' => 'AGENCY — expires in 10 days',
    'boundary_10_days_0000' => 'AGENCY — expires in 10 days',
) as $fr_id => $fr_line ) {
    $fr_r = isset( $fr_fx_cases[ $fr_id ] ) ? fr_render_both( $fr_fx_cases[ $fr_id ], $fr_paying ) : array();
    ok( "Panel, $fr_id: renders '$fr_line', cleanly",
        array( fr_contains( $fr_r['panel'] ?? null, $fr_line ), $fr_r['raised'] ?? null ), array( true, array() ) );
    ok( "Notice, $fr_id: renders '$fr_line'", fr_contains( $fr_r['notice'] ?? null, $fr_line ), true );
}
$fr_r = isset( $fr_fx_cases['active_200_days'] ) ? fr_render_both( $fr_fx_cases['active_200_days'], $fr_paying ) : array();
ok( 'Panel and notice, active_200_days: the plan name alone, and no notice',
    array( fr_contains( $fr_r['panel'] ?? null, 'AGENCY' ), fr_contains( $fr_r['panel'] ?? null, 'expires in' ),
           // A site with its key in place is never "deactivated", whatever the panel
           // says about the paid days that outlive a deactivation.
           fr_contains( $fr_r['panel'] ?? null, 'deactivated' ),
           $fr_r['notice'] ?? null, $fr_r['raised'] ?? null ),
    array( true, false, false, '', array() ) );

// Every keyed state the service sends, rendered on both real surfaces. The panel
// prints its line through wp_kses(), beside the warning icon; the notice escapes
// the line, so an apostrophe arrives as &#039; there.
foreach ( array(
    'sold_perpetual'            => array( 'Your licence has been paid for but not yet delivered. Please contact support.', 'Contact' ),
    'not_found_foreign_product' => array( "We don't recognise that licence key — check it for typos", '' ),
    'inactive_perpetual'        => array( 'This licence is no longer active', 'Renew' ),
    'disabled_dated'            => array( 'This licence is no longer active', 'Renew' ),
) as $fr_id => $fr_w ) {
    list( $fr_line, $fr_button ) = $fr_w;
    $fr_r = isset( $fr_fx_cases[ $fr_id ] ) ? fr_render_both( $fr_fx_cases[ $fr_id ], $fr_paying ) : array();
    ok( "Panel, $fr_id: renders '$fr_line' and the warning icon, cleanly",
        array( fr_contains( $fr_r['panel'] ?? null, $fr_line ), fr_contains( $fr_r['panel'] ?? null, '⚠️' ),
               $fr_r['raised'] ?? null ),
        array( true, true, array() ) );
    ok( "Notice, $fr_id: renders '$fr_line', its button, and no close control",
        array( fr_contains( $fr_r['notice'] ?? null, esc_html( $fr_line ) ),
               fr_contains( $fr_r['notice'] ?? null, 'class="button">Renew</a>' ),
               fr_contains( $fr_r['notice'] ?? null, 'class="button">Contact</a>' ),
               fr_contains( $fr_r['notice'] ?? null, 'is-dismissible' ) ),
        array( true, 'Renew' === $fr_button, 'Contact' === $fr_button, false ) );
}
$fr_r = isset( $fr_fx_cases['active_perpetual'] ) ? fr_render_both( $fr_fx_cases['active_perpetual'], $fr_paying ) : array();
ok( 'Panel and notice, active_perpetual: the plan name alone, and no notice',
    array( fr_contains( $fr_r['panel'] ?? null, 'AGENCY' ), fr_contains( $fr_r['panel'] ?? null, 'expires in' ),
           $fr_r['notice'] ?? null, $fr_r['raised'] ?? null ),
    array( true, false, '', array() ) );

// Deactivated, with paid days still running (D10): the panel names the plan, says the
// licence is deactivated but still valid, and shows the date those days run to.
$fr_deact_exp = gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS );
reset_state( array( 'wpsa_license_key' => '', 'wpsa_saved_tier' => 'premium1',
                    'wpsa_license_state' => 'free', 'wpsa_license_expiration' => $fr_deact_exp ) );
$fr_dp = fr_capture( 'wpsa_render_license_panel_ui' );
ok( 'Panel, deactivated with paid days left: PRO, still valid, and the date',
    array( fr_contains( $fr_dp['html'], 'PRO — deactivated, still valid' ),
           fr_contains( $fr_dp['html'], $fr_deact_exp ),
           fr_contains( $fr_dp['html'], 'No expiry' ),
           $fr_dp['raised'] ),
    array( true, true, false, array() ) );

// A paid site whose licence service is unreachable, with nothing cached, must see its
// date too — the same missing field showed "No expiry" there.
reset_state( array( 'wpsa_license_key' => 'PREKEY', 'wpsa_saved_tier' => 'premium3',
                    'wpsa_license_state' => 'active', 'wpsa_license_expiration' => $fr_deact_exp ) );
$GLOBALS['http'] = array( 'code' => 503, 'body' => body( array( 'status' => 'unverified' ) ), 'error' => false );
$fr_up = fr_capture( 'wpsa_render_license_panel_ui' );
ok( 'Panel, paid site with the service unreachable: its date, and not "No expiry"',
    array( fr_contains( $fr_up['html'], $fr_deact_exp ), fr_contains( $fr_up['html'], 'No expiry' ), $fr_up['raised'] ),
    array( true, false, array() ) );

// A site that never had a licence — or whose paid days have run out — is plain Free.
// The deactivated line belongs only to a plan that is still running.
reset_state( array( 'wpsa_license_key' => '', 'wpsa_saved_tier' => 'free',
                    'wpsa_license_state' => 'free', 'wpsa_license_expiration' => '' ) );
$fr_fp = fr_capture( 'wpsa_render_license_panel_ui' );
ok( 'Panel, free site with no key: no deactivated line',
    array( fr_contains( $fr_fp['html'], 'deactivated' ), $fr_fp['raised'] ),
    array( false, array() ) );
// A stored state the panel does not name (a pre-v4 'invalid', or anything unexpected),
// reached with the service unreachable and nothing cached: the plan name, no notice.
foreach ( array( 'invalid', 'something-else' ) as $fr_state ) {
    reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_state' => $fr_state,
                        'wpsa_license_expiration' => gmdate( 'Y-m-d', time() + 200 * DAY_IN_SECONDS ) ) );
    $GLOBALS['http'] = array( 'code' => 503, 'body' => body( array( 'status' => 'unverified' ) ), 'error' => false );
    $fr_p = fr_capture( 'wpsa_render_license_panel_ui' );
    $fr_n = fr_capture( 'wpsa_render_license_notice' );
    ok( "Stored '$fr_state': the plan name, no notice, cleanly",
        array( fr_contains( $fr_p['html'], 'AGENCY' ), $fr_n['html'], array_merge( $fr_p['raised'], $fr_n['raised'] ) ),
        array( true, '', array() ) );
}

// The grace date travels from the service's answer into its own option and back out
// through the display helper: grace_day_2 still reads five days of access.
$fr_g = isset( $fr_fx_cases['grace_day_2'] ) ? $fr_fx_cases['grace_day_2'] : null;
if ( is_array( $fr_g ) ) {
    reset_state( array( 'wpsa_saved_tier' => 'premium3' ) );
    $GLOBALS['http']['body'] = json_encode( $fr_g['answer'] );
    wpsa_check_quota( 'ttfb' );
}
ok( 'Grace date stored as received and read back into five days of access',
    is_array( $fr_g ) ? array( get_option( 'wpsa_license_grace_until' ),
        wpsa_license_display( 'grace', 'premium3', '', (string) get_option( 'wpsa_license_expiration' ),
            (string) get_option( 'wpsa_license_grace_until' ), intdiv( (int) $fr_g['input']['nowMs'], 1000 ) )['days'] ) : null,
    array( '2027-06-20', 5 ) );
// The same answer, with its grace date deliberately not a week after the expiry, so a store that computes its own date fails.
if ( is_array( $fr_g ) ) {
    reset_state( array( 'wpsa_saved_tier' => 'premium3' ) );
    $GLOBALS['http']['body'] = json_encode( array_merge( $fr_g['answer'], array( 'grace_until' => '2027-06-24' ) ) );
    wpsa_check_quota( 'ttfb' );
}
ok( 'A grace date that is not a week after the expiry is stored as the service sent it',
    is_array( $fr_g ) ? array( get_option( 'wpsa_license_expiration' ), get_option( 'wpsa_license_grace_until' ) ) : null,
    array( '2027-06-13', '2027-06-24' ) );
// The same answer with its grace date removed by hand, over a stale stored pair: the grace date is stored empty, never computed here.
if ( is_array( $fr_g ) ) {
    reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_expiration' => '2027-01-01', 'wpsa_license_grace_until' => '2027-01-08' ) );
    $GLOBALS['http']['body'] = json_encode( array_merge( $fr_g['answer'], array( 'grace_until' => null ) ) );
    wpsa_check_quota( 'ttfb' );
}
ok( 'An answer edited by hand to carry no grace date stores its expiry and an empty grace date',
    is_array( $fr_g ) ? array( get_option( 'wpsa_license_expiration' ), get_option( 'wpsa_license_grace_until' ) ) : null,
    array( '2027-06-13', '' ) );

// The expiry and its grace date move together. A missing answer returns null, so every
// check below fails on its own rather than passing on a run that never happened.
$fr_pair = function ( $fr_id, array $start ) use ( $fr_fx_cases ) {
    if ( ! isset( $fr_fx_cases[ $fr_id ] ) ) {
        return null;
    }
    reset_state( $start );
    $GLOBALS['http']['body'] = json_encode( $fr_fx_cases[ $fr_id ]['answer'] );
    wpsa_check_quota( 'ttfb' );
    return array( get_option( 'wpsa_license_expiration' ), get_option( 'wpsa_license_grace_until' ) );
};
$fr_old = array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_expiration' => '2027-01-01', 'wpsa_license_grace_until' => '2027-01-08' );
ok( 'An answer with an expiry writes both dates (grace)', $fr_pair( 'grace_day_2', $fr_old ), array( '2027-06-13', '2027-06-20' ) );
ok( 'An answer with an expiry writes both dates, whatever its state (disabled)', $fr_pair( 'disabled_dated', $fr_old ), array( '2027-09-01', '2027-09-08' ) );
ok( 'A perpetual active answer clears both dates', $fr_pair( 'active_perpetual', $fr_old ), array( '', '' ) );
foreach ( array( 'sold_perpetual', 'inactive_perpetual', 'free_no_key' ) as $fr_id ) {
    ok( "An answer without an expiry leaves both dates ($fr_id)", $fr_pair( $fr_id, $fr_old ), array( '2027-01-01', '2027-01-08' ) );
}

// The offline hold: the service's grace date, else the expiry date alone, else 14 days.
// wpsa_license_unverified_snapshot() reads time(), so the boundaries are today and yesterday.
// That tests the day, not the time of day: a cutoff moved earlier in the day is caught only
// by a run made after the new cutoff, and one moved to 23:59:58 is never caught.
$fr_today     = gmdate( 'Y-m-d', time() );
$fr_yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
$fr_long_ago  = gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS );
$fr_hold = function ( array $opts ) {
    reset_state( array_merge( array( 'wpsa_saved_tier' => 'premium3' ), $opts ) );
    $GLOBALS['http'] = array( 'code' => 503, 'body' => body( array( 'status' => 'unverified' ) ), 'error' => false );
    return wpsa_check_quota( 'ttfb' )['tier'];
};
ok( 'Offline hold, grace date today: held', $fr_hold( array( 'wpsa_license_grace_until' => $fr_today, 'wpsa_license_expiration' => $fr_long_ago ) ), 'premium3' );
ok( 'Offline hold, grace date yesterday: free', $fr_hold( array( 'wpsa_license_grace_until' => $fr_yesterday, 'wpsa_license_expiration' => $fr_long_ago ) ), 'free' );
ok( 'Offline hold, no grace date, expiry today: held', $fr_hold( array( 'wpsa_license_expiration' => $fr_today ) ), 'premium3' );
ok( "Offline hold, no grace date, expiry yesterday: free (no grace without the service's date)", $fr_hold( array( 'wpsa_license_expiration' => $fr_yesterday ) ), 'free' );
ok( 'Offline hold, no dates, verified 13 days ago: held', $fr_hold( array( 'wpsa_license_last_verified' => time() - 13 * DAY_IN_SECONDS ) ), 'premium3' );
ok( 'Offline hold, no dates, verified 15 days ago: free', $fr_hold( array( 'wpsa_license_last_verified' => time() - 15 * DAY_IN_SECONDS ) ), 'free' );

// The grace days from the service's date equal the seven-day count used before
// (7 + the days since the expiry), for three expiry times of day and every hour of the
// grace week. The 7 here is the oracle, written independently of the code under test.
$fr_sweep_bad = 0;
foreach ( array( '00:00:01', '12:00:00', '23:59:59' ) as $fr_t ) {
    $fr_exp_ts = (int) strtotime( '2027-06-13 ' . $fr_t . ' UTC' );
    $fr_exp    = gmdate( 'Y-m-d', $fr_exp_ts );
    $fr_grace  = gmdate( 'Y-m-d', $fr_exp_ts + 7 * DAY_IN_SECONDS );
    for ( $fr_h = 0; $fr_h < 7 * 24; $fr_h++ ) {
        $fr_now = $fr_exp_ts + $fr_h * 3600;
        $fr_was = max( 0, 7 + (int) wpsa_license_notice_days_left( $fr_exp, $fr_now ) );
        $fr_is  = wpsa_license_display( 'grace', 'premium3', '', $fr_exp, $fr_grace, $fr_now )['days'];
        if ( $fr_was !== $fr_is ) {
            $fr_sweep_bad++;
        }
    }
}
ok( 'Grace days unchanged over 504 grace-week hours', $fr_sweep_bad, 0 );

// The service unreachable with nothing cached: the unverified snapshot carries no
// dates, so the grace count on both surfaces must come from the stored grace date.
$fr_out = array( 'html' => null, 'raised' => array() );
if ( isset( $fr_fx_cases['grace_day_2'] ) ) {
    fr_render_both( $fr_fx_cases['grace_day_2'], $fr_paying );
    $GLOBALS['transients'] = array();
    $GLOBALS['http']       = array( 'code' => 503, 'body' => body( array( 'status' => 'unverified' ) ), 'error' => false );
    $fr_out = fr_capture( 'wpsa_render_license_panel_ui' );
}
ok( 'Panel, service unreachable and nothing cached: the grace days still come from the stored grace date',
    array( fr_contains( $fr_out['html'], 'AGENCY — licence expired; 5 days of access remaining' ), $fr_out['raised'] ),
    array( true, array() ) );
$fr_out_n = isset( $fr_fx_cases['grace_day_2'] ) ? fr_capture( 'wpsa_render_license_notice' ) : array( 'html' => null, 'raised' => array() );
ok( 'Notice, service unreachable and nothing cached: the grace days come from the stored grace date',
    array( fr_contains( $fr_out_n['html'], 'AGENCY — licence expired; 5 days of access remaining' ), $fr_out_n['raised'] ),
    array( true, array() ) );

// A grace date the service set to something other than a week after the expiry. Two
// days after the expiry, a week counted from the expiry would leave 5 days of access;
// the stored grace date leaves 8, and both surfaces must print 8.
reset_state( array( 'wpsa_saved_tier' => 'premium3', 'wpsa_license_state' => 'grace',
                    'wpsa_license_expiration'  => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
                    'wpsa_license_grace_until' => gmdate( 'Y-m-d', time() + 8 * DAY_IN_SECONDS ) ) );
$GLOBALS['http'] = array( 'code' => 503, 'body' => body( array( 'status' => 'unverified' ) ), 'error' => false );
$fr_gp = fr_capture( 'wpsa_render_license_panel_ui' );
$fr_gn = fr_capture( 'wpsa_render_license_notice' );
ok( 'Panel and notice, a grace date other than a week after the expiry: both count the days to it, cleanly',
    array( fr_contains( $fr_gp['html'], 'AGENCY — licence expired; 8 days of access remaining' ),
           fr_contains( $fr_gn['html'], 'AGENCY — licence expired; 8 days of access remaining' ),
           array_merge( $fr_gp['raised'], $fr_gn['raised'] ) ),
    array( true, true, array() ) );

// One mapping. The plan names live in the helper alone, and each render site asks
// it. Presence pins: they catch a site that keeps a copy of its own, or reads the
// service's days_left, even when that copy happens to print the same thing.
$fr_notice_src  = file_get_contents( __DIR__ . '/../includes/license-notice.php' );
$fr_notice_body = fr_fn_body( $fr_notice_src, 'wpsa_render_license_notice' );
ok( 'Panel render site: asks the helper once, keeps no plan names, never reads days_left',
    array( is_string( $fr_panel_body ) ? substr_count( $fr_panel_body, 'wpsa_license_display(' ) : null,
           fr_contains( $fr_panel_body, "'AGENCY'" ), fr_contains( $fr_panel_body, "'days_left'" ) ),
    array( 1, false, false ) );
ok( 'Notice render site: asks the helper once, keeps no plan names',
    array( is_string( $fr_notice_body ) ? substr_count( $fr_notice_body, 'wpsa_license_display(' ) : null,
           fr_contains( $fr_notice_body, "'AGENCY'" ) ),
    array( 1, false ) );
ok( 'The plan-name map is written once across the panel and the notice',
    ( is_string( $fr_lpanel ) && is_string( $fr_notice_src ) ) ? substr_count( $fr_lpanel . $fr_notice_src, "'AGENCY'" ) : null, 1 );

// Count gate (fix-round 5). Deliberately NOT routed through ok(), which
// would increment the very number under test.
if ( FR_EXPECTED_CHECKS !== $checks ) {
    $fails++;
    printf( "FAIL harness assertion count drifted from FR_EXPECTED_CHECKS\n  expected %d\n  actual   %d\n",
        FR_EXPECTED_CHECKS, $checks );
}

// End marker. Set BEFORE either exit path below, so the shutdown guard stays
// silent on a normal run whether that run passed or failed, and fires only
// when control never got here at all.
$GLOBALS['fr_reached_end'] = true;

if ( $fails ) { fwrite( STDERR, "$fails of $checks check(s) failed\n" ); exit( 1 ); }
echo "license state harness passed ($checks assertions)\n";
