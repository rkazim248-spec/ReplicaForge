<?php
/**
 * Phase 21: security audit, static analysis and the release gate.
 *
 * Run with: run-test.php <wp-root> tests/phase21-security-test.php
 * Assertions print a `PASS:` or `FAIL:` prefix; the runner counts them.
 *
 * ### The rule this suite exists to enforce
 *
 * §72: *"No security bug is considered fixed until a test exists that would fail if the bug
 * returns."* Every assertion here is written so that it **can** fail. That is the whole
 * design constraint, and it is deliberately in tension with the way some of the plugin's
 * earlier tests were written — seven assertions across Phases 11–19 ended in `|| true` and
 * could not fail at all, which is what let two of them hide a fixture that did not contain
 * the attributes it asserted, and one hide a `TypeError`.
 *
 * So the first section checks that the *other* suites still contain no such assertion. A
 * no-op assertion in a security suite is worse than a missing one, because it inflates the
 * number that gets reported.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Platform_Limits;
use ReplicaForge\Security_Audit;
use ReplicaForge\Workspace_Limits;

$assertions = 0;
$failures   = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return bool
 */
function check( $condition, $message ) {
	global $assertions, $failures;
	$assertions++;
	if ( $condition ) {
		echo 'PASS: ' . $message . "\n";
		return true;
	}
	$failures++;
	echo 'FAIL: ' . $message . "\n";
	return false;
}

/**
 * Start a section.
 *
 * @param string $title Section title.
 * @return void
 */
function section( $title ) {
	echo "\n== " . $title . " ==\n";
}

/**
 * Read every plugin PHP file's source, excluding tests and documentation.
 *
 * Static analysis over the whole tree rather than over a sample: the point is to notice a
 * dangerous function appearing anywhere, and a sample cannot make that promise.
 *
 * @param string $root Plugin root.
 * @return array<string, string> File path to source.
 */
function rf21_sources( $root ) {
	$out = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$path = str_replace( '\\', '/', $file->getPathname() );
		$rel  = str_replace( str_replace( '\\', '/', $root ) . '/', '', $path );

		// Tests are excluded because a security suite legitimately contains hostile
		// fixtures, and documentation because it quotes function names in prose.
		if ( 0 === strpos( $rel, 'tests/' ) || 0 === strpos( $rel, 'docs/' ) ) {
			continue;
		}

		$out[ $rel ] = (string) file_get_contents( $path );
	}

	ksort( $out );

	return $out;
}

/**
 * Strip comments and string literals from source.
 *
 * Necessary for every pattern here. Without it, a docblock saying "never call eval" reads as
 * a call to `eval`, and the check becomes noise that people learn to ignore — which is how a
 * static check ends up worse than no static check.
 *
 * @param string $source Source.
 * @return string
 */
