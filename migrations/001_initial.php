<?php
/**
 * Migration 001: Initial schema baseline.
 *
 * Tables push_subscription and push_preferences are created by config.php ensureTable().
 * This migration just marks the baseline so future migrations know where to start.
 */

return function () {
    // Baseline — tables already exist from config.php pre_save().
    // Nothing to do, just establishing schema version 1.
    return true;
};
