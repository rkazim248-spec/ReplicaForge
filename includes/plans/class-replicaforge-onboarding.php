<?php
/**
 * Phase 10: first-run onboarding and contextual guidance.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The state behind the welcome screen and the product tours.
 *
 * This class holds state and the text of the guidance. It renders nothing and
 * triggers nothing, and the second half of that sentence is the important part.
 *
 * §3 is explicit that a fresh activation must not analyze or generate anything.
 * The strongest way to honour that is for the onboarding code to contain no call
 * into the analysis or generation pipeline at all — so a future change that added
 * "start a sample analysis so the welcome screen has something to show" would have
 * to add that call explicitly, and would be adding it to a file whose whole purpose
 * is to not do it. Nothing here reaches `Analyzer`, `Elementor_Generator`, or
 * `Ai_Manager`.
 *
 * Dismissal is stored per WordPress user rather than per site, because a site with
 * three editors should not have the welcome screen reappear for the two who have
 * already dismissed it. The site's completion is separate from a user's
 * acknowledgement: one administrator completing setup is a fact about the site,
 * while one user closing a panel is a fact about that user.
 */
final class Onboarding {

	/**
	 * Option holding the site-level onboarding state.
	 */
	const OPTION = 'replicaforge_onboarding';

	/**
	 * User meta holding per-user acknowledgement and tour dismissal.
	 */
	const USER_META = 'replicaforge_onboarding';

	/**
	 * The current onboarding schema version.
	 *
	 * Bumped when the guidance text changes materially, so an installation that has
	 * already been through onboarding can be shown new guidance without the old
	 * dismissal silently hiding it.
	 */
	const VERSION = 1;

	/**
	 * The tour definitions.
	 *
	 * Each entry is an id, a trigger, and the text. The triggers are pure functions
	 * of project state, so a tour appears when it is true and disappears when it
	 * stops being true — a dismissed tour does not reappear because a project
	 * changed, and an undismissed one does not appear before its moment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tours() {
		return array(
			'new_replica'          => array(
				'title'    => __( 'Enter a public website URL to begin.', 'replicaforge' ),
				'body'     => __( 'ReplicaForge reads the public frontend of a page: its structure, components, assets, typography, and spacing. It does not need a login, and it never executes the page in your browser.', 'replicaforge' ),
				'when'     => 'starting',
				'order'    => 10,
			),
			'analysis_complete'    => array(
				'title'    => __( 'Analysis complete.', 'replicaforge' ),
				'body'     => __( 'ReplicaForge has identified the page structure, components, assets, and design system. Read the report before generating — it is the evidence the reconstruction is built from.', 'replicaforge' ),
				'when'     => 'analyzed',
				'order'    => 20,
			),
			'specification_ready'  => array(
				'title'    => __( 'Reconstruction plan ready.', 'replicaforge' ),
				'body'     => __( 'Review what ReplicaForge plans to rebuild before generating. The plan names each section, the components in it, and where each value came from.', 'replicaforge' ),
				'when'     => 'planned',
				'order'    => 30,
			),
			'draft_created'        => array(
				'title'    => __( 'Your editable Elementor draft is ready.', 'replicaforge' ),
				'body'     => __( 'The draft is a WordPress draft, not a published page. Open it in Elementor to edit it. ReplicaForge will not publish it for you.', 'replicaforge' ),
				'when'     => 'generated',
				'order'    => 40,
			),
			'validation_complete'  => array(
				'title'    => __( 'Validation complete.', 'replicaforge' ),
				'body'     => __( 'Review the visual differences and optionally apply safe corrections. ReplicaForge only offers a correction it can show you and can reverse.', 'replicaforge' ),
				'when'     => 'validated',
				'order'    => 50,
			),
			'corrections_planned'  => array(
				'title'    => __( 'Corrections are ready to review.', 'replicaforge' ),
				'body'     => __( 'Every correction shows the current value, the source value, and the measured difference. Nothing is written until you apply it.', 'replicaforge' ),
				'when'     => 'corrections',
				'order'    => 60,
			),
			'limit_reached'        => array(
				'title'    => __( 'You have used this month\'s allowance.', 'replicaforge' ),
				'body'     => __( 'Usage counts operations that completed, not requests. A failed run does not consume allowance. Your allowance resets at the start of next month.', 'replicaforge' ),
				'when'     => 'limit',
				'order'    => 70,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Site state
	 * ------------------------------------------------------------------ */

