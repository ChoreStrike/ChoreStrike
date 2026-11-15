<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Controller;

use OCA\ChoreStrike\Controller\PageController;
use OCA\ChoreStrike\Controller\PreferenceController;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\ColorSchemeService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\PreferenceService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;

/**
 * CS-PLAN-090 WP-K (D-090-61/64/67): the colour scheme on a real instance —
 * a member stores their own scheme in the per-user app config and reads it
 * back with the preferences, *Standard* removes the value, an unknown
 * scheme and a non-member write nothing, and the page hands the viewer's
 * scheme to the app as initial state.
 */
class ColorSchemeIntegrationTest extends IntegrationTestCase {
	/** @var int[] */
	private array $membershipIds = [];

	protected function tearDown(): void {
		if ($this->membershipIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_house_users')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->membershipIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$schemes = \OC::$server->get(ColorSchemeService::class);
		foreach (['cs_member', 'cs_other', 'cs_outsider'] as $userId) {
			$schemes->forget($userId);
		}
		parent::tearDown();
	}

	public function testMember_StoresTheOwnScheme_AndReadsItWithThePreferences(): void {
		$this->instanceMembers();
		$this->storedValue('cs_other', 'berry');

		$response = $this->controller('cs_member', 'forest')->updateColorScheme();

		self::assertSame(200, $response->getStatus());
		self::assertSame('forest', $this->storedValue('cs_member'));
		self::assertSame('berry', $this->storedValue('cs_other'), 'another member keeps their scheme');
		$show = $this->controller('cs_member', null)->show();
		self::assertSame('forest', $show->getData()['data']['colorScheme']);
	}

	public function testStandard_RemovesTheStoredValue(): void {
		$this->instanceMembers();
		$this->storedValue('cs_member', 'slate');

		$response = $this->controller('cs_member', 'standard')->updateColorScheme();

		self::assertSame(200, $response->getStatus());
		self::assertFalse($this->userConfig()->hasKey('cs_member', 'chorestrike', ColorSchemeService::KEY));
	}

	public function testUnknownScheme_IsRefusedWithoutWrite(): void {
		$this->instanceMembers();

		$response = $this->controller('cs_member', 'neon')->updateColorScheme();

		self::assertSame(400, $response->getStatus());
		self::assertFalse($this->userConfig()->hasKey('cs_member', 'chorestrike', ColorSchemeService::KEY));
	}

	public function testNonMember_IsRefusedWithoutWrite(): void {
		$this->instanceMembers();

		$response = $this->controller('cs_outsider', 'ocean')->updateColorScheme();

		self::assertSame(403, $response->getStatus());
		self::assertFalse($this->userConfig()->hasKey('cs_outsider', 'chorestrike', ColorSchemeService::KEY));
	}

	public function testThePage_ProvidesTheViewersScheme(): void {
		$this->instanceMembers();
		$this->storedValue('cs_member', 'sunset');
		$this->actingAs('cs_member');

		\OC::$server->get(PageController::class)->index();

		$states = \OC::$server->get(IInitialStateService::class)->getInitialStates();
		self::assertSame('"sunset"', $states['chorestrike-' . PageController::COLOR_SCHEME_STATE] ?? null);
	}

	/**
	 * The preference controller with real services and a request that
	 * carries only `colorScheme`.
	 */
	private function controller(string $userId, ?string $scheme): PreferenceController {
		$this->actingAs($userId);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'colorScheme' ? $scheme : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new PreferenceController(
			$request,
			\OC::$server->get(PreferenceService::class),
			\OC::$server->get(HouseService::class),
			\OC::$server->get(AuthorizationService::class),
			\OC::$server->get(ColorSchemeService::class),
			$l10n,
			\OC::$server->get(AppLogger::class),
		);
	}

	private function userConfig(): IUserConfig {
		return \OC::$server->get(IUserConfig::class);
	}

	/** Write a stored value directly, or read the stored one. */
	private function storedValue(string $userId, ?string $write = null): string {
		if ($write !== null) {
			$this->userConfig()->setValueString($userId, 'chorestrike', ColorSchemeService::KEY, $write);
		}
		$this->userConfig()->clearCache($userId);
		return $this->userConfig()->getValueString($userId, 'chorestrike', ColorSchemeService::KEY);
	}

	private function instanceMembers(): void {
		$houseService = \OC::$server->get(HouseService::class);
		$houseId = $houseService->getOrCreateHouse($houseService->getInstanceId())->getId();
		$this->addMembership($houseId, 'cs_member', 'Scheme Member', 'member');
		$this->addMembership($houseId, 'cs_other', 'Scheme Other', 'child');
		$this->actingAs('cs_outsider');
	}

	private function addMembership(int $houseId, string $userId, string $displayName, string $role): void {
		$this->actingAs($userId);
		if (\OC::$server->get(HouseUserMapper::class)->findByHouseAndUser($houseId, $userId) !== null) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_house_users')
			->values([
				'house_id' => $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT),
				'nextcloud_user_id' => $qb->createNamedParameter($userId),
				'display_name' => $qb->createNamedParameter($displayName),
				'role' => $qb->createNamedParameter($role),
				'contribution_minor' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'current_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'longest_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'unlocked_titles' => $qb->createNamedParameter('[]'),
				'joined_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			])
			->executeStatement();
		$this->membershipIds[] = (int)$this->db->lastInsertId('chorestrike_house_users');
	}
}
