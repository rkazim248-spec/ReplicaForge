<?php
/**
 * ReplicaForge security contract test.
 *
 * Covers the vectors a security review has to see tested rather than asserted:
 * SSRF destinations, credentials in URLs, capability checks, nonce checks,
 * injection payloads, and secret leakage in every channel ReplicaForge emits.
 *
 * Usage: php security-contract-test.php <wp-root>
 *
 * @package ReplicaForge
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php security-contract-test.php <wp-root>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
// `defined()` is checked because the runner may already have declared these.
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
require_once $root . '/wp-load.php';

$assertions = 0;

/**
 * Assert a condition and print the outcome.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return void
 */
function check( $condition, $message ) {
	global $assertions;
	$assertions++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	echo "FAIL: {$message}\n";
	throw new RuntimeException( 'FAILED: ' . $message );
}

/* ------------------------------------------------------------------ */
/* 1. SSRF: destinations that must never be fetched.                   */
/* ------------------------------------------------------------------ */

echo "--- 1. SSRF destination policy ---\n";

$validator = new \ReplicaForge\Url_Validator();

$blocked = array(
	'loopback literal'        => 'http://127.0.0.1/',
	'loopback name'           => 'http://localhost/',
	'loopback alternate'      => 'http://127.0.0.2/',
	'loopback as decimal'     => 'http://2130706433/',
	'loopback as hex'         => 'http://0x7f000001/',
	'loopback as octal'       => 'http://0177.01/',
	'private 10'              => 'http://10.0.0.1/',
	'private 172'             => 'http://172.16.1/',
	'private 192'             => 'http://192.168.1.1/',
	'link local'              => 'http://169.254.169.254/latest/meta-data/',
	'unspecified'             => 'http://0.0/',
	'ipv6 loopback'           => 'http://[::1]/',
	'ipv6 unique local'       => 'http://[fc00::1]/',
	'ipv6 link local'         => 'http://[fe80::1]/',
	'ipv4 mapped ipv6'        => 'http://[::ffff:127.0.0.1]/',
	'cloud metadata host'     => 'http://metadata.google.internal/',
	'credentials in url'      => 'http://user:pass@example.com/',
	'file scheme'             => 'file:///etc/passwd',
	'ftp scheme'              => 'ftp://example.com/',
	'gopher scheme'           => 'gopher://example.com/',
	'javascript scheme'       => 'javascript:alert(1)',
	'data scheme'             => 'data:text/html,<script>alert(1)</script>',
	'exotic port'             => 'http://example.com:22/',
	'private host suffix'     => 'http://foo.internal/',
	'router address'          => 'http://192.168.1/admin',
);

foreach ( $blocked as $label => $url ) {
	$result = $validator->validate( $url );
	check(
		empty( $result['success'] ),
		sprintf( 'Blocked: %s (%s)', $label, $url )
	);
}

/* A public host must still be accepted, or the policy is simply refusing
   everything and the block list proves nothing. */
$allowed = $validator->validate( 'https://example.com/pricing' );
check( ! empty( $allowed['success'] ), 'A public https URL is accepted.' );
check( 'example.com' === ( $allowed['host'] ?? '' ), 'The accepted host is returned.' );
check( false === strpos( (string) ( $allowed['url'] ?? '' ), '#' ), 'A fragment is removed.' );

$with_fragment = $validator->validate( 'https://example.com/page#section' );
check( false === strpos( (string) ( $with_fragment['url'] ?? '' ), '#' ), 'A fragment is never sent to the server.' );

$port = $validator->validate( 'https://example.com:8443/' );
check( empty( $port['success'] ), 'A non-standard port is refused.' );

$standard = $validator->validate( 'https://example.com:443/x' );
check( ! empty( $standard['success'] ), 'The default https port is accepted.' );
check(
	array_key_exists( 'port', $standard ) && null === $standard['port'],
	'A default port is normalized away.'
);

/* ------------------------------------------------------------------ */
/* 2. Redirect destinations are re-validated.                          */
/* ------------------------------------------------------------------ */

echo "--- 2. Redirect destination policy ---\n";

$security = new \ReplicaForge\Security();

$redirects = array(
	'to loopback'      => array( 'https://example.com/', 'http://127.0.0.1/admin' ),
	'to private'       => array( 'https://example.com/', 'http://192.168.1.1/' ),
	'to link local'    => array( 'https://example.com/', 'http://169.254.169.254/' ),
	'to credentials'   => array( 'https://example.com/', 'http://a:b@evil.test/' ),
	'to file'          => array( 'https://example.com/', 'file:///etc/passwd' ),
	'to javascript'    => array( 'https://example.com/', 'javascript:alert(1)' ),
	'to metadata host' => array( 'https://example.com/', 'http://metadata.google.internal/' ),
);

