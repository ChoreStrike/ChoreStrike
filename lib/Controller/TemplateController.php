<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Controller;

use OCA\ChoreStrike\Service\AuthorizationService;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Service\TemplateService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * API controller for task template endpoints
 */
class TemplateController extends OCSController {

	private TemplateService $templateService;
	private HouseService $houseService;
	private AuthorizationService $authService;

	public function __construct(
		IRequest $request,
		TemplateService $templateService,
		HouseService $houseService,
		AuthorizationService $authService
	) {
		parent::__construct('chorestrike', $request);
		$this->templateService = $templateService;
		$this->houseService = $houseService;
		$this->authService = $authService;
	}

	/**
	 * Get all available templates
	 *
	 * @NoAdminRequired
	 */
	public function index(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$templates = $this->templateService->getAllTemplates();

			return new JSONResponse([
				'success' => true,
				'data' => $templates
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TEMPLATES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get template categories
	 *
	 * @NoAdminRequired
	 */
	public function categories(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$categories = $this->templateService->getCategories();

			return new JSONResponse([
				'success' => true,
				'data' => $categories
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CATEGORIES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get templates by category
	 *
	 * @NoAdminRequired
	 */
	public function byCategory(string $category): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$templates = $this->templateService->getTemplatesByCategory($category);

			return new JSONResponse([
				'success' => true,
				'data' => array_values($templates)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'CATEGORY_TEMPLATES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get templates by room type
	 *
	 * @NoAdminRequired
	 */
	public function byRoomType(string $roomType): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$templates = $this->templateService->getTemplatesByRoomType($roomType);

			return new JSONResponse([
				'success' => true,
				'data' => array_values($templates)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'ROOM_TYPE_TEMPLATES_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Search templates
	 *
	 * @NoAdminRequired
	 */
	public function search(string $query): JSONResponse {
		try {
			if (empty(trim($query))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Search query is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$templates = $this->templateService->searchTemplates($query);

			return new JSONResponse([
				'success' => true,
				'data' => array_values($templates)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SEARCH_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get template suggestions for a room
	 *
	 * @NoAdminRequired
	 */
	public function suggestions(string $roomName): JSONResponse {
		try {
			if (empty(trim($roomName))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Room name is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$templates = $this->templateService->getSuggestionsForRoom($roomName);

			return new JSONResponse([
				'success' => true,
				'data' => array_values($templates)
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'SUGGESTIONS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Import template as a new task
	 *
	 * @NoAdminRequired
	 */
	public function import(string $templateId): JSONResponse {
		try {
			if (empty(trim($templateId))) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Template ID is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$task = $this->templateService->importTemplate($templateId);

			return new JSONResponse([
				'success' => true,
				'data' => $task->jsonSerialize(),
				'message' => 'Template imported successfully'
			]);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'TEMPLATE_NOT_FOUND',
					'message' => $e->getMessage()
				]
			], 404);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'IMPORT_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Bulk import templates
	 *
	 * @NoAdminRequired
	 */
	public function bulkImport(): JSONResponse {
		try {
			$templateIds = $this->request->getParam('templateIds', []);
			
			if (empty($templateIds) || !is_array($templateIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => [
						'code' => 'VALIDATION_ERROR',
						'message' => 'Template IDs array is required'
					]
				], 400);
			}

			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$tasks = $this->templateService->bulkImport($templateIds);

			return new JSONResponse([
				'success' => true,
				'data' => array_map(fn($t) => $t->jsonSerialize(), $tasks),
				'message' => count($tasks) . ' templates imported successfully'
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'BULK_IMPORT_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}

	/**
	 * Get template statistics
	 *
	 * @NoAdminRequired
	 */
	public function statistics(): JSONResponse {
		try {
			$instanceId = $this->houseService->getInstanceId();
			$house = $this->houseService->getOrCreateHouse($instanceId);
			$this->authService->requireHouseMember($house->getId());

			$stats = $this->templateService->getStatistics();

			return new JSONResponse([
				'success' => true,
				'data' => $stats
			]);
		} catch (\Exception $e) {
			return new JSONResponse([
				'success' => false,
				'error' => [
					'code' => 'STATISTICS_ERROR',
					'message' => $e->getMessage()
				]
			], 500);
		}
	}
}
