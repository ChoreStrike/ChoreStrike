<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Deterministic fixture-lifecycle regression for TECH-TEST-003
 * (CS-PLAN-081 H2).
 *
 * The historical flake: on the FIRST full integration run after a
 * disposable-volume reset, `ChecklistApiTest::testDeleteTaskItem_Member_Returns403`
 * intermittently failed with DoesNotExistException on the shared instance
 * house during `IntegrationTestCase::initializeScheduleForFixture`. The
 * 2026-10-03 hypothesis correction established that the production lookup
 * re-queries (no memoized house) and structural deletion tombstones, so the
 * old deletion mechanism is not proven against the current code.
 *
 * This suite pins the CURRENT fixture contract instead of relying on
 * reruns:
 *
 *  - FixtureHouseLifecycleTwice_InOneProcess: two independent
 *    createHouse → floor → room → task → assignTaskToRoom lifecycles (the
 *    assignTaskToRoom path exercises the exact
 *    initializeScheduleForFixture → timeZoneForHouse seam the flake hit)
 *    succeed in ONE process, and the second lifecycle does not depend on
 *    the first — the harness owns every row it creates.
 *  - GenuinelyMissingHouse_StillRejected: the production read path fails
 *    loudly for a house id that was never created — the harness repair
 *    never masks a missing production house (no production fallback).
 */
class FixtureHouseLifecycleTest extends IntegrationTestCase {
	public function testFixtureHouseLifecycleTwice_InOneProcess(): void {
		foreach (['first', 'second'] as $round) {
			$houseId = $this->createHouse('fx_owner_' . $round, 'Fixture House ' . $round);
			$floorId = $this->createFloor($houseId, 'Ground Floor');
			$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
			$taskId = $this->createTask($houseId, 'Wash dishes ' . $round);
			// assignTaskToRoom drives the WP1 schedule-initialization seam
			// (houseIdForRoomId → timeZoneForHouse → initializeSchedule).
			$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'fx_owner_' . $round);

			self::assertGreaterThan(0, $roomTaskId, "$round lifecycle must materialize its room task");

			// The schedule was initialized through the production generator:
			// a completion receipt is resolvable for the fixture room task.
			[$commandId, $occurrenceId, $expectedVersion, $selectionToken] = $this->completionReceiptFor($roomTaskId);
			self::assertNotEmpty($commandId);
			self::assertNotEmpty($occurrenceId);
			self::assertIsInt($expectedVersion);
			self::assertGreaterThanOrEqual(0, $expectedVersion);
			self::assertNotEmpty($selectionToken);
		}
	}

	public function testGenuinelyMissingHouse_StillRejected(): void {
		$missingHouseId = 2147483000; // far outside any fixture id range

		$this->expectException(DoesNotExistException::class);

		\OC::$server->get(RoomTaskReadService::class)->timeZoneForHouse($missingHouseId);
	}
}