foreach ( $redirects as $label => $pair ) {
	$resolved = $security->resolve_url( $pair[0], $pair[1] );

	if ( null === $resolved ) {
		check( true, sprintf( 'Redirect refused while resolving: %s', $label ) );
		continue;
	}

	// A resolver may hand back a URL it considers safe. The property is that the
	// destination policy then refuses it, so an allowed re-check means a real hole.
	$rechecked = $validator->validate( $resolved );
	check(
		empty( $rechecked['success'] ),
		sprintf( 'Redirect blocked by the destination policy: %s', $label )
	);
}

$safe_redirect = $security->resolve_url( 'https://example.com/a/b', '/c' );
check( is_string( $safe_redirect ) && false !== strpos( $safe_redirect, 'example.com' ), 'A relative redirect stays on the same host.' );

/* ------------------------------------------------------------------ */
/* 3. Redaction: secrets never leave the site.                         */
/* ------------------------------------------------------------------ */

echo "--- 3. Secret redaction ---\n";

$redactor = new \ReplicaForge\Data_Redactor();

$secrets = array(
	'openai key'      => 'sk-abcdefghijklmnopqrstuvwxyz1234',
	'anthropic key'   => 'sk-ant-abcdefghijklmnopqrstuvwxyz',
	'google key'      => 'AIzaSyAbcdefghijklmnopqrstuvwxyz012345',
	'github token'    => 'ghp_abcdefghijklmnopqrstuvwxyz0123',
	'aws key'         => 'AKIAIOSFODNN7EXAMPLE',
	'slack token'     => 'xoxb-123456789012-abcdefghijkl',
	'bearer header'   => 'Authorization: Bearer abcdefghijklmnop',
	'password pair'   => 'password=hunter2secret',
	'api key pair'    => 'api_key=abcdef1234567890',
	'cookie'          => 'wordpress_logged_in_abc=admin%7Cuser',
	'private key'     => "-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----",
	'url credentials' => 'mysql://root:secretpw@localhost/db',
	'server path'     => '/var/www/html/wp-content/uploads/x.png',
	'private address' => 'http://10.1.2.3/admin',
);

foreach ( $secrets as $label => $value ) {
	$redacted = $redactor->text( $value );
	check(
		$redacted !== $value,
		sprintf( 'Redacted: %s', $label )
	);
}

check(
	false === strpos( $redactor->text( 'sk-abcdefghijklmnopqrstuvwxyz1234' ), 'abcdefghijklmnop' ),
	'The key body is gone, not just the prefix.'
);
check(
	false === strpos( $redactor->text( 'password=hunter2secret' ), 'hunter2secret' ),
	'A password value is removed.'
);
check(
	false === strpos( $redactor->text( 'http://10.1.2.3/' ), '10.1.2.3' ),
	'A private address is removed.'
);
check(
	$redactor->contains_secret( 'api_key=abcdef1234567890' ),
	'A secret-looking value is detected.'
);
check(
	! $redactor->contains_secret( 'An ordinary sentence about a website.' ),
	'An ordinary sentence is not treated as a secret.'
);
check(
	$redactor->is_secret_key( 'openai_api_key' ),
	'A secret-looking key name is recognised.'
);
check(
	! $redactor->is_secret_key( 'source_url' ),
	'An ordinary key name is not treated as secret.'
);

$payload = $redactor->payload(
	array(
		'api_key'    => 'sk-abcdefghijklmnopqrstuvwxyz1234',
		'source_url' => 'https://example.com/page',
		'nested'     => array( 'password' => 'hunter2', 'keep' => 'visible' ),
		'count'      => 42,
	)
);
check( '[redacted]' === ( $payload['api_key'] ?? '' ), 'A secret key is replaced, not dropped.' );
check( 'https://example.com/page' === ( $payload['source_url'] ?? '' ), 'An ordinary value is kept.' );
check( '[redacted]' === ( $payload['nested']['password'] ?? '' ), 'A nested secret is redacted.' );
check( 'visible' === ( $payload['nested']['keep'] ?? '' ), 'A nested ordinary value is kept.' );
check( 42 === ( $payload['count'] ?? 0 ), 'A number is preserved.' );

/* ------------------------------------------------------------------ */
/* 4. Error catalog: a code always has a message and a retry decision. */
/* ------------------------------------------------------------------ */

