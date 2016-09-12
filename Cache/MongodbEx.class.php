<?php
/*
 * mongo operate methods class
 * @author huzikan@zbj.com
 */

namespace Util\Cache;
use Util;

class MongodbEx
{
    /**
     * mogo client instance
     *
     * @var pbject
     */
    private $_mongoClient;

    /**
     * current collection
     *
     * @var object
     */
    private $_collection;

    /**
     * current database
     *
     * @var object
     */
    private $_database;

    /**
     * current query primarty key
     *
     * @var object
     */
    private $_id;

    /**
     * support operator object
     *
     * @var array
     */
    private $_operate;

    /**
     * save the error info
     *
     * @var array
     */
    protected $_error;

    /**
     * mongo server config
     *
     * @var array
     */
    static public $_config;
    
    /**
     * the $type operate set
     *
     * @var array
     */
    private $_typeSet = array(
        //浮点
        'double' => 1,
        //字符串
        'string' => 2,
        //对象
        'object' => 3,
        //数组
        'array'  => 4,
        //布尔值
        'bool'   => 8,
        //日期
        'date'   => 9,
        //整形
        'int'    => 16,
        //长整
        'long'   => 18
    );

    /**
     * the connect timeout config
     */
    const CONNECT_TIMEOUT = 5000;

    /**
     * replicaSet name 
     */
    const REPLICASET_NAME = '';

    public function __construct($database, $collection)
    {
        try {
            if (empty($database) || empty($collection)) {
                throw new \Exception('please set database or collection', -1);
            }
            if (!extension_loaded('mongo')) {
                throw new \Exception('please install mongo extension', -1);
            }
            if (empty(self::$_config[$database])) {
                $config = array('host' => '127.0.0.1', 'port' => 27017);
            } else {
                $config = self::$_config[$database];
            }
            //初始化连接对象
            $mongoUrl = "mongodb://" . $config['host'] . ':' . $config['port'] . '/' . $database;
            //设置连接账号
            if (!empty($config['username']) && !empty($config['password'])) {
                $option['username'] = $config['username'];
                $option['password'] = $config['password'];
            }
            //是否启用连接集群
            if (self::REPLICASET_NAME != '') {
                $option['replicaSet'] = self::REPLICASET_NAME;
            }

            //连接操作时间
            $option['connectTimeoutMS'] = self::CONNECT_TIMEOUT;
            $this->_mongoClient = new \MongoClient($mongoUrl, $option);
            $this->_database = $this->_mongoClient->{$database};
            $this->_collection = $this->_database->{$collection};
        } catch (\Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }
    }

    /**
     * 初始化连接配置
     */
    static public function init()
    {
        //获取配置文件数据
        if (!empty(self::$_config)) {
            return true;
        }
        $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'cache.ini';
        $configData = parse_ini_file(realpath($configFile), true);
        $config = $configData['mongo'];
        
        //获取配置项
        foreach ($config as $key => $value) {
            $row = str_replace(':', '=', $value);
            $row = str_replace(',', '&', $row);
            parse_str($row, $configList);
            if (empty($configList)) {
                continue;
            }
            self::$_config[$key] = $configList;
        }
    }

    /**
     * 恢复查询变量状态
     */
    public function clear() {
        $this->_id = '';
        $this->_operate = array();
    }

    /**
     * 设置错误信息
     * @param  string $msg     错误信息
     * @param  int    $code    错误码
     */
    public function setError($msg, $code = 0)
    {
        //重写服务连接错误
        $this->_error['msg'] = $msg;
        $this->_error['code'] = $code;
        //清除查询设置
        $this->clear();
    }

    /**
     * 获取错误信息
     * @param  string $type 信息类型
     * @return mixed
     */
    public function getError($type = "msg")
    {
        return $this->_error[$type];
    }

    /**
     * 获取数据对象主键ID
     *
     * @param  object $item   数据项
     * @return string         主键ID
     */
    public function getId($item)
    {
        if (empty($item['_id'])) {
            return false;
        }

        if ($item['_id'] instanceof \MongoId) {
            return $item['_id']->{'$id'};
        }
    }

