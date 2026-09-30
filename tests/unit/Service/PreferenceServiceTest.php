<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\PreferenceService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the self-only PreferenceService (CS-PLAN-070 WP5 §3.1,
 * C5h, RF-076-01, Q-076-01 Option A).
 *
 * Covers: the pre-side-effect validation matrix (unknown fields, HH:mm
 * shapes, cross-midnight quiet ranges, digest time required while a
 * category is enabled), the versioned update semantics (stale
 * expectedVersion → 409 without any write; version bump on every write;
 * first save creating the row at version 1 WITHOUT ranking consent), the
 * neutral read shape at version 0 and the quiet-hour gate semantics.
 * The CommandService mock EXECUTES the aggregate callable directly — the
 * receipt mechanics are CommandService's own contract.
 *
 * @covers \OCA\ChoreStrike\Service\PreferenceService
 */
class PreferenceServiceTest extends TestCase {
	private PreferenceService $service;
	private MemberPreferenceMapper|MockObject $prefMapper;
	/** Captured rows by (house, membership) — the fake mapper store. */
	private array $store = [];
	/** Sequence of writes for write-count assertions. */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->writes = [];

		$this->prefMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->prefMapper->method('findByHouseAndUser')->willReturnCallback(
			function (int $houseId, int $houseUserId): ?MemberPreference {
				return $this->store[$houseId . ':' . $houseUserId] ?? null;
			}
		);
		$this->prefMapper->method('update')->willReturnCallback(
			function (MemberPreference $pref): MemberPreference {
				$this->writes[] = 'update';
				$this->store[$pref->getHouseId() . ':' . $pref->getHouseUserId()] = $pref;
				return $pref;
			}
		);
		$this->prefMapper->method('insertOrGet')->willReturnCallback(
			function (MemberPreference $pref): MemberPreference {
				$this->writes[] = 'insert';
				$pref->setId(101);
				$this->store[$pref->getHouseId() . ':' . $pref->getHouseUserId()] = $pref;
				return $pref;
			}
		);

		// The executing command service: the aggregate runs immediately.
		$commands = $this->createMock(CommandService::class);
		$commands->method('runCommand')->willReturnCallback(
			static function (int $houseId, int $actorId, string $commandId, string $action, array $payload, callable $fn): array {
				return ['result' => $fn(), 'replay' => false];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new PreferenceService($this->prefMapper, $commands, $l10n);
	}

	private function actor(): HouseUser {
		$actor = new HouseUser();
		$actor->setId(7);
		$actor->setHouseId(1);
		$actor->setNextcloudUserId('mom');
		return $actor;
	}

	// ------------------------------------------------------------------
	// Read shape
	// ------------------------------------------------------------------

	public function testGetOwnPreferences_WithoutRow_ReturnsNeutralDefaultsAtVersionZero(): void {
		$payload = $this->service->getOwnPreferences(1, $this->actor(), new \DateTime('2026-09-29 12:00:00 UTC'));

		$this->assertSame(0, $payload['version']);
		$this->assertFalse($payload['taskDigestEnabled']);
		$this->assertFalse($payload['orgDigestEnabled']);
		$this->assertSame('18:00', $payload['digestTime']);
		$this->assertNull($payload['quietStart']);
		$this->assertNull($payload['quietEnd']);
		$this->assertFalse($payload['soundEnabled']);
		$this->assertFalse($payload['animationEnabled']);
		$this->assertTrue($payload['arcadeVisible']);
		$this->assertTrue($payload['catalogVisible']);
		$this->assertSame(['state' => 'none', 'generation' => null, 'effectiveMonday' => null], $payload['ranking']);
		$this->assertSame([], $this->writes, 'a read never writes');
	}

	public function testGetOwnPreferences_WithConsentedPendingRow_ReportsPendingState(): void {
		$pref = new MemberPreference();
		$pref->setHouseId(1);
		$pref->setHouseUserId(7);
		$pref->setVersion(2);
		$pref->setRankingConsentGeneration(4);
		$pref->setRankingEffectiveMonday(new \DateTime('2030-01-07'));
		$pref->setRankingSavedZone('UTC');
		$this->store['1:7'] = $pref;

		$payload = $this->service->getOwnPreferences(1, $this->actor(), new \DateTime('2026-09-29 12:00:00 UTC'));

		$this->assertSame('pending', $payload['ranking']['state']);
		$this->assertSame(4, $payload['ranking']['generation']);
		$this->assertSame('2030-01-07', $payload['ranking']['effectiveMonday']);
	}

	// ------------------------------------------------------------------
	// Validation matrix (pre-side-effect)
	// ------------------------------------------------------------------

	public function testUpdate_UnknownField_RejectedBeforeAnySideEffect(): void {
		try {
			$this->service->updateOwnPreferences(1, $this->actor(), [
				'expectedVersion' => 0,
				'secretOwnerField' => true,
			], '11111111-1111-4111-8111-111111111111');
			$this->fail('unknown fields must be rejected');
		} catch (\InvalidArgumentException $e) {
			// The message is client-safe: it never echoes the field name.
			$this->assertSame('Unknown preference field', $e->getMessage());
		}
		$this->assertSame([], $this->writes, 'validation rejects before any side effect');
	}

	public function testUpdate_MissingExpectedVersion_Rejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A valid expectedVersion is required');
		$this->service->updateOwnPreferences(1, $this->actor(), [
			'taskDigestEnabled' => true,
		], '11111111-1111-4111-8111-111111111111');
	}