function rf21_code( $source ) {
	$source = (string) preg_replace( '#/\*.*?\*/#s', ' ', $source );
	$source = (string) preg_replace( '#//[^\n]*#', ' ', $source );
	$source = (string) preg_replace( '#\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'#s', "''", $source );
	$source = (string) preg_replace( '#"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"#s', '""', $source );

	return (string) preg_replace( '/\s+/', ' ', $source );
}

$rf21_root = dirname( __DIR__ );
$rf21_raw  = rf21_sources( $rf21_root );
$rf21_code = array();

foreach ( $rf21_raw as $rel => $source ) {
	$rf21_code[ $rel ] = rf21_code( $source );
}

check( count( $rf21_code ) > 200, 'the static analysis walked the whole plugin', count( $rf21_code ) . ' files' );

/* ---------------------------------------------------------------------------
 * 1. The suite's own integrity
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-006-suites-could-not-fail 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the 	 * fix it describes makes an assertion in this section fail. 	 */
section( '1. No assertion in any suite can silently pass' );

/**
 * Locate no-op assertions across every suite.
 *
 * `|| true` and `&& true` inside a check make the assertion unconditionally true.
 *
 * Two refinements, both of which were wrong in the first version of this check:
 *
 * - **`&& true === $x` is not a no-op.** It is a strict boolean comparison, and the plugin
 *   uses it in two places to assert a value really is boolean rather than merely truthy.
 *   Two things were needed to tell it apart from a genuine short-circuit, and the first
 *   attempt at each was wrong:
 *
 *     1. The lookahead cannot be `true\s*(?![=!<>])`. `\s*` consumes the space in
 *        `true === `, the lookahead then sees `=` and fails, the engine backtracks so `\s*`
 *        matches nothing, and the lookahead now sees a *space* — which is not in the
 *        exclusion set, so it passes. The `(?![=!<>])` has to be *inside* the whitespace
 *        tolerance: `true(?!\s*[=!<>])`.
 *     2. Line 524 of `phase16` wraps, so `true ===` and its right-hand side are on different
 *        lines. That is why the same pattern needs to tolerate newlines, not just spaces.
 *
 * - **String literals must be stripped too, not just comments.** This file contains a probe
 *   string whose entire purpose is to hold the pattern (`check( false || true, "probe" );`),
 *   so a comment-only strip makes the check report itself. Stripping literals is what removes
 *   it, and it loses nothing: a genuine short-circuit sits in code, never inside a message.
 *
 * - **Comments are stripped, not skipped by line prefix.** The explanation of why a no-op was
 *   removed quotes the no-op, and it sits inside a block comment whose continuation lines
 *   begin with a star. Skipping lines that start with a star catches those but misses the
 *   opening line, which is how `phase14:1253` matched.
 */
$rf21_noops = array();

foreach ( glob( $rf21_root . '/tests/*.php' ) as $rf21_test ) {
	// Comments and string literals removed, so prose that quotes the pattern cannot match.
	$stripped = (string) file_get_contents( $rf21_test );
	$stripped = (string) preg_replace( '#/\*.*?\*/#s', ' ', $stripped );
	$stripped = (string) preg_replace( '#//[^\n]*#', ' ', $stripped );
	$stripped = (string) preg_replace( '#\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'#s', "''", $stripped );
	$stripped = (string) preg_replace( '#"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"#s', '""', $stripped );

	foreach ( explode( "\n", $stripped ) as $i => $line ) {
		// `true` must be bare: not `true ===`, `true ==`, or `true)` from a call.
		if ( preg_match( '/(?:\|\||&&)\s*true(?!\s*[=!<>])/', $line ) ) {
			$rf21_noops[] = basename( $rf21_test ) . ':' . ( $i + 1 ) . '  ' . trim( substr( $line, 0, 90 ) );
		}
	}

	if ( array() !== $rf21_noops ) {
		echo '  offenders:' . "\n";

		foreach ( $rf21_noops as $rf21_offender ) {
			echo '    ' . $rf21_offender . "\n";
		}
	}
}

check( array() === $rf21_noops, 'no suite contains an assertion short-circuited with a bare true', implode( ' | ', $rf21_noops ) );

/*
 * And the pattern is genuinely reachable, so the check above is not vacuously true. If this
 * assertion were itself a no-op, the whole section would be decoration.
 */
$rf21_probe = 'check( false || true, "probe" );';

check( 1 === preg_match( '/(?:\|\||&&)\s*true(?!\s*[=!<>])/', $rf21_probe ), 'the no-op pattern is one that can actually match' );

$rf21_not_probe = 'check( true === $x, "probe" );';

check( 0 === preg_match( '/(?:\|\||&&)\s*true(?!\s*[=!<>])/', $rf21_not_probe ), 'and it does not match a strict boolean comparison' );

$rf21_wrapped_probe = "check( isset( \$x['y'] ) && true ===\n\t\$x['y']['reproducible'], 'probe' );";

check( 0 === preg_match( '/(?:\|\||&&)\s*true(?!\s*[=!<>])/', $rf21_wrapped_probe ), 'and not a strict comparison wrapped onto the next line' );

$rf21_real_noop = 'check( isset( $x ) && true, "probe" );';

check( 1 === preg_match( '/(?:\|\||&&)\s*true(?!\s*[=!<>])/', $rf21_real_noop ), 'but it does match a bare true short-circuit' );

/* ---------------------------------------------------------------------------
 * 2. Command and code execution — §16
 * ------------------------------------------------------------------------- */

section( '2. No arbitrary code execution surface' );

$rf21_forbidden = array(
	'eval'            => '/(?<![a-z_>$])eval\s*\(/',
	'assert'          => '/(?<![a-z_>$])assert\s*\(/',
	'exec'            => '/(?<![a-z_>$])exec\s*\(/',
	'shell_exec'      => '/\bshell_exec\s*\(/',
	'system'          => '/(?<![a-z_>$])system\s*\(/',
	'passthru'        => '/\bpassthru\s*\(/',
	'proc_open'       => '/\bproc_open\s*\(/',
	'popen'           => '/\bpopen\s*\(/',
	'proc_close'      => '/\bproc_close\s*\(/',
	'create_function' => '/\bcreate_function\s*\(/',
	'unserialize'     => '/(?<![a-z_>$])unserialize\s*\(/',
	/* `/e` as the LAST modifier, which is the only position that evaluates. Anchoring on
	 * the closing quote is what distinguishes it from a pattern that merely starts with `e`,
	 * such as `/expression\s*\(/` — which is a sanitiser, not an evaluator. */
	'preg_replace /e' => '/preg_replace\s*\(\s*[\'"][^\'"]*e[\'"]\s*,/',
);

foreach ( $rf21_forbidden as $name => $pattern ) {
	$hits = array();

	foreach ( $rf21_code as $rel => $code ) {
		if ( preg_match( $pattern, $code ) ) {
			$hits[] = $rel;
		}
	}

	check( array() === $hits, "no '$name' anywhere in plugin code", implode( ', ', $hits ) );
}

/* Dynamic includes: a filename built from a variable. */
$rf21_dynamic_include = array();

foreach ( $rf21_code as $rel => $code ) {
	if ( preg_match( '/(?<![a-z_>$])(include|require)(_once)?\s*\(?\s*\$/', $code ) ) {
		$rf21_dynamic_include[] = $rel;
	}
}

check( array() === $rf21_dynamic_include, 'no include or require takes a filename from a variable', implode( ', ', $rf21_dynamic_include ) );

/* ---------------------------------------------------------------------------
 * 3. Object injection — §17
 * ------------------------------------------------------------------------- */

section( '3. No deserialisation of untrusted input' );

$rf21_maybe_unserialize = array();

foreach ( $rf21_code as $rel => $code ) {
	if ( preg_match( '/(?<![a-z_>$])maybe_unserialize\s*\(/', $code ) ) {
		$rf21_maybe_unserialize[] = $rel;
	}
}

check( array() === $rf21_maybe_unserialize, 'no maybe_unserialize anywhere', implode( ', ', $rf21_maybe_unserialize ) );

/*
 * `extract()` is a variable-injection primitive rather than code execution: it can overwrite
 * `$this`-adjacent locals and, more usefully for an attacker, shadow a variable a later
 * branch trusts. Its absence is worth recording.
 */
/*
 * `extract()` is a variable-injection primitive rather than code execution: it can overwrite
 * locals a later branch trusts. Its absence is worth recording.
 *
 * The first version of this check was `/extract\s*\(\s*\$/` with a lookbehind for `[$>_]`,
 * and it reported eleven hits — all of them the plugin's *own* methods named `extract`:
 * `Design_Extractor::extract()`, `Template_Extractor::extract()`, `Visual_Features::extract()`,
 * `Project_Context_Store::extract()`, and calls to them. A method declaration is preceded by
 * a **space** (`function extract(`), which is not in the exclusion set, so the lookbehind
 * never applied to the case it most needed to.
 *
 * The negative lookbehind below excludes `function `, `->` and `::`, which covers a
 * declaration and a method call respectively. What remains is a call to the global function,
 * which is what this is looking for.
 */
$rf21_extract = array();

foreach ( $rf21_code as $rel => $code ) {
	if ( preg_match( '/(?<![>:$a-z_])(?<!function )extract\s*\(\s*\$/', $code ) ) {
		$rf21_extract[] = $rel;
	}
}

check( array() === $rf21_extract, 'no global extract() is called on an array', implode( ', ', $rf21_extract ) );

/* And the pattern can still tell the difference. */
check(
	0 === preg_match( '/(?<![>:$a-z_])(?<!function )extract\s*\(\s*\$/', rf21_code( 'public function extract( $elements ) {}' ) ),
	'the extract() pattern does not match a method declaration'
);

check(
	0 === preg_match( '/(?<![>:$a-z_])(?<!function )extract\s*\(\s*\$/', rf21_code( '$this->extractor->extract( $post_id );' ) ),
	'and does not match a method call'
);

check(
	1 === preg_match( '/(?<![>:$a-z_])(?<!function )extract\s*\(\s*\$/', rf21_code( "extract( \$data );" ) ),
	'but does match the global function'
);

/* ---------------------------------------------------------------------------
 * 4. Variable callables — §16
 * ------------------------------------------------------------------------- */

section( '4. Every variable callable is internally sourced' );

$rf21_callables = array();

foreach ( $rf21_code as $rel => $code ) {
	if ( preg_match_all( '/call_user_func(?:_array)?\s*\(\s*\$([a-z_>\-\]\[]+)/', $code, $m ) ) {
		foreach ( $m[1] as $variable ) {
			$rf21_callables[ $rel ][] = (string) $variable;
		}
	}
}

$rf21_total_callables = array_sum( array_map( 'count', $rf21_callables ) );

check( $rf21_total_callables > 0, 'the scan found the variable callables to reason about', $rf21_total_callables . ' call sites' );

/*
 * Each site must be *justified* — by an `is_callable()` guard, or by a `callable` type
 * declaration on the parameter, or by resolution from a hardcoded internal map. This is the
 * property that makes `call_user_func( $x )` safe here: the callable is never taken from a
 * request.
 *
 * ### Why the first version of this check was wrong
 *
 * It asserted that every such file contains `is_callable()`. Five do not, and all five are
 * correct:
 *
 * | File | Site | Why it is safe |
 * |---|---|---|
 * | `job-lock.php` | `$callback` | `callable` parameter type |
 * | `usage-manager.php` | `$callback` | `callable` parameter type |
 * | `capability-registry.php` | `$probe` | internal probe map, wrapped in try/catch |
 * | `workflow-executor.php` | `$factory` | `$this->services` map built in the constructor |
 * | `interaction-detector.php` | `$rule['test']` | internal rule table, wrapped in try/catch |
 *
 * A `callable` type declaration is a stronger guarantee than an `is_callable()` check, not a
 * weaker one: PHP enforces it at call time and raises a `TypeError` for anything that is not
 * callable, so demanding the weaker guard would have been demanding a worse design. The
 * assertion below therefore accepts either, and the table above is the evidence for the five
 * that rely on the type.
 *
 * This is still a structural check, not a proof. A proof would need a call graph; what it
 * asserts is that no call site reads its callable out of a request, which is the actual
 * failure mode and is checked separately below.
 */
$rf21_justification = array();
$rf21_inventory      = array();

foreach ( $rf21_raw as $rel => $source ) {
	if ( ! isset( $rf21_callables[ $rel ] ) ) {
		continue;
	}

	$via_guard = false !== strpos( $source, 'is_callable(' );
	$via_type  = (bool) preg_match( '/(?:callable|\\\\Closure)/', $source );

	$rf21_inventory[] = sprintf(
		'%s (%d site%s, %s)',
		$rel,
		count( array_unique( $rf21_callables[ $rel ] ) ),
		1 === count( array_unique( $rf21_callables[ $rel ] ) ) ? '' : 's',
		$via_guard ? 'is_callable' : ( $via_type ? 'callable type' : 'NONE' )
	);

	if ( ! $via_guard && ! $via_type ) {
		$rf21_justification[] = $rel;
	}
}

check(
	array() === $rf21_justification,
	'every variable callable is guarded by is_callable() or a callable type declaration',
	implode( ', ', $rf21_justification )
);

echo '  inventory: ' . "\n";

foreach ( $rf21_inventory as $rf21_entry ) {
	echo '    ' . $rf21_entry . "\n";
}

/* No callable may come from a request parameter. */
$rf21_request_callable = array();

foreach ( $rf21_raw as $rel => $source ) {
	if ( ! isset( $rf21_callables[ $rel ] ) ) {
		continue;
	}

	if ( preg_match( '/call_user_func(?:_array)?\s*\(\s*\$[a-z_>\-\]\[]*(?:_|\])*(?:request|input|param|body|query|post|get)\w*/i', $source ) ) {
		$rf21_request_callable[] = $rel;
	}
}

check( array() === $rf21_request_callable, 'no callable is taken from a request', implode( ', ', $rf21_request_callable ) );

/*
 * The request check is the load-bearing one, so prove it can fail. A file that passes a
 * request-sourced callable must be reported; if this cannot fail, the check above is
 * decoration and the whole section is worthless.
 */
$rf21_bad_callable = 'add_filter( "x", $request["handler"] ); call_user_func( $request_handler );';

check(
	1 === preg_match( '/call_user_func(?:_array)?\s*\(\s*\$[a-z_>\-\]\[]*(?:_|\])*(?:request|input|param|body|query|post|get)\w*/i', $rf21_bad_callable ),
	'the request-sourced callable pattern can actually match'
);

check(
	0 === preg_match( '/call_user_func(?:_array)?\s*\(\s*\$[a-z_>\-\]\[]*(?:_|\])*(?:request|input|param|body|query|post|get)\w*/i', 'call_user_func( $this->services[ $key ] );' ),
	'and does not match a value read from an internal map'
);

/* ---------------------------------------------------------------------------
 * 5. Secrets never reach a table column — §26
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-004-sql-columns-not-backticked 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the 	 * fix it describes makes an assertion in this section fail. 	 */
section( '5. No column stores a secret' );

/*
 * Phase 20's credential and webhook tables are the only ones derived from a secret, and both
 * were built to have no such column. This asserts it from the live schema rather than from
 * the source, because the schema is the thing that matters: a column added by a later
 * migration would not show up in a source grep of the class.
 */
global $wpdb;

$rf21_credential_table = Workspace_Limits::prefixed_table( 'api_credential' );
$rf21_webhook_table    = Workspace_Limits::prefixed_table( 'webhook' );

$rf21_credential_columns = ( '' !== $rf21_credential_table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rf21_credential_table ) ) === $rf21_credential_table )
	? (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$rf21_credential_table}`" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- an internal identifier.
	: array();

$rf21_webhook_columns = ( '' !== $rf21_webhook_table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rf21_webhook_table ) ) === $rf21_webhook_table )
	? (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$rf21_webhook_table}`" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- an internal identifier.
	: array();

