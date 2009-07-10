<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM;

use Doctrine\ORM\Query\Expr;

/**
 * This class is responsible for building DQL query strings via an object oriented
 * PHP interface.
 *
 * TODO: I don't like the API of using the Expr::*() syntax inside of the QueryBuilder
 * methods. What can we do to allow them to do it more fluently with the QueryBuilder.
 *
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org>
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        http://www.doctrine-project.org
 * @since       2.0
 * @version     $Revision$
 */
class QueryBuilder
{
    const SELECT = 0;
    const DELETE = 1;
    const UPDATE = 2;

    const STATE_DIRTY = 0;
    const STATE_CLEAN = 1;

    /**
     * @var EntityManager $em Instance of an EntityManager to use for query
     */
    protected $_em;

    /**
     * @var array $dqlParts The array of DQL parts collected
     */
    protected $_dqlParts = array(
        'select' => array(),
        'from' => array(),
        'where' => array(),
        'groupBy' => array(),
        'having' => array(),
        'orderBy' => array(),
        'limit' => array(), 
        'offset' => array()
    );

    /**
     * @var integer $type  The type of query this is. Can be select, update or delete
     */
    protected $_type = self::SELECT;

    /**
     * @var integer $state The state of the query object. Can be dirty or clean.
     */
    protected $_state = self::STATE_CLEAN;

    /**
     * @var string $dql The complete DQL string for this query
     */
    protected $_dql;

    /**
     * @var array $params Parameters of this query.
     */
    protected $_params = array();

    public function __construct(EntityManager $entityManager)
    {
        $this->_em = $entityManager;
    }

    public static function create(EntityManager $entityManager)
    {
        return new self($entityManager);
    }

    public function getType()
    {
        return $this->_type;
    }

    public function getEntityManager()
    {
        return $this->_em;
    }

    public function getState()
    {
        return $this->_state;
    }

    public function getDql()
    {
        if ($this->_dql !== null && self::STATE_CLEAN) {
            return $this->_dql;
        }

        $dql = '';

        switch ($this->_type) {
            case self::DELETE:
                $dql = $this->_getDqlForDelete();
                break;

            case self::UPDATE:
                $dql = $this->_getDqlForUpdate();
                break;

            case self::SELECT:
            default:
                $dql = $this->_getDqlForSelect();
                break;
        }

        $this->_dql = $dql;

        return $dql;
    }

    public function getQuery()
    {
        $q = new Query($this->_em);
        $q->setDql($this->getDql());
        $q->setParameters($this->getParameters());

        return $q;
    }

    public function execute($params = array(), $hydrationMode = null)
    {
        return $this->getQuery()->execute($params, $hydrationMode);
    }

    /**
     * Sets a query parameter.
     *
     * @param string|integer $key The parameter position or name.
     * @param mixed $value The parameter value.
     */
    public function setParameter($key, $value)
    {
        $this->_params[$key] = $value;

        return $this;
    }
    
    /**
     * Sets a collection of query parameters.
     *
     * @param array $params
     */
    public function setParameters(array $params)
    {
        foreach ($params as $key => $value) {
            $this->setParameter($key, $value);
        }

        return $this;
    }

    /**
     * Get all defined parameters
     *
     * @return array Defined parameters
     */
    public function getParameters($params = array())
    {
        if ($params) {
            return array_merge($this->_params, $params);
        }
        return $this->_params;
    }
    
    /**
     * Gets a query parameter.
     * 
     * @param mixed $key The key (index or name) of the bound parameter.
     * @return mixed The value of the bound parameter.
     */
    public function getParameter($key)
    {
        return isset($this->_params[$key]) ? $this->_params[$key] : null;
    }

    /**
     * Add a single DQL query part to the array of parts
     *
     * @param string $dqlPartName 
     * @param string $dqlPart 
     * @param string $append 
     * @return QueryBuilder $this
     */
    public function add($dqlPartName, $dqlPart, $append = false)
    {
        if ($append) {
            $this->_dqlParts[$dqlPartName][] = $dqlPart;
        } else {
            $this->_dqlParts[$dqlPartName] = array($dqlPart);
        }

        $this->_state = self::STATE_DIRTY;

        return $this;
    }

