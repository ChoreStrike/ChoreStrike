<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TemplateService
 */
class TemplateServiceTest extends TestCase
{
    private TemplateService $service;
    private TaskMapper $taskMapper;
    private IL10N $l10n;

    private const FIXTURE_TEMPLATES = [
        [
            'id' => 'tpl-kitchen-01',
            'name' => 'Wipe Counters',
            'description' => 'Clean kitchen counters',
            'category' => 'kitchen',
            'scopeType' => 'room',
            'scopeLabel' => 'Kitchen',
            'energyCost' => 5,
            'priority' => 'medium',
            'frequencyType' => 'daily',
            'frequencyValue' => 1,
            'estimatedMinutes' => 10,
            'schedulingMode' => 'dynamic',
            'tags' => ['cleaning', 'kitchen'],
        ],
        [
            'id' => 'tpl-bath-01',
            'name' => 'Scrub Toilet',
            'description' => 'Clean the toilet',
            'category' => 'bathroom',
            'scopeType' => 'room',
            'scopeLabel' => 'Bathroom',
            'energyCost' => 8,
            'priority' => 'high',
            'frequencyType' => 'weekly',
            'frequencyValue' => 1,
            'estimatedMinutes' => 15,
            'schedulingMode' => 'static',
            'fixedScheduleTime' => '09:00',
            'fixedScheduleDays' => ['saturday'],
            'tags' => ['cleaning', 'bathroom'],
        ],
        [
            'id' => 'tpl-general-01',
            'name' => 'Vacuum',
            'description' => 'Vacuum all rooms',
            'category' => 'general',
            'scopeType' => 'house',
            'scopeLabel' => '',
            'energyCost' => 12,
            'priority' => 'medium',
            'frequencyType' => 'weekly',
            'frequencyValue' => 2,
            'estimatedMinutes' => 30,
            'schedulingMode' => 'dynamic',
            'tags' => ['cleaning'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->taskMapper = $this->createMock(TaskMapper::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(fn ($text) => $text);

        $this->service = new TemplateService($this->taskMapper, $this->l10n);

        $this->injectTemplates(self::FIXTURE_TEMPLATES);
    }

    private function injectTemplates(array $templates): void
    {
        $ref = new \ReflectionProperty(TemplateService::class, 'templates');
        $ref->setAccessible(true);
        $ref->setValue($this->service, $templates);
    }

    public function testGetAllTemplates_ReturnsInjectedTemplates(): void
    {
        $result = $this->service->getAllTemplates();

        $this->assertCount(3, $result);
        $this->assertEquals('tpl-kitchen-01', $result[0]['id']);
    }

    public function testGetTemplate_ExistingId_ReturnsTemplate(): void
    {
        $result = $this->service->getTemplate('tpl-bath-01');

        $this->assertNotNull($result);
        $this->assertEquals('Scrub Toilet', $result['name']);
    }

    public function testGetTemplate_UnknownId_ReturnsNull(): void
    {
        $this->assertNull($this->service->getTemplate('nonexistent'));
    }

    public function testGetTemplatesByCategory_FiltersCorrectly(): void
    {
        $result = $this->service->getTemplatesByCategory('kitchen');

        $this->assertCount(1, $result);
        $this->assertEquals('tpl-kitchen-01', array_values($result)[0]['id']);
    }

    public function testGetTemplatesByCategory_NoMatch_ReturnsEmpty(): void
    {
        $this->assertEmpty($this->service->getTemplatesByCategory('garage'));
    }

    public function testGetTemplatesByRoomType_MatchesScopeLabel(): void
    {
        $result = $this->service->getTemplatesByRoomType('Kitchen');

        $this->assertGreaterThanOrEqual(1, count($result));
        $ids = array_column(array_values($result), 'id');
        $this->assertContains('tpl-kitchen-01', $ids);
    }

    public function testGetTemplatesByRoomType_IncludesHouseScope(): void
    {
        $result = $this->service->getTemplatesByRoomType('Kitchen');

        $ids = array_column(array_values($result), 'id');
        $this->assertContains('tpl-general-01', $ids);
    }

    public function testSearchTemplates_MatchesName(): void
    {
        $result = $this->service->searchTemplates('vacuum');

        $this->assertCount(1, $result);
        $this->assertEquals('tpl-general-01', array_values($result)[0]['id']);
    }

    public function testSearchTemplates_MatchesTag(): void
    {
        $result = $this->service->searchTemplates('bathroom');

        $this->assertCount(1, $result);
    }

    public function testSearchTemplates_NoMatch_ReturnsEmpty(): void
    {
        $this->assertEmpty($this->service->searchTemplates('zzzznotfound'));
    }

    public function testGetCategories_ReturnsSortedUnique(): void
    {
        $result = $this->service->getCategories();

        $this->assertEquals(['bathroom', 'general', 'kitchen'], $result);
    }

    public function testGetRoomTypes_ReturnsSortedUnique(): void
    {
        $result = $this->service->getRoomTypes();

        $this->assertContains('Bathroom', $result);
        $this->assertContains('Kitchen', $result);
    }

    public function testImportTemplate_ValidId_CreatesTask(): void
    {
        $task = new Task();
        $task->setId(42);
        $task->setName('Wipe Counters');

        $this->taskMapper->expects($this->once())
            ->method('create')
            ->with(
                'Wipe Counters',
                'Clean kitchen counters',
                5,
                2,
                'daily',
                1,
                10,
                'kitchen',
                'room',
                'Kitchen',
                'recurring',
                null,
                null,
                false,
                false,
                'dynamic',
                null,
                null
            )
            ->willReturn($task);

        $result = $this->service->importTemplate('tpl-kitchen-01');

        $this->assertSame($task, $result);
    }

    public function testImportTemplate_WithFixedSchedule_ParsesTime(): void
    {
        $task = new Task();
        $task->setId(43);

        $this->taskMapper->expects($this->once())
            ->method('create')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'static',
                $this->callback(fn ($dt) => $dt instanceof \DateTime),
                json_encode(['saturday'])
            )
            ->willReturn($task);

        $this->service->importTemplate('tpl-bath-01');
    }

    public function testImportTemplate_UnknownId_ThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Template not found: nonexistent');

        $this->service->importTemplate('nonexistent');
    }

    public function testBulkImport_MultipleIds_CreatesAll(): void
    {
        $task1 = new Task();
        $task1->setId(1);
        $task2 = new Task();
        $task2->setId(2);

        $this->taskMapper->method('create')
            ->willReturnOnConsecutiveCalls($task1, $task2);

        $result = $this->service->bulkImport(['tpl-kitchen-01', 'tpl-bath-01']);

        $this->assertCount(2, $result);
    }

    public function testBulkImport_SkipsInvalidIds(): void
    {
        $task = new Task();
        $task->setId(1);

        $this->taskMapper->method('create')->willReturn($task);

        $result = $this->service->bulkImport(['tpl-kitchen-01', 'bad-id']);

        $this->assertCount(1, $result);
    }

    public function testGetSuggestionsForRoom_Kitchen_ReturnsKitchenTemplates(): void
    {
        $result = $this->service->getSuggestionsForRoom('Kitchen');

        $this->assertNotEmpty($result);
    }

    public function testGetSuggestionsForRoom_UnknownRoom_ReturnsEmpty(): void
    {
        $this->assertEmpty($this->service->getSuggestionsForRoom('Dungeon'));
    }

    public function testGetStatistics_ReturnsCountsByCategory(): void
    {
        $stats = $this->service->getStatistics();

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['byCategory']['kitchen']);
        $this->assertEquals(1, $stats['byCategory']['bathroom']);
        $this->assertEquals(1, $stats['byCategory']['general']);
        $this->assertEquals(2, $stats['bySchedulingMode']['dynamic']);
        $this->assertEquals(1, $stats['bySchedulingMode']['static']);
    }

    public function testGetLocalizedTemplates_TranslatesFields(): void
    {
        $result = $this->service->getLocalizedTemplates();

        $this->assertCount(3, $result);
        $this->assertEquals('Wipe Counters', $result[0]['name']);
    }

    public function testLoadTemplates_EmptyCache_ReturnsEmptyWhenNoFile(): void
    {
        $service = new TemplateService($this->taskMapper, $this->l10n);

        $ref = new \ReflectionProperty(TemplateService::class, 'templates');
        $ref->setAccessible(true);
        $ref->setValue($service, null);

        $fileRef = new \ReflectionMethod(TemplateService::class, 'loadTemplates');
        $fileRef->setAccessible(true);

        $result = $fileRef->invoke($service);

        $this->assertIsArray($result);
    }
}