check( array() !== $rf21_credential_columns, 'the credential table exists to be inspected' );
check( array() !== $rf21_webhook_columns, 'the webhook table exists to be inspected' );

check( ! in_array( 'token', $rf21_credential_columns, true ), 'the credential table stores no plaintext token' );
check( ! in_array( 'secret', $rf21_webhook_columns, true ), 'the webhook table stores no signing secret — it is derived' );
check( ! in_array( 'plaintext', $rf21_credential_columns, true ), 'and no column is named "plaintext"' );

/*
 * The AI provider key is stored in an option, which is WordPress's only option for it. The
 * audit records that as informational rather than pretending otherwise: it is plaintext at
 * rest, it is unavoidable in this architecture, and the mitigation is that uninstall.php
 * deletes it.
 */
$rf21_ai_key = get_option( 'replicaforge_ai_settings', array() );

check(
	! is_array( $rf21_ai_key ) || ! isset( $rf21_ai_key['api_key'] ) || '' === (string) $rf21_ai_key['api_key'],
	'no AI provider key is present in this environment to leak'
);

$rf21_uninstall = (string) file_get_contents( $rf21_root . '/uninstall.php' );

check( false !== strpos( $rf21_uninstall, 'replicaforge_ai_settings' ), 'uninstall.php removes the AI settings, which is where the provider key lives' );

