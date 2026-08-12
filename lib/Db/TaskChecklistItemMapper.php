<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TaskChecklistItem>
 */
class TaskChecklistItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'chorestrike_checklist_items', TaskChecklistItem::class);
	}

	/**
	 * Find checklist item by ID
	 *
	 * @param int $id
	 * @throws DoesNotExistException
	 */
	public function find(int $id): TaskChecklistItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all checklist items for a task
	 *
	 * @param int $taskId
	 * @return TaskChecklistItem[]
	 */
	public function findByTaskId(int $taskId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Create a new checklist item
	 *
	 * @param int $taskId
	 * @param string $label
	 * @param int $sortOrder
	 */
	public function create(int $taskId, string $label, int $sortOrder = 0): TaskChecklistItem {
		$item = new TaskChecklistItem();
		$item->setTaskId($taskId);
		$item->setLabel($label);
		$item->setSortOrder($sortOrder);
		$item->setCreatedAt(new \DateTime());

		return $this->insert($item);
	}

	/**
	 * Update checklist item label
	 *
	 * @param int $itemId
	 * @param string $label
	 */
	public function updateLabel(int $itemId, string $label): TaskChecklistItem {
		$item = $this->find($itemId);
		$item->setLabel($label);
		return $this->update($item);
	}

	/**
	 * Update sort order for multiple items
	 *
	 * @param array $itemOrders Map of itemId => sortOrder
	 */
	public function updateSortOrders(array $itemOrders): void {
		foreach ($itemOrders as $itemId => $sortOrder) {
			try {
				$item = $this->find($itemId);
				$item->setSortOrder($sortOrder);
				$this->update($item);
			} catch (DoesNotExistException $e) {
				continue;
			}
		}
	}

	/**
	 * Delete all checklist items for a task
	 *
	 * @param int $taskId
	 */
	public function deleteByTaskId(int $taskId): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Count checklist items for a task
	 *
	 * @param int $taskId
	 */
	public function countByTaskId(int $taskId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		return (int)$result->fetchOne();
	}
}