	/**
	 * Return the site-level onboarding state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$version = isset( $stored['version'] ) ? (int) $stored['version'] : 0;

		return array(
			'version'       => $version,
			'fresh'         => ( 0 === $version ),
			'completed'     => ! empty( $stored['completed_at'] ),
			'completed_at'  => isset( $stored['completed_at'] ) ? (int) $stored['completed_at'] : 0,
			'completed_by'  => isset( $stored['completed_by'] ) ? (int) $stored['completed_by'] : 0,
			'skipped'       => ! empty( $stored['skipped_at'] ),
			'skipped_at'    => isset( $stored['skipped_at'] ) ? (int) $stored['skipped_at'] : 0,
		);
	}

	/**
	 * Record that the site's onboarding has been finished.
	 *
	 * A skip and a completion are stored separately because they mean different
	 * things. A user who skipped has not seen the workflow and may want it back; a
	 * user who completed has seen it and does not. Both stop the welcome screen
	 * appearing, which is the whole point of either.
	 *
	 * @param bool $skipped Whether the user skipped rather than completed.
	 * @return array<string, mixed>
	 */
	public static function complete( $skipped = false ) {
		$user_id = get_current_user_id();
		$now     = time();

		$stored = array(
			'version'      => self::VERSION,
			'completed_at' => $skipped ? 0 : $now,
			'completed_by' => $skipped ? 0 : $user_id,
			'skipped_at'   => $skipped ? $now : 0,
		);

		update_option( self::OPTION, $stored, false );

		Audit_Log::record(
			$skipped ? 'onboarding_skipped' : 'onboarding_completed',
			array( 'by' => $user_id ),
			$user_id
		);

		return self::state();
	}

	/**
	 * Return whether the welcome screen should be offered to a user.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function needs_welcome( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}

		$state = self::state();
		if ( $state['completed'] || $state['skipped'] ) {
			return false;
		}

		return ! self::user_state( $user_id )['seen'];
	}

	/**
	 * Return whether the site has ever been through onboarding.
	 *
	 * @return bool
	 */
	public static function is_fresh_install() {
		return self::state()['fresh'];
	}

	/* ---------------------------------------------------------------------
	 * Per-user state
	 * ------------------------------------------------------------------ */

	/**
	 * Return a user's onboarding state.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	public static function user_state( $user_id ) {
		$user_id = (int) $user_id;
		$stored  = $user_id > 0 ? get_user_meta( $user_id, self::USER_META, true ) : array();
		$stored  = is_array( $stored ) ? $stored : array();

		$dismissed = isset( $stored['dismissed'] ) && is_array( $stored['dismissed'] ) ? $stored['dismissed'] : array();
		$clean     = array();
		foreach ( array_keys( self::tours() ) as $tour_id ) {
			$clean[ $tour_id ] = ! empty( $dismissed[ $tour_id ] );
		}

		return array(
			'seen'      => ! empty( $stored['seen'] ),
			'seen_at'   => isset( $stored['seen_at'] ) ? (int) $stored['seen_at'] : 0,
			'dismissed' => $clean,
		);
	}

	/**
	 * Record that a user has seen the welcome screen.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	public static function mark_seen( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return self::user_state( 0 );
		}

		$stored = get_user_meta( $user_id, self::USER_META, true );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['seen']    = true;
		$stored['seen_at'] = time();

		update_user_meta( $user_id, self::USER_META, $stored );

		return self::user_state( $user_id );
	}

	/**
	 * Dismiss a tour for a user.
	 *
	 * An unrecognised tour id is refused rather than stored. Storing it would make
	 * the dismissal list grow with whatever a caller sent, and a caller sending
	 * arbitrary ids is a caller that has misunderstood the API.
	 *
	 * @param int    $user_id User id.
	 * @param string $tour_id Tour identifier.
	 * @return array<string, mixed>
	 */
	public static function dismiss_tour( $user_id, $tour_id ) {
		$user_id = (int) $user_id;
		$tour_id = is_string( $tour_id ) ? $tour_id : '';

		if ( $user_id < 1 || ! array_key_exists( $tour_id, self::tours() ) ) {
			return self::user_state( $user_id );
		}

		$stored = get_user_meta( $user_id, self::USER_META, true );
		$stored = is_array( $stored ) ? $stored : array();

		$dismissed = isset( $stored['dismissed'] ) && is_array( $stored['dismissed'] ) ? $stored['dismissed'] : array();
		$dismissed[ $tour_id ] = true;

		$stored['dismissed'] = $dismissed;
		update_user_meta( $user_id, self::USER_META, $stored );

		return self::user_state( $user_id );
	}

