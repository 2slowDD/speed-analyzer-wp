<?php
declare( strict_types=1 );
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }

// ---- Early-termination guard and check-count gate -------------------------------
// A bare exit or die anywhere in the run (this file, the plugin, a stand-in) ends
// PHP with status 0 and no output, which the suite runner scores as a pass. The
// shutdown function below turns any stop before the end marker into a failure,
// and the gate at the bottom fails the run unless exactly DLMY_EXPECTED_CHECKS
// checks ran, so a block that silently stops running is caught as well. Same
// pattern as tests/license-state-harness.php.
//
// When you add or remove a check on purpose, update this number.
define( 'DLMY_EXPECTED_CHECKS', 320 );

$GLOBALS['dlmy_reached_end'] = false;
register_shutdown_function( function () {
    if ( ! empty( $GLOBALS['dlmy_reached_end'] ) ) {
        return; // Normal end, pass or fail: the run already reported for itself.
    }
    $err   = error_get_last();
    $fatal = ( is_array( $err ) && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) )
        ? sprintf( "\n  Last PHP error: %s in %s:%d", $err['message'], $err['file'], (int) $err['line'] )
        : '';
    fwrite( STDERR, sprintf( "FAIL dlm yearly extend harness stopped before its end marker\n  %d of %d expected check(s) had run.%s\n",
        (int) ( $GLOBALS['checks'] ?? 0 ), DLMY_EXPECTED_CHECKS, $fatal ) );
    exit( 1 );
} );

// The production file uses WordPress's DAY_IN_SECONDS. Define it the way
// WordPress does so the file can be loaded from the command line.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
// Stand-ins for WordPress's hook API, WooCommerce and DLM. Loaded first, so that loading the
// plugin below runs its real registration code against WordPress's hook functions.
require __DIR__ . '/_dlm-yearly-stubs.php';
require __DIR__ . '/../cloudflare/mu-plugins/wpsa-dlm-yearly.php';

$fails  = 0;
$checks = 0;
function ok( $n, $a, $e ) { global $fails, $checks; $checks++; if ( $a !== $e ) { $fails++; printf( "FAIL %s expected %s actual %s\n", $n, var_export( $e, true ), var_export( $a, true ) ); } }
function picked_id( array $rows, $order_id = 0 ) { $r = wpsa_dlmy_pick_existing_license( $rows, $order_id ); return null === $r ? null : $r['id']; }
function row( $id, $status, $created, $order_id = 0 ) { return array( 'id' => $id, 'status' => $status, 'created_at' => $created, 'order_id' => $order_id ); }

$now = strtotime( '2027-06-15 12:00:00 UTC' );

// ---- New expiry date --------------------------------------------------------
// 40 days left: the new year goes on top of them (365 + 40 days from now).
// 2028 is a leap year, so 29 February falls inside the added year.
ok( 'extends from the current expiry', wpsa_dlmy_compute_extension( '2027-07-25 12:00:00', $now ), '2028-07-24 12:00:00' );
// Expired 30 days ago: a full year from now, not from the old date.
ok( 'expired licence extends from now', wpsa_dlmy_compute_extension( '2027-05-16 12:00:00', $now ), '2028-06-14 12:00:00' );
// A licence that never expires is never shortened.
ok( 'never-expiring (empty) untouched', wpsa_dlmy_compute_extension( '', $now ), '' );
ok( 'never-expiring (null) untouched', wpsa_dlmy_compute_extension( null, $now ), '' );
// Digital License Manager also treats the MySQL zero date as "never expires".
ok( 'zero date treated as never-expiring', wpsa_dlmy_compute_extension( '0000-00-00 00:00:00', $now ), '' );
// Expiry dates are stored in UTC; the answer must not move with PHP's default timezone.
$tz = date_default_timezone_get();
date_default_timezone_set( 'America/New_York' );
ok( 'reads the stored date as UTC', wpsa_dlmy_compute_extension( '2027-07-25 12:00:00', $now ), '2028-07-24 12:00:00' );
date_default_timezone_set( $tz );

// ---- Which existing licence a repeat purchase extends ----------------------
ok( 'live status beats recency',
    picked_id( array( row( 1, 3, '2025-01-01 00:00:00' ), row( 2, 1, '2026-01-01 00:00:00' ) ) ), 1 );
ok( 'disabled is never chosen', picked_id( array( row( 9, 5, '2026-01-01 00:00:00' ) ) ), null );
// A sold-but-undelivered key must still be chosen, or the repeat purchase issues a duplicate.
ok( 'sold is chosen when it is the only candidate', picked_id( array( row( 7, 1, '2026-01-01 00:00:00' ) ) ), 7 );
// Status read straight from the database arrives as a numeric string.
ok( 'numeric-string status is honoured', picked_id( array( row( 4, '3', '2026-01-01 00:00:00' ) ) ), 4 );
ok( 'newest wins within the same status',
    picked_id( array( row( 21, 3, '2025-01-01 00:00:00' ), row( 22, 3, '2026-01-01 00:00:00' ) ) ), 22 );

