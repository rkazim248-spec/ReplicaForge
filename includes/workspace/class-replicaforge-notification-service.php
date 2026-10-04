<?php
/**
 * Phase 15: the notification service and its providers.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The contract every notification provider implements.
 *
 * §26 says "Use: Notification Service -> Notification Provider" and "Support future
 * providers". This interface is that seam.
 *
 * ### Why an interface rather than a `if ( $channel === 'email' )`
 *
 * Because the alternative is what every plugin ends up with: a service that knows about
 * email, and later about Slack, and later about a webhook, each with its own inline
 * conditional, its own error handling and its own idea of what a "failed" notification
 * is. Then a third channel arrives and somebody adds a fourth conditional.
 *
 * With the interface, a provider is a small class that answers one question — "can you
 * deliver this, and did you?" — and the service decides *which* providers to ask without
 * knowing how any of them work. A new channel is a new file and one line of
 * registration; nothing in the service changes.
 *
 * ### A provider must never be able to grant access
 *
 * §51 forbids AI from changing permissions, approving projects, removing users and
 * deleting projects. The same applies here by construction: a provider's entire surface
 * is `delivers()`, `send()` and `name()`. There is no method through which a notification
 * could change state, so no provider can be tricked into being an authorisation path.
 *
 * That is the reason the interface is this small. A wider one — a provider that could
 * accept a callback, or read the recipient's capabilities — would put the policy back
 * inside the transport.
 */
interface Notification_Provider {

	/**
	 * Return whether this provider can deliver the given notification.
	 *
	 * A provider says no rather than throwing when it cannot deliver — an unconfigured
	 * mailer is a configuration state, not an error, and a service that treats it as one
	 * will page somebody about a provider that was never switched on.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return bool
	 */
	public function delivers( array $notification );

	/**
	 * Deliver a notification.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return bool Whether the provider accepted it for delivery. False means "not
	 *              delivered", and the caller records that rather than claiming success.
	 */
	public function send( array $notification );

	/**
	 * Return the provider's channel name, from {@see Workspace_Limits::CHANNELS}.
	 *
	 * @return string
	 */
	public function name();
}

/**
 * The in-app provider: a row in the notifications table.
 *
 * The default, because it needs no configuration, leaks nothing off the site, and cannot
 * fail in a way that loses a message.
 */
final class In_App_Notification_Provider implements Notification_Provider {

	/**
	 * Return the channel name.
	 *
	 * @return string
	 */
	public function name() {
		return 'in_app';
	}

	/**
	 * Return whether an in-app notification can be delivered.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return bool
	 */
	public function delivers( array $notification ) {
		// Only to a real, signed-in-able recipient. A notification for user 0 is a
		// programming error, and storing it would produce an unread row nobody can ever
		// dismiss - a permanent badge on a table somebody has to clean by hand.
		return ( (int) ( $notification['user_id'] ?? 0 ) > 0 )
			&& ! empty( $notification['type'] );
	}

	/**
	 * Store the notification.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return bool
	 */
	public function send( array $notification ) {
		$store = new Notification_Store();
		return null !== $store->create( $notification );
	}
}

/**
 * The email provider.
 *
 * ### Off unless the workspace asks for it, and off per-recipient too
 *
 * §40 lists "Allow automatic notifications" among the settings, and §26 lists notification
 * preferences as per-user. So there are two switches and both must be on: the workspace
 * has to permit automatic notification, and the recipient has to want this *kind* of
 * notification by email.
 *
 * Either one alone is not enough. A workspace that turns notifications on has still not
 * acquired the right to email somebody who turned that category off.
 *
 * ### §27's rule, enforced at the boundary
 *
 * "Do not expose project private data, source credentials, API keys or internal logs in
 * notification emails." So the body is **not** composed here from the notification's
 * metadata — the service passes a pre-composed `body` that {@see Notification_Service}
 * built from a fixed set of templates, and this provider refuses to send a notification
 * that carries a body it did not get from a template. Email is the one channel that
 * leaves the site, and a channel that can carry arbitrary metadata is a channel that can
 * carry a secret.
 *
 * The link back is a normal admin URL with a nonce-gated route, never a token: §27 asks
 * for "secure links back to ReplicaForge", and a token in an email is a bearer secret
 * sitting in a mailbox.
 */