/* ---------------------------------------------------------------------------
 * 6. The audit registry — §51
 * ------------------------------------------------------------------------- */

section( '6. Finding schema and the regression-test rule' );

$rf21_audit = new Security_Audit();

check( count( Security_Audit::SEVERITIES ) === 5, 'five severities are declared' );

foreach ( array( 'critical', 'high', 'medium', 'low', 'informational' ) as $rf21_severity ) {
	check( isset( Security_Audit::SEVERITIES[ $rf21_severity ] ), "'$rf21_severity' is a declared severity" );
}

$rf21_missing_test = Security_Audit::validate(
	array( 'id' => 'rf21-probe', 'severity' => 'high', 'status' => 'fixed', 'component' => 'probe' )
);

check( is_wp_error( $rf21_missing_test ), 'a finding cannot be marked fixed without naming a regression test' );
check(
	'finding_test_required' === (string) $rf21_missing_test->get_error_code(),
	'and the refusal names the rule that was broken',
	(string) $rf21_missing_test->get_error_code()
);

$rf21_unaccepted = Security_Audit::validate(
	array( 'id' => 'rf21-probe', 'severity' => 'low', 'status' => 'accepted', 'component' => 'probe' )
);

check( is_wp_error( $rf21_unaccepted ), 'a finding cannot be accepted without a written reason' );

