<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\TaskSyncService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for Nextcloud Task synchronization endpoints
 */
class TaskSyncController extends OCSController {

	private TaskSyncService $taskSyncService;

	public function __construct(
		IRequest $request,
		TaskSyncService $taskSyncService
	) {
		parent::__construct($request);
		$this->taskSyncService = $taskSyncService;
	}

	/**
	 * Complete a task and sync to Nextcloud Tasks
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function complete(int $roomTaskId, ?int $nextcloudTaskId = null): JSONResponse {
		try {
			$userId = $this->getUserId();
			$result = $this->taskSyncService->completeTask($roomTaskId, $userId, $nextcloudTaskId);

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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function syncFromNextcloud(int $nextcloudTaskId, string $completedAt, ?int $roomTaskId = null): JSONResponse {
		try {
			$result = $this->taskSyncService->syncCompletionFromNextcloud($nextcloudTaskId, $completedAt, $roomTaskId);

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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function getReminders(int $roomTaskId): JSONResponse {
		try {
			$roomTask = $this->taskSyncService->getRoomTask($roomTaskId);
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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function getPendingSyncs(int $limit = 50): JSONResponse {
		try {
			$pending = $this->taskSyncService->getPendingSyncs($limit);

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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function markAsSynced(int $completedTaskId): JSONResponse {
		try {
			$this->taskSyncService->markAsSynced($completedTaskId);

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
	 * @PublicPage
	 * @NoAdminRequired
	 */
	public function linkTask(int $roomTaskId, int $nextcloudTaskId): JSONResponse {
		try {
			$this->taskSyncService->linkTask($roomTaskId, $nextcloudTaskId);

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

	/**
	 * Get current user ID from session
	 */
	private function getUserId(): int {
		// This would be implemented based on Nextcloud's user session
		// For now, returning a placeholder
		return 1;
	}
}
