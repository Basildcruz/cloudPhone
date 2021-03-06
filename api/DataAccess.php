<?php

trait DataAccess
{

    private static $types = array();

    public static function GetTableType()
    {
        if (count(self::$types) == 0) {
            $r = Database::GetInstance()->query('SELECT * FROM ' . self::Mark(self::$tableName) . ' LIMIT 1', PDO::FETCH_ASSOC);
            if ($r) {
                $columnCount = $r->columnCount();
                for ($i = 0; $i < $columnCount; ++$i) {
                    $metaInfo = $r->getColumnMeta($i);
                    self::$types[$metaInfo['name']] = $metaInfo['native_type'];
                }
            }
        }
    }

    public static function GetTypeByName($columnName) {
        $result = FALSE;
        self::GetTableType();
        if (array_key_exists($columnName, self::$types)) {
            $result = self::$types[$columnName];
        }
        return $result;
    }

    private static function Mark($n)
    {
        //return '`' . $n . '`';
        return '"' . $n . '"';
    }

    private static function GetMarkedColumnNames() {
        $result = array();
        self::GetTableType();
        foreach (self::$types as $key => $typeName) {
            $result[] = self::Mark($key);
        }
        return $result;
    }

    private static function DatabaseQuote($v, $type)
    {
        $result = '';
        if (is_null($v)) {
            $result = 'NULL';
        } else if (is_bool($v)) {
            $result = $v ? 'TRUE' : 'FALSE';
        } else if (is_string($v)) {
            $result = "'" . $v . "'";
        } else {
            $result .= $v;
        }
        if (is_null($v)) {
            $result = 'NULL';
        } else {
            switch ($type) {
                case 'varchar':
                case 'text':
                case 'char':
                    $result = "'" . $v . "'";
                    break;
            }
        }
        return $result;
    }

    private function GetNameValues() {
        $result = array();
        self::GetTableType(self::$tableName);
        foreach (self::$types as $key => $typeName) {
            //if (array_key_exists($key, (array)$this)) {
                $result[self::Mark($key)] = self::DatabaseQuote($this->$key, $typeName);
            //}
        }
        return $result;
    }

    public function GetSetItems() {
        $result = array();
        $nameValues = $this->GetNameValues();
        foreach ($nameValues as $name => $value) {
            $result[] = $name . ' = ' . $value;
        }
        return $result;
    }

    public function FillSelf($row)
    {
        foreach ($this as $key => $value) {
            if (array_key_exists($key, $row)) {
                $type = self::GetTypeByName($key);
                $value = $row[$key];
                if (!is_string($value) && ($value == NULL)) {
                    $this->$key = NULL;
                } else {
                    switch ($type) {
                        case 'int2':
                        case 'int4':
                        case 'int8':
                            $value = intval($value);
                            break;
                        default:
                            break;
                    }
                    $this->$key = $value;
                }
            }
        }
    }

    private $id = 0;

    public function GetId()
    {
        return $this->id;
    }

    public function SetId($id)
    {
        $this->id = $id;
    }

    public static function ConvertJsonToWhere($filter)
    {
        $where = array();
        $filterJson = json_decode($filter);
        //print_r($filterJson);
        foreach ($filterJson as $key => $value) {
            if (is_null($value)) {
                $where[] = self::Mark($key) . ' IS NULL';
            } else {
                $where[] = self::Mark($key) . ' = ' . self::DatabaseQuote($value, self::GetTypeByName($key));
            }
        }
        //print_r($where);
        return implode(' AND ', $where);
    }

    private static function ConvertJsonToOrderBy($orderBy)
    {
        $orders = array();
        $orderByJson = json_decode($orderBy);
        foreach ($orderByJson as $key => $value) {
            $orders[] = self::Mark($key) . ' ' . strtoupper($value);
        }
        return implode(', ', $orders);
    }

    public static function IsPrimaryKey($v)
    {
        return self::GetOne('id', $v);
    }

    private static function GetOneData($query, $className) {
        $result = FALSE;
        $r = Database::GetInstance()->query($query, PDO::FETCH_ASSOC);
        if ($r) {
            foreach ($r as $row) {
                $item = new $className;
                $item->FillSelf($row);
                $result = $item;
                break;
            }
        }
        return $result;
    }

    private static function GetData($query, $className) {
        $result = array();
        $r = Database::GetInstance()->query($query, PDO::FETCH_ASSOC);
        if ($r) {
            foreach ($r as $row) {
                $item = new $className;
                $item->FillSelf($row);
                $result[] = $item;
            }
        }
        return $result;
    }