// Full order, peeled one pick at a time: active, delivered, inactive, sold; never disabled.
$d     = '2026-01-01 00:00:00';
$pool  = array( row( 11, 1, $d ), row( 12, 2, $d ), row( 13, 3, $d ), row( 14, 4, $d ), row( 15, 5, $d ) );
$order = array();
while ( null !== ( $id = picked_id( $pool ) ) ) {
    $order[] = $id;
    $pool    = array_values( array_filter( $pool, function ( $r ) use ( $id ) { return $r['id'] !== $id; } ) );
}
ok( 'priority is active, delivered, inactive, sold; never disabled', $order, array( 13, 12, 14, 11 ) );

// A key issued by the order being processed is not a previous purchase.
ok( 'key from this same order is ignored', picked_id( array( row( 31, 3, $d, 900 ) ), 900 ), null );
ok( 'key from an earlier order is chosen', picked_id( array( row( 31, 3, $d, 800 ) ), 900 ), 31 );

// ---- Whether to act on an order line ----------------------------------------
ok( 'first purchase of a plan is looked up', wpsa_dlmy_rebuy_step( true, 11208, 5, 1, '', true ), 'lookup' );
foreach ( array( 11208, 11413, 11414 ) as $pid ) {
    ok( "product $pid is a Speed Analyzer plan", wpsa_dlmy_rebuy_step( true, $pid, 5, 1, '', true ), 'lookup' );
}
ok( 'other products are left to DLM', wpsa_dlmy_rebuy_step( true, 999, 5, 1, '', true ), 'pass' );
// Guest licences are all stored under user id 0, so a guest must never be matched.
ok( 'guest orders are left to DLM', wpsa_dlmy_rebuy_step( true, 11208, 0, 1, '', true ), 'pass' );
ok( 'multi-unit lines are left to DLM', wpsa_dlmy_rebuy_step( true, 11208, 5, 2, '', true ), 'pass' );
ok( 'an earlier skip by other code is respected', wpsa_dlmy_rebuy_step( false, 11208, 5, 1, '', true ), 'pass' );
ok( 'a recorded extension stops a second one', wpsa_dlmy_rebuy_step( true, 11208, 5, 1, '42', true ), 'done' );
// Only a paid order extends a licence. The check runs after the replay mark, so a line
// handled while the order was paid stays handled whatever the order's status is now.
ok( 'an unpaid order is left to DLM', wpsa_dlmy_rebuy_step( true, 11208, 5, 1, '', false ), 'unpaid' );
ok( 'an unpaid order with a handled line still issues no key', wpsa_dlmy_rebuy_step( true, 11208, 5, 1, '42', false ), 'done' );
ok( 'an unpaid order DLM already skipped is still respected', wpsa_dlmy_rebuy_step( false, 11208, 5, 1, '', false ), 'pass' );
ok( 'an unpaid order for another product is left to DLM', wpsa_dlmy_rebuy_step( true, 999, 5, 1, '', false ), 'pass' );

// ---- Order lines, then the same order replayed ------------------------------------
ok( 'meta key names the product', wpsa_dlmy_extension_meta_key( 11208 ), '_wpsa_license_extended_11208' );
// Each line keeps its own meta; the filter writes the licence id there after extending.
// Lines 501 and 503 are the same plan bought twice; 502 is a second plan.
$lines = array( 501 => 11208, 502 => 11413, 503 => 11208 );
$meta  = array(); // Stands in for each line's meta.
$run   = function () use ( $lines, &$meta ) {
    $out = array( 'extended' => array(), 'done' => array() );
    foreach ( $lines as $line => $pid ) {
        $key  = wpsa_dlmy_extension_meta_key( $pid );
        $step = wpsa_dlmy_rebuy_step( true, $pid, 5, 1, isset( $meta[ $line ][ $key ] ) ? $meta[ $line ][ $key ] : '', true );
        if ( 'lookup' === $step ) {
            $meta[ $line ][ $key ] = 100 + $pid;
            $out['extended'][]     = $line;
        } elseif ( 'done' === $step ) {
            $out['done'][] = $line;
        }
    }
    return $out;
};
$first  = $run();
$replay = $run();
ok( 'every line extended on the first run', $first['extended'], array( 501, 502, 503 ) );
ok( 'replay extends no line a second time', $replay['extended'], array() );
ok( 'replay still issues no new key for any line', $replay['done'], array( 501, 502, 503 ) );

// ---- Order-note label ---------------------------------------------------------
ok( 'label shows id and last four', wpsa_dlmy_license_label( 12, 'ABCD-EFGH-IJKL' ), '#12 (...IJKL)' );
ok( 'label without a readable key', wpsa_dlmy_license_label( 12, false ), '#12' );
ok( 'a short key is not revealed', wpsa_dlmy_license_label( 12, 'ABCD' ), '#12' );

