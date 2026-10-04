<?php
/**
 * Phase 15: the client store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Clients, and the contacts who review their work.
 *
 * ### What a client record deliberately does not hold
 *
 * §6 says "Do not store unnecessary sensitive information", and §29 says "Avoid
 * unnecessary CRM features". So there is no billing address, no payment method, no
 * contract value, no lead score, and no behavioural tracking. A client's row is a name, a
 * company, one email, one phone, a website, and notes.
 *
 * The `notes` field is the one place a user can put something sensitive, so it is stored
 * through `sanitize_textarea_field` and it is **excluded from every export and every
 * notification** unless the reader holds `clients.edit`. Notes are where a client's
 * internal opinions live, and an agency email that quoted one to the client would be a
 * disclosure neither party asked for.
 *
 * ### Contacts see only what is shared with them
 *
 * A contact is a person, not a user. They do not become a WordPress account, and §7's rule
 * is the one that applies: a client contact sees only the projects and review links
 * explicitly shared with them. `Permission_Manager::can_act_on_review()` is what enforces
 * it, and a contact row on its own grants nothing at all.
 */
final class Client_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'clients';

	/**
	 * The contacts store.
	 *
	 * @var Client_Contact_Store
	 */
	private $contacts;

	/**
	 * Constructor.
	 *
	 * @param Collaboration_Schema|null $schema   Optional schema.
	 * @param Logger|null              $logger   Optional logger.
	 * @param Client_Contact_Store|null $contacts Optional contacts store.
	 */
	public function __construct( $schema = null, $logger = null, $contacts = null ) {
		parent::__construct( $schema, $logger );
		$this->contacts = $contacts instanceof Client_Contact_Store ? $contacts : new Client_Contact_Store();
	}

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'name', 'company', 'email', 'phone', 'website', 'notes', 'status', 'archived_at', 'created_at', 'updated_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'    => 'string',
			'workspace_id' => 'string',
			'name'         => 'line',
			'company'      => 'line',
			'email'        => 'email',
			'phone'        => 'line',
			'website'      => 'url',
			'notes'        => 'text',
			'status'       => 'line',
			'archived_at'  => 'datetime',
			'created_at'   => 'datetime',
			'updated_at'   => 'datetime',
		);
	}

	/**
	 * Return the columns free text may search.
	 *
	 * Notes are deliberately excluded. A search across notes would return a client because
	 * of a word in an internal note, and would then show the note to whoever ran the search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name', 'company', 'email' );
	}

	/**
	 * Return a page of clients, with a project count each.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function clients( $workspace_id, array $args = array() ) {
		$page = $this->query( (string) $workspace_id, $args );

		// The project count is a single grouped query rather than one per row. A list of
		// 25 clients asking the project table 25 times is the difference between one query
		// and twenty-six, and §45 asks for indexed queries rather than a loop.
		$counts = $this->project_counts( (string) $workspace_id );
		foreach ( $page['items'] as $index => $client ) {
			$page['items'][ $index ]['project_count'] = (int) ( $counts[ (string) $client['public_id'] ] ?? 0 );
		}

		$page['statuses'] = $this->group_counts( (string) $workspace_id, 'status' );
		return $page;
	}

	/**
	 * Return one client, or null.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Client public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Create a client.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param array<string, mixed> $data         Client data.
	 * @return array<string, mixed>|null
	 */
	public function create( $workspace_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		if ( '' === $workspace_id || ! $this->ready() ) {
			return null;
		}

		$name = trim( (string) ( $data['name'] ?? '' ) );
		$company = trim( (string) ( $data['company'] ?? '' ) );
		if ( '' === $name && '' === $company ) {
			// A client with neither a person nor a company is not a client.
			return null;
		}
		if ( '' === $name ) {
			$name = $company;
		}
		if ( '' === $company ) {
			$company = $name;
		}

		if ( $this->count_where( $workspace_id, array( 'status' => 'active' ) ) >= Workspace_Limits::MAX_CLIENTS ) {
			$this->logger->warning(
				'workspace_client_cap',
				'A workspace reached its client limit, so the client was not stored.',
				array( 'workspace' => $workspace_id, 'limit' => Workspace_Limits::MAX_CLIENTS ),
				'workspace'
			);
			return null;
		}

		return $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => $workspace_id,
				'name'         => substr( $name, 0, 120 ),
				'company'      => substr( $company, 0, 120 ),
				'email'        => (string) ( $data['email'] ?? '' ),
				'phone'        => substr( (string) ( $data['phone'] ?? '' ), 0, 40 ),
				'website'      => (string) ( $data['website'] ?? '' ),
				'notes'        => (string) ( $data['notes'] ?? '' ),
				'status'       => 'active',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Update a client.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Client public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return array<string, mixed>|null
	 */
	public function update( $workspace_id, $public_id, array $changes ) {
		$allowed = array( 'name', 'company', 'email', 'phone', 'website', 'notes', 'status' );
		$clean   = array();
		foreach ( $allowed as $column ) {
			if ( array_key_exists( $column, $changes ) ) {
				$clean[ $column ] = $changes[ $column ];
			}
		}
		if ( array() === $clean ) {
			return null;
		}
		$this->update_row( (string) $public_id, $clean );
		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Archive a client.
	 *
	 * §30 says archive; it does not say delete. The client keeps their projects, their
	 * reviews and their history, and simply stops appearing in active lists. A hard delete
	 * would take the audit trail's subject with it, which is the opposite of §25's intent.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Client public id.
	 * @return array<string, mixed>|null
	 */
	public function archive( $workspace_id, $public_id ) {
		$this->update_row(
			(string) $public_id,
			array( 'status' => 'archived', 'archived_at' => gmdate( 'Y-m-d H:i:s' ) )
		);
		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Return a client without the notes, for a reader who may not edit.
	 *
	 * The single place a client is redacted, so no caller has to remember. `clients.view`
	 * without `clients.edit` gets the name, the company and the email — enough to run a
	 * project list — and not the internal notes.
	 *
	 * @param array<string, mixed> $client     Client row.
	 * @param bool                 $may_edit   Whether the reader may edit.
	 * @return array<string, mixed>
	 */
	public static function present( array $client, $may_edit ) {
		if ( ! $may_edit ) {
			unset( $client['notes'] );
			$client['notes_hidden'] = true;
		} else {
			$client['notes_hidden'] = false;
		}
		return $client;
	}

	/**
	 * Return a client for export.
	 *
	 * `notes` is included only when the caller holds `clients.edit`, and the caller's
	 * capability is re-read here rather than trusted as a boolean from the caller, because
	 * an export is a disclosure and §34 lists private credentials as the thing to exclude.
	 * Notes are not a credential, but they are internal.
	 *
	 * @param array<string, mixed> $client   Client row.
	 * @param int                  $user_id  Exporting user.
	 * @param string               $workspace_id Workspace id.
	 * @return array<string, mixed>
	 */
	public static function for_export( array $client, $user_id, $workspace_id ) {
		$permissions = new Permission_Manager();
		$may_edit    = $permissions->can( (int) $user_id, (string) $workspace_id, 'clients.edit' );
		return self::present( $client, $may_edit );
	}

	/**
	 * Return a client's contacts.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $client_id    Client public id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function client_contacts( $workspace_id, $client_id, array $args = array() ) {
		return $this->contacts->contacts( $workspace_id, (string) $client_id, $args );
	}

	/**
	 * Add a contact to a client.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $client_id    Client public id.
	 * @param array<string, mixed> $data         Contact data.
	 * @return array<string, mixed>|null
	 */
	public function add_contact( $workspace_id, $client_id, array $data ) {
		return $this->contacts->add( $workspace_id, $client_id, $data );
	}

	/**
	 * Count clients per project, for the list view.
	 *
	 * The count comes from the project context, not from a join, because the project
	 * record itself lives in an option and there is no `client_id` column to index. The
	 * grouping happens in PHP over the project ids the caller already has, which keeps it
	 * to one pass instead of a per-row query.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, int>
	 */
	public function project_counts( $workspace_id ) {
		$contexts = ( new Project_Context_Store() )->all( (string) $workspace_id );
		$counts   = array();
		foreach ( $contexts as $context ) {
			$client_id = (string) ( $context['client_id'] ?? '' );
			if ( '' === $client_id ) {
				continue;
			}
			$counts[ $client_id ] = ( $counts[ $client_id ] ?? 0 ) + 1;
		}
		return $counts;
	}
}
