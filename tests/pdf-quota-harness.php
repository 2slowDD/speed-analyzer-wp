<?php

/**
 * Focused regression harness for wpsa_ajax_pdf_report quota timing.
 *
 * Runs outside WordPress by extracting the production function body and
 * stubbing only the WordPress/plugin functions that endpoint calls.
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Wpsa_Test_Json_Response extends RuntimeException {
    public bool $success;
    /** @var mixed */
    public $data;

    /** @param mixed $data */
    public function __construct( bool $success, $data ) {
        parent::__construct( $success ? 'success' : 'error' );
        $this->success = $success;
        $this->data    = $data;
    }
}

$GLOBALS['wpsa_test_pdf_usage']          = 0;
$GLOBALS['wpsa_test_render_should_fail'] = false;

function check_ajax_referer( $action, $query_arg = false ) {}
function current_user_can( $capability ) { return true; }
function wpsa_check_quota( $operation ) { return array( 'allowed' => true, 'remaining' => 7, 'limit' => 10 ); }
function is_wp_error( $thing ) { return false; }
function wpsa_increment_pdf_usage() { $GLOBALS['wpsa_test_pdf_usage']++; }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return max( 0, (int) $value ); }
function esc_url_raw( $value ) { return $value; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wpsa_get_pdf_remaining() { return 7; }
function wpsa_get_pdf_limit() { return 10; }

function wpsa_pdf_report_content( $tested_url, $debug_log, $results_log_pdf, $test_no = 0 ) {
    if ( $GLOBALS['wpsa_test_render_should_fail'] ) {
        throw new RuntimeException( 'simulated render failure' );
    }

    echo '<div id="pdf-report">ok</div>';
}

function wp_send_json_error( $data = null ) {
    throw new Wpsa_Test_Json_Response( false, $data );
}

function wp_send_json_success( $data = null ) {
    throw new Wpsa_Test_Json_Response( true, $data );
}

function load_pdf_endpoint(): void {
    $source = file_get_contents( dirname( __DIR__ ) . '/wp-speed-analyzer.php' );
    if ( false === $source ) {
        throw new RuntimeException( 'Could not read plugin bootstrap.' );
    }

    if ( ! preg_match( '/function\s+wpsa_ajax_pdf_report\s*\(\)\s*\{(?P<body>.*?)\n\}/s', $source, $matches ) ) {
        throw new RuntimeException( 'Could not locate wpsa_ajax_pdf_report().' );
    }

    eval( 'function wpsa_ajax_pdf_report() {' . $matches['body'] . "\n}" );
}

function assert_same( $expected, $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
        );
    }
}

function reset_harness_state( bool $render_should_fail ): void {
    $GLOBALS['wpsa_test_pdf_usage']          = 0;
    $GLOBALS['wpsa_test_render_should_fail'] = $render_should_fail;
    $_POST                                  = array( 'test_no' => 123 );
}

load_pdf_endpoint();

reset_harness_state( true );
try {
    wpsa_ajax_pdf_report();
    throw new RuntimeException( 'Expected simulated render failure.' );
} catch ( RuntimeException $e ) {
    if ( $e instanceof Wpsa_Test_Json_Response ) {
        throw $e;
    }
    assert_same( 'simulated render failure', $e->getMessage(), 'Unexpected render failure message.' );
    assert_same( 0, $GLOBALS['wpsa_test_pdf_usage'], 'Render failures must not consume PDF quota.' );
}

reset_harness_state( false );
try {
    wpsa_ajax_pdf_report();
    throw new RuntimeException( 'Expected JSON success response.' );
} catch ( Wpsa_Test_Json_Response $response ) {
    assert_same( true, $response->success, 'Successful render should return JSON success.' );
    assert_same( 1, $GLOBALS['wpsa_test_pdf_usage'], 'Successful render should consume one PDF quota.' );
    assert_same( '<div id="pdf-report">ok</div>', $response->data['html'], 'Successful response should include rendered PDF HTML.' );
}

echo "pdf quota harness passed\n";