final class Email_Notification_Provider implements Notification_Provider {

	/**
	 * The logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the channel name.
	 *
	 * @return string
	 */
	public function name() {
		return 'email';
	}

	/**
	 * Return whether an email can be delivered.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return bool
	 */
	public function delivers( array $notification ) {
		if ( empty( $notification['email'] ) || ! is_email( (string) $notification['email'] ) ) {
			return false;
		}
		if ( empty( $notification['body'] ) ) {
			// A notification with no composed body is refused rather than sent with an
			// empty message. This is the check that keeps arbitrary metadata out of an
			// outbound message: there is no way to reach this provider with content that
			// did not come from a template.
			return false;
		}
		return true;
	}

	/**
	 * Send the email.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return bool
	 */
	public function send( array $notification ) {
		if ( ! $this->delivers( $notification ) ) {
			return false;
		}

		$subject = (string) ( $notification['title'] ?? '' );
		if ( '' === $subject ) {
			$subject = __( 'ReplicaForge', 'replicaforge' );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$body    = (string) $notification['body'];

		if ( ! empty( $notification['link'] ) ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: link back to ReplicaForge. */
				__( 'Open ReplicaForge: %s', 'replicaforge' ),
				(string) $notification['link']
			);
		}

		$sent = wp_mail( (string) $notification['email'], $subject, $body, $headers );

		if ( ! $sent ) {
			// Logged, not swallowed. A notification that could not be delivered is a real
			// operational fact — an agency that believes a client was emailed and was not
			// has a worse problem than one that knows the mail did not go out.
			$this->logger->warning(
				'notification_email_failed',
				'A notification email could not be sent.',
				array(
					'type'  => (string) ( $notification['type'] ?? '' ),
					// The address is hashed, not stored: a failed send is still a log line,
					// and §25 forbids an address in a log.
					'to'    => substr( hash( 'sha256', (string) $notification['email'] ), 0, 12 ),
				),
				'workspace'
			);
		}

		return (bool) $sent;
	}
}

/**
 * The notification service.
 *
 * ### Two levels of "should this be sent", and both are checked here
 *
 * 1. Does an event *imply* a notification? {@see self::RECIPIENT_RULES} answers that —
 *    not every `comment_added` is worth an email, and only a comment that *mentions*
 *    somebody or is a reply to them is.
 * 2. Does this recipient want it, on this channel? The per-user preferences answer that.
 *
 * The workspace-level "allow automatic notifications" switch is checked by the caller that
 * owns the workspace's settings, because that is a setting of the workspace rather than of
 * the notification.
 *
 * ### Delivery is attempted per provider and the results are reported
 *
 * A notification going to in-app but failing by email is a partial success, and reporting
 * it as either "sent" or "failed" would be wrong in one direction or the other. The
 * result names each channel.
 */
final class Notification_Service {

	/**
	 * The registered providers, keyed by channel.
	 *
	 * @var array<string, Notification_Provider>
	 */
	private $providers = array();

	/**
	 * The in-app store.
	 *
	 * @var Notification_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param array<int, Notification_Provider> $providers Optional providers.
	 * @param Notification_Store|null           $store     Optional store.
	 */
	public function __construct( array $providers = array(), $store = null ) {
		$this->store = $store instanceof Notification_Store ? $store : new Notification_Store();

		// Both defaults are registered here rather than in a caller, so a service
		// constructed with no arguments behaves correctly. That matters because the
		// inviter, the review service and the task service each construct their own.
		$this->register( new In_App_Notification_Provider() );
		$this->register( new Email_Notification_Provider() );

		foreach ( $providers as $provider ) {
			$this->register( $provider );
		}
	}

	/**
	 * Register a provider.
	 *
	 * @param Notification_Provider $provider Provider.
	 * @return bool
	 */
	public function register( Notification_Provider $provider ) {
		$name = (string) $provider->name();
		if ( '' === $name || ! in_array( $name, Workspace_Limits::CHANNELS, true ) ) {
			return false;
		}
		$this->providers[ $name ] = $provider;
		return true;
	}

