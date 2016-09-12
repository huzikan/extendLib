<?php
    //引入常量配置文件
    require_once('./Constant.php');
    //引入自动加载文件
    require_once('./AutoLoader.class.php');
    spl_autoload_register(array('AutoLoader', 'loadClass'));

    use Util\Cachelib;
    //初始化Mongdb配置
    Util\Cache\MongodbEx::init();
    $mongoDO = new Util\Cache\MongodbEx('testDatabase', 'testCollection');
    //插入操作(insert操作插入1W条测试数据)
    for ($i = 0; $i < 10000; $i++) {
        $data = array(
            "autoNo"      => $i,
            "title"       => "php",
            "description" => "世界上最好的语言",
            "url"         => "http://www.runoob.com/mongodb/",
        );
        $res = $mongoDO->insert($data);
    }

    //更新操作(update操作将后5000条记录title变为java)
    $updateCondition['autoNo'] = array("lte"=>5000);
    $updateData['title'] = "java";
    $res = $mongoDO->update($updateCondition, $updateData);

    //查询
    //titile = 'java'
    $selectCondition['title'] = 'java';
    //autoNo < 100 AND autoNo > 10
    $selectCondition['autoNo'] = array("lt"=>100, 'gt'=>10);
    $data = $mongoDO->field("autoNo,title")->page(2)->limit(10)->order("autoNo desc")->select($selectCondition);
    var_dump($data['list']);

    //聚合分组统计操作
    $aggregateCondition['autoNo'] = array("lt"=>5500, 'gt'=>4500);
    $list = $mongoDO->field("_id,title")->group('title', true)->order("autoNo desc")->match($aggregateCondition)->aggregate();
    var_dump($list);

    //删除
    $res = $mongoDO->delete();
?>