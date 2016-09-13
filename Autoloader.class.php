<?php 
/**
 * 自动加载类
 *
 */

class AutoLoader
{
    /**
     * autoload class
     * 
     * @param string $className
     * @return boolean
     */
    public static function loadClass($className)
    {
        //去除左侧根目录命名空间
        $className = ltrim($className, '\\');
        //扩展目录
        if (strpos($className, 'Util') !== false) {
            $filePath = self::toPath($className);
            $filePath = str_replace('Util', PHP_PLUGINS_ROOT, $filePath);
            self::loadFile($filePath);
        }
    }

    /**
     * convert class name to file path
     * 
     * @param string $className
     * @return mixed
     */
    private static function toPath($className)
    {
        return str_replace(array('\\'), DIRECTORY_SEPARATOR, $className) . '.class.php';
    }
    
    /**
     * load file with file is exists test
     * 
     * @param string $path
     * @return boolean
     */
    public static function loadFile($path)
    {
        if(!file_exists($path)) {
            return false;
        }

        require_once $path;
    }
}
