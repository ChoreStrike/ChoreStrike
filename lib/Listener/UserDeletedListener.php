<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Listener;

use OCA\ChoreStrike\Service\AccountErasureService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Nextcloud user-deletion hook (CS-PLAN-071 WP0, Q-070-02): when the
 * platform deletes an account, every ChoreStrike reference is erased
 * ({@see AccountErasureService}) — memberships, assignments, medals,
 * scores, the wallet with its ledger, receipt actors and completion actor
 * references. Registered in `Application::register`; never exposed as an
 * app API.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	private AccountErasureService $erasureService;

	public function __construct(AccountErasureService $erasureService) {
		$this->erasureService = $erasureService;
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$this->erasureService->eraseAccount($event->getUser()->getUID());
	}
}
