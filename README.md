[![Latest Stable Version](https://poser.pugx.org/dmamontov/davbackup/v/stable.svg)](https://packagist.org/packages/dmamontov/davbackup)
[![License](https://poser.pugx.org/dmamontov/davbackup/license.svg)](https://packagist.org/packages/dmamontov/davbackup)

PHP Backup to WebDav Server
===========================

This package can backup files and a database to a WebDav server.

It takes the path of a given local directory and creates a PHAR archive with the files of the directory.

The base class can also connect to a given database using PDO and generate a backup file with SQL statements to recreate the database. The generated SQL file is also added to the PHAR archive.

The PHAR archive is compressed and transferred to a given remote server using the WebDAV protocol.

The package comes with several sub-classes specialized in configuring the connection to different WebDAV servers.

## Requirements
* PHP version 5.3.6 or higher

### Currently it supports clouds
* `Yandex Disk`
* `CloudMe`
* `GoogleDrive` working through service [dav-pocket](https://dav-pocket.appspot.com/)
* `DropBox` working through service [dropdav](https://www.dropdav.com/)
* ~~`Mail Disk`~~ temporary does not work
* ~~`OneDrive`~~ temporary does not work

## Installation

1) Install [composer](https://getcomposer.org/download/)

2) Follow in the project folder:
```bash
composer require dmamontov/davbackup ~1.0.1
```

In config `composer.json` your project will be added to the library `dmamontov/davbackup`, who settled in the folder `vendor/`. In the absence of a config file or folder with vendors they will be created.

If before your project is not used `composer`, connect the startup file vendors. To do this, enter the code in the project:
```php
require 'path/to/vendor/autoload.php';
```

### Example of work
```php
require 'DavBackup.php';

$ya = new YandexBackup('test@yandex.ru', 'test');

$ya->setName('My Backup');
$ya->setType(YandexBackup::ZIP);

$ya->db('user', 'password', 'db');
$ya->folder('/var/www/public_html/');

$ya->backup();
```

### Example of adding support for WebDav cloud
```php
class MyDavBackup extends DavBackup
{
    const URL = 'https://dav.my.ru/';

    public function __construct($login, $password)
    {
        parent::__construct(self::URL, (string) $login, (string) $password);
    }
}
```