    public static function ConstructNameValueFilter($name, $value)
    {
        return self::Mark($name) . ' = ' . self::DatabaseQuote($value, self::GetTypeByName($name));
    }

    public static function GetOne($name, $value)
    {
        $whereClause = ' WHERE ' . self::ConstructNameValueFilter($name, $value);
        $query = 'SELECT ' . implode(', ', self::GetMarkedColumnNames()) . ' FROM ' . self::Mark(self::$tableName) . $whereClause . ' LIMIT 1';
        return self::GetOneData($query, __CLASS__);
    }

    public static function Select($params, $regionExpression)
    {
        $whereClause = '';
        $filter = $params['filter'];
        if (!empty($filter)) {
            $whereClause = ' WHERE ' . self::ConvertJsonToWhere($filter) . ($regionExpression ? ' AND ( ' . $regionExpression . ' )' : '');
        }
        //print $whereClause;
        $orderByClause = '';
        $orderBy = $params['orderBy'];
        if (!empty($orderBy)) {
            $orderByClause = ' ORDER BY ' . self::ConvertJsonToOrderBy($orderBy);
        }
        $pagedClause = '';
        $count = $params['count'];
        $offset = $params['offset'];
        if ($count != -1 && $offset != -1) {
            $pagedClause = ' LIMIT ' . $count . ' OFFSET ' . $offset;
        }
        $query = 'SELECT ' . implode(', ', self::GetMarkedColumnNames()) . ' FROM ' . self::Mark(self::$tableName) . $whereClause . $orderByClause . $pagedClause;
        //print $query;
        return self::GetData($query, __CLASS__);
    }

    public static function ConstructMapFilter($foreignName, $mapTable, $pairName, $pairValue)
    {
        return 'id IN ( SELECT ' . $foreignName . ' FROM ' . $mapTable . ' WHERE ' . $pairName . ' = ' . $pairValue . ' )';
    }

    public static function GetByMap($foreignName, $mapTable, $pairName, $pairValue)
    {
        $whereClause = ' WHERE ' . self::ConstructMapFilter($foreignName, $mapTable, $pairName, $pairValue);
        $query = 'SELECT ' . implode(', ', self::GetMarkedColumnNames()) . ' FROM ' . self::Mark(self::$tableName) . $whereClause;
        //print $query;
        return self::GetData($query, __CLASS__);
    }

    public static function CustomSelect($whereClause)
    {
        $query = 'SELECT ' . implode(', ', self::GetMarkedColumnNames()) . ' FROM ' . self::Mark(self::$tableName) . $whereClause;
        //print $query;
        return self::GetData($query, __CLASS__);
    }

    public function Insert()
    {
        if ($this->id == 0) {
            $this->id = IdGenerator::GetNewId();
        }
        $nameValues = $this->GetNameValues();
        $command = 'INSERT INTO ' . self::Mark(self::$tableName) . ' ( ' . implode(', ', array_keys($nameValues)) . ' ) VALUES ( ' . implode(', ', array_values($nameValues)) . ' )';
        Database::GetInstance()->exec($command);
        //if (isset($seqName)) {
        //    $this->id = Database::GetInstance()->lastInsertId($seqName);
        //} else {
        //    $this->id = Database::GetInstance()->lastInsertId();
        //}
        return $this->id;
    }

    public function Delete()
    {
        $command = 'DELETE FROM ' . self::Mark(self::$tableName) . ' WHERE ' . self::ConstructNameValueFilter('id', $this->id);
        return Database::GetInstance()->exec($command);
    }

    public static function BatchDelete($filter)
    {
        $whereClause = '';
        if (!empty($filter)) {
            $whereClause = ' WHERE ' . $filter;
        }
        $command = 'DELETE FROM ' . self::Mark(self::$tableName) . $whereClause;
        return Database::GetInstance()->exec($command);
    }

    public function Update()
    {
        $command = 'UPDATE ' . self::Mark(self::$tableName) . ' SET ' . implode(', ', $this->GetSetItems()) . ' WHERE ' . self::ConstructNameValueFilter('id', $this->id);
        return Database::GetInstance()->exec($command);
    }

    public static function BatchUpdate($value, $filter)
    {
        $whereClause = '';
        if (!empty($filter)) {
            $whereClause = ' WHERE ' . $filter;
        }
        $command = 'UPDATE ' . self::Mark(self::$tableName) . ' SET ' . implode(', ', $value->GetSetItems()) . $whereClause;
        return Database::GetInstance()->exec($command);
    }

}

?>
