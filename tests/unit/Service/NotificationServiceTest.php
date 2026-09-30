<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the digest-only NotificationService
 * (CS-PLAN-070 WP5 §3.4/§3.5, C5e, RF-070-10).
 *
 * The service is the app's ONE notification choke point and carries
 * exactly one subject — the daily digest. Every former pressure subject
 * (task due reminders, medal/weekly-goal/title bells, overdue-takeover)
 * must stay removed (Q-076-02 negative pins). The dispatch outcome is
 * explicit: an accepted send returns; an uncertain failure is logged
 * (bounded, payload-free) and RETHROWN so the digest slot can be marked
 * `uncertain` and never resent (parent §3.6 at-most-once doctrine).
 */
class NotificationServiceTest extends TestCase {
	private IManager&MockObject $manager;
	private AppLogger&MockObject $appLogger;
	private NotificationService $service;

	/** @var array<string, mixed>|null */
	private ?array $lastSubject = null;
	private ?string $lastSubjectName = null;
	private ?string $lastObjectType = null;
	private ?string $lastObjectId = null;
	private ?string $lastUser = null;

	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->service = new NotificationService(
			$this->manager,
			$this->appLogger
		);

		$this->lastSubject = null;
		$this->lastSubjectName = null;
		$this->lastObjectType = null;
		$this->lastObjectId = null;
		$this->lastUser = null;
	}

	/**
	 * Wire a fluent INotification mock that records the dispatched shape.
	 */
	private function wireRecordingNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnCallback(fn (): INotification => $notification);
		$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification) {
			$this->lastUser = $user;
			return $notification;
		});
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification) {
				$this->lastObjectType = $type;
				$this->lastObjectId = $id;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($notification) {
				$this->lastSubjectName = $subject;
				$this->lastSubject = $params;
				return $notification;
			}
		);

		$this->manager->method('createNotification')->willReturn($notification);
		$this->manager->method('notify')->with($notification);
	}

	/**
	 * NotifyDigest_BuildsDayQualifiedCombinedPayload
	 */
	public function testNotifyDigest_BuildsDayQualifiedCombinedPayload(): void {
		$this->wireRecordingNotification();
		$this->manager->expects($this->once())->method('notify');

		$this->service->notifyDigest('alice', 7, 42, '2026-09-29', 3, 2);

		$this->assertSame('alice', $this->lastUser);
		$this->assertSame('digest', $this->lastSubjectName);
		// Counts only — no task names, no foreign request details.
		$this->assertSame(['taskCount' => 3, 'orgCount' => 2], $this->lastSubject);
		$this->assertSame('digest', $this->lastObjectType);
		// The Nextcloud dedup key reinforces the day budget without being
		// relied upon as the guarantee.
		$this->assertSame('7:42:2026-09-29', $this->lastObjectId);
	}

	/**
	 * NotifyDigest_EmptyRecipient_SkipsSilently
	 */
	public function testNotifyDigest_EmptyRecipient_SkipsSilently(): void {
		$this->manager->expects($this->never())->method('createNotification');
		$this->manager->expects($this->never())->method('notify');

		$this->service->notifyDigest('', 7, 42, '2026-09-29', 1, 0);
	}

	/**
	 * Dispatch_ManagerThrows_LogsAndRethrows_UncertainOutcome
	 *
	 * The WP5 contract change: an uncertain result is no longer
	 * catch-and-log-only — it propagates so DigestService marks the slot
	 * `uncertain` and NEVER resends (parent §3.6).
	 */
	public function testDispatch_ManagerThrows_LogsAndRethrows(): void {
		$this->wireRecordingNotification();
		$this->manager->method('notify')->willThrowException(new \RuntimeException('boom'));
		$this->appLogger->expects($this->once())->method('logException');

		try {
			$this->service->notifyDigest('alice', 7, 42, '2026-09-29', 1, 0);
			$this->fail('an uncertain dispatch outcome must propagate to the caller');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}
	}

	/**
	 * RetiredSubjects_Removed (Q-076-02 negative pins)
	 *
	 * No pressure-notification surface may linger on the choke point:
	 * task due reminders, medal/weekly-goal/title bells and the
	 * overdue-takeover bell are gone with their callers (RF-070-10 —
	 * removal-with-replacement, not repair).
	 */
	public function testRetiredSubjects_Removed(): void {
		foreach ([
			'notifyOverdueTakenOver',
			'notifyMedalAwarded',
			'notifyWeeklyGoalReached',
			'notifyWeeklyGoalReachedForHouse',
			'notifyTitleUnlocked',
			'notifyTaskDue',
			'notifyStreakAtRisk',
		] as $retiredMethod) {
			$this->assertFalse(
				method_exists($this->service, $retiredMethod),
				"the retired notification surface {$retiredMethod} must stay removed"
			);
		}
		$this->assertFalse(defined('OCA\ChoreStrike\Service\NotificationService::SUBJECT_OVERDUE_TAKEN_OVER'));
		$this->assertFalse(defined('OCA\ChoreStrike\Service\NotificationService::SUBJECT_MEDAL_AWARDED'));
		$this->assertFalse(defined('OCA\ChoreStrike\Service\NotificationService::SUBJECT_WEEKLY_GOAL_REACHED'));
		$this->assertFalse(defined('OCA\ChoreStrike\Service\NotificationService::SUBJECT_TITLE_UNLOCKED'));
		$this->assertFalse(defined('OCA\ChoreStrike\Service\NotificationService::SUBJECT_TASK_DUE_TODAY'));
		$this->assertFalse(defined('OCA\ChoreStrike\Service\NotificationService::SUBJECT_TASK_DUE_TOMORROW'));
	}

	/**
	 * DigestSubject_IsTheOnlyDeclaredSubject
	 */
	public function testDigestSubject_IsTheOnlyDeclaredSubject(): void {
		$reflection = new \ReflectionClass(NotificationService::class);
		$subjectNames = array_values(array_filter(
			array_keys($reflection->getConstants()),
			static fn (string $name): bool => str_starts_with($name, 'SUBJECT')
		));
		$this->assertSame(['SUBJECT_DIGEST'], $subjectNames, 'the daily digest must be the only notification subject');
	}
}
