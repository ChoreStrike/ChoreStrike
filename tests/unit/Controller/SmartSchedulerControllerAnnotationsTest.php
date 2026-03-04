<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Controller;

use OCA\ChoreStrike\Controller\SmartSchedulerController;
use PHPUnit\Framework\TestCase;

/**
 * Security annotation regression tests for SmartSchedulerController.
 */
class SmartSchedulerControllerAnnotationsTest extends TestCase
{
    /**
     * Ensures all SmartSchedulerController endpoints require authenticated users.
     */
    public function testAllPublicEndpointsUseNoAdminRequiredAnnotation(): void
    {
        $endpointMethods = [
            'getSchedulePreview',
            'generateSchedule',
            'getMemberAvailability',
            'updateMyAvailability',
            'updateMyEnergyBudget',
            'getSchedulingSuggestions',
            'applySuggestion',
        ];

        $reflection = new \ReflectionClass(SmartSchedulerController::class);

        foreach ($endpointMethods as $methodName) {
            $method = $reflection->getMethod($methodName);
            $docComment = (string) $method->getDocComment();

            $this->assertStringContainsString('@NoAdminRequired', $docComment, "Method {$methodName} must require authentication");
            $this->assertStringNotContainsString('@PublicPage', $docComment, "Method {$methodName} must not be public");
        }
    }
}