    public function select($select)
    {
        $selects = func_get_args();
        $this->_type = self::SELECT;

        if (empty($selects)) {
            return $this;
        }

        $select = call_user_func_array(array('Doctrine\ORM\Query\Expr', 'select'), $selects);
        return $this->add('select', $select, true);
    }

    public function delete($delete = null, $alias = null)
    {
        $this->_type = self::DELETE;

        if ( ! $delete) {
            return $this;
        }

        return $this->add('from', $delete . ' ' . $alias);
    }

    public function update($update = null, $alias = null)
    {
        $this->_type = self::UPDATE;

        if ( ! $update) {
            return $this;
        }

        return $this->add('from', $update . ' ' . $alias);
    }

    public function set($key, $value)
    {
        return $this->add('set', Expr::eq($key, $value), true);
    }

    public function from($from, $alias)
    {
        return $this->add('from', $from . ' ' . $alias, true);
    }

    public function innerJoin($parentAlias, $join, $alias, $condition = null)
    {
        $join = 'INNER JOIN ' . $parentAlias . '.' . $join . ' '
        . $alias . (isset($condition) ? ' ' . $condition : null);

        return $this->add('from', $join, true);
    }

    public function leftJoin($parentAlias, $join, $alias, $condition = null)
    {
        $join = 'LEFT JOIN ' . $parentAlias . '.' . $join . ' '
        . $alias . (isset($condition) ? ' ' . $condition : null);

        return $this->add('from', $join, true);
    }

    public function where($where)
    {
        $where = call_user_func_array(array('Doctrine\ORM\Query\Expr', 'andx'), func_get_args());
        return $this->add('where', $where, false);
    }

    public function andWhere($where)
    {
        if (count($this->_getDqlQueryPart('where')) > 0) {
            $this->add('where', 'AND', true);
        }

        $where = call_user_func_array(array('Doctrine\ORM\Query\Expr', 'andx'), func_get_args());
        return $this->add('where', $where, true);
    }

    public function orWhere($where)
    {
        if (count($this->_getDqlQueryPart('where')) > 0) {
            $this->add('where', 'OR', true);
        }

        $where = call_user_func_array(array('Doctrine\ORM\Query\Expr', 'orx'), func_get_args());
        return $this->add('where', $where, true);
    }

    public function andWhereIn($expr, $params)
    {
        if (count($this->_getDqlQueryPart('where')) > 0) {
            $this->add('where', 'AND', true);
        }

        return $this->add('where', Expr::in($expr, $params), true);
    }

    public function orWhereIn($expr, $params = array(), $not = false)
    {
        if (count($this->_getDqlQueryPart('where')) > 0) {
            $this->add('where', 'OR', true);
        }

        return $this->add('where', Expr::in($expr, $params), true);
    }

    public function andWhereNotIn($expr, $params = array())
    {
        if (count($this->_getDqlQueryPart('where')) > 0) {
            $this->add('where', 'AND', true);
        }

        return $this->add('where', Expr::notIn($expr, $params), true);
    }

    public function orWhereNotIn($expr, $params = array())
    {
        if (count($this->_getDqlQueryPart('where')) > 0) {
            $this->add('where', 'OR', true);
        }

        return $this->add('where', Expr::notIn($expr, $params), true);
    }

    public function groupBy($groupBy)
    {
        return $this->add('groupBy', $groupBy, false);
    }

    public function having($having)
    {
        return $this->add('having', $having, false);
    }

    public function andHaving($having)
    {
        if (count($this->_getDqlQueryPart('having')) > 0) {
            $this->add('having', 'AND', true);
        }

        return $this->add('having', $having, true);
    }

    public function orHaving($having)
    {
        if (count($this->_getDqlQueryPart('having')) > 0) {
            $this->add('having', 'OR', true);
        }

        return $this->add('having', $having, true);
    }

    public function orderBy($sort, $order)
    {
        return $this->add('orderBy', $sort . ' ' . $order, false);
    }

    public function addOrderBy($sort, $order)
    {
        return $this->add('orderBy', $sort . ' ' . $order, true);
    }

    public function limit($limit)
    {
        return $this->add('limit', $limit);
    }

    public function offset($offset)
    {
        return $this->add('offset', $offset);
    }

