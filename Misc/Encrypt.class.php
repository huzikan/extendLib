<?php 
/**
 * 杂项功能函数集
 */

namespace Util\Misc;
use Util;

class Encrypt
{
    /**
     * RSA签名
     * $data待签名数据
     * 签名用商户私钥，必须是没有经过pkcs8转换的私钥
     * 最后的签名，需要用base64编码
     * return Sign签名
     */
    function rsaSign($data)
    {
        //读取私钥文件
        $priKey = file_get_contents('./app/config/rsa_private_key.pem');

        //转换为openssl密钥，必须是没有经过pkcs8转换的私钥
        $pkeyid = openssl_get_privatekey($priKey);

        //调用openssl内置签名方法，生成签名$sign
        openssl_sign($data, $sign, $pkeyid);
        
        //释放资源
        openssl_free_key($pkeyid);
        
        //base64编码
        $sign = base64_encode($sign);
        
        return $sign;
    }

    /**
     * RSA验签
     * $data待签名数据
     * $sign需要验签的签名
     * 验签用支付宝公钥
     * return 验签是否通过 bool值
     */
    private function rsaVerify($data, $sign)
    {
        if (zbj_lib_Constant::RUNTIME_ENVIRONMENT == 'product') {
            $pubFile = './app/config/ws_public_key.pem';
        } else {
            $pubFile = './app/config/ws_public_key_test.pem';
        }

        //读取网商公钥文件
        $pubKey = file_get_contents($pubFile);
        
        //转换为openssl格式密钥
        $pkeyid = openssl_get_publickey($pubKey);

        //调用openssl内置方法验签，返回bool值
        $result = (bool)openssl_verify($data, base64_decode($sign), $pkeyid);

        //释放资源
        openssl_free_key($pkeyid);
        
        return $result;
    }

    /**
     * 过滤中文转义的json结构
     * @param array $arr        转义数组
     */
    public static function jsonEncode($arr)
    {
        $is_list = false;
        // Find out if the given array is a numerical array
        $keys = array_keys($arr);
        $max_length = count($arr) - 1;
        // See if the first key is 0 and last key is length - 1
        if (($keys [0] === 0) && ($keys [$max_length] === $max_length)) {
            $is_list = true;
            for ($i = 0; $i < count($keys); $i++) {
                if ($i != $keys[$i]) {
                    $is_list = false;
                    break;
                }
            }
        }

        $parts = array();
        foreach ($arr as $key => $value) {
            if (is_array ($value)) {
                if ($is_list) {
                    $parts[] = self::json_encode($value);
                } else {
                    $parts[] = '"' . $key . '":' . self::json_encode($value);
                }
            } else {
                $str = '';
                if (!$is_list) {
                    $str = '"' . $key . '":';
                }
                // Custom handling for multiple data types
                if ((is_int($value) || is_float($value)) && $value < 2000000000) {
                    $str .= $value;
                } elseif ($value === false) {
                    $str .= 'false';
                } elseif ($value === true) {
                    $str .= 'true';
                } else {
                    $str .= '"' . addslashes($value) . '"';
                }

                $parts[] = $str;
            }
        }
        $json = implode(',', $parts);
        
        if ($is_list) {
            return '[' . $json . ']'; 
        }

        return '{' . $json . '}';
    }

    /**
     * 获取API文档注释
     */
    public function pageGetApiDocComent($className)
    {
        $apiObject = new ReflectionClass($className);
        $methods = $apiObject->getMethods(ReflectionProperty::IS_PUBLIC);  
        $docComent = array();
        if (!is_dir('./classApiDoc')) {
            mkdir('./classApiDoc');
        }
        $headStr = "class {$className} {\n";
        file_put_contents('./classApiDoc', $headStr);
        if (!empty($methods)) {
            foreach ($methods as $method) {
                $funcName = $method->getName();
                if (preg_match('/__construct/', $funcName)) {
                    continue;
                }
                $comment = $method->getDocComment();
                $argruments = $method->getParameters();
                $fromatSpace = preg_replace('/\w/', ' ', $funcName);
                $fromatSpace .= ' ';
                $argrumentStr = implode(",\n\t{$fromatSpace}", $argruments);
                file_put_contents('./classApiDoc', "\t " . $comment . "\n", FILE_APPEND | LOCK_EX);
                $function = "$funcName($argrumentStr)\n";
                file_put_contents('./classApiDoc', "\t" . $function . "\n", FILE_APPEND | LOCK_EX);

                $docComent[$className][] = array(
                    'funcName'   => $funcName,
                    'comment'    => $comment,
                    'argruments' => $argrumentStr,
                );
            }
        }

        file_put_contents('./classApiDoc', '}', FILE_APPEND | LOCK_EX);
    }

