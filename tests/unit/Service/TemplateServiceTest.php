<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\TemplateService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the v2 room-type-based TemplateService.
 */
class TemplateServiceTest extends TestCase {
	private TemplateService $service;
	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->l10n = $this->createMock(IL10N::class);
		// t() is the identity function for tests; the catalog ships English
		// source text so this mirrors the untranslated (en) path exactly.
		$this->l10n->method('t')->willReturnCallback(fn ($text) => $text);

		$this->service = new TemplateService($this->l10n);

		$this->injectProperty('templatesCache', self::FIXTURE_TEMPLATES);
		$this->injectProperty('templatesById', null);
		$this->injectProperty('roomTypesCache', self::FIXTURE_ROOM_TYPES);
	}

	private function injectProperty(string $name, $value): void {
		$ref = new \ReflectionProperty(TemplateService::class, $name);
		$ref->setAccessible(true);
		$ref->setValue($this->service, $value);
	}

	private const FIXTURE_ROOM_TYPES = [
		['id' => 'kitchen', 'defaultName' => 'Kitchen', 'icon' => 'Utensils', 'templateIds' => ['kitchen_counters', 'shared_dust']],
		['id' => 'bathroom', 'defaultName' => 'Bathroom', 'icon' => 'Bath', 'templateIds' => ['bathroom_toilet']],
		['id' => 'household', 'defaultName' => 'Household & Co.', 'icon' => 'WashingMachine', 'templateIds' => ['household_trash']],
	];

	private const FIXTURE_TEMPLATES = [
		[
			'id' => 'kitchen_counters',
			'name' => 'Wipe kitchen counters',
			'description' => 'Clear and wipe down the countertops.',
			'instructions' => 'Spray and wipe.',
			'category' => 'cleaning',
			'energyCost' => 1,
			'priority' => 3,
			'roomTypes' => ['kitchen'],
			'levels' => [
				'putzteufel' => ['frequencyType' => 'daily', 'frequencyValue' => 1, 'estimatedMinutes' => 10],
				'putzmuffel' => null,
			],
			'tags' => ['daily', 'hygiene'],
		],
		[
			'id' => 'shared_dust',
			'name' => 'Dust surfaces',
			'description' => 'Dust the surfaces.',
			'instructions' => 'Work top to bottom.',
			'category' => 'cleaning',
			'energyCost' => 2,
			'priority' => 2,
			'roomTypes' => ['kitchen', 'living', 'generic'],
			'levels' => [
				'putzteufel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15],
				'putzmuffel' => ['frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 12],
			],
			'tags' => ['weekly', 'dust'],
		],
		[
			'id' => 'bathroom_toilet',
			'name' => 'Clean toilet',
			'description' => 'Scrub the bowl.',
			'instructions' => 'Scrub and wipe.',
			'category' => 'cleaning',
			'energyCost' => 3,
			'priority' => 3,
			'roomTypes' => ['bathroom'],
			'levels' => [
				'putzteufel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15],
				'putzmuffel' => ['frequencyType' => 'weekly', 'frequencyValue' => 2, 'estimatedMinutes' => 12],
			],
			'tags' => ['weekly', 'hygiene'],
		],
		[
			'id' => 'household_trash',
			'name' => 'Take out all trash',
			'description' => 'Empty the bins.',
			'instructions' => 'Empty and reline.',
			'category' => 'cleaning',
			'energyCost' => 2,
			'priority' => 3,
			'roomTypes' => ['household'],
			'levels' => [
				'putzteufel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15],
				'putzmuffel' => ['frequencyType' => 'weekly', 'frequencyValue' => 1, 'estimatedMinutes' => 15],
			],
			'tags' => ['weekly', 'trash'],
		],
	];

	public function testGetCatalog_ReturnsLevelsRoomTypesAndTemplates(): void {
		$catalog = $this->service->getCatalog();

		$this->assertArrayHasKey('levels', $catalog);
		$this->assertArrayHasKey('roomTypes', $catalog);
		$this->assertArrayHasKey('templates', $catalog);

		$this->assertCount(2, $catalog['levels']);
		$this->assertSame(['putzteufel', 'putzmuffel'], array_column($catalog['levels'], 'id'));
		$this->assertCount(4, $catalog['templates']);
	}

	public function testGetRoomTypes_AreLocalizedAndCarryTemplateIds(): void {
		$roomTypes = $this->service->getRoomTypes();

		$this->assertCount(3, $roomTypes);
		$kitchen = $roomTypes[0];
		$this->assertSame('kitchen', $kitchen['id']);
		$this->assertSame('Kitchen', $kitchen['defaultName']);
		$this->assertSame('Utensils', $kitchen['icon']);
		$this->assertSame(['kitchen_counters', 'shared_dust'], $kitchen['templateIds']);
	}

	public function testIsValidRoomType(): void {
		$this->assertTrue($this->service->isValidRoomType('kitchen'));
		$this->assertFalse($this->service->isValidRoomType('dungeon'));
	}

	public function testGetTemplatesForRoomType_IsMultiValued(): void {
		// shared_dust lists both kitchen and living — both must match.
		$kitchen = $this->service->getTemplatesForRoomType('kitchen');
		$ids = array_column($kitchen, 'id');

		$this->assertContains('kitchen_counters', $ids);
		$this->assertContains('shared_dust', $ids);
		$this->assertNotContains('household_trash', $ids);
	}

	public function testGetTemplatesForRoomType_LivingMatchesSharedTemplate(): void {
		$living = $this->service->getTemplatesForRoomType('living');

		$this->assertSame(['shared_dust'], array_column($living, 'id'));
	}

	public function testGetTemplatesForRoomType_UnknownType_ReturnsEmpty(): void {
		$this->assertSame([], $this->service->getTemplatesForRoomType('dungeon'));
	}

	public function testSearchTemplates_MatchesName(): void {
		$result = $this->service->searchTemplates('toilet');

		$this->assertSame(['bathroom_toilet'], array_column($result, 'id'));
	}

	public function testSearchTemplates_MatchesTag(): void {
		$result = $this->service->searchTemplates('trash');

		$this->assertSame(['household_trash'], array_column($result, 'id'));
	}

	public function testSearchTemplates_EmptyQuery_ReturnsAll(): void {
		$this->assertCount(4, $this->service->searchTemplates(''));
	}

	public function testSearchTemplates_NoMatch_ReturnsEmpty(): void {
		$this->assertSame([], $this->service->searchTemplates('zzzz'));
	}

	public function testMaterializeTemplate_Putzteufel_UsesStandardFrequency(): void {
		$payload = $this->service->materializeTemplate('kitchen_counters', TemplateService::LEVEL_PUTZTEUFEL);

		$this->assertSame('Wipe kitchen counters', $payload['name']);
		$this->assertSame('daily', $payload['frequencyType']);
		$this->assertSame(1, $payload['frequencyValue']);
		$this->assertSame(10, $payload['estimatedMinutes']);
		$this->assertSame(1, $payload['energyCost']);
		$this->assertSame('room', $payload['scopeType']);
		$this->assertSame('dynamic', $payload['schedulingMode']);
	}

	public function testMaterializeTemplate_Putmuffel_UsesRelaxedFrequency(): void {
		$payload = $this->service->materializeTemplate('shared_dust', TemplateService::LEVEL_PUTZMUFFEL);

		// Putzmuffel roughly halves the frequency and shortens the time.
		$this->assertSame('weekly', $payload['frequencyType']);
		$this->assertSame(2, $payload['frequencyValue']);
		$this->assertSame(12, $payload['estimatedMinutes']);
	}

	public function testMaterializeTemplate_LevelExclusiveNull_Throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not available for level');

		// kitchen_counters has putzmuffel === null.
		$this->service->materializeTemplate('kitchen_counters', TemplateService::LEVEL_PUTZMUFFEL);
	}

	public function testMaterializeTemplate_UnknownTemplate_Throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Template not found');

		$this->service->materializeTemplate('nope', TemplateService::LEVEL_PUTZTEUFEL);
	}

	public function testMaterializeTemplate_InvalidLevel_Throws(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid cleaning level');

		$this->service->materializeTemplate('shared_dust', 'gammypuss');
	}

	public function testMaterializeTemplate_OverridesApplied(): void {
		$payload = $this->service->materializeTemplate(
			'shared_dust',
			TemplateService::LEVEL_PUTZTEUFEL,
			[
				'name' => 'My dust task',
				'frequencyValue' => 3,
				'estimatedMinutes' => 20,
				'energyCost' => 4,
			]
		);

		$this->assertSame('My dust task', $payload['name']);
		$this->assertSame(3, $payload['frequencyValue']);
		$this->assertSame(20, $payload['estimatedMinutes']);
		$this->assertSame(4, $payload['energyCost']);
		// Non-overridden fields keep their catalog/level values.
		$this->assertSame('weekly', $payload['frequencyType']);
	}

	public function testMaterializeTemplate_OverridesIgnoreNull(): void {
		$payload = $this->service->materializeTemplate(
			'shared_dust',
			TemplateService::LEVEL_PUTZTEUFEL,
			['name' => null]
		);

		$this->assertSame('Dust surfaces', $payload['name']);
	}

	public function testLocalizedTemplate_NormalizesLevels(): void {
		$catalog = $this->service->getCatalog();
		$kitchen = null;
		foreach ($catalog['templates'] as $template) {
			if ($template['id'] === 'kitchen_counters') {
				$kitchen = $template;
				break;
			}
		}

		$this->assertNotNull($kitchen);
		$this->assertNull($kitchen['levels']['putzmuffel']);
		$this->assertSame('daily', $kitchen['levels']['putzteufel']['frequencyType']);
	}
}
