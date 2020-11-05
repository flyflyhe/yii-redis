<?php

namespace yii\redis;

use Redis;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;

/**
 * @property string $driverName Name of the DB driver. This property is read-only.
 * @property bool $isActive Whether the DB connection is established. This property is read-only.
 * @property string $connectionString
 * @property Redis|false $socket
 * Class Connection
 * @package yii\redis
 */
class Connection extends Component
{
    /**
     * @event Event an event that is triggered after a DB connection is established
     */
    const EVENT_AFTER_OPEN = 'afterOpen';

    /**
     * @var string the hostname or ip address to use for connecting to the redis server. Defaults to 'localhost'.
     * If [[unixSocket]] is specified, hostname and [[port]] will be ignored.
     */
    public $hostname = 'localhost';
    /**
     * @var string if the query gets redirected, use this as the temporary new hostname
     * @since 2.0.11
     */
    public $redirectConnectionString;
    /**
     * @var integer the port to use for connecting to the redis server. Default port is 6379.
     * If [[unixSocket]] is specified, [[hostname]] and port will be ignored.
     */
    public $port = 6379;
    /**
     * @var string the unix socket path (e.g. `/var/run/redis/redis.sock`) to use for connecting to the redis server.
     * This can be used instead of [[hostname]] and [[port]] to connect to the server using a unix socket.
     * If a unix socket path is specified, [[hostname]] and [[port]] will be ignored.
     * @since 2.0.1
     */
    public $unixSocket;
    /**
     * @var string the password for establishing DB connection. Defaults to null meaning no AUTH command is sent.
     * See https://redis.io/commands/auth
     */
    public $password;
    /**
     * @var integer the redis database to use. This is an integer value starting from 0. Defaults to 0.
     * Since version 2.0.6 you can disable the SELECT command sent after connection by setting this property to `null`.
     */
    public $database = 0;
    /**
     * @var float timeout to use for connection to redis. If not set the timeout set in php.ini will be used: `ini_get("default_socket_timeout")`.
     */
    public $connectionTimeout;
    /**
     * @var float timeout to use for redis socket when reading and writing data. If not set the php default value will be used.
     */
    public $dataTimeout;
    /**
     * @var boolean Send sockets over SSL protocol. Default state is false.
     * @since 2.0.12
     */
    public $useSSL = false;
    /**
     * @var integer Bitmask field which may be set to any combination of connection flags passed to [stream_socket_client()](https://www.php.net/manual/en/function.stream-socket-client.php).
     * Currently the select of connection flags is limited to `STREAM_CLIENT_CONNECT` (default), `STREAM_CLIENT_ASYNC_CONNECT` and `STREAM_CLIENT_PERSISTENT`.
     *
     * > Warning: `STREAM_CLIENT_PERSISTENT` will make PHP reuse connections to the same server. If you are using multiple
     * > connection objects to refer to different redis [[$database|databases]] on the same [[port]], redis commands may
     * > get executed on the wrong database. `STREAM_CLIENT_PERSISTENT` is only safe to use if you use only one database.
     * >
     * > You may still use persistent connections in this case when disambiguating ports as described
     * > in [a comment on the PHP manual](https://www.php.net/manual/en/function.stream-socket-client.php#105393)
     * > e.g. on the connection used for session storage, specify the port as:
     * >
     * > ```php
     * > 'port' => '6379/session'
     * > ```
     *
     * @see https://www.php.net/manual/en/function.stream-socket-client.php
     * @since 2.0.5
     */
    public $socketClientFlags = STREAM_CLIENT_CONNECT;
    /**
     * @var integer The number of times a command execution should be retried when a connection failure occurs.
     * This is used in [[executeCommand()]] when a [[SocketException]] is thrown.
     * Defaults to 0 meaning no retries on failure.
     * @since 2.0.7
     */
    public $retries = 0;
    /**
     * @var integer The retry interval in microseconds to wait between retry.
     * This is used in [[executeCommand()]] when a [[SocketException]] is thrown.
     * Defaults to 0 meaning no wait.
     * @since 2.0.10
     */
    public $retryInterval = 0;

