<?php
/**
 * Phase 10: one plan definition.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * A single plan: what it allows and how much of it.
 *
 * This is a value object, not a service. It holds no state, reads no options, and
 * decides nothing — it describes a plan and answers questions about that description.
 * Keeping it inert is what makes the plan set configurable without the rest of the
 * plugin knowing where the numbers came from.
 *
 * The default definitions live in `Plan_Storage` and are filterable, so an
 * installation can change them without editing code. That matters because the brief
 * is explicit that these values must not be permanently hard-coded: a price point is
 * a commercial decision that changes, and a plan system that requires a code change
 * to change a limit is a plan system that will be forked.
 */
final class Plan_Definition {

	/**
	 * Plan identifier.
	 *
	 * @var string
	 */
	private $plan_id;

	/**
	 * Plan data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string               $plan_id Plan identifier.
	 * @param array<string, mixed> $data    Plan data.
	 */
	public function __construct( $plan_id, array $data ) {
		$this->plan_id = self::clean_id( $plan_id );
		$this->data    = $this->normalize( $data );
	}

	/**
	 * Build a definition from a plan id and data.
	 *
	 * @param string               $plan_id Plan identifier.
	 * @param array<string, mixed> $data    Plan data.
	 * @return self|null Null when the plan id is not usable.
	 */
	public static function create( $plan_id, array $data ) {
		$plan_id = self::clean_id( $plan_id );
		if ( '' === $plan_id ) {
			return null;
		}
		return new self( $plan_id, $data );
	}

	/**
	 * Return the plan identifier.
	 *
	 * @return string
	 */
	public function id() {
		return $this->plan_id;
	}

	/**
	 * Return the display name.
	 *
	 * @return string
	 */
	public function name() {
		$name = isset( $this->data['name'] ) ? (string) $this->data['name'] : '';
		return '' !== $name ? $name : $this->plan_id;
	}

	/**
	 * Return the one-line description.
	 *
	 * @return string
	 */
	public function description() {
		return isset( $this->data['description'] ) ? (string) $this->data['description'] : '';
	}

	/**
	 * Return the plan's rank in the declared order.
	 *
	 * @return int
	 */
	public function rank() {
		return Plan_Limits::plan_rank( $this->plan_id );
	}

	/**
	 * Return every limit value.
	 *
	 * @return array<string, int>
	 */
	public function limits() {
		$limits = isset( $this->data['limits'] ) && is_array( $this->data['limits'] ) ? $this->data['limits'] : array();
		$out    = array();
		foreach ( $limits as $name => $value ) {
			if ( Plan_Limits::is_limit( $name ) && is_numeric( $value ) ) {
				// A negative value other than the declared unlimited marker is a
				// configuration mistake, and treating it as unlimited would silently
				// grant more than the plan says. It becomes a limit of zero instead,
				// which is the conservative direction to fail.
				$out[ $name ] = ( (int) $value < 0 && (int) $value !== Plan_Limits::UNLIMITED )
					? 0
					: (int) $value;
			}
		}
		return $out;
	}

	/**
	 * Return one limit value.
	 *
	 * An absent limit is **unlimited**, not zero. A plan that does not mention a limit
	 * is not saying "none allowed"; it is saying it has not thought about it, and
	 * failing closed there would break every plan the author forgot to complete.
	 *
	 * @param string $limit Limit name.
	 * @return int
	 */
	public function limit( $limit ) {
		$limits = $this->limits();
		return array_key_exists( $limit, $limits ) ? (int) $limits[ $limit ] : Plan_Limits::UNLIMITED;
	}

	/**
	 * Return whether a limit is unlimited.
	 *
	 * @param string $limit Limit name.
	 * @return bool
	 */
	public function is_unlimited( $limit ) {
		return Plan_Limits::is_unlimited( $this->limit( $limit ) );
	}

