<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCP\IL10N;

/**
 * Service for managing task templates from JSON database
 */
class TemplateService
{
    private TaskMapper $taskMapper;
    private IL10N $l10n;
    private ?array $templates = null;

    public function __construct(TaskMapper $taskMapper, IL10N $l10n)
    {
        $this->taskMapper = $taskMapper;
        $this->l10n = $l10n;
    }

    /**
     * Load templates from JSON file
     */
    private function loadTemplates(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $templateFile = __DIR__ . '/../Templates/TaskTemplates.json';

        if (!file_exists($templateFile)) {
            return [];
        }

        $content = file_get_contents($templateFile);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        $this->templates = $data['templates'] ?? [];
        return $this->templates;
    }

    /**
     * Get all available templates
     *
     * @return array
     */
    public function getAllTemplates(): array
    {
        return $this->loadTemplates();
    }

    /**
     * Get template by ID
     *
     * @param string $templateId
     * @return array|null
     */
    public function getTemplate(string $templateId): ?array
    {
        $templates = $this->loadTemplates();

        foreach ($templates as $template) {
            if ($template['id'] === $templateId) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Get templates filtered by category
     *
     * @param string $category
     * @return array
     */
    public function getTemplatesByCategory(string $category): array
    {
        $templates = $this->loadTemplates();

        return array_filter($templates, fn ($t) => $t['category'] === $category);
    }

    /**
     * Get templates filtered by scope label (room type)
     *
     * @param string $scopeLabel
     * @return array
     */
    public function getTemplatesByRoomType(string $scopeLabel): array
    {
        $templates = $this->loadTemplates();

        return array_filter(
            $templates,
            fn ($t) =>
            $t['scopeLabel'] === $scopeLabel || $t['scopeType'] === 'house'
        );
    }

    /**
     * Search templates by name or description
     *
     * @param string $query
     * @return array
     */
    public function searchTemplates(string $query): array
    {
        $templates = $this->loadTemplates();
        $lowerQuery = strtolower($query);

        return array_filter(
            $templates,
            fn ($t) =>
            stripos(strtolower($t['name']), $lowerQuery) !== false ||
            stripos(strtolower($t['description'] ?? ''), $lowerQuery) !== false ||
            in_array($lowerQuery, array_map('strtolower', $t['tags'] ?? []))
        );
    }

    /**
     * Get all available categories
     *
     * @return array
     */
    public function getCategories(): array
    {
        $templates = $this->loadTemplates();
        $categories = array_unique(array_column($templates, 'category'));
        sort($categories);
        return $categories;
    }

    /**
     * Get all available room types (scope labels)
     *
     * @return array
     */
    public function getRoomTypes(): array
    {
        $templates = $this->loadTemplates();
        $roomTypes = array_filter(array_unique(array_column($templates, 'scopeLabel')));
        sort($roomTypes);
        return $roomTypes;
    }

    /**
     * Get templates with their localized names
     *
     * @return array
     */
    public function getLocalizedTemplates(): array
    {
        $templates = $this->loadTemplates();

        return array_map(fn ($t) => [
            ...$t,
            'name' => $this->l10n->t($t['name']),
            'description' => $this->l10n->t($t['description']),
            'instructions' => $this->l10n->t($t['instructions'] ?? ''),
        ], $templates);
    }

    /**
     * Import template as a new task
     *
     * @param string $templateId
     * @param bool $asTemplate Whether to create as template or regular task
     * @return Task
     * @throws \InvalidArgumentException if template not found
     */
    public function importTemplate(string $templateId, bool $asTemplate = false): Task
    {
        $template = $this->getTemplate($templateId);

        if ($template === null) {
            throw new \InvalidArgumentException('Template not found: ' . $templateId);
        }

        // Parse fixed schedule time if present
        $fixedScheduleTime = null;
        if (!empty($template['fixedScheduleTime'])) {
            $fixedScheduleTime = new \DateTime($template['fixedScheduleTime']);
        }

        $priority = match (strtolower((string)($template['priority'] ?? 'medium'))) {
            'low' => 1,
            'high' => 3,
            default => 2,
        };

        return $this->taskMapper->create(
            $template['name'],
            $template['description'] ?? null,
            $template['energyCost'],
            $priority,
            $template['frequencyType'],
            $template['frequencyValue'],
            $template['estimatedMinutes'],
            $template['category'],
            $template['scopeType'] ?? 'room',
            $template['scopeLabel'] ?? null,
            'recurring',
            null, // activeFrom
            null, // activeUntil
            false, // isVoluntary
            $asTemplate,
            $template['schedulingMode'] ?? 'dynamic',
            $fixedScheduleTime,
            isset($template['fixedScheduleDays']) ? json_encode($template['fixedScheduleDays']) : null
        );
    }

    /**
     * Get suggested templates for a room based on room name
     *
     * @param string $roomName
     * @return array
     */
    public function getSuggestionsForRoom(string $roomName): array
    {
        $templates = $this->loadTemplates();
        $roomNameLower = strtolower($roomName);

        // Map common room names to categories
        $roomMappings = [
            'kitchen' => ['kitchen', 'cooking'],
            'bad' => ['bathroom', 'toilet', 'wc'],
            'bathroom' => ['bathroom', 'toilet', 'wc'],
            'schlafzimmer' => ['bedroom', 'sleeping'],
            'bedroom' => ['bedroom', 'sleeping'],
            'wohnzimmer' => ['living', 'lounge'],
            'living' => ['living', 'lounge'],
            'eingang' => ['entrance', 'hallway'],
            'entrance' => ['entrance', 'hallway'],
            'flur' => ['entrance', 'hallway'],
            'hallway' => ['entrance', 'hallway'],
        ];

        // Find matching category
        $matchingCategories = [];
        foreach ($roomMappings as $key => $categories) {
            if (strpos($roomNameLower, $key) !== false) {
                $matchingCategories = array_merge($matchingCategories, $categories);
            }
        }

        // Return templates matching those categories
        if (empty($matchingCategories)) {
            return [];
        }

        return array_filter(
            $templates,
            fn ($t) =>
            in_array(strtolower($t['category']), $matchingCategories) ||
            in_array(strtolower($t['scopeLabel'] ?? ''), $matchingCategories)
        );
    }

    /**
     * Bulk import multiple templates
     *
     * @param array $templateIds
     * @param bool $asTemplate
     * @return array Created tasks
     */
    public function bulkImport(array $templateIds, bool $asTemplate = false): array
    {
        $created = [];

        foreach ($templateIds as $id) {
            try {
                $created[] = $this->importTemplate($id, $asTemplate);
            } catch (\Exception $e) {
                // Log error and continue
                continue;
            }
        }

        return $created;
    }

    /**
     * Get template statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $templates = $this->loadTemplates();

        $byCategory = [];
        foreach ($templates as $t) {
            $cat = $t['category'];
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = 0;
            }
            $byCategory[$cat]++;
        }

        return [
            'total' => count($templates),
            'byCategory' => $byCategory,
            'bySchedulingMode' => [
                'dynamic' => count(array_filter($templates, fn ($t) => ($t['schedulingMode'] ?? 'dynamic') === 'dynamic')),
                'static' => count(array_filter($templates, fn ($t) => ($t['schedulingMode'] ?? 'dynamic') === 'static')),
            ],
            'categories' => array_keys($byCategory),
        ];
    }
}