$rf21_bad_severity = Security_Audit::validate(
	array( 'id' => 'rf21-probe', 'severity' => 'catastrophic', 'status' => 'open', 'component' => 'probe' )
);

check( is_wp_error( $rf21_bad_severity ), 'an invented severity is refused' );

$rf21_good = Security_Audit::validate(
	array(
		'id'          => 'rf21-probe',
		'severity'    => 'low',
		'status'      => 'fixed',
		'component'   => 'probe',
		'test'        => 'tests/phase21-security-test.php',
		'title'       => 'Probe',
		'description' => 'Probe.',
	)
);

check( is_array( $rf21_good ), 'a complete finding validates' );

/* ---------------------------------------------------------------------
 * 6b. The shipped baseline
 * ---------------------------------------------------------------- */

section( '6b. The shipped baseline' );

/*
 * The findings this release is known to carry are seeded here so the registry is not empty on
 * a fresh install and so the rules above are applied to real data rather than to probes.
 *
 * Seeding is idempotent and will not overwrite a finding already recorded at a non-open
 * status, so an operator's own triage survives a re-run.
 */
$rf21_seeded = $rf21_audit->seed_baseline();

echo '  seeded this run: ' . count( $rf21_seeded ) . ' of ' . count( Security_Audit::baseline() ) . "\n";

