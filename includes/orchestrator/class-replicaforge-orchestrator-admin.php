<?php
/**
 * Phase 17: the workflow admin interface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard and the workflow detail screen.
 *
 * ### Two rules this screen exists to obey
 *
 * **No fabricated metrics.** There is no progress bar, no percentage and no "estimated time
 * remaining". §18 forbids them, and the honest substitute is already available: the count of
 * completed stages out of the declared total, and the name of the stage in flight. A user can
 * do arithmetic; what they cannot do is detect a number this plugin made up.
 *
 * **Every number comes from a record.** The dashboard reads persisted workflow rows. If the
 * store is empty the dashboard says it is empty - it does not fall back to zeroes that look
 * like measurements, because a dashboard whose numbers are placeholders is worse than no
 * dashboard.
 *
 * ### The UI is not the security boundary
 *
 * Controls are hidden or disabled for a user who cannot use them, and the REST API re-checks
 * every one of those decisions. Hiding a button is a courtesy to the user; refusing the
 * request is the actual control.
 */
final class Orchestrator_Admin {

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	const PAGE = 'replicaforge-workflows';

	/**
	 * The detail page slug.
	 *
	 * @var string
	 */
	const DETAIL_PAGE = 'replicaforge-workflow';

	/**
	 * The capability the menu is registered with.
	 *
	 * `replicaforge_use`, which is the lowest capability that can be held and still be
	 * relevant: someone who may use ReplicaForge but not generate pages can usefully see
	 * which workflows exist and what state they are in.
	 *
	 * This follows the pattern `Workspace_Admin` documents rather than registering with
	 * `manage_options`. `add_menu_page()` takes a capability *string*, not a callback, so
	 * the page has to be registered with something and the real check belongs in the render
	 * method. Registering with the strictest capability would hide the dashboard from the
	 * designers and reviewers who most need to see it; registering with the loosest and
	 * refusing inside the render keeps the menu visible and the screen protected.
	 *
	 * Neither is the security boundary. The REST API re-checks every action independently.
	 *
	 * @var string
	 */
	const MENU_CAPABILITY = 'replicaforge_use';

	/**
	 * The repository.
	 *
	 * @var Workflow_Repository
	 */
	private $workflows;

	/**
	 * The capability registry.
	 *
	 * @var Capability_Registry
	 */
	private $capabilities;

	/**
	 * The executor.
	 *
	 * @var Workflow_Executor
	 */
	private $executor;

	/**
	 * Build the admin.
	 *
	 * @param array $services repository, capabilities, executor.
	 */
	public function __construct( array $services = array() ) {
		$this->workflows    = ( $services['repository'] ?? null ) instanceof Workflow_Repository
			? $services['repository']
			: new Workflow_Repository();

		$this->capabilities = ( $services['capabilities'] ?? null ) instanceof Capability_Registry
			? $services['capabilities']
			: new Capability_Registry();

		$this->executor = ( $services['executor'] ?? null ) instanceof Workflow_Executor
			? $services['executor']
			: new Workflow_Executor( $this->workflows, $this->capabilities );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the menu items.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'ReplicaForge Workflows', 'replicaforge' ),
			__( 'Workflows', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::PAGE,
			array( $this, 'render_dashboard' ),
			'dashicons-randomize',
			58
		);

