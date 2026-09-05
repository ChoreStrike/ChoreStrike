<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Setup;

use OCA\ChoreStrike\Controller\SetupController;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\HouseSetupService;
use OCA\ChoreStrike\Service\TemplateService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Integration tests for the atomic first-run setup (CS-PLAN-062 §3.1/§3.3/§4).
 *
 * The races are reproduced deterministically with two real connections (the
 * `proc_open` helper pattern from BootstrapConcurrencyTest): the helper
 * process plays the concurrent winner (holds the house-row lock inside an
 * uncommitted transaction), the test process plays the loser whose apply
 * blocks at the lock and then either conflicts (409 path) or fails the
 * under-lock membership revalidation (400 path, zero writes).
 *
 * MySQL/MariaDB only — SQLite has no row-level concurrency to prove; the
 * tolerant code paths are exercised there as straight-line behavior.
 */
class SetupApplyConcurrencyTest extends IntegrationTestCase {
	/** @var int[] Houses whose helper-created structure must be cleaned. */
	private array $raceHouseIds = [];

	private int $instanceHouseId;

	protected function setUp(): void {
		parent::setUp();

		$houseService = \OC::$server->get(HouseService::class);
		$this->actingAs('sac_bootstrapper');
		$this->instanceHouseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->purgeInstanceHouse();
	}

