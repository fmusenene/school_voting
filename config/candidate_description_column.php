<?php
/**
 * Some installs use candidates.description; others use candidates.bio (see setup_database.php).
 * These helpers pick the right physical column and expose description in SELECT lists.
 *
 * @param mysqli|PDO $conn
 */
function candidate_table_field_set($conn): array
{
    $fields = [];
    if ($conn instanceof mysqli) {
        $res = mysqli_query($conn, 'SHOW COLUMNS FROM candidates');
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $fields[$row['Field']] = true;
            }
            mysqli_free_result($res);
        }
        return $fields;
    }
    if ($conn instanceof PDO) {
        $stmt = $conn->query('SHOW COLUMNS FROM candidates');
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $fields[$row['Field']] = true;
            }
        }
        return $fields;
    }
    return $fields;
}

/** @param mysqli|PDO $conn */
function candidate_text_column_name($conn): string
{
    static $byKey = [];
    $key = $conn instanceof PDO ? 'pdo:' . spl_object_id($conn) : 'mysqli:' . spl_object_id($conn);
    if (isset($byKey[$key])) {
        return $byKey[$key];
    }
    $f = candidate_table_field_set($conn);
    if (!empty($f['description'])) {
        $byKey[$key] = 'description';
    } elseif (!empty($f['bio'])) {
        $byKey[$key] = 'bio';
    } else {
        $byKey[$key] = 'description';
    }
    return $byKey[$key];
}

/** SQL fragment for SELECT list; result column is always `description`. @param mysqli|PDO $conn */
function candidate_description_select_expr($conn, string $tableAlias = 'c'): string
{
    $col = candidate_text_column_name($conn);
    if ($col === 'bio') {
        return "{$tableAlias}.bio AS description";
    }
    return "{$tableAlias}.description";
}

/**
 * Append after `c.*` in SELECT so JSON/JS still get `description` when only `bio` exists.
 * @param mysqli|PDO $conn
 */
function candidate_description_select_suffix($conn, string $tableAlias = 'c'): string
{
    if (candidate_text_column_name($conn) === 'bio') {
        return ", {$tableAlias}.bio AS description";
    }
    return '';
}
