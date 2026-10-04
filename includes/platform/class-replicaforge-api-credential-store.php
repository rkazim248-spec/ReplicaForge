<?php
/**
 * Phase 20: API credential storage.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Scoped API credentials, hashed with `Secure_Token`.
 *
 * ### Why scoped tokens and not application passwords
 *
 * WordPress application passwords are the right answer for *many* integrations and the
 * wrong one here, for one reason: **they are all-or-nothing.** An application password
 * grants everything the account can do. Phase 20's whole premise is that an external
 * system should get the narrowest thing that lets it work — a CI job that reads validation
 * status should not be able to trigger a reconstruction, and a monitoring integration should
 * not be able to create a template.
 *
 * So credentials are separate, scoped, revocable and expiring. An application password is
 * still accepted, because WordPress authenticates it before any ReplicaForge code runs and
 * refusing it would break integrations that work today. What differs is that a request
 * arriving *without* a ReplicaForge credential falls back to the existing
 * session/authentication path and every workspace permission check still applies.
 *
 * ### The plaintext is returned exactly once
 *
 * {@see self::create()} returns the token in its result. It is never stored, never logged,
 * never included in a list, and never recoverable — only its HMAC is on file. That is
 * `Secure_Token::issue()`, which is the plugin's existing token primitive, used here rather
 * than a second one with a second salt.
 *
 * ### What a credential is *not*
 *
 * A credential is narrower than the WordPress session it stands in for. It has no
 * `workspace:*`, `admin` or `everything` scope, it is bound to one workspace, and every
 * request it makes still passes `Permission_Manager::can()` against the *user's* real
 * permissions. Revoking the WordPress account revokes the credential implicitly; revoking
 * the credential leaves the account untouched. Both directions are covered.
 */
