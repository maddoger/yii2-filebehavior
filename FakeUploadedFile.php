<?php
/**
 * @copyright Copyright (c) 2014-2016 Vitaliy Syrchikov
 * @link http://syrchikov.name
 */

namespace maddoger\filebehavior;

use Yii;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;

/**
 * FakeUploadedFile
 * Helps to create fake files from URL. Use only if you know what you doing.
 * @package maddoger\filebehavior
 */
class FakeUploadedFile extends UploadedFile
{
    /**
     * @var string original url
     */
    public $url;

    /**
     * @param string $file
     * @param bool $deleteTempFile
     * @return bool
     */
    public function saveAs($file, $deleteTempFile = true)
    {
        if ($this->error == UPLOAD_ERR_OK) {
            if ($deleteTempFile) {
                return rename($this->tempName, $file);
            } else {
                return copy($this->tempName, $file);
            }
        }
        return false;
    }


    /**
     * Returns UploadFile created from url
     * Notice that this file cannot be saved by move_uploaded_file
     * @param $url
     * @throws \yii\base\InvalidConfigException
     */
    public static function getFromUrl($url)
    {
        $tmpFile = null;
        if (static::isExternalUrl($url)) {
            //External url
            $tmpFile = static::downloadToTmp($url);
        } else {
            //File must be in static folder
            $staticPath = Yii::getAlias('@static/');
            $path = str_replace(
                Yii::getAlias('@staticUrl/'),
                Yii::getAlias('@static/'),
                Yii::getAlias($url),
                $count
            );
            //If we can replace static url to path
            if ($count>0) {
                //Check staticPath after normalize
                $path = FileHelper::normalizePath($path);
                if (strpos($path, $staticPath) === 0) {
                    if (file_exists($path)) {
                        $tmpFile = tempnam(sys_get_temp_dir(), 'CURL');
                        if (!copy($path, $tmpFile)) {
                            $tmpFile = null;
                        }
                    }
                }
            }

        }
        if ($tmpFile) {
            return new static([
                'name' => basename($url),
                'tempName' => $tmpFile,
                'type' => FileHelper::getMimeType($tmpFile),
                'size' => filesize($tmpFile),
                'error' => UPLOAD_ERR_OK,
            ]);
        }
        return null;
    }

    public static function isExternalUrl($url)
    {
        return (
            (strpos($url, '//') === 0) ||
            (strpos($url, 'http://') === 0) ||
            (strpos($url, 'https://') === 0) ||
            (strpos($url, 'ftp://') === 0)
        );
    }

    /**
     * Download file from external url to the temp (system) folder
     * @param $url
     */
    public static function downloadToTmp($url)
    {
        $saveTo = tempnam(sys_get_temp_dir(), 'CURL');
        try {
            $data = null;
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
                $data = curl_exec($ch);
                curl_close($ch);
            } else {
                $data = file_get_contents($url);
            }
            if ($data) {
                file_put_contents($saveTo, $data);
            }
            return $saveTo;

        } catch (\Exception $e) {
            return null;
        }
    }
}