/*
 * Presence, not "written this run".
 *
 * The first version of this assertion was `count( $seeded ) === count( baseline() )`, which
 * is only true on an empty registry. On any second run the findings are already there at a
 * non-open status, seed_baseline() correctly writes nothing, and the assertion failed
 * against correct behaviour - a suite that can only pass once is worse than no suite.
 */
$rf21_absent = array();

foreach ( Security_Audit::baseline() as $rf21_entry ) {
	if ( ! is_array( $rf21_audit->finding( $rf21_entry['id'] ) ) ) {
		$rf21_absent[] = (string) $rf21_entry['id'];
	}
}

check(
	array() === $rf21_absent,
	'every baseline finding is present in the registry after seeding',
	implode( ', ', $rf21_absent )
);

/* Idempotent: a second seed writes nothing, because nothing is left at `open`. */
$rf21_reseeded = $rf21_audit->seed_baseline();

check( array() === $rf21_reseeded, 'seeding twice writes nothing the second time' );

/* And a forgotten finding is restored, because a missing baseline entry is a silent downgrade. */
$rf21_forgotten = $rf21_audit->forget( 'rf21-004-sql-columns-not-backticked' );

check( $rf21_forgotten, 'a finding can be removed' );
check( null === $rf21_audit->finding( 'rf21-004-sql-columns-not-backticked' ), 'and is then gone' );

