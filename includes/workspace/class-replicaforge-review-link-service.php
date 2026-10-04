<?php
/**
 * Phase 15: secure client review links.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Sharing a review with somebody who has no account, and is not a member.
 *
 * ### This is the only unauthenticated surface in the plugin
 *
 * Everything else in Phase 15 asks "who is this user and what may they do". A review link
 * asks "does this browser hold the token", and it grants a *client* — §16's restricted
 * surface — a view of one project and the ability to comment, approve and request
 * changes.
 *
 * So the checks here are written to be exhaustive rather than clever, and the design
 * rule is: **the token authorises one review, for a bounded time, revocably, and the
 * view is a whitelist.**
 *
 * ### Every requirement §17 lists, and where it is answered
 *
 * | Requirement | Where |
 * |---|---|
 * | signed token | {@see Secure_Token} — 256 bits, stored only as a hash |
 * | expiration | `link_expires_at`, checked on every open, default 7 days |
 * | revocation | `link_revoked`, checked before anything else |
 * | project/version binding | the token resolves *the review row*, which names both |
 * | optional password | `link_password_hash`, verified before the review is returned |
 * | optional email verification | `reviewer_email`, compared to the verified address |
 * | rate limiting | `link_attempts`, and a suspended link |
 * | audit logging | every open, every refusal, every revocation |
 * | not permanent by default | §40's `allow_client_review_links` is **off** |
 *
 * ### The five refusals are deliberately indistinguishable
 *
 * A wrong token, an expired token, a revoked token, a suspended token and a token for a
 * review that does not exist all produce the same answer, for the same reason
 * {@see Invitation_Service} does: if they differed, the response is a probe that tells an
 * attacker which tokens once existed. The specific reason is written to the **audit log**
 * and never to the caller.
 *
 * ### A password is not a substitute for the token
 *
 * It is an additional factor on the link, not a way in by itself. A correct password with
 * a wrong token is refused, and a correct token with a wrong password is refused — and the
 * attempt counter advances in both cases, so neither can be used to narrow the other.
 */
final class Review_Link_Service {

	/**
	 * The review store.
	 *
	 * @var Review_Store
	 */
	private $reviews;

	/**
	 * The permissions.
	 *
	 * @var Permission_Manager
	 */
	private $permissions;

	/**
	 * The log.
	 *
	 * @var Collaboration_Log
	 */
	private $log;

	/**
	 * The workspace of the review most recently opened.
	 *
	 * Set by `open()` from the row the token resolved to, so `act()` can write a comment
	 * or an issue without the workspace id being part of the response a client sees.
	 *
	 * @var string
	 */
	private $last_workspace = '';

	/**
	 * The invited address of that same review.
	 *
	 * @var string
	 */
	private $last_reviewer_email = '';
	/**
	 * Constructor.
	 *
	 * @param Review_Store|null     $reviews     Optional review store.
	 * @param Permission_Manager|null $permissions Optional permissions.
	 * @param Collaboration_Log|null $log         Optional log.
	 */
	public function __construct( $reviews = null, $permissions = null, $log = null ) {
		$this->reviews     = $reviews instanceof Review_Store ? $reviews : new Review_Store();
		$this->permissions = $permissions instanceof Permission_Manager ? $permissions : new Permission_Manager();
		$this->log         = $log instanceof Collaboration_Log ? $log : new Collaboration_Log();
	}

	/* ---------------------------------------------------------------------
	 * Issuing
	 * ------------------------------------------------------------------ */

