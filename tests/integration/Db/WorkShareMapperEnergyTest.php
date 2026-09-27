<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\DailyCapacityService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB proofs for the WP3 §3.2 capacity seam (CS-PLAN-074 WP3,
 * RF-074-WP3-09 — replaces the retired completer-only
 * CompletedTaskMapperEnergyTest): WorkShareMapper::findSelectedSharesBetweenByHouse
 * returns each participant's SELECTED frozen share of every VALID
 * completion inside the half-open instant bounds. Superseded allocation
 * revisions, undone completions and out-of-window work are excluded, and
 * the DailyCapacityService day report charges exactly those selected
 * shares — a fulfilled occurrence plans nothing further, an undone one
 * plans its agreed share again.
 */
class WorkShareMapperEnergyTest extends IntegrationTestCase {
	private array $cleanupRoomTaskIds = [];
	private array $cleanupHouseIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			$completionIds = $this->completionIdsForRoomTask($roomTaskId);
			if ($completionIds !== []) {
				$intArray = IQueryBuilder::PARAM_INT_ARRAY;
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_work_shares')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_completion_revs')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_room_events')
					->where($qb->expr()->in('source_completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		foreach ($this->cleanupHouseIds as $houseId) {
			$walletIds = $this->walletIdsForHouse($houseId);
			if ($walletIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_wallet_entries')
					->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * SelectedRevisionValidWindowAndCapacityReport
	 *
	 * Energy 3: the initial 50/50 completion (revision 1) is corrected to
	 * 100% owner (revision 2). The mapper returns ONLY the selected
	 * revision-2 owner row inside today's window — never the superseded
	 * member share and nothing outside the half-open bounds — and the day
	 * report charges the owner 300 completed minor while the fulfilled
	 * occurrence plans nothing further.
	 */
	public function testSelectedShares_SelectedRevisionValidWindowAndCapacityReport(): void {
		$f = $this->seedSeamFixture();
		$service = \OC::$server->get(CompletionService::class);
		$todayKey = (new \DateTime())->format('Y-m-d');

		$before = $this->dayReport($f['houseId'], $todayKey);
		self::assertSame(300, $before['ws_owner']['plannedEnergyMinor'], 'the open occurrence plans the assignee share');
		self::assertSame(0, $before['ws_owner']['completedEnergyMinor']);

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'ws_owner', $f['roomTaskId'],
			[['userId' => 'ws_owner', 'sharePct' => 50], ['userId' => 'ws_member', 'sharePct' => 50]]
		);
		$completion = $this->completionRow($f['roomTaskId']);
		$owner = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($f['houseId'], 'ws_owner');
		$service->attribute(
			$f['houseId'], $owner, (int)$completion['id'], $this->uuid(),
			(int)$completion['completion_version'],
			[['userId' => 'ws_owner', 'sharePct' => 100]],
			true, 'wrong_split'
		);

		$ownerWalletId = (int)\OC::$server->get(WalletMapper::class)
			->findByHouseAndUser($f['houseId'], 'ws_owner')->getId();
		$todayRows = $this->seamRows($f['houseId'], $todayKey);
		self::assertCount(1, $todayRows, 'only the selected revision-2 share is returned');
		self::assertSame($ownerWalletId, $todayRows[0]['walletId']);
		self::assertSame(300, $todayRows[0]['energyMinor']);
		self::assertSame((int)$completion['id'], $todayRows[0]['completionId']);

		$yesterdayKey = (new \DateTime('-1 day'))->format('Y-m-d');
		self::assertSame([], $this->seamRows($f['houseId'], $yesterdayKey), 'the half-open window bounds exclude other days');

		$after = $this->dayReport($f['houseId'], $todayKey);
		self::assertSame(300, $after['ws_owner']['completedEnergyMinor'], 'the day report charges the selected owner share');
		self::assertSame(0, $after['ws_owner']['plannedEnergyMinor'], 'a fulfilled occurrence plans nothing further');
		self::assertSame(0, $after['ws_member']['completedEnergyMinor'], 'the revised-away member share never counts');
	}

	/**
	 * UndoneCompletionExcluded
	 *
	 * The 50/50 completion charges 150 minor to each participant; the undo
	 * invalidates the completion, so the mapper returns nothing and the
	 * day report plans the open occurrence's agreed share again.
	 */
	public function testSelectedShares_UndoneCompletionExcluded(): void {
		$f = $this->seedSeamFixture();
		$service = \OC::$server->get(CompletionService::class);
		$todayKey = (new \DateTime())->format('Y-m-d');

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'ws_owner', $f['roomTaskId'],
			[['userId' => 'ws_owner', 'sharePct' => 50], ['userId' => 'ws_member', 'sharePct' => 50]]
		);
		self::assertCount(2, $this->seamRows($f['houseId'], $todayKey));
		$completed = $this->dayReport($f['houseId'], $todayKey);
		self::assertSame(150, $completed['ws_owner']['completedEnergyMinor']);
		self::assertSame(150, $completed['ws_member']['completedEnergyMinor']);

		$this->undoLatestForRoomTask($service, $f['houseId'], 'ws_owner', $f['roomTaskId']);

		self::assertSame([], $this->seamRows($f['houseId'], $todayKey), 'an undone completion leaves no selected shares');
		$undone = $this->dayReport($f['houseId'], $todayKey);
		self::assertSame(0, $undone['ws_owner']['completedEnergyMinor']);
		self::assertSame(0, $undone['ws_member']['completedEnergyMinor']);
		self::assertSame(300, $undone['ws_owner']['plannedEnergyMinor'], 'the reopened occurrence plans its agreed share again');
	}

	// ========== Helpers ==========

	/**
	 * @return array{houseId: int, roomTaskId: int}
	 */
	private function seedSeamFixture(): array {
		$houseId = $this->createHouse('ws_owner', 'Capacity Seam House');
		$this->addHouseMember($houseId, 'ws_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Seam task', '', 5, 3);
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'ws_owner');

		$this->cleanupRoomTaskIds[] = $roomTaskId;
		$this->cleanupHouseIds[] = $houseId;

		return ['houseId' => $houseId, 'roomTaskId' => $roomTaskId];
	}

	/**
	 * The mapper's selected-share rows whose completion instant falls in
	 * the half-open UTC interval of the given civil day.
	 *
	 * @return array<int, array{completionId: int, walletId: int|null, energyMinor: int, completedAt: \DateTime, workDate: \DateTime|null, workZone: string|null, occurrenceKey: string|null}>
	 */
	private function seamRows(int $houseId, string $dayKey): array {
		$start = new \DateTime($dayKey . 'T00:00:00+00:00');
		$end = (clone $start)->modify('+1 day');
		return \OC::$server->get(WorkShareMapper::class)->findSelectedSharesBetweenByHouse($houseId, $start, $end);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function dayReport(int $houseId, string $dayKey): array {
		return \OC::$server->get(DailyCapacityService::class)->evaluateDays($houseId, [$dayKey])[$dayKey];
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function completionRow(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}

	/**
	 * @return array<int, int>
	 */
	private function completionIdsForRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @return int[]
	 */
	private function walletIdsForHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}
}
