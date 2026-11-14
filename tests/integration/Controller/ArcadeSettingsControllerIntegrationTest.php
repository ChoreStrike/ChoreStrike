<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\ArcadeSettingsController;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\ArcadeStartPointStore;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * CS-PLAN-090 WP-J (D-090-63/66): the owner's arcade controls resolved
 * through the DI container against the shared instance house — the owner
 * reads the progress list and resets a member, a child gets 403 on both,
 * and a target outside the house is refused without a write. The threshold
 * envelope is covered by the unit suite and the browser gate.
 */
class ArcadeSettingsControllerIntegrationTest extends IntegrationTestCase {
	/** @var int[] */
	private array $membershipIds = [];

	protected function tearDown(): void {
		if ($this->membershipIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->membershipIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$startPoints = \OC::$server->get(ArcadeStartPointStore::class);
		foreach (['as_owner', 'as_kid', 'as_outsider'] as $userId) {
			$startPoints->forget($userId);
		}
		parent::tearDown();
	}

	public function testOwner_ReadsTheProgressAndResetsAMember(): void {
		$this->instanceMembers();

		$read = $this->controller('as_owner')->progress();
		self::assertSame(200, $read->getStatus());
		$kid = array_column($read->getData()['data'], null, 'userId')['as_kid'];
		self::assertSame(['userId' => 'as_kid', 'displayName' => 'Arcade Kid', 'finishedChores' => 0, 'resetAt' => null], $kid);

		$reset = $this->controller('as_owner')->resetMember('as_kid');
		self::assertSame(200, $reset->getStatus());
		self::assertNotNull(array_column($reset->getData()['data'], null, 'userId')['as_kid']['resetAt']);
		self::assertArrayHasKey('as_kid', \OC::$server->get(ArcadeStartPointStore::class)->forUsers(['as_kid']));
	}

	public function testChild_GetsForbiddenOnTheReadAndTheReset(): void {
		$this->instanceMembers();

		self::assertSame(403, $this->controller('as_kid')->progress()->getStatus());
		self::assertSame(403, $this->controller('as_kid')->resetMember('as_kid')->getStatus());
		self::assertSame([], \OC::$server->get(ArcadeStartPointStore::class)->forUsers(['as_kid']), 'a refused reset writes nothing');
	}

	public function testOwner_ResetOfANonMember_RefusedWithoutWrite(): void {
		$this->instanceMembers();

		$response = $this->controller('as_owner')->resetMember('as_outsider');

		self::assertSame(403, $response->getStatus());
		self::assertSame([], \OC::$server->get(ArcadeStartPointStore::class)->forUsers(['as_outsider']));
	}

	private function controller(string $userId): ArcadeSettingsController {
		$this->actingAs($userId);
		return \OC::$server->get(ArcadeSettingsController::class);
	}

	private function instanceMembers(): void {
		$houseService = \OC::$server->get(HouseService::class);
		$houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->addMembership($houseId, 'as_owner', 'Arcade Owner', 'owner');
		$this->addMembership($houseId, 'as_kid', 'Arcade Kid', 'child');
	}

	private function addMembership(int $houseId, string $userId, string $displayName, string $role): void {
		if (\OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, $userId) !== null) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_house_users')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'nextcloud_user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter($displayName),
				'role' => $qb->createNamedParameter($role),
				'contribution_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'current_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'longest_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$this->membershipIds[] = (int)$this->db->lastInsertId('chorestrike_house_users');
	}
}
