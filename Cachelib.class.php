<?php
/**
 * NOSQL操作类（redis、memcache、mogodb）
 * 单例模式
 */

namespace Util;

class Cachelib
{
    /**
     * 操作实例对象数组
     *
     * @var array
     */
    public static $_cacheHandle;
    
    /**
     * 缓存驱动配置
     *
     * @var array
     */
    public static $_config;

    /**
     * nosql namespace prefix
     */
    const PRE_NAMESPACE = 'Util\\Cache\\';

    /**
     * 初始化缓存实例对象
     *
     * @param  string $cacheType 实例类型
     * @return object            实例对象
     */
    public static function init($cacheType)
    {
        $cacheClass = self::PRE_NAMESPACE . $cacheType . 'Ex';
        if (!class_exists($cacheClass)) {
            return false;
        }

        if (!self::$_cacheHandle[$cacheType] instanceof $cacheClass) {
            $config = self::getConfig($cacheType);
            self::$_cacheHandle[$cacheType] =  new $cacheClass($config);
        }
        
        return self::$_cacheHandle[$cacheType];
    }

    /**
     * 获取配置文件
     *
     * @param  string $cacheType 对象类型
     * @return array             配置项
     */
    private static function getConfig($cacheType)
    {
        //获取配置文件数据
        if (empty(self::$_config)) {
            $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR . 'cache.ini';
            $configData = parse_ini_file(realpath($configFile), true);
            self::$_config = $configData;
        }

        $configType = strtolower($cacheType);
        if (empty(self::$_config[$configType])) {
            return false;
        }

        //获取配置项
        foreach (self::$_config[$configType] as $key => $value) {
            $row = str_replace(':', '=', $value);
            $row = str_replace(',', '&', $row);
            parse_str($row, $configList);
            if (empty($configList)) {
                continue;
            }
            
            //是否支持分布式集群
            if (strpos($key, 'master') !== false) {
                $config['master'] = $configList;
            } elseif (strpos($key, 'slave') !== false) {
                $config['slave'][] = $configList;
            }
        }

        return $config;
    }
}