// ---- What a repeat purchase does to the chosen licence -------------------------
$year_from_now = '2028-06-14 12:00:00'; // $now + 365 days (29 February 2028 falls inside).
// A licence with an expiry date: a year on top of the time left, set Active, customer told.
foreach ( array( 1, 2, 3, 4 ) as $s ) {
    $p = wpsa_dlmy_extension_plan( '2027-07-25 12:00:00', $s, $now );
    ok( "dated, status $s: extended", $p['outcome'], 'extended' );
    ok( "dated, status $s: year on top, set Active", $p['changes'], array( 'status' => 3, 'expires_at' => '2028-07-24 12:00:00' ) );
    ok( "dated, status $s: reported expiry", $p['expiry'], '2028-07-24 12:00:00' );
    ok( "dated, status $s: customer told", $p['notify'], true );
}
ok( 'dated but expired: a year from now', wpsa_dlmy_extension_plan( '2027-05-16 12:00:00', 4, $now )['changes'], array( 'status' => 3, 'expires_at' => $year_from_now ) );
foreach ( array( '', null, '0000-00-00 00:00:00' ) as $never ) {
    $tag = var_export( $never, true );
    // Never-expiring and not Active: becomes a one-year licence from now, set Active, customer told.
    foreach ( array( 1, 2, 4 ) as $s ) {
        $p = wpsa_dlmy_extension_plan( $never, $s, $now );
        ok( "never-expiring $tag, status $s: converted", $p['outcome'], 'converted' );
        ok( "never-expiring $tag, status $s: one year from now, set Active", $p['changes'], array( 'status' => 3, 'expires_at' => $year_from_now ) );
        ok( "never-expiring $tag, status $s: reported expiry", $p['expiry'], $year_from_now );
        ok( "never-expiring $tag, status $s: customer told", $p['notify'], true );
    }
    // Never-expiring and already Active: left exactly as it is, nothing written, customer not told.
    $p = wpsa_dlmy_extension_plan( $never, 3, $now );
    ok( "never-expiring $tag, Active: untouched", $p['outcome'], 'untouched' );
    ok( "never-expiring $tag, Active: no licence write", $p['changes'], array() );
    ok( "never-expiring $tag, Active: no expiry", $p['expiry'], '' );
    ok( "never-expiring $tag, Active: customer not told", $p['notify'], false );
}
// Status read straight from the database arrives as a numeric string.
ok( "never-expiring, status '3' as a string: untouched", wpsa_dlmy_extension_plan( null, '3', $now )['outcome'], 'untouched' );
ok( "never-expiring, status '4' as a string: converted", wpsa_dlmy_extension_plan( null, '4', $now )['outcome'], 'converted' );

// ---- Did the licence write land? ---------------------------------------------------
$written = array( 'status' => 3, 'expires_at' => $year_from_now );
ok( 'write landed', wpsa_dlmy_changes_landed( $written, array( 'status' => 3, 'expires_at' => $year_from_now ) ), true );
ok( 'write landed, status read back as a string', wpsa_dlmy_changes_landed( $written, array( 'status' => '3', 'expires_at' => $year_from_now ) ), true );
ok( 'expiry did not land', wpsa_dlmy_changes_landed( $written, array( 'status' => 3, 'expires_at' => null ) ), false );
ok( 'status did not land', wpsa_dlmy_changes_landed( $written, array( 'status' => 4, 'expires_at' => $year_from_now ) ), false );
ok( 'expiry missing from the read-back', wpsa_dlmy_changes_landed( $written, array( 'status' => 3 ) ), false );

// ---- Reading a stored UTC date back as a moment in time -----------------------------
date_default_timezone_set( 'America/New_York' );
ok( 'stored date is read as UTC', wpsa_dlmy_utc_timestamp( '2028-07-24 23:30:00' ), gmmktime( 23, 30, 0, 7, 24, 2028 ) );
date_default_timezone_set( $tz );
ok( 'empty date has no moment', wpsa_dlmy_utc_timestamp( '' ), null );
ok( 'zero date has no moment', wpsa_dlmy_utc_timestamp( '0000-00-00 00:00:00' ), null );
ok( 'unreadable date has no moment', wpsa_dlmy_utc_timestamp( 'not a date' ), null );

// ---- The note the customer receives ---------------------------------------------------
// Approved wording, byte for byte: British "licence" and an em dash (U+2014, UTF-8 bytes E2 80 94).
ok( 'customer note wording',
    wpsa_dlmy_customer_note( '24 July 2028' ),
    "Your existing Speed Analyzer licence has been extended to 24 July 2028. No new key was needed \xE2\x80\x94 keep using the key you already have." );
ok( 'no customer note without a date', wpsa_dlmy_customer_note( '' ), '' );

// ---- The private notes shop staff see ---------------------------------------------------
ok( 'staff note: extended', wpsa_dlmy_staff_note( 'extended', '#12', '2028-07-24 12:00:00' ),
    'Speed Analyzer: extended existing licence #12 to 2028-07-24 12:00:00 UTC and set it to Active. No new key was issued.' );
ok( 'staff note: converted', wpsa_dlmy_staff_note( 'converted', '#12', $year_from_now ),
    'Speed Analyzer: existing licence #12 had no expiry date and was not Active; it is now a one-year licence to 2028-06-14 12:00:00 UTC and set to Active. No new key was issued.' );
ok( 'staff note: untouched', wpsa_dlmy_staff_note( 'untouched', '#12', '' ),
    'Speed Analyzer: existing licence #12 never expires and is already Active, so it was left unchanged. No new key was issued.' );
ok( 'staff note: failed', wpsa_dlmy_staff_note( 'failed', '#12', '' ),
    'Speed Analyzer: could not extend existing licence #12, so a new key is being issued instead. Merge the two by hand if needed.' );

