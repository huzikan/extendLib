<?php
/**
 * Date     :2015-09-11
 * Contact  :huzikan@zbj.com  
 */

namespace Util\Misc;
use Util;

class FileEx
{
    /**
     * var fileName
     */
    public $fileName;

    public function __construct($fileName)
    {
        $this->fileName = $fileName;
    }

    public function downLoadFile($file_url)
    {
        if (empty($file_url)) {
            return;
        }

        //获取文件的下载地址或本地文件地址
        $file_url = zbj_lib_Constant::UPFILEURLOLD . $file_url;
        if (file_exists($file_url)) {
            //指定当前页面内容为文件下载
            header('Content-Description: File Transfer');
            //指定当前页面内容可接收的文件类型(application/octet-stream:全类型)
            header('Content-Type: application/octet-stream');
            //指定文件下载的文件名
            header("Content-Disposition: attachment; filename=" . $this->fileName ? $this->fileName : uniqid());
            //指定内容传输为二进制方式
            header('Content-Transfer-Encoding: binary');
            //指定页面不进行缓存
            header("Expires: 0");
            //强制从服务器读取数据既不进行页面缓存
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header('Pragma: public');
            //指定文件内容大小
            header('Content-Length: ' . filesize($file_url));
            //清空当前缓冲区缓存  
            ob_clean();
            //获取下载的文件内容
            readfile($file_url);
            exit;
        }
    }
}
