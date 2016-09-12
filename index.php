<?php
    //引入常量配置文件
    require_once('./Constant.php');
    //引入自动加载文件
    require_once('./AutoLoader.class.php');
    spl_autoload_register(array('AutoLoader', 'loadClass'));

    use Util\Cachelib;
    //创建redis实例
    $redisDO = Cachelib::init('Redis');
    for ($i = 0; $i <= 5000; $i++) {
        $a = $redisDO->set('goodtime', 10086, 60);
        // $redisDO->incr('goodtime', 2);
    }

    $a = $redisDO->get('goodtime');
    echo $a . "\n";

    $redisDO = Cachelib::init('Redis');
    for ($i = 0; $i <= 20000; $i++) {
        $a = $redisDO->set('goodtime', 10086, 60);
        // $redisDO->incr('goodtime', 2);
    }
    $redisDO->set('goodtime', '10086', 60);
    $a = $redisDO->get('goodtime');
    echo $a . "\n";
?>