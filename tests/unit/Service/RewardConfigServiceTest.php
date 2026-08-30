<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RewardConfigService
 */
class RewardConfigServiceTest extends TestCase {
	private RewardConfigService $service;
	private IAppConfig $config;

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->service = new RewardConfigService(
			$this->config,
			$this->createMock(AppLogger::class)
		);
	}

	/**
	 * GetEarlyBonusThresholdDays_ReturnsDefault
	 */
	public function testGetEarlyBonusThresholdDays_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('21');

		$result = $this->service->getEarlyBonusThresholdDays();

		$this->assertEquals(21, $result);
	}

	/**
	 * GetEarlyBonusMultiplier_ReturnsDefault
	 */
	public function testGetEarlyBonusMultiplier_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('1.5');

		$result = $this->service->getEarlyBonusMultiplier();

		$this->assertEquals(1.5, $result);
	}

	/**
	 * GetEnergyMaxPerTask_ReturnsDefault
	 */
	public function testGetEnergyMaxPerTask_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('9');

		$result = $this->service->getEnergyMaxPerTask();

		$this->assertEquals(9, $result);
	}

	/**
	 * GetCoinBasePerEnergy_ReturnsDefault
	 */
	public function testGetCoinBasePerEnergy_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('10');

		$result = $this->service->getCoinBasePerEnergy();

		$this->assertEquals(10, $result);
	}

	/**
	 * GetAllConfig_ReturnsAllValues
	 */
	public function testGetAllConfig_ReturnsAllValues(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getAllConfig();

		$this->assertArrayHasKey('tierBronzeMultiplier', $result);
		$this->assertArrayHasKey('tierSilverMultiplier', $result);
		$this->assertArrayHasKey('tierGoldMultiplier', $result);
		$this->assertArrayHasKey('earlyBonusThresholdDays', $result);
		$this->assertArrayHasKey('earlyBonusMultiplier', $result);
		$this->assertArrayHasKey('energyMaxPerTask', $result);
		$this->assertArrayHasKey('coinBasePerEnergy', $result);
	}

	/**
	 * SetTierMultiplier_StoresValue
	 */
	public function testSetTierMultiplier_StoresValue(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'tier_gold_multiplier', '2.5');

		$this->service->setTierMultiplier('gold', 2.5);
	}

	/**
	 * UpdateConfig_UpdatesMultipleValues
	 */
	public function testUpdateConfig_UpdatesMultipleValues(): void {
		$this->config
			->expects($this->exactly(2))
			->method('setValueString');

		$this->service->updateConfig([
			'tierGoldMultiplier' => 2.5,
			'energyMaxPerTask' => 12,
		]);
	}

	/**
	 * GetStreakFreezesPerWeek_ReturnsDefault
	 */
	public function testGetStreakFreezesPerWeek_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('1');

		$result = $this->service->getStreakFreezesPerWeek();

		$this->assertEquals(1, $result);
	}

	/**
	 * GetStreakFreezeCap_ReturnsDefault
	 */
	public function testGetStreakFreezeCap_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('2');

		$result = $this->service->getStreakFreezeCap();

		$this->assertEquals(2, $result);
	}

	/**
	 * GetComboBonusFreezeThreshold_ReturnsDefault
	 */
	public function testGetComboBonusFreezeThreshold_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('5');

		$result = $this->service->getComboBonusFreezeThreshold();

		$this->assertEquals(5, $result);
	}

	/**
	 * SetStreakFreezesPerWeek_StoresValue
	 */
	public function testSetStreakFreezesPerWeek_StoresValue(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'streak_freezes_per_week', '3');

		$this->service->setStreakFreezesPerWeek(3);
	}

	/**
	 * GetAllConfig_IncludesFreezeKeys
	 */
	public function testGetAllConfig_IncludesFreezeKeys(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getAllConfig();

		$this->assertArrayHasKey('streakFreezesPerWeek', $result);
		$this->assertArrayHasKey('streakFreezeCap', $result);
		$this->assertArrayHasKey('comboBonusFreezeThreshold', $result);
	}

	/**
	 * UpdateConfig_HandlesFreezeKeys
	 */
	public function testUpdateConfig_HandlesFreezeKeys(): void {
		$this->config
			->expects($this->exactly(2))
			->method('setValueString');

		$this->service->updateConfig([
			'streakFreezesPerWeek' => 2,
			'streakFreezeCap' => 3,
		]);
	}

	/**
	 * GetWeeklyGoalTargetPercent_ReturnsDefault
	 */
	public function testGetWeeklyGoalTargetPercent_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturn('80');

		$result = $this->service->getWeeklyGoalTargetPercent();

		$this->assertEquals(80, $result);
	}

	/**
	 * GetWeeklyGoalTargetPercent_ClampsOutOfRange
	 */
	public function testGetWeeklyGoalTargetPercent_ClampsOutOfRange(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				// Stored value below the floor.
				return '0';
			});

		$result = $this->service->getWeeklyGoalTargetPercent();

		$this->assertEquals(1, $result);
	}

	/**
	 * GetWeeklyGoalTargetPercent_ClampsAboveHundred
	 */
	public function testGetWeeklyGoalTargetPercent_ClampsAboveHundred(): void {
		$this->config
			->method('getValueString')
			->willReturn('150');

		$result = $this->service->getWeeklyGoalTargetPercent();

		$this->assertEquals(100, $result);
	}

	/**
	 * SetWeeklyGoalTargetPercent_StoresClampedValue
	 */
	public function testSetWeeklyGoalTargetPercent_StoresClampedValue(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'weekly_goal_target_percent', '90');

		$this->service->setWeeklyGoalTargetPercent(90);
	}

	/**
	 * SetWeeklyGoalTargetPercent_ClampsBelowOne
	 */
	public function testSetWeeklyGoalTargetPercent_ClampsBelowOne(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'weekly_goal_target_percent', '1');

		$this->service->setWeeklyGoalTargetPercent(-5);
	}

	/**
	 * GetAllConfig_IncludesWeeklyGoalKey
	 */
	public function testGetAllConfig_IncludesWeeklyGoalKey(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getAllConfig();

		$this->assertArrayHasKey('weeklyGoalTargetPercent', $result);
		$this->assertEquals(80, $result['weeklyGoalTargetPercent']);
	}

	/**
	 * UpdateConfig_HandlesWeeklyGoalKey
	 */
	public function testUpdateConfig_HandlesWeeklyGoalKey(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'weekly_goal_target_percent', '75');

		$this->service->updateConfig([
			'weeklyGoalTargetPercent' => 75,
		]);
	}

	/**
	 * GetTitlesEnabled_ReturnsDefaultTrue
	 */
	public function testGetTitlesEnabled_ReturnsDefaultTrue(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$this->assertTrue($this->service->getTitlesEnabled());
	}

	/**
	 * SetTitlesEnabled_StoresValue
	 */
	public function testSetTitlesEnabled_StoresValue(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'titles_enabled', '0');

		$this->service->setTitlesEnabled(false);
	}

	/**
	 * GetTitleThreshold_ReturnsCatalogDefaultWhenUnset
	 */
	public function testGetTitleThreshold_ReturnsCatalogDefaultWhenUnset(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		// home_helpers tier 0 default is 50.
		$this->assertSame(50, $this->service->getTitleThreshold('home_helpers', 0));
	}

	/**
	 * GetTitleThreshold_ClampsBelowOneToOne
	 */
	public function testGetTitleThreshold_ClampsBelowOneToOne(): void {
		$this->config
			->method('getValueString')
			->willReturn('0');

		$this->assertSame(1, $this->service->getTitleThreshold('home_helpers', 0));
	}

	/**
	 * SetTitleThreshold_StoresClampedValue
	 */
	public function testSetTitleThreshold_StoresClampedValue(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'title_threshold_home_helpers_1', '15');

		$this->service->setTitleThreshold('home_helpers', 1, 15);
	}

	/**
	 * GetAllConfig_IncludesTitleKeys
	 */
	public function testGetAllConfig_IncludesTitleKeys(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getAllConfig();

		$this->assertArrayHasKey('titlesEnabled', $result);
		$this->assertTrue($result['titlesEnabled']);
		$this->assertArrayHasKey('titleThresholds', $result);
		$this->assertCount(3, $result['titleThresholds']['home_helpers']);
	}

	/**
	 * UpdateConfig_HandlesTitlesEnabledAndThresholds
	 */
	public function testUpdateConfig_HandlesTitlesEnabledAndThresholds(): void {
		$this->config
			->expects($this->exactly(4))
			->method('setValueString');

		$this->service->updateConfig([
			'titlesEnabled' => false,
			'titleThresholds' => [
				'home_helpers' => [60, 260, 1010],
			],
		]);
	}

	/**
	 * UpdateConfig_RejectsNonAscendingThresholds_BeforeAnyWrite
	 */
	public function testUpdateConfig_RejectsNonAscendingThresholds_BeforeAnyWrite(): void {
		// Non-ascending (500 then 50) must be rejected before any config write.
		$this->config->expects($this->never())->method('setValueString');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->updateConfig([
			'titleThresholds' => [
				'home_helpers' => [500, 50, 1000],
			],
		]);
	}

	/**
	 * UpdateConfig_TitleThresholdsSparsePayload_WritesCorrectTierIndex
	 *
	 * Regression: applyTitleThresholds() iterated array_values($tierValues),
	 * which reindexes a sparse payload (e.g. [2 => 1000]) to position 0, so
	 * the value was written to the wrong tier. The tier index must come from
	 * the original key.
	 */
	public function testUpdateConfig_TitleThresholdsSparsePayload_WritesCorrectTierIndex(): void {
		$written = [];
		$this->config
			->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			});

		// Sparse payload: only tier index 2 provided.
		$this->service->updateConfig([
			'titleThresholds' => [
				'home_helpers' => [2 => 1000],
			],
		]);

		$this->assertSame('1000', $written['title_threshold_home_helpers_2'] ?? null);
		$this->assertArrayNotHasKey('title_threshold_home_helpers_0', $written);
		$this->assertArrayNotHasKey('title_threshold_home_helpers_1', $written);
	}

	/**
	 * GetComboMultiplier_ReturnsDefault
	 */
	public function testGetComboMultiplier_ReturnsDefault(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getComboMultiplier();

		$this->assertEquals(2.0, $result);
	}

	/**
	 * GetComboMultiplier_ClampsNegativeToZero
	 */
	public function testGetComboMultiplier_ClampsNegativeToZero(): void {
		$this->config
			->method('getValueString')
			->willReturn('-1.5');

		$result = $this->service->getComboMultiplier();

		$this->assertEquals(0.0, $result);
	}

	/**
	 * SetComboMultiplier_StoresClampedValue
	 */
	public function testSetComboMultiplier_StoresClampedValue(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'combo_multiplier', '3');

		$this->service->setComboMultiplier(3.0);
	}

	/**
	 * SetComboMultiplier_ClampsNegativeToZero
	 */
	public function testSetComboMultiplier_ClampsNegativeToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'combo_multiplier', '0');

		$this->service->setComboMultiplier(-1.0);
	}

	/**
	 * GetAllConfig_IncludesComboMultiplierKey
	 */
	public function testGetAllConfig_IncludesComboMultiplierKey(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getAllConfig();

		$this->assertArrayHasKey('comboMultiplier', $result);
		$this->assertEquals(2.0, $result['comboMultiplier']);
	}

	/**
	 * UpdateConfig_HandlesComboMultiplierKey
	 */
	public function testUpdateConfig_HandlesComboMultiplierKey(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'combo_multiplier', '3');

		$this->service->updateConfig([
			'comboMultiplier' => 3.0,
		]);
	}

	/**
	 * SetTierMultiplier_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetTierMultiplier_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'tier_gold_multiplier', '0');

		$this->service->setTierMultiplier('gold', -2.0);
	}

	/**
	 * SetEarlyBonusMultiplier_BelowOne_ClampsToOne (CR-SVC-19)
	 */
	public function testSetEarlyBonusMultiplier_BelowOne_ClampsToOne(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'early_bonus_multiplier', '1');

		$this->service->setEarlyBonusMultiplier(0.5);
	}

	/**
	 * SetCoinBasePerEnergy_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetCoinBasePerEnergy_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'coin_base_per_energy', '0');

		$this->service->setCoinBasePerEnergy(-10);
	}

	/**
	 * SetStreakFreezeCap_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetStreakFreezeCap_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'streak_freeze_cap', '0');

		$this->service->setStreakFreezeCap(-1);
	}

	/**
	 * SetStreakFreezesPerWeek_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetStreakFreezesPerWeek_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'streak_freezes_per_week', '0');

		$this->service->setStreakFreezesPerWeek(-2);
	}

	/**
	 * SetComboBonusFreezeThreshold_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetComboBonusFreezeThreshold_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'combo_bonus_freeze_threshold', '0');

		$this->service->setComboBonusFreezeThreshold(-3);
	}

	/**
	 * SetEarlyBonusThresholdDays_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetEarlyBonusThresholdDays_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'early_bonus_threshold_days', '0');

		$this->service->setEarlyBonusThresholdDays(-5);
	}

	/**
	 * SetEnergyMaxPerTask_NegativeValue_ClampsToZero (CR-SVC-19)
	 */
	public function testSetEnergyMaxPerTask_NegativeValue_ClampsToZero(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', 'energy_max_per_task', '0');

		$this->service->setEnergyMaxPerTask(-9);
	}

	/**
	 * GetEarlyBonusMultiplier_PersistedBelowOne_ClampedOnRead (CR-SVC-19)
	 */
	public function testGetEarlyBonusMultiplier_PersistedBelowOne_ClampedOnRead(): void {
		$this->config
			->method('getValueString')
			->willReturn('0.5');

		$this->assertEquals(1.0, $this->service->getEarlyBonusMultiplier());
	}

	/**
	 * GetCoinBasePerEnergy_PersistedNegative_ClampedOnRead (CR-SVC-19)
	 */
	public function testGetCoinBasePerEnergy_PersistedNegative_ClampedOnRead(): void {
		$this->config
			->method('getValueString')
			->willReturn('-10');

		$this->assertEquals(0, $this->service->getCoinBasePerEnergy());
	}

	/**
	 * GetStreakFreezeCap_PersistedNegative_ClampedOnRead (CR-SVC-19)
	 */
	public function testGetStreakFreezeCap_PersistedNegative_ClampedOnRead(): void {
		$this->config
			->method('getValueString')
			->willReturn('-2');

		$this->assertEquals(0, $this->service->getStreakFreezeCap());
	}

	/**
	 * GetEarlyBonusThresholdDays_PersistedNegative_ClampedOnRead (CR-SVC-19)
	 */
	public function testGetEarlyBonusThresholdDays_PersistedNegative_ClampedOnRead(): void {
		$this->config
			->method('getValueString')
			->willReturn('-3');

		$this->assertEquals(0, $this->service->getEarlyBonusThresholdDays());
	}

	/**
	 * ClampWarning_LoggedWithKeyAndClampedValue (CR-SVC-19)
	 */
	public function testClampWarning_LoggedWithKeyAndClampedValue(): void {
		$appLogger = $this->createMock(AppLogger::class);
		$appLogger->expects($this->once())
			->method('warning')
			->with(
				'Reward config value clamped',
				$this->callback(static function (array $context): bool {
					return ($context['service'] ?? null) === 'RewardConfigService'
						&& ($context['key'] ?? null) === 'coin_base_per_energy'
						&& ($context['value'] ?? null) === -10
						&& ($context['clampedTo'] ?? null) === 0;
				})
			);

		$service = new RewardConfigService($this->config, $appLogger);
		$service->setCoinBasePerEnergy(-10);
	}

	/**
	 * ClampWarning_NotLoggedForInRangeValue (CR-SVC-19)
	 */
	public function testClampWarning_NotLoggedForInRangeValue(): void {
		$appLogger = $this->createMock(AppLogger::class);
		$appLogger->expects($this->never())->method('warning');

		$service = new RewardConfigService($this->config, $appLogger);
		$service->setCoinBasePerEnergy(10);
	}

	// ---------------------------------------------------------------------------
	// Registry specs (CS-PLAN-049): every scalar registry key is pinned by a
	// table-driven default/clamp/round-trip suite plus getAllConfig/updateConfig
	// parity specs, so the generic readSpec/writeSpec path cannot drift from the
	// former per-key literals.
	// ---------------------------------------------------------------------------

	/** apiField => IAppConfig key (independent of the service's REGISTRY). */
	private const KEY_BY_FIELD = [
		'tierBronzeMultiplier' => RewardConfigService::KEY_TIER_BRONZE_MULTIPLIER,
		'tierSilverMultiplier' => RewardConfigService::KEY_TIER_SILVER_MULTIPLIER,
		'tierGoldMultiplier' => RewardConfigService::KEY_TIER_GOLD_MULTIPLIER,
		'earlyBonusThresholdDays' => RewardConfigService::KEY_EARLY_BONUS_THRESHOLD_DAYS,
		'earlyBonusMultiplier' => RewardConfigService::KEY_EARLY_BONUS_MULTIPLIER,
		'energyMaxPerTask' => RewardConfigService::KEY_ENERGY_MAX_PER_TASK,
		'coinBasePerEnergy' => RewardConfigService::KEY_COIN_BASE_PER_ENERGY,
		'streakFreezesPerWeek' => RewardConfigService::KEY_STREAK_FREEZES_PER_WEEK,
		'streakFreezeCap' => RewardConfigService::KEY_STREAK_FREEZE_CAP,
		'comboBonusFreezeThreshold' => RewardConfigService::KEY_COMBO_BONUS_FREEZE_THRESHOLD,
		'comboMultiplier' => RewardConfigService::KEY_COMBO_MULTIPLIER,
		'weeklyGoalTargetPercent' => RewardConfigService::KEY_WEEKLY_GOAL_TARGET_PERCENT,
		'teamworkBonusEnabled' => RewardConfigService::KEY_TEAMWORK_BONUS_ENABLED,
		'teamworkBonusMaxPct' => RewardConfigService::KEY_TEAMWORK_BONUS_MAX_PCT,
		'titlesEnabled' => RewardConfigService::KEY_TITLES_ENABLED,
	];

	public static function registryDefaultProvider(): array {
		return [
			'tierBronzeMultiplier' => ['tierBronzeMultiplier', 1.0],
			'tierSilverMultiplier' => ['tierSilverMultiplier', 1.5],
			'tierGoldMultiplier' => ['tierGoldMultiplier', 2.0],
			'earlyBonusThresholdDays' => ['earlyBonusThresholdDays', 21],
			'earlyBonusMultiplier' => ['earlyBonusMultiplier', 1.5],
			'energyMaxPerTask' => ['energyMaxPerTask', 9],
			'coinBasePerEnergy' => ['coinBasePerEnergy', 10],
			'streakFreezesPerWeek' => ['streakFreezesPerWeek', 1],
			'streakFreezeCap' => ['streakFreezeCap', 2],
			'comboBonusFreezeThreshold' => ['comboBonusFreezeThreshold', 5],
			'comboMultiplier' => ['comboMultiplier', 2.0],
			'weeklyGoalTargetPercent' => ['weeklyGoalTargetPercent', 80],
			'teamworkBonusEnabled' => ['teamworkBonusEnabled', true],
			'teamworkBonusMaxPct' => ['teamworkBonusMaxPct', 50],
			'titlesEnabled' => ['titlesEnabled', true],
		];
	}

	/**
	 * RegistryKey_ReturnsDefaultWhenUnset — one row per registry key.
	 *
	 * @dataProvider registryDefaultProvider
	 */
	public function testRegistryKey_ReturnsDefaultWhenUnset(string $field, $expected): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$this->assertSame($expected, $this->registryGet($field));
	}

	public static function registryReadClampProvider(): array {
		return [
			'tierBronzeMultiplier below min' => ['tierBronzeMultiplier', '-2.5', 0.0],
			'tierSilverMultiplier below min' => ['tierSilverMultiplier', '-1', 0.0],
			'tierGoldMultiplier below min' => ['tierGoldMultiplier', '-0.1', 0.0],
			'earlyBonusThresholdDays below min' => ['earlyBonusThresholdDays', '-3', 0],
			'earlyBonusMultiplier below min' => ['earlyBonusMultiplier', '0.5', 1.0],
			'energyMaxPerTask below min' => ['energyMaxPerTask', '-9', 0],
			'coinBasePerEnergy below min' => ['coinBasePerEnergy', '-10', 0],
			'streakFreezesPerWeek below min' => ['streakFreezesPerWeek', '-2', 0],
			'streakFreezeCap below min' => ['streakFreezeCap', '-2', 0],
			'comboBonusFreezeThreshold below min' => ['comboBonusFreezeThreshold', '-3', 0],
			'comboMultiplier below min' => ['comboMultiplier', '-1.5', 0.0],
			'weeklyGoalTargetPercent below min' => ['weeklyGoalTargetPercent', '0', 1],
			'weeklyGoalTargetPercent above max' => ['weeklyGoalTargetPercent', '150', 100],
			'teamworkBonusMaxPct below min' => ['teamworkBonusMaxPct', '-5', 0],
			'teamworkBonusMaxPct above max' => ['teamworkBonusMaxPct', '120', 100],
		];
	}

	/**
	 * RegistryKey_ClampsPersistedOutOfRange_OnRead (CR-SVC-19 parity)
	 *
	 * @dataProvider registryReadClampProvider
	 */
	public function testRegistryKey_ClampsPersistedOutOfRange_OnRead(string $field, string $storedRaw, $expected): void {
		$this->config
			->method('getValueString')
			->willReturn($storedRaw);

		$this->assertSame($expected, $this->registryGet($field));
	}

	public static function registryWriteClampProvider(): array {
		return [
			'tierBronzeMultiplier below min' => ['tierBronzeMultiplier', -2.5, '0'],
			'tierSilverMultiplier below min' => ['tierSilverMultiplier', -1.0, '0'],
			'tierGoldMultiplier below min' => ['tierGoldMultiplier', -0.1, '0'],
			'earlyBonusThresholdDays below min' => ['earlyBonusThresholdDays', -3, '0'],
			'earlyBonusMultiplier below min' => ['earlyBonusMultiplier', 0.5, '1'],
			'energyMaxPerTask below min' => ['energyMaxPerTask', -9, '0'],
			'coinBasePerEnergy below min' => ['coinBasePerEnergy', -10, '0'],
			'streakFreezesPerWeek below min' => ['streakFreezesPerWeek', -2, '0'],
			'streakFreezeCap below min' => ['streakFreezeCap', -2, '0'],
			'comboBonusFreezeThreshold below min' => ['comboBonusFreezeThreshold', -3, '0'],
			'comboMultiplier below min' => ['comboMultiplier', -1.5, '0'],
			'weeklyGoalTargetPercent below min' => ['weeklyGoalTargetPercent', 0, '1'],
			'weeklyGoalTargetPercent above max' => ['weeklyGoalTargetPercent', 150, '100'],
			'teamworkBonusMaxPct below min' => ['teamworkBonusMaxPct', -5, '0'],
			'teamworkBonusMaxPct above max' => ['teamworkBonusMaxPct', 120, '100'],
		];
	}

	/**
	 * RegistryKey_ClampsOutOfRange_OnWrite (CR-SVC-19 parity)
	 *
	 * @dataProvider registryWriteClampProvider
	 */
	public function testRegistryKey_ClampsOutOfRange_OnWrite(string $field, $input, string $expectedStored): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', self::KEY_BY_FIELD[$field], $expectedStored);

		$this->registrySet($field, $input);
	}

	public static function registryBoolFieldProvider(): array {
		return [
			'teamworkBonusEnabled' => ['teamworkBonusEnabled', RewardConfigService::KEY_TEAMWORK_BONUS_ENABLED],
			'titlesEnabled' => ['titlesEnabled', RewardConfigService::KEY_TITLES_ENABLED],
		];
	}

	/**
	 * RegistryBoolKey_RoundTripsOneAndZero
	 *
	 * @dataProvider registryBoolFieldProvider
	 */
	public function testRegistryBoolKey_RoundTripsOneAndZero(string $field, string $key): void {
		$written = [];
		$this->config
			->method('setValueString')
			->willReturnCallback(function ($app, $k, $v) use (&$written): void {
				$written[$k] = $v;
			});
		$stored = '1';
		$this->config
			->method('getValueString')
			->willReturnCallback(function () use (&$stored): string {
				return $stored;
			});

		$this->registrySet($field, true);
		$this->assertSame('1', $written[$key]);
		$this->registrySet($field, false);
		$this->assertSame('0', $written[$key]);

		$this->assertTrue($this->registryGet($field));
		$stored = '0';
		$this->assertFalse($this->registryGet($field));
	}

	/**
	 * UpdateConfig_IgnoresUnknownFields
	 */
	public function testUpdateConfig_IgnoresUnknownFields(): void {
		$this->config->expects($this->never())->method('setValueString');

		$this->service->updateConfig([
			'bogusField' => 5,
			'tierMultiplier' => 2.0,
		]);
	}

	/**
	 * UpdateConfig_SkipsExplicitNullValues (isset semantics)
	 */
	public function testUpdateConfig_SkipsExplicitNullValues(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', RewardConfigService::KEY_TITLES_ENABLED, '0');

		$this->service->updateConfig([
			'coinBasePerEnergy' => null,
			'titlesEnabled' => false,
		]);
	}

	/**
	 * UpdateConfig_AppliesPartialPayload
	 */
	public function testUpdateConfig_AppliesPartialPayload(): void {
		$this->config
			->expects($this->once())
			->method('setValueString')
			->with('chorestrike', RewardConfigService::KEY_ENERGY_MAX_PER_TASK, '12');

		$this->service->updateConfig(['energyMaxPerTask' => 12]);
	}

	/**
	 * GetAllConfig_ExposesExactlyTheSixteenCurrentFields — shape pin
	 * (public API contract: field set AND order, incl. nested titleThresholds).
	 */
	public function testGetAllConfig_ExposesExactlyTheSixteenCurrentFields(): void {
		$this->config
			->method('getValueString')
			->willReturnCallback(function ($app, $key, $default) {
				return $default;
			});

		$result = $this->service->getAllConfig();

		$this->assertSame([
			'tierBronzeMultiplier',
			'tierSilverMultiplier',
			'tierGoldMultiplier',
			'earlyBonusThresholdDays',
			'earlyBonusMultiplier',
			'energyMaxPerTask',
			'coinBasePerEnergy',
			'streakFreezesPerWeek',
			'streakFreezeCap',
			'comboBonusFreezeThreshold',
			'comboMultiplier',
			'weeklyGoalTargetPercent',
			'teamworkBonusEnabled',
			'teamworkBonusMaxPct',
			'titlesEnabled',
			'titleThresholds',
		], array_keys($result));
		$this->assertIsArray($result['titleThresholds']);
	}

	/**
	 * Invoke the typed public getter for a registry apiField. The tier keys
	 * lost their dedicated getter in CS-PLAN-055 (getTierMultiplier was
	 * test-only) — they read through the getAllConfig registry loop.
	 */
	private function registryGet(string $field) {
		return match ($field) {
			'tierBronzeMultiplier', 'tierSilverMultiplier', 'tierGoldMultiplier' => $this->service->getAllConfig()[$field],
			'earlyBonusThresholdDays' => $this->service->getEarlyBonusThresholdDays(),
			'earlyBonusMultiplier' => $this->service->getEarlyBonusMultiplier(),
			'energyMaxPerTask' => $this->service->getEnergyMaxPerTask(),
			'coinBasePerEnergy' => $this->service->getCoinBasePerEnergy(),
			'streakFreezesPerWeek' => $this->service->getStreakFreezesPerWeek(),
			'streakFreezeCap' => $this->service->getStreakFreezeCap(),
			'comboBonusFreezeThreshold' => $this->service->getComboBonusFreezeThreshold(),
			'comboMultiplier' => $this->service->getComboMultiplier(),
			'weeklyGoalTargetPercent' => $this->service->getWeeklyGoalTargetPercent(),
			'teamworkBonusEnabled' => $this->service->getTeamworkBonusEnabled(),
			'teamworkBonusMaxPct' => $this->service->getTeamworkBonusMaxPct(),
			'titlesEnabled' => $this->service->getTitlesEnabled(),
			default => throw new \RuntimeException('Unknown registry field: ' . $field),
		};
	}

	/**
	 * Invoke the typed public setter for a registry apiField.
	 */
	private function registrySet(string $field, $value): void {
		match ($field) {
			'tierBronzeMultiplier' => $this->service->setTierMultiplier('bronze', (float)$value),
			'tierSilverMultiplier' => $this->service->setTierMultiplier('silver', (float)$value),
			'tierGoldMultiplier' => $this->service->setTierMultiplier('gold', (float)$value),
			'earlyBonusThresholdDays' => $this->service->setEarlyBonusThresholdDays((int)$value),
			'earlyBonusMultiplier' => $this->service->setEarlyBonusMultiplier((float)$value),
			'energyMaxPerTask' => $this->service->setEnergyMaxPerTask((int)$value),
			'coinBasePerEnergy' => $this->service->setCoinBasePerEnergy((int)$value),
			'streakFreezesPerWeek' => $this->service->setStreakFreezesPerWeek((int)$value),
			'streakFreezeCap' => $this->service->setStreakFreezeCap((int)$value),
			'comboBonusFreezeThreshold' => $this->service->setComboBonusFreezeThreshold((int)$value),
			'comboMultiplier' => $this->service->setComboMultiplier((float)$value),
			'weeklyGoalTargetPercent' => $this->service->setWeeklyGoalTargetPercent((int)$value),
			'teamworkBonusEnabled' => $this->service->setTeamworkBonusEnabled((bool)$value),
			'teamworkBonusMaxPct' => $this->service->setTeamworkBonusMaxPct((int)$value),
			'titlesEnabled' => $this->service->setTitlesEnabled((bool)$value),
			default => throw new \RuntimeException('Unknown registry field: ' . $field),
		};
	}
}
