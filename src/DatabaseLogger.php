<?php

namespace TiGR\DatabaseLogger;

/**
 * Class DatabaseLogger
 * @package TiGR\DatabaseLogger
 */
class DatabaseLogger
{
    private static $lastStartTime;
    private static $uniqueInstance = null;

    private $addExplain = false;
    private $debug = false;
    private $prettyPrint = false;
    private $queryLogging = false;

    private $log = [];
    private $errorLog = [];

    private $time = 0;
    private $queries = 0;
    private $backtracer;

    /** @var \PDO $pdo */
    private $pdo;
    private $statementClass;

    private function __construct()
    {
        // private constructor - singleton can be created only by itself
    }

    /**
     * @return DatabaseLogger
     */
    public static function getInstance()
    {
        if (!isset(self::$uniqueInstance)) {
            self::$uniqueInstance = new self;
        }

        return self::$uniqueInstance;
    }

    public static function getQueryLog()
    {
        return self::getInstance()->getLog();
    }

    public static function getErrorLog()
    {
        return self::getInstance()->errorLog;
    }

    public static function getTotalQueriesNumber()
    {
        return self::getInstance()->queries;
    }

    public static function getTotalTime()
    {
        return self::getInstance()->time;
    }

    public static function logStart()
    {
        self::$lastStartTime = microtime(true);
    }

    public static function logEnd($query, $params = null)
    {
        self::getInstance()->logQuery($query, microtime(true) - self::$lastStartTime, $params);
    }

    public static function logError($query, $error, $errorCode, $params = null)
    {
        self::getInstance()->_logError($query, microtime(true) - self::$lastStartTime, $error, $errorCode, $params);
    }

    public static function setQueryLogging($flag)
    {
        return self::getInstance()->_setQueryLogging($flag);
    }


    public function logQuery($query, $time, $params)
    {
        $this->time += $time;
        $this->queries++;

        if ($this->queryLogging) {
            $this->log[] = $this->prepareLogEntry($query, $time, $params, $this->debug);
        }
    }

    public function getLog()
    {
        if ($this->addExplain) {
            $this->addExplain();
        }

        return $this->log;
    }

    public function _logError($query, $time, $error, $errorCode, $params = null)
    {
        if ($this->queryLogging or $this->debug) {
            $logItem = $this->prepareLogEntry($query, $time, $params, true);
            $logItem['error'] = [
                'code' => $errorCode,
                'message' => $error
            ];

            $this->errorLog[] = $logItem;
        }
    }

    public function setDebug($flag)
    {
        $this->debug = (bool)$flag;
        if ($this->debug) {
            $this->_setQueryLogging(true);
        }

        return $this;
    }

    public function addIncludeDebugDir($namespace)
    {
        self::getInstance()->getBacktracer()->addIncludeDir($namespace);

        return $this;
    }

    public function enableExplain(\PDO $pdo)
    {
        $this->addExplain = true;
        $this->pdo = $pdo;

        return $this;
    }

    public function setPrettyPrint($flag)
    {
        $this->prettyPrint = $flag;

        return $this;
    }

    private function prepareLogEntry($query, $time, $params, $trace = false)
    {
        $stats = array(
            'query' => $query,
            'queryFormatted' => '',
            'params' => $params,
            'queryTime' => $time,
        );
        if ($this->prettyPrint) {
            $query = $this->formatQuery($query);
        }

        $stats['queryFormatted'] = (isset($params) ? $this->substituteParameters($query, $params) : $query);

        if ($trace) {
            $stats['trace'] = $this->getBacktracer()->getBacktrace();
        }
        return $stats;
    }

    private function formatQuery($query)
    {
        $query = preg_replace('/(?<!\s) (((left|right|outer|inner) )?join|where) /i', "\n  $1 ", $query);
        $query = preg_replace('/(?<!\s) (order by|limit|having) /i', "\n    $1 ", $query);
        $query = preg_replace('/(?<!\s) (union( (all|distinct))?) /i', "\n$1\n", $query);

        $query = preg_replace(
            '/(?<=^|\s|\()(select|set|from|explain|update|insert|replace|left|right|outer|inner|join|where|order by|'
            . 'limit|as|and|or|having|union|all|distinct|on|is|not|null|true|false|desc|asc|between|in)'
            . '(?=$|\s|\)|\(|,)/i', "<b>$1</b>", $query
        );

        return $query;
    }

    private function substituteParameters($query, array $params)
    {
        ksort($params);

        foreach ($params as $key => $param) {
            if ($param['type'] == \PDO::PARAM_STR) {
                $value = ($this->prettyPrint ? $param['value'] : sprintf("'%s'", addslashes($param['value'])));
            } elseif ($param['type'] == \PDO::PARAM_INT) {
                $value = (int)$param['value'];
            } elseif ($param['type'] == \PDO::PARAM_BOOL) {
                $value = ($param['value'] ? 'true' : 'false');
            } else {
                $value = 'data';
            }

            if ($this->prettyPrint) {
                $value = sprintf(
                    '<abbr title="%s" data-escaped="%s">%s</abbr>',
                    htmlspecialchars($value),
                    $param['type'] == \PDO::PARAM_STR ? sprintf("'%s'", addslashes($param['value'])) : $value,
                    is_int($key) ? '&#63;' : $key
                );
            }

            if (is_int($key)) {
                if (($pos = strpos($query, '?')) !== false) {
                    $query = substr($query, 0, $pos) . $value . substr($query, $pos + 1);
                }
            } else {
                $query = str_replace($key, $value, $query);
            }
        }

        return $query;
    }

    private function getBacktracer()
    {
        if (!isset($this->backtracer)) {
            $this->backtracer = new Backtracer();
        }

        return $this->backtracer;
    }

    private function _setQueryLogging($flag)
    {
        $this->queryLogging = (bool)$flag;

        return $this;
    }

    private function addExplain()
    {
        $this->disableLogging();
        foreach ($this->log as &$row) {
            if (isset($row['explain'])) {
                continue;
            }

            if (strtolower(substr(ltrim($row['query'], " \t\r\n("), 0, 7)) == 'select ') {
                $stmt = $this->pdo->prepare('explain extended ' . $row['query']);
                $params = [];
                if (!empty($row['params'])) {
                    foreach ($row['params'] as $key => $param) {
                        if (is_int($key)) {
                            $params[] = $param['value'];
                        } else {
                            $params[$key] = $param['value'];
                        }
                    }
                }
                $stmt->execute($params);
                $explain = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $row['explain'] = $explain;
                $complexity = 0;
                foreach ($explain as $explainRow) {
                    $complexity += 1 - $explainRow['filtered'] / 100;
                }
                $row['cost'] = ceil($complexity * 4);
            } else {
                $row['explain'] = null;
                $row['cost'] = null;
            }
        }
        $this->enableLogging();
    }

    private function disableLogging()
    {
        $this->statementClass = $this->pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
    }

    private function enableLogging()
    {
        $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $this->statementClass);
    }
}