    /**
     * 设置当前查询主键
     *
     * @param string $id 主键ID
     */
    public function setId($id)
    {
        if (!empty($id)) {
            $this->_id = new \MongoId($id);
        }
    }

    /**
     * 魔术方法支持连贯操作对象
     */
    public function __call($method, $args)
    {
        switch ($method) {
            //字段查询
            case 'field':
                $this->_operate['field'] =  $args[0];
                break;
            //分页查询
            case 'limit':
                $this->_operate['limit'] =  $args[0];
                break;
            //当前页数
            case 'page' :
                $this->_operate['page'] =  $args[0];
                break;
            //聚合操作匹配条件
            case 'match':
                $this->_operate['match'] =  $args[0];
             //排序操作
            case 'order':
                $orderStr = $args[0];
                $orderArr = explode(',', $orderStr);
                $order = array();
                foreach ($orderArr as $item) {
                    preg_match('/(\w+)\s+(\w+)/', $item, $match);
                    $order[$match[1]] = $match[2] == 'desc' ? -1 : 1;
                }
                $this->_operate['order'] =  $order;
                break;
            //分组操作
            case 'group':
                $group['_id'] = '$' . $args[0];
                //是否执行count操作
                $isCount = $args[1];
                if ($isCount) {
                    $group['count']['$sum'] = 1; 
                }
                $this->_operate['group'] =  $group;
                break;
            default:
                # code...
                break;
        }

        return $this;
    }

    /**
     * 添加操作
     * @param  array $data          添加数据集 
     * @param  bool  $writeConcerns 重复写入限制(false为限制)
     * @return bool                 操作结果
     */
    public function insert($data, $writeConcern = true)
    {
        try {
            $_option['w'] = (int)$writeConcern;
            $resultSet = $this->_collection->insert($data, $_option);
            if ($resultSet['ok'] != 1) {
                throw new \Exception($resultSet['errmsg'], -1);
            }
        } catch (\Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }

        return true;
    }

    /**
     * 查询数据集合
     *
     * @param  array  $condition 查询条件
     * @param  string $fileds    查询的字段值
     * @param  bool   $keyFlag   是否返回主键_id
     * @return object            MongoCursor结果对象
     */
    public function select($condition, $fields = '', $keyFlag = true)
    {
        //查询字段(连贯操作设置覆盖参数)
        if (!empty($this->_operate['field'])) {
            $fields = $this->_operate['field'];
        }
        $_fields = array();
        if (!empty($fields)) {
            $_fields = is_array($fields) ? $fields : explode(',', $fields);
        }
        //排序操作
        $_order = array();
        if (!empty($this->_operate['order'])) {
            $_order  = $this->_operate['order'];
        }
        //limit操作
        $_limit = 0;
        if ($this->_operate['limit'] > 0) {
            $_limit  = $this->_operate['limit'];
        }
        //分页操作
        $_skip = 0;
        if ($this->_operate['page'] > 0) {
            $page  = $this->_operate['page'];
            $limit = $this->_operate['limit'] ? $this->_operate['limit'] : 10;
            $_skip  = $limit * ($page - 1);
        }
        try {
            //转换查询参数
            $_cond = $this->parseWhere($condition);
            //添加设置主键
            if ($this->_id instanceof \MongoId) {
                $_cond['_id'] = $this->_id;
            }
            //返回的结果为MongoCursor对象
            $cursor = $this->_collection->find($_cond, $_fields)->skip($_skip)->limit($_limit)->sort($_order);
            if (!$keyFlag) {
                $cursor->fields(array('_id' => false));
            }
            $result = $this->_convertResult($cursor);
            //获取当前查询结果总数
            if ($this->_limit > 0) {
                $cursorAll = $this->_collection->find($_cond, $_fields);
                $result['totalRecord']  = $cursorAll->count();
            } else {
                $result['totalRecord']  = $cursor->count();
            }
        } catch (Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }
        
        $this->clear();
        return $result;
    }

    /**
     * 转换对象集查询结果
     *
     * @param  MongoCursor $cursor 结果集对象
     * @return array               结果集
     */
    private function _convertResult($cursor)
    {
        $cursorInfo = $cursor->info();
        $pageIndex = 0;
        if ($cursorInfo['limit'] > 0) {
            $pageIndex = $cursorInfo['skip'] / $cursorInfo['limit'] + 1;
        }
        $result = array(
            'list'        => iterator_to_array($cursor),
            //分页大小
            'pageSize'    => $cursorInfo['limit'],
            //当前页数
            'pageIndex'   => $pageIndex
        );

        return $result;
    }