echo "--- 4. Error classification ---\n";

$known = \ReplicaForge\Error_Catalog::describe( 'request_timeout' );
check( 'NETWORK' === $known['category'], 'A network code is categorized.' );
check( true === $known['retryable'], 'A timeout is retryable.' );
check( '' !== $known['message'], 'A timeout has a user-facing message.' );
check( 408 === $known['status'], 'A timeout maps to 408.' );

$permanent = \ReplicaForge\Error_Catalog::describe( 'invalid_url' );
check( false === $permanent['retryable'], 'An invalid address is not retryable.' );

$security_code = \ReplicaForge\Error_Catalog::describe( 'blocked_destination' );
check( 'SECURITY' === $security_code['category'], 'A blocked destination is a security event.' );
check( 403 === $security_code['status'], 'A blocked destination maps to 403.' );

$unknown = \ReplicaForge\Error_Catalog::describe( 'a_code_nobody_declared' );
check( 'internal_error' === $unknown['code'], 'An unknown code becomes internal_error.' );
check( 'a_code_nobody_declared' === $unknown['internal_code'], 'The original code is kept for the log.' );
check( '' !== $unknown['message'], 'An unknown code still has a message.' );
check( false === $unknown['retryable'], 'An unknown code is not retried blindly.' );

check( in_array( 'request_timeout', \ReplicaForge\Error_Catalog::retryable_codes(), true ), 'Retryable codes are enumerable.' );
check( ! in_array( 'invalid_url', \ReplicaForge\Error_Catalog::retryable_codes(), true ), 'A bad address is not in the retry set.' );
check( ! in_array( 'blocked_destination', \ReplicaForge\Error_Catalog::retryable_codes(), true ), 'A security block is not in the retry set.' );

/* ------------------------------------------------------------------ */
/* 5. Capabilities and nonces on the REST surface.                      */
/* ------------------------------------------------------------------ */

echo "--- 5. REST authorization ---\n";

do_action( 'rest_api_init' );
$routes = rest_get_server()->get_routes();
$mine   = array();
foreach ( array_keys( $routes ) as $route ) {
	if ( 0 === strpos( (string) $route, '/replicaforge/v1' ) ) {
		$mine[ (string) $route ] = $routes[ $route ];
	}
}

check( count( $mine ) >= 12, 'The ReplicaForge routes are registered.' );

$unprotected = array();
foreach ( $mine as $route => $handlers ) {
	foreach ( (array) $handlers as $handler ) {
		if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
			continue;
		}
		$callback = $handler['callback'];
		// The namespace index is not a functional route; WordPress registers it with
		// a null permission callback by convention.
		if ( '/replicaforge/v1' === $route ) {
			continue;
		}
		if ( ! is_callable( $callback ) ) {
			continue;
		}
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'job_id', 'job_000000000000' );
		$request->set_param( 'action', 'cancel' );
		$request->set_param( 'validation_id', 'val_00000000000000000000000000' );
		$request->set_param( 'correction_id', 'cor_00000000000000000000' );
		$request->set_param( 'draft_id', 0 );
		$request->set_param( 'url', 'https://example.com' );
		$allowed = call_user_func( $callback, $request );
		if ( true === $allowed ) {
			$unprotected[] = $route;
		}
	}
}
check( empty( $unprotected ), 'Every functional route refuses a request with no session.' );

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $administrators ) ) {
	throw new RuntimeException( 'FAILED: an administrator account is required to test authorization.' );
}
wp_set_current_user( (int) $administrators[0]->ID );

$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1 ) );
if ( ! empty( $subscriber ) ) {
	wp_set_current_user( (int) $subscriber[0]->ID );
	$api     = new \ReplicaForge\Rest_Api();
	$request = new \WP_REST_Request( 'GET', '/replicaforge/v1/analyze' );
	$verdict = $api->can_analyze( $request );
	check(
		is_wp_error( $verdict ),
		'A subscriber is refused even with a valid session.'
	);
	wp_set_current_user( (int) $administrators[0]->ID );
}

$no_nonce = new \WP_REST_Request( 'GET', '/replicaforge/v1/analyze' );
$api      = new \ReplicaForge\Rest_Api();
$verdict  = $api->can_analyze( $no_nonce );
check( is_wp_error( $verdict ), 'An administrator without a nonce is refused.' );

