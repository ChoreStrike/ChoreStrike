<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Service\ArcadeStartPointStore;
use OCP\Config\IUserConfig;
use OCP\Config\ValueType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CS-PLAN-090 WP-J (D-090-64/66): the arcade start point is a lazy per-user
 * setting, read without cache and deleted with the account.
 */
class ArcadeStartPointStoreTest extends TestCase {
	private IUserConfig&MockObject $userConfig;
	private ArcadeStartPointStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->store = new ArcadeStartPointStore($this->userConfig);
	}

	/**
	 * ForUsers_ReadsPastTheCache_AndDropsBrokenValues: one uncached read for
	 * every member; a value of the wrong shape counts as no reset.
	 */
	public function testForUsers_ReadsPastTheCache_AndDropsBrokenValues(): void {
		$this->userConfig->expects($this->once())->method('getValuesByUsers')
			->with('chorestrike', 'arcade_reset', ValueType::ARRAY, ['kid', '1001', 'odd', 'gone'])
			->willReturn([
				'kid' => ['after' => 42, 'at' => 1700000000],
				1001 => ['after' => 0, 'at' => 1700000001],
				'odd' => ['after' => '42', 'at' => 1700000000],
				'gone' => 'not an array',
			]);

		$this->assertSame([
			'kid' => ['after' => 42, 'at' => 1700000000],
			'1001' => ['after' => 0, 'at' => 1700000001],
		], $this->store->forUsers(['kid', '1001', 'kid', '', 'odd', 'gone']));
	}

	public function testForUsers_NoMembers_NoQuery(): void {
		$this->userConfig->expects($this->never())->method('getValuesByUsers');

		$this->assertSame([], $this->store->forUsers([]));
	}

	public function testSet_WritesALazyArray(): void {
		$this->userConfig->expects($this->once())->method('setValueArray')
			->with('kid', 'chorestrike', 'arcade_reset', ['after' => 42, 'at' => 1700000000], true);

		$this->store->set('kid', 42, 1700000000);
	}

	public function testForget_DeletesOneMembersValue(): void {
		$this->userConfig->expects($this->once())->method('deleteUserConfig')->with('kid', 'chorestrike', 'arcade_reset');

		$this->store->forget('kid');
	}

	public function testForgetAll_DeletesTheKeyForEveryone(): void {
		$this->userConfig->expects($this->once())->method('deleteKey')->with('chorestrike', 'arcade_reset');

		$this->store->forgetAll();
	}
}
