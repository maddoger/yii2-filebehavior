# yii2-filebehavior

File field behavior for Yii 2

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist maddoger/yii2-filebehavior "*"
```

or add

```
"maddoger/yii2-filebehaviore": "*"
```

to the require section of your `composer.json` file.

In model behaviors:
```php
[
    'class' => 'maddoger\filebehavior\FileBehavior',
    'attribute' => 'file_attribute',
    'deleteAttribute' => 'deleteFile',
    'fileName' => function ($model, $file, $index) {
        return md5($file->name).'.'.$file->extension;
    },
    'basePath' => '@webapp/uploads/files',
    'baseUrl' => '@web/uploads/files',
    'overwriteFile' => false,
],
```