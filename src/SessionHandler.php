<?php
/**
 * Piton Session Manager
 *
 * The MIT License (MIT)
 * Copyright (c) 2015 Wolfgang Moritz
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
namespace Piton\Session;

use \Exception;
use \PDO;

/**
 * Session Handler
 *
 * Manage http session state across page views.
 * @version 1.3.0
 */
class SessionHandler
{
    //
    // Modifiable Configuration Settings
    //

    /**
     * PDO database handle
     * @var PDO connection object
     */
    protected $db = null;

    /**
     * Cookie name
     * @var string
     */
    protected $cookieName = 'sessionCookie';

    /**
     * Database table
     * @var string
     */
    protected $tableName = 'session';

    /**
     * Number of seconds before the session expires
     * @var integer
     */
    protected $secondsUntilExpiration = 7200;

    /**
     * Number of seconds before the session ID is regenerated
     * @var integer
     */
    protected $renewalTime = 300;

    /**
     * Whether to kill the session when the browser is closed
     * @var boolean
     */
    protected $expireOnClose = false;

    /**
     * Whether to check IP address in validating session ID
     * @var boolean
     */
    protected $checkIpAddress = false;

    /**
     * Whether to check the user agent in validating a session
     * @var boolean
     */
    protected $checkUserAgent = false;

    /**
     * Will only set the session cookie if a secure HTTPS connection is being used
     * @var boolean
     */
    protected $secureCookie = false;

    /**
     * Encyrption key to salt hash
     * @var string
     */
    protected $salt = '';

    /**
     * Auto-Run Session
     * @var boolean
     */
    protected $autoRunSession = true;

    //
    // End Modifiable Configuration Settings
    //

    /**
     * IP address that will be checked against the database if enabled.
     * @var string
     */
    protected $ipAddress = '0.0.0.0';

    /**
     * User agent hash that will be checked against the database if enabled.
     * @var string
     */
    protected $userAgent = 'unknown';

    /**
     * The session ID hash
     * @var string
     */
    protected $sessionId = '';

    /**
     * Data stored by the user.
     * @var array
     */
    protected $data = [];

    /**
     * Flash data from the last request.
     * @var array
     */
    protected $lastFlashData = [];

    /**
     * Flash data for the next request.
     * @var array
     */
    protected $newFlashData = [];

    /**
     * Current Unix time
     * @var integer
     */
    protected $now;

    /**
     * Constructor
     *
     * Initialize the session handler.
     * @param object $db PDO Database Connection
     * @param array $config Configuration options
     * @return void
     */
    public function __construct(PDO $db, array $config)
    {
        // Set database connection handle
        $this->db = $db;

        // Set session configuration
        $this->setConfig($config);

        // Set current time
        $this->now = time();

        // Write session data to db on shutdown
        register_shutdown_function(function () {$this->write();});

        // If auto-run is set, run session
        if ($this->autoRunSession) {
            $this->run();
        }
    }

    /**
     * Run Session
     *
     * Start session
     * @return void
     */
    public function run()
    {
        // Run the session
        if (!$this->read()) {
            $this->create();
        }

        // Clean expired sessions and set cookie
        $this->cleanExpired();
        $this->setCookie();
    }

    /**
     * Set Data
     *
     * Set key => value or an array of key => values to the session data array.
     * @param mixed $newdata Session data array or string (key)
     * @param string $value Value for single key
     * @return void
     */
    public function setData($newdata, $value = '')
    {
        if (is_string($newdata)) {
            $newdata = [$newdata => $value];
        }

        if (!empty($newdata)) {
            foreach ($newdata as $key => $val) {
                $this->data[$key] = $val;
            }
        }
    }

    /**
     * Unset Data
     *
     * Unset a specific key from the session data array, or clear the entire array
     * @param string $key Session data array key
     * @return void
     */
    public function unsetData($key = null)
    {
        if ($key === null) {
            $this->data = [];
        }

        if ($key !== null and isset($this->data[$key])) {
            unset($this->data[$key]);
        }
    }