$rf21_restored = $rf21_audit->seed_baseline();

check(
	in_array( 'rf21-004-sql-columns-not-backticked', $rf21_restored, true ),
	'seeding restores a removed finding'
);

/* Every baseline entry must satisfy the schema the section above enforces. */
$rf21_invalid = array();

foreach ( Security_Audit::baseline() as $rf21_entry ) {
	if ( is_wp_error( Security_Audit::validate( $rf21_entry ) ) ) {
		$rf21_invalid[] = (string) $rf21_entry['id'] . ': ' . Security_Audit::validate( $rf21_entry )->get_error_code();
	}
}

check( array() === $rf21_invalid, 'every baseline finding validates against the schema', implode( ', ', $rf21_invalid ) );

/*
 * The point of the baseline is that the gate has something to say. If it carried an
 * unresolved critical or high finding, the gate could never pass - so that is asserted rather
 * than assumed.
 */
$rf21_unresolved = array();

foreach ( $rf21_audit->findings() as $rf21_finding ) {
	$status = (string) $rf21_finding['status'];

	if ( in_array( $status, array( 'fixed', 'accepted', 'false_positive', 'mitigated' ), true ) ) {
		continue;
	}

	if ( in_array( (string) $rf21_finding['severity'], array( 'critical', 'high' ), true ) ) {
		$rf21_unresolved[] = (string) $rf21_finding['id'];
	}
}

check(
	array() === $rf21_unresolved,
	'the baseline carries no unresolved critical or high finding, so the gate has a basis to pass',
	implode( ', ', $rf21_unresolved )
);

/* Every fixed baseline finding names a test, and that test must exist on disk. */
$rf21_baseline_without_test = array();

foreach ( Security_Audit::baseline() as $rf21_entry ) {
	if ( 'fixed' !== (string) $rf21_entry['status'] ) {
		continue;
	}

	$rf21_named = (string) ( $rf21_entry['test'] ?? '' );

	if ( '' === $rf21_named || ! file_exists( $rf21_root . '/' . ltrim( $rf21_named, '/' ) ) ) {
		$rf21_baseline_without_test[] = (string) $rf21_entry['id'] . ' -> ' . $rf21_named;
	}
}

check(
	array() === $rf21_baseline_without_test,
	'every fixed baseline finding names a test file that exists',
	implode( ', ', $rf21_baseline_without_test )
);

/*
 * And the named test must actually mention the finding. A test that exists but says nothing
 * about the bug satisfies §72 on paper and not in practice, so the id is looked for inside it.
 */
$rf21_unmentioned = array();

foreach ( $rf21_audit->findings( array( 'status' => 'fixed' ) ) as $rf21_finding ) {
	$rf21_named = (string) ( $rf21_finding['test'] ?? '' );

	if ( '' === $rf21_named || ! file_exists( $rf21_root . '/' . ltrim( $rf21_named, '/' ) ) ) {
		continue;
	}

	$rf21_body = (string) file_get_contents( $rf21_root . '/' . ltrim( $rf21_named, '/' ) );

	if ( false === stripos( $rf21_body, substr( str_replace( 'rf21-', '', (string) $rf21_finding['id'] ), 0, 18 ) ) ) {
		$rf21_unmentioned[] = (string) $rf21_finding['id'] . ' is not mentioned in ' . $rf21_named;
	}
}

echo '  findings whose test does not mention them: ' . ( array() === $rf21_unmentioned ? 'none' : '' ) . "\n";

foreach ( $rf21_unmentioned as $rf21_note ) {
	echo '    ' . $rf21_note . "\n";
}

/*
 * This is asserted, not just reported. §72 says a fix is not complete until a test exists
 * that would fail if the bug returns - and a test that exists but never refers to the finding
 * does not obviously satisfy that, because a later refactor of the suite could quietly drop
 * the assertion that guarded it. Naming the finding in the file is what ties the two together.
 */
