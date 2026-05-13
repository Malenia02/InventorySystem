<?php
declare(strict_types=1);

final class ListQueryHelper
{
    public static function extractSearchTerms(string $search): array
    {
        $search = trim(preg_replace('/\s+/', ' ', $search) ?? '');
        if ($search === '') {
            return [];
        }

        $terms = preg_split('/\s+/', $search) ?: [];
        $terms = array_values(array_filter(array_map(
            static fn(string $term): string => trim($term),
            $terms
        ), static fn(string $term): bool => $term !== ''));

        return array_slice(array_unique($terms), 0, 6);
    }

    public static function buildTokenizedLikeFilters(array $columns, array $terms, array &$params, string $prefix = 'search'): array
    {
        $clauses = [];

        foreach ($terms as $termIndex => $term) {
            $columnClauses = [];
            foreach (array_values($columns) as $columnIndex => $column) {
                $tokenKey = ':' . $prefix . '_' . $termIndex . '_' . $columnIndex;
                $params[$tokenKey] = '%' . $term . '%';
                $columnClauses[] = $column . ' LIKE ' . $tokenKey;
            }

            if ($columnClauses !== []) {
                $clauses[] = '(' . implode(' OR ', $columnClauses) . ')';
            }
        }

        return $clauses;
    }

    public static function ensureIndex(PDO $conn, string $table, string $indexName, string $createSql): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = :table_name
                  AND index_name = :index_name
            ");
            $stmt->execute([
                ':table_name' => $table,
                ':index_name' => $indexName,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }

            $conn->exec($createSql);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[ListQueryHelper] Failed to ensure index %s on %s: %s',
                $indexName,
                $table,
                $e->getMessage()
            ));
        }
    }
}