    /**
     * Get the DQL query string for DELETE queries
     *
     * BNF:
     *
     * DeleteStatement = DeleteClause [WhereClause] [OrderByClause] [LimitClause] [OffsetClause]
     * DeleteClause    = "DELETE" "FROM" RangeVariableDeclaration
     * WhereClause     = "WHERE" ConditionalExpression
     * OrderByClause   = "ORDER" "BY" OrderByItem {"," OrderByItem}
     * LimitClause     = "LIMIT" integer
     * OffsetClause    = "OFFSET" integer
     *
     * @return string $dql
     */
    private function _getDqlForDelete()
    {
         return 'DELETE'
              . $this->_getReducedDqlQueryPart('from', array('pre' => ' ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('where', array('pre' => ' WHERE ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('orderBy', array('pre' => ' ORDER BY ', 'separator' => ', '))
              . $this->_getReducedDqlQueryPart('limit', array('pre' => ' LIMIT ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('offset', array('pre' => ' OFFSET ', 'separator' => ' '));
    }

    /**
     * Get the DQL query string for UPDATE queries
     *
     * BNF:
     *
     * UpdateStatement = UpdateClause [WhereClause] [OrderByClause] [LimitClause] [OffsetClause]
     * UpdateClause    = "UPDATE" RangeVariableDeclaration "SET" UpdateItem {"," UpdateItem}
     * WhereClause     = "WHERE" ConditionalExpression
     * OrderByClause   = "ORDER" "BY" OrderByItem {"," OrderByItem}
     * LimitClause     = "LIMIT" integer
     * OffsetClause    = "OFFSET" integer
     *
     * @return string $dql
     */
    private function _getDqlForUpdate()
    {
         return 'UPDATE'
              . $this->_getReducedDqlQueryPart('from', array('pre' => ' ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('set', array('pre' => ' SET ', 'separator' => ', '))
              . $this->_getReducedDqlQueryPart('where', array('pre' => ' WHERE ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('orderBy', array('pre' => ' ORDER BY ', 'separator' => ', '))
              . $this->_getReducedDqlQueryPart('limit', array('pre' => ' LIMIT ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('offset', array('pre' => ' OFFSET ', 'separator' => ' '));
    }

    /**
     * Get the DQL query string for SELECT queries
     *
     * BNF:
     *
     * SelectStatement = [SelectClause] FromClause [WhereClause] [GroupByClause] [HavingClause] [OrderByClause] [LimitClause] [OffsetClause]
     * SelectClause    = "SELECT" ["ALL" | "DISTINCT"] SelectExpression {"," SelectExpression}
     * FromClause      = "FROM" IdentificationVariableDeclaration {"," IdentificationVariableDeclaration}
     * WhereClause     = "WHERE" ConditionalExpression
     * GroupByClause   = "GROUP" "BY" GroupByItem {"," GroupByItem}
     * HavingClause    = "HAVING" ConditionalExpression
     * OrderByClause   = "ORDER" "BY" OrderByItem {"," OrderByItem}
     * LimitClause     = "LIMIT" integer
     * OffsetClause    = "OFFSET" integer
     *
     * @return string $dql
     */
    private function _getDqlForSelect()
    {
         return 'SELECT'
              . $this->_getReducedDqlQueryPart('select', array('pre' => ' ', 'separator' => ', '))
              . $this->_getReducedDqlQueryPart('from', array('pre' => ' FROM ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('where', array('pre' => ' WHERE ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('groupBy', array('pre' => ' GROUP BY ', 'separator' => ', '))
              . $this->_getReducedDqlQueryPart('having', array('pre' => ' HAVING ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('orderBy', array('pre' => ' ORDER BY ', 'separator' => ', '))
              . $this->_getReducedDqlQueryPart('limit', array('pre' => ' LIMIT ', 'separator' => ' '))
              . $this->_getReducedDqlQueryPart('offset', array('pre' => ' OFFSET ', 'separator' => ' '));
    }

    private function _getReducedDqlQueryPart($queryPartName, $options = array())
    {
        if (empty($this->_dqlParts[$queryPartName])) {
            return (isset($options['empty']) ? $options['empty'] : '');
        }

        $str  = (isset($options['pre']) ? $options['pre'] : '');
        $str .= implode($options['separator'], $this->_getDqlQueryPart($queryPartName));
        $str .= (isset($options['post']) ? $options['post'] : '');

        return $str;
    }

    private function _getDqlQueryPart($queryPartName)
    {
        return $this->_dqlParts[$queryPartName];
    }
}