<?php

/**
 * Mock for OCP\DB\QueryBuilder\IExpression
 */

namespace OCP\DB\QueryBuilder;

/**
 * Mock IExpression for unit tests
 */
interface IExpression {
	/**
	 * @return string
	 */
	public function andX(...$expressions);

	/**
	 * @return string
	 */
	public function orX(...$expressions);

	/**
	 * @return string
	 */
	public function eq($x, $y);

	/**
	 * @return string
	 */
	public function neq($x, $y);

	/**
	 * @return string
	 */
	public function lt($x, $y);

	/**
	 * @return string
	 */
	public function lte($x, $y);

	/**
	 * @return string
	 */
	public function gt($x, $y);

	/**
	 * @return string
	 */
	public function gte($x, $y);

	/**
	 * @return string
	 */
	public function isNull($x);

	/**
	 * @return string
	 */
	public function isNotNull($x);

	/**
	 * @return string
	 */
	public function like($x, $y);

	/**
	 * @return string
	 */
	public function notLike($x, $y);

	/**
	 * @return string
	 */
	public function in($x, $y);

	/**
	 * @return string
	 */
	public function notIn($x, $y);

	/**
	 * @return string
	 */
	public function literal($input, $type = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR);

	/**
	 * @return string
	 */
	public function castColumn($column, $type);

	/**
	 * @return string
	 */
	public function compare($column, $operator, $parameter);
}
