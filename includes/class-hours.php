<?php
/**
 * Pegasus Botify — Hours module.
 *
 * Deliberately WordPress-independent: this class uses only native PHP (DateTime /
 * DateTimeZone) so it can be unit-tested with plain PHPUnit and no WP bootstrap. The
 * CMB2 → array adapter that feeds it lives WP-side (see pegasus_botify_get_hours() in
 * the main plugin file).
 *
 * Responsibilities (build spec §7, §3.5, §3.6):
 *   - Normalize/serialize hours to the §7 JSON schema.
 *   - Validate that schema.
 *   - Compute open-state (is_open / closes_at / next_open_at) + a plain-English summary,
 *     DST-correctly, so the model never has to do date math (§3.5) and IANA timezone
 *     names are honored across DST transitions (§3.6).
 *
 * @package pegasus-botify
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PEGASUS_BOTIFY_HOURS_TEST' ) ) {
	// Allow direct include only under the test harness; otherwise require WP context.
	exit;
}

class Pegasus_Botify_Hours {

	/** Current schema version. */
	const VERSION = 1;

	/** Ordered weekday keys, matching the §7 schema. */
	const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/** Regex for a zero-padded 24-hour time, "HH:MM". */
	const TIME_RE = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

	/** Regex for an ISO date, "YYYY-MM-DD". */
	const DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';

	/** @var string IANA timezone name. */
	private $timezone;

	/** @var array<string, array<int, array{0:string,1:string}>> regular hours by day key. */
	private $regular;

	/** @var array<int, array> exception rows. */
	private $exceptions;

	/** @var array<string, array> exceptions indexed by date for fast lookup. */
	private $exceptions_by_date = array();

	/**
	 * @param array $config Normalized hours config (may be partial; missing days become closed).
	 */
	public function __construct( array $config ) {
		$this->timezone   = isset( $config['timezone'] ) ? (string) $config['timezone'] : 'UTC';
		$this->regular    = $this->normalize_regular( isset( $config['regular'] ) ? $config['regular'] : array() );
		$this->exceptions = $this->normalize_exceptions( isset( $config['exceptions'] ) ? $config['exceptions'] : array() );

		foreach ( $this->exceptions as $ex ) {
			// Last write wins if the same date appears twice.
			$this->exceptions_by_date[ $ex['date'] ] = $ex;
		}
	}

	/* ---------------------------------------------------------------------
	 * Normalization
	 * ------------------------------------------------------------------- */

	/**
	 * Ensure every weekday key exists (empty array = closed) and intervals are sorted.
	 *
	 * @param array $regular
	 * @return array
	 */
	private function normalize_regular( $regular ) {
		$out = array();
		foreach ( self::DAYS as $day ) {
			$intervals = array();
			if ( isset( $regular[ $day ] ) && is_array( $regular[ $day ] ) ) {
				foreach ( $regular[ $day ] as $interval ) {
					if ( is_array( $interval ) && isset( $interval[0], $interval[1] ) ) {
						$intervals[] = array( (string) $interval[0], (string) $interval[1] );
					}
				}
			}
			usort( $intervals, function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			} );
			$out[ $day ] = $intervals;
		}
		return $out;
	}

	/**
	 * Normalize exception rows to { date, closed, hours[], label }.
	 *
	 * @param array $exceptions
	 * @return array
	 */
	private function normalize_exceptions( $exceptions ) {
		$out = array();
		if ( ! is_array( $exceptions ) ) {
			return $out;
		}
		foreach ( $exceptions as $ex ) {
			if ( ! is_array( $ex ) || empty( $ex['date'] ) ) {
				continue;
			}
			$closed = ! empty( $ex['closed'] );
			$hours  = array();
			if ( ! $closed && isset( $ex['hours'] ) && is_array( $ex['hours'] ) ) {
				foreach ( $ex['hours'] as $interval ) {
					if ( is_array( $interval ) && isset( $interval[0], $interval[1] ) ) {
						$hours[] = array( (string) $interval[0], (string) $interval[1] );
					}
				}
			}
			usort( $hours, function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			} );
			$out[] = array(
				'date'   => (string) $ex['date'],
				'closed' => $closed,
				'hours'  => $hours,
				'label'  => isset( $ex['label'] ) ? (string) $ex['label'] : '',
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Serialization
	 * ------------------------------------------------------------------- */

	public function timezone() {
		return $this->timezone;
	}

	/**
	 * The §7 schema as an array.
	 *
	 * @return array
	 */
	public function to_array() {
		$exceptions = array();
		foreach ( $this->exceptions as $ex ) {
			$row = array( 'date' => $ex['date'] );
			if ( $ex['closed'] ) {
				$row['closed'] = true;
			} else {
				$row['hours'] = $ex['hours'];
			}
			if ( '' !== $ex['label'] ) {
				$row['label'] = $ex['label'];
			}
			$exceptions[] = $row;
		}

		return array(
			'version'    => self::VERSION,
			'timezone'   => $this->timezone,
			'regular'    => $this->regular,
			'exceptions' => $exceptions,
		);
	}

	/**
	 * The §7 schema as JSON.
	 *
	 * @param int $flags json_encode flags.
	 * @return string
	 */
	public function to_json( $flags = 0 ) {
		return json_encode( $this->to_array(), $flags );
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Validate the config. Returns a list of human-readable error strings; empty = valid.
	 *
	 * @return string[]
	 */
	public function validate() {
		$errors = array();

		// Timezone must be a real IANA name.
		if ( ! $this->is_valid_timezone( $this->timezone ) ) {
			$errors[] = sprintf( 'Invalid timezone: "%s".', $this->timezone );
		}

		// Regular hours.
		foreach ( self::DAYS as $day ) {
			foreach ( $this->regular[ $day ] as $i => $interval ) {
				$errors = array_merge(
					$errors,
					$this->validate_interval( $interval, sprintf( 'regular.%s[%d]', $day, $i ) )
				);
			}
		}

		// Exceptions.
		foreach ( $this->exceptions as $i => $ex ) {
			$ctx = sprintf( 'exceptions[%d]', $i );
			if ( ! preg_match( self::DATE_RE, $ex['date'] ) || ! $this->is_real_date( $ex['date'] ) ) {
				$errors[] = sprintf( '%s: invalid date "%s" (expected YYYY-MM-DD).', $ctx, $ex['date'] );
			}
			if ( ! $ex['closed'] && empty( $ex['hours'] ) ) {
				$errors[] = sprintf( '%s: must be either closed or have at least one hours interval.', $ctx );
			}
			foreach ( $ex['hours'] as $j => $interval ) {
				$errors = array_merge(
					$errors,
					$this->validate_interval( $interval, sprintf( '%s.hours[%d]', $ctx, $j ) )
				);
			}
		}

		return $errors;
	}

	public function is_valid() {
		return array() === $this->validate();
	}

	/**
	 * @param array  $interval [open, close]
	 * @param string $ctx      context label for error messages
	 * @return string[]
	 */
	private function validate_interval( $interval, $ctx ) {
		$errors = array();
		list( $open, $close ) = $interval;
		if ( ! preg_match( self::TIME_RE, $open ) ) {
			$errors[] = sprintf( '%s: invalid open time "%s" (expected zero-padded HH:MM).', $ctx, $open );
		}
		if ( ! preg_match( self::TIME_RE, $close ) ) {
			$errors[] = sprintf( '%s: invalid close time "%s" (expected zero-padded HH:MM).', $ctx, $close );
		}
		if ( preg_match( self::TIME_RE, $open ) && preg_match( self::TIME_RE, $close ) && $open >= $close ) {
			$errors[] = sprintf( '%s: open "%s" must be before close "%s".', $ctx, $open, $close );
		}
		return $errors;
	}

	private function is_valid_timezone( $tz ) {
		if ( ! is_string( $tz ) || '' === $tz ) {
			return false;
		}
		return in_array( $tz, timezone_identifiers_list(), true );
	}

	private function is_real_date( $date ) {
		$parts = explode( '-', $date );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		list( $y, $m, $d ) = array_map( 'intval', $parts );
		return checkdate( $m, $d, $y );
	}

	/* ---------------------------------------------------------------------
	 * Open-state computation (DST-safe)
	 * ------------------------------------------------------------------- */

	/**
	 * Compute open-state for a given moment.
	 *
	 * @param DateTimeInterface|null $now Moment to evaluate. Defaults to "now". Any timezone
	 *                                    is accepted; it is converted into the business tz.
	 * @return array{is_open:bool, closes_at:?string, next_open_at:?string, summary:string, timezone:string, now_local:string}
	 */
	public function get_state( DateTimeInterface $now = null ) {
		$tz = new DateTimeZone( $this->is_valid_timezone( $this->timezone ) ? $this->timezone : 'UTC' );

		if ( null === $now ) {
			$now = new DateTimeImmutable( 'now', $tz );
		} else {
			// Work off an immutable copy in the business timezone.
			$now = ( $now instanceof DateTimeImmutable ) ? $now : DateTimeImmutable::createFromInterface( $now );
			$now = $now->setTimezone( $tz );
		}

		$now_hm    = $now->format( 'H:i' );
		$intervals = $this->intervals_for_date( $now, $tz );

		$is_open    = false;
		$closes_at  = null;
		foreach ( $intervals as $interval ) {
			list( $open, $close ) = $interval;
			if ( $now_hm >= $open && $now_hm < $close ) {
				$is_open   = true;
				$closes_at = $this->human_time( $close );
				break;
			}
		}

		$next_open_at = null;
		if ( ! $is_open ) {
			$next         = $this->find_next_open( $now, $tz );
			$next_open_at = $next ? $this->human_datetime( $now, $next ) : null;
		}

		return array(
			'is_open'      => $is_open,
			'closes_at'    => $closes_at,
			'next_open_at' => $next_open_at,
			'summary'      => $this->summary(),
			'timezone'     => $this->timezone,
			'now_local'    => $now->format( 'Y-m-d H:i' ),
		);
	}

	/**
	 * Effective intervals for a given date: an exception for that date overrides the
	 * regular weekly schedule entirely.
	 *
	 * @param DateTimeImmutable $date
	 * @param DateTimeZone      $tz (unused here but kept for symmetry / future use)
	 * @return array<int, array{0:string,1:string}>
	 */
	private function intervals_for_date( DateTimeImmutable $date, DateTimeZone $tz ) {
		$key = $date->format( 'Y-m-d' );
		if ( isset( $this->exceptions_by_date[ $key ] ) ) {
			$ex = $this->exceptions_by_date[ $key ];
			return $ex['closed'] ? array() : $ex['hours'];
		}
		$weekday = strtolower( $date->format( 'D' ) ); // mon, tue, ...
		return isset( $this->regular[ $weekday ] ) ? $this->regular[ $weekday ] : array();
	}

	/**
	 * Find the next opening instant at or after $now, scanning up to 14 days ahead.
	 *
	 * DST-correct: each candidate opening instant is constructed from a local wall-clock
	 * date + time in the business timezone, so PHP resolves the correct UTC offset for
	 * that date (which may differ from today's across a DST boundary).
	 *
	 * @param DateTimeImmutable $now
	 * @param DateTimeZone      $tz
	 * @return DateTimeImmutable|null
	 */
	private function find_next_open( DateTimeImmutable $now, DateTimeZone $tz ) {
		$now_hm = $now->format( 'H:i' );

		for ( $offset = 0; $offset <= 14; $offset++ ) {
			$day       = $now->modify( sprintf( '+%d day', $offset ) );
			$intervals = $this->intervals_for_date( $day, $tz );
			foreach ( $intervals as $interval ) {
				$open = $interval[0];
				// On day 0 only future openings count.
				if ( 0 === $offset && $open <= $now_hm ) {
					continue;
				}
				return new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $open . ':00', $tz );
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Human-readable formatting
	 * ------------------------------------------------------------------- */

	/**
	 * "17:00" → "5:00 PM".
	 *
	 * @param string $hm
	 * @return string
	 */
	private function human_time( $hm ) {
		$dt = DateTimeImmutable::createFromFormat( '!H:i', $hm );
		return $dt ? $dt->format( 'g:i A' ) : $hm;
	}

	/**
	 * Human string for the next opening, relative to $now: "today at 1:00 PM",
	 * "tomorrow at 9:00 AM", or "Monday at 9:00 AM".
	 *
	 * @param DateTimeImmutable $now
	 * @param DateTimeImmutable $next
	 * @return string
	 */
	private function human_datetime( DateTimeImmutable $now, DateTimeImmutable $next ) {
		$today    = $now->format( 'Y-m-d' );
		$tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
		$next_day = $next->format( 'Y-m-d' );
		$time     = $next->format( 'g:i A' );

		if ( $next_day === $today ) {
			return sprintf( 'today at %s', $time );
		}
		if ( $next_day === $tomorrow ) {
			return sprintf( 'tomorrow at %s', $time );
		}
		return sprintf( '%s at %s', $next->format( 'l' ), $time );
	}

	/**
	 * Plain-English weekly hours summary, one line per day.
	 *
	 * @return string
	 */
	public function summary() {
		$labels = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);

		$lines = array();
		foreach ( self::DAYS as $day ) {
			$intervals = $this->regular[ $day ];
			if ( empty( $intervals ) ) {
				$lines[] = sprintf( '%s: Closed', $labels[ $day ] );
				continue;
			}
			$parts = array();
			foreach ( $intervals as $interval ) {
				$parts[] = sprintf( '%s–%s', $this->human_time( $interval[0] ), $this->human_time( $interval[1] ) );
			}
			$lines[] = sprintf( '%s: %s', $labels[ $day ], implode( ', ', $parts ) );
		}
		return implode( "\n", $lines );
	}
}