    /**
     * 查询单条数据集
     *
     * @param  array  $condition 查询条件
     * @param  string $fileds    查询的字段值
     * @param  string $orderBy   排序条件
     * @return array             数据集信息
     */
    public function selectOne($condition, $fileds = '', $orderBy = '')
    {
        //查询字段(连贯操作设置覆盖参数)
        if (!empty($this->_operate['filed'])) {
            $fileds = $this->_operate['filed'];
        }
        $_fileds = array();
        if (!empty($fileds)) {
            $_fileds = is_array($fileds) ? $fileds : explode(',', $fileds);
        }
        //排序操作
        $_order = array();
        if (!empty($this->_operate['order'])) {
            $_order  = $this->_operate['order'];
        }
        try {
            //转换查询参数
            $_cond = $this->parseWhere($condition);
            //添加设置主键
            if ($this->_id instanceof \MongoId) {
                $_cond['_id'] = $this->_id;
            }
            $item = $this->_collection->findOne($_cond, $_fileds)->sort($_order);           
        } catch (Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }

        $this->clear();
        return $item;
    }

    /**
     * 更新数据对象
     *
     * @param  array  $condition  更新条件
     * @param  array  $updateData 更新数据集
     * @param  array  $option     可选参数集
     * @return bool               更新结果
     */
    public function update($condition, $updateData, $option = array())
    {
        //没有符合条件的数据集时是否新建数据集
        $_option['upsert']   = !empty($option['upsert']) ? $option['upsert'] : false;
        //是否更新所有满足条件的数据集(false为只更新第一条)
        $_option['multiple'] = !empty($option['multiple']) ? $option['multiple'] : true;
        //新增数据集时是否允许重复写入(0为允许)
        $_option['w']        = !empty($option['writeConcern']) ? $option['writeConcern'] : 1;

        $_updateFields['$set'] = $updateData;
        //转换查询参数
        $_cond = $this->parseWhere($condition);
        //添加设置主键
        if ($this->_id instanceof \MongoId) {
            $_cond['_id'] = $this->_id;
        }

        try {
            //显示更新后的文档
            $resultSet = $this->_collection->update($_cond, $_updateFields, $_option);
            if ($resultSet['ok'] != 1) {
                throw new \Exception($resultSet['errmsg'], -1);
            }
        } catch (\Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }

        $this->clear();
        return true;
    }

    /**
     * 删除数据文档对象
     *
     * @param  array   $condition 删除条件
     * @param  bool    $deleteOne 删除单条标识
     * @return bool               删除结果
     */
    public function delete($condition, $deleteOne = false)
    {
        //是否只删除匹配到的第一条数据
        $_option['justOne'] = (bool)$deleteOne;
        try {
            //转换查询参数
            $_cond = $this->parseWhere($condition);
            //添加设置主键
            if ($this->_id instanceof \MongoId) {
                $_cond['_id'] = $this->_id;
            }
            //显示更新后的文档
            $resultSet = $this->_collection->remove($_cond, $_option);
            if ($resultSet['ok'] != 1) {
                throw new \Exception($resultSet['errmsg'], -1);
            }
        } catch (\Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }

        return true;
    }

    /**
     * 去重查询操作(不支持连贯操作)
     *
     * @param  string $field    去重字段
     * @param  array  $condition 查询条件
     * @return array             字段去重结果集
     */
    public function distinct($field, $condition)
    {
        try {
            //转换查询参数
            $_cond = $this->parseWhere($condition);
            $item = $this->_collection->distinct($field, $_cond);
        } catch (\Exception $e) {
            $this->setError($e->getMessage(), $e->getCode());
            return false;
        }

        return $item;
    }

