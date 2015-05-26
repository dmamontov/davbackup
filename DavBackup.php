<?php
/**
 * DavBackup
 *
 * Copyright (c) 2015, Dmitry Mamontov <d.slonyara@gmail.com>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Dmitry Mamontov nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package   davbackup
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @since     File available since Release 1.0.1
 */

/**
 * DavBackup main class implements backup and sending it to the cloud.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 * @abstract
 */
abstract class DavBackup
{
    /*
     * Remote Directory for backups
     */
    const REMOTEDIR = 'backup';

    /*
     * Directory for the temporary storage of backups
     */
    const TMPPATH = 'tmp';

    /*
     * Archive Type TAR
     */
    const TAR = 0;

    /*
     * Archive Type ZIP
     */
    const ZIP = 1;

    /**
     * URL to the cloud
     * @var string
     * @static
     * @access private
     */
    private static $url;

    /**
     * Authorization data
     * @var array
     * @static
     * @access private
     */
    private static $credentials;

    /**
     * Path to the directory you want to backup
     * @var string
     * @static
     * @access private
     */
    private static $path;

    /**
     * The name of the archive
     * @var string
     * @static
     * @access private
     */
    private static $name;

    /**
     * Archive Type
     * @var integer
     * @access public
     */
    public $type = 0;

    /**
     * Compression
     * @var bool
     * @access public
     */
    public $compression = false;

    /**
     * Type of authorization
     * @var string
     * @access public
     */
    public $authtype = '';

    /**
     * Sets variables and creates the required directory
     * @param string $url
     * @param string $login
     * @param string $password
     * @return void
     * @access protected
     */
    protected function __construct($url, $login, $password)
    {
        ini_set('memory_limit', '-1');

        self::$url = $url;
        self::$credentials = array($login, $password);
        self::$name = (string) time();

        if (file_exists(__DIR__ . '/' . self::TMPPATH . '/') == false) {
            mkdir(__DIR__ . '/' . self::TMPPATH . '/', 0755);
        }
    }