	/**
	 * Return every feature flag.
	 *
	 * @return array<string, bool>
	 */
	public function features() {
		$features = isset( $this->data['features'] ) && is_array( $this->data['features'] ) ? $this->data['features'] : array();
		$out      = array();
		foreach ( $features as $name => $enabled ) {
			if ( Plan_Limits::is_feature( $name ) ) {
				$out[ $name ] = (bool) $enabled;
			}
		}
		return $out;
	}

	/**
	 * Return whether a feature is granted.
	 *
	 * A feature the plan does not mention is **not granted**. That is the opposite of
	 * how an absent *limit* is read, and deliberately so: forgetting to grant a
	 * feature should not hand it out.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function allows( $feature ) {
		$features = $this->features();
		return ! empty( $features[ $feature ] );
	}

	/**
	 * Return whether an operation is permitted, ignoring its limit.
	 *
	 * @param string $operation Operation name.
	 * @return bool
	 */
	public function permits( $operation ) {
		$feature = Plan_Limits::feature_for_operation( $operation );
		if ( '' === $feature ) {
			// An operation with no feature is not permitted. An unmapped operation is
			// a gap in the mapping table, and allowing it would mean a limit nobody
			// can reach is also a feature nobody can withhold.
			return false;
		}
		return $this->allows( $feature );
	}

	/**
	 * Return the limit name for an operation.
	 *
	 * @param string $operation Operation name.
	 * @return string
	 */
	public function limit_for( $operation ) {
		return Plan_Limits::limit_for_operation( $operation );
	}

	/**
	 * Return the definition as a plain array.
	 *
	 * The shape is stable and is what the export and the REST layer return, so a
	 * consumer never has to reach into private state.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'plan_id'     => $this->plan_id,
			'name'        => $this->name(),
			'description' => $this->description(),
			'rank'        => $this->rank(),
			'limits'      => $this->limits(),
			'features'    => $this->features(),
		);
	}

	/**
	 * Return the definition for a public screen.
	 *
	 * Adds the derived facts a reader needs — which operations are permitted, and
	 * which limits are unlimited — so the screen does not reimplement that reasoning
	 * and risk disagreeing with it.
	 *
	 * @return array<string, mixed>
	 */
	public function to_public_array() {
		$out       = $this->to_array();
		$out['permitted_operations'] = array();
		foreach ( Plan_Limits::OPERATIONS as $operation ) {
			if ( $this->permits( $operation ) ) {
				$out['permitted_operations'][] = $operation;
			}
		}
		$out['unlimited_limits'] = array();
		foreach ( array_keys( $this->limits() ) as $limit ) {
			if ( Plan_Limits::is_unlimited( $this->limit( $limit ) ) ) {
				$out['unlimited_limits'][] = $limit;
			}
		}
		return $out;
	}

	/**
	 * Return the plan's editable shape, for a definition form.
	 *
	 * @return array<string, mixed>
	 */
	public function to_editable_array() {
		return array(
			'plan_id'     => $this->plan_id,
			'name'        => $this->name(),
			'description' => $this->description(),
			'limits'      => $this->limits(),
			'features'    => $this->features(),
		);
	}

	/**
	 * Normalize incoming data into the shape this class expects.
	 *
	 * Unknown keys are dropped rather than stored. A plan definition is read from an
	 * option and from an import, and carrying an unrecognised key forward is how an
	 * unvalidated value eventually reaches something that trusts it.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed>
	 */
	private function normalize( array $data ) {
		return array(
			'name'        => isset( $data['name'] ) && is_scalar( $data['name'] ) ? (string) $data['name'] : '',
			'description' => isset( $data['description'] ) && is_scalar( $data['description'] ) ? (string) $data['description'] : '',
			'limits'      => isset( $data['limits'] ) && is_array( $data['limits'] ) ? $data['limits'] : array(),
			'features'    => isset( $data['features'] ) && is_array( $data['features'] ) ? $data['features'] : array(),
		);
	}

	/**
	 * Clean a plan identifier.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function clean_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 40 ) {
			return '';
		}
		return preg_match( '/^[a-z0-9_]+$/', $value ) ? $value : '';
	}
}
