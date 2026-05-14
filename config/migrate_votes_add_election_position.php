<?php
/**
 * Adds election_id and position_id to votes if missing (align with config/install.php),
 * then backfills from candidates/positions.
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/votes_schema.php';

if (!$conn || $conn->connect_error) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$f = votes_table_field_set($conn);
if (!empty($f['election_id']) && !empty($f['position_id'])) {
    echo "votes.election_id and votes.position_id already exist.\n";
    exit(0);
}

if (empty($f['election_id'])) {
    if (!$conn->query('ALTER TABLE votes ADD COLUMN election_id INT NULL AFTER id')) {
        fwrite(STDERR, 'ALTER election_id failed: ' . $conn->error . "\n");
        exit(1);
    }
    echo "Added votes.election_id\n";
}

$f = votes_table_field_set($conn, true);
if (empty($f['position_id'])) {
    $after = !empty($f['election_id']) ? 'election_id' : 'id';
    if (!$conn->query("ALTER TABLE votes ADD COLUMN position_id INT NULL AFTER $after")) {
        fwrite(STDERR, 'ALTER position_id failed: ' . $conn->error . "\n");
        exit(1);
    }
    echo "Added votes.position_id\n";
}

$sql = 'UPDATE votes v
        INNER JOIN candidates c ON v.candidate_id = c.id
        INNER JOIN positions p ON c.position_id = p.id
        SET v.position_id = p.id, v.election_id = p.election_id
        WHERE v.election_id IS NULL OR v.position_id IS NULL';
if (!$conn->query($sql)) {
    fwrite(STDERR, 'Backfill failed: ' . $conn->error . "\n");
    exit(1);
}
echo 'Backfilled election_id/position_id from candidates/positions (' . $conn->affected_rows . " rows).\n";
echo "Done. Optionally add FOREIGN KEY constraints in phpMyAdmin if desired.\n";