    /**
     * 支持mongoDB聚合操作(仅支持连贯操作)
     *
     * @return array          聚合结果集
     */
    public function aggregate()
    {
        //匹配参数
        if (!empty($this->_operate['match'])) {
            $_match = $this->parseWhere($this->_operate['match']);
            $_aggregate[]['$match'] = $_match;
        }
        //limit操作
        if ($this->_operate['limit'] > 0) {
            $_limit  = $this->_operate['limit'];
            $_aggregate[]['$limit'] = $_limit;
        }
        //分页操作
        if ($this->_operate['page'] > 0) {
            $page  = $this->_operate['page'];
            $limit = $this->_operate['limit'] ? $this->_operate['limit'] : 10;
            $_skip  = $limit * ($page - 1);
            $_aggregate[]['$skip'] = $_skip;
        }
        //排序操作
        if (!empty($this->_operate['order'])) {
            $_order  = $this->_operate['order'];
            $_aggregate[]['$sort'] = $_order;
        }
        //分组操作
        if (!empty($this->_operate['group'])) {
            $_group = $this->_operate['group'];
            //查询字段
            if (!empty($this->_operate['field'])) {
                $_fields = explode(',', $this->_operate['field']);
                foreach ($_fields as $value) {
                   $_group[$value]['$addToSet'] = '$' . $value; 
                }
            }
            $_aggregate[]['$group'] = $_group;
        }
        //聚合查询
        $resultSet = $this->_collection->aggregate($_aggregate);
        //数据转换
        array_walk($resultSet['result'], array($this, 'convertStruct'));
        
        $this->clear();
        return $resultSet['result'];
    }

    /**
     *  转换返回数据结构
     *
     * @param  array    $item 数据项
     * @param  string   $key  键值
     * @return array          转换数据项
     */
    private function convertStruct(&$item, $key)
    {
        $_item = array();
        foreach ($item as $k => $v) {
            if (!in_array($k, array('_id', 'count'))) {
                $_item[$k] = $v[0];
            } else if ($k != '_id') {
                $_item[$k] = $v;
            }
        }
        $item = $_item;
    }

    /**
     * 查询条件分析
     * 
     * @param  mixed  $condition
     * @return array
     */
    protected function parseWhere($condition)
    {
        $query = array();
        if (empty($condition)) {
            return $query;
        }

        foreach ($condition as $key => $val) {
            // 查询字段的安全过滤
            if (!preg_match('/^[a-zA-Z0-9._\|\&\-]+$/', trim($key))) {
                throw new \Exception("invalid query fileds", -1);
            }
            //处理or值操作
            if ($key == 'or') {
                $orCond = array();
                foreach ($val as $cond) {
                    $query['$or'][] = $this->parseWhere($cond);
                }
            } else if (is_array($val)) {
                $andCond = array();
                foreach ($val as $oper => $item) {
                    //正则查询支持
                    if ($oper == 'like' || $oper == 'regex') {
                        $regexDO = new \MongoRegex($item);
                        $query[$key]['$regex'] = $regexDO->regex;
                        $query[$key]['$options'] = $regexDO->flags;
                    } else {
                        $andCond = $this->parseWhereItem($oper, $item);
                        $query[$key][$andCond['oper']] = $andCond['exp'];
                    }
                }
            //等值查询
            } else {
                $query[$key] = $val;
            }
        }

        return $query;
    }

    /**
     * 查询条件子单元分析
     * 
     * @param  string $key  关键字
     * @param  mixed  $val  表达式
     * @return array        分析结果 
     */
    protected function parseWhereItem($key, $val)
    {
        $condArr = array();
        switch ($key) {
            //比较操作符('ne'=>'等于', 'gt'=>'大于', 'lt'=>'小于', 'lte'=>'小于等于', 'gte'=>'大于等于')
            case 'ne' :
            case 'gt' :
            case 'lt' :
            case 'lte':
            case 'gte':
                $condArr['oper'] = '$' . $key;
                $condArr['exp'] = $val;
                break;
            //IN NIN运算
            case 'in'  :
            case 'nin' :
                $condArr['oper'] = '$' . $key;
                $condArr['exp'] = is_string($val) ? explode(',', $val) : $val;
                break;
            //字段类型限制
            case 'type': 
                $condArr['oper'] = '$' . $key;
                $condArr['exp'] = $this->_typeSet[$val];
                break;
            default:
                # code...
                break;
        }

        return $condArr;
    }
}
