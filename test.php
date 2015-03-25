<?
    require 'DavBackup.php';

    $ya = new YandexBackup('test@yandex.ru', 'test');
    $ya->db('user', 'password', 'db');
    $ya->folder('/var/www/public_html/');
    $ya->backup();
?>