    /**
     * Creates a backup and sends it to the cloud
     * @return bool
     * @access public
     * @final
     */
    final public function backup()
    {
        $folder = $this->request(self::$url . self::REMOTEDIR, array('Depth: 0'), 'PROPFIND');

        if ($folder->code == 404) {
            $folder = $this->request(self::$url . self::REMOTEDIR, array(), 'MKCOL');
        }

        if (in_array($folder->code, array(201, 207)) === false) {
            throw new RuntimeException('Failed to create remote directory', $folder->code);
        }

        try {
            switch ($this->type) {
                case self::TAR:
                    $archive = new PharData(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.tar');
                    break;
                case self::ZIP:
                    $archive = new ZipArchive();
                    $archive->open(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.zip', ZIPARCHIVE::CREATE);
                    break;
            }

            if (is_null(self::$path) == false) {
                switch ($this->type) {
                    case self::TAR:
                        $archive->buildFromDirectory(self::$path);
                        break;
                    case self::ZIP:
                        self::$path = str_replace('\\', '/', realpath(self::$path));
                        $files = new RecursiveIteratorIterator(
                                     new RecursiveDirectoryIterator(self::$path),
                                     RecursiveIteratorIterator::SELF_FIRST
                                 );

                        foreach ($files as $file) {
                            $file = str_replace('\\', '/', $file);
                            if (in_array(substr($file, strrpos($file, '/') + 1), array('.', '..'))) {
                                continue;
                            }

                            $file = realpath($file);
                            if (is_file($file) === true) {
                                $archive->addFile($file, str_replace(self::$path . '/', '', $file));
                            }
                        }
                        break;
                }
            }
            if (file_exists(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.sql')) {
                $archive->addFile(
                    __DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.sql',
                    'sql/' . self::$name . '.sql'
                );
            }

            if ($this->compression == true && $this->type == self::TAR) {
                $archive->compress(Phar::GZ);
                unlink(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.tar');
            }

            if ($this->type == self::ZIP) {
                $archive->close();
            }
        } catch (Exception $e) {
            throw new RuntimeException("Failed to create the archive: $e");
        }

        switch ($this->type) {
            case self::TAR:
                $realName = self::$name . '.tar' . ($this->compression == true ? '.gz' : '');
                break;
            case self::ZIP:
                $realName = self::$name . '.zip';
                break;
        }

        if (file_exists(__DIR__ . '/' . self::TMPPATH . '/' . $realName)) {
            $send = $this->request(
                self::$url . self::REMOTEDIR . '/' . $realName,
                array('Content-type: application/octet-stream'),
                'PUT',
                __DIR__ . '/' . self::TMPPATH . '/' . $realName
            );

            if (file_exists(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.sql')) {
                unlink(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.sql');
            }
            unlink(__DIR__ . '/' . self::TMPPATH . '/' . $realName);

            return $send->code == 201 ? true : false;
        }
    }

    /**
     * Set the compression of the archive
     * @param bool $compression
     * @return bool
     * @access public
     * @final
     */
    final public function setCompression($compression = true)
    {
        $this->compression = (bool) $compression;

        return true;
    }

    /**
     * Gets a compressed archive
     * @return bool
     * @access public
     * @final
     */
    final public function getCompression()
    {
        return $this->compression;
    }

    /**
     * Sets the name of the archive
     * @param mixed $name
     * @return bool
     * @access public
     * @final
     */
    final public function setName($name = null)
    {
        if (is_null($name)) {
            self::$name = time();
        } else {
            self::$name = (string) time() . '-' . str_replace(array(' ', "\n", "\t", '_'), '-', strtolower($name));
        }

        return true;
    }

    /**
     * It gets the name of the archive
     * @return string
     * @access public
     * @final
     */
    final public function getName()
    {
        return self::$name;
    }

    /**
     * Sets type of archive
     * @param int $type
     * @return bool
     * @access public
     * @final
     */
    final public function setType($type = 0)
    {
        if (in_array($type, array(0, 1))) {
            $this->type = (int) $type;
        } else {
            $this->type = 0;
        }

        return true;
    }

    /**
     * Gets type of archive
     * @return string
     * @access public
     * @final
     */
    final public function getType()
    {
        switch ($this->type) {
            case self::TAR:
                return 'tar';
            case self::ZIP:
                return 'zip';
        }
    }

    /**
     * Sets the directory you want to backup
     * @param string $path
     * @return bool
     * @access public
     * @final
     */
    final public function folder($path)
    {
        if (file_exists($path) && is_dir($path)) {
            self::$path = $path;

            return true;
        }

        return false;
    }

    /**
     * Connects to the database and the creation of its temporary backup
     * @param string $dbuser
     * @param string $dbpass
     * @param string $dbname
     * @param string $host
     * @param string $driver
     * @access public
     * @final
     */
    final public function db($dbuser, $dbpass, $dbname, $host = 'localhost', $driver = 'mysql')
    {
        $types = array(
            'tinyint',
            'smallint',
            'mediumint',
            'int',
            'bigint',
            'float',
            'double',
            'decimal',
            'real'
        );

        $db = new PDO("$driver:host=$host;dbname=$dbname", $dbuser, $dbpass);
        $db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

        $file = fopen(__DIR__ . '/' . self::TMPPATH . '/' . self::$name . '.sql', 'a+');

        $tables = array();
        $sql = $db->query('SHOW TABLES');
        while ($row = $sql->fetch(PDO::FETCH_NUM)) {
            array_push($tables, $row[0]);
        }

        foreach ($tables as $table) {
            $sql = $db->query("SELECT * FROM $table");
            $column = $sql->columnCount();
            $rows = $sql->rowCount();

            $result = "DROP TABLE IF EXISTS `$table`;";

            $structure = $db->query("SHOW CREATE TABLE $table");

            $row = $structure->fetch(PDO::FETCH_NUM);
            $notexists = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $row[1]);
            $result .= "\n\n$notexists;\n\n";

            unset($structure, $notexists);

            fwrite($file, $result);

            $result = '';

            if ($rows) {
                $result = "INSERT INTO `$table` (";
                $columns = $db->query("SHOW COLUMNS FROM $table");

                $count = 0;
                $type = array();

                while ($row = $columns->fetch(PDO::FETCH_NUM)) {
                    $type[$table][] = stripos($row[1], '(') ? stristr($row[1], '(', true) : $row[1];
                    $result .= "`{$row[0]}`";

                    $count++;
                    if ($count < $columns->rowCount()) {
                        $result .= ', ';
                    }
                }
                unset($columns);

                $result .= ') VALUES';

                fwrite($file, $result);

                $result = '';
            }

            $count = 0;
            while ($row = $sql->fetch(PDO::FETCH_NUM)) {
                $result .= "\n\t(";

                for ($i=0; $i < $column; $i++) {
                    if (isset($row[$i]) && in_array($type[$table][$i], $types) && empty($row[$i]) == false) {
                        $result .= $row[$i];
                    } elseif (isset($row[$i])) {
                        $result .= $db->quote($row[$i]);
                    } else {
                        $result .= 'NULL';
                    }

                    if ($i < $column - 1) {
                        $result .= ',';
                    }
                }

                $count++;
                $result .= $count < $rows ? '),' : ');';

                fwrite($file, $result);

                $result = '';
            }

            fwrite($file, "\n\n\n\n");
        }

        fclose($file);
        unset($file, $result, $tables, $sql, $column, $rows, $row);
    }

    /**
     * Executes queries to the cloud
     * @param string $url
     * @param array $headers
     * @param string $method
     * @param string $file
     * @return stdClass
     * @access private
     * @final
     */
    final private function request($url, $headers = array(), $method = '', $file = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERPWD, implode(':', self::$credentials));

        if (empty($this->authtype) === false) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, $this->authtype);
        }

        if (empty($headers) === false) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if (empty($method) === false) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if (is_null($file) === false) {
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_INFILE, fopen($file, 'r'));
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file));
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $result = new stdClass();
        $result->response = $response;
        $result->code = $statusCode;

        return $result;
    }
}

