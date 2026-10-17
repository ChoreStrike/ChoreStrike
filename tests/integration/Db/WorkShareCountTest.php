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
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB proofs for WorkShareMapper::countValidSelectedByWallet
 * (CS-PLAN-086 §3.2), the finished-chore count behind the arcade unlocks:
 * one count per distinct VALID completion in whose SELECTED allocation
 * revision the wallet holds a share. Helpers count, an undone completion
 * and a revised-away share do not.
 */
class WorkShareCountTest extends IntegrationTestCase {
	public function testCount_HelperCountsAndAnUndoneCompletionDoesNot(): void {
		$f = $this->seedFixture(2);
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'wc_owner', $f['roomTaskIds'][0],
			[['userId' => 'wc_owner', 'sharePct' => 50], ['userId' => 'wc_helper', 'sharePct' => 50]]
		);
		$this->completeOccurrence($service, $f['houseId'], 'wc_owner', $f['roomTaskIds'][1]);
		self::assertSame([$f['owner'] => 2, $f['helper'] => 1], $this->counts($f), 'the helper counts the shared chore');

		$this->undoLatestForRoomTask($service, $f['houseId'], 'wc_owner', $f['roomTaskIds'][1]);
		self::assertSame([$f['owner'] => 1, $f['helper'] => 1], $this->counts($f), 'an undone completion no longer counts');
	}

	public function testCount_RevisedAwayShareDoesNotCount(): void {
		$f = $this->seedFixture(1);
		$service = \OC::$server->get(CompletionService::class);

		$this->completeOccurrenceWithParticipants(
			$service, $f['houseId'], 'wc_owner', $f['roomTaskIds'][0],
			[['userId' => 'wc_owner', 'sharePct' => 50], ['userId' => 'wc_helper', 'sharePct' => 50]]
		);
		$completion = $this->latestCompletion($f['roomTaskIds'][0]);
		$owner = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($f['houseId'], 'wc_owner');
		$service->attribute(
			$f['houseId'], $owner, (int)$completion['id'], $this->uuid(),
			(int)$completion['completion_version'],
			[['userId' => 'wc_owner', 'sharePct' => 100]],
			true, 'wrong_split'
		);

		self::assertSame([$f['owner'] => 1], $this->counts($f), 'only the selected revision-2 share counts');
	}

	public function testCount_WithoutWalletsOrShares(): void {
		$mapper = \OC::$server->get(WorkShareMapper::class);
		self::assertSame([], $mapper->countValidSelectedByWallet([]));

		$f = $this->seedFixture(0);
		self::assertSame([], $this->counts($f), 'wallets without shares are omitted, never counted as rows');
	}

	// ========== Helpers ==========

	/**
	 * @return array{houseId: int, roomTaskIds: int[], owner: int, helper: int}
	 */
	private function seedFixture(int $roomTasks): array {
		$houseId = $this->createHouse('wc_owner', 'Finished Chores House');
		$this->addHouseMember($houseId, 'wc_helper', 'Helper', 'member');
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground Floor', 1), 'Kitchen');
		$roomTaskIds = [];
		for ($i = 0; $i < $roomTasks; $i++) {
			$roomTaskIds[] = $this->assignTaskToRoom($this->createTask($houseId, 'Count task ' . $i, '', 5, 3), $roomId, 'wc_owner');
		}
		$wallets = \OC::$server->get(WalletMapper::class);

		return [
			'houseId' => $houseId,
			'roomTaskIds' => $roomTaskIds,
			'owner' => (int)$wallets->findByHouseAndUser($houseId, 'wc_owner')->getId(),
			'helper' => (int)$wallets->findByHouseAndUser($houseId, 'wc_helper')->getId(),
		];
	}

	/**
	 * @param array{owner: int, helper: int} $f
	 * @return array<int, int>
	 */
	private function counts(array $f): array {
		$counts = \OC::$server->get(WorkShareMapper::class)->countValidSelectedByWallet([$f['owner'], $f['helper']]);
		ksort($counts);
		return $counts;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function latestCompletion(int $roomTaskId): array {
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

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}
}