		add_submenu_page(
			self::PAGE,
			__( 'All workflows', 'replicaforge' ),
			__( 'All workflows', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::PAGE,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::PAGE,
			__( 'Workflow detail', 'replicaforge' ),
			__( 'Workflow detail', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::DETAIL_PAGE,
			array( $this, 'render_detail' )
		);
	}

	/**
	 * Enqueue the styles on this screen only.
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) && false === strpos( (string) $hook, self::DETAIL_PAGE ) ) {
			return;
		}

		$relative = 'includes/orchestrator/css/orchestrator-admin.css';

		wp_enqueue_style( 'replicaforge-orchestrator', REPLICAFORGE_URL . $relative, array(), REPLICAFORGE_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * The dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Render the workflow dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view workflows.', 'replicaforge' ), 403 );
		}

		$user_id = get_current_user_id();
		$rows    = $this->visible_workflows( $user_id );

		echo '<div class="wrap rf-workflows">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Reconstruction workflows', 'replicaforge' ) . '</h1>';

		$this->render_capability_banner();

		// The empty state is a real state, and it says what to do about it.
		if ( array() === $rows ) {
			echo '<div class="rf-empty">';
			echo '<h2>' . esc_html__( 'No workflows yet', 'replicaforge' ) . '</h2>';
			echo '<p>' . esc_html__( 'A workflow coordinates the whole reconstruction: it analyses a website, plans the result, generates an editable Elementor draft, validates it, and reports what it could not reproduce.', 'replicaforge' ) . '</p>';
			echo '<p>' . esc_html__( 'Open a project, choose Reconstruct, and pick a mode. The plan is shown for review before anything is created.', 'replicaforge' ) . '</p>';
			echo '</div>';
			echo '</div>';

			return;
		}

		$groups = $this->group( $rows );

		foreach ( $groups as $key => $group ) {
			printf(
				'<h2 class="rf-group">%s <span class="rf-count">%d</span></h2>',
				esc_html( $group['label'] ),
				count( $group['rows'] )
			);

			$this->render_table( $group['rows'] );
		}

		echo '</div>';
	}

	/**
	 * Render the capability banner.
	 *
	 * The most useful thing this screen can tell a user who is about to spend money on a
	 * reconstruction is that their site cannot do half of it. So the banner is always shown
	 * when anything is missing, and it names the missing thing rather than a count.
	 *
	 * @return void
	 */
	private function render_capability_banner() {
		$summary = $this->capabilities->summary();
		$missing = (array) $summary['missing'];
		$degraded = (array) $summary['degraded'];

		if ( array() === $missing && array() === $degraded ) {
			printf(
				'<div class="rf-banner rf-banner-ok" role="status"><p>%s</p></div>',
				esc_html__( 'Every feature ReplicaForge can use is fully available on this site.', 'replicaforge' )
			);

			return;
		}

		$level = array() === $missing ? 'warn' : 'bad';

		printf( '<div class="rf-banner rf-banner-%s" role="status">', esc_attr( $level ) );
		echo '<h2>' . esc_html__( 'This site cannot do everything', 'replicaforge' ) . '</h2>';
		echo '<p>' . esc_html__( 'The following features are unavailable. Workflows will still run, and every limitation below will be recorded in the report rather than hidden.', 'replicaforge' ) . '</p>';
		echo '<ul class="rf-capability-list">';

		foreach ( (array) $summary['limitations'] as $capability => $entry ) {
			printf(
				'<li><strong>%s</strong> <span class="rf-tag rf-tag-%s">%s</span><br />%s</li>',
				esc_html( ucfirst( str_replace( '_', ' ', (string) $capability ) ) ),
				esc_attr( 'degraded' === (string) $entry['status'] ? 'warn' : 'bad' ),
				esc_html( 'degraded' === (string) $entry['status'] ? __( 'reduced', 'replicaforge' ) : __( 'unavailable', 'replicaforge' ) ),
				esc_html( (string) $entry['reason'] )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Render one table of workflows.
	 *
	 * @param array $rows The rows.
	 * @return void
	 */
	private function render_table( array $rows ) {
		echo '<table class="wp-list-table widefat fixed striped rf-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Reconstruction workflows', 'replicaforge' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Workflow', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'State', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Stage', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Progress', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Result', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Updated', 'replicaforge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$record = $this->workflows->get( (string) $row['workflow_id'] );

			if ( null === $record ) {
				continue;
			}

			echo '<tr>';
			printf(
				'<td><a href="%s">%s</a><br /><span class="description">%s</span></td>',
				esc_url( $this->detail_url( (string) $record['workflow_id'] ) ),
				esc_html( $this->label( (string) $record['source_url'] ) ),
				esc_html( ucfirst( str_replace( '_', ' ', (string) $record['type'] ) ) . ' / ' . ucfirst( str_replace( '_', ' ', (string) $record['mode'] ) ) )
			);

			echo '<td>' . $this->state_badge( (string) $record['state'] ) . '</td>';

			// The stage, by name. Never a bar.
			printf( '<td>%s</td>', esc_html( $this->current_stage_label( $record ) ) );

			printf(
				'<td>%s</td>',
				esc_html(
					sprintf(
						/* translators: 1: completed count, 2: total count. */
						__( '%1$d of %2$d stages', 'replicaforge' ),
						$this->completed_count( $record ),
						count( (array) ( $record['plan']['stages'] ?? array() ) )
					)
				)
			);

			$final = (string) ( $record['result']['status'] ?? '' );

			echo '<td>';

			if ( '' === $final ) {
				echo '<span class="description">' . esc_html__( 'Not finished', 'replicaforge' ) . '</span>';
			} else {
				$this->final_badge( $final );
			}

			echo '</td>';

			printf( '<td>%s</td>', esc_html( $this->relative_time( (string) $record['updated_at'] ) ) );

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * The detail screen
	 * ------------------------------------------------------------------ */

	/**
	 * Render the workflow detail screen.
	 *
	 * @return void
	 */
	public function render_detail() {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this workflow.', 'replicaforge' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only GET.
		$id     = isset( $_GET['workflow'] ) ? sanitize_text_field( wp_unslash( $_GET['workflow'] ) ) : '';
		$record = $this->workflows->get( $id );

		if ( null === $record ) {
			// The same message whether it does not exist or may not be seen, so this page is
			// not a way to discover which workflow ids are real.
			wp_die( esc_html__( 'That workflow does not exist.', 'replicaforge' ), 404 );
		}

		$access = $this->workflows->may_access( $record, get_current_user_id(), 'projects.view' );

		if ( is_wp_error( $access ) ) {
			wp_die( esc_html__( 'You do not have access to this workflow.', 'replicaforge' ), 403 );
		}

		$user_id  = get_current_user_id();
		$artifacts = new Workflow_Artifacts( (string) $record['workflow_id'] );

		echo '<div class="wrap rf-workflow-detail">';

		printf(
			'<h1>%s</h1>',
			esc_html( sprintf( /* translators: %s: source URL. */ __( 'Workflow for %s', 'replicaforge' ), $this->label( (string) $record['source_url'] ) ) )
		);

		$this->render_summary_bar( $record );
		$this->render_actions( $record, $user_id );
		$this->render_approvals( $record, $user_id );
		$this->render_timeline( $record );
		$this->render_quality( $record, $artifacts );

		echo '</div>';
	}

	/**
	 * Render the summary bar.
	 *
	 * @param array $record The workflow.
	 * @return void
	 */
	private function render_summary_bar( array $record ) {
		echo '<div class="rf-summary">';
		printf( '<div><span class="rf-label">%s</span><span class="rf-value">%s</span></div>', esc_html__( 'State', 'replicaforge' ), $this->state_badge( (string) $record['state'] ) );
		printf( '<div><span class="rf-label">%s</span><span class="rf-value">%s</span></div>', esc_html__( 'Type', 'replicaforge' ), esc_html( ucfirst( str_replace( '_', ' ', (string) $record['type'] ) ) ) );
		printf( '<div><span class="rf-label">%s</span><span class="rf-value">%s</span></div>', esc_html__( 'Mode', 'replicaforge' ), esc_html( ucfirst( str_replace( '_', ' ', (string) $record['mode'] ) ) ) );
		printf( '<div><span class="rf-label">%s</span><span class="rf-value">%s</span></div>', esc_html__( 'Source', 'replicaforge' ), esc_html( (string) $record['source_url'] ) );
		echo '</div>';
	}

	/**
	 * Render the action controls.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return void
	 */
	private function render_actions( array $record, $user_id ) {
		$actions = $this->actions( $record, $user_id );

		if ( array() === $actions ) {
			return;
		}

		echo '<div class="rf-actions" role="group" aria-label="' . esc_attr__( 'Workflow actions', 'replicaforge' ) . '">';

		foreach ( $actions as $action ) {
			if ( ! $action['enabled'] ) {
				/*
				 * Rendered disabled with an explanation rather than omitted. A user who cannot
				 * see a control cannot tell whether it does not exist, does not apply yet, or
				 * is forbidden to them - and the third of those is worth saying out loud.
				 */
				printf(
					'<button type="button" class="button" disabled="disabled" aria-describedby="rf-why-%1$s" title="%2$s">%1$s</button>',
					esc_html( $action['label'] ),
					esc_attr( $action['reason'] )
				);
				printf( '<span id="rf-why-%s" class="screen-reader-text">%s</span>', esc_attr( $action['key'] ), esc_html( $action['reason'] ) );
				continue;
			}

			printf(
				'<button type="button" class="button button-%s" data-rf-action="%s" data-rf-workflow="%s" data-rf-nonce="%s">%s</button>',
				esc_attr( $action['primary'] ? 'primary' : '' ),
				esc_attr( $action['key'] ),
				esc_attr( (string) $record['workflow_id'] ),
				esc_attr( wp_create_nonce( 'replicaforge_workflow_' . (string) $record['workflow_id'] ) ),
				esc_html( $action['label'] )
			);
		}

		echo '</div>';
	}

	/**
	 * Render the outstanding approvals.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return void
	 */
	private function render_approvals( array $record, $user_id ) {
		$pending = array();

		foreach ( (array) ( $record['plan']['approval_checkpoints'] ?? array() ) as $checkpoint ) {
			$gate   = (string) ( $checkpoint['gate'] ?? '' );
			$status = $this->workflows->gate_status( $record, $gate );

			if ( 'approved' === (string) $status['status'] && empty( $status['stale'] ) ) {
				continue;
			}

			$pending[] = array( 'gate' => $gate ) + $status + array( 'stage' => (string) ( $checkpoint['stage'] ?? '' ) );
		}

		if ( array() === $pending ) {
			return;
		}

		echo '<div class="rf-panel rf-panel-approvals">';
		echo '<h2>' . esc_html__( 'Approvals', 'replicaforge' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Nothing behind an approval has been created. Each approval is bound to this exact plan, and if the plan changes the approval is no longer valid.', 'replicaforge' ) . '</p>';
		echo '<ul class="rf-approval-list">';

		foreach ( $pending as $item ) {
			echo '<li>';
			printf( '<strong>%s</strong> ', esc_html( ucfirst( str_replace( '_', ' ', $item['gate'] ) ) ) );

			if ( ! empty( $item['stale'] ) ) {
				printf( ' <span class="rf-tag rf-tag-warn">%s</span> ', esc_html__( 'outdated', 'replicaforge' ) );
			}

			if ( 'rejected' === (string) $item['status'] ) {
				printf( ' <span class="rf-tag rf-tag-bad">%s</span> ', esc_html__( 'rejected', 'replicaforge' ) );
			}

			printf( '<br /><span class="description">%s</span> ', esc_html__( 'Requires the capability', 'replicaforge' ) . ' ' . (string) $item['capability'] );
			printf( '<br /><span class="description">%s</span>', esc_html__( 'Stage', 'replicaforge' ) . ': ' . esc_html( $item['stage'] ) );

			// Approve and reject are only rendered for a user who holds the capability. The
			// API refuses anyone else, so this is presentation rather than enforcement.
			$capability = (string) $item['capability'];

			if ( ! is_wp_error( $this->workflows->may_access( $record, $user_id, '' === $capability ? 'projects.view' : $capability ) ) ) {
				printf(
					'<div class="rf-approval-buttons"><button type="button" class="button button-primary" data-rf-approve="%1$s" data-rf-gate="%2$s" data-rf-workflow="%3$s" data-rf-nonce="%4$s">%5$s</button> <button type="button" class="button" data-rf-reject="%1$s" data-rf-gate="%2$s" data-rf-workflow="%3$s" data-rf-nonce="%4$s">%6$s</button></div>',
					esc_attr( (string) $record['workflow_id'] ),
					esc_attr( (string) $item['gate'] ),
					esc_attr( (string) $record['workflow_id'] ),
					esc_attr( wp_create_nonce( 'replicaforge_workflow_' . (string) $record['workflow_id'] ) ),
					esc_html__( 'Approve', 'replicaforge' ),
					esc_html__( 'Reject', 'replicaforge' )
				);
			}

			echo '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Render the stage timeline.
	 *
	 * @param array $record The workflow.
	 * @return void
	 */
	private function render_timeline( array $record ) {
		$stages = (array) ( $record['plan']['stages'] ?? array() );

		if ( array() === $stages ) {
			return;
		}

		echo '<div class="rf-panel">';
		echo '<h2>' . esc_html__( 'Stages', 'replicaforge' ) . '</h2>';
		echo '<ol class="rf-timeline">';

		foreach ( $stages as $stage ) {
			$entry    = (array) ( $record['stages'][ $stage ] ?? array() );
			$outcome  = (string) ( $entry['outcome'] ?? 'pending' );
			$required = (array) ( Orchestrator_Limits::STAGE_DEPENDENCIES[ $stage ] ?? array() );

			echo '<li class="rf-stage rf-stage-' . esc_attr( $outcome ) . '">';
			printf( '<span class="rf-stage-name">%s</span> ', esc_html( ucfirst( str_replace( '_', ' ', (string) $stage ) ) ) );
			printf( '<span class="rf-tag">%s</span> ', esc_html( $outcome ) );

			if ( '' !== (string) ( $entry['reason'] ?? '' ) ) {
				printf( '<span class="description">%s</span> ', esc_html( (string) $entry['reason'] ) );
			}

			// The dependency line. §18 asks for a dependency view, and the honest way to show
			// one for a 17-stage graph is the list, not a diagram that would need a layout
			// engine and would be unreadable at this size.
			if ( ! empty( $required ) ) {
				printf(
					'<span class="description">%s</span>',
					esc_html(
						sprintf(
							/* translators: %s: comma separated stage names. */
							__( 'after: %s', 'replicaforge' ),
							implode( ', ', array_map( static fn( $s ) => str_replace( '_', ' ', (string) $s ), $required ) )
						)
					)
				);
			}

			if ( (int) ( $entry['attempts'] ?? 0 ) > 1 ) {
				printf(
					' <span class="description">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: attempt count. */
							__( '%d attempts', 'replicaforge' ),
							(int) $entry['attempts']
						)
					)
				);
			}

			echo '</li>';
		}

		echo '</ol></div>';
	}

	/**
	 * Render the quality results.
	 *
	 * @param array              $record    The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return void
	 */
	private function render_quality( array $record, $artifacts ) {
		$final = $artifacts->get( 'quality_gate' );
		$final = is_wp_error( $final ) ? array( 'payload' => (array) ( $record['result'] ?? array() ) ) : $final;

		$dimensions = (array) ( $final['payload']['dimensions'] ?? array() );

		if ( array() === $dimensions ) {
			return;
		}

		echo '<div class="rf-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'Quality', 'replicaforge' ) );
		echo '<p class="description">' . esc_html__( 'Each dimension is reported on its own. There is no combined score, because a single number cannot say whether a reconstruction is visually right and structurally unusable.', 'replicaforge' ) . '</p>';
		echo '<table class="widefat striped rf-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Dimension', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'State', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Note', 'replicaforge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $dimensions as $name => $dimension ) {
			$status = (string) ( $dimension['status'] ?? 'unavailable' );

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( ucfirst( (string) $name ) ) );
			printf(
				'<td><span class="rf-tag rf-tag-%s">%s</span></td>',
				esc_attr( 'measured' === $status ? 'ok' : 'warn' ),
				esc_html( 'measured' === $status ? __( 'measured', 'replicaforge' ) : __( 'not measurable', 'replicaforge' ) )
			);
			printf( '<td>%s</td>', esc_html( (string) ( $dimension['reason'] ?? '' ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		// The generated page, when there is one.
		$draft = $artifacts->get( 'draft' );

		if ( ! is_wp_error( $draft ) ) {
			$post = get_post( (int) ( $draft['payload']['draft_id'] ?? 0 ) );

			if ( null !== $post ) {
				$link = get_edit_post_link( (int) $post->ID, 'raw' );

				if ( is_string( $link ) && '' !== $link ) {
					printf(
						'<p><a class="button button-primary" href="%s">%s</a></p>',
						esc_url( $link ),
						esc_html__( 'Open the generated draft in Elementor', 'replicaforge' )
					);
				}
			}
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/**
	 * Return the workflows a user may see, with their records.
	 *
	 * @param int $user_id The user.
	 * @return array<int, array>
	 */
	private function visible_workflows( $user_id ) {
		$out = array();

		foreach ( $this->workflows->recent( 100 ) as $row ) {
			$record = $this->workflows->get( (string) $row['workflow_id'] );

			if ( null === $record ) {
				continue;
			}

			if ( is_wp_error( $this->workflows->may_access( $record, (int) $user_id, 'projects.view' ) ) ) {
				continue;
			}

			$out[] = $record;
		}

		return $out;
	}

	/**
	 * Group workflows by what a user needs to do about them.
	 *
	 * @param array $records The records.
	 * @return array<string, array>
	 */
	private function group( array $records ) {
		$groups = array(
			'active'    => array( 'label' => __( 'Running now', 'replicaforge' ), 'rows' => array() ),
			'waiting'   => array( 'label' => __( 'Waiting for a decision', 'replicaforge' ), 'rows' => array() ),
			'paused'    => array( 'label' => __( 'Paused and failed', 'replicaforge' ), 'rows' => array() ),
			'finished'  => array( 'label' => __( 'Finished', 'replicaforge' ), 'rows' => array() ),
		);

		foreach ( $records as $record ) {
			$state = (string) $record['state'];

			if ( in_array( $state, array( 'queued', 'running', 'preflight', 'validating', 'correcting' ), true ) ) {
				$groups['active']['rows'][] = $record;
				continue;
			}

			if ( 'waiting_approval' === $state ) {
				$groups['waiting']['rows'][] = $record;
				continue;
			}

			if ( in_array( $state, array( 'paused', 'failed' ), true ) ) {
				$groups['paused']['rows'][] = $record;
				continue;
			}

			$groups['finished']['rows'][] = $record;
		}

		return array_filter(
			$groups,
			static fn( $group ) => array() !== $group['rows']
		);
	}

	/**
	 * Return the actions available, with reasons.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array<int, array>
	 */
	private function actions( array $record, $user_id ) {
		$state     = (string) $record['state'];
		$out       = array();
		$can_run   = ! is_wp_error( $this->workflows->may_access( $record, (int) $user_id, 'generation.run' ) );
		$can_write = ! is_wp_error( $this->workflows->may_access( $record, (int) $user_id, 'correction.apply' ) );

		$definitions = array(
			'run'     => array( __( 'Run', 'replicaforge' ), array( 'draft', 'preflight', 'queued', 'paused' ), $can_run, true ),
			'pause'   => array( __( 'Pause', 'replicaforge' ), array( 'running', 'waiting_approval' ), $can_run, false ),
			'resume'  => array( __( 'Resume', 'replicaforge' ), array( 'paused' ), $can_run, true ),
			'retry'   => array( __( 'Retry failed stage', 'replicaforge' ), array( 'paused', 'failed' ), $can_write, true ),
			'cancel'  => array( __( 'Cancel', 'replicaforge' ), array( 'draft', 'preflight', 'queued', 'running', 'waiting_approval', 'paused', 'validating', 'correcting', 'final_review' ), $can_run, false ),
		);

		foreach ( $definitions as $key => $definition ) {
			list( $label, $from, $allowed, $primary ) = $definition;

			$out[] = array(
				'key'     => (string) $key,
				'label'   => $label,
				'enabled' => $allowed && in_array( $state, $from, true ),
				'primary' => (bool) $primary,
				'reason'  => $allowed
					? __( 'Not available in the current state.', 'replicaforge' )
					: __( 'You do not have permission to do this.', 'replicaforge' ),
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Formatting
	 * ------------------------------------------------------------------ */

	/**
	 * Return a state badge.
	 *
	 * @param string $state The state.
	 * @return string
	 */
	private function state_badge( $state ) {
		$levels = array(
			'completed'               => 'ok',
			'completed_with_warnings' => 'warn',
			'failed'                  => 'bad',
			'cancelled'               => 'muted',
			'waiting_approval'        => 'warn',
			'paused'                  => 'muted',
			'running'                 => 'ok',
		);

		$level = (string) ( $levels[ (string) $state ] ?? 'muted' );

		return sprintf(
			'<span class="rf-tag rf-tag-%1$s">%2$s</span>',
			esc_attr( $level ),
			esc_html( ucfirst( str_replace( '_', ' ', (string) $state ) ) )
		);
	}

	/**
	 * Return a final status badge.
	 *
	 * @param string $status The status.
	 * @return string
	 */
	private function final_badge( $status ) {
		$levels = array(
			'passed'               => 'ok',
			'passed_with_warnings' => 'warn',
			'needs_review'         => 'warn',
			'blocked'              => 'bad',
			'failed'               => 'bad',
		);

		return sprintf(
			'<span class="rf-tag rf-tag-%1$s">%2$s</span>',
			esc_attr( (string) ( $levels[ (string) $status ] ?? 'muted' ) ),
			esc_html( ucfirst( str_replace( '_', ' ', (string) $status ) ) )
		);
	}

	/**
	 * Return the number of completed stages.
	 *
	 * @param array $record The workflow.
	 * @return int
	 */
	private function completed_count( array $record ) {
		$count = 0;

		foreach ( (array) ( $record['plan']['stages'] ?? array() ) as $stage ) {
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Return the current stage, in words.
	 *
	 * @param array $record The workflow.
	 * @return string
	 */
	private function current_stage_label( array $record ) {
		foreach ( (array) ( $record['plan']['stages'] ?? array() ) as $stage ) {
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( ! in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				return ucfirst( str_replace( '_', ' ', (string) $stage ) );
			}
		}

		return __( 'Finished', 'replicaforge' );
	}

	/**
	 * Return a readable label for a URL.
	 *
	 * @param string $url The URL.
	 * @return string
	 */
	private function label( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );

		$label = (string) ( $host ? $host : $url );

		if ( is_string( $path ) && '/' !== $path && '' !== $path ) {
			$label .= $path;
		}

		return $label;
	}

	/**
	 * Return a relative time.
	 *
	 * @param string $when The timestamp.
	 * @return string
	 */
	private function relative_time( $when ) {
		$stamp = strtotime( (string) $when );

		if ( false === $stamp ) {
			return (string) $when;
		}

		return sprintf(
			/* translators: %s: human readable time difference. */
			__( '%s ago', 'replicaforge' ),
			human_time_diff( $stamp, time() )
		);
	}

	/**
	 * Return the detail screen URL.
	 *
	 * @param string $workflow_id Workflow id.
	 * @return string
	 */
	private function detail_url( $workflow_id ) {
		return admin_url( 'admin.php?page=' . self::DETAIL_PAGE . '&workflow=' . rawurlencode( (string) $workflow_id ) );
	}
}
