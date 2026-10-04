<?php
/**
 * Phase 7: structured, bounded, redacted logging.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * A ReplicaForge log a human can actually read and an administrator can clear.
 *
 * Entries are structured, redacted, and bounded. The log is stored in a single
 * option so it is one row rather than one per entry, which keeps the queries
 * cheap and the rotation trivial. Redaction happens here rather than at each call
 * site, so a new call site cannot leak a secret by forgetting.
 */
final class Logger {

	/**
	 * Option holding the log.
	 */
	const OPTION = 'replicaforge_log';

	/**
	 * Log levels, least to most severe.
	 *
	 * @var array<int, string>
	 */
	const LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/**
	 * Default maximum entries kept.
	 */
	const DEFAULT_LIMIT = 500;

	/**
	 * Absolute ceiling, so a setting cannot raise retention without bound.
	 */
	const MAX_LIMIT = 5000;

	/**
	 * Maximum characters kept in one entry's message or context.
	 */
	const MAX_VALUE = 2000;

	/**
	 * Configured entry limit.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Minimum level that is recorded.
	 *
	 * @var string
	 */
	private $minimum_level;

	/**
	 * Constructor.
	 *
	 * @param int|null    $limit         Entries to keep.
	 * @param string|null $minimum_level Minimum level to record.
	 */
	public function __construct( $limit = null, $minimum_level = null ) {
		$this->limit = self::clamp_limit( $limit );
		if ( is_string( $minimum_level ) && in_array( $minimum_level, self::LEVELS, true ) ) {
			$this->minimum_level = $minimum_level;
		} else {
			$settings = get_option( 'replicaforge_settings', array() );
			$stored   = is_array( $settings ) && isset( $settings['log_level'] ) ? (string) $settings['log_level'] : '';
			$this->minimum_level = in_array( $stored, self::LEVELS, true ) ? $stored : self::default_level();
		}
	}

	/**
	 * Return the default minimum level.
	 *
	 * A site with debugging on records everything; otherwise `info` is enough for a
	 * support conversation and keeps the option small.
	 *
	 * @return string
	 */
	public static function default_level() {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'debug' : 'info';
	}

