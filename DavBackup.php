<?
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
 * @since     File available since Release 1.0.0
 */

/**
 * DavBackup main class implements backup and sending it to the cloud. 
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.0
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.0
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
     * Current time
     * @var int
     * @static
     * @access private
     */
    private static $time;

    /**
     * Compression
     * @var bool
     * @access public
     */
    public $compression = true;

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
        self::$url = $url;
        self::$credentials = array($login, $password);
        self::$time = time();

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
            $archive = new PharData(__DIR__ . '/' . self::TMPPATH . '/' . self::$time . '.tar');
            if (is_null(self::$path) == false) {
                $archive->buildFromDirectory(self::$path);
            }

            if (file_exists(__DIR__ . '/' . self::TMPPATH . '/' . self::$time . '.sql')) {
                $archive->addFile(
                    __DIR__ . '/' . self::TMPPATH . '/' . self::$time . '.sql',
                    'sql/' . self::$time . '.sql'
                );
            }

            if ($this->compression == true) {
                $archive->compress(Phar::GZ);
                unlink(__DIR__ . '/' . self::TMPPATH . '/' . self::$time . '.tar');
            }

            unlink(__DIR__ . '/' . self::TMPPATH . '/' . self::$time . '.sql');
        } catch (Exception $e) {
            throw new RuntimeException("Failed to create the archive: $e");
        }

        $realName = self::$time . '.tar' . ($this->compression == true ? '.gz' : '');

        if (file_exists(__DIR__ . '/' . self::TMPPATH . '/' . $realName)) {
            $send = $this->request(
                self::$url . self::REMOTEDIR . '/' . $realName,
                array(),
                'PUT',
                __DIR__ . '/' . self::TMPPATH . '/' . $realName
            );

            unlink(__DIR__ . '/' . self::TMPPATH . '/' . $realName);
            if ($send->code == 201) {
                return true;
            } else {
                return false;
            }
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

        $file = fopen(__DIR__ . '/' . self::TMPPATH . '/' . self::$time . '.sql', 'a+');

        $tables = array();
        $sql = $db->query('SHOW TABLES');
        while ($row = $sql->fetch(PDO::FETCH_NUM)) {
            array_push($tables, $row[0]);
        }

        foreach($tables as $table) {
            $sql = $db->query("SELECT * FROM $table");
            $column = $sql->columnCount();
            $rows = $sql->rowCount();

            $result = "DROP TABLE IF EXISTS `$table`;";

            $structure = $db->query("SHOW CREATE TABLE $table");

            $row = $structure->fetch(PDO::FETCH_NUM);
            $notexists = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $row[1]);
            $result .= "\n\n$notexists;\n\n";

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

                $result .= ') VALUES';

                fwrite($file, $result);

                $result = '';
            }

            $count = 0;
            while ($row = $sql->fetch(PDO::FETCH_NUM)) {
                $result .= "\n\t(";

                for($i=0; $i < $column; $i++) {
                    if (isset($row[$i])) {
                        if (in_array($type[$table][$i], $types) && empty($row[$i]) == false) {
                            $result .= $row[$i];
                        } else {
                            $result .= $db->quote($row[$i]);
                        }
                    } else {
                        $result .= 'NULL';
                    }

                    if ($i < $column - 1) {
                        $result .= ',';
                    }
                }

                $count++;
                if ($count < $rows) {
                    $result .= '),';
                } else {
                    $result .= ');';
                }

                fwrite($file, $result);

                $result = '';
            }

            $result = "\n\n--------------------------------------------------- \n\n";
            fwrite($file, $result);
        }

        fclose($file);
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
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERPWD, implode(':', self::$credentials));

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
 * @version   Release: 1.0.0
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.0
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
 * @version   Release: 1.0.0
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.0
 * @todo      working through service dav-pocket.appspot.com
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
 * @version   Release: 1.0.0
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.0
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
 * MailBackup - backup in Mail Disc.
 *
 * @author    Dmitry Mamontov <d.slonyara@gmail.com>
 * @copyright 2015 Dmitry Mamontov <d.slonyara@gmail.com>
 * @license   http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @version   Release: 1.0.0
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.0
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
 * @version   Release: 1.0.0
 * @link      https://github.com/dmamontov/davbackup
 * @since     Class available since Release 1.0.0
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

?>
