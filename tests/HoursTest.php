<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Pegasus_Botify_Hours.
 *
 * Covers split shifts, exception overrides (closed + custom hours), empty-day closures,
 * DST spring-forward and fall-back boundaries, and schema validation.
 */
class HoursTest extends TestCase {

	/** Timezone used across the behavioral tests. */
	const TZ = 'America/New_York';

	/**
	 * A representative weekly schedule:
	 *   - Monday has a split shift with a lunch closure.
	 *   - Sunday is open (so DST-boundary Sundays exercise open logic).
	 *   - Saturday is closed.
	 */
	private function base_config( array $exceptions = array() ) {
		return array(
			'timezone'   => self::TZ,
			'regular'    => array(
				'mon' => array( array( '09:00', '12:00' ), array( '13:00', '17:00' ) ),
				'tue' => array( array( '09:00', '17:00' ) ),
				'wed' => array( array( '09:00', '17:00' ) ),
				'thu' => array( array( '09:00', '17:00' ) ),
				'fri' => array( array( '09:00', '15:00' ) ),
				'sat' => array(),
				'sun' => array( array( '09:00', '17:00' ) ),
			),
			'exceptions' => $exceptions,
		);
	}

	private function at( $local, $tz = self::TZ ) {
		return new DateTimeImmutable( $local, new DateTimeZone( $tz ) );
	}

	/* ------------------------------------------------------------------ */
	/* Split shifts                                                        */
	/* ------------------------------------------------------------------ */

	public function test_open_during_morning_shift() {
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( $this->at( '2026-06-01 09:30' ) ); // Monday

		$this->assertTrue( $state['is_open'] );
		$this->assertSame( '12:00 PM', $state['closes_at'] );
		$this->assertNull( $state['next_open_at'] );
	}

	public function test_closed_during_lunch_gap_reopens_same_day() {
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( $this->at( '2026-06-01 12:30' ) ); // Monday, lunch

		$this->assertFalse( $state['is_open'] );
		$this->assertSame( 'today at 1:00 PM', $state['next_open_at'] );
	}

	public function test_open_during_afternoon_shift() {
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( $this->at( '2026-06-01 13:30' ) );

		$this->assertTrue( $state['is_open'] );
		$this->assertSame( '5:00 PM', $state['closes_at'] );
	}

	public function test_closed_at_exact_close_time() {
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( $this->at( '2026-06-01 17:00' ) ); // Monday close

		$this->assertFalse( $state['is_open'] );
		$this->assertSame( 'tomorrow at 9:00 AM', $state['next_open_at'] ); // Tuesday
	}

	/* ------------------------------------------------------------------ */
	/* Empty day                                                           */
	/* ------------------------------------------------------------------ */

	public function test_empty_day_is_closed_and_points_to_next_open() {
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( $this->at( '2026-06-06 10:00' ) ); // Saturday (closed)

		$this->assertFalse( $state['is_open'] );
		$this->assertSame( 'tomorrow at 9:00 AM', $state['next_open_at'] ); // Sunday
	}

	/* ------------------------------------------------------------------ */
	/* Exceptions                                                          */
	/* ------------------------------------------------------------------ */

	public function test_exception_closed_overrides_regular() {
		$config = $this->base_config( array(
			array( 'date' => '2026-06-01', 'closed' => true, 'label' => 'Company holiday' ),
		) );
		$hours = new Pegasus_Botify_Hours( $config );
		$state = $hours->get_state( $this->at( '2026-06-01 09:30' ) ); // normally open Monday

		$this->assertFalse( $state['is_open'] );
		$this->assertSame( 'tomorrow at 9:00 AM', $state['next_open_at'] );
	}

	public function test_exception_custom_hours_override_regular() {
		$config = $this->base_config( array(
			array( 'date' => '2026-06-02', 'hours' => array( array( '10:00', '14:00' ) ), 'label' => 'Late open' ),
		) );
		$hours = new Pegasus_Botify_Hours( $config );

		$early = $hours->get_state( $this->at( '2026-06-02 09:30' ) ); // before custom open
		$this->assertFalse( $early['is_open'] );
		$this->assertSame( 'today at 10:00 AM', $early['next_open_at'] );

		$mid = $hours->get_state( $this->at( '2026-06-02 11:00' ) );
		$this->assertTrue( $mid['is_open'] );
		$this->assertSame( '2:00 PM', $mid['closes_at'] );
	}

	/* ------------------------------------------------------------------ */
	/* DST boundaries                                                      */
	/* ------------------------------------------------------------------ */

	public function test_dst_spring_forward_next_open_wall_clock() {
		// Saturday evening (EST) → next open is Sunday 09:00, after the spring-forward.
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( $this->at( '2026-03-07 20:00' ) ); // Saturday, closed

		$this->assertFalse( $state['is_open'] );
		$this->assertSame( 'tomorrow at 9:00 AM', $state['next_open_at'] );
	}

