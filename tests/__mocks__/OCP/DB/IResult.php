<?php

/**
 * Mock for OCP\DB\IResult
 */

namespace OCP\DB;

/**
 * Mock IResult for unit tests
 */
interface IResult {
	/**
	 * @return array|false
	 */
	public function fetch();

	/**
	 * @return array
	 */
	public function fetchAll();

	/**
	 * @return mixed
	 */
	public function fetchColumn($columnIndex = 0);

	/**
	 * @return mixed
	 */
	public function fetchOne($columnIndex = 0);

	/**
	 * @return int
	 */
	public function rowCount();
}
