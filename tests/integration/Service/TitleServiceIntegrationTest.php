<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for TitleService against the real Docker DB
 * (CS-PLAN-006 Phase 4).
 *
 * Verifies that selectPack persists, getCurrentTitle reads live stats, and
 * detectUnlock + the unlocked_titles cache round-trip through the mapper +
 * the new JSON column.
 */
class TitleServiceIntegrationTest extends IntegrationTestCase {
	private TitleService $titleService;
	private HouseUserMapper $houseUserMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->titleService = \OC::$server->get(TitleService::class);
		$this->houseUserMapper = \OC::$server->get(HouseUserMapper::class);
	}

	/**
	 * Create a member in a fresh house with the given stats and return the
	 * reloaded HouseUser entity.
	 */
	private function memberWithStats(int $contributionMinor, int $longestStreak, int $currentStreak) {
		$houseId = $this->createHouse('title-owner-' . uniqid());
		$userId = 'title-member-' . uniqid();
		$this->addHouseMember($houseId, $userId, 'Title Member', 'member');

		// Bump the stats directly so we don't have to grind completions.
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_house_users')
			->set('contribution_minor', $qb->createNamedParameter($contributionMinor))
			->set('longest_streak', $qb->createNamedParameter($longestStreak))
			->set('current_streak', $qb->createNamedParameter($currentStreak))
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId)));
		$qb->executeStatement();

		return $this->houseUserMapper->findByNextcloudUserId($houseId, $userId);
	}

	public function testSelectPack_PersistsAndGetCurrentTitleReadsLiveStats(): void {
		$user = $this->memberWithStats(150000, 1, 1);

		$this->titleService->selectPack($user, $user, 'home_helpers');

		$reloaded = $this->houseUserMapper->find($user->getId());
		$this->assertSame('home_helpers', $reloaded->getSelectedTitlePack());

		$title = $this->titleService->getCurrentTitle($reloaded);
		$this->assertNotNull($title);
		$this->assertSame('home_helpers', $title['pack']);
		$this->assertSame('care_captain', $title['tierKey']);
	}

	public function testDetectUnlock_PersistsCacheToTheJsonColumn(): void {
		$user = $this->memberWithStats(6000, 8, 0);

		$unlocked = $this->titleService->detectUnlock($user);
		// detectUnlock only mutates in-memory — persist via the mapper.
		$this->houseUserMapper->update($user);

		$this->assertNotEmpty($unlocked);

		$reloaded = $this->houseUserMapper->find($user->getId());
		$cached = $reloaded->getUnlockedTitles();
		$this->assertContains('home_helpers.helper', $cached);
		$this->assertContains('team_energy.spark_starter', $cached);
	}

	public function testGetCurrentTitle_WithConfigOverride_AppliesLowerThreshold(): void {
		/** @var RewardConfigService $config */
		$config = \OC::$server->get(RewardConfigService::class);
		$original = $config->getTitleThreshold('home_helpers', 0);
		try {
			$config->setTitleThreshold('home_helpers', 0, 5);
			$user = $this->memberWithStats(10, 1, 1);
			$user->setSelectedTitlePack('home_helpers');

			$title = $this->titleService->getCurrentTitle($user);
			$this->assertNotNull($title);
			$this->assertSame('helper', $title['tierKey']);
		} finally {
			$config->setTitleThreshold('home_helpers', 0, $original);
		}
	}
}
