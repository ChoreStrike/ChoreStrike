<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\HouseController;
use OCA\ChoreStrike\Controller\RoomTaskController;
use OCA\ChoreStrike\Controller\UserController;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\StreakFreezeService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Integration tests for the transparency/insights backend (CS-PLAN-040):
 * the /house/activity endpoint (happy path, per-user filter), mapper-level
 * house scoping, the roomStats cross-house fix, and lastFreezeDate
 * surfacing through the serialized users.
 *
 * The controllers resolve the shared instance house (getInstanceId), so
 * controller-level tests run against it (WireStatusAuthDenialTest
 * pattern); strict cross-house isolation is proven at mapper level with
 * isolated fixture houses.
 */
class HouseActivityIntegrationTest extends IntegrationTestCase {
	private const MEMBER_A = 'activity_member_a';
	private const MEMBER_B = 'activity_member_b';

	/** @var array<int> */
	private array $completedTaskIds = [];

	protected function tearDown(): void {
		$this->deleteCompletions();

		// Remove shared-house memberships auto-created by ensureHouseMember.
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->in(
				'nextcloud_user_id',
				$qb->createNamedParameter([self::MEMBER_A, self::MEMBER_B], IQueryBuilder::PARAM_STR_ARRAY)
			));
		$qb->executeStatement();

