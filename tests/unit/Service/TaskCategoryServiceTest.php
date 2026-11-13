<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\TaskCategoryService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-090 WP-I (FEAT-UX-053, D-090-65): the household's task categories
 * in typed app config, saved by an owner against a revision.
 */
class TaskCategoryServiceTest extends TestCase {
	private ?array $stored = null;
	private IAppConfig&MockObject $config;
	private AuthorizationService&MockObject $auth;
	private HouseMapper&MockObject $houseMapper;
	private AppLogger&MockObject $logger;
	private TaskCategoryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueArray')->willReturnCallback(fn (string $app, string $key, array $default): array => $this->stored ?? $default);
		$this->config->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
			$this->stored = $value;
			return true;
		});
		$owner = new HouseUser();
		$owner->setId(7);
		$this->auth = $this->createMock(AuthorizationService::class);
		$this->auth->method('requireHouseOwner')->willReturn($owner);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$this->logger = $this->createMock(AppLogger::class);

		$this->service = new TaskCategoryService($this->config, $this->auth, $this->houseMapper,
			new TransactionExecutor($this->createMock(IDBConnection::class)), $l10n, $this->logger);
	}

	public function testDefaults_TheSixKeysOfTheFormWithTranslatedNames(): void {
		$this->assertSame([
			'revision' => 0,
			'items' => array_map(static fn (string $key): array => ['key' => $key, 'label' => null], TaskCategoryService::DEFAULT_KEYS),
		], $this->service->getList());
		$this->assertTrue($this->service->isKnown('maintenance'));
		$this->assertFalse($this->service->isKnown('garden'));
		$this->assertSame('kitchen', $this->service->keyOrOther('kitchen'));
		$this->assertSame('other', $this->service->keyOrOther('garden'));
	}

	public function testSave_AddsRenamesReordersAndDeletesUnderTheHouseLock(): void {
		$this->houseMapper->expects($this->once())->method('findForUpdate')->with(3);
		$this->config->expects($this->once())->method('clearCache')->with(true);
		$this->logger->expects($this->once())->method('audit')
			->with('Task categories saved', $this->callback(static fn (array $context): bool
				=> $context['event'] === 'task-categories.saved' && $context['actorId'] === 7 && $context['count'] === 4));

		$saved = $this->service->save(3, 0, [
			['key' => 'kitchen', 'label' => '  Kitchen & dining  '],
			['label' => 'Garden'],
			['key' => 'cleaning', 'label' => null],
			['key' => 'other'],
		]);

		$this->assertSame(1, $saved['revision']);
		$this->assertSame($saved, $this->stored);
		$this->assertSame(['key' => 'kitchen', 'label' => 'Kitchen & dining'], $saved['items'][0]);
		$this->assertMatchesRegularExpression('/^c[0-9a-f]{8}$/', $saved['items'][1]['key']);
		$this->assertSame('Garden', $saved['items'][1]['label']);
		$this->assertSame(['cleaning', 'other'], array_column(array_slice($saved['items'], 2), 'key'));
		$this->assertFalse($this->service->isKnown('bathroom'), 'a deleted key is no longer writable');
		$this->assertTrue($this->service->isKnown($saved['items'][1]['key']));
	}

	public function testSave_ACustomKeyStaysStableAndKeepsNeedingAName(): void {
		$garden = $this->service->save(3, 0, [['label' => 'Garden'], ['key' => 'other']])['items'][0]['key'];

		$renamed = $this->service->save(3, 1, [['key' => $garden, 'label' => 'Yard'], ['key' => 'other']]);
		$this->assertSame([$garden, 'Yard'], [$renamed['items'][0]['key'], $renamed['items'][0]['label']]);
		$this->assertSame(2, $renamed['revision']);

		$this->expectException(ValidationException::class);
		$this->service->save(3, 2, [['key' => $garden, 'label' => null], ['key' => 'other']]);
	}

	public function testSave_TwentyCategoriesAreAllowed(): void {
		$items = array_map(static fn (int $i): array => ['label' => "Category $i"], range(1, 19));
		$items[] = ['key' => 'other'];

		$this->assertCount(20, $this->service->save(3, 0, $items)['items']);
	}

	/**
	 * @dataProvider brokenListProvider
	 */
	public function testSave_RefusesABrokenListAndWritesNothing(array $items): void {
		try {
			$this->service->save(3, 0, $items);
			$this->fail('the list must be refused');
		} catch (ValidationException $e) {
			$this->assertNotSame('', $e->getMessage());
		}
		$this->assertNull($this->stored);
	}

	public static function brokenListProvider(): array {
		$other = ['key' => 'other'];
		return [
			'other missing' => [[['key' => 'cleaning']]],
			'other not last' => [[$other, ['key' => 'cleaning']]],
			'other renamed' => [[['key' => 'cleaning'], ['key' => 'other', 'label' => 'Misc']]],
			'a key that is not listed' => [[['key' => 'garden'], $other]],
			'a key twice' => [[['key' => 'cleaning'], ['key' => 'cleaning'], $other]],
			'a new category without a name' => [[['key' => null], $other]],
			'a blank name' => [[['label' => '   '], $other]],
			'a name of 41 characters' => [[['label' => str_repeat('a', 41)], $other]],
			'a name that is not a string' => [[['label' => 5], $other]],
			'two names that differ only in case' => [[['label' => 'Garden'], ['label' => 'GARDEN'], $other]],
			'an item that is not an object' => [['cleaning', $other]],
			'an empty list' => [[]],
			'21 categories' => [[...array_map(static fn (int $i): array => ['label' => "Category $i"], range(1, 20)), $other]],
		];
	}

	/**
	 * RF-090-35: an oversized list is refused by its size, before any item
	 * is read, so its cost does not grow with the payload.
	 */
	public function testSave_AnOversizedListIsRefusedBeforeItsItemsAreRead(): void {
		try {
			$this->service->save(3, 0, array_fill(0, 10000, 'not an item'));
			$this->fail('the list must be refused');
		} catch (ValidationException $e) {
			$this->assertSame('A household can have at most 20 categories.', $e->getMessage());
		}
		$this->assertNull($this->stored);
	}

	public function testSave_AStaleRevisionConflictsAndWritesNothing(): void {
		$this->stored = ['revision' => 4, 'items' => [['key' => 'other', 'label' => null]]];

		$this->expectException(ConflictException::class);
		try {
			$this->service->save(3, 3, [['key' => 'other']]);
		} finally {
			$this->assertSame(4, $this->stored['revision']);
		}
	}

	public function testSave_ANonOwnerIsRefusedBeforeTheLock(): void {
		$auth = $this->createMock(AuthorizationService::class);
		$auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner only'));
		$this->houseMapper->expects($this->never())->method('findForUpdate');
		$l10n = $this->createMock(IL10N::class);
		$service = new TaskCategoryService($this->config, $auth, $this->houseMapper,
			new TransactionExecutor($this->createMock(IDBConnection::class)), $l10n, $this->logger);

		$this->expectException(ForbiddenException::class);
		$service->save(3, 0, [['key' => 'other']]);
	}

	public function testResetToDefaults_DropsTheStoredList(): void {
		$this->config->expects($this->once())->method('deleteKey')->with('chorestrike', 'task_categories');

		$this->service->resetToDefaults();
	}
}
