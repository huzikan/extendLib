<?php
/**
 * Memcache 操作，支持 Master/Slave 的负载集群
 *
 */

namespace Util\Cache;
use Util;

class MemcacheEx
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
     * @return boolean
     */
    public function connect()
    {
        if (!extension_loaded('memcache')) {
            return false;
        }

        //连接master
        if (!empty($this->_config['master'])) {
            $this->_linkHandle['master'] = new \Memcache;
            if (empty($this->_config['master']['port'])) {
                $this->_config['master']['port'] = 11211;//默认端口号
            }
            $res = $this->_linkHandle['master']->connect($this->_config['master']['host'], $this->_config['master']['port']);
        }

        //连接slave
        if (!empty($this->_config['slave'])) {
            $this->_linkHandle['slave'][$this->_counter] = new \Memcache;
            // 多个连接句柄
            foreach ($this->_config['slave'] as $_config) {
                $res = $this->_linkHandle['slave']->connect($this->_config['master']['host'], $this->_config['master']['port'], 10);
                $this->_counter++;
            }

            return $res;
        }
    }

    /**
     * 读取缓存
     * 
     * @param string $key 缓存变量名
     * @return mixed
     */
    public function get($key)
    {
        // 没有使用M/S
        if (!$this->_isUseCluster) {
            return $this->getMemcache()->get($key);
        }

        // 使用了 M/S
        return $this->_getSlaveMemcache()->get($key);
    }

    /**
     * 写入缓存
     * 
     * @param  string  $key     缓存变量名
     * @param  mixed   $value   存储数据
     * @param  integer $expire  有效时间（秒）
     * @return boolean
     */
    public function set($key, $value, $expire = 0)
    {
        //最大有效期为30天
        $expire = $expire < 2592000 ? $expire : 2592000;
        $res = $this->getMemcache()->set($key, $value, 0, $expire);

        return $res;
    }

    /**
     * 删除缓存
     * 
     * @param string $key 缓存变量名
     * @return boolean
     */
    public function remove($key, $ttl = false)
    {
        if ($ttl === false) {
            $res = $this->getMemcache()->delete($key);
        } else {
            $res = $this->getMemcache()->delete($key, $ttl);
        }

        return $res;
    }

    /**
     * 清除缓存
     * 
     * @return boolean
     */
    public function clear()
    {
        return $this->getMemcache()->flush();
    }

    /**
     * 获取实例对象
     *
     * @param  boolean $isMaster 是否获取主库
     * @return object            实例链接对象 
     */
    public function getMemcache($isMaster = true)
    {
        if ($isMaster) {
            return $this->_linkHandle['master'];
        } else {
            return $this->_getSlaveRedis();
        }
    }

    /**
     * 获取从库对象
     *
     * @return object 实例链接对象
     */
    private function _getSlaveMemcache()
    {
        //TODO
        return true;
    }
}
