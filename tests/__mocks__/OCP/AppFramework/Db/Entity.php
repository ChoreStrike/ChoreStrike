<?php

/**
 * Mock for OCP\AppFramework\Db\Entity for Unit Testing
 * This allows entity tests to run without full Nextcloud infrastructure
 */

namespace OCP\AppFramework\Db;

if (!class_exists('OCP\AppFramework\Db\Entity')) {
    class Entity
    {
        protected $id;
        protected $_fieldTypes = [];
        protected $_data = [];

        public function __construct()
        {
            // Initialize with empty field types
        }

        protected function addType(string $fieldName, string $type): void
        {
            $this->_fieldTypes[$fieldName] = $type;
        }

        public function getId(): ?int
        {
            return $this->id;
        }

        public function setId(int $id): void
        {
            $this->id = $id;
        }

        public function resetUpdatedFields(): void
        {
            // Mock implementation
        }

        public function getUpdatedFields(): array
        {
            return [];
        }

        /**
         * Magic setter - also updates _data array for getter consistency
         */
        public function __set(string $name, $value): void
        {
            $this->_data[$name] = $value;
        }

        /**
         * Magic getter - reads from _data array
         */
        public function __get(string $name)
        {
            return $this->_data[$name] ?? null;
        }

        /**
         * Magic method to handle dynamic getters and setters
         */
        public function __call(string $methodName, array $args)
        {
            // Handle setters: setName($value) -> _data['name'] = $value AND property = $value
            if (strpos($methodName, 'set') === 0) {
                $property = lcfirst(substr($methodName, 3));
                $this->_data[$property] = $args[0];
                // Also set actual property via reflection for protected properties
                try {
                    $reflection = new \ReflectionClass($this);
                    if ($reflection->hasProperty($property)) {
                        $prop = $reflection->getProperty($property);
                        $prop->setAccessible(true);
                        $prop->setValue($this, $args[0]);
                    }
                } catch (\ReflectionException $e) {
                    // Property doesn't exist, that's ok
                }
                return;
            }

            // Handle getters: getName() -> return _data['name'] or actual property via reflection
            if (strpos($methodName, 'get') === 0) {
                $property = lcfirst(substr($methodName, 3));
                // First check _data
                if (isset($this->_data[$property])) {
                    return $this->_data[$property];
                }
                // Try to get property via reflection (handles protected properties)
                try {
                    $reflection = new \ReflectionClass($this);
                    if ($reflection->hasProperty($property)) {
                        $prop = $reflection->getProperty($property);
                        $prop->setAccessible(true);
                        return $prop->getValue($this);
                    }
                } catch (\ReflectionException $e) {
                    // Property doesn't exist
                }
                return null;
            }

            throw new \BadMethodCallException("Method $methodName not found");
        }
    }
}
