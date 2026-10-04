<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\AgreementService;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseService;
use OCA\ChoreStrike\Service\SchedulePlanService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Real consumer/receipt proofs with an explicit two-connection lock barrier. */
class OwnerCommandAuthorizationTest extends IntegrationTestCase {
	/** @var int[] */
	private array $resetRoomIds = [];

	protected function tearDown(): void {
		if ($this->resetRoomIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_room_events')->where($qb->expr()->in('room_id', $qb->createNamedParameter($this->resetRoomIds, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		parent::tearDown();
	}

	/** @dataProvider lostAuthorityProvider */
	public function testCommand_AuthorityLostBehindHouseLock_DeniesExecutionAndReplay(string $action, string $loss, bool $replay): void {
		[$houseId, $actorId, $service, $invoke] = $this->commandFixture($action);
		$logger = $this->createMock(AppLogger::class);
		$logger->expects($replay ? $this->once() : $this->never())->method('audit');
		$beforeLock = null;
		$this->installCommands($service, $logger, $beforeLock);
		if ($replay) {
			$original = $invoke();
			self::assertIsArray($original, 'the first command must commit a real domain result and receipt');
			self::assertSame($original, $invoke(), 'authorized replay returns the committed result despite changed business facts');
		}
		$before = $this->snapshot($houseId);
		$this->raceAuthorityChange($houseId, $actorId, $loss, $beforeLock, static function () use ($invoke): void {
			try {
				$invoke();
				self::fail('fresh authority is required before domain execution or a stored receipt answer');
			} catch (ForbiddenException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		});
		self::assertSame($before, $this->snapshot($houseId), 'denial must leave domain facts and receipts unchanged');
	}

	public static function lostAuthorityProvider(): array {
		$cases = [];
		foreach (['house', 'illness', 'approve', 'reject', 'shorten', 'apply', 'amend', 'catchUp', 'restart', 'reset'] as $action) {
			foreach ($action === 'reset' ? ['demoted', 'departed', 'replaced'] : ['demoted', 'departed'] as $loss) {
				foreach ([false, true] as $replay) {
					$cases[$action . ' ' . $loss . ' replay=' . (int)$replay] = [$action, $loss, $replay];
				}
			}
		}
		return $cases;
	}

	private function commandFixture(string $action): array {
		$houseId = $this->createHouse('cmd_auth_owner', 'Command authority fixture');
		$this->addHouseMember($houseId, 'cmd_auth_backup', 'Backup', 'owner');
		$this->addHouseMember($houseId, 'cmd_auth_child', 'Child', 'child');
		$actor = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, 'cmd_auth_owner');
		$today = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$uuid = sprintf('08100000-0000-4000-8000-%012x', random_int(0, 0xffffffffffff));
		$payload = ['commandId' => $uuid, 'startDate' => $today,
			'endDate' => (new \DateTime($today))->modify('+5 days')->format('Y-m-d'), 'reasonCode' => 'travel'];
		if ($action === 'reset') {
			$floor = $this->createFloor($houseId);
			$this->resetRoomIds[] = $this->createRoom($houseId, $floor);
			$this->actingAs('cmd_auth_owner');
			$service = clone \OC::$server->get(HouseService::class);
			$invoke = static function () use ($service, $houseId, $uuid): array {
				$service->resetHouse($houseId, $uuid);
				return ['houseId' => $houseId];
			};
		} elseif ($action === 'apply') {
			$floor = $this->createFloor($houseId);
			$room = $this->createRoom($houseId, $floor);
			$rowId = $this->assignTaskToRoom($this->createTask($houseId, 'Command task'), $room, 'cmd_auth_owner');
			$service = clone \OC::$server->get(SchedulePlanService::class);
			$preview = $service->preview($houseId, $actor, $today, [$rowId], []);
			$invoke = static fn (): array => $service->apply($houseId, $actor, $today, [$rowId], [], $preview['previewVersion'], $uuid, true);
		} elseif (in_array($action, ['amend', 'catchUp', 'restart'], true)) {
			[$service, $invoke] = $this->agreementCommand($houseId, $action, $uuid, $today);
		} else {
			$service = clone \OC::$server->get(PauseService::class);
			if (in_array($action, ['approve', 'reject'], true)) {
				$pending = $service->requestForSelf($houseId, 'cmd_auth_child', array_merge($payload, ['commandId' => $this->uuid()]));
				$method = $action;
				$invoke = static fn (): array => $service->$method($houseId, 'cmd_auth_owner', $pending['pauseGroupId'], ['commandId' => $uuid, 'expectedRevision' => $pending['revision']]);
			} elseif ($action === 'shorten') {
				$pause = $service->createHousePause($houseId, 'cmd_auth_owner', array_merge($payload, ['commandId' => $this->uuid()]));
				$end = (new \DateTime($today))->modify('+2 days')->format('Y-m-d');
				$invoke = static fn (): array => $service->shorten($houseId, 'cmd_auth_owner', $pause['pauseGroupId'], ['commandId' => $uuid, 'expectedRevision' => $pause['revision'], 'endDate' => $end]);
			} elseif ($action === 'illness') {
				$invoke = static fn (): array => $service->recognizeIllness($houseId, 'cmd_auth_owner', array_merge($payload, ['subjectUserId' => 'cmd_auth_child']));
			} else {
				$invoke = static fn (): array => $service->createHousePause($houseId, 'cmd_auth_owner', $payload);
			}
		}
		return [$houseId, (int)$actor->getId(), $service, $invoke];
	}

	private function agreementCommand(int $houseId, string $action, string $uuid, string $today): array {
		$floor = $this->createFloor($houseId);
		$room = $this->createRoom($houseId, $floor);
		$taskId = $this->createTask($houseId, 'Agreement task');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')->set('frequency_type', $qb->createNamedParameter('daily'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))->executeStatement();
		$rowId = $this->assignTaskToRoom($taskId, $room, 'cmd_auth_owner', $today . ' 00:00:00');
		$occurrences = \OC::$server->get(OccurrenceService::class);
		$pending = \OC::$server->get(OccurrenceMapper::class)->findByRoomTask($rowId)[0];
		if ($action === 'catchUp') {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_occurrences')->set('planned_date', $qb->createNamedParameter((new \DateTime($today))->modify('-2 days')->format('Y-m-d')))
				->set('window_start', $qb->createNamedParameter((new \DateTime($today))->modify('-2 days')->format('Y-m-d H:i:s')))
				->set('window_end', $qb->createNamedParameter((new \DateTime($today))->modify('-1 day')->format('Y-m-d H:i:s')))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$pending->getId(), IQueryBuilder::PARAM_INT)))->executeStatement();
		} elseif ($action === 'restart') {
			\OC::$server->get(PauseService::class)->requestForSelf($houseId, 'cmd_auth_owner', ['commandId' => $this->uuid(),
				'startDate' => $today, 'endDate' => (new \DateTime($today))->modify('+4 days')->format('Y-m-d'), 'reasonCode' => 'travel']);
			$pending = \OC::$server->get(OccurrenceMapper::class)->find((int)$pending->getId());
		}
		$service = clone \OC::$server->get(AgreementService::class);
		if ($action === 'restart') {
			$payload = ['commandId' => $uuid, 'occurrenceId' => $pending->toPublicKey(), 'expectedVersion' => $pending->getVersion(),
				'consent' => true, 'workDate' => (new \DateTime($today))->modify('+5 days')->format('Y-m-d')];
		} else {
			$row = \OC::$server->get(RoomTaskMapper::class)->find($rowId);
			$selection = $occurrences->selectionFor($row, new \DateTime(), $houseId);
			self::assertNotNull($selection);
			$payload = ['commandId' => $uuid, 'occurrenceId' => $selection['occurrence']->toPublicKey(), 'expectedVersion' => $selection['occurrence']->getVersion(),
				'selectionToken' => $occurrences->selectionToken($selection['occurrence'], $selection['revision'], $selection['classification'], $houseId, null, $selection['workAppointment']), 'consent' => true];
			$payload[$action === 'amend' ? 'date' : 'workDate'] = (new \DateTime($today))->modify('+1 day')->format('Y-m-d');
		}
		return [$service, static fn (): array => $service->$action($houseId, 'cmd_auth_owner', $rowId, $payload)];
	}

	private function uuid(): string {
		return sprintf('08100000-0000-4000-8000-%012x', random_int(0, 0xffffffffffff));
	}

	private function installCommands(object $service, AppLogger $logger, ?\Closure &$beforeLock): void {
		$realHouses = \OC::$server->get(HouseMapper::class);
		$houses = $this->createMock(HouseMapper::class);
		$houses->method('findForUpdate')->willReturnCallback(static function (int $houseId) use ($realHouses, &$beforeLock) {
			$beforeLock?->__invoke();
			return $realHouses->findForUpdate($houseId);
		});
		$commands = new CommandService(new TransactionExecutor($this->db), $houses,
			\OC::$server->get(CommandReceiptMapper::class), $logger);
		$property = new \ReflectionProperty($service, $service instanceof HouseService ? 'commandService' : 'commands');
		$property->setAccessible(true);
		$property->setValue($service, $commands);
	}

	private function snapshot(int $houseId): array {
		$rows = [];
		foreach (['chorestrike_houses', 'chorestrike_rooms', 'chorestrike_room_events', 'chorestrike_pauses', 'chorestrike_commands', 'chorestrike_agreements', 'chorestrike_occurrences', 'chorestrike_schedule_revs'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('facts.*')->from($table, 'facts')->orderBy('facts.id');
			$houseColumn = 'facts.house_id';
			if ($table === 'chorestrike_houses') {
				$houseColumn = 'facts.id';
			} elseif ($table === 'chorestrike_rooms' || $table === 'chorestrike_room_events') {
				if ($table === 'chorestrike_room_events') {
					$qb->innerJoin('facts', 'chorestrike_rooms', 'r', $qb->expr()->eq('facts.room_id', 'r.id'));
				} else {
					$qb->innerJoin('facts', 'chorestrike_floors', 'f', $qb->expr()->eq('facts.floor_id', 'f.id'));
				}
				if ($table === 'chorestrike_room_events') {
					$qb->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'));
				}
				$houseColumn = 'f.house_id';
			} elseif ($table === 'chorestrike_agreements') {
				$qb->innerJoin('facts', 'chorestrike_occurrences', 'occ', $qb->expr()->eq('facts.occurrence_id', 'occ.id'));
				$houseColumn = 'occ.house_id';
			} elseif ($table === 'chorestrike_schedule_revs') {
				$qb->innerJoin('facts', 'chorestrike_room_tasks', 'rt', $qb->expr()->eq('facts.room_task_id', 'rt.id'))
					->innerJoin('rt', 'chorestrike_rooms', 'r', $qb->expr()->eq('rt.room_id', 'r.id'))
					->innerJoin('r', 'chorestrike_floors', 'f', $qb->expr()->eq('r.floor_id', 'f.id'));
				$houseColumn = 'f.house_id';
			}
			$qb->where($qb->expr()->eq($houseColumn, $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$rows[$table] = $result->fetchAll();
			$result->closeCursor();
		}
		return $rows;
	}

	private function raceAuthorityChange(int $houseId, int $actorId, string $loss, ?\Closure &$beforeLock, callable $invoke): void {
		$base = sys_get_temp_dir() . '/cs-owner-guard-' . bin2hex(random_bytes(8));
		$script = $base . '.php';
		$ready = $base . '.ready';
		$go = $base . '.go';
		file_put_contents($script, $this->holderScript());
		$process = proc_open([PHP_BINARY, $script, (string)$houseId, (string)$actorId, $loss, $ready, $go],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		try {
			$deadline = microtime(true) + 30;
			while (!file_exists($ready)) {
				if (microtime(true) > $deadline) {
					self::fail('authority holder did not acquire the house lock');
				}
				usleep(10000);
			}
			$beforeLock = static function () use ($go): void {
				file_put_contents($go, 'preflight-complete');
			};
			$invoke();
			$error = stream_get_contents($pipes[2]);
			self::assertSame('', $error, 'authority holder must commit without errors');
		} finally {
			$beforeLock = null;
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_close($process);
			foreach ([$script, $ready, $go] as $path) {
				if (file_exists($path)) {
					unlink($path);
				}
			}
		}
	}

	private function holderScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';
$db = \OC::$server->get(\OCP\IDBConnection::class);
$db->beginTransaction();
if ($db->getDatabaseProvider() === \OCP\IDBConnection::PLATFORM_SQLITE) {
    $db->executeStatement('UPDATE *PREFIX*chorestrike_houses SET state_version = state_version WHERE id = :id', ['id' => (int)$argv[1]]);
} else {
    $result = $db->executeQuery('SELECT id FROM *PREFIX*chorestrike_houses WHERE id = :id FOR UPDATE', ['id' => (int)$argv[1]]);
    $result->closeCursor();
}
file_put_contents($argv[4], 'locked');
$deadline = microtime(true) + 30;
while (!file_exists($argv[5])) {
    if (microtime(true) > $deadline) {
        throw new \RuntimeException('command did not reach its house lock');
    }
    usleep(10000);
}
$qb = $db->getQueryBuilder();
if ($argv[3] === 'replaced') {
    $result = $db->executeQuery('SELECT * FROM *PREFIX*chorestrike_house_users WHERE id = :id', ['id' => (int)$argv[2]]);
    $replacement = $result->fetch();
    $result->closeCursor();
    unset($replacement['id']);
}
if ($argv[3] === 'demoted') {
    $qb->update('chorestrike_house_users')->set('role', $qb->createNamedParameter('member'));
} else {
    $qb->delete('chorestrike_house_users');
}
$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$argv[2], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();
if ($argv[3] === 'replaced') {
    $qb = $db->getQueryBuilder();
    $values = [];
    foreach ($replacement as $column => $value) {
        $values[$column] = $qb->createNamedParameter($value);
    }
    $qb->insert('chorestrike_house_users')->values($values)->executeStatement();
    if ((int)$db->lastInsertId('chorestrike_house_users') === (int)$argv[2]) {
        throw new \RuntimeException('Replacement must have a new membership identity');
    }
}
$db->commit();
PHP;
	}
}