	protected function tearDown(): void {
		if (!empty($this->raceHouseIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_floors')
				->where($qb->expr()->in('house_id', $qb->createNamedParameter($this->raceHouseIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();

			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_houses')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->raceHouseIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}

		$this->purgeInstanceHouse();
		parent::tearDown();
	}

	/**
	 * Two-connection apply race: the winner (helper) commits its structure
	 * under the house lock, the loser (this process) blocks at the lock and
	 * must get ConflictException — exactly one structure, zero loser rows.
	 */
	public function testConcurrentApplyRace_ExactlyOneStructure_LoserGetsConflict(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$ownerUserId = 'sac-race-owner-' . uniqid();
		$houseId = $this->createHouse($ownerUserId);
		$this->raceHouseIds[] = $houseId;

		// T1 (helper): lock the house row, insert the winning floor
		// (uncommitted), signal, hold, commit.
		$signalFile = sys_get_temp_dir() . '/cs-setup-race-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-setup-race-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildFloorInsertHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the setup-race holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The setup-race holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			// T2 (this process): applySetup blocks at findForUpdate until T1
			// commits, then the in-transaction floor recheck must conflict.
			$this->actingAs($ownerUserId);
			$setupService = \OC::$server->get(HouseSetupService::class);

			try {
				$setupService->applySetup($houseId, $this->validPayload());
				self::fail('The racing loser must be rejected with a conflict');
			} catch (ConflictException $e) {
				self::assertStringContainsString('already set up', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Exactly one structure: only the winner's single floor exists.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_floors')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$floorCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $floorCount, 'exactly one floor (the winner\'s) may exist after the race');
	}

	/**
	 * Membership removed between the pre-transaction validation and the
	 * under-lock revalidation: the apply fails with 400 and persists zero
	 * floors/rooms/tasks/assignees (CS-PLAN-062 §3.3).
	 */
	public function testRemovedMemberAssigneeDuringApply_Yields400_AndZeroWrites(): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			self::markTestSkipped('The two-connection race scenario requires MySQL/MariaDB (the Docker test DB).');
		}

		$ownerUserId = 'sac-del-owner-' . uniqid();
		$assigneeUserId = 'sac-del-assignee-' . uniqid();
		$houseId = $this->createHouse($ownerUserId);
		$this->raceHouseIds[] = $houseId;
		$this->addHouseMember($houseId, $assigneeUserId, 'Race Assignee', 'member');

		// T1 (helper): lock the house row, signal, hold, DELETE the assignee's
		// membership, commit — so the removal lands between T2's pre-check
		// (plain read, still sees the member) and T2's under-lock revalidation.
		$signalFile = sys_get_temp_dir() . '/cs-setup-del-' . uniqid();
		$helperScript = sys_get_temp_dir() . '/cs-setup-del-holder-' . uniqid() . '.php';
		file_put_contents($helperScript, $this->buildMembershipDeleteHolderScript());

		$process = proc_open(
			[PHP_BINARY, $helperScript, (string)$houseId, $signalFile, $assigneeUserId],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		self::assertIsResource($process, 'failed to start the membership-delete holder process');

		try {
			$deadline = microtime(true) + 30.0;
			while (!file_exists($signalFile)) {
				if (microtime(true) > $deadline) {
					self::fail('The membership-delete holder did not acquire the house lock in time');
				}
				usleep(50000);
			}

			$this->actingAs($ownerUserId);
			$setupService = \OC::$server->get(HouseSetupService::class);

			$payload = $this->validPayload();
			$payload['floors'][0]['rooms'][0]['assigneeUserId'] = $assigneeUserId;

			try {
				$setupService->applySetup($houseId, $payload);
				self::fail('The removed-member assignee must fail the under-lock revalidation');
			} catch (\InvalidArgumentException $e) {
				self::assertStringContainsString('Assignee is not a member', $e->getMessage());
			}
		} finally {
			$this->terminateHelper($process, $pipes);
			@unlink($signalFile);
			@unlink($helperScript);
		}

		// Zero writes survived the rollback.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_floors')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$floorCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(0, $floorCount, 'a failed revalidation must persist zero floors');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_task_assignees')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($assigneeUserId)));
		$result = $qb->executeQuery();
		$assigneeCount = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(0, $assigneeCount, 'a failed revalidation must persist zero assignee rows');
	}

	/**
	 * Controller-level matrix against the real instance house (CS-PLAN-062):
	 * first owner 200, member 403, non-member 403 (invite-only), and a
	 * second apply on the now-set-up house 409 HOUSE_ALREADY_SET_UP.
	 */
	public function testControllerMatrix_OwnerMemberNonMemberAndAlreadySetUp(): void {
		$ownerUserId = 'sac-matrix-owner-' . uniqid();
		$memberUserId = 'sac-matrix-member-' . uniqid();
		$strangerUserId = 'sac-matrix-stranger-' . uniqid();

		// Owner: bootstrap preamble auto-joins the first user, the service
		// applies the (valid) payload.
		$this->actingAs($ownerUserId);
		$response = $this->makeController($this->validPayload())->apply();
		self::assertSame(200, $response->getStatus());
		$body = $response->getData();
		self::assertTrue($body['success']);
		self::assertArrayHasKey('houseName', $body['data']);

		// Member (invited by fixture): the service owner gate denies.
		$this->addHouseMember($this->instanceHouseId, $memberUserId, 'Matrix Member', 'member');
		$this->actingAs($memberUserId);
		$response = $this->makeController($this->validPayload())->apply();
		self::assertSame(403, $response->getStatus());

		// Non-member: the invite-only bootstrap preamble denies (anti-
		// enumeration: same 403 shape as any other denial).
		$this->actingAs($strangerUserId);
		$response = $this->makeController($this->validPayload())->apply();
		self::assertSame(403, $response->getStatus());

		// Already set up: the owner's second apply hits the in-transaction
		// gate and gets the unchanged 409 envelope.
		$this->actingAs($ownerUserId);
		$response = $this->makeController($this->validPayload())->apply();
		self::assertSame(409, $response->getStatus());
		$body = $response->getData();
		self::assertFalse($body['success']);
		self::assertSame('HOUSE_ALREADY_SET_UP', $body['error']['code']);
	}

	// ========== Helpers ==========

	private function validPayload(): array {
		return [
			'intensityLevel' => TemplateService::LEVEL_PUTZTEUFEL,
			'options' => ['createHouseholdRoom' => false, 'createHallways' => false],
			'floors' => [
				['name' => 'Ground Floor', 'rooms' => [['name' => 'Kitchen', 'roomType' => 'kitchen']]],
			],
		];
	}

	/**
	 * A SetupController with a mocked request (payload provider) but the
	 * real DI services — controller-level matrix per the actingAs pattern.
	 */
	private function makeController(array $payload): SetupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($payload);

		return new SetupController(
			$request,
			\OC::$server->get(TemplateService::class),
			\OC::$server->get(HouseSetupService::class),
			\OC::$server->get(HouseService::class),
			\OC::$server->get(AuthorizationService::class),
			\OC::$server->get(\OCP\L10N\IFactory::class)->get('chorestrike'),
			\OC::$server->get(AppLogger::class),
		);
	}

	/**
	 * Remove all floors/rooms/tasks under the instance house and every
	 * membership, so the shared instance house starts/ends structurally
	 * empty (the controller matrix runs against it).
	 */
	private function purgeInstanceHouse(): void {
		$floorIds = $this->collectColumn('chorestrike_floors', 'id', 'house_id', $this->instanceHouseId);
		if ($floorIds !== []) {
			$roomIds = $this->collectColumnIn('chorestrike_rooms', 'id', 'floor_id', $floorIds);
			if ($roomIds !== []) {
				$roomTaskIds = $this->collectColumnIn('chorestrike_room_tasks', 'id', 'room_id', $roomIds);
				$taskIds = $this->collectColumnIn('chorestrike_room_tasks', 'task_id', 'room_id', $roomIds);
				if ($roomTaskIds !== []) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete('chorestrike_task_assignees')
						->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)));
					$qb->executeStatement();
				}
				$this->deleteColumnIn('chorestrike_room_tasks', 'room_id', $roomIds);
				$this->deleteColumnIn('chorestrike_rooms', 'floor_id', $floorIds);
				if ($taskIds !== []) {
					$this->deleteColumnIn('chorestrike_tasks', 'id', $taskIds);
				}
			}
			$this->deleteColumnIn('chorestrike_floors', 'id', $floorIds);
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('chorestrike_house_users')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->instanceHouseId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @return int[] */
	private function collectColumn(string $table, string $selectCol, string $whereCol, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($selectCol)->from($table)
			->where($qb->expr()->eq($whereCol, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$ids = [];
		while ($row = $res->fetch()) {
			$ids[] = (int)$row[$selectCol];
		}
		$res->closeCursor();
		return $ids;
	}

	/** @param int[] $values @return int[] */
	private function collectColumnIn(string $table, string $selectCol, string $whereCol, array $values): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($selectCol)->from($table)
			->where($qb->expr()->in($whereCol, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)));
		$res = $qb->executeQuery();
		$ids = [];
		while ($row = $res->fetch()) {
			$ids[] = (int)$row[$selectCol];
		}
		$res->closeCursor();
		return $ids;
	}

	/** @param int[] $values */
	private function deleteColumnIn(string $table, string $whereCol, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->in($whereCol, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	private function terminateHelper($process, array $pipes): void {
		$status = proc_get_status($process);
		if ($status !== false && $status['running']) {
			proc_terminate($process);
		}
		foreach ([1, 2] as $pipe) {
			if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
				fclose($pipes[$pipe]);
			}
		}
		proc_close($process);
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the house
	 * row (first of the documented lock order), inserts the winning floor
	 * (uncommitted), signals the parent, holds briefly, then commits.
	 */
	private function buildFloorInsertHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = :id FOR UPDATE',
    ['id' => $houseId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

$qb = $db->getQueryBuilder();
$qb->insert('chorestrike_floors')
    ->values([
        'house_id' => $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'name' => $qb->createNamedParameter('Winner Floor'),
        'sort_order' => $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
    ]);
$qb->executeStatement();

file_put_contents($signalFile, 'locked');
usleep(2000000);
$db->commit();
PHP;
	}

	/**
	 * The T1 helper: boots Nextcloud, opens a transaction, locks the house
	 * row, signals the parent, holds briefly, deletes the assignee's
	 * membership, then commits — the removal lands between the loser's
	 * pre-transaction validation and its under-lock revalidation.
	 */
	private function buildMembershipDeleteHolderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$houseId = (int)$argv[1];
$signalFile = $argv[2];
$assigneeUserId = $argv[3];

$db = \OC::$server->get(\OCP\IDBConnection::class);

$db->beginTransaction();
$result = $db->executeQuery(
    'SELECT id FROM *PREFIX*chorestrike_houses WHERE id = :id FOR UPDATE',
    ['id' => $houseId],
    ['id' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]
);
$result->closeCursor();

file_put_contents($signalFile, 'locked');
usleep(2000000);

$qb = $db->getQueryBuilder();
$qb->delete('chorestrike_house_users')
    ->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
    ->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($assigneeUserId)));
$qb->executeStatement();

$db->commit();
PHP;
	}
}
