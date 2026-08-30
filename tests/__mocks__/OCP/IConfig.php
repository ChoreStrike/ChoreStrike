<?php

/**
 * Mock for OCP\IConfig
 */

namespace OCP;

/**
 * Mock IConfig for unit tests
 */
interface IConfig {
	/**
	 * @return string
	 */
	public function getAppValue($appName, $key, $default = '');

	/**
	 * @return void
	 */
	public function setAppValue($appName, $key, $value);

	/**
	 * @return void
	 */
	public function deleteAppValue($appName, $key);

	/**
	 * @return string
	 */
	public function getUserValue($userId, $appName, $key, $default = '');

	/**
	 * @return void
	 */
	public function setUserValue($userId, $appName, $key, $value);

	/**
	 * @return array
	 */
	public function getUserKeys($userId, $appName);

	/**
	 * @return void
	 */
	public function deleteUserValue($userId, $appName, $key);

	/**
	 * @return string
	 */
	public function getSystemValue($key, $default = '');

	/**
	 * @return void
	 */
	public function setSystemValue($key, $value);
}
