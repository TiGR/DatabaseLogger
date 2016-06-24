<?php

namespace TiGR\Database;

class LoggingPDOStatement extends \PDOStatement
{
    private $params;

    public function bindValue($parameter, $value, $data_type = \PDO::PARAM_STR)
    {
        $this->storeParam($parameter, $value, $data_type);

        return parent::bindParam($parameter, $value, $data_type);
    }

    private function storeParam($parameter, &$value, $type = \PDO::PARAM_STR, $length = null)
    {
        $param = ['value' => $value, 'type' => $type];
        if (isset($length)) {
            $param['length'] = $length;
        }
        $this->params[$parameter] = $param;
    }

    public function bindParam($parameter, &$variable, $data_type = \PDO::PARAM_STR, $length = null, $driver_options = null)
    {
        $this->storeParam($parameter, $variable, $data_type, $length);

        parent::bindParam($parameter, $variable, $data_type, $length, $driver_options);
    }

    public function execute($params = null)
    {
        $this->storeParams($params);
        DatabaseLogger::logStart();
        try {
            $result = parent::execute($params);
            DatabaseLogger::logEnd($this->queryString, $this->params);
            return $result;
        } catch (\PDOException $e) {
            DatabaseLogger::logError($this->queryString, $e->getMessage(), $e->getCode(), $this->params);

            throw $e;
        }
    }

    private function storeParams(array $params = null)
    {
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $this->storeParam($key, $value);
            }
        }
    }
}
