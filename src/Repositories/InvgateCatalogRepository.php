<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class InvgateCatalogRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $category
     */
    public function upsertCategory(array $category): bool
    {
        $id = isset($category['id']) ? (int) $category['id'] : 0;
        $name = isset($category['name']) ? trim((string) $category['name']) : '';
        if ($id <= 0 || $name === '') {
            return false;
        }

        $parentId = isset($category['parent_category_id']) ? (int) $category['parent_category_id'] : null;
        if ($parentId !== null && $parentId <= 0) {
            $parentId = null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO invgate_categories (invgate_id, name, parent_category_id)
             VALUES (:id, :name, :parent_id)
             ON CONFLICT(invgate_id) DO UPDATE SET
                name = excluded.name,
                parent_category_id = excluded.parent_category_id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'parent_id' => $parentId,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $type
     */
    public function upsertType(array $type): bool
    {
        $id = isset($type['id']) ? (int) $type['id'] : 0;
        $name = isset($type['name']) ? trim((string) $type['name']) : '';
        if ($id <= 0 || $name === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO invgate_types (invgate_id, name)
             VALUES (:id, :name)
             ON CONFLICT(invgate_id) DO UPDATE SET
                name = excluded.name'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $status
     */
    public function upsertStatus(array $status): bool
    {
        $id = isset($status['id']) ? (int) $status['id'] : 0;
        $name = isset($status['name']) ? trim((string) $status['name']) : '';
        if ($id <= 0 || $name === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO invgate_statuses (invgate_id, name)
             VALUES (:id, :name)
             ON CONFLICT(invgate_id) DO UPDATE SET
                name = excluded.name'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
        ]);

        return true;
    }

    /**
     * @return array{category_ids: list<int>, type_ids: list<int>, status_ids: list<int>}
     */
    public function listMissingCatalogIdsFromTickets(): array
    {
        $categoryIds = $this->listMissingIds('category_id', 'invgate_categories');
        $typeIds = $this->listMissingIds('type_id', 'invgate_types');
        $statusIds = $this->listMissingIds('status_id', 'invgate_statuses');

        return [
            'category_ids' => $categoryIds,
            'type_ids' => $typeIds,
            'status_ids' => $statusIds,
        ];
    }

    /**
     * @return list<int>
     */
    private function listMissingIds(string $ticketCol, string $catalogTable): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT it.' . $ticketCol . ' AS id
             FROM invgate_tickets it
             LEFT JOIN ' . $catalogTable . ' cat ON cat.invgate_id = it.' . $ticketCol . '
             WHERE it.' . $ticketCol . ' IS NOT NULL
               AND it.' . $ticketCol . ' > 0
               AND cat.invgate_id IS NULL'
        );
        if ($stmt === false) {
            return [];
        }

        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }
}

