<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlot;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\DigestScheduleService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/** Two live worker connections; pipe barriers and a held house lock, no sleeps. */
class DigestConcurrencyTest extends IntegrationTestCase {
	private const NOW = '2026-09-29 18:05:00 UTC';
	private array $workers = [];
	private string $deliveryFile;
	private int $houseId;
	private int $memberId;
	private MemberPreference $pref;
	private DigestSlot $slot;
	private bool $holdingLock = false;

	protected function setUp(): void {
		parent::setUp();
		$this->deliveryFile = tempnam(sys_get_temp_dir(), 'dgc_delivery_');
		$this->houseId = $this->createHouse('dgc-lead');
		$this->addHouseMember($this->houseId, 'dgc-member', 'Digest Member');
		$this->memberId = (int)\OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($this->houseId, 'dgc-member')->getId();
		$this->pref = new MemberPreference();
		$this->pref->setHouseId($this->houseId);
		$this->pref->setHouseUserId($this->memberId);
		$this->pref->setVersion(1);
		$this->pref->setOrgDigestEnabled(true);
		$this->pref->setDigestTime('18:00');
		$this->pref->setCreatedAt(new \DateTime(self::NOW));
		$this->pref->setUpdatedAt(new \DateTime(self::NOW));
		$this->pref = \OC::$server->get(MemberPreferenceMapper::class)->insertOrGet($this->pref);
		\OC::$server->get(DigestScheduleService::class)->materializeSlotsForHouse($this->houseId, new \DateTime('2026-09-29 17:00:00 UTC'));
		$this->slot = \OC::$server->get(DigestSlotMapper::class)->findByHouseUserAndDay($this->houseId, $this->memberId, '2026-09-29');
		$change = new DigestChange();
		$change->setHouseId($this->houseId);
		$change->setHouseUserId($this->memberId);
		$pauseMapper = \OC::$server->get(PauseMapper::class);
		$pause = new Pause();
		$pause->setHouseId($this->houseId);
		$pause->setPauseGroupId($pauseMapper->maxGroupId());
		$pause->setScope(Pause::SCOPE_HOUSE);
		$pause->setState(Pause::STATE_ACKNOWLEDGED);
		$pause->setStartDate(new \DateTime('2026-09-29'));
		$pause->setEndDate(new \DateTime('2026-09-30'));
		$pause->setTimezone('UTC');
		$pause->setCreatedAt(new \DateTime(self::NOW));
		$pause->setUpdatedAt(new \DateTime(self::NOW));
		$pause = $pauseMapper->appendRevision($pause);
		$change->setObjectType('pause');
		$change->setObjectId((string)$pause->getPauseGroupId());
		$change->setObjectRevision($pause->getRevision());
		$change->setTargetSlot('2026-09-29');
		$change->setChangeTime(new \DateTime('2026-09-29 10:00:00 UTC'));
		$change->setCreatedAt(new \DateTime(self::NOW));
		$change->setUpdatedAt(new \DateTime(self::NOW));
		\OC::$server->get(DigestChangeMapper::class)->queueOrCoalesce($change);
	}

	protected function tearDown(): void {
		if ($this->holdingLock) {
			\OC::$server->get(TransactionExecutor::class)->rollBack();
		}
		foreach ($this->workers as [$process, $pipes, $script]) {
			$status = proc_get_status($process);
			if ($status !== false && $status['running']) {
				proc_terminate($process);
			}
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_close($process);
			unlink($script);
		}
		unlink($this->deliveryFile);
		parent::tearDown();
	}

	public function testConcurrentClaims_OneCommittedAttempt_OneDispatch(): void {
		$this->holdHouseLock();
		$first = $this->startWorker('dispatch');
		$second = $this->startWorker('dispatch');
		$this->releaseHouseLock();
		$outcomes = [$this->finishWorker($first), $this->finishWorker($second)];
		sort($outcomes);
		self::assertSame(['contended', 'dispatched'], $outcomes);
		self::assertSame("accepted\n", file_get_contents($this->deliveryFile));
		self::assertSame(DigestSlot::STATUS_SENT, $this->freshSlot()->getStatus());
	}

	public function testCommittedClaimBeforeCrash_ConcurrentWorkerNeverRedelivers(): void {
		$this->holdHouseLock();
		$worker = $this->startWorker('dispatch');
		\OC::$server->get(DigestSlotMapper::class)->claimPending((int)$this->slot->getId(), 'crashed-token', new \DateTime(self::NOW));
		$this->releaseHouseLock();
		self::assertSame('contended', $this->finishWorker($worker));
		self::assertSame('', file_get_contents($this->deliveryFile));
		self::assertSame(DigestSlot::STATUS_DISPATCHING, $this->freshSlot()->getStatus());
	}

	public function testConcurrentMaterializers_UniqueSlots_ConvergeWithoutDuplicates(): void {
		\OC::$server->get(DigestSlotMapper::class)->deleteByHouseAndUser($this->houseId, $this->memberId);
		$this->holdHouseLock();
		$first = $this->startWorker('materialize');
		$second = $this->startWorker('materialize');
		$this->releaseHouseLock();
		$outcomes = [$this->finishWorker($first), $this->finishWorker($second)];
		sort($outcomes);
		self::assertSame([0, 2], $outcomes);
		self::assertCount(2, \OC::$server->get(DigestSlotMapper::class)->findPendingByHouseId($this->houseId));
	}

