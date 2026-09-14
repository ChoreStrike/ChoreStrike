<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\GameLogicService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\MedalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

/**
 * Integration tests for GameController API endpoints (CS-PLAN-068 §3.1).
 *
 * The live `checkMedals` case is proven against the REAL MedalService and
 * real persisted completions/awards in this container's database: a
 * threshold-crossing week awards exactly one serialized medal, the award is
 * persisted, the serialized read is repeatable, and a second check awards
 * nothing new (idempotence). The obsolete `handleTaskCompletion` controller
 * case was removed — its route was pruned by CS-PLAN-055 and
 * RouteMatrixTest::testPrunedCS055RoutesAreNotReintroduced proves the route
 * (and thus the endpoint) stays absent; the completion successor is the
 * CompletionService aggregate behind POST /room-tasks/{id}/complete.
 */
class GameControllerIntegrationTest extends IntegrationTestCase {
	/** @var array<int> Room task ids whose seeded medal-week ledger rows need cleanup. */
	private array $ledgerRoomTaskIds = [];
	/** @var array<int> Instance-house membership ids created by these tests. */
	private array $instanceMembershipIds = [];
	/** @var array<int> Medal rows (user ids) created by these tests. */
	private array $medalUserIds = [];

	protected function tearDown(): void {
		if ($this->ledgerRoomTaskIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($this->ledgerRoomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$this->ledgerRoomTaskIds = [];
		}
		if ($this->medalUserIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_medals')
				->where($qb->expr()->in('user_id', $qb->createNamedParameter($this->medalUserIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$this->medalUserIds = [];
		}
		if ($this->instanceMembershipIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->instanceMembershipIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$this->instanceMembershipIds = [];
		}
		parent::tearDown();
	}

	/**
	 * The GameController endpoints resolve the SHARED instance house
	 * (HouseService::getInstanceId) — fixtures therefore live there, with
	 * explicitly tracked membership rows (ADR-010: no auto-join).
	 */
	private function instanceHouseId(): int {
		$houseService = \OC::$server->get(HouseService::class);
		return $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
	}

	private function addInstanceMembership(string $userId, string $displayName, string $role = 'member'): int {
		$qb = $this->db->getQueryBuilder();
		$existing = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByHouseAndUser($this->instanceHouseId(), $userId);
		if ($existing !== null) {
			return $existing->getId();
		}
		$qb->insert('chorestrike_house_users')
			->values([
				'house_id' => $qb->createNamedParameter($this->instanceHouseId(), IQueryBuilder::PARAM_INT),
				'nextcloud_user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter($displayName),
				'role' => $qb->createNamedParameter($role),
				'contribution_score' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'coins' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'current_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'longest_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'streak_freezes' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$id = (int)$this->db->lastInsertId('chorestrike_house_users');
		$this->instanceMembershipIds[] = $id;
		return $id;
	}

	/**
	 * State_ReturnsGameState_SuccessResponse
	 */
	public function testState_ReturnsGameState_SuccessResponse(): void {
		$request = $this->createMock(IRequest::class);
		$gameLogicService = $this->createMock(GameLogicService::class);
		$houseService = $this->createMock(HouseService::class);
		$authService = $this->createMock(AuthorizationService::class);

		$gameState = [
			'houseHealth' => 82,
			'totalCoins' => 1250,
			'activeCombos' => [],
			'currentWeek' => ['start' => '2026-02-23', 'end' => '2026-03-01'],
		];

		$houseService->method('getInstanceId')->willReturn('test-instance');
		$houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());
		$gameLogicService->method('getGameState')
			->willReturn($gameState);

		$controller = new GameController(
			$request,
			$gameLogicService,
			$this->createMock(MedalService::class),
			$houseService,
			$authService,
			$this->createMock(AppLogger::class)
		);

		$response = $controller->state();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	private function createMockHouse(): House {
		$house = new House();
		$house->setId(1);
		$house->setNextcloudInstanceId('test-instance');
		$house->setName('Test House');
		$house->setHouseHealth(100);
		return $house;
	}

	private function createMockHouseUser(): HouseUser {
		$user = new HouseUser();
		$user->setId(1);
		$user->setHouseId(1);
		$user->setNextcloudUserId('test-user');
		$user->setDisplayName('Test User');
		$user->setRole('member');
		return $user;
	}

	/**
	 * Medals_ReturnsAllMedals_SuccessResponse
	 *
	 * CS-PLAN-067 §3.2: the medal listing is served by MedalService.
	 */
	public function testMedals_ReturnsAllMedals_SuccessResponse(): void {
		$request = $this->createMock(IRequest::class);
		$gameLogicService = $this->createMock(GameLogicService::class);
		$houseService = $this->createMock(HouseService::class);
		$authService = $this->createMock(AuthorizationService::class);

		$medals = [
			['id' => 1, 'userId' => 'user1', 'medalType' => 'team_anchor', 'value' => 12],
			['id' => 2, 'userId' => 'user1', 'medalType' => 'flow_keeper', 'value' => 5],
		];
		$medalService = $this->createMock(MedalService::class);
		$medalService->method('getMedals')->willReturn($medals);

		$houseService->method('getInstanceId')->willReturn('test-instance');
		$houseService->method('getOrCreateHouse')->willReturn($this->createMockHouse());
		$authService->method('assertHouseMember')->willReturn($this->createMockHouseUser());

		$controller = new GameController(
			$request,
			$gameLogicService,
			$medalService,
			$houseService,
			$authService,
			$this->createMock(AppLogger::class)
		);

		$response = $controller->medals();

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
	}

	/**
	 * CheckMedals_RealPersistedWeek_AwardsSerializedMedal_IdempotentOnRepeat
	 *
	 * A week with ten real persisted completions crosses the team_anchor
	 * threshold; the controller answer serializes the awarded Medal entity,
	 * the award is persisted with the awarder's display name, and a repeat
	 * check is a no-award no-write no-op.
	 */
	public function testCheckMedals_RealPersistedWeek_AwardsSerializedMedal_IdempotentOnRepeat(): void {
		$houseId = $this->instanceHouseId();
		$this->addInstanceMembership('med_parent', 'Medal Parent');
		$floorId = $this->createFloor($houseId, 'Medal Ground Floor', 901);
		// Three rooms so no single room reaches the room_guardian
		// threshold (5/room) — team_anchor must be the ONLY new award.
		$roomTaskIds = [];
		$roomNumber = 1;
		foreach (['Medal Kitchen', 'Medal Living Room', 'Medal Attic'] as $roomName) {
			$roomId = $this->createRoom($houseId, $floorId, $roomName);
			$taskId = $this->createTask($houseId, "Medal seed {$roomNumber}", '', 5, 2);
			$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, null);
			$roomTaskIds[] = $roomTaskId;
			$this->ledgerRoomTaskIds[] = $roomTaskId;
			$roomNumber++;
		}

		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'med_parent');
		$this->medalUserIds[] = $member->getId();

		// Ten persisted completions in the CURRENT medal week (Monday-start,
		// house timezone): exactly the team_anchor threshold, spread 4/3/3
		// across rooms.
		$calendarService = \OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class);
		$weekStart = $calendarService->weekStart(new \DateTime(), $calendarService->getTimeZone($this->houseRow($houseId)));
		$spread = [4, 3, 3];
		foreach ($spread as $i => $count) {
			for ($j = 0; $j < $count; $j++) {
				$this->insertLedgerRow($roomTaskIds[$i], (int)$member->getId(), $weekStart);
			}
		}

		$controller = $this->getController(GameController::class, 'med_parent');
		$response = $controller->checkMedals();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(1, $data['data']['count'], 'exactly the team_anchor medal is newly awarded');
		$awarded = $data['data']['awardedMedals'];
		self::assertCount(1, $awarded);
		self::assertSame('team_anchor', $awarded[0]['medalType']);
		self::assertSame(10, $awarded[0]['value']);
		self::assertSame($member->getId(), $awarded[0]['userId']);
		self::assertSame($weekStart->format('Y-m-d'), (string)$awarded[0]['weekStart']);
		self::assertArrayHasKey('awardedAt', $awarded[0]);
		self::assertArrayHasKey('medalLabel', $awarded[0]);

		// The award is persisted and serializes with the member's name.
		$medalsResponse = $controller->medals();
		self::assertSame(200, $medalsResponse->getStatus());
		$medals = $medalsResponse->getData()['data'];
		self::assertCount(1, $medals);
		self::assertSame('team_anchor', $medals[0]['medalType']);
		self::assertSame('Medal Parent', $medals[0]['userName']);

		// Repeat-read idempotence: the second check awards nothing new and
		// does not duplicate the persisted row.
		$repeat = $controller->checkMedals();
		self::assertSame(200, $repeat->getStatus());
		self::assertSame(0, $repeat->getData()['data']['count']);
		self::assertSame([], $repeat->getData()['data']['awardedMedals']);
		self::assertCount(1, $this->medalRows($member->getId()));
	}

	/**
	 * CheckMedals_EmptyWeek_AwardsNothing
	 */
	public function testCheckMedals_EmptyWeek_AwardsNothing(): void {
		$this->instanceHouseId();
		$this->addInstanceMembership('med_empty', 'Quiet Member');
		$controller = $this->getController(GameController::class, 'med_empty');

		$response = $controller->checkMedals();

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
		self::assertSame(0, $response->getData()['data']['count']);
		self::assertSame([], $response->getData()['data']['awardedMedals']);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function houseRow(int $houseId): House {
		return \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
	}

	private function insertLedgerRow(int $roomTaskId, int $houseUserId, \DateTime $completedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt->format('Y-m-d H:i:s')),
				'coins_earned' => $qb->createNamedParameter(4, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'before_state' => $qb->createNamedParameter('{}'),
				'deltas' => $qb->createNamedParameter('{}'),
				'occurrence_due' => $qb->createNamedParameter($completedAt->format('Y-m-d')),
			])
			->executeStatement();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function medalRows(int $houseUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_medals')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		self::assertIsArray($rows);
		return $rows;
	}
}
