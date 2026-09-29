<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Controller\RankingController;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for the ranking consent lifecycle against the real
 * Docker DB (CS-PLAN-070 WP4, C5i/C5l/D10b, Q-075-01 Option A).
 *
 * Covers: the default opt-in/closed state, the pending→effective consent
 * generation (next Monday, never retroactive), immediate withdrawal,
 * the disable wipe, departure deletion, and the on-time-ratio facts over
 * public agreed occurrences — including the never-exposed data classes.
 */
class RankingConsentTest extends IntegrationTestCase {
	private HouseService $houseService;
	private MemberPreferenceMapper $prefMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->houseService = \OC::$server->get(HouseService::class);
		$this->prefMapper = \OC::$server->get(MemberPreferenceMapper::class);
	}

	private function rankingIndex(string $userId, int $houseId): array {
		/** @var RankingController $controller */
		$controller = $this->getController(RankingController::class, $userId);
		// Bind the fixture house instead of the controller's instance house.
		$prop = new \ReflectionProperty(RankingController::class, 'houseService');
		$prop->setAccessible(true);
		$mock = $this->createMock(HouseService::class);
		$mock->method('getInstanceId')->willReturn('instance-1');
		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$mock->method('getOrCreateHouse')->willReturn($house);
		$prop->setValue($controller, $mock);

		$response = $controller->index();
		self::assertSame(200, $response->getStatus(), json_encode($response->getData()));
		return $response->getData()['data'];
	}

	private function giveConsent(string $userId, int $houseId): void {
		/** @var RankingController $controller */
		$controller = $this->getController(RankingController::class, $userId);
		$prop = new \ReflectionProperty(RankingController::class, 'houseService');
		$prop->setAccessible(true);
		$mock = $this->createMock(HouseService::class);
		$mock->method('getInstanceId')->willReturn('instance-1');
		$mock->method('getOrCreateHouse')->willReturn(\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId));
		$prop->setValue($controller, $mock);

		$response = $controller->consent();
		self::assertSame(200, $response->getStatus(), json_encode($response->getData()));
	}

	private function withdraw(string $userId, int $houseId): void {
		/** @var RankingController $controller */
		$controller = $this->getController(RankingController::class, $userId);
		$prop = new \ReflectionProperty(RankingController::class, 'houseService');
		$prop->setAccessible(true);
		$mock = $this->createMock(HouseService::class);
		$mock->method('getInstanceId')->willReturn('instance-1');
		$mock->method('getOrCreateHouse')->willReturn(\OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId));
		$prop->setValue($controller, $mock);

		$response = $controller->withdraw();
		self::assertSame(200, $response->getStatus(), json_encode($response->getData()));
	}

	private function seedHouseWithTwoMembers(): int {
		$houseId = $this->createHouse('rank_owner', 'Ranking House');
		$this->addHouseMember($houseId, 'rank_member', 'Rank Member', 'member');
		return $houseId;
	}

	private function makeConsentEffective(int $houseId, string $userId): void {
		// Move the saved Monday into the past (the generation facts stay —
		// the same semantics a real second Monday produces).
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, $userId);
		$pref = $this->prefMapper->findByHouseAndUser($houseId, $member->getId());
		self::assertNotNull($pref, 'a consent row must exist');

		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_member_prefs')
			->set('ranking_effective_monday', $qb->createNamedParameter('2026-01-05'))
			->set('ranking_saved_zone', $qb->createNamedParameter('UTC'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pref->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Flip the owner-only ranking flag through the production update path
	 * (as the owner session).
	 */
	private function setRankingFlag(int $houseId, bool $enabled): void {
		$this->actingAs('rank_owner');
		$house = $this->houseService->updateHouse($houseId, 'Ranking House', null, $enabled);
		self::assertSame($enabled, $house->getRankingEnabled());
	}

	public function testDefaultFlagIsOff_ViewShowsOptInClosedState(): void {
		$houseId = $this->seedHouseWithTwoMembers();

		$view = $this->rankingIndex('rank_owner', $houseId);

		self::assertFalse($view['rankingEnabled']);
		self::assertSame([], $view['entries']);
		self::assertSame([], $view['pending']);
		self::assertSame('none', $view['me']['state']);
	}

	public function testConsent_CreatesPendingGeneration_EffectiveNextMonday(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);

		$this->giveConsent('rank_member', $houseId);

		$view = $this->rankingIndex('rank_member', $houseId);
		self::assertTrue($view['rankingEnabled']);
		// Pending: no visible rank yet (C5l), but the own state shows it.
		self::assertSame('pending', $view['me']['state']);
		self::assertSame(1, $view['me']['generation']);
		self::assertNotEmpty($view['me']['effectiveMonday']);
		self::assertSame([], $view['entries']);
		self::assertCount(1, $view['pending']);
		self::assertSame('Rank Member', $view['pending'][0]['displayName']);
		self::assertSame($view['me']['effectiveMonday'], $view['pending'][0]['effectiveMonday']);
	}

	public function testEffectiveConsent_ShowsEntry_OthersStayHidden(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);

		$this->giveConsent('rank_member', $houseId);
		$this->makeConsentEffective($houseId, 'rank_member');

		// Seen from the owner (a non-consenting member): the member entry
		// is visible, the owner stays out, no foreign consent state leaks.
		$view = $this->rankingIndex('rank_owner', $houseId);
		self::assertCount(1, $view['entries']);
		self::assertSame('Rank Member', $view['entries'][0]['displayName']);
		self::assertSame([], $view['pending']);
		self::assertSame('none', $view['me']['state']);

		$json = json_encode($view);
		self::assertStringNotContainsString('rank_owner', $json, 'non-consenting members never appear');
		self::assertStringNotContainsString('contribution', $json);
		self::assertStringNotContainsString('bookedMinor', $json);
		self::assertStringNotContainsString('wallet', $json);
	}

	public function testWithdrawal_RemovesVisibleParticipationImmediately(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);

		$this->giveConsent('rank_member', $houseId);
		$this->makeConsentEffective($houseId, 'rank_member');
		self::assertCount(1, $this->rankingIndex('rank_owner', $houseId)['entries']);

		$this->withdraw('rank_member', $houseId);

		$view = $this->rankingIndex('rank_owner', $houseId);
		self::assertSame([], $view['entries'], 'withdrawal removes visible participation immediately');
		// Idempotent double-withdraw is a no-op.
		$this->withdraw('rank_member', $houseId);
		self::assertSame([], $this->rankingIndex('rank_owner', $houseId)['entries']);
	}

	public function testReConsent_StartsAFreshGeneration_NeverRetroactive(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);

		$this->giveConsent('rank_member', $houseId);
		$this->makeConsentEffective($houseId, 'rank_member');
		$this->withdraw('rank_member', $houseId);
		$this->giveConsent('rank_member', $houseId);

		$view = $this->rankingIndex('rank_member', $houseId);
		self::assertSame('pending', $view['me']['state'], 'a re-consent never activates retroactively');
		self::assertSame(2, $view['me']['generation'], 'the generation is fresh (withdrawn generation stays invalidated)');
	}

	public function testDisable_WipesEveryConsentGeneration(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);
		$this->giveConsent('rank_member', $houseId);
		$this->giveConsent('rank_owner', $houseId);
		self::assertCount(2, $this->prefMapper->findByHouseId($houseId));

		$this->setRankingFlag($houseId, false);

		self::assertSame([], $this->prefMapper->findByHouseId($houseId), 'disable invalidates every generation');
		$view = $this->rankingIndex('rank_member', $houseId);
		self::assertFalse($view['rankingEnabled']);
		self::assertSame('none', $view['me']['state'], 're-enabling starts everyone unconsented');
	}

	public function testDeparture_DeletesConsentWithMembership(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);
		$this->giveConsent('rank_member', $houseId);
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'rank_member');
		self::assertNotNull($this->prefMapper->findByHouseAndUser($houseId, $member->getId()));

		$this->actingAs('rank_owner');
		$this->houseService->removeHouseMember((int)$member->getId());

		self::assertNull($this->prefMapper->findByHouseAndUser($houseId, $member->getId()), 'consent belongs to the active membership');
	}

	public function testOnTimeRatio_CountsOnlyPublicAgreedOccurrences(): void {
		$houseId = $this->seedHouseWithTwoMembers();
		$this->setRankingFlag($houseId, true);

		// A public household task owned by the consenting member.
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Rank chore', '', 5, 3);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('visibility_type', $qb->createNamedParameter('public'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'rank_member');

		$this->giveConsent('rank_member', $houseId);
		$this->makeConsentEffective($houseId, 'rank_member');

		// One valid on-time agreed completion on the current occurrence.
		$completionService = \OC::$server->get(CompletionService::class);
		$this->completeOccurrence($completionService, $houseId, 'rank_member', $roomTaskId);

		$view = $this->rankingIndex('rank_member', $houseId);
		self::assertCount(1, $view['entries']);
		$entry = $view['entries'][0];
		self::assertSame(1, $entry['eligible'], 'the fulfilled public obligation is eligible');
		self::assertSame(1, $entry['onTime'], 'the valid on-time completion counts');
		self::assertSame(1, $entry['rank']);
		self::assertTrue($entry['isMe']);
		self::assertArrayHasKey('provisional', $view['week']);
	}
}
