<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Service\PreferenceService;
use OCA\ChoreStrike\Service\RankingService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The feedback default against the real database (CS-PLAN-090 WP-F,
 * D-090-05, D-090-20): the animation is on for a member without a row,
 * and every path that creates the row writes the delivery fields, so the
 * column default (`animation_enabled` 0) never decides. Runs on every
 * supported provider.
 */
class PreferenceDefaultsTest extends IntegrationTestCase {
	private PreferenceService $preferences;
	private MemberPreferenceMapper $prefMapper;
	private int $houseId;

	protected function setUp(): void {
		parent::setUp();
		$this->preferences = \OC::$server->get(PreferenceService::class);
		$this->prefMapper = \OC::$server->get(MemberPreferenceMapper::class);
		$this->houseId = $this->createHouse('pref_owner', 'Preference House');
		$this->addHouseMember($this->houseId, 'pref_kid', 'Pref Kid', 'child');
	}

	private function member(string $userId): HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($this->houseId, $userId);
	}

	/** The stored columns as the provider returns them, cast to booleans. */
	private function storedToggles(HouseUser $member): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('animation_enabled', 'sound_enabled', 'arcade_visible', 'catalog_visible', 'digest_time', 'version')
			->from('chorestrike_member_prefs')
			->where($qb->expr()->eq('house_user_id', $qb->createNamedParameter((int)$member->getId(), IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, 'the preference row exists');
		return [
			'animation' => (bool)$row['animation_enabled'],
			'sound' => (bool)$row['sound_enabled'],
			'arcade' => (bool)$row['arcade_visible'],
			'catalog' => (bool)$row['catalog_visible'],
			'digestTime' => $row['digest_time'],
			'version' => (int)$row['version'],
		];
	}

	public function testMemberWithoutARow_ReadsTheAnimationOnAndSoundOff(): void {
		$payload = $this->preferences->getOwnPreferences($this->houseId, $this->member('pref_kid'));

		self::assertSame(0, $payload['version']);
		self::assertTrue($payload['animationEnabled']);
		self::assertFalse($payload['soundEnabled']);
		self::assertNull($this->prefMapper->findByHouseAndUser($this->houseId, (int)$this->member('pref_kid')->getId()), 'a read creates no row');
	}

	public function testFirstSaveOfAnotherField_KeepsTheAnimationOn(): void {
		$member = $this->member('pref_kid');

		$payload = $this->preferences->updateOwnPreferences($this->houseId, $member,
			['expectedVersion' => 0, 'soundEnabled' => true], '21111111-1111-4111-8111-111111111111');

		self::assertTrue($payload['animationEnabled']);
		self::assertSame(
			['animation' => true, 'sound' => true, 'arcade' => true, 'catalog' => true, 'digestTime' => '18:00', 'version' => 1],
			$this->storedToggles($member)
		);
	}

	public function testFirstSaveWithTheAnimationOff_IsStoredAsOffAndStaysOff(): void {
		$member = $this->member('pref_kid');

		$payload = $this->preferences->updateOwnPreferences($this->houseId, $member,
			['expectedVersion' => 0, 'animationEnabled' => false], '21111111-1111-4111-8111-111111111112');
		self::assertFalse($payload['animationEnabled']);
		self::assertFalse($this->storedToggles($member)['animation']);

		// A later save of another field does not bring it back.
		$payload = $this->preferences->updateOwnPreferences($this->houseId, $member,
			['expectedVersion' => 1, 'soundEnabled' => true], '21111111-1111-4111-8111-111111111113');
		self::assertFalse($payload['animationEnabled']);
		self::assertSame(2, $payload['version']);
		self::assertFalse($this->storedToggles($member)['animation']);
	}

	public function testRowCreatedByRankingConsent_CarriesTheDeliveryDefaults(): void {
		$member = $this->member('pref_owner');

		\OC::$server->get(RankingService::class)->consent($this->houseId, $member);

		self::assertSame(
			['animation' => true, 'sound' => false, 'arcade' => true, 'catalog' => true, 'digestTime' => '18:00', 'version' => 1],
			$this->storedToggles($member)
		);
		$payload = $this->preferences->getOwnPreferences($this->houseId, $member);
		self::assertTrue($payload['animationEnabled']);
		self::assertSame('pending', $payload['ranking']['state']);
	}
}
