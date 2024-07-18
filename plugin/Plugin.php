<?php

namespace TypechoPlugin\CloudflareR2Plugin;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/vendor/aws/aws-autoloader.php';

//插件名
if (!defined('pluginName')) {
    define('pluginName', 'CloudflareR2Plugin');
}

/**
 * 支持上传文件到Cloudflare R2的Typeecho插件
 *
 * @package CloudflareR2Typecho
 * @author DeerShark
 * @version %version%
 * @link https://github.com/DeerShark/CloudflareR2Typecho
 */
class Plugin implements PluginInterface
{
    #上传文件目录
    const UPLOAD_DIR = 'usr/uploads';

    /**
     * Activate plugin method, if activated failed, throw exception will disable this plugin.
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle = array(pluginName . '_Plugin', 'uploadHandle');
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = array(pluginName . '_Plugin', 'attachmentHandle');
        \Typecho\Plugin::factory('Widget_Upload')->attachmentDataHandle = array(pluginName . '_Plugin', 'attachmentDataHandle');


        return _t('Cloudflare R2 插件已成功激活');
    }

    /**
     * Deactivate plugin method, if deactivated failed, throw exception will enable this plugin.
     */
    public static function deactivate()
    {
        return _t('Cloudflare R2 插件已被禁用');
    }

    /**
     * Plugin config panel render method.
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $account_id = new Text('account_id', NULL, '', _t('Account ID'), _t('请输入您的Cloudflare R2 Account ID'));
        $form->addInput($account_id->addRule('required', _t('您必须填写Cloudflare R2 Account ID')));

        $access_key_id = new Text('access_key_id', NULL, '', _t('Access Key ID'), _t('请输入您的Cloudflare R2 Access Key ID'));
        $form->addInput($access_key_id->addRule('required', _t('您必须填写Cloudflare R2 Access Key ID')));

        $access_key_secret = new Text('access_key_secret', NULL, '', _t('Access Key Secret'), _t('请输入您的Cloudflare R2 Access Key Secret'));
        $form->addInput($access_key_secret->addRule('required', _t('您必须填写Cloudflare R2 Access Key Secret')));

        $bucket = new Text('bucket', NULL, '', _t('Bucket'), _t('请输入您的Cloudflare R2 Bucket名称'));
        $form->addInput($bucket->addRule('required', _t('您必须填写Cloudflare R2 Bucket名称')));

        $access_domain = new Text('access_domain', NULL, '', _t('访问域名'), _t('可以是自定义域（推荐）也可以是R2.dev 子域（不推荐）'));
        $form->addInput($access_domain->addRule('required', _t('您必须填写Cloudflare R2 公开访问域名')));
    }

    /**
     * Plugin personal config panel render method.
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form) {}


    /**
     * @description: 获取安全的文件名
     * @param string $file
     * @return string
     */
    private static function getSafeName(&$file)
    {
        $file = str_replace(array('"', '<', '>'), '', $file);
        $file = str_replace('\\', '/', $file);
        $file = false === strpos($file, '/') ? ('a' . $file) : str_replace('/', '/a', $file);
        $info = pathinfo($file);
        $file = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * @description: 获取文件上传目录
     * @return {*}
     */
    private static function getUploadDir()
    {
        $opt = Options::alloc()->plugin(pluginName);
        if ($opt->path) {
            return $opt->path;
        } else if (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            return self::UPLOAD_DIR;
        }
    }

    /**
     * @description: 获取上传文件信息
     * @param array $file 上传的文件
     * @return {*}
     */
    private static function getUploadFile($file)
    {
        return isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bytes']) ? $file['bytes'] : (isset($file['bits']) ? $file['bits'] : ''));
    }

    /**
     * @description: 创建上传路径
     * @param {string} $path
     * @return {*}
     */
    private static function makeUploadDir(string $path)
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last, 0755)) {
            return false;
        }

        return self::makeUploadDir($path);
    }

    /**
     * @description: R2初始化
     * @param object $options 设置信息
     * @return {*}
     */
    public static function R2Init($options = '')
    {
        $opt = Options::alloc()->plugin(pluginName);
        $credentials = new \Aws\Credentials\Credentials($opt->access_key_id, $opt->access_key_secret);
        if (!$options) {
            $options = [
                'region' => 'auto',
                'endpoint' => "https://" . $opt->account_id . ".r2.cloudflarestorage.com",
                'version' => 'latest',
                'credentials' => $credentials
            ];
        }


        return new \Aws\S3\S3Client($options);
    }

    /**
     * @description: 判断对象是否已存在
     * @param {*} $key
     * @return {*}
     */
    public static function doesObjectExist($key)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        #初始化r2
        $s3_client = self::R2Init();
        try {
            $result = $s3_client->doesObjectExist(
                $opt->bucket,
                $key
            );
            if ($result) {
                return true;
            }
        } catch (Exception $e) {
            return true;
        }
        return false;
    }

    /* 插件实现方法 */
    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }
        #获取扩展名
        $ext = self::getSafeName($file['name']);
        #判定是否是允许的文件类型
        if (!\Widget\Upload::checkFileType($ext) || \Typecho\Common::isAppEngine()) {
            return false;
        }
        $opt = Options::alloc()->plugin(pluginName);

        #获取文件名
        $date = new \Typecho\Date($opt->gmtTime);
        $fileDir = self::getUploadDir() . '/' . $date->year . '/' . $date->month;
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $fileDir . '/' . $fileName;
        #获得上传文件
        $uploadfile = self::getUploadFile($file);
        #如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }

        try {
            #判断是否存在重名文件，重名则重新生成
            $times = 10;
            while ($times > 0 && self::doesObjectExist($path)) {
                $fileName = sprintf('%u', crc32(uniqid($times--))) . '.' . $ext;
                $path = $fileDir . '/' . $fileName;
            }

            $s3_client = self::R2Init();

            $s3_client->PutObject([
                'Bucket' => $opt->bucket,
                'Key' => $path,
                'Body'   => fopen($uploadfile, 'rb'),
                'ACL'    => 'public-read',
            ]);
        } catch (Exception $e) {
            echo "$e\n";
            return false;
        }

        if (self::makeUploadDir($fileDir)) {
            #本地存储一份
            @move_uploaded_file($uploadfile, $path);
        }

        #返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @\Typecho\Common::mimeContentType($path)
        );
    }

    /**
     * @description: 获取对象访问Url
     * @param {array} $content
     * @return {*}
     */
    public static function attachmentHandle(array $content)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        $url = "https://" . $opt->access_domain . "/" . $content['attachment']->path;
        return $url;
    }


    /**
     * @description: 获取对象访问Url
     * @param array $content
     * @return {*}
     */
    public static function attachmentDataHandle($content)
    {
        #获取设置参数
        $opt = Options::alloc()->plugin(pluginName);
        $url = "https://" . $opt->access_domain . $content['attachment']->path;
        return $url;
    }
}
