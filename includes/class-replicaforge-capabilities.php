<?php
/**
 * Phase 10: capability definitions and grants.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Grants and checks the custom capabilities Phase 10 needs.
 *
 * Before this phase the plugin had **no** capabilities of its own. Every check was
 * either `manage_options` — which only an administrator has, so the plugin was
 * administrator-only by accident rather than by decision — or `edit_pages` /
 * `edit_post`, which says nothing about whether the person may run a reconstruction.
 * That is why §26 and §27 could not be satisfied: there was no way to express "may
 * use ReplicaForge" as distinct from "may administer WordPress".
 *
 * The existing checks are deliberately **not** rewritten. They continue to work,
 * which is what keeps Phases 1 to 9 intact. The new capabilities are an
 * additional, narrower layer applied at the Phase 10 endpoints, and they are
 * granted to roles on activation and on migration so that an installation upgrading
 * from Phases 1 to 9 starts with the same permissions its users already implied.
 *
 * Granting is conservative. An administrator gets everything because administering
 * the site implies administering a plugin on it. An editor gets the two operational
 * capabilities and not the three management ones. Authors and contributors get
 * nothing, because a replica is an Elementor page and an author who cannot edit
 * pages cannot usefully generate one.
 */
final class Capabilities {

	/**
	 * Capabilities granted to an administrator.
	 *
	 * @var array<int, string>
	 */
	const ADMIN_CAPS = array(
		'replicaforge_use',
		'replicaforge_generate',
		'replicaforge_manage_projects',
		'replicaforge_manage_settings',
		'replicaforge_manage_plans',
	);

	/**
	 * Capabilities granted to an editor.
	 *
	 * @var array<int, string>
	 */
	const EDITOR_CAPS = array(
		'replicaforge_use',
		'replicaforge_generate',
	);

	/**
	 * The role grants applied on activation and on migration.
	 *
	 * @var array<string, array<int, string>>
	 */
	const ROLE_GRANTS = array(
		'administrator' => self::ADMIN_CAPS,
		'editor'        => self::EDITOR_CAPS,
	);

	/**
	 * Return whether a value is a declared capability.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return is_string( $value ) && isset( Plan_Limits::CAPABILITIES[ $value ] );
	}

	/**
	 * Return every declared capability with its labels.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function all() {
		return Plan_Limits::CAPABILITIES;
	}

	/**
	 * Return the capability that must be held for an operation.
	 *
	 * Generation is separate from use on purpose. §27 asks for the distinction and
	 * there is a real case for it: a site owner may want editors to analyse and
	 * validate while an experienced person handles the step that writes an
	 * Elementor document.
	 *
	 * @param string $operation Operation name.
	 * @return string Empty string for an unmapped operation.
	 */
	public static function for_operation( $operation ) {
		$map = array(
			'analysis'       => 'replicaforge_use',
			'ai_analysis'    => 'replicaforge_use',
			'validation'     => 'replicaforge_use',
			'correction'     => 'replicaforge_generate',
			'generation'     => 'replicaforge_generate',
			'sync_operation' => 'replicaforge_use',
			'export'         => 'replicaforge_use',
			'import'         => 'replicaforge_generate',
		);

		$operation = is_string( $operation ) ? strtolower( trim( $operation ) ) : '';
		return isset( $map[ $operation ] ) ? $map[ $operation ] : 'replicaforge_use';
	}

	/**
	 * Return whether the current user holds a ReplicaForge capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public static function current_user_can( $capability ) {
		if ( ! self::is_valid( $capability ) ) {
			return false;
		}
		return current_user_can( $capability );
	}

	/**
	 * Return whether a user holds a ReplicaForge capability.
	 *
	 * @param int    $user_id    User id.
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public static function user_can( $user_id, $capability ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! self::is_valid( $capability ) ) {
			return false;
		}
		return user_can( $user_id, $capability );
	}

	/**
	 * Return whether a user may administer ReplicaForge.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function user_is_manager( $user_id ) {
		return self::user_can( $user_id, 'replicaforge_manage_settings' );
	}

	/**
	 * Grant the capabilities to the roles that should hold them.
	 *
	 * Roles are granted rather than users, because roles are what an installation
	 * already administers. Granting to individual users would leave a set of users
	 * holding a capability no role explains, and a capability that appears in
	 * nobody's role list cannot be audited in the WordPress UI.
	 *
	 * A role that does not exist on this site is skipped, which is what makes this
	 * correct on a site with a custom role set and on multisite where the
	 * administrator role may have been renamed.
	 *
	 * @return array<string, int> Capability count added, per role.
	 */
	public static function grant_default_roles() {
		$added = array();

		foreach ( self::ROLE_GRANTS as $role_name => $capabilities ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			$count = 0;
			foreach ( $capabilities as $capability ) {
				if ( ! $role->has_cap( $capability ) ) {
					$role->add_cap( $capability, true );
					$count++;
				}
			}
			if ( $count > 0 ) {
				$added[ $role_name ] = $count;
			}
		}

		return $added;
	}

	/**
	 * Remove every ReplicaForge capability from every role.
	 *
	 * Called on uninstall, not on deactivation. Deactivation must not change who
	 * can do what in the database — a user deactivating and reactivating the plugin
	 * should not find their permissions quietly rewritten — and this is the one
	 * place where removing the caps is unambiguous.
	 *
	 * @return int Number of roles changed.
	 */
	public static function revoke_all_roles() {
		$roles    = wp_roles();
		$changed  = 0;
		$declared = array_keys( Plan_Limits::CAPABILITIES );

		foreach ( array_keys( $roles->get_names() ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			$removed = false;
			foreach ( $declared as $capability ) {
				if ( $role->has_cap( $capability ) ) {
					$role->remove_cap( $capability );
					$removed = true;
				}
			}
			if ( $removed ) {
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * Return a report of which roles hold which capabilities.
	 *
	 * Used by the system status screen, so an administrator can see the actual
	 * state rather than the intended one.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function role_report() {
		$out   = array();
		$roles = wp_roles();

		foreach ( array_keys( $roles->get_names() ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			$row = array();
			foreach ( array_keys( Plan_Limits::CAPABILITIES ) as $capability ) {
				$row[ $capability ] = (bool) $role->has_cap( $capability );
			}
			$out[ $role_name ] = $row;
		}

		return $out;
	}

	/**
	 * Return whether every declared capability has been granted somewhere.
	 *
	 * A capability no role holds is a capability nobody can pass, and the symptom
	 * appears as every user being refused with no explanation.
	 *
	 * @return array{ok: bool, orphaned: array<int, string>}
	 */
	public static function orphan_check() {
		$report = self::role_report();
		$owned  = array();

		foreach ( $report as $row ) {
			foreach ( $row as $capability => $granted ) {
				if ( $granted ) {
					$owned[ $capability ] = true;
				}
			}
		}

		$orphaned = array();
		foreach ( array_keys( Plan_Limits::CAPABILITIES ) as $capability ) {
			if ( ! isset( $owned[ $capability ] ) ) {
				$orphaned[] = $capability;
			}
		}

		return array(
			'ok'       => array() === $orphaned,
			'orphaned' => $orphaned,
		);
	}
}
