# yii2-filebehavior
File field behavior for Yii 2

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