// ---- The real filter callback, without WooCommerce objects --------------------
// It must hand back exactly what it was given, never a hard true.
ok( 'filter passes true through', wpsa_dlmy_filter( true, null, null, null ), true );
ok( 'filter passes false through', wpsa_dlmy_filter( false, null, null, null ), false );
ok( 'filter passes a non-boolean through unchanged', wpsa_dlmy_filter( 0, null, null, null ), 0 );

// ---- Hooks the file registers -------------------------------------------------------
// The stand-ins keep add_action() and add_filter() calls the way WordPress keeps them
// (tests/_dlm-yearly-stubs.php). Loading the file at the top of this harness ran its real
// registration code; firing dlm_boot below runs the callback it left there.
$creation = 'dlm_woocommerce_order_licenses_creation_for_product'; // The name DLM applies (its Orders.php:167).
ok( 'on load: boot waits for dlm_boot', dlmy_hooked( 'dlm_boot' ), array( array( 'callback' => 'wpsa_dlmy_boot', 'priority' => 10, 'accepted_args' => 1 ) ) );
ok( 'on load: nothing on the creation filter before DLM boots', dlmy_hooked( $creation ), array() );
ok( 'on load: the hide-list filter is registered', dlmy_hooked( 'woocommerce_hidden_order_itemmeta' ), array( array( 'callback' => 'wpsa_dlmy_hide_extension_meta', 'priority' => 10, 'accepted_args' => 1 ) ) );
do_action( 'dlm_boot' ); // What DLM does once it has booted (its Boot.php:128).
$hooked = dlmy_hooked( $creation );
ok( "after dlm_boot: one callback on DLM's creation filter", count( $hooked ), 1 );
ok( 'after dlm_boot: the callback is the plugin filter', $hooked[0]['callback'] ?? null, 'wpsa_dlmy_filter' );
ok( 'after dlm_boot: priority 99', $hooked[0]['priority'] ?? null, 99 );
ok( 'after dlm_boot: four arguments accepted', $hooked[0]['accepted_args'] ?? null, 4 );
// DLM passes four arguments (its Orders.php:167). The callback's last three default to null,
// so a smaller count would not fail loudly: every line would fall through to a new key.
ok( "after dlm_boot: accepted arguments equal the callback's parameter count",
    $hooked[0]['accepted_args'] ?? null, ( new ReflectionFunction( 'wpsa_dlmy_filter' ) )->getNumberOfParameters() );

// ---- The line mark is hidden from the admin order screens ---------------------------------
// WooCommerce compares item meta keys against this list exactly, on the order screen and in
// the orders-list preview, and starts from its own default list.
$ours = array( '_wpsa_license_extended_11208', '_wpsa_license_extended_11413', '_wpsa_license_extended_11414' );
ok( 'hide list: the keys given are kept and one key per plan appended',
    wpsa_dlmy_hide_extension_meta( array( '_qty', 'another_plugin_key' ) ), array_merge( array( '_qty', 'another_plugin_key' ), $ours ) );
ok( 'hide list: a key already listed is not added twice',
    wpsa_dlmy_hide_extension_meta( array( '_wpsa_license_extended_11413' ) ), array( '_wpsa_license_extended_11413', '_wpsa_license_extended_11208', '_wpsa_license_extended_11414' ) );
ok( 'hide list: an empty list gets exactly the keys the plugin can write', wpsa_dlmy_hide_extension_meta( array() ), $ours );
ok( 'hide list: anything that is not a list is left alone', wpsa_dlmy_hide_extension_meta( 'not a list' ), 'not a list' );
// WooCommerce's own list (OrderItemMetaUtil::get_hidden_keys()), run through the registered filter.
$wc_hidden    = array( '_qty', '_tax_class', '_product_id', '_variation_id', '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', 'method_id', 'cost', '_reduced_stock', '_restock_refunded_items' );
$admin_hidden = apply_filters( 'woocommerce_hidden_order_itemmeta', $wc_hidden );
ok( "hide list through the filter: WooCommerce's keys kept in order, then this plugin's", $admin_hidden, array_merge( $wc_hidden, $ours ) );

