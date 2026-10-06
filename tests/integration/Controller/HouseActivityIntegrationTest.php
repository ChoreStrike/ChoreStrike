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
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\StreakFreezeService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\QueryCounter;
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
		$qb->delete('chorestrike_work_shares')
			->where($qb->expr()->in('completion_id', $qb->createNamedParameter($this->completedTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
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

	private function seedCompletion(int $roomTaskId, int $houseUserId, string $completedAt, int $totalEnergy = 3): int {
		$id = $this->insertCompletionRow($roomTaskId, $houseUserId, $completedAt, $totalEnergy);
		$this->completedTaskIds[] = $id;
		return $id;
	}

	private function seedShare(int $completionId, int $walletId, int $revision = 1, int $energyMinor = 300): void {
		$share = new WorkShare();
		$share->setCompletionId($completionId);
		$share->setAllocationRevision($revision);
		$share->setWalletId($walletId);
		$share->setSharePct(100);
		$share->setEnergyMinor($energyMinor);
		$share->setCoinMinor($energyMinor * 10);
		$share->setCreatedAt(new \DateTime());
		\OC::$server->get(WorkShareMapper::class)->insert($share);
	}

	private function seedRoomTaskFixture(int $houseId, string $taskName): int {
		$floorId = $this->createFloor($houseId, 'Activity Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Activity Room');
		$taskId = $this->createTask($houseId, $taskName, '', 5, 3);

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
		$this->seedCompletion($roomTaskId, $memberAId, date('Y-m-d H:i:s', time() - 3600), 4);

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
		// WP3 §3.1: the frozen original work (totalEnergy) plus the live
		// definition's energyCost — no monetary outputs in the feed.
		self::assertSame(4, $item['totalEnergy']);
		self::assertSame(3, $item['energyCost']);
		self::assertArrayNotHasKey('coinsEarned', $item);
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
	 * is redacted for non-viewers ” the creator sees the title, another
	 * member sees the null fallback.
	 */
	public function testActivity_InvisiblePrivateTaskContent_Redacted(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);
		$this->seedMembership($houseId, self::MEMBER_B);
		$memberAId = $this->houseUserId($houseId, self::MEMBER_A);

		$floorId = $this->createFloor($houseId, 'Privacy Floor');
		$roomId = $this->createRoom($houseId, $floorId, 'Privacy Room');
		$taskId = $this->createTask($houseId, 'Secret Project', '', 5, 3);

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter('private'))
			->set('owner_user_id', $qb->createNamedParameter(self::MEMBER_A))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, self::MEMBER_A);
		$completionId = $this->seedCompletion($roomTaskId, $memberAId, date('Y-m-d H:i:s', time() - 600), 12);
		$walletId = \OC::$server->get(HouseUserMapper::class)->find($memberAId)->getWalletId();
		$this->seedShare($completionId, $walletId, 1, 1200);

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
		self::assertNull($viewerRows[0]['totalEnergy'], 'frozen effort is private task content');
		self::assertSame([], $viewerRows[0]['allocation'], 'private participant identities and shares are redacted');
		self::assertFalse($viewerRows[0]['canCorrect']);
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
		self::assertSame(3, $creatorRows[0]['energyCost']);
		self::assertSame(12, $creatorRows[0]['totalEnergy']);
		self::assertSame(self::MEMBER_A, $creatorRows[0]['allocation'][0]['userId']);
		self::assertSame(100, $creatorRows[0]['allocation'][0]['sharePct']);
	}

	/**
	 * Activity_MaxPage_SelectedSharesStayBatchBoundedAndCurrent
	 */
	public function testActivity_MaxPage_SelectedSharesStayBatchBoundedAndCurrent(): void {
		$houseId = $this->createHouse('activity_budget_owner', 'Activity Budget');
		$this->addHouseMember($houseId, 'activity_budget_partner', 'Partner');
		$owner = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'activity_budget_owner');
		$partner = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'activity_budget_partner');
		$roomTaskId = $this->seedRoomTaskFixture($houseId, 'Budget task');
		$completedAt = date('Y-m-d H:i:s', time() - 3600);
		$firstId = 0;
		for ($i = 0; $i < 200; $i++) {
			$id = $this->seedCompletion($roomTaskId, $owner->getId(), $completedAt);
			$this->seedShare($id, $owner->getWalletId());
			if ($i === 0) {
				$firstId = $id;
			}
		}
		// A corrected completion selects revision 2, never the obsolete
		// revision 1 share. The other 199 rows still select revision 1.
		$this->seedShare($firstId, $partner->getWalletId(), 2);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_completed_tasks')
			->set('allocation_revision', $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($firstId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$service = \OC::$server->get(\OCA\ChoreStrike\Service\ActivityService::class);
		$counter = QueryCounter::attach($this->db);
		try {
			$counter->mark();
			$small = $service->getActivity($houseId, 30, 10, null, $owner);
			$smallQueries = $counter->count();
			$counter->mark();
			$full = $service->getActivity($houseId, 30, 200, null, $owner);
			$fullQueries = $counter->count();
		} finally {
			$counter->detach();
		}
		self::assertSame(200, $full['total']);
		self::assertCount(10, $small['completions']);
		self::assertCount(200, $full['completions']);
		$ids = array_column($full['completions'], 'id');
		$descendingIds = $ids;
		rsort($descendingIds, SORT_NUMERIC);
		self::assertSame($descendingIds, $ids, 'same-second activity ties use newest inserted id first');
		self::assertSame(array_slice($ids, 0, 10), array_column($small['completions'], 'id'), 'the limited page uses the same stable ordering');
		$first = array_values(array_filter($full['completions'], static fn (array $item): bool => $item['id'] === $firstId));
		self::assertCount(1, $first);
		self::assertCount(1, $first[0]['allocation'], 'obsolete revisions are not double counted');
		self::assertSame('activity_budget_partner', $first[0]['allocation'][0]['userId']);
		self::assertSame('activity_budget_owner', $full['completions'][0]['allocation'][0]['userId']);
		self::assertSame($smallQueries, $fullQueries, '200 rows do not trigger per-completion/share queries');
		self::assertLessThanOrEqual(10, $fullQueries, 'feed enrichment has a fixed query budget');
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
	 * different house ” membership alone used to leak foreign room counts.
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
	 * Freeze lifecycle surfaces retired (WP3 cutover, Q-074-01 Option A):
	 * the freeze service no longer exists and the serialized member
	 * carries neither streakFreezes nor lastFreezeDate.
	 */
	public function testFreezeSurfaces_RetiredInUsersPayload(): void {
		$houseId = $this->sharedHouseId();
		$this->seedMembership($houseId, self::MEMBER_A);

		self::assertFalse(
			class_exists(StreakFreezeService::class),
			'the retired freeze service must stay removed'
		);

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
		self::assertArrayNotHasKey('streakFreezes', $serialized);
		self::assertArrayNotHasKey('lastFreezeDate', $serialized);
	}
}