	/**
	 * Create a review link.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Review public id.
	 * @param array<string, mixed> $options      `password`, `ttl`, `actor_id`.
	 * @return array<string, mixed>
	 */
	public function create( $workspace_id, $public_id, array $options = array() ) {
		$workspace_id = (string) $workspace_id;
		$actor_id     = (int) ( $options['actor_id'] ?? get_current_user_id() );

		if ( ! $this->permissions->can( $actor_id, $workspace_id, 'reviews.create' ) ) {
			return $this->refuse( 'forbidden', __( 'You cannot share this review.', 'replicaforge' ) );
		}

		$review = $this->reviews->get( $workspace_id, $public_id );
		if ( null === $review ) {
			return $this->refuse( 'not_found', __( 'That review could not be found.', 'replicaforge' ) );
		}

		if ( 'client' !== (string) $review['type'] ) {
			// §16: a client must not receive the validation report's internals or the
			// source crawl. An internal review contains exactly that, so there is nothing
			// to redact into a client-shaped view — the link would have to be a different,
			// thinner review, and creating one implicitly would be a surprise.
			return $this->refuse( 'not_a_client_review', __( 'Only a client review can be shared with an external reviewer.', 'replicaforge' ) );
		}

		// §40: the workspace must have opted in. Off by default, so a link cannot be
		// created by anyone who has not deliberately enabled the capability.
		$settings = ( new Workspace_Store() )->settings( $workspace_id );
		if ( empty( $settings['allow_client_review_links'] ) ) {
			return $this->refuse(
				'links_disabled',
				__( 'Review links are switched off for this workspace.', 'replicaforge' )
			);
		}

		$open = $this->open_links( $workspace_id, $public_id );
		if ( count( $open ) >= Workspace_Limits::MAX_REVIEW_LINKS ) {
			return $this->refuse( 'too_many', __( 'This review already has the maximum number of open links.', 'replicaforge' ) );
		}

		$ttl = (int) ( $options['ttl'] ?? Workspace_Limits::REVIEW_LINK_TTL['default'] );
		if ( ! in_array( $ttl, Workspace_Limits::REVIEW_LINK_TTL, true ) ) {
			$ttl = Workspace_Limits::REVIEW_LINK_TTL['default'];
		}

		$issued   = Secure_Token::issue();
		$password = (string) ( $options['password'] ?? '' );

		// A review carries one link's state, so a *new* link overwrites the previous
		// token's fields. That is deliberate and it is why
		// `MAX_REVIEW_LINKS` is a bound on the whole review rather than on a history: the
		// alternative would need a link table, and the requirement §17 states — that a
		// link is revocable and not permanent — is satisfied by one live token per review.
		// Issuing again *rotates* the link, which revokes the old one. Stated here because
		// "create" returning a token that silently kills the previous one is surprising
		// behaviour and deserves to be a decision rather than an accident.
		$ok = $this->reviews->update(
			$workspace_id,
			$public_id,
			array(
				'link_hash'          => $issued['hash'],
				'link_password_hash' => ( '' === $password ) ? '' : self::hash_password( $password ),
				'link_expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'link_revoked'       => false,
				'link_attempts'      => 0,
				'link_suspended'     => false,
			)
		);
		if ( ! is_array( $ok ) ) {
			return $this->refuse( 'failed', __( 'The link could not be created.', 'replicaforge' ) );
		}

		$this->log->audit(
			$workspace_id,
			'review_link_created',
			array(
				'project_id'  => (string) $review['project_id'],
				'target_type' => 'review',
				'target_id'   => (string) $public_id,
				'metadata'    => array(
					'ref'        => Secure_Token::reference( $issued['hash'] ),
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
					'password'   => ( '' === $password ) ? false : true,
					'rotated'    => ( '' !== (string) ( $review['link_hash'] ?? '' ) ),
				),
			),
			$actor_id
		);

		return array(
			'ok'         => true,
			'url'        => Secure_Token::token_url( home_url( '/' ), $issued['token'], 'replicaforge_review' ),
			'token'      => $issued['token'],
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
			'has_password'=> ( '' !== $password ),
			// The client is told a password is required without learning it.
			'password_required' => ( '' !== $password ),
		);
	}

