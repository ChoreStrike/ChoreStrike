<?php

/**
 * Mock for OCP\DB\QueryBuilder\IFunctionBuilder
 */

namespace OCP\DB\QueryBuilder;

/**
 * Mock IFunctionBuilder for unit tests (minimal surface: only the
 * aggregate functions used by the mapper unit suites).
 */
interface IFunctionBuilder {
	/**
	 * @return IExpression
	 */
	public function count($count = '*');

	/**
	 * @return IExpression
	 */
	public function sum($x);

	/**
	 * @return IExpression
	 */
	public function min($x);

	/**
	 * @return IExpression
	 */
	public function max($x);
}