	public function testClaimVersusPreferenceToggle_OptOutBeforeClaimWins(): void {
		$this->holdHouseLock();
		$worker = $this->startWorker('dispatch');
		$this->pref->setOrgDigestEnabled(false);
		$this->pref->setVersion(2);
		\OC::$server->get(MemberPreferenceMapper::class)->update($this->pref);
		$this->releaseHouseLock();
		self::assertSame('skipped', $this->finishWorker($worker));
		self::assertSame('', file_get_contents($this->deliveryFile));
		self::assertSame(DigestSlot::STATUS_SKIPPED, $this->freshSlot()->getStatus());
	}

	public function testClaimVersusPreferenceTimeChange_StaleDueScanDoesNotClaimEarly(): void {
		$this->holdHouseLock();
		$worker = $this->startWorker('dispatch');
		$this->pref->setDigestTime('20:00');
		$this->pref->setVersion(2);
		\OC::$server->get(MemberPreferenceMapper::class)->update($this->pref);
		$this->releaseHouseLock();
		self::assertSame('contended', $this->finishWorker($worker));
		self::assertSame('', file_get_contents($this->deliveryFile));
		self::assertSame('20:00', $this->freshSlot()->getScheduledUtc()->format('H:i'));
	}

	public function testClaimVersusZoneChange_StaleDueScanUsesTheNewResolvedSlot(): void {
		$this->holdHouseLock();
		$worker = $this->startWorker('dispatch');
		$house = \OC::$server->get(HouseMapper::class)->find($this->houseId);
		$house->setTimezone('America/New_York');
		\OC::$server->get(HouseMapper::class)->update($house);
		\OC::$server->get(DigestScheduleService::class)->reviseForZoneChangeUnderLock($this->houseId, 'America/New_York', new \DateTime(self::NOW));
		$this->releaseHouseLock();
		self::assertSame('contended', $this->finishWorker($worker));
		self::assertSame('', file_get_contents($this->deliveryFile));
		self::assertSame('22:00', $this->freshSlot()->getScheduledUtc()->format('H:i'));
	}

	private function freshSlot(): DigestSlot {
		return \OC::$server->get(DigestSlotMapper::class)->findById((int)$this->slot->getId());
	}

	private function holdHouseLock(): void {
		\OC::$server->get(TransactionExecutor::class)->begin();
		$this->holdingLock = true;
		\OC::$server->get(HouseMapper::class)->findForUpdate($this->houseId);
	}

	private function releaseHouseLock(): void {
		\OC::$server->get(TransactionExecutor::class)->commit();
		$this->holdingLock = false;
	}

	private function startWorker(string $mode): int {
		$script = tempnam(sys_get_temp_dir(), 'dgc_worker_');
		file_put_contents($script, $this->workerScript());
		$process = proc_open([PHP_BINARY, $script, $mode, (string)$this->houseId, (string)$this->slot->getId(), $this->deliveryFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		stream_set_timeout($pipes[1], 30);
		$this->workers[] = [$process, $pipes, $script];
		self::assertSame("ready\n", fgets($pipes[1]), 'the worker captured its input before the parent mutation');
		return array_key_last($this->workers);
	}

	private function finishWorker(int $index): mixed {
		[, $pipes] = $this->workers[$index];
		$result = fgets($pipes[1]);
		self::assertIsString($result, 'the worker must complete after the parent releases the house lock');
		self::assertSame('', stream_get_contents($pipes[2]), 'worker errors must not be hidden');
		return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
	}

	private function workerScript(): string {
		return <<<'PHP'
<?php
require '/var/www/html/lib/base.php';

$mode = $argv[1];
$houseId = (int)$argv[2];
$slot = $mode === 'dispatch' ? \OC::$server->get(\OCA\ChoreStrike\Db\DigestSlotMapper::class)->findById((int)$argv[3]) : null;
$service = \OC::$server->get(\OCA\ChoreStrike\Service\DigestService::class);
$adapter = new class($argv[4]) extends \OCA\ChoreStrike\Service\NotificationService {
    public function __construct(private string $output) {}
    public function notifyDigest(string $user, int $houseId, int $memberId, string $day, int $tasks, int $org): void {
        if (\OC::$server->get(\OCP\IDBConnection::class)->inTransaction()) {
            throw new \RuntimeException('notification attempted inside transaction');
        }
        file_put_contents($this->output, "accepted\n", FILE_APPEND | LOCK_EX);
    }
};
(new \ReflectionProperty($service, 'notificationService'))->setValue($service, $adapter);
fwrite(STDOUT, "ready\n");
$now = new \DateTime('2026-09-29 18:05:00 UTC');
$result = $mode === 'dispatch'
    ? (new \ReflectionMethod($service, 'dispatchSlot'))->invoke($service, $slot, $now)
    : \OC::$server->get(\OCA\ChoreStrike\Service\DigestScheduleService::class)->materializeSlotsForHouse($houseId, $now)['materialized'];
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
PHP;
	}
}