// ---- The real filter callback, driven the way DLM drives it --------------------
// tests/_dlm-yearly-stubs.php stands in for WooCommerce's order, line and product
// objects, DLM's licence repository and DLM's per-order loop, and names the source
// each one models. Every run below reaches the real wpsa_dlmy_filter() through
// the filter registered above, with the same order object for every line, and each
// run loads the order afresh from the stand-in storage, as DLM does, so a second run
// is a genuine replay.
function lic( $id, $status, $expires, $order_id = 800, $user_id = 5, $product_id = 11208 ) {
    // A licence row the way the database hands it back: every column a string, or NULL.
    return array(
        'id'          => (string) $id,
        'status'      => (string) $status,
        'expires_at'  => $expires,
        'order_id'    => (string) $order_id,
        'user_id'     => (string) $user_id,
        'product_id'  => (string) $product_id,
        'created_at'  => '2026-01-01 00:00:00',
        'license_key' => 'ABCD-EFGH-IJKL',
    );
}
function shop( array $lines, array $licences, $mode = 'ok', $user_id = 5, $order_id = 900, $status = 'processing' ) {
    DLMY_Store::reset();
    DLMY_Store::add_order( $order_id, $user_id, $lines, $status );
    DLMY_Licenses::reset( $licences, $mode );
}
function dlm_run( $order_id = 900, array $start = array() ) { return dlmy_dlm_generate_order_licenses( $order_id, $start ); }
function writes() { return DLMY_Licenses::instance()->writes; }
function notes( $order_id, $customer ) {
    $texts = array();
    foreach ( isset( DLMY_Store::$notes[ $order_id ] ) ? DLMY_Store::$notes[ $order_id ] : array() as $note ) {
        if ( $customer === $note['customer'] ) {
            $texts[] = $note['text'];
        }
    }
    return $texts;
}
function line_meta( $item_id ) { return DLMY_Store::$items[ $item_id ]['meta']; } // As stored.
function marker( $item_id ) {
    $meta = line_meta( $item_id );
    $key  = wpsa_dlmy_extension_meta_key( DLMY_Store::$items[ $item_id ]['product_id'] );
    return isset( $meta[ $key ] ) ? $meta[ $key ] : null;
}
function order_marks( $order_id ) { return array_values( preg_grep( '/^_wpsa_license_extended/', array_keys( DLMY_Store::$orders[ $order_id ]['meta'] ) ) ); }
function happened_before( $first, $second ) {
    $a = array_search( $first, DLMY_Store::$events, true );
    $b = array_search( $second, DLMY_Store::$events, true );
    return is_int( $a ) && is_int( $b ) && $a < $b;
}
$far  = '2099-01-01 00:00:00';
$far1 = '2100-01-01 00:00:00'; // + 365 days: 2099 is not a leap year.
$far2 = '2101-01-01 00:00:00'; // + 365 more: nor is 2100.
$told = function ( $date ) {
    return "Your existing Speed Analyzer licence has been extended to $date. No new key was needed \xE2\x80\x94 keep using the key you already have.";
};
$mk = wpsa_dlmy_extension_meta_key( 11208 );

// A key with an end date: a year on top, set Active, customer told after the write.
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
$r = dlm_run();
ok( 'extended: DLM issues no key', $r['answers'], array( 501 => false ) );
ok( 'extended: nothing minted', $r['minted'], array() );
ok( 'extended: one licence write', count( writes() ), 1 );
ok( 'extended: a year on top, set Active', writes()[0], array( 7, array( 'status' => 3, 'expires_at' => $far1 ) ) );
ok( 'extended: customer told the new date', notes( 900, 1 ), array( $told( '1 January 2100' ) ) );
ok( 'extended: staff told', notes( 900, 0 ), array( wpsa_dlmy_staff_note( 'extended', '#7 (...IJKL)', $far1 ) ) );
ok( 'extended: customer told only after the write', happened_before( 'licence write', 'customer note' ), true );
// The mark goes through storage the way WooCommerce writes item meta, so it is stored as a string.
ok( 'extended: the line records the licence', marker( 501 ), '7' );
ok( 'extended: nothing recorded on the order itself', order_marks( 900 ), array() );
ok( 'extended: the admin order screens hide the mark', in_array( array_keys( line_meta( 501 ) )[0] ?? '', $admin_hidden, true ), true );

// The same order replayed: DLM loads it afresh and offers the line again.
$r = dlm_run();
ok( 'replay: DLM still issues no key', $r['answers'], array( 501 => false ) );
ok( 'replay: nothing minted', $r['minted'], array() );
ok( 'replay: no second write', count( writes() ), 1 );
ok( 'replay: no new notes', count( DLMY_Store::$notes[900] ?? array() ), 2 );
ok( 'replay: the mark reads back from storage as a string', ( new WC_Order_Item_Product( 501 ) )->get_meta( $mk, true ), '7' );
$r = dlm_run( 900, array( 501 => false ) );
ok( 'replay after an earlier skip: false passes through', $r['answers'], array( 501 => false ) );

// The date in the customer note follows the shop's date format.
$GLOBALS['dlmy_options']['date_format'] = 'Y-m-d';
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 3, $far ) ) );
dlm_run();
ok( "customer note uses the shop's date format", notes( 900, 1 ), array( $told( '2100-01-01' ) ) );
$GLOBALS['dlmy_options']['date_format'] = 'j F Y';

// Never-expiring keys (NULL and the MySQL zero date).
foreach ( array( 'NULL' => null, 'zero date' => '0000-00-00 00:00:00' ) as $tag => $never ) {
    // Not Active: becomes a one-year key from now, set Active, customer told.
    foreach ( array( 1, 2, 4 ) as $s ) {
        shop( array( 501 => array( 11208, 1 ) ), array( lic( 8, $s, $never ) ) );
        $t0   = time();
        $r    = dlm_run();
        $t1   = time();
        $w    = writes();
        $year = array( gmdate( 'Y-m-d H:i:s', $t0 + 365 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $t1 + 365 * DAY_IN_SECONDS ) );
        ok( "converted ($tag, status $s): DLM issues no key", $r['answers'], array( 501 => false ) );
        ok( "converted ($tag, status $s): one write, set Active", array( count( $w ), $w[0][1]['status'] ?? null ), array( 1, 3 ) );
        ok( "converted ($tag, status $s): a year from now", in_array( $w[0][1]['expires_at'] ?? '', $year, true ), true );
        ok( "converted ($tag, status $s): customer told", count( notes( 900, 1 ) ), 1 );
        ok( "converted ($tag, status $s): staff told", count( notes( 900, 0 ) ), 1 );
        ok( "converted ($tag, status $s): the line records the licence", marker( 501 ), '8' );
    }
    // Already Active: left as it is, nothing written, staff note only.
    shop( array( 501 => array( 11208, 1 ) ), array( lic( 10, 3, $never ) ) );
    $r = dlm_run();
    ok( "untouched ($tag): DLM issues no key", $r['answers'], array( 501 => false ) );
    ok( "untouched ($tag): no licence write", count( writes() ), 0 );
    ok( "untouched ($tag): customer not told", notes( 900, 1 ), array() );
    ok( "untouched ($tag): staff told", notes( 900, 0 ), array( wpsa_dlmy_staff_note( 'untouched', '#10 (...IJKL)', '' ) ) );
    ok( "untouched ($tag): the line records the licence", marker( 501 ), '10' );
}