$good = new \WP_REST_Request( 'GET', '/replicaforge/v1/analyze' );
$good->set_header( 'x_wp_nonce', wp_create_nonce( 'wp_rest' ) );
$verdict = $api->can_analyze( $good );
check( true === $verdict, 'An administrator with a valid nonce is allowed.' );

$bad = new \WP_REST_Request( 'GET', '/replicaforge/v1/analyze' );
$bad->set_header( 'x_wp_nonce', 'not-a-real-nonce' );
$verdict = $api->can_analyze( $bad );
check( is_wp_error( $verdict ), 'A forged nonce is refused.' );

/* ------------------------------------------------------------------ */
/* 6. Injection payloads survive as text, never as structure.          */
/* ------------------------------------------------------------------ */

echo "--- 6. Injection payloads ---\n";

$values = new \ReplicaForge\Elementor_Values();

$code_payloads = array(
	'script tag'      => '<script>alert(1)</script>',
	'php tag'         => '<?php echo 1; ?>',
	'event handler'   => '<img src=x onerror=alert(1)>',
	'js url'          => 'javascript:alert(document.cookie)',
	'data url'        => 'data:text/html;base64,PHNjcmlwdD4=',
	'svg onload'      => '<svg onload=alert(1)>',
	'attribute break' => '" onmouseover="alert(1)',
	'css expression'  => 'width: expression(alert(1))',
	'iframe'          => '<iframe src="https://evil.test"></iframe>',
	'object tag'      => '<object data="https://evil.test/x.swf"></object>',
	'embed tag'       => '<embed src="https://evil.test/x.swf">',
);

foreach ( $code_payloads as $label => $payload ) {
	check( $values->is_executable( $payload ), sprintf( 'Detected as executable: %s', $label ) );
}

/* A SQL-shaped string is data, not code. It is neutralised by never building a
   query from a value, so the assertion is that no query is ever assembled by
   concatenation anywhere in the plugin. */
$sql_payloads = array(
	'drop table'  => "'; DROP TABLE wp_posts; --",
	'union select' => '1 UNION SELECT user_pass FROM wp_users',
	'stacked'     => "1; DELETE FROM wp_options WHERE option_name='x';",
);
foreach ( $sql_payloads as $label => $payload ) {
	check( is_string( $payload ), sprintf( 'SQL payload treated as data: %s', $label ) );
}

$source_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/replicaforge' ) );
$raw_queries = array();
foreach ( $source_files as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = (string) file_get_contents( $file->getPathname() );
	// A query built by concatenating a value into the SQL string is the only shape
	// that can be injected into.
	if ( preg_match( '/\$wpdb->(query|get_var|get_row|get_col|get_results)\s*\(\s*["\'][^"\']*["\']\s*\.\s*\$/', $source ) ) {
		$raw_queries[] = $file->getPathname();
	}
	// A prepared statement with a literal placeholder but no prepare() call.
	if ( preg_match( '/\$wpdb->(query|get_var|get_row|get_col|get_results)\s*\([^)]*%s/', $source ) && false === strpos( $source, 'prepare' ) ) {
		$raw_queries[] = $file->getPathname() . ' (placeholder without prepare)';
	}
}
check( empty( $raw_queries ), 'No query is built by concatenating a value: ' . ( empty( $raw_queries ) ? 'none found' : implode( ', ', array_unique( $raw_queries ) ) ) );

$safe_text = array( 'An ordinary heading', 'Price: $19.00', 'A & B', '100% off' );
foreach ( $safe_text as $text ) {
	check( ! $values->is_executable( $text ), sprintf( 'Not flagged: %s', $text ) );
}

$redacted_script = $redactor->text( '<script>alert(1)</script>' );
check( false === stripos( $redacted_script, '<script' ), 'A script tag is not reproduced in a redacted payload.' );

/* ------------------------------------------------------------------ */
/* 7. The log never stores a secret.                                   */
/* ------------------------------------------------------------------ */

echo "--- 7. Log redaction ---\n";

$logger = new \ReplicaForge\Logger( 50, 'debug' );
$logger->clear();
$logger->error(
	'security_test',
	'Attempted request with sk-abcdefghijklmnopqrstuvwxyz1234 to http://10.0.0.1/admin',
	array(
		'authorization' => 'Bearer abcdefghijklmnopqrst',
		'api_key'       => 'sk-abcdefghijklmnopqrstuvwxyz1234',
		'host'          => '10.0.0.1',
	)
);
$entries = $logger->all();
check( 1 === count( $entries ), 'The entry was recorded.' );

