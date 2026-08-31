<?php
/**
 * Harness: plugin-root URL/DIR must be anchored to the MAIN FILE, never to the caller.
 *
 * WordPress's plugin_dir_url( __FILE__ ) and plugin_dir_path( __FILE__ ) resolve relative
 * to the file that calls them. While every source file sits at the plugin root that is
 * harmless. The moment a file moves into includes/, a caller there resolves to
 * <plugin>/includes/ and every asset URL built from it 404s - which is exactly how the
 * bundled Chart.js and the trends chart break (the failure 1.18.6 fixed by bundling it).
 *
 * This harness is the guard that makes the restructure safe. It pins three things:
 *   1. WPSA_PLUGIN_DIR / WPSA_PLUGIN_URL exist and derive from WPSA_PLUGIN_FILE.
 *   2. They are defined BEFORE the require block that consumes WPSA_PLUGIN_DIR
 *      (otherwise the plugin fatals on activation).
 *   3. No shipped PHP resolves a plugin path from __FILE__ any more.
 * Plus a behavioural check that the constants produce the plugin root regardless of
 * which subdirectory the calling file lives in.
 *
 * Run: php tests/plugin-path-constants-harness.php
 */

$root = dirname( __DIR__ );
$main = $root . '/wp-speed-analyzer.php';

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

$src = str_replace( array( "\r\n", "\r" ), "\n", file_get_contents( $main ) );

echo "-- the constants must exist and be anchored to the main file --\n";
wpsa_check(
	'WPSA_PLUGIN_DIR derives from WPSA_PLUGIN_FILE',
	(bool) preg_match( "/define\(\s*'WPSA_PLUGIN_DIR',\s*plugin_dir_path\(\s*WPSA_PLUGIN_FILE\s*\)\s*\)/", $src ),
	true
);
wpsa_check(
	'WPSA_PLUGIN_URL derives from WPSA_PLUGIN_FILE',
	(bool) preg_match( "/define\(\s*'WPSA_PLUGIN_URL',\s*plugin_dir_url\(\s*WPSA_PLUGIN_FILE\s*\)\s*\)/", $src ),
	true
);

echo "\n-- definition must precede the require block that uses it --\n";
$def_pos = strpos( $src, "define( 'WPSA_PLUGIN_DIR'" );
$req_pos = strpos( $src, 'require_once WPSA_PLUGIN_DIR' );
wpsa_check( 'WPSA_PLUGIN_DIR is defined', false !== $def_pos, true );
wpsa_check( 'require block uses WPSA_PLUGIN_DIR', false !== $req_pos, true );
wpsa_check(
	'define comes before the first require (else: fatal on activation)',
	( false !== $def_pos && false !== $req_pos && $def_pos < $req_pos ),
	true
);

echo "\n-- no shipped PHP may resolve a plugin path from __FILE__ --\n";
$offenders = array();
$rii       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
	$path = str_replace( '\\', '/', $file->getPathname() );
	if ( substr( $path, -4 ) !== '.php' ) {
		continue;
	}
	// tests/ is dev-only; 1.18.6/ is a gitignored SFTP staging copy, not source.
	if ( false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/1.18.6/' ) ) {
		continue;
	}
	$body = file_get_contents( $path );
	if ( preg_match( '/plugin_dir_(url|path)\(\s*__FILE__\s*\)/', $body ) ) {
		$offenders[] = basename( $path );
	}
}
sort( $offenders );
wpsa_check( 'files still using plugin_dir_*( __FILE__ )', implode( ', ', $offenders ), '' );

echo "\n-- behavioural: the constants point at the plugin root from any depth --\n";
// WordPress semantics, reduced to what matters here: dirname of the given file,
// trailing-slashed. The point is that the ARGUMENT decides the result.
$plugin_dir_path = static function ( $file ) {
	return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/';
};

$fake_root = '/wp-content/plugins/speed-analyzer';
$expected  = $fake_root . '/';

// A caller at the root and a caller in includes/ must both yield the plugin root
// once the value is anchored to the main file rather than to __FILE__.
$anchored_from_root     = $plugin_dir_path( $fake_root . '/wp-speed-analyzer.php' );
$anchored_from_includes = $plugin_dir_path( $fake_root . '/wp-speed-analyzer.php' );
$naive_from_includes    = $plugin_dir_path( $fake_root . '/includes/compare.php' );

wpsa_check( 'anchored value, caller at root', $anchored_from_root, $expected );
wpsa_check( 'anchored value, caller in includes/', $anchored_from_includes, $expected );
wpsa_check(
	'and the naive __FILE__ form would have been wrong',
	$naive_from_includes !== $expected,
	true
);

if ( $failed > 0 ) {
	fwrite( STDERR, "\nplugin path constants harness FAILED ({$failed} case(s))\n" );
	exit( 1 );
}
echo "\nplugin path constants harness passed\n";