// Lines the plugin leaves to DLM: DLM's own value comes back unchanged, and nothing else happens.
// A guest's licence (user 0) and the customer's own licence both exist, so a matching bug would show.
foreach ( array(
    'guest order'   => array( array( 501 => array( 11208, 1 ) ), 0 ),
    'two units'     => array( array( 501 => array( 11208, 2 ) ), 5 ),
    'other product' => array( array( 501 => array( 999, 1 ) ), 5 ),
) as $name => $c ) {
    foreach ( array( true, 1 ) as $start ) {
        $tag = var_export( $start, true );
        shop( $c[0], array( lic( 6, 2, $far, 800, 0 ), lic( 11, 2, $far, 800, 5 ), lic( 12, 2, $far, 800, 5, 999 ) ), 'ok', $c[1] );
        $r = dlm_run( 900, array( 501 => $start ) );
        ok( "$name: $tag passes through unchanged", $r['answers'], array( 501 => $start ) );
        ok( "$name ($tag): no licence write", count( writes() ), 0 );
        ok( "$name ($tag): no notes", DLMY_Store::$notes, array() );
        ok( "$name ($tag): nothing recorded on the line", line_meta( 501 ), array() );
    }
}
// Other code already asked DLM not to issue a key: that answer stands.
foreach ( array( false, 0 ) as $start ) {
    $tag = var_export( $start, true );
    shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
    $r = dlm_run( 900, array( 501 => $start ) );
    ok( "earlier skip: $tag passes through unchanged", $r['answers'], array( 501 => $start ) );
    ok( "earlier skip ($tag): no licence write", count( writes() ), 0 );
    ok( "earlier skip ($tag): no notes", DLMY_Store::$notes, array() );
    ok( "earlier skip ($tag): nothing recorded on the line", line_meta( 501 ), array() );
}

// Nothing to extend: DLM issues a key as usual.
foreach ( array(
    'no earlier licence'              => array(),
    'only a key from this same order' => array( lic( 12, 2, $far, 900 ) ),
    'only a disabled key'             => array( lic( 13, 5, $far ) ),
) as $name => $rows ) {
    shop( array( 501 => array( 11208, 1 ) ), $rows );
    $r = dlm_run();
    ok( "$name: DLM issues a key", $r['answers'], array( 501 => true ) );
    ok( "$name: minted", $r['minted'], array( 501 ) );
    ok( "$name: no licence write", count( writes() ), 0 );
    ok( "$name: no notes", DLMY_Store::$notes, array() );
    ok( "$name: nothing recorded on the line", line_meta( 501 ), array() );
}

// The write is not confirmed: DLM issues a key, the customer is told nothing, nothing is recorded.
foreach ( array(
    'write-fails'      => 'the write fails',
    'listener-throws'  => 'a listener throws after the write',
    'reads-back-other' => 'the row reads back differently',
) as $mode => $what ) {
    foreach ( array( 'dated' => $far, 'never-expiring' => null ) as $kind => $exp ) {
        shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 4, $exp ) ), $mode );
        $r = dlm_run();
        ok( "$what ($kind): DLM issues a key instead", $r['answers'], array( 501 => true ) );
        ok( "$what ($kind): one write was tried", count( writes() ), 1 );
        ok( "$what ($kind): customer not told", notes( 900, 1 ), array() );
        ok( "$what ($kind): staff told", notes( 900, 0 ), array( wpsa_dlmy_staff_note( 'failed', '#7 (...IJKL)', '' ) ) );
        ok( "$what ($kind): nothing recorded on the line", line_meta( 501 ), array() );
    }
}
// The licence lookup fails: DLM issues a key, nothing else happens.
foreach ( array( 'get-exception' => 'a failed licence query', 'get-error' => 'an error escaping the licence query' ) as $mode => $what ) {
    shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), $mode );
    $r = dlm_run();
    ok( "$what: DLM issues a key", $r['answers'], array( 501 => true ) );
    ok( "$what: no licence write", count( writes() ), 0 );
    ok( "$what: no notes", DLMY_Store::$notes, array() );
    ok( "$what: nothing recorded on the line", line_meta( 501 ), array() );
}

