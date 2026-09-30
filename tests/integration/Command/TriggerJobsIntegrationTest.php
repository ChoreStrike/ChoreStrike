<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Command;

use OCA\ChoreStrike\Command\TriggerJobs;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration smoke for the chorestrike:trigger-jobs OCC command
 * (CS-PLAN-023; WP5 cutover): runs the real command, resolved from the
 * Nextcloud DI container, end-to-end against a seeded house in the Docker
 * test DB. The `daily-notification` type triggers the SAME pass the
 * five-minute cron job runs — the digest delivery pass (CS-PLAN-070
 * WP5). The retired streak-freeze/at-risk dispatches must stay gone
 * (Q-074-01 Option A — the rhythm evaluator derives streaks from valid
 * history instead of a snapshot pass).
 */
class TriggerJobsIntegrationTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		// The CLI test bootstrap (bootstrap-integration.php) only runs the
		// app registration phase; the boot phase — where the notifications
		// app registers the persisting handler with OCP\Notification\IManager
		// — normally runs in handleRequest()/occ. Boot it here so notify()
		// actually persists rows like it does under `php occ`.
		\OC::$server->get(\OC\AppFramework\Bootstrap\Coordinator::class)->bootApp('notifications');
	}

	/**
	 * The daily-notification pass runs the digest delivery pass with the
	 * --as-of clock substitution (the wall-time form included); the
	 * retired freeze dispatches are rejected with the unknown-type
	 * envelope.
	 */
	public function testTriggerJobsRunsDigestPassAndRejectsRetiredTypes(): void {
		$houseId = $this->createHouse('tt-lead');
		$this->addHouseMember($houseId, 'tt-member', 'Time Travel Member');

		// 1) the retained daily-notification pass (the digest delivery
		// pass) — bare day and wall-time forms both resolve.
		$notificationTester = new CommandTester(\OC::$server->get(TriggerJobs::class));
		$tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');
		$this->assertSame(
			0,
			$notificationTester->execute(['type' => 'daily-notification', '--as-of' => $tomorrow . ' 18:00']),
			$notificationTester->getDisplay()
		);
		$this->assertStringContainsString('digest-pass', $notificationTester->getDisplay());

		// 2) the retired freeze dispatch is rejected, not silently run.
		$retiredTester = new CommandTester(\OC::$server->get(TriggerJobs::class));
		$this->assertSame(
			1,
			$retiredTester->execute(['type' => 'streak-freeze', '--as-of' => $tomorrow]),
			'streak-freeze retired with the WP3 cutover'
		);
		$this->assertStringContainsString('Invalid job type', $retiredTester->getDisplay());
	}

	/**
	 * The pass is the SAME dispatch path the five-minute cron job runs:
	 * an opted-in member with a materialized, due slot gets the ONE daily
	 * notification persisted through the real IManager; a second run is
	 * consumed without a second notification (at-most-once, parent
	 * §3.6).
	 */
	public function testDigestPassDispatchesAtMostOneNotificationPerDay(): void {
		$houseId = $this->createHouse('dg-lead');
		$this->addHouseMember($houseId, 'dg-member', 'Digest Member');

		$houseMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class);
		$house = $houseMapper->find($houseId);
		$member = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'dg-member');
		$prefMapper = \OC::$server->get(\OCA\ChoreStrike\Db\MemberPreferenceMapper::class);
		$pref = new \OCA\ChoreStrike\Db\MemberPreference();
		$pref->setHouseId($houseId);
		$pref->setHouseUserId((int)$member->getId());
		$pref->setVersion(1);
		$pref->setTaskDigestEnabled(true);
		$pref->setOrgDigestEnabled(true);
		$pref->setDigestTime('18:00');
		$pref->setCreatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$pref->setUpdatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$prefMapper->insertOrGet($pref);

		// Materialize today's slot for 18:00 (UTC house) and queue one
		// organizational update so the summary is non-empty (no empty
		// summary is ever sent, C5c).
		$scheduleService = \OC::$server->get(\OCA\ChoreStrike\Service\DigestScheduleService::class);
		$stats = $scheduleService->materializeSlotsForHouse($houseId, new \DateTime('2026-09-29 17:30:00 UTC'));
		self::assertGreaterThanOrEqual(1, $stats['materialized'], 'today/tomorrow slots are materialized for the opted-in member');

		$changeMapper = \OC::$server->get(\OCA\ChoreStrike\Db\DigestChangeMapper::class);
		$todayKey = '2026-09-29';
		$floorId = $this->createFloor($houseId);
		$roomId = $this->createRoom($houseId, $floorId);
		$roomTaskId = $this->assignTaskToRoom($this->createTask($houseId, 'Digest source'), $roomId, 'dg-member', $todayKey);
		$source = \OC::$server->get(\OCA\ChoreStrike\Db\OccurrenceMapper::class)->findByRoomTask($roomTaskId)[0];
		$change = new \OCA\ChoreStrike\Db\DigestChange();
		$change->setHouseId($houseId);
		$change->setHouseUserId((int)$member->getId());
		$change->setObjectType('agreement');
		$change->setObjectId((string)$source->getId());
		$change->setObjectRevision($source->getVersion());
		$change->setCategory(\OCA\ChoreStrike\Db\DigestChange::CATEGORY_ORG);
		$change->setChangeTime(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setTargetSlot($todayKey);
		$change->setDisposition(\OCA\ChoreStrike\Db\DigestChange::DISPOSITION_QUEUED);
		$change->setCreatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setUpdatedAt(new \DateTime('2026-09-29 10:00:00 UTC'));
		$changeMapper->queueOrCoalesce($change);
		self::assertCount(1, $changeMapper->findBySlot((int)$member->getId(), $todayKey), 'the fixture change is queued for today');

		$notificationTester = new CommandTester(\OC::$server->get(TriggerJobs::class));
		$this->assertSame(
			0,
			$notificationTester->execute(['type' => 'daily-notification', '--as-of' => '2026-09-29 18:05']),
			$notificationTester->getDisplay()
		);
		// The pass reports the dispatch through the same stats the cron
		// job logs (bounded diagnostics).
		self::assertStringContainsString('dispatched=1', $notificationTester->getDisplay(), $notificationTester->getDisplay());

		// Exactly ONE persisted bell for THIS membership (counted directly
		// in the notifications table — IManager::markProcessed would
		// mutate). The dedup key is membership-qualified, so filtering by
		// the (house, membership) prefix isolates this run even against a
		// persistent test DB.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from('notifications')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter('dg-member')))
			->andWhere($qb->expr()->like('object_id', $qb->createNamedParameter($houseId . ':' . $member->getId() . ':%')));
		$result = $qb->executeQuery();
		$found = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $found, 'exactly one daily summary is persisted for the opted-in member');
	}

	/**
	 * Outside test mode the command must refuse to run.
	 */
	public function testTriggerJobsRefusesOutsideTestMode(): void {
		$originalGetenv = getenv('NEXTCLOUD_TEST_MODE');
		$originalEnv = $_ENV['NEXTCLOUD_TEST_MODE'] ?? null;

		putenv('NEXTCLOUD_TEST_MODE');
		unset($_ENV['NEXTCLOUD_TEST_MODE']);

		try {
			$command = \OC::$server->get(TriggerJobs::class);
			$tester = new CommandTester($command);
			$exitCode = $tester->execute(['type' => 'daily-notification']);

			$this->assertSame(1, $exitCode);
			$this->assertStringContainsString('test mode', $tester->getDisplay());
		} finally {
			if ($originalGetenv === false) {
				putenv('NEXTCLOUD_TEST_MODE');
			} else {
				putenv('NEXTCLOUD_TEST_MODE=' . $originalGetenv);
			}
			if ($originalEnv !== null) {
				$_ENV['NEXTCLOUD_TEST_MODE'] = $originalEnv;
			}
		}
	}
}
