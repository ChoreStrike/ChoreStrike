<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Notification;

use OCA\ChoreStrike\Notification\ChoreStrikeNotifier;
use OCP\IL10n;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChoreStrikeNotifier (INotifier implementation)
 */
class ChoreStrikeNotifierTest extends TestCase {
	private IFactory&MockObject $l10nFactory;
	private IURLGenerator&MockObject $url;
	private IL10n&MockObject $l10n;
	private ChoreStrikeNotifier $notifier;

	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10n::class);

		// Default: t() returns the concatenation of the format + params so we
		// can assert the right key was used without depending on the l10n file.
		$this->l10n->method('t')->willReturnCallback(function (string $text, array $args = []) {
			if (empty($args)) {
				return $text;
			}
			// Replace %1$s, %2$d, etc. with the positional args for assertion clarity.
			$i = 1;
			$out = $text;
			foreach ($args as $value) {
				$out = preg_replace('/%' . $i . '(\$[a-z])?/', (string)$value, $out, 1);
				$i++;
			}
			return $out;
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
		$notification = $this->buildNotification('other_app', 'whatever');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepare_UnknownSubject_Throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		$notification = $this->buildNotification('chorestrike', 'unknown_subject');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepare_OverdueTakenOver_RendersSubjectAndMessage(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			'overdue_taken_over',
			['task' => 'Dishes', 'taskId' => 42, 'claimer' => 'Bob']
		);
		$notification->expects($this->once())->method('setIcon');
		$notification->expects($this->once())->method('setRichSubject')
			->with('Your task "{task}" was taken over by {claimer}');
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Your task "Dishes" was taken over by Bob');
		$notification->expects($this->once())->method('setParsedMessage');

		$result = $this->notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testPrepare_MedalAwarded_RendersKnownMedalLabel(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			'medal_awarded',
			['medalType' => 'team_anchor', 'value' => 12]
		);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('You earned a new medal: Team Anchor');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepare_MedalAwarded_UnknownType_PrettifiesSnakeCase(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			'medal_awarded',
			['medalType' => 'some_new_medal', 'value' => 3]
		);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('You earned a new medal: Some new medal');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepare_WeeklyGoalReached_RendersSubject(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			'weekly_goal_reached',
			['percent' => 95, 'targetPercent' => 80]
		);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Weekly household goal reached!');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepare_StreakAtRisk_IncludesStreakCount(): void {
		$notification = $this->buildNotification(
			'chorestrike',
			'streak_at_risk',
			['currentStreak' => 7]
		);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Your 7-day streak is at risk — complete a task today!');

		$this->notifier->prepare($notification, 'en');
	}

	public function testPrepare_StreakAtRisk_MissingParams_DefaultsToZero(): void {
		$notification = $this->buildNotification('chorestrike', 'streak_at_risk', []);
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Your 0-day streak is at risk — complete a task today!');

		$this->notifier->prepare($notification, 'en');
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