// Two lines of the same plan on one order are two purchases: a year for each line,
// the second on top of the first, and the customer is told each new date.
shop( array( 501 => array( 11208, 1 ), 502 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'ok', 5, 950 );
$r = dlm_run( 950 );
ok( 'same plan twice: DLM issues no key for either line', $r['answers'], array( 501 => false, 502 => false ) );
ok( 'same plan twice: nothing minted', $r['minted'], array() );
ok( 'same plan twice: a year for each line, the second on top of the first',
    array_map( function ( $w ) { return $w[1]['expires_at']; }, writes() ), array( $far1, $far2 ) );
ok( 'same plan twice: customer told each new date', notes( 950, 1 ), array( $told( '1 January 2100' ), $told( '1 January 2101' ) ) );
ok( 'same plan twice: staff told for each line', count( notes( 950, 0 ) ), 2 );
ok( 'same plan twice: each line records the licence', array( marker( 501 ), marker( 502 ) ), array( '7', '7' ) );
$r = dlm_run( 950 );
ok( 'same plan twice, replayed: still no key for either line', $r['answers'], array( 501 => false, 502 => false ) );
ok( 'same plan twice, replayed: no further write', count( writes() ), 2 );
ok( 'same plan twice, replayed: no new notes', count( DLMY_Store::$notes[950] ?? array() ), 4 );
// The same with a never-expiring key: the first line converts it, the second adds a year on top.
shop( array( 501 => array( 11208, 1 ), 502 => array( 11208, 1 ) ), array( lic( 8, 4, null ) ), 'ok', 5, 951 );
dlm_run( 951 );
$w = writes();
ok( 'same plan twice, never-expiring key: converted, then a year on top',
    2 === count( $w ) && gmdate( 'Y-m-d H:i:s', strtotime( $w[0][1]['expires_at'] . ' UTC' ) + 365 * DAY_IN_SECONDS ) === $w[1][1]['expires_at'], true );
ok( 'same plan twice, never-expiring key: customer told twice', count( notes( 951, 1 ) ), 2 );

// Two different plans on one order: each licence extended exactly once, also on a replay.
shop( array( 601 => array( 11208, 1 ), 602 => array( 11413, 1 ) ), array( lic( 7, 2, $far ), lic( 8, 3, $far, 800, 5, 11413 ) ), 'ok', 5, 960 );
$r = dlm_run( 960 );
ok( 'two plans: DLM issues no key for either line', $r['answers'], array( 601 => false, 602 => false ) );
ok( 'two plans: each licence extended once', array_map( function ( $w ) { return $w[0]; }, writes() ), array( 7, 8 ) );
ok( 'two plans: customer told twice', count( notes( 960, 1 ) ), 2 );
ok( 'two plans: each line records its own licence', array( marker( 601 ), marker( 602 ) ), array( '7', '8' ) );
ok( 'two plans: the admin order screens hide both marks',
    array_map( function ( $k ) use ( $admin_hidden ) { return in_array( $k, $admin_hidden, true ); }, array_merge( array_keys( line_meta( 601 ) ), array_keys( line_meta( 602 ) ) ) ), array( true, true ) );
$r = dlm_run( 960 );
ok( 'two plans, replayed: still no key for either line', $r['answers'], array( 601 => false, 602 => false ) );
ok( 'two plans, replayed: no further write', count( writes() ), 2 );
ok( 'two plans, replayed: no new notes', count( DLMY_Store::$notes[960] ?? array() ), 4 );

// Saving the mark fails after the licence was extended: the purchase still counts as fulfilled.
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
DLMY_Store::$line_save_throws = true;
$r = dlm_run();
DLMY_Store::$line_save_throws = false;
ok( 'mark not saved: DLM issues no key', $r['answers'], array( 501 => false ) );
ok( 'mark not saved: the licence was extended once', count( writes() ), 1 );
ok( 'mark not saved: customer still told', count( notes( 900, 1 ) ), 1 );

// ---- Loading the file a second time must not be fatal --------------------------
// DLM has booted by now (dlm_boot fired above), so this load takes the "loaded after DLM"
// branch: the creation filter is registered at once, and nothing waits on dlm_boot.
dlmy_forget_hooks();
require __DIR__ . '/../cloudflare/mu-plugins/wpsa-dlm-yearly.php';
ok( 'loaded after DLM booted: the creation filter is registered at once', dlmy_hooked( $creation ),
    array( array( 'callback' => 'wpsa_dlmy_filter', 'priority' => 99, 'accepted_args' => 4 ) ) );
ok( 'loaded after DLM booted: nothing waits on dlm_boot', dlmy_hooked( 'dlm_boot' ), array() );

// ---- An order that is not paid -----------------------------------------------------
// DLM acts only on the statuses ticked in its settings. If an unpaid one is ever ticked, the
// callback steps aside and DLM does exactly what it would do without this plugin.
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'ok', 5, 900, 'on-hold' );
$r = dlm_run();
ok( 'unpaid: DLM decides as usual', $r['answers'], array( 501 => true ) );
ok( 'unpaid: DLM issues its own key', $r['minted'], array( 501 ) );
ok( 'unpaid: no licence write', writes(), array() );
ok( 'unpaid: no order note', array( notes( 900, 0 ), notes( 900, 1 ) ), array( array(), array() ) );
ok( 'unpaid: the line is not marked', marker( 501 ), null );