/**
 * YandexBackup - backup in Yandex.Disk.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 */
class YandexBackup extends DavBackup
{
    /**
     * URL to the cloud
     */
    const URL = 'https://webdav.yandex.ru/';

    /**
     * Sets variables
     * @param string $url
     * @param string $login
     * @return void
     * @access public
     */
    public function __construct($login, $password)
    {
        parent::__construct(self::URL, (string) $login, (string) $password);
    }
}

/**
 * GoogleBackup - backup in GoogleDrive.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 * @todo      working through service appspot.com
 */
class GoogleBackup extends DavBackup
{
    /**
     * URL to the cloud
     */
    const URL = 'https://dav-pocket.appspot.com/docso/';

    /**
     * Sets variables
     * @param string $url
     * @param string $login
     * @return void
     * @access public
     */
    public function __construct($login, $password)
    {
        parent::__construct(self::URL, (string) $login, (string) $password);
    }
}

/**
 * DropBoxBackup - backup in DropBox.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 * @todo      working through service dropdav.com
 */
class DropBoxBackup extends DavBackup
{
    /**
     * URL to the cloud
     */
    const URL = 'https://dav.dropdav.com/';

    /**
     * Sets variables
     * @param string $url
     * @param string $login
     * @return void
     * @access public
     */
    public function __construct($login, $password)
    {
        parent::__construct(self::URL, (string) $login, (string) $password);
    }
}

/**
 * CloudMeBackup - backup in CloudMe.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 */
class CloudMeBackup extends DavBackup
{
    /**
     * URL to the cloud
     */
    const URL = 'http://webdav.cloudme.com/';

    /**
     * Sets variables
     * @param string $url
     * @param string $login
     * @return void
     * @access public
     */
    public function __construct($login, $password)
    {
        $this->authtype = CURLAUTH_ANY;
        parent::__construct(self::URL . "$login/CloudDrive/Documents/", (string) $login, (string) $password);
    }
}

/**
 * MailBackup - backup in Mail Disc.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 * @todo      mail.ru temporarily disable access to WebDAV
 */
class MailBackup extends DavBackup
{
    /**
     * URL to the cloud
     */
    const URL = 'https://webdav.cloud.mail.ru/';

    /**
     * Sets variables
     * @param string $url
     * @param string $login
     * @return void
     * @access public
     */
    public function __construct($login, $password)
    {
        throw new RuntimeException('Mail.ru temporarily disable access to WebDAV');
        //parent::__construct(self::URL, (string) $login, (string) $password);
    }
}

/**
 * OneDriveBackup - backup in OneDrive.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.1
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.1
 * @todo      microsoft temporarily disable access to WebDAV
 */
class OneDriveBackup extends DavBackup
{
    /**
     * URL to the cloud
     */
    const URL = 'https://d.docs.live.net/';

    /**
     * Sets variables
     * @param string $url
     * @param string $login
     * @param string $cid
     * @return void
     * @access public
     */
    public function __construct($login, $password, $cid)
    {
        throw new RuntimeException('Microsoft temporarily disable access to WebDAV');
        //parent::__construct(self::URL . $cid . '/', (string) $login, (string) $password);
    }
}
