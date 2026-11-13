<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\TaskCategoryController;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\ValidationException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskCategoryService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-090 WP-I (D-090-65): the owner-only writer of the household's
 * task categories validates its envelope and maps each service outcome.
 */
class TaskCategoryControllerTest extends TestCase {
	private AuthorizationService&MockObject $auth;
	private TaskCategoryService&MockObject $categories;

	protected function setUp(): void {
		parent::setUp();
		$this->auth = $this->createMock(AuthorizationService::class);
		$this->categories = $this->createMock(TaskCategoryService::class);
	}

	private function controller(array $params): TaskCategoryController {
		$house = new House();
		$house->setId(3);
		$houseService = $this->createMock(HouseService::class);
		$houseService->method('getInstanceId')->willReturn('instance');
		$houseService->method('getOrCreateHouse')->willReturn($house);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TaskCategoryController($request, $houseService, $this->auth, $this->categories, $l10n, $this->createMock(AppLogger::class));
	}

	public function testUpdate_SavesForAnOwnerAndReturnsTheList(): void {
		$items = [['label' => 'Garden'], ['key' => 'other']];
		$saved = ['revision' => 1, 'items' => [['key' => 'c0a1b2c3d', 'label' => 'Garden'], ['key' => 'other', 'label' => null]]];
		$this->auth->expects($this->once())->method('assertHouseMember')->with(3);
		$this->auth->expects($this->once())->method('requireHouseOwner')->with(3);
		$this->categories->expects($this->once())->method('save')->with(3, 0, $items)->willReturn($saved);

		$response = $this->controller(['revision' => 0, 'items' => $items])->update();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($saved, $response->getData()['data']);
	}

	/**
	 * @dataProvider malformedEnvelopes
	 */
	public function testUpdate_RefusesAMalformedEnvelopeWithoutAWrite(array $params): void {
		$this->categories->expects($this->never())->method('save');

		$response = $this->controller($params)->update();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('VALIDATION_ERROR', $response->getData()['error']['code']);
	}

	public static function malformedEnvelopes(): array {
		return [
			'no revision' => [['items' => [['key' => 'other']]]],
			'a string revision' => [['revision' => '0', 'items' => [['key' => 'other']]]],
			'items as an object' => [['revision' => 0, 'items' => ['a' => ['key' => 'other']]]],
			'no items' => [['revision' => 0]],
		];
	}

	public function testUpdate_ANonOwnerGets403BeforeTheEnvelopeIsRead(): void {
		$this->auth->method('requireHouseOwner')->willThrowException(new ForbiddenException('Owner role required'));
		$this->categories->expects($this->never())->method('save');

		$response = $this->controller(['revision' => 'junk'])->update();

		$this->assertSame(403, $response->getStatus());
	}

	public function testUpdate_MapsAStaleRevisionTo409AndABrokenListTo400(): void {
		$this->categories->method('save')->willReturnOnConsecutiveCalls(
			$this->throwException(new ConflictException('changed')),
			$this->throwException(new ValidationException('"Other" stays the last category and keeps its name.')),
		);
		$params = ['revision' => 0, 'items' => [['key' => 'other']]];

		$this->assertSame(409, $this->controller($params)->update()->getStatus());
		$refused = $this->controller($params)->update();
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('"Other" stays the last category and keeps its name.', $refused->getData()['error']['message']);
	}
}
