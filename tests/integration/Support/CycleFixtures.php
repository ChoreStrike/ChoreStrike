<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One household with one room task for the tests of derived windows
 * (ADR-021): the fixture, days relative to today and the reads the
 * assertions share. The using test defines the accounts as `OWNER` and
 * `HELPER`.
 */
trait CycleFixtures {
	/** Today in UTC, the zone of a fixture house. */
	protected string $today;

	protected function initToday(): void {
		$this->today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
	}

	/**
	 * House + owner + helper + room + one task (daily, after completion,
	 * energy 2 unless overridden) mounted for the owner with the given
	 * first date.
	 *
	 * @param array<string, mixed> $taskOverrides Columns of `chorestrike_tasks`
	 * @return array{houseId: int, roomId: int, taskId: int, roomTaskId: int}
	 */
	protected function fixture(array $taskOverrides = [], ?string $firstDate = null): array {
		$houseId = $this->createHouse(static::OWNER, 'Cycle House');
		$this->addHouseMember($houseId, static::HELPER, 'Helper', 'member');
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground', 1), 'Kitchen');
		$taskId = $this->createTask($houseId, 'Dishes', 'Do the dishes', 3, 2);
		$taskOverrides += [
			'frequency_type' => 'daily',
			'frequency_value' => 1,
			'scheduling_mode' => 'dynamic',
			'recurrence_mode' => 'recurring',
		];
		foreach ($taskOverrides as $column => $value) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return [
			'houseId' => $houseId,
			'roomId' => $roomId,
			'taskId' => $taskId,
			'roomTaskId' => $this->assignTaskToRoom($taskId, $roomId, static::OWNER, ($firstDate ?? $this->today) . ' 00:00:00'),
		];
	}

	protected function day(int $offset): string {
		return (new \DateTime($this->today))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
	}

	protected function member(int $houseId, string $userId): HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $userId);
	}

	/** The `cycle` block a row shows; null when the room task has no current cycle. */
	protected function block(array $f, ?\DateTime $now = null): ?array {
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($f['roomTaskId']);
		return \OC::$server->get(RoomTaskReadService::class)->cycleFacts($f['houseId'], [$roomTask], null, $now)[$f['roomTaskId']]['block'];
	}

	/** @return list<array<string, mixed>> */
	protected function rows(string $table, string $column, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	protected function createChecklistItem(int $taskId, string $label, int $sortOrder): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_chk_items')
			->values([
				'task_id' => $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT),
				'label' => $qb->createNamedParameter($label),
				'sort_order' => $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
		return (int)$this->db->lastInsertId('chorestrike_chk_items');
	}
}
