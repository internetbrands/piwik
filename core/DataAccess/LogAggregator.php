<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik\DataAccess;

use PDOStatement;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db\Factory;
use Piwik\Db;
use Piwik\Metrics;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Tracker\GoalManager;

/**
 * Contains methods that aggregates log data (visits, actions, conversions, ecommerce).
 * 
 * Plugin [Archiver](#) descendants can use the methods in this class to aggregate data
 * in the log tables without creating their own SQL queries.
 * 
 * ### Examples
 * 
 * ** TODO **
 */
class LogAggregator
{
    const LOG_VISIT_TABLE = 'log_visit';

    const LOG_ACTIONS_TABLE = 'log_link_visit_action';

    const LOG_CONVERSION_TABLE = "log_conversion";

    const REVENUE_SUBTOTAL_FIELD = 'revenue_subtotal';

    const REVENUE_TAX_FIELD = 'revenue_tax';

    const REVENUE_SHIPPING_FIELD = 'revenue_shipping';

    const REVENUE_DISCOUNT_FIELD = 'revenue_discount';

    const TOTAL_REVENUE_FIELD = 'revenue';

    const ITEMS_COUNT_FIELD = "items";

    const CONVERSION_DATETIME_FIELD = "server_time";

    const ACTION_DATETIME_FIELD = "server_time";

    const VISIT_DATETIME_FIELD = 'visit_last_action_time';

    const IDGOAL_FIELD = 'idgoal';

    const FIELDS_SEPARATOR = ", \n\t\t\t";

    /** @var \Piwik\Date */
    protected $dateStart;

    /** @var \Piwik\Date */
    protected $dateEnd;

    /** @var \Piwik\Site */
    protected $site;

    /** @var \Piwik\Segment */
    protected $segment;

    protected $db;

    /**
     * Constructor
     * @param Date $dateStart
     * @param Date $dateEnd
     * @param Site $site
     * @param Segment $segment
     */
    public function __construct(Date $dateStart, Date $dateEnd, Site $site, Segment $segment)
    {
        $this->dateStart = $dateStart;
        $this->dateEnd = $dateEnd;
        $this->segment = $segment;
        $this->site = $site;

        $this->db = $this->getDb();
    }

