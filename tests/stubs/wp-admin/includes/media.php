<?php
/**
 * Empty stand-in for wp-admin/includes/media.php.
 *
 * `PostImporter::apply_featured_image()` require_once's three wp-admin includes before
 * sideloading, unconditionally — so without a file at the path the suite fatals before
 * reaching the behaviour under test. The functions themselves are stubbed in the suite;
 * this only has to exist.
 *
 * MUST NOT EMIT ANYTHING, not even a byte-order mark: it is required mid-run, so any
 * output lands in the middle of the suite's own PASS/FAIL lines and run-all.php stops
 * counting the one it is glued to.
 */
