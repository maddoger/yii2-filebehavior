<?php
/**
 * @copyright Copyright (c) 2014-2016 Vitaliy Syrchikov
 * @link http://syrchikov.name
 */

namespace maddoger\filebehavior;

use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;
use yii\web\UploadedFile;

/**
 * FileBehavior
 *
 * You must create aliases @static and @staticUrl for folder for uploaded files
 *
 * @author Vitaliy Syrchikov <maddoger@gmail.com>
 * @link http://syrchikov.name
 * @package maddoger/yii2-core
 *
 */
class FileBehavior extends Behavior
{
    /**
     * @var ActiveRecord the owner of this behavior
     */
    public $owner;

    /**
     * @var string Base path for uploading
     * Defaults to '@static/[model_table]'
     */
    public $basePath;

    /**
     * @var string Base url for base path
     * Defaults to '@staticUrl/[model_table]'
     */
    public $baseUrl;

    /**
     * @var string Attribute for writing file URL
     */
    public $attribute = null;

    /**
     * @var null|String|callable generator for filename
     *
     * If its null original file name will be used.
     * Can be an attribute name for transliterate.
     * The signature of the function should be the following: `function ($model, $file, $index)`.
     */
    public $fileName = null;

    /**
     * @var bool use only $_FILES for changing value of attribute.
     * If is true and file var is exists but empty, old value will be preserved.
     */
    public $changeByFileOnly = true;

    /**
     * @var string delete attribute name
     * if it is set and its true file will be delete
     * Default is null.
     */
    public $deleteAttribute;

    /**
     * @var bool overwrite if file already exists
     */
    public $overwriteFile = true;

    /**
     * @var bool delete old file if it exists
     */
    public $deleteOldFile = true;

    /**
     * @var bool file will be deleted with model deletion
     */
    public $deleteFileWithModel = true;

    /**
     * @var UploadedFile
     */
    public $file = null;

    /**
     * @var string old value of attribute
     */
    protected $oldValue = null;

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function init()
    {
        parent::init();

        if (is_null($this->attribute)) {
            throw new Exception('Attribute name must be set.');
        }
    }