    public function generateQuery($select, $from, $where, $groupBy, $orderBy)
    {
        $bind = $this->getBindDatetimeSite();
        $query = $this->segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy);
        return $query;
    }

    protected function getVisitsMetricFields()
    {
        return array(
            Metrics::INDEX_NB_UNIQ_VISITORS    => "count(distinct " . self::LOG_VISIT_TABLE . ".idvisitor)",
            Metrics::INDEX_NB_VISITS           => "count(*)",
            Metrics::INDEX_NB_ACTIONS          => "sum(" . self::LOG_VISIT_TABLE . ".visit_total_actions)",
            Metrics::INDEX_MAX_ACTIONS         => "max(" . self::LOG_VISIT_TABLE . ".visit_total_actions)",
            Metrics::INDEX_SUM_VISIT_LENGTH    => "sum(" . self::LOG_VISIT_TABLE . ".visit_total_time)",
            Metrics::INDEX_BOUNCE_COUNT        => "sum(case " . self::LOG_VISIT_TABLE . ".visit_total_actions when 1 then 1 when 0 then 1 else 0 end)",
            Metrics::INDEX_NB_VISITS_CONVERTED => "sum(case " . self::LOG_VISIT_TABLE . ".visit_goal_converted when 1 then 1 else 0 end)",
        );
    }

    static public function getConversionsMetricFields()
    {
        return array(
            Metrics::INDEX_GOAL_NB_CONVERSIONS             => "count(*)",
            Metrics::INDEX_GOAL_NB_VISITS_CONVERTED        => "count(distinct " . self::LOG_CONVERSION_TABLE . ".idvisit)",
            Metrics::INDEX_GOAL_REVENUE                    => self::getSqlConversionRevenueSum(self::TOTAL_REVENUE_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SUBTOTAL => self::getSqlConversionRevenueSum(self::REVENUE_SUBTOTAL_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_TAX      => self::getSqlConversionRevenueSum(self::REVENUE_TAX_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SHIPPING => self::getSqlConversionRevenueSum(self::REVENUE_SHIPPING_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_DISCOUNT => self::getSqlConversionRevenueSum(self::REVENUE_DISCOUNT_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_ITEMS            => "SUM(" . self::LOG_CONVERSION_TABLE . "." . self::ITEMS_COUNT_FIELD . ")",
        );
    }

    static private function getSqlConversionRevenueSum($field)
    {
        return self::getSqlRevenue('SUM(' . self::LOG_CONVERSION_TABLE . '.' . $field . ')');
    }

    static public function getSqlRevenue($field)
    {
        $Generic = Factory::getGeneric();
        return $Generic->getSqlRevenue($field);
    }

    /**
     * Queries visit logs by dimension and returns the aggregate data.
     *
     * @param array|string $dimensions SELECT fields (or just one field) that will be grouped by,
     *                                 eg, `'referrer_name'` or `array('referrer_name', 'referrer_keyword')`.
     *                                 The metrics retrieved from the query will be specific to combinations
     *                                 of these fields. So if `array('referrer_name', 'referrer_keyword')`
     *                                 is supplied, the query will select the visits for each referrer/keyword
     *                                 combination.
     * @param bool|string $where Additional condition for the WHERE clause. Can be used to filter
     *                           the set of visits that are looked at.
     * @param array $additionalSelects Additional SELECT fields that are not included in the group by
     *                                 clause. These can be aggregate expressions, eg, `SUM(somecol)`.
     * @param bool|array $metrics The set of metrics to return. If false, the query will select all of them.
     *                            The following values can be used:
     *                              - Metrics::INDEX_NB_UNIQ_VISITORS
     *                              - Metrics::INDEX_NB_VISITS
     *                              - Metrics::INDEX_NB_ACTIONS
     *                              - Metrics::INDEX_MAX_ACTIONS
     *                              - Metrics::INDEX_SUM_VISIT_LENGTH
     *                              - Metrics::INDEX_BOUNCE_COUNT
     *                              - Metrics::INDEX_NB_VISITS_CONVERTED
     * @param bool|\Piwik\RankingQuery $rankingQuery
     *                                   A pre-configured ranking query instance that will be used to limit the result.
     *                                   If set, the return value is the array returned by [RankingQuery::execute()](#).
     * @return mixed A Zend_Db_Statement if `$rankingQuery` isn't supplied, otherwise the result of
     *                                   [RankingQuery::execute()](#).
     * @api
     */
    public function queryVisitsByDimension(array $dimensions = array(), $where = false, array $additionalSelects = array(),
                                           $metrics = false, $rankingQuery = false)
    {
        $tableName = self::LOG_VISIT_TABLE;
        $availableMetrics = $this->getVisitsMetricFields();

        $select = $this->getSelectStatement($dimensions, $tableName, $additionalSelects, $availableMetrics, $metrics);
        $from = array($tableName);
        $where = $this->getWhereStatement($tableName, self::VISIT_DATETIME_FIELD, $where);
        $groupBy = $this->getGroupByStatement($dimensions, $tableName);
        $orderBy = false;

        if ($rankingQuery) {
            $orderBy = $this->db->quoteIdentifier(Metrics::INDEX_NB_VISITS) . ' DESC';
        }
        $query = $this->generateQuery($select, $from, $where, $groupBy, $orderBy);

        if ($rankingQuery) {
            unset($availableMetrics[Metrics::INDEX_MAX_ACTIONS]);
            $sumColumns = array_keys($availableMetrics);
            if ($metrics) {
                $sumColumns = array_intersect($sumColumns, $metrics);
            }
            $rankingQuery->addColumn($sumColumns, 'sum');
            if ($this->isMetricRequested(Metrics::INDEX_MAX_ACTIONS, $metrics)) {
                $rankingQuery->addColumn(Metrics::INDEX_MAX_ACTIONS, 'max');
            }
            return $rankingQuery->execute($query['sql'], $query['bind']);
        }
        return $this->db->query($query['sql'], $query['bind']);
    }

    protected function getSelectsMetrics($metricsAvailable, $metricsRequested = false)
    {
        $selects = array();
        foreach ($metricsAvailable as $metricId => $statement) {
            if ($this->isMetricRequested($metricId, $metricsRequested)) {
                $aliasAs = $this->getSelectAliasAs($metricId);
                $selects[] = $statement . $aliasAs;
            }
        }
        return $selects;
    }

    protected function getSelectStatement($dimensions, $tableName, $additionalSelects, array $availableMetrics, $requestedMetrics = false)
    {
        $dimensionsToSelect = $this->getDimensionsToSelect($dimensions, $additionalSelects);
        $selects = array_merge(
            $this->getSelectDimensions($dimensionsToSelect, $tableName),
            $this->getSelectsMetrics($availableMetrics, $requestedMetrics),
            !empty($additionalSelects) ? $additionalSelects : array()
        );
        $select = implode(self::FIELDS_SEPARATOR, $selects);
        return $select;
    }

    /**
     * Will return the subset of $dimensions that are not found in $additionalSelects
     *
     * @param $dimensions
     * @param array $additionalSelects
     * @return array
     */
    protected function getDimensionsToSelect($dimensions, $additionalSelects)
    {
        if (empty($additionalSelects)) {
            return $dimensions;
        }
        $dimensionsToSelect = array();
        foreach ($dimensions as $selectAs => $dimension) {
            $asAlias = $this->getSelectAliasAs($dimension);
            foreach ($additionalSelects as $additionalSelect) {
                if (strpos($additionalSelect, $asAlias) === false) {
                    $dimensionsToSelect[$selectAs] = $dimension;
                }
            }
        }
        $dimensionsToSelect = array_unique($dimensionsToSelect);
        return $dimensionsToSelect;
    }

    /**
     * Returns the dimensions array, where
     * (1) the table name is prepended to the field
     * (2) the "AS `label` " is appended to the field
     *
     * @param $dimensions
     * @param $tableName
     * @param bool $appendSelectAs
     * @return mixed
     */
    protected function getSelectDimensions($dimensions, $tableName, $appendSelectAs = true)
    {
        foreach ($dimensions as $selectAs => &$field) {
            $selectAsString = $field;
            if (!is_numeric($selectAs)) {
                $selectAsString = $selectAs;
            } else {
                // if function, do not alias or prefix
                if ($this->isFieldFunctionOrComplexExpression($field)) {
                    $selectAsString = $appendSelectAs = false;
                }
            }
            $isKnownField = !in_array($field, array('referrer_data'));
            if ($selectAsString == $field
                && $isKnownField
            ) {
                $field = $this->prefixColumn($field, $tableName);
            }
            if ($appendSelectAs && $selectAsString) {
                $field = $this->prefixColumn($field, $tableName) . $this->getSelectAliasAs($selectAsString);
            }
        }
        return $dimensions;
    }

    /**
     * Prefixes a column name with a table name if not already done.
     *
     * @param string $column eg, 'location_provider'
     * @param string $tableName eg, 'log_visit'
     * @return string eg, 'log_visit.location_provider'
     */
    private function prefixColumn($column, $tableName)
    {
        if (strpos($column, '.') === false) {
            return $tableName . '.' . $column;
        } else {
            return $column;
        }
    }

    protected function isFieldFunctionOrComplexExpression($field)
    {
        return strpos($field, "(") !== false
        || strpos($field, "CASE") !== false;
    }

    protected function getSelectAliasAs($metricId)
    {
        return ' AS ' . $this->db->quoteIdentifier($metricId);
    }

    protected function isMetricRequested($metricId, $metricsRequested)
    {
        return $metricsRequested === false
        || in_array($metricId, $metricsRequested);
    }

    protected function getWhereStatement($tableName, $datetimeField, $extraWhere = false)
    {
        $where = "$tableName.$datetimeField >= ?
				AND $tableName.$datetimeField <= ?
				AND $tableName.idsite = ?";
        if (!empty($extraWhere)) {
            $extraWhere = sprintf($extraWhere, $tableName, $tableName);
            $where .= ' AND ' . $extraWhere;
        }
        return $where;
    }

    protected function getGroupByStatement($dimensions, $tableName)
    {
        $dimensions = $this->getSelectDimensions($dimensions, $tableName, $appendSelectAs = false);
        $groupBy = implode(", ", $dimensions);
        return $groupBy;
    }

    protected function getBindDatetimeSite()
    {
        return array($this->dateStart->getDateStartUTC(), $this->dateEnd->getDateEndUTC(), $this->site->getId());
    }

    /**
     * Queries all ecommerce items.
     *
     * @param string $field
     * @return string
     */
    public function queryEcommerceItems($field)
    {
        $LogConversionItem = Factory::getDAO('log_conversion_item');
        return $LogConversionItem->getEcommerceItems($field, $this->getBindDatetimeSite());
    }

    /**
     * Queries the Actions table log_link_visit_action and returns the aggregate data.
     *
     * @param array|string $dimensions the dimensionRecord(s) you're interested in
     * @param string $where where clause
     * @param array|bool $additionalSelects additional select clause
     * @param bool|array $metrics Set this if you want to limit the columns that are returned.
     *                                  The possible values in the array are Metrics::INDEX_*.
     * @param \Piwik\RankingQuery $rankingQuery pre-configured ranking query instance
     * @param bool|string $joinLogActionOnColumn column from log_link_visit_action that
     *                                              log_action should be joined on.
     *                                                can be an array to join multiple times.
     * @param bool|string $extraGroupBy columns to be added to the "group by" clause
     * @return mixed
     */
    public function queryActionsByDimension($dimensions, $where = '', $additionalSelects = array(), $metrics = false, $rankingQuery = null, $joinLogActionOnColumn = false, $extraGroupBy = false)
    {
        $tableName = self::LOG_ACTIONS_TABLE;
        $availableMetrics = $this->getActionsMetricFields();

        $select = $this->getSelectStatement($dimensions, $tableName, $additionalSelects, $availableMetrics, $metrics);
        $from = array($tableName);
        $where = $this->getWhereStatement($tableName, self::ACTION_DATETIME_FIELD, $where);
        $groupBy = $this->getGroupByStatement($dimensions, $tableName);
        if ($extraGroupBy !== false) {
            $groupBy .= ', ' . trim($extraGroupBy, ', ');
        }
        $orderBy = false;

        if ($joinLogActionOnColumn !== false) {
            $multiJoin = is_array($joinLogActionOnColumn);
            if (!$multiJoin) {
                $joinLogActionOnColumn = array($joinLogActionOnColumn);
            }

            foreach ($joinLogActionOnColumn as $i => $joinColumn) {
                $tableAlias = 'log_action' . ($multiJoin ? $i + 1 : '');
                if (strpos($joinColumn, ' ') === false) {
                    $joinOn = $tableAlias . '.idaction = ' . $tableName . '.' . $joinColumn;
                } else {
                    // more complex join column like IF(...)
                    $joinOn = $tableAlias . '.idaction = ' . $joinColumn;
                }
                $from[] = array(
                    'table'      => 'log_action',
                    'tableAlias' => $tableAlias,
                    'joinOn'     => $joinOn
                );
            }
        }

        if ($rankingQuery) {
            $orderBy =  $this->db->quoteIdentifier(Metrics::INDEX_NB_ACTIONS) . ' DESC';
        }

        $query = $this->generateQuery($select, $from, $where, $groupBy, $orderBy);

        if ($rankingQuery !== null) {
            $sumColumns = array_keys($availableMetrics);
            if ($metrics) {
                $sumColumns = array_intersect($sumColumns, $metrics);
            }
            $rankingQuery->addColumn($sumColumns, 'sum');
            return $rankingQuery->execute($query['sql'], $query['bind']);
        }

        return $this->db->query($query['sql'], $query['bind']);
    }

    protected function getActionsMetricFields()
    {
        return $availableMetrics = array(
            Metrics::INDEX_NB_VISITS        => "count(distinct " . self::LOG_ACTIONS_TABLE . ".idvisit)",
            Metrics::INDEX_NB_UNIQ_VISITORS => "count(distinct " . self::LOG_ACTIONS_TABLE . ".idvisitor)",
            Metrics::INDEX_NB_ACTIONS       => "count(*)",
        );
    }

    /**
     * Queries the log_conversion table and return aggregate data
     *
     * @param string|array $dimensions
     * @param bool|string $where
     * @param array $additionalSelects
     * @return PDOStatement
     */
    public function queryConversionsByDimension($dimensions = array(), $where = false, $additionalSelects = array())
    {
        $dimensions = array_merge(array(self::IDGOAL_FIELD), $dimensions);
        $availableMetrics = $this->getConversionsMetricFields();
        $tableName = self::LOG_CONVERSION_TABLE;

        $select = $this->getSelectStatement($dimensions, $tableName, $additionalSelects, $availableMetrics);

        $from = array($tableName);
        $where = $this->getWhereStatement($tableName, self::CONVERSION_DATETIME_FIELD, $where);
        $groupBy = $this->getGroupByStatement($dimensions, $tableName);
        $orderBy = false;
        $query = $this->generateQuery($select, $from, $where, $groupBy, $orderBy);
        return $this->db->query($query['sql'], $query['bind']);
    }

    /**
     * Creates and returns an array of SQL SELECT expressions that will summarize
     * the data in a column of a specified table, over a set of ranges.
     *
     * The SELECT expressions will count the number of column values that are
     * within each range.
     *
     * @param $metadata
     * @return array  An array of SQL SELECT expressions.
     */
    public static function getSelectsFromRangedColumn($metadata)
    {
        @list($column, $ranges, $table, $selectColumnPrefix, $restrictToReturningVisitors) = $metadata;

        $db = Db::get();

        $selects = array();
        $extraCondition = '';
        if ($restrictToReturningVisitors) {
            // extra condition for the SQL SELECT that makes sure only returning visits are counted
            // when creating the 'days since last visit' report
            $extraCondition = 'and log_visit.visitor_returning = 1';
            $extraSelect = "sum(case when log_visit.visitor_returning = 0 then 1 else 0 end) "
                . " as " . $db->quoteIdentifier($selectColumnPrefix . 'General_NewVisits') . " ";
            $selects[] = $extraSelect;
        }
        foreach ($ranges as $gap) {
            if (count($gap) == 2) {
                $lowerBound = $gap[0];
                $upperBound = $gap[1];

                $selectAs = $db->quoteIdentifier("$selectColumnPrefix$lowerBound-$upperBound");

                $selects[] = "sum(case when $table.$column between $lowerBound and $upperBound $extraCondition" .
                    " then 1 else 0 end) as $selectAs";
            } else {
                $lowerBound = $gap[0];

                $selectAs = $db->quoteIdentifier($selectColumnPrefix . ($lowerBound + 1) . urlencode('+'));

                $selects[] = "sum(case when $table.$column > $lowerBound $extraCondition then 1 else 0 end) as $selectAs";
            }
        }

        return $selects;
    }

    /**
     * Clean up the row data and return values.
     * $lookForThisPrefix can be used to make sure only SOME of the data in $row is used.
     *
     * The array will have one column $columnName
     *
     * @param $row
     * @param $columnName
     * @param bool $lookForThisPrefix A string that identifies which elements of $row to use
     *                                 in the result. Every key of $row that starts with this
     *                                 value is used.
     * @return array
     */
    static public function makeArrayOneColumn($row, $columnName, $lookForThisPrefix = false)
    {
        $cleanRow = array();
        foreach ($row as $label => $count) {
            if (empty($lookForThisPrefix)
                || strpos($label, $lookForThisPrefix) === 0
            ) {
                $cleanLabel = substr($label, strlen($lookForThisPrefix));
                $cleanRow[$cleanLabel] = array($columnName => $count);
            }
        }
        return $cleanRow;
    }

    public function getDb()
    {
        return Db::get();
    }
}