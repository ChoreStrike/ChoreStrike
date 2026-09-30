<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Notification;

use OCA\ChoreStrike\Notification\ChoreStrikeNotifier;
use OCA\ChoreStrike\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChoreStrikeNotifier (INotifier implementation).
 *
 * WP5 digest cutover (CS-PLAN-076 §3.5, C5e): the notifier renders
 * exactly ONE subject — the count-based daily `digest` summary. The
 * former pressure subjects (task due reminders, medal/weekly-goal/title
 * bells, overdue-takeover) are rejected. Content is visibility-safe: the
 * parameters carry counts only, and the notifier resolves no object
 * references that could leak hidden task names or foreign request
 * details.
 */
class ChoreStrikeNotifierTest extends TestCase {
	private IFactory&MockObject $l10nFactory;
	private IURLGenerator&MockObject $url;
	private IL10N&MockObject $l10n;
	private ChoreStrikeNotifier $notifier;

	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);

		// Default: t() returns the concatenation of the format + params so we
		// can assert the right key was used without depending on the l10n file.
		$this->l10n->method('t')->willReturnCallback(function (string $text, array $args = []) {
			if (empty($args)) {
				return $text;
			}
			// Replace %s / %1$s-style placeholders with the args for
			// assertion clarity.
			$i = 1;
			$out = $text;
			foreach ($args as $value) {
				$out = preg_replace('/%' . $i . '(\$[a-z])?/', (string)$value, $out, 1);
				$i++;
			}
			// The combined-subject format uses %s with one joined argument.
			$out = str_replace('%s', '%1$s', $out);
			$i = 1;
			foreach ($args as $value) {
				$out = preg_replace('/%' . $i . '(\$[a-z])?/', (string)$value, $out, 1);
				$i++;
			}
			return $out;
		});
		// n() plural forms: return the selected form with %n replaced.
		$this->l10n->method('n')->willReturnCallback(function (string $singular, string $plural, int $count) {
			$form = $count === 1 ? $singular : $plural;
			return str_replace('%n', (string)$count, $form);
		});

		$this->l10nFactory->method('get')->willReturn($this->l10n);
		$this->url->method('getAbsoluteURL')->willReturn('https://example/apps/chorestrike/img/app.svg');

		$this->notifier = new ChoreStrikeNotifier($this->l10nFactory, $this->url);
	}

	public function testGetID_ReturnsAppId(): void {
		$this->assertSame('chorestrike', $this->notifier->getID());
	}

	public function testGetName_ReturnsAppName(): void {
		$this->assertSame('ChoreStrike', $this->notifier->getName());
	}

	public function testPrepare_OtherApp_Throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		$notification = $this->buildNotification('other_app', 'digest');

		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Prepare_Digest_CombinedCategories_RendersCountOnlySubject
	 */
	public function testPrepare_Digest_CombinedCategories_RendersCountOnlySubject(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			NotificationService::SUBJECT_DIGEST,
			['taskCount' => 3, 'orgCount' => 2]
		);
		$notification->expects($this->once())->method('setIcon');
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Daily summary: 3 planned tasks · 2 household updates');
		$notification->expects($this->once())->method('setRichSubject')
			->with('Daily summary: 3 planned tasks · 2 household updates');
		$notification->expects($this->once())->method('setParsedMessage');

		$result = $this->notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	/**
	 * Prepare_Digest_SingleTask_SingularPlural
	 */
	public function testPrepare_Digest_SingleTask_UsesSingularForm(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			NotificationService::SUBJECT_DIGEST,
			['taskCount' => 1, 'orgCount' => 0]
		);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Daily summary: 1 planned task');

		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Prepare_Digest_OrgOnly_RendersOrgSubject
	 */
	public function testPrepare_Digest_OrgOnly_RendersOrgSubject(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			NotificationService::SUBJECT_DIGEST,
			['taskCount' => 0, 'orgCount' => 4]
		);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Daily summary: 4 household updates');

		$this->notifier->prepare($notification, 'en');
	}

	/**
	 * Prepare_RetiredSubjects_Rejected (Q-076-02 negative pins)
	 *
	 * Every retired pressure subject must be rejected, not silently
	 * rendered — the notifier's contract is the single digest subject.
	 */
	public function testPrepare_RetiredSubjects_Rejected(): void {
		$retired = [
			['overdue_taken_over', ['task' => 'Dishes', 'taskId' => 42, 'claimer' => 'Bob']],
			['medal_awarded', ['medalType' => 'team_anchor', 'value' => 12]],
			['weekly_goal_reached', ['percent' => 95, 'targetPercent' => 80]],
			['title_unlocked', ['titleLabelKey' => 'x', 'packLabelKey' => 'y']],
			['task_due_today', ['task' => 'Dishes', 'taskId' => 42, 'dueDate' => '2026-08-18']],
			['task_due_tomorrow', ['task' => 'Dishes', 'taskId' => 42, 'dueDate' => '2026-08-18']],
			['streak_at_risk', ['currentStreak' => 7]],
		];
		foreach ($retired as [$subject, $params]) {
			$notification = $this->buildNotification('chorestrike', $subject, $params);
			try {
				$this->notifier->prepare($notification, 'en');
				$this->fail("the retired subject {$subject} must be rejected");
			} catch (\InvalidArgumentException $e) {
				$this->assertSame('Unknown ChoreStrike notification subject', $e->getMessage());
			}
		}
	}

	/**
	 * Build a notification mock that returns the supplied subject/params.
	 *
	 * @param array<string, mixed> $params
	 */
	private function buildNotification(string $app, string $subject, array $params = []): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		return $notification;
	}
}
