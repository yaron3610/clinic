<?php
/**
 * Router for PHP's built-in web server (local preview only).
 *
 * Emulates the parts of the live server's .htaccess that matter here:
 * pretty permalinks go to index.php, and private / sensitive files are never served.
 *
 * Windows resolves "//", "/./", "/x/../", "\", trailing dots and "::$DATA" to the
 * same file, so a prefix check alone is not enough: odd paths are rejected outright,
 * and every existing file is checked again by its real path.
 */

$root = realpath( $_SERVER['DOCUMENT_ROOT'] );
$uri  = (string) strtok( $_SERVER['REQUEST_URI'], '?' );
$path = rawurldecode( $uri );

function shoval_local_deny( $code = 403 ) {
	http_response_code( $code );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo $code === 400 ? 'Bad Request' : 'Forbidden';
	return true;
}

/** True if a path (relative to the site root, "/" separators) must never be served. */
function shoval_local_is_private( $relative ) {
	$relative = strtolower( $relative );
	return strpos( $relative, '/wp-content/uploads/shoval-intake-private' ) === 0 // patient files
		|| strpos( $relative, '/wp-content/plugins/shoval-intake/assets/letter' ) === 0 // letterhead and stamp images
		|| strpos( $relative, '/wp-content/duplicator-backups' ) === 0           // site backups
		|| strpos( $relative, '/wp-content/ai1wm-backups' ) === 0
		|| strpos( $relative, '/.' ) !== false                                   // .htaccess, .user.ini, .git ...
		|| preg_match( '/\.(log|sql|gz|wpress|zip|daf|bak|ini|env|phar)$/', $relative ); // debug.log, dumps, archives
}

// Only plain, canonical paths: no "\", ":", control characters, "//", ".", "..",
// or segments ending in a dot or space (Windows strips those).
if ( $path === '' || $path[0] !== '/' || preg_match( '/[\\\\:\x00-\x1F\x7F]/', $path ) ) {
	return shoval_local_deny( 400 );
}
$segments = explode( '/', substr( $path, 1 ) );
foreach ( $segments as $i => $segment ) {
	$last = $i === count( $segments ) - 1;
	if ( ( $segment === '' && ! $last ) || $segment === '.' || $segment === '..' || preg_match( '/[. ]$/', $segment ) ) {
		return shoval_local_deny( 400 );
	}
}

if ( shoval_local_is_private( $path ) ) {
	return shoval_local_deny();
}

$file = $root . str_replace( '/', DIRECTORY_SEPARATOR, $path );
$real = file_exists( $file ) ? realpath( $file ) : false;

if ( $real !== false ) {
	// Must stay inside the site folder (the plugin is a junction, so its real folder is allowed too).
	$plugin = realpath( $root . '/wp-content/plugins/shoval-intake' );
	$inside = function ( $base ) use ( $real ) {
		return $base && ( strcasecmp( $real, $base ) === 0 || stripos( $real, $base . DIRECTORY_SEPARATOR ) === 0 );
	};
	if ( ! $inside( $root ) && ! $inside( $plugin ) ) {
		return shoval_local_deny();
	}
	if ( $inside( $root ) && shoval_local_is_private( str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) ) ) ) ) {
		return shoval_local_deny();
	}

	// Existing files (images, CSS, JS, PHP entry points) are served as-is.
	if ( $path !== '/' && is_file( $real ) ) {
		return false;
	}

	// Directories with an index.php (e.g. /wp-admin/).
	if ( $path !== '/' && is_dir( $real ) && is_file( $real . DIRECTORY_SEPARATOR . 'index.php' ) ) {
		if ( substr( $path, -1 ) !== '/' ) {
			header( 'Location: ' . $uri . '/', true, 301 );
			return true;
		}
		return false;
	}
}

// Everything else is a WordPress permalink.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $root );
require $root . DIRECTORY_SEPARATOR . 'index.php';
return true;
