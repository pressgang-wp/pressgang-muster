<?php

namespace PressGang\Muster\Testing;

use PressGang\Muster\Results\RunReport;
use RuntimeException;

/**
 * Stable JSON snapshots for structured Muster reconciliation reports.
 *
 * WordPress IDs are excluded by default because logical keys and locators are
 * the portable fixture contract. Snapshot writes are always explicit.
 */
final class MusterSnapshot
{
    /**
     * Render the on-disk snapshot envelope for one reconciliation report.
     *
     * The envelope is versioned JSON: `{snapshot_version: 1, report: {...}}`
     * with a trailing newline, pretty-printed for reviewable diffs. Byte-for-
     * byte equality of this string is the snapshot contract used by
     * {@see self::assertMatches()}.
     *
     * @param RunReport $report
     * @param bool $includeIds Keep WordPress IDs; excluded by default because
     *        they vary between environments.
     * @return string
     */
    public static function serialize(RunReport $report, bool $includeIds = false): string
    {
        $data = $report->toArray();
        if (!$includeIds) {
            foreach ($data['operations'] as &$operation) {
                unset($operation['id']);
            }
            unset($operation);
        }

        return (string) json_encode([
            'snapshot_version' => 1,
            'report' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Explicitly create or replace one snapshot file.
     *
     * @param string $path Existing parent directory and target filename.
     * @param RunReport $report
     * @param bool $includeIds
     * @return void
     */
    public static function write(string $path, RunReport $report, bool $includeIds = false): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Snapshot directory [%s] does not exist.', $directory));
        }

        if (file_put_contents($path, self::serialize($report, $includeIds)) === false) {
            throw new RuntimeException(sprintf('Failed to write Muster snapshot [%s].', $path));
        }
    }

    /**
     * Assert the report serializes byte-for-byte to the stored snapshot.
     *
     * A missing snapshot file is a mismatch, never an implicit write — call
     * {@see self::write()} deliberately to create or refresh snapshots.
     *
     * @param string $path Snapshot file produced by write().
     * @param RunReport $report
     * @param bool $includeIds Must match the value used when writing.
     * @return void
     * @throws SnapshotMismatch If the file is missing or differs.
     */
    public static function assertMatches(string $path, RunReport $report, bool $includeIds = false): void
    {
        if (!is_file($path)) {
            throw new SnapshotMismatch(sprintf('Muster snapshot [%s] does not exist.', $path));
        }

        $expected = file_get_contents($path);
        $actual = self::serialize($report, $includeIds);
        if ($expected !== $actual) {
            throw new SnapshotMismatch(sprintf(
                "Muster snapshot [%s] does not match.\nExpected:\n%s\nActual:\n%s",
                $path,
                (string) $expected,
                $actual
            ));
        }
    }
}
