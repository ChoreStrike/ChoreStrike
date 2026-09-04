<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\Floor;
use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\MinigameScore;
use OCA\ChoreStrike\Db\MinigameScoreMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\RoomTaskService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Mapper minors + TransactionExecutor semantics (CS-PLAN-060 WP1 §3.5/§3.6):
 * bounded getUserRank, insert()-based FloorMapper::create, and the single
 * transaction owner's commit/rollback behavior through a real service path.
 */
class MapperMinorsRegressionTest extends IntegrationTestCase {
	/** @var int[] */
	private array $createdScoreIds = [];

	protected function tearDown(): void {
		if (!empty($this->createdScoreIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_minigame_scores')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdScoreIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * GetUserRank_CountsBetterBestScores_Bounded
	 *
	 * rank = |users whose best beats the user's best| + 1; 0 for a user
	 * without scores.
	 */
	public function testGetUserRank_CountsBetterBestScores(): void {
		$houseId = $this->createHouse('rank_owner', 'Rank House');
		$mapper = \OC::$server->get(MinigameScoreMapper::class);

		$this->insertScore($houseId, 'alice', 'snake', 100);
		$this->insertScore($houseId, 'alice', 'snake', 90); // alice's best: 100
		$this->insertScore($houseId, 'bob', 'snake', 95);
		$this->insertScore($houseId, 'dave', 'snake', 100);
		$this->insertScore($houseId, 'carol', 'tetris', 500); // other game, must not count

		self::assertSame(1, $mapper->getUserRank($houseId, 'alice', 'snake'), 'alice shares the best score');
		self::assertSame(1, $mapper->getUserRank($houseId, 'dave', 'snake'), 'tied best scores share rank 1');
		self::assertSame(3, $mapper->getUserRank($houseId, 'bob', 'snake'), 'two users (alice, dave) have a better best');
		self::assertSame(0, $mapper->getUserRank($houseId, 'nobody', 'snake'), 'an unknown user is unranked');
	}

	/**
	 * FloorMapper_Create_ReturnsPersistedEntityWithId
	 */
	public function testFloorMapperCreate_ReturnsPersistedEntityWithId(): void {
		$houseId = $this->createHouse('floor_owner', 'Floor House');
		$mapper = \OC::$server->get(FloorMapper::class);

		$floor = $mapper->create($houseId, 'Attic', 3);

		self::assertInstanceOf(Floor::class, $floor);
		self::assertGreaterThan(0, $floor->getId(), 'the returned entity must carry the persisted id');
		self::assertSame($houseId, $floor->getHouseId());
		self::assertSame('Attic', $floor->getName());
		self::assertSame(3, $floor->getSortOrder());
		self::assertNotNull($floor->getCreatedAt());
	}

	/**
	 * TransactionExecutor_Run_Commits_AndReturnsCallableResult — proven
	 * through the real RoomTaskService::deleteRoomTask path: the cascade
	 * (assignees) and the room-task delete land together.
	 */
	public function testTransactionExecutor_CommitsThroughRealServicePath(): void {
		$houseId = $this->createHouse('tx_owner', 'Tx House');
		$this->addHouseMember($houseId, 'tx_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'To delete');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'tx_member');

		$this->actingAs('tx_owner');
		$roomTaskService = \OC::$server->get(RoomTaskService::class);
		$actor = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'tx_owner');
		$roomTaskService->deleteRoomTask($roomTaskId, $actor);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		self::assertSame(0, (int)$result->fetchOne(), 'the committed cascade removed the assignee rows');
		$result->closeCursor();
	}

	/**
	 * TransactionExecutor_Exception_RollsBack — a failing callable leaves no
	 * partial writes behind and re-throws.
	 */
	public function testTransactionExecutor_RollsBackOnException(): void {
		$transactions = \OC::$server->get(TransactionExecutor::class);

		$instanceId = 'tx-rollback-' . uniqid();
		try {
			$transactions->run(function () use ($instanceId): int {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('chorestrike_houses')
					->values([
						'nextcloud_instance_id' => $qb->createNamedParameter($instanceId),
						'name' => $qb->createNamedParameter('Never Persists'),
						'house_health' => $qb->createNamedParameter(100),
						'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
						'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
					]);
				$qb->executeStatement();

				throw new \RuntimeException('boom');
			});
			self::fail('the exception must propagate');
		} catch (\RuntimeException $e) {
			self::assertSame('boom', $e->getMessage());
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_houses')
			->where($qb->expr()->eq('nextcloud_instance_id', $qb->createNamedParameter($instanceId)));
		$result = $qb->executeQuery();
		self::assertSame(0, (int)$result->fetchOne(), 'the rolled-back insert must not persist');
		$result->closeCursor();
	}

	private function insertScore(int $houseId, string $userId, string $gameId, int $score): void {
		$mapper = \OC::$server->get(MinigameScoreMapper::class);
		$scoreEntity = new MinigameScore();
		$scoreEntity->setHouseId($houseId);
		$scoreEntity->setUserId($userId);
		$scoreEntity->setGameId($gameId);
		$scoreEntity->setScore($score);
		$scoreEntity->setCreatedAt(new \DateTime());
		$saved = $mapper->insert($scoreEntity);
		$this->createdScoreIds[] = $saved->getId();
	}
}