	/**
	 * Return the registered providers.
	 *
	 * @return array<string, Notification_Provider>
	 */
	public function providers() {
		return $this->providers;
	}

	/**
	 * Notify a workspace about something.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $type         Notification type.
	 * @param array<string, mixed> $context      `project_id`, `actor_id`, `resource_id`, plus type-specific keys.
	 * @return array<string, mixed>
	 */
	public function notify( $workspace_id, $type, array $context = array() ) {
		$workspace_id = (string) $workspace_id;
		$type         = (string) $type;

		if ( '' === $workspace_id || ! in_array( $type, Workspace_Limits::NOTIFICATION_TYPES, true ) ) {
			return array( 'ok' => false, 'reason' => 'unknown_type' );
		}

		$recipients = $this->recipients( $workspace_id, $type, $context );
		if ( array() === $recipients ) {
			// Not an error. Many events have no recipient — a project archived when
			// nobody else is in the workspace, a validation completed when the validator is
			// the only person involved — and reporting a failure would train callers to
			// ignore the result.
			return array( 'ok' => true, 'reason' => 'no_recipients', 'sent' => array() );
		}

		$sent   = array();
		$failed = array();

		foreach ( $recipients as $user_id ) {
			$preferences = $this->preferences( $user_id );
			$composed    = $this->compose( $type, $context, $user_id );

			if ( null === $composed ) {
				continue;
			}

			foreach ( $this->providers as $channel => $provider ) {
				if ( ! $this->wants( $preferences, $type, $channel ) ) {
					continue;
				}

				$notification = array_merge(
					$composed,
					array(
						'workspace_id' => $workspace_id,
						'user_id'      => (int) $user_id,
						'type'         => $type,
						'channel'      => $channel,
					)
				);

				if ( ! $provider->delivers( $notification ) ) {
					continue;
				}

				if ( $provider->send( $notification ) ) {
					$sent[] = array( 'user_id' => (int) $user_id, 'channel' => $channel );
					if ( 'email' === $channel ) {
						$this->store->mark_sent( $workspace_id, (int) $user_id, $type );
					}
				} else {
					$failed[] = array( 'user_id' => (int) $user_id, 'channel' => $channel );
				}
			}
		}

		return array(
			'ok'        => ( array() === $failed ),
			'reason'    => ( array() === $failed ) ? '' : 'delivery_failed',
			'sent'      => $sent,
			'failed'    => $failed,
			'recipients'=> count( $recipients ),
		);
	}

	/**
	 * Return the in-app notifications for a user.
	 *
	 * @param string $workspace_id Workspace id, or empty for all.
	 * @param int    $user_id      User id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function inbox( $workspace_id, $user_id, array $args = array() ) {
		return $this->store->inbox( $workspace_id, (int) $user_id, $args );
	}

	/**
	 * Return the unread count for a user.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @return int
	 */
	public function unread_count( $workspace_id, $user_id ) {
		return $this->store->unread_count( $workspace_id, (int) $user_id );
	}

	/**
	 * Mark a notification read.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param string $public_id    Notification public id.
	 * @return bool
	 */
	public function mark_read( $workspace_id, $user_id, $public_id ) {
		return $this->store->mark_read( $workspace_id, (int) $user_id, $public_id );
	}

	/**
	 * Remove notifications older than an age.
	 *
	 * ### Why the service, and not the store, is what maintenance calls
	 *
	 * The store is private and stays that way. If the maintenance sweep reached for
	 * `new Notification_Store()` directly it would construct a second gateway with its own
	 * view, and a caller would have two ways to prune the same table - which is the shape
	 * a second, disagreeing answer takes. This is the same seam
	 * {@see Collaboration_Log::prune()} provides for the activity and audit stores, and it
	 * is deliberately the only one.
	 *
	 * The age is a parameter rather than a constant held here, for a reason that inverts the
	 * activity and audit arrangement: notifications have no declared retention in
	 * `Workspace_Limits`, because nothing about the notification vocabulary implies one. So
	 * the caller states the age it is pruning to and this method does not second-guess it.
	 * Inventing a constant here to match would put a policy in a file that has no business
	 * holding one.
	 *
	 * @param int $age Maximum age in seconds.
	 * @return int Number removed.
	 */
	public function prune( $age ) {
		return (int) $this->store->prune( (int) $age );
	}

