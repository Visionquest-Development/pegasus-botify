<?php
/**
 * PHPUnit bootstrap for the Hours module.
 *
 * The Hours class is deliberately WordPress-independent, so no WP test suite is needed —
 * we just define the test guard constant and require the class directly.
 */

define( 'PEGASUS_BOTIFY_HOURS_TEST', true );

require __DIR__ . '/../includes/class-hours.php';