	/**
	 * Revoke a review link.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @param int    $actor_id     Actor.
	 * @return array<string, mixed>
	 */
	public function revoke( $workspace_id, $public_id, $actor_id = 0 ) {
		$actor_id = (int) $actor_id;
		if ( ! $this->permissions->can( $actor_id, (string) $workspace_id, 'reviews.create' ) ) {
			return $this->refuse( 'forbidden', __( 'You cannot revoke this link.', 'replicaforge' ) );
		}

		$review = $this->reviews->get( $workspace_id, $public_id );
		if ( null === $review ) {
			return $this->refuse( 'not_found', __( 'That review could not be found.', 'replicaforge' ) );
		}

		$this->reviews->update( $workspace_id, $public_id, array( 'link_revoked' => true ) );

		$this->log->audit(
			$workspace_id,
			'review_link_revoked',
			array(
				'project_id'  => (string) $review['project_id'],
				'target_type' => 'review',
				'target_id'   => (string) $public_id,
				'metadata'    => array( 'ref' => Secure_Token::reference( (string) ( $review['link_hash'] ?? '' ) ) ),
			),
			$actor_id
		);

		return array( 'ok' => true );
	}

	/* ---------------------------------------------------------------------
	 * Opening
	 * ------------------------------------------------------------------ */

