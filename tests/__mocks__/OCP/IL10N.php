<?php

namespace OCP;

/**
 * Mock interface for Nextcloud localization service.
 */
interface IL10N
{
    /**
     * Translate a string.
     *
     * @param string $text Source text key.
     * @param array<string, scalar> $parameters Placeholder replacements.
     * @param int|null $count Optional plural count.
     */
    public function t($text, array $parameters = [], ?int $count = null);
}
