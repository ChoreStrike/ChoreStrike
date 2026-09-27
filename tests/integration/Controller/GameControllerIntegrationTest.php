<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\GameController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\WalletService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for GameController (CS-PLAN-070 WP3 cutover):
 * `/game/state` returns the viewer-safe member progression plus the
 * caller's OWN wallet in minor units — no aggregate coin totals, no
 * combo state; `checkMedals` awards the retained rhythm tiers from the
 * member's recomputed valid-history streak.
 */
class GameControllerIntegrationTest extends IntegrationTestCase {
	/** @var list<int> */
	private array $instanceMembershipIds = [];
	/** @var list<int> */
	private array $medalUserIds = [];

	protected function tearDown(): void {
		if ($this->instanceMembershipIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->instanceMembershipIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$this->instanceMembershipIds = [];
		}
		foreach ($this->medalUserIds as $houseUserId) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_medals')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$this->medalUserIds = [];
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

	private function addInstanceMembership(string $userId, string $displayName, string $role = 'member', int $currentStreak = 0): int {
		$houseId = $this->instanceHouseId();
		$existing = \OC::$server->get(HouseUserMapper::class)
			->findByHouseAndUser($houseId, $userId);
		if ($existing !== null) {
			return $existing->getId();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_house_users')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'nextcloud_user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter($displayName),
				'role' => $qb->createNamedParameter($role),
				'contribution_minor' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'current_streak' => $qb->createNamedParameter($currentStreak, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'longest_streak' => $qb->createNamedParameter($currentStreak, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$id = (int)$this->db->lastInsertId('chorestrike_house_users');
		$this->instanceMembershipIds[] = $id;

		// WP3: memberships get a wallet binding through the production
		// path so /game/state can expose the caller's own wallet.
		\OC::$server->get(WalletService::class)
			->ensureBinding($houseId, \OC::$server->get(HouseUserMapper::class)->find($id));

		return $id;
	}

	/**
	 * State_InstanceMember_ReturnsProgressionAndOwnWallet
	 *
	 * WP3 §3.6: the state carries the member progression (viewer-safe)
	 * and the caller's OWN wallet in minor units; no aggregate coin
	 * totals or combo state exist anymore.
	 */
	public function testState_InstanceMember_ReturnsProgressionAndOwnWallet(): void {
		$this->addInstanceMembership('game_parent', 'Game Parent');

		$controller = $this->getController(GameController::class, 'game_parent');
		$response = $controller->state();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertArrayHasKey('users', $data['data']);
		self::assertArrayHasKey('weeklyGoal', $data['data']);

		// The caller's own wallet is exposed in exact minor units.
		self::assertArrayHasKey('wallet', $data['data']);
		self::assertIsArray($data['data']['wallet']);
		self::assertArrayHasKey('availableMinor', $data['data']['wallet']);
		self::assertArrayHasKey('bookedMinor', $data['data']['wallet']);
		self::assertSame(0, $data['data']['contributionMinor']);

		// The retired aggregate economy fields stay gone.
		self::assertArrayNotHasKey('totalCoins', $data['data']);
		self::assertArrayNotHasKey('activeCombos', $data['data']);
	}

	/**
	 * State_WalletNeverExposesForeignMembers
	 *
	 * Each member row in `users` is viewer-safe: no wallet balances ride
	 * the progression list.
	 */
	public function testState_WalletNeverExposesForeignMembers(): void {
		$this->addInstanceMembership('game_other', 'Other Member');

		$controller = $this->getController(GameController::class, 'game_other');
		$response = $controller->state();

		self::assertSame(200, $response->getStatus());
		foreach ($response->getData()['data']['users'] as $userRow) {
			self::assertArrayNotHasKey('coins', $userRow);
			self::assertArrayNotHasKey('bookedMinor', $userRow);
			self::assertArrayNotHasKey('walletId', $userRow);
		}
	}

	/**
	 * Medals_ReturnsAllMedals_SuccessResponse
	 *
	 * CS-PLAN-067 §3.2: the medal listing is served by MedalService.
	 */
	public function testMedals_ReturnsAllMedals_SuccessResponse(): void {
		$this->addInstanceMembership('game_medal_viewer', 'Medal Viewer');

		$controller = $this->getController(GameController::class, 'game_medal_viewer');
		$response = $controller->medals();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertIsArray($data['data']);
	}

	/**
	 * CheckMedals_ForgedCache_NeverAwardsUnsupportedRhythmTier
	 *
	 * A manually stale member cache with no supporting occurrences is not
	 * proof of a legitimately earned rhythm tier.
	 */
	public function testCheckMedals_ForgedCache_NeverAwardsUnsupportedRhythmTier(): void {
		$this->addInstanceMembership('med_parent', 'Medal Parent', 'member', 3);
		$member = \OC::$server->get(HouseUserMapper::class)
			->findByNextcloudUserId($this->instanceHouseId(), 'med_parent');
		$this->medalUserIds[] = $member->getId();

		$controller = $this->getController(GameController::class, 'med_parent');
		$response = $controller->checkMedals();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(0, $data['data']['count'], 'unsupported cached streak must be refreshed');
		$awarded = $data['data']['awardedMedals'];
		self::assertSame([], $awarded);

		// The award is persisted and serializes with the member's name.
		$medalsResponse = $controller->medals();
		self::assertSame(200, $medalsResponse->getStatus());
		$medals = $medalsResponse->getData()['data'];
		self::assertSame([], $medals);

		// Repeat-read idempotence: the second check awards nothing new and
		// does not duplicate the persisted row.
		$repeat = $controller->checkMedals();
		self::assertSame(200, $repeat->getStatus());
		self::assertSame(0, $repeat->getData()['data']['count']);
		self::assertSame([], $repeat->getData()['data']['awardedMedals']);
		self::assertCount(0, $this->medalRows($member->getId()));
	}

	/**
	 * CheckMedals_NoStreak_AwardsNothing
	 */
	public function testCheckMedals_NoStreak_AwardsNothing(): void {
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
	 * @return array<int, array<string, mixed>>
	 */
	private function medalRows(int $houseUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('chorestrike_medals')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($houseUserId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$rows = $qb->executeQuery()->fetchAll();
		self::assertIsArray($rows);
		return $rows;
	}
}