	/**
	 * Return a tour again for a user.
	 *
	 * @param int    $user_id User id.
	 * @param string $tour_id Tour identifier.
	 * @return array<string, mixed>
	 */
	public static function restore_tour( $user_id, $tour_id ) {
		$user_id = (int) $user_id;
		$tour_id = is_string( $tour_id ) ? $tour_id : '';

		if ( $user_id < 1 || ! array_key_exists( $tour_id, self::tours() ) ) {
			return self::user_state( $user_id );
		}

		$stored = get_user_meta( $user_id, self::USER_META, true );
		$stored = is_array( $stored ) ? $stored : array();

		$dismissed = isset( $stored['dismissed'] ) && is_array( $stored['dismissed'] ) ? $stored['dismissed'] : array();
		unset( $dismissed[ $tour_id ] );

		$stored['dismissed'] = $dismissed;
		update_user_meta( $user_id, self::USER_META, $stored );

		return self::user_state( $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Tours
	 * ------------------------------------------------------------------ */

	/**
	 * Return the tours that currently apply.
	 *
	 * A tour applies when its moment has arrived and it has not been dismissed.
	 *
	 * @param string               $moment  Current moment, from {@see self::moment_for()}.
	 * @param int                  $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function active_tours( $moment, $user_id ) {
		$user_state = self::user_state( $user_id );
		$out        = array();

		foreach ( self::tours() as $tour_id => $tour ) {
			if ( $tour['when'] !== (string) $moment ) {
				continue;
			}
			if ( ! empty( $user_state['dismissed'][ $tour_id ] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $tour_id,
				'title' => (string) $tour['title'],
				'body'  => (string) $tour['body'],
				'order' => (int) $tour['order'],
				'moment' => (string) $tour['when'],
			);
		}

		usort(
			$out,
			static function ( $left, $right ) {
				return $left['order'] <=> $right['order'];
			}
		);

		return $out;
	}

	/**
	 * Return the moment a project is currently at.
	 *
	 * Derived from the project's own fields, using the same status vocabulary the
	 * rest of Phase 10 uses, so "which tour is showing" and "what state is this
	 * project in" cannot be two different answers.
	 *
	 * @param array<string, mixed>|null $project Project record.
	 * @return string
	 */
	public static function moment_for( $project ) {
		if ( ! is_array( $project ) || array() === $project ) {
			return 'starting';
		}

		$status = Project_Status::of( $project );

		if ( Project_Status::FAILED === $status ) {
			return 'failed';
		}

		if ( ! empty( $project['corrections'] ) && is_array( $project['corrections'] ) ) {
			return 'corrections';
		}

		if ( ! empty( $project['validation'] ) && is_array( $project['validation'] ) ) {
			return 'validated';
		}

		if ( ! empty( $project['drafts'] ) && is_array( $project['drafts'] ) ) {
			return 'generated';
		}

		if ( ! empty( $project['specification'] ) && is_array( $project['specification'] ) ) {
			return 'planned';
		}

		if ( ! empty( $project['analysis'] ) && is_array( $project['analysis'] ) ) {
			return 'analyzed';
		}

		return 'starting';
	}

	/**
	 * Return the welcome content.
	 *
	 * §3 specifies the shape. The six verbs are the plugin's actual workflow, and
	 * the one-line description is what the plugin does, stated without a claim it
	 * cannot support.
	 *
	 * @return array<string, mixed>
	 */
	public static function welcome() {
		return array(
			'title'    => __( 'Welcome to ReplicaForge', 'replicaforge' ),
			'tagline'  => __( 'Turn public website references into editable Elementor layouts.', 'replicaforge' ),
			'steps'    => array(
				array(
					'id'    => 'analyze',
					'label' => __( 'Analyze', 'replicaforge' ),
					'body'  => __( 'Read the public frontend of a page and record its structure, components, assets, and design tokens.', 'replicaforge' ),
				),
				array(
					'id'    => 'understand',
					'label' => __( 'Understand', 'replicaforge' ),
					'body'  => __( 'Turn those observations into a reconstruction plan that names every section and says where each value came from.', 'replicaforge' ),
				),
				array(
					'id'    => 'reconstruct',
					'label' => __( 'Reconstruct', 'replicaforge' ),
					'body'  => __( 'Build an editable Elementor draft from the plan, as a WordPress draft.', 'replicaforge' ),
				),
				array(
					'id'    => 'validate',
					'label' => __( 'Validate', 'replicaforge' ),
					'body'  => __( 'Measure the differences between the source and the draft, per viewport and per category.', 'replicaforge' ),
				),
				array(
					'id'    => 'improve',
					'label' => __( 'Improve', 'replicaforge' ),
					'body'  => __( 'Propose corrections for the differences that can be measured, and show each one before it is written.', 'replicaforge' ),
				),
				array(
					'id'    => 'monitor',
					'label' => __( 'Monitor', 'replicaforge' ),
					'body'  => __( 'Notice when the source page changes and review what a rebuild would alter.', 'replicaforge' ),
				),
			),
			'actions'  => array(
				'primary' => array(
					'label' => __( 'Create Your First Replica', 'replicaforge' ),
					'url'   => admin_url( 'admin.php?page=replicaforge-new' ),
				),
				'secondary' => array(
					'label' => __( 'Explore Demo', 'replicaforge' ),
					'url'   => admin_url( 'admin.php?page=replicaforge-demo' ),
					'note'  => __( 'Demo mode is not available in this build.', 'replicaforge' ),
					'available' => false,
				),
				'tertiary' => array(
					'label' => __( 'Skip Setup', 'replicaforge' ),
					'action' => 'skip',
				),
			),
			// Stated plainly rather than implied: nothing has been analyzed,
			// generated, or requested from the source website.
			'does_nothing_yet' => __( 'Nothing has been analyzed or generated. ReplicaForge will not contact a website until you ask it to.', 'replicaforge' ),
		);
	}

	/**
	 * Return the product notice shown on the plans screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function product_notice() {
		$licenses = new License_Manager();
		$billing  = $licenses->billing();

		return array(
			'title'       => __( 'About plans on this site', 'replicaforge' ),
			'body'        => ( null === $billing )
				? __( 'This installation has no billing provider connected. Plans are a local configuration: an administrator can change the plan for this site, and ReplicaForge enforces it. No payment is taken and no purchase is simulated.', 'replicaforge' )
				: __( 'A billing provider is connected. Plans and purchases are handled by that provider.', 'replicaforge' ),
			'billing_configured' => ( null !== $billing ),
			'license_state' => $licenses->state()->effective_name(),
			'license_remote' => (bool) $licenses->provider()->is_remote(),
		);
	}

	/**
	 * Return the content/legal notice.
	 *
	 * §45 requires the plugin to distinguish structural analysis from content and
	 * asset reuse, and not to present public accessibility as permission to copy.
	 * The distinction is the whole notice: ReplicaForge records *shape*, and
	 * responsibility for *material* stays with the user.
	 *
	 * @return array<string, mixed>
	 */
	public static function content_notice() {
		return array(
			'title' => __( 'What ReplicaForge analyzes, and what it does not', 'replicaforge' ),
			'scope' => array(
				'title' => __( 'Structural analysis', 'replicaforge' ),
				'body'  => __( 'ReplicaForge records the shape of a public page: its element structure, layout relationships, typography and colour values, spacing, and the locations of its assets. That is what a reconstruction is built from.', 'replicaforge' ),
			),
			'not_covered' => array(
				'title' => __( 'Content and asset reuse', 'replicaforge' ),
				'body'  => __( 'Text, images, logos, product information, and trademarks belong to whoever made them. A page being publicly reachable is not permission to copy it. Reproducing any of that is your responsibility, and you are the one who has to hold the right to do it.', 'replicaforge' ),
			),
			'legal' => __( 'You are responsible for ensuring you have permission to reproduce or reuse copyrighted text, images, logos, product information, trademarks, and other proprietary content.', 'replicaforge' ),
		);
	}
}
