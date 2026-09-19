<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * CS-PLAN-071 WP0 T0 schema gate (parent §4 "Verification"): the real
 * fresh-install migration chain (Docker test DB) carries the WP0 schema
 * surface and the tombstone/identity constraints hold on the REAL engine —
 * not only against the recording schema of the unit smoke test.
 *
 * Covered here:
 *  - Version1000/1004 chain edits: tombstone columns, re-keyed uniq_rt,
 *    house state/version columns, house_users.wallet_id, nullable
 *    completed_tasks actor references (with defaults);
 *  - Version1030: commands/wallets/wallet_entries tables and their unique
 *    indexes;
 *  - Q-70-01 behavior: a tombstoned room task keeps its (room, task) pair
 *    unique, allows a re-created live link, and retains completed_tasks;
 *  - active-planning read filters exclude tombstoned structure rows;
 *  - HouseMapper::bumpStateVersion (the T0-demonstrated version mechanism).
 */
class FamilyRhythmSchemaTest extends IntegrationTestCase {
	private array $cleanupWalletIds = [];
	private array $cleanupWalletEntryIds = [];
	private array $cleanupCommandIds = [];
	private array $cleanupCompletionIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupCommandIds as $id) {
			$this->deleteById('chorestrike_commands', $id);
		}
		foreach ($this->cleanupWalletEntryIds as $id) {
			$this->deleteById('chorestrike_wallet_entries', $id);
		}
		foreach ($this->cleanupWalletIds as $id) {
			$this->deleteById('chorestrike_wallets', $id);
		}
		foreach ($this->cleanupCompletionIds as $id) {
			$this->deleteById('chorestrike_completed_tasks', $id);
		}
		parent::tearDown();
	}

	private function deleteById(string $table, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * FreshInstallSchema_CarriesWP0ColumnsAndDefaults
	 */
	public function testFreshInstallSchema_CarriesWP0ColumnsAndDefaults(): void {
		$houseId = $this->createHouse('frs_owner', 'FRS House');

		// The house row was inserted WITHOUT the new columns — defaults apply.
		$qb = $this->db->getQueryBuilder();
		$qb->select('state_version', 'timezone_version', 'catalog_enabled', 'ranking_enabled')
			->from('chorestrike_houses')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($row, 'the version/flag columns must exist on houses');
		self::assertSame(0, (int)$row['state_version'], 'state_version defaults to 0');
		self::assertSame(0, (int)$row['timezone_version'], 'timezone_version defaults to 0');
		self::assertSame(0, (int)$row['catalog_enabled'], 'catalog_enabled ships frozen false');
		self::assertSame(0, (int)$row['ranking_enabled'], 'ranking_enabled ships frozen false');

		// Tombstone columns default to NULL (live rows).
		$floorId = $this->createFloor($houseId, 'FRS Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'FRS Room');
		$roomTaskId = $this->assignTaskToRoom($this->createTask($houseId, 'FRS Task'), $roomId, 'frs_owner');

		$qb = $this->db->getQueryBuilder();
		$qb->select('f.deleted_at AS f_deleted', 'r.deleted_at AS r_deleted', 'rt.deleted_at AS rt_deleted', 'rt.deleted_token', 'rt.is_active')
			->from('chorestrike_floors', 'f')
			->innerJoin('f', 'chorestrike_rooms', 'r', $qb->expr()->eq('r.floor_id', 'f.id'))
			->innerJoin('r', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('rt.room_id', 'r.id'))
			->where($qb->expr()->eq('rt.id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($row);
		self::assertNull($row['f_deleted'], 'floors.deleted_at defaults to NULL (live)');
		self::assertNull($row['r_deleted'], 'rooms.deleted_at defaults to NULL (live)');
		self::assertNull($row['rt_deleted'], 'room_tasks.deleted_at defaults to NULL (live)');
		self::assertSame(0, (int)$row['deleted_token'], 'deleted_token defaults to 0 (live)');
		self::assertSame(1, (int)$row['is_active'], 'room tasks stay active');

		// house_users.wallet_id exists and is NULL for unbound rows.
		$this->addHouseMember($houseId, 'frs_member', 'FRS Member');
		$qb = $this->db->getQueryBuilder();
		$qb->select('wallet_id')
			->from('chorestrike_house_users')
			->where($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter('frs_member')));
		$result = $qb->executeQuery();
		$walletId = $result->fetchOne();
		$result->closeCursor();
		self::assertNull($walletId, 'house_users.wallet_id exists and defaults to NULL');

		// completed_tasks.user_id is nullable (D3; combo partner facts since
		// the CS-PLAN-064 reshape live in the deltas JSON, not a column).
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
				'completed_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				'coins_earned' => $qb->createNamedParameter(5, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_BOOL),
				'before_state' => $qb->createNamedParameter('{}'),
				'deltas' => $qb->createNamedParameter('{}'),
			])
			->executeStatement();
		$this->cleanupCompletionIds[] = (int)$this->db->lastInsertId('chorestrike_completed_tasks');

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$completion = $result->fetch();
		$result->closeCursor();

		self::assertIsArray($completion, 'a completion with a NULL actor reference must be insertable (D3)');
		self::assertNull($completion['user_id']);
	}

	/**
	 * TombstonedRoomTask_KeepsPairUniqueness_AndAllowsRecreatedLiveLink
	 *
	 * Q-70-01 / decision D4: the re-keyed uniq_rt keeps exactly one live
	 * (room, task) row, never blocks a re-created link, and the tombstoned
	 * row retains its completed_tasks evidence.
	 */
	public function testTombstonedRoomTask_KeepsPairUniqueness_AndAllowsRecreatedLiveLink(): void {
		$houseId = $this->createHouse('frs_tomb_owner', 'FRS Tombstone House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'FRS tombstone task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'frs_tomb_owner');

		// Evidence row that must survive the tombstone.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				'coins_earned' => $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_BOOL),
				'before_state' => $qb->createNamedParameter('{}'),
				'deltas' => $qb->createNamedParameter('{}'),
			])
			->executeStatement();
		$this->cleanupCompletionIds[] = (int)$this->db->lastInsertId('chorestrike_completed_tasks');

		// Tombstone via the entity primitive (same semantics as production).
		$roomTaskMapper = \OC::$server->get(RoomTaskMapper::class);
		$roomTask = $roomTaskMapper->find($roomTaskId);
		$roomTask->tombstone(new \DateTime());
		$roomTaskMapper->update($roomTask);

		$row = $this->fetchRoomTask($roomTaskId);
		self::assertSame((string)$roomTaskId, (string)$row['deleted_token'], 'the tombstone folds the row id into deleted_token');
		self::assertSame(0, (int)$row['is_active']);

		// A re-created live link for the same pair is allowed.
		$recreatedId = $this->assignTaskToRoom($taskId, $roomId, 'frs_tomb_owner');
		self::assertNotSame($roomTaskId, $recreatedId);

		// Tombstoning the re-created row works too (distinct tokens).
		$recreated = $roomTaskMapper->find($recreatedId);
		$recreated->tombstone(new \DateTime());
		$roomTaskMapper->update($recreated);

		// A THIRD live row is allowed (two distinct tombstones exist).
		$thirdId = $this->assignTaskToRoom($taskId, $roomId, 'frs_tomb_owner');

		// But a duplicate LIVE row (deleted_token = 0) is rejected.
		$violated = false;
		try {
			$this->assignTaskToRoom($taskId, $roomId, 'frs_tomb_owner');
		} catch (Exception $e) {
			$violated = $e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
		}
		self::assertTrue($violated, 'a second live (room, task) row must violate uniq_rt');

		// The evidence rows survived every tombstone.
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		self::assertSame(1, $count, 'completed_tasks evidence is retained across tombstones');
	}

	/**
	 * TombstonedStructureRows_VanishFromActivePlanningReads
	 */
	public function testTombstonedStructureRows_VanishFromActivePlanningReads(): void {
		$houseId = $this->createHouse('frs_read_owner', 'FRS Read House');
		$floorId = $this->createFloor($houseId, 'Live Floor', 1);
		$tombFloorId = $this->createFloor($houseId, 'Tomb Floor', 2);
		$roomId = $this->createRoom($houseId, $floorId, 'Live Room');
		$tombRoomId = $this->createRoom($houseId, $floorId, 'Tomb Room');
		$taskId = $this->createTask($houseId, 'FRS read task');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'frs_read_owner');

		$now = new \DateTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_floors')
			->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($tombFloorId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_rooms')
			->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($tombRoomId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$floorMapper = \OC::$server->get(FloorMapper::class);
		$roomMapper = \OC::$server->get(RoomMapper::class);
		$roomTaskMapper = \OC::$server->get(RoomTaskMapper::class);

		$floorIds = array_map(static fn ($f) => $f->getId(), $floorMapper->findByHouseId($houseId));
		self::assertNotContains($tombFloorId, $floorIds, 'tombstoned floors vanish from findByHouseId');
		self::assertSame(1, $floorMapper->countByHouseId($houseId), 'tombstoned floors do not count');

		$roomIds = array_map(static fn ($r) => $r->getId(), $roomMapper->findByHouseId($houseId));
		self::assertNotContains($tombRoomId, $roomIds, 'tombstoned rooms vanish from findByHouseId');
		self::assertNotContains($tombRoomId, array_keys($roomMapper->findByIds([$roomId, $tombRoomId])), 'tombstoned rooms vanish from findByIds');
		$liveFloorRoomIds = array_map(static fn ($r) => $r->getId(), $roomMapper->findByFloorId($floorId));
		self::assertNotContains($tombRoomId, $liveFloorRoomIds, 'tombstoned rooms vanish from findByFloorId');

		// exists() ignores tombstoned links (Q-70-01).
		$roomTask = $roomTaskMapper->find($roomTaskId);
		$roomTask->tombstone(new \DateTime());
		$roomTaskMapper->update($roomTask);
		self::assertFalse($roomTaskMapper->exists($roomId, $taskId), 'a tombstoned link must not block re-creation');
	}

	/**
	 * WP0 identity/receipt tables enforce their unique constraints.
	 */
	public function testWalletAndReceiptUniqueConstraintsHold(): void {
		$houseId = $this->createHouse('frs_uq_owner', 'FRS Unique House');
		$walletMapper = \OC::$server->get(WalletMapper::class);

		$wallet = $walletMapper->createOrGet($houseId, 'frs_uq_member');
		$this->cleanupWalletIds[] = $wallet->getId();
		$again = $walletMapper->createOrGet($houseId, 'frs_uq_member');
		self::assertSame($wallet->getId(), $again->getId(), 'wallet creation is idempotent per (house, binding)');

		// A second wallet row for the same binding is rejected by the index.
		$violated = false;
		try {
			$qb = $this->db->getQueryBuilder();
			$now = date('Y-m-d H:i:s');
			$qb->insert('chorestrike_wallets')
				->values([
					'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
					'nextcloud_user_id' => $qb->createNamedParameter('frs_uq_member'),
					'booked_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'reserved_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'version' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now),
					'updated_at' => $qb->createNamedParameter($now),
				])
				->executeStatement();
		} catch (Exception $e) {
			$violated = $e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
		}
		self::assertTrue($violated, 'uniq_wallet_house_user must reject a duplicate binding');

		// Commands: same key twice is rejected; the ledger idempotency key too.
		$now = date('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_commands')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'actor_house_user_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'command_uuid' => $qb->createNamedParameter('11111111-1111-4111-8111-111111111111'),
				'action' => $qb->createNamedParameter('test.action'),
				'payload_hash' => $qb->createNamedParameter(str_repeat('a', 64)),
				'result_refs' => $qb->createNamedParameter('{}'),
				'status' => $qb->createNamedParameter('committed'),
				'created_at' => $qb->createNamedParameter($now),
			])
			->executeStatement();
		$commandId = (int)$this->db->lastInsertId('chorestrike_commands');
		$this->cleanupCommandIds[] = $commandId;

		$violated = false;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_commands')
				->values([
					'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
					'actor_house_user_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'command_uuid' => $qb->createNamedParameter('11111111-1111-4111-8111-111111111111'),
					'action' => $qb->createNamedParameter('test.action'),
					'payload_hash' => $qb->createNamedParameter(str_repeat('b', 64)),
					'status' => $qb->createNamedParameter('committed'),
					'created_at' => $qb->createNamedParameter($now),
				])
				->executeStatement();
		} catch (Exception $e) {
			$violated = $e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
		}
		self::assertTrue($violated, 'uniq_cmd_key must reject a duplicate receipt key');

		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_wallet_entries')
			->values([
				'wallet_id' => $qb->createNamedParameter($wallet->getId(), IQueryBuilder::PARAM_INT),
				'source_kind' => $qb->createNamedParameter('completion'),
				'source_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'source_revision' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'delta_minor' => $qb->createNamedParameter(100, IQueryBuilder::PARAM_INT),
				'reason_code' => $qb->createNamedParameter('test.reason'),
				'actor_house_user_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now),
			])
			->executeStatement();
		$this->cleanupWalletEntryIds[] = (int)$this->db->lastInsertId('chorestrike_wallet_entries');

		$violated = false;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('chorestrike_wallet_entries')
				->values([
					'wallet_id' => $qb->createNamedParameter($wallet->getId(), IQueryBuilder::PARAM_INT),
					'source_kind' => $qb->createNamedParameter('completion'),
					'source_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'source_revision' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
					'delta_minor' => $qb->createNamedParameter(-100, IQueryBuilder::PARAM_INT),
					'reason_code' => $qb->createNamedParameter('test.undo'),
					'actor_house_user_id' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now),
				])
				->executeStatement();
		} catch (Exception $e) {
			$violated = $e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
		}
		self::assertTrue($violated, 'uniq_wentry_source must reject a duplicate ledger source');
	}

	/**
	 * BumpStateVersion_IncrementsCounterUnderHouseLock
	 *
	 * CS-PLAN-071 D7: the T0-demonstrated mechanism — the mapper write
	 * increments the stored counter exactly once per call.
	 */
	public function testBumpStateVersion_IncrementsCounter(): void {
		$houseId = $this->createHouse('frs_bump_owner', 'FRS Bump House');
		$houseMapper = \OC::$server->get(HouseMapper::class);

		self::assertSame(0, (int)$this->fetchHouseColumn($houseId, 'state_version'));

		$houseMapper->bumpStateVersion($houseId);
		$houseMapper->bumpStateVersion($houseId);

		self::assertSame(2, (int)$this->fetchHouseColumn($houseId, 'state_version'));
		self::assertSame(0, (int)$this->fetchHouseColumn($houseId, 'timezone_version'), 'the timezone counter is untouched');
	}

	private function fetchHouseColumn(int $houseId, string $column): mixed {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)
			->from('chorestrike_houses')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_room_tasks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row);
		return $row;
	}
}