// "completed" counts as paid too.
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'ok', 5, 900, 'completed' );
$r = dlm_run();
ok( 'completed: extended, no key issued', array( $r['answers'], count( writes() ) ), array( array( 501 => false ), 1 ) );

// A line extended while the order was paid stays handled after a refund.
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
dlm_run();
DLMY_Store::$orders[900]['status'] = 'refunded';
$r = dlm_run();
ok( 'refunded after extension: still no key issued', $r['answers'], array( 501 => false ) );
ok( 'refunded after extension: the licence is not written again', count( writes() ), 1 );

// WooCommerce's own paid-status list decides, not a list kept by this plugin.
add_filter( 'woocommerce_order_is_paid_statuses', function ( $statuses ) {
    $statuses[] = 'on-hold';
    return $statuses;
} );
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'ok', 5, 900, 'on-hold' );
$r = dlm_run();
ok( 'a status the shop counts as paid extends', $r['answers'], array( 501 => false ) );
unset( $GLOBALS['dlmy_hooks']['woocommerce_order_is_paid_statuses'] );

// DLM's own value comes back from an unpaid order exactly as DLM sent it.
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'ok', 5, 900, 'on-hold' );
$r = dlm_run( 900, array( 501 => 1 ) );
ok( "unpaid: DLM's own value comes back unchanged", $r['answers'], array( 501 => 1 ) );

// A paid check that throws (a third-party filter on it) never turns a line an earlier run
// handled into a second key. A line not yet handled is left to DLM, which issues its key.
$throwing_paid = function () { throw new RuntimeException( 'paid check failed' ); };
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
dlm_run();
add_filter( 'woocommerce_order_is_paid', $throwing_paid );
$r = dlm_run();
ok( 'a failing paid check: a handled line still issues no key and is not written again',
    array( $r['answers'], $r['minted'], count( writes() ) ), array( array( 501 => false ), array(), 1 ) );
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
$r = dlm_run();
ok( 'a failing paid check: a line not yet handled is left to DLM',
    array( $r['answers'], $r['minted'], writes(), marker( 501 ) ), array( array( 501 => true ), array( 501 ), array(), null ) );
unset( $GLOBALS['dlmy_hooks']['woocommerce_order_is_paid'] );

// ---- The debug log names the exception class, never its message --------------------
// A message carries whatever the failing code put in it, and the log is meant to hold ids
// only. With WP_DEBUG on and PHP's error log pointed at a temporary file, the two failure
// paths that catch an exception from DLM log it; each line must name the class and must not
// repeat the message. WP_DEBUG is defined here, last, so no earlier run writes to the log.
define( 'WP_DEBUG', true );
$log_file = tempnam( sys_get_temp_dir(), 'dlmy' );
ini_set( 'error_log', $log_file );
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'get-error' );       // the lookup throws an Error
dlm_run();
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 4, $far ) ), 'listener-throws' ); // the update throws
dlm_run();
$log = (string) file_get_contents( $log_file );
unlink( $log_file );
ok( 'debug log: a failed lookup logs the class, not the message',
    array( false !== strpos( $log, 'order 900: lookup failed (Error)' ), false !== strpos( $log, 'an error the query does not catch' ) ),
    array( true, false ) );
ok( 'debug log: a failed licence update logs the class, not the message',
    array( false !== strpos( $log, 'order 900: update of licence 7 failed (RuntimeException)' ), false !== strpos( $log, 'listener failed after the write' ) ),
    array( true, false ) );
ok( 'debug log: no log line in the plugin can carry an exception message',
    substr_count( (string) file_get_contents( __DIR__ . '/../cloudflare/mu-plugins/wpsa-dlm-yearly.php' ), 'getMessage' ), 0 );
// An unpaid run, then a paid run, in one capture window. Every other log call in the plugin
// sits in a catch, so a paid run that succeeds logs nothing, and the whole window must be one
// line: PHP's own [timestamp] prefix, then the order id and its status and nothing else.
// Lines are split on any line break: error_log() ends them with CRLF on Windows.
$log_file = tempnam( sys_get_temp_dir(), 'dlmy' );
ini_set( 'error_log', $log_file );
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ), 'ok', 5, 900, 'on-hold' );
dlm_run();
shop( array( 501 => array( 11208, 1 ) ), array( lic( 7, 2, $far ) ) );
dlm_run();
$log = (string) file_get_contents( $log_file );
unlink( $log_file );
ok( 'debug log: an unpaid run and a paid run leave exactly one line, the order id and its status and nothing else',
    preg_replace( '/^\[[^\]]+\] /', '', preg_split( '/\R/', trim( $log ) ) ),
    array( 'wpsa-dlm-yearly: order 900: not paid (status on-hold), left to DLM' ) );

// Check-count gate. Not routed through ok(), which would change the number it checks.
if ( DLMY_EXPECTED_CHECKS !== $checks ) {
    $fails++;
    printf( "FAIL check count drifted from DLMY_EXPECTED_CHECKS\n  expected %d\n  actual   %d\n", DLMY_EXPECTED_CHECKS, $checks );
}
$GLOBALS['dlmy_reached_end'] = true;

if ( $fails ) { fwrite( STDERR, "$fails of $checks check(s) failed\n" ); exit( 1 ); }
echo "dlm yearly extend harness passed ($checks checks)\n";
