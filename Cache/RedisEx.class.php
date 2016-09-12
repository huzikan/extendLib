<?php
/**
 * Redis 操作，支持 Master/Slave 的负载集群
 *
 */

namespace Util\Cache;
use Util;

class RedisEx
{
    /**
     * 是否使用 M/S 的读写集群方案
     *
     * @var bool
     */
    private $_isUseCluster;

    /**
     * Slave 句柄计数器
     *
     * @var integer
     */
    private $_counter = 0;

    /**
     * connect配置
     *
     * @var array
     */
    private $_config;

    /**
     * 服务器连接句柄
     *
     * @var array
     */
    private $_linkHandle;

    /**
     * 是否已初始化连接
     *
     * @var bool
     */
    private $_isConnect = false;

    /**
     * 构造函数
     *
     * @param array $config 配置参数
     */
    public function __construct($config)
    {
        if (!empty($config['slave'])) {
            $this->_isUseCluster = true;
        }
        $this->_config = $config;
        
        if (!$this->_isConnect) {
            $this->connect();
            $this->_isConnect = true;
        }
    }

    /**
     * 连接服务器
     *
     * @param  array $config Redis  服务器配置
     * @param  boolean $isMaster    当前添加的服务器是否为 Master 服务器
     * @return boolean
     */
    public function connect()
    {
        if (!extension_loaded('redis')) {
            return false;
        }
        //连接master
        if (!empty($this->_config['master'])) {
            $this->_linkHandle['master'] = new \Redis;
            if (empty($this->_config['master']['port'])) {
                $this->_config['master']['port'] = 6379;
            }
            $res = $this->_linkHandle['master']->connect($this->_config['master']['host'], $this->_config['master']['port']);
        }

        //连接slave
        if (!empty($this->_config['slave'])) {
            // 多个 Slave 连接
            foreach ($this->_config['slave'] as $_config) {
                $this->_linkHandle['slave'][$this->_counter] = new \Redis();
                $_config['port'] = empty($_config['port']) ? 6379 : $_config['port']; 
                $res = $this->_linkHandle['slave'][$this->_counter]->connect($_config['host'], $_config['port']);
                $this->_counter++;
            }

            return $res;
        }
    }

    /**
     * 关闭连接
     *
     * @param int $flag 关闭选择 0:关闭 Master 1:关闭 Slave 2:关闭所有
     * @return boolean
     */
    public function disconnect($flag = 2)
    {
        switch ($flag) {
            // 关闭 Master
            case 0:
                $this->getRedis()->close();
            break;
            // 关闭 Slave
            case 1:
                for ($i = 0; $i < $this->_counter; ++$i) {
                    $this->_linkHandle['slave'][$i]->close();
                }
            break;
            // 关闭所有
            case 2:
                $this->getRedis()->close();
                for ($i = 0; $i  < $this->_counter; ++$i) {
                    $this->_linkHandle['slave'][$i]->close();
                }
            break;
        }

        return true;
    }

    /**
     * 得到 Redis 原始对象可以有更多的操作
     *
     * @param boolean $isMaster 返回服务器的类型 true:返回Master false:返回Slave
     * @param boolean $slaveOne 返回的Slave选择 true:负载均衡随机返回一个Slave选择 false:返回所有的Slave选择
     * @return redis object
     */
    public function getRedis($isMaster = true, $slaveOne = true)
    {
        // 只返回 Master
        if ($isMaster) {
            return $this->_linkHandle['master'];
        } else {
            return $slaveOne ? $this->_getSlaveRedis() : $this->_linkHandle['slave'];
        }
    }

    /**
     * 写缓存
     *
     * @param string $key 组存KEY
     * @param string $value 缓存值
     * @param int $expire 过期时间， 0:表示无过期时间
     */
    public function set($key, $value, $expire = 0)
    {
        // 永不超时
        if ($expire == 0) {
            $ret = $this->getRedis()->set($key, $value);
        } else {
            $ret = $this->getRedis()->setex($key, $expire, $value);
        }

        return $ret;
    }

    /**
     * 读缓存
     *
     * @param  string $key 缓存KEY,支持一次取多个 $key = array('key1','key2')
     * @return string || boolean  失败返回 false, 成功返回字符串
     */
    public function get($key)
    {
        // 是否一次取多个值
        $func = is_array($key) ? 'mGet' : 'get';
        // 没有使用M/S
        if (!$this->_isUseCluster) {
            return $this->getRedis()->{$func}($key);
        }
        // 使用了 M/S
        return $this->_getSlaveRedis()->{$func}($key);
    }

    /**
     * 条件形式设置缓存，如果 key 不存时就设置，存在时设置失败
     *
     * @param  string $key      缓存KEY
     * @param  string $value    缓存值
     * @return boolean
     */
    public function setnx($key, $value)
    {
        return $this->getRedis()->setnx($key, $value);
    }

    /**
     * 删除缓存
     *
     * @param  string || array $key 缓存KEY，支持单个健:"key1" 或多个健:array('key1','key2')
     * @return int 删除的健的数量
     */
    public function remove($key)
    {
        // $key => "key1" || array('key1','key2')
        return $this->getRedis()->delete($key);
    } 

    /**
     * 值加加操作,类似 ++$i ,如果 key 不存在时自动设置为 0 后进行加加操作
     *
     * @param  string   $key        缓存KEY
     * @param  int      $step       递增步长
     * @return int　             操作结果
     */
    public function incr($key, $step = 1)
    {
        if ($step == 1) {
            return $this->getRedis()->incr($key);
        } else {
            return $this->getRedis()->incrBy($key, $step);
        }
    }

    /**
     * 值减减操作,类似 --$i ,如果 key 不存在时自动设置为 0 后进行减减操作
     *
     * @param  string   $key        缓存KEY
     * @param  int      $step       递增步长
     * @return int　             操作结果
     */
    public function decr($key, $step = 1)
    {
        if ($step == 1) {
            return $this->getRedis()->decr($key);
        } else {
            return $this->getRedis()->decrBy($key, $step);
        }
    }

    /**
     * 清空当前数据库
     *
     * @return boolean
     */
    public function clear()
    {
        return $this->getRedis()->flushDB();
    }

    /**
     * 随机 HASH 得到 Redis Slave 服务器句柄
     *
     * @return redis object
     */
    private function _getSlaveRedis()
    {

        // 就一台 Slave 机直接返回
        if ($this->_counter <= 1) {
            return $this->_linkHandle['slave'][0];
        }
        // 随机 Hash 得到 Slave 的句柄
        // $hash = $this->_hashId(mt_rand(), $this->_counter);

        return $this->_linkHandle['slave'][mt_rand()%2];
    }

    /**
     * 根据ID得到 hash 后 0～m-1 之间的值
     *
     * @param  string  $id
     * @param  int     $m
     * @return int
     */
    private function _hashId($id, $m = 10) {
        //把字符串K转换为 0～m-1 之间的一个值作为对应记录的散列地址
        $k = md5($id);
        $l = strlen($k);
        $b = bin2hex($k);
        $h = 0;
        for ($i = 0; $i < $l; $i++) {
            //相加模式HASH
            $h += substr($b, $i * 2, 2);
        }
        $hash = ($h * 1) % $m;
        
        $this->_slave[$hash]++;

        return $hash;
    }
}