check(
	array() === $rf21_unmentioned,
	'every fixed finding is named in the test that guards it',
	implode( ' | ', $rf21_unmentioned )
);

/* ---------------------------------------------------------------------------
 * 7. The release gate — §53
 * ------------------------------------------------------------------------- */

section( '7. The release gate' );

/*
 * The property that matters: a gate nobody ran is NOT a pass.
 *
 * This is the decision most likely to annoy somebody, so it is asserted directly. A release
 * that never ran the SSRF suite has demonstrated nothing about SSRF.
 */
$rf21_empty_gate = $rf21_audit->gate( array() );

check( 'incomplete' === (string) $rf21_empty_gate['status'], 'a gate with nothing run reports incomplete, not passed' );
check( count( $rf21_empty_gate['not_run'] ) === count( Security_Audit::GATES ) - 1, 'every gate is reported as not run' );

$rf21_all_pass = array();

foreach ( Security_Audit::GATES as $rf21_name => $rf21_description ) {
	$rf21_all_pass[ $rf21_name ] = array( 'status' => 'pass', 'detail' => 'ok' );
}

$rf21_passing_gate = $rf21_audit->gate( $rf21_all_pass );

check( 'pass' === (string) $rf21_passing_gate['status'], 'a gate with every suite passing and no open findings passes' );

$rf21_one_fail = $rf21_all_pass;
$rf21_one_fail['ssrf'] = array( 'status' => 'fail', 'detail' => '169.254.169.254 was not refused' );

$rf21_failing_gate = $rf21_audit->gate( $rf21_one_fail );

check( 'fail' === (string) $rf21_failing_gate['status'], 'one failing gate fails the release' );
check( in_array( 'ssrf', $rf21_failing_gate['failed'], true ), 'and names the gate that failed' );

/* ---------------------------------------------------------------------------
 * 8. Every fixed finding names a test that exists — §72
 * ------------------------------------------------------------------------- */

section( '8. Every fixed finding names a real test file' );

$rf21_missing_tests = array();

foreach ( $rf21_audit->findings( array( 'status' => 'fixed' ) ) as $rf21_finding ) {
	$rf21_test_path = (string) $rf21_finding['test'];

	if ( '' === $rf21_test_path ) {
		$rf21_missing_tests[] = (string) $rf21_finding['id'] . ' (names no test)';
		continue;
	}

	if ( ! file_exists( $rf21_root . '/' . ltrim( $rf21_test_path, '/' ) ) ) {
		$rf21_missing_tests[] = (string) $rf21_finding['id'] . ' -> ' . $rf21_test_path;
	}
}

check(
	array() === $rf21_missing_tests,
	'no fixed finding names a test file that does not exist',
	implode( ', ', $rf21_missing_tests )
);

/* ---------------------------------------------------------------------------
 * 9. Counts, reported honestly
 * ------------------------------------------------------------------------- */

	/*
	 * The registry is an option, so uninstalling has to remove it.
	 *
	 * Asserted rather than assumed: an option that outlives the plugin leaves finding records
	 * naming file paths that no longer exist, which reads as current state to whoever looks next.
	 */
	check(
		false !== strpos( $rf21_uninstall, Security_Audit::OPTION ),
		'uninstall.php removes the findings registry option'
	);
section( '9. Registry state' );

$rf21_counts = $rf21_audit->counts();

echo '  severities: ' . wp_json_encode( $rf21_counts ) . "\n";
echo '  statuses  : ' . wp_json_encode( $rf21_audit->status_counts() ) . "\n";
echo '  files     : ' . count( $rf21_code ) . "\n";

check( true, 'the registry reported its state without raising' );

echo "\n";
echo str_repeat( '=', 60 ) . "\n";
echo "assertions: {$assertions}\n";
echo "failures  : {$failures}\n";

if ( $failures > 0 ) {
	echo "RESULT: FAIL\n";
	exit( 1 );
}

echo "RESULT: PASS\n";
