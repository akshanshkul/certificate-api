<?php

namespace App\Libraries;

use Config\Database;
use Throwable;

/**
 * Keeping the database connection alive across slow work.
 *
 * The database closes any connection left idle for twenty seconds. Generating
 * a batch of certificates takes far longer than that and touches the database
 * at neither end of the gap: the rows are read, then every PDF is rendered and
 * uploaded to S3 — minutes, for a large batch — and only then is the result
 * written down.
 *
 * By that point the connection has been dropped, and the write fails with
 * "MySQL server has gone away". The certificates exist in S3 and nothing
 * records them, which is worse than an outright failure: the caller is told
 * the batch failed while the files sit there unreferenced.
 *
 * So: before writing anything on the far side of slow work, come through here.
 * Reviving is cheap when nothing is wrong — one SELECT 1 — and rebuilds the
 * connection when it has been dropped.
 */
class Db
{
    /**
     * Makes sure the connection is usable, rebuilding it if it is not.
     *
     * @param non-empty-string $group
     */
    public static function revive(string $group = 'default'): bool
    {
        try {
            // reconnect() pings first and only rebuilds if that fails, so this
            // costs a single round trip on the common path.
            Database::connect($group)->reconnect();

            return true;
        } catch (Throwable $e) {
            // The database being unreachable is a real failure, but it is not
            // this function's to report: the caller is about to try a write
            // that will raise it with far better context.
            log_message('error', 'Could not revive the {group} database connection: {message}', [
                'group'   => $group,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
