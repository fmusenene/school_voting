<?php
/**
 * Optional candidates.class_section column (e.g. S.2 K) for results display.
 */

/** @param mysqli $conn */
function ensure_candidate_class_section_column(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $res = $conn->query("SHOW COLUMNS FROM candidates LIKE 'class_section'");
    if ($res) {
        $missing = $res->num_rows === 0;
        $res->free();
        if ($missing) {
            $conn->query(
                "ALTER TABLE candidates ADD COLUMN class_section VARCHAR(50) NULL DEFAULT NULL AFTER name"
            );
        }
    }
    $ensured = true;
}