    public function attach($owner)
    {
        parent::attach($owner);
        $folder = Inflector::tableize(StringHelper::basename($this->owner->className()));

        if (!$this->basePath) {
            $this->basePath = '@static/' . $folder;
        }
        if (!$this->baseUrl) {
            $this->baseUrl = '@staticUrl/' . $folder;
        }
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    /**
     * After find event.
     */
    public function afterFind()
    {
        $this->oldValue = $this->owner->{$this->attribute};
    }

    /**
     * Before validate event. Loading UploadFile instance and set it to attribute
     */
    public function beforeValidate()
    {
        if ($this->owner->isAttributeSafe($this->attribute)) {

            $attributeValue = $this->owner->{$this->attribute};

            if ($attributeValue instanceof UploadedFile) {
                $this->file = $attributeValue;
            }

            if (is_null($this->file)) {
                $this->file = UploadedFile::getInstance($this->owner, $this->attribute);
            }

            if ($this->file instanceof UploadedFile && !$this->file->hasError) {
                $this->owner->{$this->attribute} = $this->file;
            } else {
                $this->file = null;

                if ($this->changeByFileOnly) {
                    //Reset old value
                    $this->owner->{$this->attribute} = $this->oldValue;
                }

                //Delete file using deleteAttribute
                if ($this->deleteAttribute &&
                    $this->owner->canGetProperty($this->deleteAttribute) &&
                    $this->owner->{$this->deleteAttribute}
                ) {
                    //Delete
                    $this->owner->{$this->attribute} = null;
                }
            }
        }
    }

    /**
     * Before save event.
     */
    public function beforeSave()
    {
        if ($this->file instanceof UploadedFile) {
            if ($this->deleteOldFile) {
                $this->deleteFileInternal();
            }
            $this->oldValue = null;
            $this->owner->{$this->attribute} = $this->file->baseName . '.' . $this->file->extension;
        } else {

            //Delete old file if needed
            if (($this->owner->{$this->attribute} != $this->oldValue) && $this->deleteOldFile) {
                $this->deleteFileInternal();
            }

        }
    }

    /**
     * After save event.
     * @throws Exception
     */
    public function afterSave()
    {
        if ($this->file instanceof UploadedFile) {

            $this->beforeFileSaving();

            $name = $this->generateName();
            $path = $this->generateFilePathInternal($name);
            $url = $this->generateFileUrlInternal($name);

            $dir = dirname($path);
            if (!FileHelper::createDirectory($dir)) {
                throw new Exception('Directory "' . $dir . '" creation error.');
            }

            if (!$this->overwriteFile && file_exists($path)) {
                for ($index = 0; $index < 10; $index++) {
                    $name = $this->generateName($index);
                    $path = $this->generateFilePathInternal($name);
                    $url = $this->generateFileUrlInternal($name);
                    if (!file_exists($path)) {
                        break;
                    }
                }
                if (file_exists($path)) {
                    throw new Exception('File already exists!');
                }
            }

            if (!$this->file->saveAs($path)) {
                throw new Exception('File saving error.');
            }

            $this->afterFileSaving();

            $this->owner->setOldAttribute($this->attribute, $url);
            $this->owner->setAttribute($this->attribute, $url);

            $this->oldValue = $url;
            $this->file = null;

            if (!$this->owner->getDb()->createCommand()->update(
                $this->owner->tableName(),
                [
                    $this->attribute => $url
                ],
                $this->owner->getPrimaryKey(true)
            )->execute()
            ) {
                throw new Exception('Model update failed.');
            }
        }
    }

    /**
     * After delete event
     */
    public function afterDelete()
    {
        if ($this->deleteFileWithModel) {
            $this->deleteFileInternal();
        }
    }

    /**
     * Return path to file in attribute
     * @param $attribute string attribute name
     * @return string|null
     */
    public function getFilePath($attribute)
    {
        $behavior = $this->getBehaviorByAttribute($attribute);
        if ($behavior) {
            $attributeValue = $this->owner->{$attribute};
            $url = is_string($attribute) ? $attribute : $behavior->oldValue;
            return $this->getFilePathFromUrl($url);
        }

        return null;
    }

    /**
     * Return path to file in attribute
     * @param $attribute string attribute name
     * @return string|null
     */
    public function deleteFile($attribute)
    {
        $behavior = $this->getBehaviorByAttribute($attribute);
        if ($behavior) {
            $behavior->deleteFileInternal();
        }
        return null;
    }

    /**
     * Event before file saving
     */
    public function beforeFileSaving()
    {
        $event = new ModelEvent();
        $this->owner->trigger('beforeFileSaving', $event);
        return $event->isValid;
    }

    /**
     * Event
     */
    public function afterFileSaving()
    {
        $event = new ModelEvent();
        $this->owner->trigger('afterFileSaving', $event);
        return $event->isValid;
    }

    /**
     * Returns possible file path from url, but not checks its existence.
     * @param $url
     * @return mixed
     */
    protected function getFilePathFromUrl($url)
    {
        return str_replace(
            Yii::getAlias($this->baseUrl),
            Yii::getAlias($this->basePath),
            $url);
    }

    /**
     * Generate file name
     * @param string $index
     * @return string
     */
    protected function generateName($index = null)
    {
        if ($this->file instanceof UploadedFile) {
            $extension = strtolower($this->file->extension);
            $baseName = $this->file->baseName;

            if ($this->fileName) {
                if ($this->fileName instanceof \Closure) {
                    $name = call_user_func($this->fileName, $this->owner, $this->file, $index);
                    if ($name) {
                        return $name;
                    }
                } elseif ($this->owner->canGetProperty($this->fileName)) {
                    $baseName = $this->owner->{$this->fileName};
                }
            }

            if ($index) {
                $baseName .= '_' . $index;
            }
            return Inflector::slug($baseName) . '.' . $extension;
        }
        return null;
    }

    /**
     * Returns file path
     * @param null|string $fileName
     * @return bool|string
     */
    protected function generateFilePathInternal($fileName = null)
    {
        return Yii::getAlias(
            rtrim($this->basePath, '/') . '/' .
            ltrim($fileName ?: $this->generateName(), '/')
        );
    }

    /**
     * Returns file url
     * @param null $fileName
     * @return bool|string
     */
    protected function generateFileUrlInternal($fileName = null)
    {
        return Yii::getAlias(
            rtrim($this->baseUrl, '/') . '/' .
            ltrim($fileName ?: $this->generateName(), '/')
        );
    }

    /**
     * Returns FileBehavior by attribute
     * @param $attribute
     * @return static
     */
    protected function getBehaviorByAttribute($attribute)
    {
        foreach ($this->owner->behaviors as $behavior) {
            if ($behavior instanceof static && $behavior->attribute == $attribute) {
                return $behavior;
            }
        }
        return null;
    }

    /**
     * Delete old files
     */
    protected function deleteFileInternal()
    {
        if ($this->oldValue) {
            $filePath = $this->getFilePathFromUrl($this->oldValue);

            try {
                if (is_file($filePath)) {
                    unlink($filePath);
                }
            } catch (\Exception $e) {

            }
            $this->oldValue = null;
        }
    }
}