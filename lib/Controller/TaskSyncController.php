<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TaskSyncService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for Nextcloud Task synchronization endpoints
 */
class TaskSyncController extends OCSController {

	private TaskSyncService $taskSyncService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		TaskSyncService $taskSyncService,
		HouseService $houseService,
		AuthorizationService $authService
	) {
		parent::__construct('chorestrike', $request);
		$this->taskSyncService = $taskSyncService;
		$this->houseService = $houseService;
		$this->authService = $authService;
	}

	/**
	 * Complete a task and sync to Nextcloud Tasks
	 *
	 * @NoAdminRequired
	 */
	public function complete(int $roomTaskId, ?int $nextcloudTaskId = null): JSONResponse {
		try {
			$userId = $this->authService->getCurrentUserId();
			if ($userId === null) {
				throw new \Exception('Not authenticated');
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$result = $this->taskSyncService->completeTaskForHouse($roomTaskId, $userId, $house->getId(), $nextcloudTaskId);

			return new JSONResponse([
				'success' => true,
				'data' => $result
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SYNC_COMPLETE_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Sync completion from Nextcloud Task to ChoreStrike
	 *
	 * @NoAdminRequired
	 */
	public function syncFromNextcloud(int $nextcloudTaskId, string $completedAt, ?int $roomTaskId = null): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$result = $this->taskSyncService->syncCompletionFromNextcloudForHouse($nextcloudTaskId, $completedAt, $house->getId(), $roomTaskId);

			if ($result === null) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'TASK_NOT_FOUND',
						'message' => 'Task not found or conflict resolution preferred local'
					]
				], 404);
			}

			return new JSONResponse([
				'success' => true,
				'data' => $result
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SYNC_FROM_NEXTCLOUD_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get reminder mapping for a room task
	 *
	 * @NoAdminRequired
	 */
	public function getReminders(int $roomTaskId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$roomTask = $this->taskSyncService->getRoomTaskForHouse($roomTaskId, $house->getId());
			$reminders = $this->taskSyncService->mapRemindersToNextcloud($roomTask);

			return new JSONResponse([
				'success' => true,
				'data' => $reminders
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'REMINDER_MAPPING_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get pending syncs that need to be pushed to Nextcloud
	 *
	 * @NoAdminRequired
	 */
	public function getPendingSyncs(int $limit = 50): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$pending = $this->taskSyncService->getPendingSyncsForHouse($house->getId(), $limit);

			return new JSONResponse([
				'success' => true,
				'data' => $pending
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'PENDING_SYNCS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Mark completion as synced to Nextcloud
	 *
	 * @NoAdminRequired
	 */
	public function markAsSynced(int $completedTaskId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$this->taskSyncService->markAsSyncedForHouse($completedTaskId, $house->getId());

			return new JSONResponse([
				'success' => true,
				'message' => 'Marked as synced'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'MARK_SYNCED_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Link a room task to a Nextcloud task
	 *
	 * @NoAdminRequired
	 */
	public function linkTask(int $roomTaskId, int $nextcloudTaskId): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$this->taskSyncService->linkTaskForHouse($roomTaskId, $nextcloudTaskId, $house->getId());

			return new JSONResponse([
				'success' => true,
				'message' => 'Tasks linked successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'LINK_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

}
