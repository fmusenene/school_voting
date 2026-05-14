<?php

/**
 * Detects votes table shape: full schema (install.php) includes election_id + position_id;
 * older setup_database.php only had candidate_id + voting_code_id.
 *
 * @param mysqli $conn
 * @return array<string, true>
 */
function votes_table_field_set(mysqli $conn, bool $refresh = false): array
{
    static $cache = null;
    if ($refresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $res = $conn->query('SHOW COLUMNS FROM votes');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cache[$row['Field']] = true;
        }
        $res->free();
    }
    return $cache;
}

/** @param mysqli $conn */
function votes_has_election_position_columns(mysqli $conn): bool
{
    $f = votes_table_field_set($conn);
    return !empty($f['election_id']) && !empty($f['position_id']);
}