		parent::tearDown();
	}

	private function deleteCompletions(): void {
		if ($this->completedTaskIds === []) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_completed_tasks')
			->where($qb->expr()->in('id', $qb->createNamedParameter($this->completedTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
		$this->completedTaskIds = [];
	}

	/**
	 * Invite-only membership (ADR-010): the shared instance house has no
	 * bootstrap auto-join once members exist, so tests seed memberships
	 * explicitly (purging leftovers from earlier runs first).
	 */
	private function seedMembership(int $houseId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();

		$this->addHouseMember($houseId, $userId, $userId, 'member');
	}

	private function sharedHouseId(): int {
		$houseService = \OC::$server->get(HouseService::class);
		$house = $houseService->getOrCreateHouse($houseService->getInstanceId());

		return $house->getId();
	}

	private function houseUserId(int $houseId, string $ncUserId): int {
		$mapper = \OC::$server->get(HouseUserMapper::class);

		return $mapper->findByNextcloudUserId($houseId, $ncUserId)->getId();
	}

	private function seedCompletion(int $roomTaskId, int $houseUserId, string $completedAt, int $coins = 10): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt),
				'coins_earned' => $qb->createNamedParameter($coins, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(0),
			]);
		$qb->executeStatement();

		$id = (int)$this->db->lastInsertId('chorestrike_completed_tasks');
		$this->completedTaskIds[] = $id;

		return $id;
	}

	private function seedRoomTaskFixture(int $houseId, string $taskName): int {
		$floorId = $this->createFloor($houseId, 'Activity Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Activity Room');
		$taskId = $this->createTask($houseId, $taskName, '', 5, 7);

		return $this->assignTaskToRoom($taskId, $roomId);
	}

	/**
	 * Happy path: a completion appears enriched (actor, task, room, energy,
	 * rewards) and the period total matches.
	 */
	public function testActivity_HappyPath_ReturnsEnrichedCompletions(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);
		$memberAId = $this->houseUserId($houseId, self::MEMBER_A);

		$roomTaskId = $this->seedRoomTaskFixture($houseId, 'Vacuum Activity Room');
		$this->seedCompletion($roomTaskId, $memberAId, date('Y-m-d H:i:s', time() - 3600), 15);

		/** @var HouseController $controller */
		$controller = $this->getController(HouseController::class, self::MEMBER_A);
		$response = $controller->activity();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);

		$completions = array_values(array_filter(
			$data['data']['completions'],
			fn (array $item): bool => $item['taskTitle'] === 'Vacuum Activity Room',
		));
		self::assertCount(1, $completions);

		$item = $completions[0];
		self::assertSame(self::MEMBER_A, $item['displayName']);
		self::assertSame($memberAId, $item['houseUserId']);
		self::assertSame('Activity Room', $item['roomName']);
		self::assertSame(7, $item['energyCost']);
		self::assertSame(15, $item['coinsEarned']);
		self::assertNotEmpty($item['completedAt']);
	}

	/**
	 * The optional userId filter scopes the feed to one member.
	 */
	public function testActivity_PerUserFilter_OnlyReturnsThatMembersCompletions(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);
		$this->seedMembership($houseId, self::MEMBER_B);
		$memberAId = $this->houseUserId($houseId, self::MEMBER_A);
		$memberBId = $this->houseUserId($houseId, self::MEMBER_B);

		$roomTaskId = $this->seedRoomTaskFixture($houseId, 'Filter Scope Task');
		$this->seedCompletion($roomTaskId, $memberAId, date('Y-m-d H:i:s', time() - 3600));
		$this->seedCompletion($roomTaskId, $memberBId, date('Y-m-d H:i:s', time() - 1800));

		/** @var HouseController $controller */
		$controller = $this->getController(HouseController::class, self::MEMBER_A);
		$response = $controller->activity(30, 50, self::MEMBER_A);

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();

		$completions = array_values(array_filter(
			$data['data']['completions'],
			fn (array $item): bool => $item['taskTitle'] === 'Filter Scope Task',
		));
		self::assertCount(1, $completions);
		self::assertSame($memberAId, $completions[0]['houseUserId']);
	}

	/**
	 * Visibility redaction (CS-PLAN-061 review fix): the feed keeps rows
	 * for every house member (D9), but the task CONTENT of a private task
	 * is redacted for non-viewers — the creator sees the title, another
	 * member sees the null fallback.
	 */
	public function testActivity_InvisiblePrivateTaskContent_Redacted(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);
		$this->seedMembership($houseId, self::MEMBER_B);
		$memberAId = $this->houseUserId($houseId, self::MEMBER_A);

		$floorId = $this->createFloor($houseId, 'Privacy Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Privacy Room');
		$taskId = $this->createTask($houseId, 'Secret Project', '', 5, 7);

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter('private'))
			->set('owner_user_id', $qb->createNamedParameter(self::MEMBER_A))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, self::MEMBER_A);
		$this->seedCompletion($roomTaskId, $memberAId, date('Y-m-d H:i:s', time() - 600), 12);

		// MEMBER_B is neither creator nor assignee: content redacted, row kept.
		/** @var HouseController $viewerController */
		$viewerController = $this->getController(HouseController::class, self::MEMBER_B);
		$viewerResponse = $viewerController->activity();
		self::assertSame(200, $viewerResponse->getStatus());

		$viewerRows = array_values(array_filter(
			$viewerResponse->getData()['data']['completions'],
			fn (array $item): bool => $item['taskId'] === $taskId,
		));
		self::assertCount(1, $viewerRows, 'the aggregate row stays visible (D9)');
		self::assertNull($viewerRows[0]['taskTitle'], 'invisible task names are redacted');
		self::assertNull($viewerRows[0]['energyCost'], 'invisible task energy is redacted');
		self::assertSame(self::MEMBER_A, $viewerRows[0]['displayName']);

		// The creator sees the content.
		/** @var HouseController $creatorController */
		$creatorController = $this->getController(HouseController::class, self::MEMBER_A);
		$creatorResponse = $creatorController->activity();
		self::assertSame(200, $creatorResponse->getStatus());

		$creatorRows = array_values(array_filter(
			$creatorResponse->getData()['data']['completions'],
			fn (array $item): bool => $item['taskId'] === $taskId,
		));
		self::assertCount(1, $creatorRows);
		self::assertSame('Secret Project', $creatorRows[0]['taskTitle']);
		self::assertSame(7, $creatorRows[0]['energyCost']);
	}

	/**
	 * Mapper-level scoping: completions of other houses never appear, even
	 * when they share the same completing member id space.
	 */
	public function testActivityMapper_NeverReturnsOtherHousesCompletions(): void {
		$houseA = $this->createHouse('activity_iso_a', 'Isolation A');
		$this->addHouseMember($houseA, 'activity_iso_b', 'Isolation B', 'member');

		$floorA = $this->createFloor($houseA, 'A Floor');
		$roomA = $this->createRoom($houseA, $floorA, 'A Room');
		$taskA = $this->createTask($houseA, 'A Task');
		$roomTaskA = $this->assignTaskToRoom($taskA, $roomA);

		// A second, fully separate house with its own completion.
		$houseB = $this->createHouse('activity_iso_c', 'Isolation B');
		$floorB = $this->createFloor($houseB, 'B Floor');
		$roomB = $this->createRoom($houseB, $floorB, 'B Room');
		$taskB = $this->createTask($houseB, 'B Task');
		$roomTaskB = $this->assignTaskToRoom($taskB, $roomB);

		$memberA = $this->houseUserId($houseA, 'activity_iso_a');
		$memberB = $this->houseUserId($houseB, 'activity_iso_c');
		$this->seedCompletion($roomTaskA, $memberA, date('Y-m-d H:i:s'));
		$this->seedCompletion($roomTaskB, $memberB, date('Y-m-d H:i:s'));

		$mapper = \OC::$server->get(\OCA\ChoreStrike\Db\CompletedTaskMapper::class);
		$start = new \DateTimeImmutable('-1 day');
		$end = new \DateTimeImmutable('+1 minute');

		$houseAItems = $mapper->findActivityBetweenByHouse($houseA, $start, $end, 200);
		$houseAIds = array_map(fn ($item) => $item->getId(), $houseAItems);
		self::assertContains($this->completedTaskIds[0], $houseAIds);
		self::assertNotContains($this->completedTaskIds[1], $houseAIds, 'house A must never see house B completions');

		$houseACount = $mapper->countActivityByHouse($houseA, $start, $end);
		self::assertSame(1, $houseACount);
	}

	/**
	 * roomStats cross-house regression (CS-PLAN-040): a caller whose house
	 * membership is valid must still get 404 when the roomId belongs to a
	 * different house — membership alone used to leak foreign room counts.
	 */
	public function testRoomStats_RejectsForeignHouseRoom(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);

		$foreignHouse = $this->createHouse('activity_iso_foreign', 'Foreign House');
		$floorId = $this->createFloor($foreignHouse, 'Foreign Floor');
		$roomId = $this->createRoom($foreignHouse, $floorId, 'Foreign Room');

		/** @var RoomTaskController $controller */
		$controller = $this->getController(RoomTaskController::class, self::MEMBER_A);
		$response = $controller->roomStats($roomId);

		self::assertSame(404, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertSame('RESOURCE_NOT_FOUND', $data['error']['code']);
	}

	/**
	 * Freeze consumption stamps lastFreezeDate and it surfaces through the
	 * serialized users (FEAT-UX-020 display data).
	 */
	public function testFreezeConsumption_SurfacesLastFreezeDateInUsers(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);
		$mapper = \OC::$server->get(HouseUserMapper::class);

		$user = $mapper->findByNextcloudUserId($houseId, self::MEMBER_A);
		$user->setCurrentStreak(5);
		$user->setStreakFreezes(2);
		$mapper->update($user);

		$yesterday = new \DateTime('2026-07-29'); // a Wednesday (no availability set ⇒ available)
		$freezeService = \OC::$server->get(StreakFreezeService::class);
		$freezeService->process($yesterday);

		/** @var UserController $controller */
		$controller = $this->getController(UserController::class, self::MEMBER_A);
		$response = $controller->index();

		self::assertSame(200, $response->getStatus());
		$users = $response->getData()['data'];
		$serialized = null;
		foreach ($users as $serializedUser) {
			if ($serializedUser['nextcloudUserId'] === self::MEMBER_A) {
				$serialized = $serializedUser;
				break;
			}
		}

		self::assertNotNull($serialized, 'member must be in the users payload');
		self::assertSame(1, $serialized['streakFreezes'], 'freeze pass consumes exactly one freeze');
		self::assertNotNull($serialized['lastFreezeDate'], 'consumption must stamp lastFreezeDate');
		self::assertStringStartsWith('2026-07-29', $serialized['lastFreezeDate']);
	}
}