	public function test_dst_spring_forward_utc_is_converted_with_edt_offset() {
		// 08:30 UTC on the transition day is AFTER the 07:00 UTC switch → EDT (-04:00).
		// Correct local time is 04:30; a DST-blind implementation would say 03:30.
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( new DateTimeImmutable( '2026-03-08 08:30:00', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '2026-03-08 04:30', $state['now_local'] );
	}

	public function test_dst_fall_back_utc_before_transition_uses_edt() {
		// 05:30 UTC is BEFORE the 06:00 UTC fall-back → still EDT (-04:00) → 01:30 local.
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( new DateTimeImmutable( '2026-11-01 05:30:00', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '2026-11-01 01:30', $state['now_local'] );
	}

	public function test_dst_fall_back_utc_after_transition_uses_est() {
		// 08:30 UTC is AFTER the fall-back → EST (-05:00) → 03:30 local.
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$state = $hours->get_state( new DateTimeImmutable( '2026-11-01 08:30:00', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '2026-11-01 03:30', $state['now_local'] );
	}

	/* ------------------------------------------------------------------ */
	/* Serialization                                                       */
	/* ------------------------------------------------------------------ */

	public function test_to_array_matches_schema_shape() {
		$hours = new Pegasus_Botify_Hours( $this->base_config( array(
			array( 'date' => '2026-11-26', 'closed' => true, 'label' => 'Thanksgiving' ),
			array( 'date' => '2026-12-24', 'hours' => array( array( '09:00', '13:00' ) ), 'label' => 'Christmas Eve' ),
		) ) );
		$out = $hours->to_array();

		$this->assertSame( 1, $out['version'] );
		$this->assertSame( self::TZ, $out['timezone'] );
		$this->assertCount( 7, $out['regular'] );
		$this->assertSame( array( array( '09:00', '12:00' ), array( '13:00', '17:00' ) ), $out['regular']['mon'] );
		$this->assertSame( array(), $out['regular']['sat'] );

		$this->assertTrue( $out['exceptions'][0]['closed'] );
		$this->assertArrayNotHasKey( 'hours', $out['exceptions'][0] );
		$this->assertSame( array( array( '09:00', '13:00' ) ), $out['exceptions'][1]['hours'] );
		$this->assertArrayNotHasKey( 'closed', $out['exceptions'][1] );
	}

	public function test_missing_days_normalize_to_closed() {
		$hours = new Pegasus_Botify_Hours( array(
			'timezone' => 'UTC',
			'regular'  => array( 'mon' => array( array( '09:00', '17:00' ) ) ),
		) );
		$out = $hours->to_array();

		foreach ( array( 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $day ) {
			$this->assertSame( array(), $out['regular'][ $day ], "$day should be closed" );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Validation                                                          */
	/* ------------------------------------------------------------------ */

	public function test_valid_config_has_no_errors() {
		$hours = new Pegasus_Botify_Hours( $this->base_config() );
		$this->assertTrue( $hours->is_valid() );
		$this->assertSame( array(), $hours->validate() );
	}

	public function test_invalid_timezone_is_rejected() {
		$hours = new Pegasus_Botify_Hours( array( 'timezone' => 'Mars/Phobos', 'regular' => array() ) );
		$this->assertFalse( $hours->is_valid() );
	}

	public function test_non_padded_time_is_rejected() {
		$hours = new Pegasus_Botify_Hours( array(
			'timezone' => 'UTC',
			'regular'  => array( 'mon' => array( array( '9:00', '17:00' ) ) ),
		) );
		$this->assertFalse( $hours->is_valid() );
	}

	public function test_open_after_close_is_rejected() {
		$hours = new Pegasus_Botify_Hours( array(
			'timezone' => 'UTC',
			'regular'  => array( 'mon' => array( array( '17:00', '09:00' ) ) ),
		) );
		$this->assertFalse( $hours->is_valid() );
	}

	public function test_exception_with_bad_date_is_rejected() {
		$hours = new Pegasus_Botify_Hours( array(
			'timezone'   => 'UTC',
			'regular'    => array(),
			'exceptions' => array( array( 'date' => '2026-13-40', 'closed' => true ) ),
		) );
		$this->assertFalse( $hours->is_valid() );
	}

	public function test_exception_neither_closed_nor_hours_is_rejected() {
		$hours = new Pegasus_Botify_Hours( array(
			'timezone'   => 'UTC',
			'regular'    => array(),
			'exceptions' => array( array( 'date' => '2026-06-01' ) ),
		) );
		$this->assertFalse( $hours->is_valid() );
	}

	/* ------------------------------------------------------------------ */
	/* Summary                                                             */
	/* ------------------------------------------------------------------ */

	public function test_summary_renders_split_shift_and_closed_days() {
		$hours   = new Pegasus_Botify_Hours( $this->base_config() );
		$summary = $hours->summary();

		$this->assertStringContainsString( 'Monday: 9:00 AM–12:00 PM, 1:00 PM–5:00 PM', $summary );
		$this->assertStringContainsString( 'Saturday: Closed', $summary );
	}
}