    /**
     * @var array redis redirect socket connection pool
     */
    private $_pool = [];


    /**
     * Closes the connection when this component is being serialized.
     * @return array
     */
    public function __sleep()
    {
        $this->close();
        return array_keys(get_object_vars($this));
    }

    /**
     * Return the connection string used to open a socket connection. During a redirect (cluster mode) this will be the
     * target of the redirect.
     * @return string socket connection string
     * @since 2.0.11
     */
    public function getConnectionString()
    {
        if ($this->unixSocket) {
            return 'unix://' . $this->unixSocket;
        }

        return 'tcp://' . ($this->redirectConnectionString ?: "$this->hostname:$this->port");
    }

    /**
     * Return the connection resource if a connection to the target has been established before, `false` otherwise.
     * @return resource|false
     * @throws \Exception
     */
    public function getSocket()
    {
        return ArrayHelper::getValue($this->_pool, $this->connectionString, false);
    }

    /**
     * Returns a value indicating whether the DB connection is established.
     * @return bool whether the DB connection is established
     * @throws \Exception
     */
    public function getIsActive()
    {
        return ArrayHelper::getValue($this->_pool, "$this->hostname:$this->port", false) !== false;
    }

    /**
     * Establishes a DB connection.
     * It does nothing if a DB connection has already been established.
     */
    public function open()
    {
        if ($this->socket !== false) {
            return;
        }

        $connection = $this->connectionString . ', database=' . $this->database;
        \Yii::debug('Opening redis DB connection: ' . $connection, __METHOD__);

        $redis = new \Redis();
        if (!$redis->connect($this->hostname, $this->port, $this->connectionTimeout, $this->retryInterval, $this->dataTimeout)) {
            throw new \RedisException("connect failed $connection");
        }
        if ($this->password) {
            $redis->auth($this->password);
        }
        $redis->select($this->database);
        $this->_pool[$this->connectionString] = $redis;
        $this->initConnection();
    }

    /**
     * Closes the currently active DB connection.
     * It does nothing if the connection is already closed.
     */
    public function close()
    {
        foreach ($this->_pool as $socket) {
            /**
             * @var $socket Redis
             */
            $connection = $this->connectionString . ', database=' . $this->database;
            \Yii::debug('Closing DB connection: ' . $connection, __METHOD__);
            try {
                $socket->close();
            } catch (\Exception $e) {
                // ignore errors when quitting a closed connection
            }
        }

        $this->_pool = [];
    }

    /**
     * Initializes the DB connection.
     * This method is invoked right after the DB connection is established.
     * The default implementation triggers an [[EVENT_AFTER_OPEN]] event.
     */
    protected function initConnection()
    {
        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    /**
     * Returns the name of the DB driver for the current [[dsn]].
     * @return string name of the DB driver
     */
    public function getDriverName()
    {
        return 'redis';
    }


    /**
     * Allows issuing all supported commands via magic methods.
     *
     * ```php
     * $redis->hmset('test_collection', 'key1', 'val1', 'key2', 'val2')
     * ```
     *
     * @param string $name name of the missing method to execute
     * @param array $params method call arguments
     * @return mixed
     * @throws \RedisException
     */
    public function __call($name, $params)
    {
        $this->open();
        if ($this->retries > 0) {
            $tries = $this->retries;
            while ($tries-- > 0) {
                try {
                    return $this->socket->$name(...$params);
                } catch (\RedisException $e) {
                    \Yii::error($e, __METHOD__);
                    // backup retries, fail on commands that fail inside here
                    $retries = $this->retries;
                    $this->retries = 0;
                    $this->close();
                    if ($this->retryInterval > 0) {
                        usleep($this->retryInterval);
                    }
                    $this->open();
                    $this->retries = $retries;
                }
            }
        }
        return $this->socket->$name(...$params);
    }
}