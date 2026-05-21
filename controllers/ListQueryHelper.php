<?php
declare(strict_types=1);

/**
 * ListQueryHelper
 *
 * Centralises query-building utilities shared across list/paginate controllers.
 *
 * Scalability notes
 * ─────────────────
 * • Index management is guarded by a per-process static flag so the
 *   information_schema probe only fires once per PHP-FPM worker lifetime,
 *   not once per request.  For production you should run migrations instead
 *   and disable the runtime check via SKIP_RUNTIME_INDEX_CHECK=true.
 *
 * • LIKE filters use a prefix pattern (term%) instead of a leading-wildcard
 *   pattern (%term%) so the DB engine can use a B-Tree index on the column.
 *
 * • Cursor-based pagination helpers are provided alongside the classic
 *   OFFSET/LIMIT approach.  Switch to cursors when the table exceeds ~100 k
 *   rows to avoid the O(offset) row-scan penalty.
 *
 * • A FULLTEXT search helper is provided for MySQL/MariaDB.  Enable it once
 *   you have a FULLTEXT index on the relevant columns; it scales to millions
 *   of rows far better than multi-column LIKE.
 */
final class ListQueryHelper
{
    // ── Index check guard ────────────────────────────────────────────────────

    /** @var array<string,bool> Per-process index-existence cache keyed by "table.index". */
    private static array $indexExistenceCache = [];

    // ── Search term helpers ──────────────────────────────────────────────────

    /**
     * Split a raw search string into up to $maxTerms distinct, non-empty tokens.
     *
     * @return string[]
     */
    public static function extractSearchTerms(string $search, int $maxTerms = 6): array
    {
        $search = trim(preg_replace('/\s+/', ' ', $search) ?? '');
        if ($search === '') {
            return [];
        }

        $terms = preg_split('/\s+/', $search) ?: [];
        $terms = array_values(array_filter(
            array_map(static fn(string $t): string => trim($t), $terms),
            static fn(string $t): bool => $t !== ''
        ));

        return array_slice(array_unique($terms), 0, max(1, $maxTerms));
    }

    // ── LIKE filter builder ──────────────────────────────────────────────────

    /**
     * Build WHERE clauses so that every search term must appear in at least
     * one of the given $columns (AND across terms, OR across columns).
     *
     * Uses a prefix pattern "term%" so MySQL can hit a regular B-Tree index.
     * If you need infix search ("%term%") pass $prefixOnly = false, but be
     * aware that leads to a full-index scan.
     *
     * @param  string[]  $columns
     * @param  string[]  $terms
     * @param  array<string,mixed> $params  Passed by reference – bindings appended here.
     * @return string[]  One SQL fragment per term; caller joins with AND.
     */
    public static function buildTokenizedLikeFilters(
        array  $columns,
        array  $terms,
        array  &$params,
        string $prefix    = 'search',
        bool   $prefixOnly = true
    ): array {
        if ($columns === [] || $terms === []) {
            return [];
        }

        $clauses = [];

        foreach ($terms as $termIndex => $term) {
            $pattern      = $prefixOnly ? $term . '%' : '%' . $term . '%';
            $columnClauses = [];

            foreach (array_values($columns) as $colIndex => $column) {
                $key             = ':' . $prefix . '_' . $termIndex . '_' . $colIndex;
                $params[$key]    = $pattern;
                $columnClauses[] = $column . ' LIKE ' . $key;
            }

            if ($columnClauses !== []) {
                $clauses[] = '(' . implode(' OR ', $columnClauses) . ')';
            }
        }

        return $clauses;
    }

    // ── FULLTEXT search helper ───────────────────────────────────────────────

    /**
     * Return a MATCH(…) AGAINST(…) WHERE fragment and its binding.
     *
     * Prerequisites:
     *   ALTER TABLE users ADD FULLTEXT INDEX ft_users_search (first_name, last_name, email, username);
     *
     * Usage (replaces buildTokenizedLikeFilters for large tables):
     *
     *   $params = [];
     *   [$ftClause, $relevanceExpr] = ListQueryHelper::buildFulltextFilter(
     *       ['first_name', 'last_name', 'email', 'username'],
     *       $search,
     *       $params
     *   );
     *   // use $ftClause in WHERE, $relevanceExpr in ORDER BY for relevance sorting
     *
     * @param  string[]            $columns
     * @param  array<string,mixed> $params   Passed by reference.
     * @return array{0:string,1:string}      [WHERE fragment, relevance expression]
     */
    public static function buildFulltextFilter(
        array  $columns,
        string $search,
        array  &$params,
        string $bindKey = ':ft_search'
    ): array {
        $cols           = implode(', ', $columns);
        $params[$bindKey] = $search;
        $matchExpr      = "MATCH({$cols}) AGAINST({$bindKey} IN BOOLEAN MODE)";

        return [$matchExpr, $matchExpr];
    }

    // ── Offset pagination ────────────────────────────────────────────────────

