<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\TitleService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Integration test for TitleService against the real Docker DB
 * (CS-PLAN-006 Phase 4; WP4 single-series cutover per CS-PLAN-070
 * C3d/D7c, Q-075-02 A).
 *
 * Verifies that the current title derives from live stats only (no pack
 * selection), and that detectUnlock + the unlocked_titles marker
 * round-trip through the mapper and the JSON column.
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

	/**
	 * Q-075-02 A: the current title is always the highest qualifying
	 * retained rhythm tier — qualification reads the valid-history
	 * maximum (longestStreak) and ignores the retired pack selection.
	 */
	public function testGetCurrentTitle_DerivesFromValidHistoryMaximum(): void {
		// longestStreak 12 → rhythm_12 despite a broken current run.
		$user = $this->memberWithStats(150000, 12, 1);

		$title = $this->titleService->getCurrentTitle($user);
		$this->assertNotNull($title);
		$this->assertSame('family_rhythm', $title['pack']);
		$this->assertSame('rhythm_12', $title['tierKey']);

		// Retired signals (contribution/current streak) alone qualify nothing.
		$onlyContribution = $this->memberWithStats(150000, 0, 90);
		$this->assertNull($this->titleService->getCurrentTitle($onlyContribution));
	}

	public function testDetectUnlock_PersistsMarkerToTheJsonColumn(): void {
		$user = $this->memberWithStats(0, 8, 8);

		$unlocked = $this->titleService->detectUnlock($user);
		// detectUnlock only mutates in-memory — persist via the mapper.
		$this->houseUserMapper->update($user);

		$this->assertNotEmpty($unlocked);

		$reloaded = $this->houseUserMapper->find($user->getId());
		$cached = $reloaded->getUnlockedTitles();
		$this->assertContains('family_rhythm.rhythm_3', $cached);
		$this->assertContains('family_rhythm.rhythm_6', $cached);
		$this->assertNotContains('family_rhythm.rhythm_12', $cached);
	}

	/**
	 * WP4 pin: the threshold policy is fixed — no config override path
	 * exists to bend the 3/6/12 rungs.
	 */
	public function testThresholds_AreFixedPolicy(): void {
		$reflection = new \ReflectionClass(\OCA\ChoreStrike\Service\RewardConfigService::class);
		$this->assertFalse($reflection->hasMethod('getTitleThreshold'));
		$this->assertFalse($reflection->hasMethod('setTitleThreshold'));

		// Exactly 3 still unlocks tier 1.
		$user = $this->memberWithStats(0, 3, 3);
		$title = $this->titleService->getCurrentTitle($user);
		$this->assertNotNull($title);
		$this->assertSame('rhythm_3', $title['tierKey']);
		$this->assertSame(3, $title['threshold']);
	}
}