    /**
     * Get Data
     *
     * Return a specific key => value or the array of key => values from the session data array.
     * @param string $key Session data array key
     * @return mixed Value or array
     */
    public function getData($key = null)
    {
        if ($key === null) {
            return ($this->data) ? $this->data : null;
        }

        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    /**
     * Set Flash Data
     *
     * Set flash data that will persist only until next request
     * @param mixed $newdata Flash data array or string (key)
     * @param string $value Value for single key
     */
    public function setFlashData($newdata, $value = '')
    {
        if (is_string($newdata)) {
            $newdata = [$newdata => $value];
        }

        if (!empty($newdata)) {
            foreach ($newdata as $key => $val) {
                $this->newFlashData[$key] = $val;
            }
        }
    }

    /**
     * Get Flash Data
     *
     * Returns flash data
     * @param string $key Flsh data array key
     * @return mixed Value or array
     */
    public function getFlashData($key = null)
    {
        if ($key === null) {
            return $this->lastFlashData;
        }

        return isset($this->lastFlashData[$key]) ? $this->lastFlashData[$key] : null;
    }

    /**
     * Destroy Session
     *
     * Destroy the current session.
     * @return void
     */
    public function destroy()
    {
        // Deletes session from the database
        if (isset($this->sessionId)) {
            $stmt = $this->db->prepare("DELETE FROM {$this->tableName} WHERE session_id = ?");
            $stmt->execute([$this->sessionId]);
        }

        // Kill the cookie, every which way
        // https://www.owasp.org/index.php/PHP_Security_Cheat_Sheet#Proper_Deletion
        setcookie($this->cookieName, '', 1);
        setcookie($this->cookieName, false);
        unset($_COOKIE[$this->cookieName]);
    }

    /**
     * Read Session
     *
     * Loads and validates current session from database
     * @return boolean
     */
    private function read()
    {
        // Fetch session cookie
        $sessionId = isset($_COOKIE[$this->cookieName]) ? $_COOKIE[$this->cookieName] : false;

        // Cookie does not exist
        if (!$sessionId) {
            return false;
        }

        $this->sessionId = $sessionId;

        // Fetch the session from the database
        $stmt = $this->db->prepare("SELECT data, user_agent, ip_address, time_updated FROM {$this->tableName} WHERE session_id = ?");
        $stmt->execute([$this->sessionId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Run validations if a session exists
        if ($result !== false && !empty($result)) {

            // Check if the session has expired in the database
            if ($this->expireOnClose === false && ($result['time_updated'] + $this->secondsUntilExpiration) < $this->now) {
                $this->destroy();
                return false;
            }

            // Check if the IP address matches the one saved in the database
            if ($this->checkIpAddress === true && $result['ip_address'] !== $this->ipAddress) {
                $this->destroy();
                return false;
            }

            // Check if the user agent matches the one saved in the database
            if ($this->userAgent === true && $result['user_agent'] !== $this->userAgent) {
                $this->destroy();
                return false;
            }

            // Is it time to regenerate the session ID?
            if (($result['time_updated'] + $this->renewalTime) < $this->now) {
                $this->regenerateId();
            }

            // Make stored user data available
            if ($sessionData = json_decode($result['data'], true)) {
                $this->data = isset($sessionData['data']) ? $sessionData['data'] : [];
                $this->lastFlashData = isset($sessionData['flash']) ? $sessionData['flash'] : [];

                unset($sessionData);
            }

            // We have a valid session
            return true;
        }

        // No session found
        return false;
    }

    /**
     * Create Session
     *
     * Creates a new ession
     * @return void
     */
    private function create()
    {
        // Generate session ID
        $this->sessionId = $this->generateId();

        // Insert new session into database
        $stmt = $this->db->prepare("INSERT INTO {$this->tableName} (session_id, user_agent, ip_address, time_updated) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->sessionId, $this->userAgent, $this->ipAddress, $this->now]);
    }

    /**
     * Write Session Data
     *
     * Writes session data to the database.
     * @return void
     */
    private function write()
    {
        $sessionData['data'] = $this->data;
        $sessionData['flash'] = $this->newFlashData;

        // Write session data to database
        $stmt = $this->db->prepare("UPDATE {$this->tableName} SET data = ? WHERE session_id = ?");
        $stmt->execute([json_encode($sessionData), $this->sessionId]);

    }

    /**
     * Set Cookie
     *
     * Set session cookie
     * @return void
     */
    private function setCookie()
    {
        setcookie(
            $this->cookieName,
            $this->sessionId,
            ($this->expireOnClose) ? 0 : $this->now + $this->secondsUntilExpiration,
            '/',
            null,
            $this->secureCookie,
            true
        );
    }

    /**
     * Clean Old Sessions
     *
     * Removes expired sessions from the database
     * @return void
     */
    private function cleanExpired()
    {
        // 5% chance to clean the database of expired sessions
        if (mt_rand(1, 20) == 1) {
            $stmt = $this->db->prepare("DELETE FROM {$this->tableName} WHERE (time_updated + {$this->secondsUntilExpiration}) < {$this->now}");
            $stmt->execute();
        }
    }

    /**
     * Generate New Session ID
     *
     * Create a unique session ID
     * @return string
     */
    private function generateId()
    {
        $randomNumber = mt_rand(0, mt_getrandmax());
        $ipAddressFragment = md5(substr($this->ipAddress, 0, 5));
        $timestamp = md5(microtime(true) . $this->now);

        return hash('sha256', $randomNumber . $ipAddressFragment . $this->salt . $timestamp);
    }

    /**
     * Regenerate ID
     *
     * Regenerates a new session ID for the current session.
     * @return void
     */
    private function regenerateId()
    {
        // Acquire a new session ID
        $oldSessionId = $this->sessionId;
        $this->sessionId = $this->generateId();

        // Update session ID in the database
        $stmt = $this->db->prepare("UPDATE {$this->tableName} SET time_updated = ?, session_id = ? WHERE session_id = ?");
        $stmt->execute([$this->now, $this->sessionId, $oldSessionId]);
    }

    /**
     * Configure Session
     *
     * Set session handler class configuration
     *
     * @param array $config Configuration options
     * @return void
     */
    private function setConfig(array $config)
    {
        // Cookie name
        if (isset($config['cookieName'])) {
            if (!ctype_alnum($config['cookieName'])) {
                throw new Exception('Invalid cookie name provided.');
            }

            $this->cookieName = $config['cookieName'];
        }

        // Database table name
        if (isset($config['tableName'])) {
            $this->tableName = $config['tableName'];
        }

        // Expiration time in seconds
        if (isset($config['secondsUntilExpiration'])) {
            // Anything else than digits?
            if (!is_int($config['secondsUntilExpiration']) || $config['secondsUntilExpiration'] <= 0) {
                throw new Exception('Seconds until expiration must be a positive non-zero integer.');
            }

            $this->secondsUntilExpiration = (int) $config['secondsUntilExpiration'];
        }

        // How often should the session be renewed?
        if (isset($config['renewalTime'])) {
            // Anything else than digits?
            if (!is_int($config['renewalTime']) || $config['renewalTime'] <= 0) {
                throw new Exception('Session renewal time must be a valid non-zero integer.');
            }

            $this->renewalTime = (int) $config['renewalTime'];
        }

        // End the session when the browser is closed?
        if (isset($config['expireOnClose'])) {
            // Not true or false?
            if (!is_bool($config['expireOnClose'])) {
                throw new Exception('Expire on close must be either true or false.');
            }

            $this->expireOnClose = $config['expireOnClose'];
        }

        // Check IP addresses?
        if (isset($config['checkIpAddress']) && $config['checkIpAddress'] === true) {
            $this->checkIpAddress = true;
            $this->ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }

        // Check user agent?
        // Hashing HTTP_USER_AGENT only so it stores in a fixed size field
        if (isset($config['checkUserAgent']) && $config['checkUserAgent'] === true) {
            $this->checkUserAgent = true;
            $this->userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? hash('sha256', $_SERVER['HTTP_USER_AGENT']) : 'unknown';
        }

        // Send cookie only when HTTPS is enabled?
        if (isset($config['secureCookie'])) {
            if (!is_bool($config['secureCookie'])) {
                throw new Exception('The secure cookie option must be either true or false.');
            }

            $this->secureCookie = $config['secureCookie'];
        }

        // Salt key
        if (isset($config['salt'])) {
            $this->salt = $config['salt'];
        } else {
            throw new Exception('Session salt encryption key not set');
        }

        // Auto-Run
        if (isset($config['autoRunSession'])) {
            $this->autoRunSession = $config['autoRunSession'];
        }
    }
}