    /**
     * Clamp and return safe offset-pagination values.
     *
     * @param  int[] $allowedPerPage
     * @return array{page:int, per_page:int, offset:int}
     */
    public static function offsetPagination(
        int   $requestedPage,
        int   $requestedPerPage,
        int   $total,
        array $allowedPerPage = [10, 25, 50, 100]
    ): array {
        $perPage    = in_array($requestedPerPage, $allowedPerPage, true)
            ? $requestedPerPage
            : $allowedPerPage[1] ?? 25;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, $requestedPage), $totalPages);
        $offset     = ($page - 1) * $perPage;

        return [
            'page'        => $page,
            'per_page'    => $perPage,
            'offset'      => $offset,
            'total_pages' => $totalPages,
        ];
    }

    // ── Cursor pagination ────────────────────────────────────────────────────

    /**
     * Build a WHERE fragment for keyset / cursor-based pagination.
     *
     * Instead of OFFSET N (which forces the DB to scan N rows), we remember
     * the last seen (sort_column, id) pair and ask for rows *after* that
     * cursor.  This is O(1) regardless of how deep into the result set you are.
     *
     * Typical query structure:
     *
     *   SELECT … FROM users
     *   WHERE <your_filters>
     *     AND (<cursor_where>)           ← injected by this helper
     *   ORDER BY status ASC, role ASC, user_id DESC
     *   LIMIT :limit
     *
     * Encode the cursor as: base64(json(["active","staff",42]))
     * Decode with ListQueryHelper::decodeCursor().
     *
     * Limitations:
     *   • Only works with a stable, unique ORDER BY (last column must be the PK).
     *   • No "jump to page N" – purely next/previous navigation.
     *   • Suitable once offset pagination becomes slow (> ~100 k rows).
     *
     * @param  array<string,mixed> $params  Passed by reference.
     * @param  array<mixed>        $cursorValues  Ordered values matching $columns.
     * @param  string[]            $columns       Ordered column names (last must be PK).
     * @param  string[]            $directions    'ASC'|'DESC' per column.
     * @return string  SQL fragment ready for WHERE (…)
     */
    public static function buildCursorWhere(
        array  &$params,
        array  $cursorValues,
        array  $columns,
        array  $directions
    ): string {
        if ($columns === [] || count($columns) !== count($cursorValues)) {
            return '1=1';
        }

        // Build an expanded row-value comparison compatible with MySQL 5.7+
        // e.g. (status > :c_s0) OR (status = :c_s0 AND role > :c_r1) OR …
        $n       = count($columns);
        $clauses = [];

        for ($i = 0; $i < $n; $i++) {
            $prefix = ':cur_' . $i . '_';
            $op     = strtoupper($directions[$i] ?? 'ASC') === 'DESC' ? '<' : '>';

            $parts = [];
            for ($j = 0; $j < $i; $j++) {
                $eqKey          = ':cur_eq_' . $i . '_' . $j;
                $params[$eqKey] = $cursorValues[$j];
                $parts[]        = $columns[$j] . ' = ' . $eqKey;
            }

            $gtKey          = $prefix . 'val';
            $params[$gtKey] = $cursorValues[$i];
            $parts[]        = $columns[$i] . ' ' . $op . ' ' . $gtKey;

            $clauses[] = '(' . implode(' AND ', $parts) . ')';
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }

    /**
     * Encode an array of cursor values as a URL-safe string.
     *
     * @param  array<mixed> $values
     */
    public static function encodeCursor(array $values): string
    {
        return rtrim(strtr(base64_encode(json_encode($values, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * Decode a cursor string back into an array of values.
     * Returns null if the cursor is invalid or tampered with.
     *
     * @return array<mixed>|null
     */
    public static function decodeCursor(string $cursor): ?array
    {
        try {
            $padded  = str_pad(strtr($cursor, '-_', '+/'), strlen($cursor) + (4 - strlen($cursor) % 4) % 4, '=');
            $decoded = base64_decode($padded, strict: true);
            if ($decoded === false) {
                return null;
            }
            $values = json_decode($decoded, associative: true, flags: JSON_THROW_ON_ERROR);
            return is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ── Index management ─────────────────────────────────────────────────────

    /**
     * Create a DB index if it does not already exist.
     *
     * The check is cached in a static property for the lifetime of the PHP
     * process so it never fires more than once per worker.
     *
     * For production workloads, set the env var SKIP_RUNTIME_INDEX_CHECK=true
     * and run proper schema migrations instead.
     */
    public static function ensureIndex(
        PDO    $conn,
        string $table,
        string $indexName,
        string $createSql
    ): void {
        if (getenv('SKIP_RUNTIME_INDEX_CHECK') === 'true') {
            return;
        }

        if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) {
            return;
        }

        $cacheKey = $table . '.' . $indexName;
        if (self::$indexExistenceCache[$cacheKey] ?? false) {
            return;
        }

        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name   = :table_name
                  AND index_name   = :index_name
            ");
            $stmt->execute([
                ':table_name' => $table,
                ':index_name' => $indexName,
            ]);

            if ((int) $stmt->fetchColumn() === 0) {
                $conn->exec($createSql);
            }

            self::$indexExistenceCache[$cacheKey] = true;

        } catch (Throwable $e) {
            // Non-fatal: log and continue. A missing index degrades performance
            // but does not break correctness.
            error_log(sprintf(
                '[ListQueryHelper] Failed to ensure index %s on %s: %s',
                $indexName,
                $table,
                $e->getMessage()
            ));
        }
    }

    /**
     * Create multiple indexes at once.
     *
     * @param  array<string, string> $indexes  [indexName => createSql]
     */
    public static function ensureIndexes(PDO $conn, string $table, array $indexes): void
    {
        foreach ($indexes as $indexName => $createSql) {
            self::ensureIndex($conn, $table, $indexName, $createSql);
        }
    }

    // ── Convenience ─────────────────────────────────────────────────────────

    /**
     * Bind a flat array of [:key => value] to a prepared statement.
     * Infers PDO::PARAM_INT for integer values, PARAM_STR otherwise.
     *
     * @param array<string,mixed> $params
     */
    public static function bindAll(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
    }
}