	/**
	 * Clamp a requested entry limit into the allowed range.
	 *
	 * @param mixed $limit Requested limit.
	 * @return int
	 */
	public static function clamp_limit( $limit ) {
		$limit = is_numeric( $limit ) ? (int) $limit : self::DEFAULT_LIMIT;
		return max( 50, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * Record an entry.
	 *
	 * @param string $level    One of the declared levels.
	 * @param string $event    Stable machine event name.
	 * @param string $message  Human-readable message.
	 * @param array  $context  Bounded, redacted context.
	 * @param string $category Optional category; defaults to the level's bucket.
	 * @return bool True when the entry was stored.
	 */
	public function log( $level, $event, $message = '', array $context = array(), $category = '' ) {
		$level = is_string( $level ) && in_array( $level, self::LEVELS, true ) ? $level : 'info';
		if ( array_search( $level, self::LEVELS, true ) < array_search( $this->minimum_level, self::LEVELS, true ) ) {
			return false;
		}

		$entry = array(
			'time'      => gmdate( 'c' ),
			'level'     => $level,
			'event'     => self::event_name( $event ),
			'category'  => self::event_name( $category ),
			'message'   => self::bound( $message ),
			'user_id'   => get_current_user_id(),
			'context'   => Data_Redactor::payload( $context ),
			'request_id' => Request_Context::request_id(),
		);

		$meta = Request_Context::meta();
		foreach ( $meta as $key => $value ) {
			if ( 'request_id' === $key ) {
				continue;
			}
			$entry['context'][ $key ] = $value;
		}

		$entries   = $this->all();
		$entries[] = $entry;
		if ( count( $entries ) > $this->limit ) {
			$entries = array_slice( $entries, -$this->limit );
		}
		update_option( self::OPTION, $entries, false );

		return true;
	}

	/**
	 * Record a debug entry.
	 *
	 * @param string $event   Event name.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return bool
	 */
	public function debug( $event, $message = '', array $context = array() ) {
		return $this->log( 'debug', $event, $message, $context, 'system' );
	}

	/**
	 * Record an info entry.
	 *
	 * @param string $event    Event name.
	 * @param string $message  Message.
	 * @param array  $context  Context.
	 * @param string $category Category.
	 * @return bool
	 */
	public function info( $event, $message = '', array $context = array(), $category = 'system' ) {
		return $this->log( 'info', $event, $message, $context, $category );
	}

	/**
	 * Record a warning entry.
	 *
	 * @param string $event    Event name.
	 * @param string $message  Message.
	 * @param array  $context  Context.
	 * @param string $category Category.
	 * @return bool
	 */
	public function warning( $event, $message = '', array $context = array(), $category = 'system' ) {
		return $this->log( 'warning', $event, $message, $context, $category );
	}

	/**
	 * Record an error entry.
	 *
	 * @param string $event    Event name.
	 * @param string $message  Message.
	 * @param array  $context  Context.
	 * @param string $category Category.
	 * @return bool
	 */
	public function error( $event, $message = '', array $context = array(), $category = 'system' ) {
		return $this->log( 'error', $event, $message, $context, $category );
	}

	/**
	 * Record a critical entry.
	 *
	 * @param string $event    Event name.
	 * @param string $message  Message.
	 * @param array  $context  Context.
	 * @param string $category Category.
	 * @return bool
	 */
	public function critical( $event, $message = '', array $context = array(), $category = 'system' ) {
		return $this->log( 'critical', $event, $message, $context, $category );
	}

	/**
	 * Return every stored entry, newest last.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$entries = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['time'], $entry['level'], $entry['event'] ) ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Return stored entries, newest first, filtered.
	 *
	 * @param array<string, mixed> $filters Optional `level`, `category`, `event`, `since`, `search`.
	 * @param int                  $limit  Maximum entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( array $filters = array(), $limit = 100 ) {
		$entries = array_reverse( $this->all() );
		$level   = isset( $filters['level'] ) ? (string) $filters['level'] : '';
		$event   = isset( $filters['event'] ) ? (string) $filters['event'] : '';
		$since   = isset( $filters['since'] ) ? (string) $filters['since'] : '';
		$search  = isset( $filters['search'] ) ? strtolower( trim( (string) $filters['search'] ) ) : '';

		$filtered = array();
		foreach ( $entries as $entry ) {
			if ( '' !== $level && ( $entry['level'] ?? '' ) !== $level ) {
				continue;
			}
			if ( '' !== $event && false === strpos( (string) ( $entry['event'] ?? '' ), $event ) ) {
				continue;
			}
			if ( '' !== $since && strtotime( (string) ( $entry['time'] ?? '' ) ) < strtotime( $since ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = strtolower(
					(string) ( $entry['event'] ?? '' ) . ' ' . (string) ( $entry['message'] ?? '' ) . ' '
					. (string) wp_json_encode( $entry['context'] ?? array() )
				);
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}
			$filtered[] = $entry;
			if ( count( $filtered ) >= max( 1, (int) $limit ) ) {
				break;
			}
		}
		return $filtered;
	}

	/**
	 * Return a summary for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public function summary() {
		$entries = $this->all();
		$counts  = array_fill_keys( self::LEVELS, 0 );
		$latest  = '';
		foreach ( $entries as $entry ) {
			$level = (string) ( $entry['level'] ?? '' );
			if ( isset( $counts[ $level ] ) ) {
				$counts[ $level ]++;
			}
			if ( '' === $latest ) {
				$latest = (string) ( $entry['time'] ?? '' );
			}
		}
		return array(
			'total'  => count( $entries ),
			'levels' => $counts,
			'limit'  => $this->limit,
			'latest' => $latest,
		);
	}

	/**
	 * Delete every entry.
	 *
	 * @return bool
	 */
	public function clear() {
		return delete_option( self::OPTION );
	}

	/**
	 * Delete entries older than a cutoff, keeping the rest.
	 *
	 * @param int $days Retention in days.
	 * @return int Number of entries removed.
	 */
	public function prune( $days = 30 ) {
		$days    = max( 1, (int) $days );
		$entries = $this->all();
		$kept    = array();
		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$removed = 0;
		foreach ( $entries as $entry ) {
			if ( strtotime( (string) ( $entry['time'] ?? '' ) ) < $cutoff ) {
				$removed++;
				continue;
			}
			$kept[] = $entry;
		}
		if ( $removed > 0 ) {
			update_option( self::OPTION, $kept, false );
		}
		return $removed;
	}

	/**
	 * Return the log as a newline-delimited JSON document.
	 *
	 * The export is redacted again on the way out, so an entry that somehow
	 * captured a secret before the redactor existed is still not exported.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit  Maximum entries.
	 * @return string
	 */
	public function export( array $filters = array(), $limit = 500 ) {
		$lines = array();
		foreach ( $this->recent( $filters, $limit ) as $entry ) {
			$safe = array(
				'time'       => (string) ( $entry['time'] ?? '' ),
				'level'      => (string) ( $entry['level'] ?? '' ),
				'event'      => (string) ( $entry['event'] ?? '' ),
				'category'   => (string) ( $entry['category'] ?? '' ),
				'message'    => Data_Redactor::text( (string) ( $entry['message'] ?? '' ) ),
				'user_id'    => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0,
				'request_id' => (string) ( $entry['request_id'] ?? '' ),
				'context'    => Data_Redactor::payload( $entry['context'] ?? array() ),
			);
			$encoded = wp_json_encode( $safe );
			if ( is_string( $encoded ) ) {
				$lines[] = $encoded;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Bound one string value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function bound( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = Data_Redactor::text( (string) $value );
		return strlen( $text ) > self::MAX_VALUE ? substr( $text, 0, self::MAX_VALUE ) . '…' : $text;
	}

	/**
	 * Normalize an event or category name.
	 *
	 * @param mixed $value Raw name.
	 * @return string
	 */
	private static function event_name( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$name = strtolower( trim( $value ) );
		$name = preg_replace( '/[^a-z0-9_.:-]/', '_', $name );
		return is_string( $name ) ? substr( trim( $name, '_' ), 0, 60 ) : '';
	}
}