    /**
     * 创建自动提交表单
     * @param  array  $params  表单元数据
     * @param  string $reqUrl  表单提交地址
     * @param  string $charset 编码方式
     * @return string $html    表单页面         
     */
    public static function createAutoFormHtml($formParam, $reqUrl, $charset = 'UTF-8')
    {
        //确定编码类型
        $encodeType = !empty($charset) ? $charset : 'UTF-8';
        $html = "
            <html>
            <head>
                <meta http-equiv=\"Content-Type\" content=\"text/html; charset={$encodeType}\" />
            </head>
            <body onload=\"javascript:document.pay_form.submit();\">
                <form id=\"pay_form\" name=\"pay_form\" action=\"{$reqUrl}\" method=\"post\">
        ";
        //添加表单元数据
        if (!empty($formParam)) {
            foreach ($formParam as $key => $value) {
                $html .= "<input type=\"hidden\" name=\"{$key}\" id=\"{$key}\" value=\"{$value}\" />\n";
            }
        }

        $html .= "
                <!-- <input type=\"submit\" type=\"hidden\">-->
                </form>
            </body>
            </html>
        ";

        return $html;
    }

    /**
     * base64加密变种
     *
     * 建议所有主键参数都使用此方法进行简单加密，防止数据被遍历 重写的base64_encode 用于对称加密
     *
     * @param string $str
     * @return string
     */
    public static function base64Encode($str)
    {
        $str_arr = str_split($str);//分成单个字符
        $mod = count($str_arr) % 3;//不够3个
        if ($mod > 0) {
            $bmod = 3 - $mod;
        } //计算需要补多少才能够3个
        for ($i = 0; $i < $bmod; $i++) {//不够3个补\0
            $str_arr[] = "\0";
        }
        //字符串转换为二进制
        foreach ($str_arr as $v) {
            $bit .= str_pad(decbin(ord($v)), 8, '0', STR_PAD_LEFT);
        }
        $len = ceil(strlen($bit) / 6);
        $base64_config = self::getBase64Config();
        //把二进制按照六位进行转换为base64索引
        for ($i = 0; $i < $len - $bmod; $i++) {
            $enstr .= $base64_config[bindec(str_pad(substr($bit, $i * 6, 6), 8, 0, STR_PAD_LEFT))];
        }
        //补=号
        for ($buf = 1; $buf <= $bmod; $buf++) {
            $enstr .= "=";
        }

        return $enstr;
    }

    /**
     * base64解密变种
     *
     * 重写的base64_decode 用于对称加密
     *
     * @param  string $str
     * @return string
     */
    public static function base64Decode($str)
    {
        $buf = substr_count($str, '=');//统计=个数
        $str_arr = str_split($str);//分成单个字符
        $base64_config = self::getBase64Config();
        //转换为二进制字符串
        foreach ($str_arr as $v) {
            $index = array_search($v, $base64_config);
            $index = $index ? $index : "\0";
            $bit .= str_pad(decbin($index), 6, 0, STR_PAD_LEFT);
        }
        $len = ceil(strlen($bit) / 8);
        //二进制转换为ASCII，在转换为字符串
        for ($i = 0; $i < $len - $buf; $i++) {
            $destr .= chr(bindec(str_pad(substr($bit, $i * 8, 8), 8, 0, STR_PAD_LEFT)));
        }

        return $destr;
    }

    /**
     * 表单提交 curlRequest
     *
     * @param  string $url 请求的url
     * @param  array|string $data 请求的参数
     * @param  string $method 请求的方法
     * @param  int $timeout 请求超时的时间
     * @param  bool|true $sync 同步还是异步
     * @return mixed|string
     */
    public static function curlRequest($url, $data = array(), $method = 'GET', $timeout = 5, $sync = true)
    {
        $ch = curl_init();
        if (is_array($data)) {
            $data = http_build_query($data);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $sync);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_NOBODY, !$sync);

        if (strtoupper($method) == 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            if (strtoupper($method) == 'GET') {
                curl_setopt($ch, CURLOPT_URL, empty($data) ? $url : $url . '?' . $data);
            } else { //PUT方法支持
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }
}