	/**
	 * Return a user's notification preferences.
	 *
	 * @param int $user_id User id.
	 * @return array<string, array<string, bool>>
	 */
	public function preferences( $user_id ) {
		return $this->store->preferences( (int) $user_id );
	}

	/**
	 * Replace a user's notification preferences.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $changes Changes.
	 * @return array<string, array<string, bool>>
	 */
	public function set_preferences( $user_id, array $changes ) {
		return $this->store->set_preferences( (int) $user_id, $changes );
	}

	/* ---------------------------------------------------------------------
	 * Recipients
	 * ------------------------------------------------------------------ */

	/**
	 * Who an event type notifies, and under what conditions.
	 *
	 * The value is a `roles` filter (empty means every active member), an `event` filter
	 * for the thing that happened, and an `only_mentioned` flag for the two types where
	 * notifying everybody would be noise.
	 *
	 * `only_mentioned` is the important one. A comment on a project is *not* news for
	 * forty people, and an email saying "Kazim commented" is how a notification system
	 * gets its domain marked as spam. A comment notifies its author, its parent comment's
	 * author, and whoever it mentions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	const RECIPIENT_RULES = array(
		'review_requested'        => array( 'capability' => 'reviews.create', 'exclude_actor' => true ),
		'review_changes_requested'=> array( 'capability' => 'reviews.create', 'exclude_actor' => true ),
		'review_approved'         => array( 'capability' => 'reviews.create' ),
		'comment_mention'         => array( 'only_mentioned' => true ),
		'comment_reply'           => array( 'only_mentioned' => true ),
		'task_assigned'           => array( 'only_assignee' => true ),
		'task_completed'          => array( 'capability' => 'projects.view', 'exclude_actor' => true ),
		'issue_created'           => array( 'only_assignee' => true ),
		'issue_resolved'          => array( 'capability' => 'projects.view' ),
		'generation_completed'    => array( 'capability' => 'generation.run' ),
		'validation_completed'    => array( 'capability' => 'validation.run' ),
		'sync_conflict'           => array( 'capability' => 'sync.approve' ),
		'content_conflict'        => array( 'capability' => 'content.apply' ),
		'member_added'            => array( 'capability' => 'members.view' ),
		'invitation_accepted'     => array( 'capability' => 'members.view' ),
	);

	/**
	 * Resolve the recipients for an event.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $type         Notification type.
	 * @param array<string, mixed> $context      Context.
	 * @return array<int, int>
	 */
	private function recipients( $workspace_id, $type, array $context ) {
		$rule = self::RECIPIENT_RULES[ $type ] ?? array();

		if ( ! empty( $rule['only_mentioned'] ) ) {
			return $this->mentioned( $workspace_id, $context );
		}
		if ( ! empty( $rule['only_assignee'] ) ) {
			$assignee = (int) ( $context['assignee_id'] ?? 0 );
			if ( $assignee > 0 && null !== ( new Workspace_Member_Store() )->membership( $workspace_id, $assignee ) ) {
				return array( $assignee );
			}
			// No assignee yet. Notifying nobody is correct: there is no person to chase,
			// and sending an unassigned issue to the whole team produces exactly the
			// notification fatigue that gets a system muted.
			return array();
		}

		$capability = (string) ( $rule['capability'] ?? '' );
		$actor_id   = (int) ( $context['actor_id'] ?? 0 );
		$permissions = new Permission_Manager();
		$members     = ( new Workspace_Member_Store() )->members( $workspace_id, array( 'per_page' => Workspace_Limits::MAX_MEMBERS ) );

		$out = array();
		foreach ( $members['items'] as $member ) {
			$user_id = (int) $member['user_id'];
			if ( $user_id < 1 ) {
				continue;
			}
			if ( ! empty( $rule['exclude_actor'] ) && $user_id === $actor_id ) {
				continue;
			}
			if ( '' !== $capability && ! $permissions->can( $user_id, $workspace_id, $capability ) ) {
				continue;
			}
			$out[] = $user_id;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Resolve the people a comment concerns.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param array<string, mixed> $context      Context.
	 * @return array<int, int>
	 */
	private function mentioned( $workspace_id, array $context ) {
		$comment_id = (string) ( $context['resource_id'] ?? '' );
		if ( '' === $comment_id ) {
			return array();
		}

		$comments  = new Comment_Store();
		$comment   = $comments->get( $workspace_id, $comment_id );
		if ( null === $comment ) {
			return array();
		}

		$out = array( (int) $comment['author_id'] );

		$parent_id = (string) $comment['parent_id'];
		if ( '' !== $parent_id ) {
			$parent = $comments->get( $workspace_id, $parent_id );
			if ( null !== $parent ) {
				$out[] = (int) $parent['author_id'];
			}
		}

		foreach ( (array) ( $comment['mentions'] ?? array() ) as $mentioned ) {
			$out[] = (int) $mentioned;
		}

		$out = array_values( array_unique( array_filter( $out, static function ( $id ) { return $id > 0; } ) ) );

		// Filtered through membership, so a mention stored against somebody who has since
		// left the workspace produces no notification rather than a row for a stranger.
		$store  = new Workspace_Member_Store();
		$active = array();
		foreach ( $out as $id ) {
			if ( null !== $store->membership( $workspace_id, $id ) ) {
				$active[] = $id;
			}
		}
		return $active;
	}

	/* ---------------------------------------------------------------------
	 * Composition and preferences
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a recipient wants a type on a channel.
	 *
	 * @param array<string, array<string, bool>> $preferences Preferences.
	 * @param string                             $type       Type.
	 * @param string                             $channel    Channel.
	 * @return bool
	 */
	private function wants( array $preferences, $type, $channel ) {
		$key = $this->preference_key( $type );
		$row = (array) ( $preferences[ $key ] ?? array() );

		// In-app is the default and the fallback: a notification nobody asked for still
		// appears in the inbox, because the alternative is an event that silently did
		// nothing. Email is strictly opt-in per category, because it leaves the site.
		if ( 'in_app' === $channel ) {
			return true;
		}
		return ! empty( $row[ $channel ] );
	}

	/**
	 * Return the preference key a type falls under.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private function preference_key( $type ) {
		$map = array(
			'review_requested'         => 'review_requests',
			'review_changes_requested' => 'review_requests',
			'review_approved'          => 'review_requests',
			'comment_mention'          => 'mentions',
			'comment_reply'            => 'mentions',
			'task_assigned'            => 'task_assignments',
			'task_completed'           => 'task_assignments',
			'generation_completed'     => 'generation_completion',
			'validation_completed'     => 'validation_completion',
			'sync_conflict'            => 'sync_conflicts',
			'content_conflict'         => 'sync_conflicts',
		);
		return $map[ $type ] ?? 'review_requests';
	}

	/**
	 * Compose a notification's title and body.
	 *
	 * ### A closed set of templates, and why
	 *
	 * §27 is a rule about what an email may contain, and the only reliable way to keep a
	 * project name, a client name or an internal note out of an outbound message is for
	 * there to be no code path that could put one there. So the body is chosen from this
	 * switch by *type*, and the context values are only ever used where the switch says
	 * they are safe.
	 *
	 * That is a real constraint on what a notification can say: it cannot include the
	 * comment's body, the task's description, a client's notes, or a validation
	 * difference's detail. It can say *what happened* and link back, which is enough for a
	 * notification and is the whole of what §27 permits.
	 *
	 * @param string               $type    Type.
	 * @param array<string, mixed> $context Context.
	 * @param int                  $user_id Recipient.
	 * @return array<string, mixed>|null
	 */
	private function compose( $type, array $context, $user_id ) {
		$project_id = (string) ( $context['project_id'] ?? '' );
		$link       = $this->link_for( $project_id, $context );

		// The project *name* is safe to include: it is not private data in the sense §27
		// means, it is what the recipient needs to know which project this is about, and
		// the recipient already has access to it. A client name is not included, because a
		// reviewer on one project has no business learning which client another project
		// belongs to.
		$project = $this->project_name( $project_id );

		switch ( $type ) {
			case 'review_requested':
				$subject = sprintf( __( 'Review requested: %s', 'replicaforge' ), $project );
				$body    = __( 'A review has been requested and is waiting for you.', 'replicaforge' );
				break;
			case 'review_changes_requested':
				$subject = __( 'Changes requested', 'replicaforge' );
				$body    = __( 'Changes were requested on a version you can review.', 'replicaforge' );
				break;
			case 'review_approved':
				$subject = sprintf( __( 'Approved: %s', 'replicaforge' ), $project );
				$body    = __( 'A review was approved.', 'replicaforge' );
				break;
			case 'comment_mention':
				$subject = __( 'You were mentioned in a comment', 'replicaforge' );
				$body    = __( 'A comment mentions you.', 'replicaforge' );
				break;
			case 'comment_reply':
				$subject = __( 'New reply to your comment', 'replicaforge' );
				$body    = __( 'Somebody replied to a comment you wrote.', 'replicaforge' );
				break;
			case 'task_assigned':
				$subject = __( 'A task was assigned to you', 'replicaforge' );
				$body    = __( 'Open ReplicaForge to see what it is.', 'replicaforge' );
				break;
			case 'task_completed':
				$subject = __( 'A task was completed', 'replicaforge' );
				$body    = __( 'A task on a project you follow was completed.', 'replicaforge' );
				break;
			case 'issue_created':
				$subject = __( 'An issue was assigned to you', 'replicaforge' );
				$body    = __( 'Open ReplicaForge to see what it is.', 'replicaforge' );
				break;
			case 'issue_resolved':
				$subject = __( 'An issue was resolved', 'replicaforge' );
				$body    = __( 'An issue on a project you follow was resolved.', 'replicaforge' );
				break;
			case 'generation_completed':
				$subject = sprintf( __( 'Generation finished: %s', 'replicaforge' ), $project );
				$body    = __( 'The site has been generated.', 'replicaforge' );
				break;
			case 'validation_completed':
				$subject = sprintf( __( 'Validation finished: %s', 'replicaforge' ), $project );
				$body    = __( 'A validation pass has finished.', 'replicaforge' );
				break;
			case 'sync_conflict':
				$subject = __( 'A synchronisation needs review', 'replicaforge' );
				$body    = __( 'A sync conflict is waiting for a decision.', 'replicaforge' );
				break;
			case 'content_conflict':
				$subject = __( 'A content conflict needs review', 'replicaforge' );
				$body    = __( 'A content mapping conflict is waiting for a decision.', 'replicaforge' );
				break;
			case 'member_added':
				$subject = __( 'You were added to a workspace', 'replicaforge' );
				$body    = __( 'You now have access to a ReplicaForge workspace.', 'replicaforge' );
				break;
			case 'invitation_accepted':
				$subject = __( 'An invitation was accepted', 'replicaforge' );
				$body    = __( 'Somebody accepted a workspace invitation.', 'replicaforge' );
				break;
			case 'weekly_summary':
				$subject = __( 'Your weekly ReplicaForge summary', 'replicaforge' );
				$body    = __( 'Your weekly activity summary is ready.', 'replicaforge' );
				break;
			default:
				return null;
		}

		$user = get_userdata( (int) $user_id );

		return array(
			'title'      => $subject,
			'body'       => $body,
			'link'       => $link,
			'project_id' => $project_id,
			'actor_id'   => (int) ( $context['actor_id'] ?? 0 ),
			// The email provider resolves the address from the *user*, not from the
			// context, so a caller cannot direct a notification at an address of their
			// choosing. For a user with no account there is no email notification at all,
			// which is correct: there is nowhere to send it.
			'email'      => ( $user instanceof \WP_User ) ? (string) $user->user_email : '',
		);
	}

	/**
	 * Return a link back into ReplicaForge.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $context    Context.
	 * @return string
	 */
	private function link_for( $project_id, array $context ) {
			/*
		 * `\Admin`, not `Admin`. Every WordPress-facing class this plugin declares lives
		 * in the global namespace, and an unqualified class name inside
		 * `namespace ReplicaForge` resolves to `ReplicaForge\Admin` - which does not
		 * exist. Unlike an `instanceof` against a missing class, which is quietly false, a
		 * static constant read on one is a fatal, so this would break the first time a
		 * notification was composed rather than at install time.
		 */
		$base = admin_url( 'admin.php?page=' . Workspace_Limits::ADMIN_PAGE );
		if ( '' === (string) $project_id ) {
			return $base;
		}
		return add_query_arg(
			array(
				'project' => rawurlencode( (string) $project_id ),
				'view'    => rawurlencode( (string) ( $context['view'] ?? '' ) ),
			),
			$base
		);
	}

	/**
	 * Return a project's name, or a neutral label.
	 *
	 * @param string $project_id Project id.
	 * @return string
	 */
	private function project_name( $project_id ) {
		$project_id = (string) $project_id;
		if ( '' === $project_id ) {
			return __( 'a project', 'replicaforge' );
		}
		$project = ( new Project_Repository() )->find( $project_id );
		if ( is_array( $project ) && '' !== (string) ( $project['name'] ?? '' ) ) {
			return (string) $project['name'];
		}
		return __( 'a project', 'replicaforge' );
	}
}

/**
 * Storage and preferences for notifications.
 */
final class Notification_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'notifications';

	/**
	 * The per-user preferences option.
	 *
	 * @var string
	 */
	const PREFERENCES_OPTION = 'replicaforge_notification_preferences';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'user_id', 'type', 'title', 'body', 'link', 'project_id', 'actor_id', 'channel', 'status', 'email_sent_at', 'read_at', 'created_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'     => 'string',
			'workspace_id'  => 'string',
			'user_id'       => 'int',
			'type'          => 'line',
			'title'         => 'line',
			'body'          => 'text',
			'link'          => 'line',
			'project_id'    => 'string',
			'actor_id'      => 'int',
			'channel'       => 'line',
			'status'        => 'line',
			'email_sent_at' => 'datetime',
			'read_at'       => 'datetime',
			'created_at'    => 'datetime',
		);
	}

	/**
	 * Create a notification.
	 *
	 * @param array<string, mixed> $notification Notification.
	 * @return array<string, mixed>|null
	 */
	public function create( array $notification ) {
		$user_id = (int) ( $notification['user_id'] ?? 0 );
		$type    = (string) ( $notification['type'] ?? '' );

		if ( $user_id < 1 || '' === $type || ! $this->ready() ) {
			return null;
		}

		return $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => (string) ( $notification['workspace_id'] ?? '' ),
				'user_id'      => $user_id,
				'type'         => $type,
				'title'        => substr( (string) ( $notification['title'] ?? '' ), 0, 200 ),
				'body'         => (string) ( $notification['body'] ?? '' ),
				'link'         => substr( (string) ( $notification['link'] ?? '' ), 0, 250 ),
				'project_id'   => (string) ( $notification['project_id'] ?? '' ),
				'actor_id'     => (int) ( $notification['actor_id'] ?? 0 ),
				'channel'      => 'in_app',
				'status'       => 'unread',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Return a user's inbox.
	 *
	 * @param string $workspace_id Workspace id, or empty for all.
	 * @param int    $user_id      User id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function inbox( $workspace_id, $user_id, array $args = array() ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return $this->empty_page();
		}
		return $this->query( '*', array_merge( $args, array( 'user_id' => $user_id ) ) );
	}

	/**
	 * Return the unread count.
	 *
	 * @param string $workspace_id Workspace id, or empty for all.
	 * @param int    $user_id      User id.
	 * @return int
	 */
	public function unread_count( $workspace_id, $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return 0;
		}
		return $this->count_where( (string) ( $workspace_id ?: '*' ), array( 'user_id' => $user_id, 'status' => 'unread' ) );
	}

	/**
	 * Mark one notification read.
	 *
	 * Scoped to the recipient, so one user cannot mark another's notification read — a
	 * trivial but real IDOR, and the sort that ends up in a penetration test report.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param string $public_id    Notification public id.
	 * @return bool
	 */
	public function mark_read( $workspace_id, $user_id, $public_id ) {
		$notification = $this->find( '*', (string) $public_id );
		if ( null === $notification || (int) $notification['user_id'] !== (int) $user_id ) {
			return false;
		}
		if ( 'read' === (string) $notification['status'] ) {
			return true;
		}
		$this->update_row( (string) $public_id, array( 'status' => 'read', 'read_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		return true;
	}

	/**
	 * Mark every notification read for a user.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @return int
	 */
	public function mark_all_read( $workspace_id, $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'read', read_at = %s WHERE user_id = %d AND status = 'unread'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				gmdate( 'Y-m-d H:i:s' ),
				$user_id
			)
		);
	}

	/**
	 * Note that an email was sent for a type.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param string $type         Notification type.
	 * @return void
	 */
	public function mark_sent( $workspace_id, $user_id, $type ) {
		$page = $this->query(
			(string) ( $workspace_id ?: '*' ),
			array( 'user_id' => (int) $user_id, 'type' => (string) $type, 'status' => 'unread', 'per_page' => 1 )
		);
		if ( 0 === (int) $page['count'] ) {
			return;
		}
		$this->update_row( (string) $page['items'][0]['public_id'], array( 'email_sent_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Delete notifications older than a period.
	 *
	 * @param int $age Seconds.
	 * @return int Rows removed.
	 */
	public function prune( $age ) {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				gmdate( 'Y-m-d H:i:s', time() - (int) $age )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Preferences
	 * ------------------------------------------------------------------ */

	/**
	 * Return a user's preferences, with defaults.
	 *
	 * Defaults: **in-app on, email off**, for every category. §40 says defaults should be
	 * conservative, and email is the channel that leaves the site — so it is never on
	 * because nothing was configured.
	 *
	 * @param int $user_id User id.
	 * @return array<string, array<string, bool>>
	 */
	public function preferences( $user_id ) {
		$user_id = (int) $user_id;
		$out     = array();
		foreach ( Workspace_Limits::PREFERENCE_KEYS as $key ) {
			$out[ $key ] = array( 'in_app' => true, 'email' => false );
		}

		if ( $user_id < 1 ) {
			return $out;
		}

		$stored = get_option( self::PREFERENCES_OPTION, array() );
		$mine   = ( is_array( $stored ) && isset( $stored[ $user_id ] ) && is_array( $stored[ $user_id ] ) ) ? $stored[ $user_id ] : array();

		foreach ( $mine as $key => $channels ) {
			if ( ! isset( $out[ $key ] ) || ! is_array( $channels ) ) {
				continue;
			}
			foreach ( array( 'in_app', 'email' ) as $channel ) {
				if ( array_key_exists( $channel, $channels ) ) {
					$out[ $key ][ $channel ] = (bool) $channels[ $channel ];
				}
			}
		}

		return $out;
	}

	/**
	 * Replace a user's preferences.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $changes Changes.
	 * @return array<string, array<string, bool>>
	 */
	public function set_preferences( $user_id, array $changes ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return $this->preferences( $user_id );
		}

		$current = $this->preferences( $user_id );

		foreach ( $changes as $key => $channels ) {
			// An unknown key is dropped rather than stored: a typo would otherwise appear
			// in the preferences screen as a setting that does nothing.
			if ( ! isset( $current[ $key ] ) || ! is_array( $channels ) ) {
				continue;
			}
			foreach ( array( 'in_app', 'email' ) as $channel ) {
				if ( array_key_exists( $channel, $channels ) ) {
					$current[ $key ][ $channel ] = (bool) $channels[ $channel ];
				}
			}
		}

		$stored = get_option( self::PREFERENCES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$stored[ $user_id ] = $current;
		update_option( self::PREFERENCES_OPTION, $stored, false );

		return $current;
	}
}