	public function testUpdate_NonBooleanToggle_Rejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateOwnPreferences(1, $this->actor(), [
			'expectedVersion' => 0,
			'taskDigestEnabled' => 'yes',
		], '11111111-1111-4111-8111-111111111111');
	}

	public function testUpdate_MalformedDigestTime_Rejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->updateOwnPreferences(1, $this->actor(), [
			'expectedVersion' => 0,
			'digestTime' => '25:99',
		], '11111111-1111-4111-8111-111111111111');
	}

	public function testUpdate_SingleQuietBound_Rejected(): void {
		try {
			$this->service->updateOwnPreferences(1, $this->actor(), [
				'expectedVersion' => 0,
				'quietStart' => '20:00',
			], '11111111-1111-4111-8111-111111111111');
			$this->fail('a single quiet bound must be rejected');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('A quiet range needs both a start and an end', $e->getMessage());
		}
		$this->assertSame([], $this->writes);
	}

	public function testUpdate_DigestEnabledWithoutDigestTime_Rejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A summary time is required while a summary is enabled');
		$this->service->updateOwnPreferences(1, $this->actor(), [
			'expectedVersion' => 0,
			'taskDigestEnabled' => true,
			'digestTime' => null,
		], '11111111-1111-4111-8111-111111111111');
	}

	// ------------------------------------------------------------------
	// Versioned update semantics
	// ------------------------------------------------------------------

	public function testUpdate_FirstSave_CreatesDeliveryOnlyRowAtVersionOneWithoutConsent(): void {
		$payload = $this->service->updateOwnPreferences(1, $this->actor(), [
			'expectedVersion' => 0,
			'taskDigestEnabled' => true,
			'digestTime' => '19:30',
		], '11111111-1111-4111-8111-111111111111');

		$this->assertSame(['insert'], $this->writes);
		$this->assertSame(1, $payload['version']);
		$this->assertSame('none', $payload['ranking']['state'], 'a first save never grants ranking consent (RF-076-01)');
		$this->assertTrue($payload['taskDigestEnabled']);
		$this->assertSame('19:30', $payload['digestTime']);
	}

	public function testUpdate_StaleExpectedVersion_ConflictsWithoutAnyWrite(): void {
		$pref = new MemberPreference();
		$pref->setHouseId(1);
		$pref->setHouseUserId(7);
		$pref->setVersion(3);
		$this->store['1:7'] = $pref;

		try {
			$this->service->updateOwnPreferences(1, $this->actor(), [
				'expectedVersion' => 2,
				'taskDigestEnabled' => false,
			], '11111111-1111-4111-8111-111111111111');
			$this->fail('a stale expectedVersion must conflict');
		} catch (ConflictException $e) {
			$this->assertSame('The preferences changed since they were displayed', $e->getMessage());
		}
		$this->assertSame([], $this->writes, 'a conflict writes nothing — the client draft is preserved');
	}

	public function testUpdate_WithFreshVersion_BumpsSharedVersionAndPreservesConsent(): void {
		$pref = new MemberPreference();
		$pref->setHouseId(1);
		$pref->setHouseUserId(7);
		$pref->setVersion(3);
		$pref->setRankingConsentGeneration(2);
		$pref->setRankingEffectiveMonday(new \DateTime('2030-01-07'));
		$pref->setRankingSavedZone('Europe/Berlin');
		$pref->setTaskDigestEnabled(true);
		$this->store['1:7'] = $pref;

		$payload = $this->service->updateOwnPreferences(1, $this->actor(), [
			'expectedVersion' => 3,
			'quietStart' => '21:00',
			'quietEnd' => '07:00',
		], '11111111-1111-4111-8111-111111111111');

		$this->assertSame(['update'], $this->writes);
		$this->assertSame(4, $payload['version']);
		// The consent block is untouched by a delivery-only write.
		$this->assertSame('pending', $payload['ranking']['state']);
		$this->assertSame(2, $payload['ranking']['generation']);
		$this->assertSame('21:00', $payload['quietStart']);
		$this->assertTrue($payload['taskDigestEnabled']);
	}

	public function testUpdate_CrossMidnightQuietRange_IsAccepted(): void {
		$payload = $this->service->updateOwnPreferences(1, $this->actor(), [
			'expectedVersion' => 0,
			'orgDigestEnabled' => true,
			'digestTime' => '18:00',
			'quietStart' => '20:00',
			'quietEnd' => '08:00',
		], '11111111-1111-4111-8111-111111111111');

		$this->assertSame('20:00', $payload['quietStart']);
		$this->assertSame('08:00', $payload['quietEnd']);
	}

	public function testUpdate_PartialQuietBound_UsesTheSavedOtherBound(): void {
		$pref = new MemberPreference();
		$pref->setVersion(1);
		$pref->setQuietStart('20:00');
		$pref->setQuietEnd('08:00');
		$this->store['1:7'] = $pref;
		$result = $this->service->updateOwnPreferences(1, $this->actor(), ['expectedVersion' => 1, 'quietEnd' => '07:00'], '11111111-1111-4111-8111-111111111111');
		$this->assertSame('20:00', $result['quietStart']);
		$this->assertSame('07:00', $result['quietEnd']);
	}

	/** @dataProvider invalidPartialSettings */
	public function testUpdate_PartialUpdateWouldInvalidateSavedSettings_RejectsWithoutWrites(array $values): void {
		$pref = new MemberPreference();
		$pref->setVersion(1);
		$pref->setTaskDigestEnabled(true);
		$pref->setQuietStart('20:00');
		$pref->setQuietEnd('08:00');
		$this->store['1:7'] = $pref;
		try {
			$this->service->updateOwnPreferences(1, $this->actor(), ['expectedVersion' => 1] + $values, '11111111-1111-4111-8111-111111111111');
			$this->fail('the resulting saved settings must remain valid');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame([], $this->writes);
			$this->assertSame(1, $pref->getVersion());
		}
	}

	public static function invalidPartialSettings(): array {
		return [[['digestTime' => null]], [['quietEnd' => null]]];
	}

	// ------------------------------------------------------------------
	// Quiet-hour gate semantics
	// ------------------------------------------------------------------

	public function testIsWithinQuietHours_CompleteRange_GatesOnlyInside(): void {
		$this->assertTrue(PreferenceService::isWithinQuietHours('20:00', '08:00', '23:00'));
		$this->assertTrue(PreferenceService::isWithinQuietHours('20:00', '08:00', '07:59'));
		$this->assertFalse(PreferenceService::isWithinQuietHours('20:00', '08:00', '08:00'));
		$this->assertFalse(PreferenceService::isWithinQuietHours('20:00', '08:00', '19:59'));
		$this->assertTrue(PreferenceService::isWithinQuietHours('12:00', '13:00', '12:30'));
		$this->assertFalse(PreferenceService::isWithinQuietHours('12:00', '13:00', '13:00'));
	}

	public function testIsWithinQuietHours_IncompleteRange_NeverQuiet(): void {
		$this->assertFalse(PreferenceService::isWithinQuietHours(null, '08:00', '23:00'));
		$this->assertFalse(PreferenceService::isWithinQuietHours('20:00', null, '23:00'));
		$this->assertFalse(PreferenceService::isWithinQuietHours(null, null, '23:00'));
	}

	public function testIsWithinQuietHours_EqualBounds_IsFullDayQuiet(): void {
		$this->assertTrue(PreferenceService::isWithinQuietHours('12:00', '12:00', '00:00'));
		$this->assertTrue(PreferenceService::isWithinQuietHours('12:00', '12:00', '23:59'));
	}
}