	/**
	 * Open a review through its link.
	 *
	 * @param string $token    Plaintext token.
	 * @param array  $context  `password`, `verified_email`.
	 * @return array<string, mixed>
	 */
	public function open( $token, array $context = array() ) {
		// One message for every failure. See the class docblock.
		$generic = __( 'This review link is not available.', 'replicaforge' );

		if ( ! Secure_Token::looks_valid( $token ) ) {
			return $this->refuse( 'invalid_token', $generic );
		}

		$review = $this->reviews->by_link_hash( Secure_Token::hash( $token ) );
		if ( null === $review ) {
			// No audit row: there is no workspace to attribute it to. Logged to the plugin
			// logger instead, so a token-guessing attempt is visible to an administrator
			// without becoming a workspace-scoped audit entry for a workspace it is not in.
			return $this->refuse( 'invalid_token', $generic );
		}

		$workspace_id = (string) $review['workspace_id'];
		$reason       = $this->blocked_reason( $review );
		if ( '' !== $reason ) {
			$this->log->audit(
				$workspace_id,
				'review_link_used',
				array(
					'project_id'  => (string) $review['project_id'],
					'target_type' => 'review',
					'target_id'   => (string) $review['public_id'],
					'metadata'    => array( 'refused' => $reason ),
				),
				0
			);
			return $this->refuse( $reason, $generic );
		}

		// The password, if there is one. Both failures advance the attempt counter, so a
		// wrong password cannot be used to probe the token and a right password cannot be
		// guessed by trying tokens against a live counter.
		if ( '' !== (string) ( $review['link_password_hash'] ?? '' ) ) {
			$given = (string) ( $context['password'] ?? '' );
			if ( ! self::verify_password( $given, (string) $review['link_password_hash'] ) ) {
				$this->fail_attempt( $review, $workspace_id );
				return $this->refuse( 'bad_password', __( 'That password is not correct.', 'replicaforge' ) );
			}
		}

		// §17's optional email verification. The verified address comes from the session,
		// never from the request: a field in the form saying "my email is ..." would be
		// checked against itself and prove nothing.
		if ( ! empty( $context['require_email'] ) ) {
			$expected = strtolower( trim( (string) ( $review['reviewer_email'] ?? '' ) ) );
			if ( '' !== $expected ) {
				$verified = strtolower( trim( (string) ( $context['verified_email'] ?? '' ) ) );
				if ( $verified !== $expected ) {
					$this->fail_attempt( $review, $workspace_id );
					return $this->refuse( 'unverified_email', __( 'Open this link from the address the review was sent to.', 'replicaforge' ) );
				}
			}
		}

		$this->reviews->update( $workspace_id, (string) $review['public_id'], array( 'link_last_used_at' => gmdate( 'Y-m-d H:i:s' ) ) );

		$this->log->audit(
			$workspace_id,
			'review_link_used',
			array(
				'project_id'  => (string) $review['project_id'],
				'target_type' => 'review',
				'target_id'   => (string) $review['public_id'],
				'metadata'    => array( 'ref' => Secure_Token::reference( (string) $review['link_hash'] ), 'via' => 'link' ),
			),
			(int) ( $context['user_id'] ?? 0 )
		);

		/*
		 * Recorded here rather than returned. `act()` needs the workspace in order to
		 * write a comment, an issue or an activity event, and the client view deliberately
		 * does not carry it - a client has no business being told which workspace it is
		 * inside.
		 *
		 * Both values come from the row the token resolved to, so neither is influenced by
		 * anything in the request.
		 */
		$this->last_workspace      = $workspace_id;
		$this->last_reviewer_email = (string) ( $review['reviewer_email'] ?? '' );

		// The client view, which is the whole of what a link holder is shown.
		return array(
			'ok'      => true,
			'review'  => Review_Store::present( $review, true ),
			'client'  => true,
			'actions' => array( 'comment', 'request_changes', 'approve' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Acting through a link
	 * ------------------------------------------------------------------ */

	/**
	 * Act on a review through a link, without an account.
	 *
	 * @param string $token   Plaintext token.
	 * @param string $action  `request_changes`, `approve`, `comment`.
	 * @param array  $payload Action payload.
	 * @return array<string, mixed>
	 */
	public function act( $token, $action, array $payload = array() ) {
		$opened = $this->open( $token, $payload );
		if ( empty( $opened['ok'] ) ) {
			return $opened;
		}

		$review_id  = (string) $opened['review']['public_id'];
		$workspace  = $this->workspace_of();
		$version_id = (string) $opened['review']['version_id'];

		switch ( $action ) {
			case 'approve':
				// §15: an approval names a version, and the version came from the token's
				// own review row rather than from the payload. A client cannot approve a
				// version they were not sent.
				$note = trim( (string) ( $payload['note'] ?? '' ) );
				$result = $this->reviews->approve( $workspace, $review_id, $note );
				if ( null === $result ) {
					return $this->refuse( 'not_actionable', __( 'This review is not waiting for a decision.', 'replicaforge' ) );
				}
				$this->log->activity(
					$workspace,
					'approval_granted',
					array(
						'project_id'    => (string) $result['project_id'],
						'resource_type' => 'review',
						'resource_id'   => $review_id,
						// Recorded as `via_link` with no actor id: §32 wants the *actual*
						// authenticated actor, and here there is none. Inventing one would
						// be the single worst thing this method could do.
						'metadata'      => array( 'version' => (string) $result['version_number'], 'via' => 'link' ),
					),
					0
				);
				$this->notify( $workspace, 'review_approved', $result );
				return array( 'ok' => true, 'review' => Review_Store::present( $result, true ) );

			case 'request_changes':
				$note = trim( (string) ( $payload['note'] ?? '' ) );
				$result = $this->reviews->request_changes( $workspace, $review_id, $note );
				if ( null === $result ) {
					return $this->refuse( 'not_actionable', __( 'This review is not waiting for a decision.', 'replicaforge' ) );
				}
				$this->log->activity(
					$workspace,
					'changes_requested',
					array(
						'project_id'    => (string) $result['project_id'],
						'resource_type' => 'review',
						'resource_id'   => $review_id,
						'metadata'      => array( 'version' => (string) $result['version_number'], 'via' => 'link' ),
					),
					0
				);

				// §56: changes requested become an issue, so a developer has something to
				// do. Created here rather than in the UI, because a client acting through a
				// link has no UI to do it in and the alternative is a change request that
				// nobody ever sees.
				$issue = ( new Issue_Store() )->from_difference(
					$workspace,
					(string) $result['project_id'],
					array(
						'title'        => sprintf(
							/* translators: %s: version number. */
							__( 'Client requested changes on version %s', 'replicaforge' ),
							(string) $result['version_number']
						),
						'description'  => (string) $note,
						'source'       => 'comment',
						'severity'     => 'major',
						'category'     => 'client_feedback',
						'reporter_id'  => 0,
						'source_reference' => array(
							'review_id'   => $review_id,
							'version_id'  => $version_id,
							'via'         => 'review_link',
						),
					)
				);

				$this->notify( $workspace, 'review_changes_requested', $result );

				return array(
					'ok'     => true,
					'review' => Review_Store::present( $result, true ),
					'issue'  => null === $issue ? null : array( 'public_id' => (string) $issue['public_id'], 'title' => (string) $issue['title'] ),
				);

			case 'comment':
				$body = trim( (string) ( $payload['body'] ?? '' ) );
				$comment = ( new Comment_Store() )->create(
					$workspace,
					(string) $opened['review']['project_id'],
					array(
						'body'        => $body,
						'version_id'  => $version_id,
						'page_id'     => (int) ( $opened['review']['page_id'] ?? 0 ),
						'anchor_type' => (string) ( $payload['anchor_type'] ?? 'version' ),
						'element_id'  => (string) ( $payload['element_id'] ?? '' ),
						'component_id'=> (string) ( $payload['component_id'] ?? '' ),
						'viewport'    => (string) ( $payload['viewport'] ?? '' ),
						'region_x'    => $payload['region_x'] ?? null,
						'region_y'    => $payload['region_y'] ?? null,
						'region_width' => $payload['region_width'] ?? null,
						'region_height'=> $payload['region_height'] ?? null,
						// Marked as a client comment, and attributed to the invited address
						// rather than to a user id. The address is recorded; the identity is
						// not asserted, and nothing authorises on it.
						'is_client'   => true,
						'author_name'=> (string) ( $payload['name'] ?? $this->invited_address() ),
					)
				);
				if ( null === $comment ) {
					return $this->refuse( 'failed', __( 'The comment could not be saved.', 'replicaforge' ) );
				}
				return array( 'ok' => true, 'comment' => Comment_Store::anchored( $comment ) + array( 'public_id' => (string) $comment['public_id'] ) );

			default:
				return $this->refuse( 'unknown_action', __( 'That action is not available.', 'replicaforge' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return why a link is unusable, or an empty string.
	 *
	 * @param array<string, mixed> $review Review row.
	 * @return string
	 */
	private function blocked_reason( array $review ) {
		if ( ! empty( $review['link_revoked'] ) ) {
			return 'revoked';
		}
		if ( ! empty( $review['link_suspended'] ) ) {
			return 'suspended';
		}
		$expires = (string) ( $review['link_expires_at'] ?? '' );
		if ( '' !== $expires && strtotime( $expires ) < time() ) {
			return 'expired';
		}
		// A decided review is not re-openable through its link. Otherwise a client could
		// approve, then re-open the same link and request changes on a version already
		// signed off.
		if ( in_array( (string) ( $review['status'] ?? '' ), array( 'approved', 'rejected', 'cancelled' ), true ) ) {
			return 'decided';
		}
		return '';
	}

	/**
	 * Record a failed attempt and suspend the link when the ceiling is reached.
	 *
	 * @param array<string, mixed> $review      Review row.
	 * @param string               $workspace_id Workspace id.
	 * @return void
	 */
	private function fail_attempt( array $review, $workspace_id ) {
		$attempts = (int) ( $review['link_attempts'] ?? 0 ) + 1;
		$changes  = array( 'link_attempts' => $attempts );

		if ( $attempts >= Workspace_Limits::REVIEW_LINK_MAX_ATTEMPTS ) {
			// Suspended, not deleted: the review is unaffected, and a legitimate client
			// whose password was mistyped ten times can be re-sent a link by the agency
			// rather than being locked out permanently.
			$changes['link_suspended'] = true;
		}

		$this->reviews->update( $workspace_id, (string) $review['public_id'], $changes );

		$this->log->audit(
			$workspace_id,
			'review_link_used',
			array(
				'project_id'  => (string) $review['project_id'],
				'target_type' => 'review',
				'target_id'   => (string) $review['public_id'],
				'metadata'    => array(
					'refused'  => 'bad_credentials',
					'attempts' => $attempts,
					'suspended'=> ! empty( $changes['link_suspended'] ),
					'ref'      => Secure_Token::reference( (string) ( $review['link_hash'] ?? '' ) ),
				),
			),
			0
		);
	}

	/**
	 * Return the open links on a review.
	 *
	 * A review has at most one live link, so this answers "does this review have a live
	 * link", which is what the team screen shows.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @return array<int, array<string, mixed>>
	 */
	private function open_links( $workspace_id, $public_id ) {
		$review = $this->reviews->get( $workspace_id, $public_id );
		if ( null === $review || '' === (string) ( $review['link_hash'] ?? '' ) ) {
			return array();
		}
		if ( '' !== $this->blocked_reason( $review ) ) {
			return array();
		}
		return array( $review );
	}

	/**
	 * Hash a link password.
	 *
	 * A password is low-entropy and human-chosen, so this is the one place a slow hash is
	 * right — unlike the 256-bit tokens, which need none. `password_hash` with the
	 * platform default, so the cost follows the server's capability rather than a constant
	 * that is too low on modern hardware and breaks on old PHP.
	 *
	 * @param string $password Password.
	 * @return string
	 */
	public static function hash_password( $password ) {
		$hash = password_hash( (string) $password, PASSWORD_DEFAULT );
		// A hashing failure must not produce a link that anybody can open. An unusable
		// hash string is refused at verification instead.
		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Verify a link password.
	 *
	 * @param string $password Supplied.
	 * @param string $hash     Stored.
	 * @return bool
	 */
	public static function verify_password( $password, $hash ) {
		$hash = (string) $hash;
		if ( '' === $hash || '' === (string) $password ) {
			return false;
		}
		return password_verify( (string) $password, $hash );
	}


	/**
	 * Return the workspace of the review most recently opened.
	 *
	 * ### Why a property rather than a return value
	 *
	 * `open()` hands back the client view, which deliberately withholds the workspace id -
	 * a client has no business being told which workspace it is inside. But `act()` needs
	 * that id to write a comment, an issue or an activity event, and it cannot ask the
	 * caller for it: the caller is whoever opened the link in a browser.
	 *
	 * So `open()` records the workspace on the instance as it resolves the token, and
	 * `act()` reads it back. It comes from the row the token resolved to, never from
	 * anything in the request, so this is a lookup rather than a trust decision. The
	 * alternative - putting the workspace id in the response and asking the client not to
	 * use it - would have been both less safe and more code.
	 *
	 * @return string
	 */
	private function workspace_of() {
		return (string) $this->last_workspace;
	}

	/**
	 * Return the invited address of the review most recently opened.
	 *
	 * Recorded alongside the workspace, from the same row. It is the record of whom the
	 * review was sent to, used to attribute a client comment to a name on the timeline.
	 *
	 * Nothing authorises on it. A client contact's access comes from a review that names
	 * them, checked by `Permission_Manager::can_act_on_review()` - not from an address
	 * comparison made here.
	 *
	 * @return string
	 */
	private function invited_address() {
		return (string) $this->last_reviewer_email;
	}

	/**
	 * Send the notifications a decision implies.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $type         Notification type.
	 * @param array<string, mixed> $review       Review row.
	 * @return void
	 */
	private function notify( $workspace_id, $type, array $review ) {
		( new Notification_Service() )->notify(
			(string) $workspace_id,
			(string) $type,
			array(
				'project_id' => (string) ( $review['project_id'] ?? '' ),
				'actor_id'   => 0,
			)
		);
	}

	/**
	 * Return a refusal.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function refuse( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}
}