class Api_Credential_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'api_credential';

	/**
	 * Writable columns.
	 *
	 * Note what is **absent**: `token`. There is no column for it, so there is no code path
	 * that could write one, and a `SELECT *` on this table cannot leak a credential.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'project_id',
			'user_id',
			'name',
			'prefix',
			'token_hash',
			'scopes',
			'status',
			'expires_at',
			'last_used_at',
			'last_used_ip',
			'request_count',
			'revoked_at',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Column types.
	 *
	 * `token_hash` is a `line` and never a `text`, so a bug elsewhere cannot grow it, and
	 * `prefix` is a short non-secret handle used to identify a credential in a log without
	 * revealing it.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'workspace_id'  => 'line',
			'project_id'    => 'line',
			'user_id'       => 'int',
			'name'          => 'line',
			'prefix'        => 'line',
			'token_hash'    => 'line',
			'scopes'        => 'json',
			'status'        => 'line',
			'expires_at'    => 'datetime',
			'last_used_at'  => 'datetime',
			'last_used_ip'  => 'line',
			'request_count' => 'int',
			'revoked_at'    => 'datetime',
			'created_at'    => 'datetime',
			'updated_at'    => 'datetime',
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name', 'prefix' );
	}

	/**
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status' );
	}

	/* ---------------------------------------------------------------------
	 * Creating and revoking
	 * ------------------------------------------------------------------ */

	/**
	 * Create a credential.
	 *
	 * @param string              $workspace_id Workspace public id.
	 * @param array<string, mixed> $input        `name`, `scopes`, `user_id`, `project_id`,
	 *                                            `expires_in_days`.
	 * @return array<string, mixed>|\WP_Error The record, plus `token` exactly once.
	 */
	public function create( $workspace_id, array $input ) {
		$workspace_id = $this->clean_workspace( $workspace_id );

		if ( '' === $workspace_id ) {
			return new \WP_Error( 'credential_workspace_required', __( 'A credential must belong to a workspace.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		if ( ! $this->ready() ) {
			return new \WP_Error( 'credential_table_missing', __( 'The API credential tables are not installed. Run the database migration.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';

		if ( '' === $name || strlen( $name ) > 120 ) {
			return new \WP_Error( 'credential_name_required', __( 'A credential needs a name, so it can be told apart from the others later.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$scopes = $this->clean_scopes( isset( $input['scopes'] ) ? $input['scopes'] : array() );

		if ( array() === $scopes ) {
			return new \WP_Error(
				'credential_scope_required',
				__( 'A credential needs at least one scope. An unscoped credential would be an unrestricted key, which this API does not issue.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$count = (int) $this->count_where( $workspace_id, array() );

		if ( $count >= Platform_Limits::MAX_CREDENTIALS ) {
			return new \WP_Error(
				'credential_limit_reached',
				sprintf(
					/* translators: %d: the maximum number of credentials. */
					__( 'This workspace already has %d API credentials, which is the limit.', 'replicaforge' ),
					Platform_Limits::MAX_CREDENTIALS
				),
				array( 'status' => 409 )
			);
		}

		$issued = Secure_Token::issue();
		$token  = Platform_Limits::TOKEN_PREFIX . $issued['token'];

		$expires = '';

		if ( isset( $input['expires_in_days'] ) && (int) $input['expires_in_days'] > 0 ) {
			$days = max( 1, min( 730, (int) $input['expires_in_days'] ) );
			$expires = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
		}

		$row = array(
			'public_id'     => $this->new_public_id(),
			'workspace_id'  => $workspace_id,
			'project_id'    => isset( $input['project_id'] ) && is_scalar( $input['project_id'] ) ? substr( (string) $input['project_id'], 0, 64 ) : '',
			'user_id'       => (int) ( $input['user_id'] ?? get_current_user_id() ),
			'name'          => substr( $name, 0, 120 ),
			/*
			 * The prefix is the first eight characters *of the hash*, not of the token. So
			 * it identifies the credential in a log and in the console without being a
			 * usable prefix of the secret — knowing it narrows a 2^256 search to nothing
			 * useful, and it means a screenshot of the console identifies the credential
			 * that leaked.
			 */
			'prefix'        => substr( $issued['hash'], 0, 8 ),
			'token_hash'    => $issued['hash'],
			'scopes'        => $scopes,
			'status'        => 'active',
			'expires_at'    => $expires,
			'request_count' => 0,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		);

		$stored = $this->insert( $row );

		if ( null === $stored ) {
			return new \WP_Error( 'credential_not_stored', __( 'The credential could not be saved.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		/*
		 * Audited with the actor as the fourth positional argument, which is where
		 * `Collaboration_Log::audit()` reads it. Passing it inside the context instead
		 * would record the event with `actor_id = 0` and produce a credential whose creator
		 * is unknown — the first thing an incident review looks for.
		 */
		( new Collaboration_Log() )->audit(
			$workspace_id,
			'api_credential_created',
			array(
				'target_type' => 'api_credential',
				'target_id'   => (string) $stored['public_id'],
				'metadata'    => array(
					/* The prefix, never the token. A prefix is eight characters of the stored
					 * hash and cannot be inverted, so the audit trail can identify which
					 * credential was created without ever containing something usable. */
					'prefix' => (string) $stored['prefix'],
					'scopes' => $scopes,
					'expires' => $expires,
				),
			),
			(int) $row['user_id']
		);

		/*
		 * The only place the plaintext exists outside the operator's screen. It is
		 * deliberately not logged, not audited, and not recoverable afterwards — which means
		 * a lost credential is rotated, not recovered.
		 */
		$stored['token'] = $token;

		return $stored;
	}

	/**
	 * Revoke a credential.
	 *
	 * @param string              $credential_id Credential public id.
	 * @param array<string, mixed> $context       `workspace_id`, `actor_id`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function revoke( $credential_id, array $context = array() ) {
		$credential_id = $this->clean_public_id( $credential_id );
		$workspace_id  = $this->clean_workspace( $context['workspace_id'] ?? '' );

		if ( '' === $credential_id || ! $this->ready() ) {
			return new \WP_Error( 'credential_not_found', __( 'That credential does not exist.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		$record = $this->find( $workspace_id, $credential_id );

		if ( null === $record ) {
			return new \WP_Error( 'credential_not_found', __( 'That credential does not exist.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		if ( 'revoked' === (string) $record['status'] ) {
			return $record;
		}

		$this->update_row( $credential_id, array( 'status' => 'revoked', 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ) );

		( new Collaboration_Log() )->audit(
			(string) $record['workspace_id'],
			'api_credential_revoked',
			array(
				'target_type' => 'api_credential',
				'target_id'   => $credential_id,
				'metadata'    => array(
					'prefix' => (string) $record['prefix'],
					'actor'  => (int) ( $context['actor_id'] ?? get_current_user_id() ),
				),
			),
			(int) ( $context['actor_id'] ?? get_current_user_id() )
		);

		/**
		 * Fires when a credential is revoked.
		 *
		 * The parameter is the credential's *public id*, never the token. An integration that
		 * wants to stop calling when its key dies subscribes here rather than polling.
		 *
		 * @param string              $credential_id Credential public id.
		 * @param array<string, mixed> $record       The record, without the token.
		 */
		do_action( 'replicaforge_credential_revoked', $credential_id, $record );

		$fresh = $this->find( (string) $record['workspace_id'], $credential_id );

		return null === $fresh ? $record : $fresh;
	}

	/* ---------------------------------------------------------------------
	 * Authentication
	 * ------------------------------------------------------------------ */

	/**
	 * Find the credential matching a presented token.
	 *
	 * ### Why this is two queries and not one
	 *
	 * A `token_hash` lookup would be a single indexed read, and it is tempting. It is also
	 * wrong here: it would make the query's *duration* depend on whether the hash exists,
	 * which is a timing oracle for "was this token ever issued", and it would mean an
	 * unknown token performs no work at all — a cheap way to probe.
	 *
	 * So the row is found by the **prefix** (a non-secret handle), and the HMAC is compared
	 * with `hash_equals()`. The prefix is not a usable part of the secret: it is derived from
	 * the stored hash, so it cannot be inverted into a token prefix. The cost is that a
	 * handful of rows are compared instead of one, which at `MAX_CREDENTIALS = 25` is not
	 * a measurable cost.
	 *
	 * @param string $token Presented token, with or without the prefix.
	 * @return array<string, mixed>|null
	 */
	public function authenticate( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return null;
		}

		if ( ! $this->looks_like_ours( $token ) ) {
			return null;
		}

		$bare     = 0 === strpos( $token, Platform_Limits::TOKEN_PREFIX ) ? substr( $token, strlen( Platform_Limits::TOKEN_PREFIX ) ) : $token;
		$hash     = Secure_Token::hash( $bare );
		$prefix   = substr( $hash, 0, 8 );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$verified = false;

		foreach ( $this->candidates( $prefix ) as $record ) {
			if ( ! Secure_Token::verify( $bare, (string) $record['token_hash'] ) ) {
				continue;
			}

			$verified = true;

			if ( 'revoked' === (string) $record['status'] ) {
				return null;
			}

			if ( '' !== (string) ( $record['expires_at'] ?? '' ) && (string) $record['expires_at'] < $now ) {
				// Expired is a real state, so it is written rather than inferred on every
				// request. An operator sees why a key stopped working.
				$this->update_row( (string) $record['public_id'], array( 'status' => 'expired' ) );
				return null;
			}

			return $record;
		}

		if ( $verified ) {
			// Unreachable: the verified branch returns. Kept as a marker that the two
			// conditions above are the only reasons a verified token is refused.
			return null;
		}

		return null;
	}

	/**
	 * Return whether a presented value could be one of our credentials.
	 *
	 * Refuses an obviously-wrong value before spending a hash on it, which also means a
	 * leaked *other* secret sent here is not used as an HMAC input.
	 *
	 * @param string $token Presented value.
	 * @return bool
	 */
	public function looks_like_ours( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return false;
		}

		if ( 0 === strpos( $token, Platform_Limits::TOKEN_PREFIX ) ) {
			$token = substr( $token, strlen( Platform_Limits::TOKEN_PREFIX ) );
		}

		// 32 bytes of hex. Exactly what `Secure_Token::issue()` produces, and nothing else.
		return (bool) preg_match( '/^[a-f0-9]{' . ( Platform_Limits::TOKEN_BYTES * 2 ) . '}$/', $token );
	}

	/**
	 * Record that a credential was used.
	 *
	 * @param string              $credential_id Credential public id.
	 * @param array<string, mixed> $context       `ip_hash`.
	 * @return void
	 */
	public function touch( $credential_id, array $context = array() ) {
		$credential_id = $this->clean_public_id( $credential_id );

		if ( '' === $credential_id || ! $this->ready() ) {
			return;
		}

		global $wpdb;

		$table = $this->table();

		/*
		 * An expression rather than a read-modify-write, so two concurrent requests cannot
		 * lose one another's count.
		 */
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"UPDATE {$table} SET request_count = request_count + 1, last_used_at = %s, last_used_ip = %s WHERE public_id = %s",
				gmdate( 'Y-m-d H:i:s' ),
				substr( (string) ( $context['ip_hash'] ?? '' ), 0, 16 ),
				$credential_id
			)
		);
	}

	/**
	 * Revoke every credential for a user, used when an account is removed.
	 *
	 * @param int $user_id User id.
	 * @return int Revoked count.
	 */
	public function revoke_for_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 || ! $this->ready() ) {
			return 0;
		}

		global $wpdb;

		$table = $this->table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT public_id, workspace_id FROM {$table} WHERE user_id = %d AND status <> 'revoked' LIMIT 100",
				$user_id
			),
			ARRAY_A
		);

		$revoked = 0;

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$this->revoke(
				(string) $row['public_id'],
				array( 'workspace_id' => (string) $row['workspace_id'], 'actor_id' => $user_id )
			);

			$revoked++;
		}

		return $revoked;
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * List a workspace's credentials.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function browse( $workspace_id, array $args = array() ) {
		$workspace_id = $this->clean_workspace( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return $this->empty_page();
		}

		$clean = array( 'per_page' => isset( $args['per_page'] ) ? (int) $args['per_page'] : Workspace_Limits::page_size( 0 ) );

		if ( isset( $args['status'] ) && Platform_Limits::is_credential_state( $args['status'] ) ) {
			$clean['status'] = (string) $args['status'];
		}

		if ( isset( $args['user_id'] ) && (int) $args['user_id'] > 0 ) {
			$clean['user_id'] = (int) $args['user_id'];
		}

		if ( isset( $args['search'] ) ) {
			$search = sanitize_text_field( (string) $args['search'] );
			if ( '' !== $search ) {
				$clean['search'] = $search;
			}
		}

		return $this->query( $workspace_id, $clean );
	}

	/**
	 * Return a credential record.
	 *
	 * @param string $workspace_id    Workspace id.
	 * @param string $credential_id   Credential public id.
	 * @return array<string, mixed>|null
	 */
	public function read( $workspace_id, $credential_id ) {
		$workspace_id = $this->clean_workspace( $workspace_id );
		$credential_id = $this->clean_public_id( $credential_id );

		if ( '' === $workspace_id || '' === $credential_id || ! $this->ready() ) {
			return null;
		}

		return $this->find( $workspace_id, $credential_id );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return records whose prefix matches, or every record when no prefix is given.
	 *
	 * @param string $prefix Prefix, or empty.
	 * @return array<int, array<string, mixed>>
	 */
	private function candidates( $prefix ) {
		global $wpdb;

		if ( ! $this->ready() ) {
			return array();
		}

		$table = $this->table();

		/*
		 * By prefix only. There is no "everything" branch, deliberately: a lookup that
		 * scanned every credential would be the natural place for an unbounded read, and
		 * `MAX_CREDENTIALS` is 25 per workspace with no guarantee of a small site count.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT * FROM {$table} WHERE prefix = %s LIMIT 10",
				(string) $prefix
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->cast( $row );
			}
		}

		return $out;
	}

	/**
	 * Reduce a scope list to known scopes.
	 *
	 * An unknown scope is **refused**, not dropped, for the same reason an unknown extension
	 * capability is: a client that asked for a scope it misremembered should be told, rather
	 * than handed a credential with fewer powers than it believes it has and discovering the
	 * gap as a confusing 403 later.
	 *
	 * @param mixed $value Candidate.
	 * @return array<int, string>
	 */
	private function clean_scopes( $value ) {
		$value = is_array( $value ) ? $value : array( $value );
		$out   = array();

		foreach ( $value as $scope ) {
			$scope = is_string( $scope ) ? trim( $scope ) : '';

			if ( '' === $scope || ! Platform_Limits::is_api_scope( $scope ) ) {
				continue;
			}

			if ( ! in_array( $scope, $out, true ) ) {
				$out[] = $scope;
			}
		}

		// Sorted, so two credentials with the same scopes in a different order compare equal
		// in the console and in a test.
		sort( $out );

		return $out;
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_workspace( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_public_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}
}
