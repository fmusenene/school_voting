<?php

/**
 * candidates.class_section column (e.g. S.2 K) for results, live, and voting displays.
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

/** @param mysqli $conn */
function candidates_has_class_section_column(mysqli $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    ensure_candidate_class_section_column($conn);
    $res = $conn->query("SHOW COLUMNS FROM candidates LIKE 'class_section'");
    $has = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $has;
}

/** SQL fragment for SELECT lists. @param mysqli $conn */
function candidate_class_section_select_expr(mysqli $conn, string $tableAlias = 'c', string $resultAlias = 'class_section'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $resultAlias) ?: 'class_section';
    if (candidates_has_class_section_column($conn)) {
        return "COALESCE({$tableAlias}.class_section, '') AS {$alias}";
    }
    return "'' AS {$alias}";
}

/** Append after SELECT c.* when class_section may be missing from c.* on old schemas. @param mysqli $conn */
function candidate_class_section_select_suffix(mysqli $conn, string $tableAlias = 'c'): string
{
    if (candidates_has_class_section_column($conn)) {
        return '';
    }
    return ", '' AS class_section";
}

/**
 * HTML snippet for class/section next to candidate name (empty string if none).
 */
function candidate_class_section_display_html(?string $classSection, string $cssClass = 'candidate-class-section'): string
{
    $classSection = trim((string) $classSection);
    if ($classSection === '') {
        return '';
    }
    $safeClass = htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8');
    $safeValue = htmlspecialchars($classSection, ENT_QUOTES, 'UTF-8');
    return '<span class="' . $safeClass . ' candidate-class-section">(' . $safeValue . ')</span>';
}