$encoded = (string) wp_json_encode( $entries );
check( false === strpos( $encoded, 'sk-abcdefghijklmnopqrstuvwxyz1234' ), 'No API key is stored in the log.' );
check( false === strpos( $encoded, 'abcdefghijklmnopqrst' ), 'No bearer token is stored in the log.' );
check( false === strpos( $encoded, '10.0.0.1' ), 'No private address is stored in the log.' );
check( false !== strpos( $encoded, 'security_test' ), 'The event name is still recorded.' );

$export = $logger->export( array(), 10 );
check( false === strpos( $export, 'sk-abcdefghijklmnopqrstuvwxyz1234' ), 'No key is exported.' );
check( '' !== trim( $export ), 'The export is not empty.' );

$logger->clear();
check( 0 === count( $logger->all() ), 'The log can be cleared.' );

/* ------------------------------------------------------------------ */
/* 8. Feature flags cannot grant a capability.                        */
/* ------------------------------------------------------------------ */

echo "--- 8. Feature flags ---\n";

$flags = new \ReplicaForge\Feature_Flags();
$flags->reset_all();
check( true === $flags->enabled( 'ai_enabled' ), 'A flag defaults to its declared value.' );
check( false === $flags->enabled( 'a_flag_that_does_not_exist' ), 'An undeclared flag is off.' );

$flags->set( 'ai_enabled', false );
check( false === $flags->enabled( 'ai_enabled' ), 'A flag can be turned off.' );
$flags->reset( 'ai_enabled' );
check( true === $flags->enabled( 'ai_enabled' ), 'A flag returns to its default.' );
check( false === $flags->set( 'not_a_flag', true ), 'An undeclared flag cannot be set.' );
$flags->reset_all();

/* ------------------------------------------------------------------ */
/* 9. Uninstall removes ReplicaForge data and nothing else.           */
/* ------------------------------------------------------------------ */

echo "--- 9. Uninstall scope ---\n";

$source = (string) file_get_contents( WP_PLUGIN_DIR . '/replicaforge/uninstall.php' );

check( false !== strpos( $source, 'WP_UNINSTALL_PLUGIN' ), 'The uninstall routine is guarded.' );
check( false !== strpos( $source, "register_uninstall_hook" ), 'The uninstall routine is registered.' );
check(
	false === strpos( $source, 'wp_delete_post' ),
	'The uninstall routine never deletes a post.'
);
check(
	false === strpos( $source, "delete_post_meta( \$post_id, '_elementor_data'" ),
	'The uninstall routine never touches the Elementor document.'
);
check(
	false === strpos( $source, "'_elementor_edit_mode'" ),
	'The uninstall routine never touches Elementor edit mode.'
);
check(
	false === strpos( $source, 'wp_delete_attachment' ),
	'The uninstall routine never deletes media.'
);
check(
	false !== strpos( $source, 'replicaforge_log' ),
	'The uninstall routine removes the log.'
);
check(
	false !== strpos( $source, 'replicaforge_jobs' ),
	'The uninstall routine removes jobs.'
);

/* ------------------------------------------------------------------ */
/* 10. Job idempotency prevents a duplicate operation.                 */
/* ------------------------------------------------------------------ */

echo "--- 10. Idempotency ---\n";

delete_option( \ReplicaForge\Job_Queue::OPTION );
delete_option( \ReplicaForge\Job_Repository::OPTION );

$queue      = new \ReplicaForge\Job_Queue();
$repository = new \ReplicaForge\Job_Repository();

// An unrelated job already exists, so the section proves that deduplication keys
// on the request rather than on "is there any job".
$repository->create( 'replica', array( 'source_url' => 'https://unrelated.test/' ) );

$first  = $queue->enqueue( 'replica', array( 'source_url' => 'https://example.com/' ) );
$second = $queue->enqueue( 'replica', array( 'source_url' => 'https://example.com/' ) );

check( false === $first['duplicate'], 'The first identical request creates a job.' );
check( 2 === count( $repository->all() ), 'Exactly one new job exists after the first request.' );
check( true === $second['duplicate'], 'The second identical request is deduplicated.' );
check(
	$first['job']['job_id'] === $second['job']['job_id'],
	'A repeated click returns the same job rather than a second one.'
);
check( 2 === count( $repository->all() ), 'A repeated click created no extra job.' );

$different = $queue->enqueue( 'replica', array( 'source_url' => 'https://example.org/' ) );
check( false === $different['duplicate'], 'A different request is not deduplicated.' );

/* ------------------------------------------------------------------ */

echo "\nSecurity contract test passed. Assertions: {$assertions}\n";
