<?php

/**
 * Mock for OCP\DB\QueryBuilder\IQueryBuilder
 */

namespace OCP\DB\QueryBuilder;

/**
 * Mock IQueryBuilder for unit tests
 */
interface IQueryBuilder {
	const PARAM_NULL = 0;
	const PARAM_INT = 1;
	const PARAM_STR = 2;
	const PARAM_LOB = 3;
	const PARAM_BOOL = 5;
	const PARAM_DATE = 6;
	const PARAM_STR_ARRAY = 100;
	const PARAM_INT_ARRAY = 101;

	/**
	 * @return $this
	 */
	public function select(...$columns);

	/**
	 * @return $this
	 */
	public function selectDistinct(...$columns);

	/**
	 * @return $this
	 */
	public function from($table, $alias = null);

	/**
	 * @return $this
	 */
	public function join($fromAlias, $join, $alias, $condition);

	/**
	 * @return $this
	 */
	public function innerJoin($fromAlias, $join, $alias, $condition);

	/**
	 * @return $this
	 */
	public function leftJoin($fromAlias, $join, $alias, $condition);

	/**
	 * @return $this
	 */
	public function rightJoin($fromAlias, $join, $alias, $condition);

	/**
	 * @return $this
	 */
	public function where(...$expressions);

	/**
	 * @return $this
	 */
	public function andWhere(...$expressions);

	/**
	 * @return $this
	 */
	public function orWhere(...$expressions);

	/**
	 * @return $this
	 */
	public function groupBy(...$columns);

	/**
	 * @return $this
	 */
	public function addGroupBy(...$columns);

	/**
	 * @return $this
	 */
	public function having(...$expressions);

	/**
	 * @return $this
	 */
	public function andHaving(...$expressions);

	/**
	 * @return $this
	 */
	public function orHaving(...$expressions);

	/**
	 * @return $this
	 */
	public function orderBy($column, $direction = 'ASC');

	/**
	 * @return $this
	 */
	public function addOrderBy($column, $direction = 'ASC');

	/**
	 * @return $this
	 */
	public function set($column, $value);

	/**
	 * @return $this
	 */
	public function setMaxResults($maxResults);

	/**
	 * @return $this
	 */
	public function setFirstResult($firstResult);

	/**
	 * @return IParameter
	 */
	public function createNamedParameter($value, $type = self::PARAM_STR);

	/**
	 * @return IParameter
	 */
	public function createPositionalParameter($value, $type = self::PARAM_STR);

	/**
	 * @return IFunctionBuilder
	 */
	public function func();

	/**
	 * @return \OCP\DB\QueryBuilder\IExpression
	 */
	public function expr();

	/**
	 * @return string
	 */
	public function getSQL();

	/**
	 * @return \Doctrine\DBAL\Result|int
	 */
	public function executeQuery();

	/**
	 * @return int
	 */
	public function executeStatement();

	/**
	 * @return string
	 */
	public function getTableName($tableName);

	/**
	 * @return string
	 */
	public function getColumnName($columnName);

	/**
	 * @return string
	 */
	public function getColumnNameAlias($columnAlias);
}
