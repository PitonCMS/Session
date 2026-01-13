<?php

/**
 * PitonCMS (https://github.com/PitonCMS)
 *
 * @link      https://github.com/PitonCMS
 * @copyright Copyright 2015 - 2026 Wolfgang Moritz
 * @license   AGPL-3.0-or-later with Theme Exception. See LICENSE file for details.
 */

declare(strict_types=1);

namespace Piton\Session;

use Exception;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Piton Session Handler
 *
 * Manage http session state across page views.
 * @version 3.0.0
 */
class SessionHandler
{
    /**
     * PDO database handle
     * @var PDO connection object
     */
    protected PDO $db;

    /**
     * Logger
     * @var Psr\Log\LoggerInterface
     */
    protected ?LoggerInterface $log = null;

    /**
     * Cookie name
     * @var string
     */
    protected string $cookieName = 'sessionCookie';

    /**
     * Database table
     * @var string
     */
    protected string $tableName = 'session';

    /**
     * Number of seconds before the session expires
     * @var int
     */
    protected int $secondsUntilExpiration = 7200;

    /**
     * Number of seconds before the session ID is regenerated
     * @var int
     */
    protected int $renewalTime = 300;

    /**
     * Whether to kill the session when the browser is closed
     * @var bool
     */
    protected bool $expireOnClose = false;

    /**
     * Whether to check IP address in validating session ID
     * @var bool
     */
    protected bool $checkIpAddress = false;

    /**
     * Whether to check the user agent in validating a session
     * @var bool
     */
    protected bool $checkUserAgent = false;

    /**
     * Will only set the session cookie if a secure HTTPS connection is being used
     * @var bool
     */
    protected bool $secureCookie = false;

    /**
     * Encyrption key to salt hash
     * @var string
     */
    protected string $salt = '';

    /**
     * Auto-Run Session
     * @var bool
     */
    protected bool $autoRunSession = true;

    /**
     * IP address that will be checked against the database if enabled.
     * @var string
     */
    protected string $ipAddress = '0.0.0.0';

    /**
     * User agent hash that will be checked against the database if enabled.
     * @var string
     */
    protected string $userAgent = 'unknown';

    /**
     * The session ID hash
     * @var string
     */
    protected string $sessionId = '';

    /**
     * Data stored by the user.
     * @var array
     */
    protected array $data = [];

    /**
     * Flash data from the last request.
     * @var array
     */
    protected array $lastFlashData = [];

    /**
     * Flash data for the next request.
     * @var array
     */
    protected array $newFlashData = [];

    /**
     * Current Unix time
     * @var int
     */
    protected int $now;

    /**
     * Constructor
     *
     * Initialize the session handler.
     * @param object $db     PDO Database Connection
     * @param array  $config Configuration options
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
        register_shutdown_function(function () {
            $this->write();
        });

        // If auto-run is set, run session
        if ($this->autoRunSession) {
            $this->run();
        }
    }

    /**
     * Run Session
     *
     * Start session
     * @param void
     * @return void
     */
    public function run(): void
    {
        // Run the session
        if (!$this->read()) {
            if ($this->log) {
                $this->log->info('PitonSession: Create new session');
            }

            $this->create();
        }

        // Clean expired sessions
        $this->cleanExpired();
    }

    /**
     * Set Data
     *
     * Set key => value or an array of key => values to the session data array.
     * @param mixed  $newdata  Session data array or string (key)
     * @param string $value    Value for single key
     * @return void
     */
    public function setData($newdata, $value = ''): void
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
    public function unsetData($key = null): void
    {
        if ($key === null) {
            $this->data = [];
        }

        if ($key !== null && isset($this->data[$key])) {
            unset($this->data[$key]);
        }
    }

    /**
     * Get Data
     *
     * Return a specific key => value or the array of key => values from the session data array.
     * @param string $key Session data array key
     * @return mixed      Value or array, default null
     */
    public function getData($key = null)
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }

    /**
     * Set Flash Data
     *
     * Set flash data that will persist only until next request
     * @param mixed  $newdata Flash data array or string (key)
     * @param string $value   Value for single key
     * @return void
     */
    public function setFlashData($newdata, $value = ''): void
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
     * @param string $key Flash data array key
     * @return mixed      Value or array
     */
    public function getFlashData($key = null)
    {
        if ($key === null) {
            return $this->lastFlashData;
        }

        return $this->lastFlashData[$key] ?? null;
    }

    /**
     * Destroy Session
     *
     * Destroy the current session.
     * @return void
     */
    public function destroy()
    {
        if ($this->log) {
            $this->log->info("PitonSession: Deleting session {$this->sessionId}");
        }

        // Deletes session from the database
        if (isset($this->sessionId)) {
            $stmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE `session_id` = ?;");
            $stmt->execute([$this->sessionId]);
        }

        // Kill the cookie by setting the value to empty, and expires to one year ago
        $this->setCookie("", time() - 60 * 60 * 24 * 365);
        unset($_COOKIE[$this->cookieName]);
    }

    /**
     * Read Session
     *
     * Loads and validates current session from database
     * @return bool
     */
    protected function read(): bool
    {
        // Fetch session cookie
        $sessionId = $_COOKIE[$this->cookieName] ?? false;

        if ($this->log) {
            $this->log->info("PitonSession: Reading session from cookie $sessionId");
        }

        // Cookie does not exist
        if (!$sessionId) {
            return false;
        }

        $this->sessionId = $sessionId;

        // Fetch the session from the database
        $stmt = $this->db->prepare("SELECT `data`, `user_agent`, `ip_address`, `time_updated` FROM `{$this->tableName}` WHERE `session_id` = ?;");
        $stmt->execute([$this->sessionId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Run validations if a session exists
        if ($result !== false && !empty($result)) {
            // Check if the session has expired in the database. The cookie should have expired so this will likely not run...
            if ($this->expireOnClose === false && ($result['time_updated'] + $this->secondsUntilExpiration) < $this->now) {
                if ($this->log) {
                    $this->log->info("PitonSession: Session out of date. Time updated {$result['time_updated']}. Duration {$this->secondsUntilExpiration}. Now {$this->now}");
                }
                $this->destroy();

                return false;
            }

            // Check if the IP address matches the one saved in the database
            if ($this->checkIpAddress === true && $result['ip_address'] !== $this->ipAddress) {
                if ($this->log) {
                    $this->log->info("PitonSession: Saved IP address {$result['ip_address']} does not match client IP {$this->ipAddress}");
                }
                $this->destroy();

                return false;
            }

            // Check if the user agent matches the one saved in the database
            if ($this->checkUserAgent === true && $result['user_agent'] !== $this->userAgent) {
                if ($this->log) {
                    $this->log->info("PitonSession: Saved user agent {$result['user_agent']} does not match client agent {$this->userAgent}");
                }
                $this->destroy();

                return false;
            }

            // Is it time to regenerate the session ID?
            if (($result['time_updated'] + $this->renewalTime) < $this->now) {
                if ($this->log) {
                    $this->log->info("PitonSession: Time to regenerate session ID");
                }
                $this->regenerateId();
            }

            // Make stored user data available
            if ($sessionData = json_decode($result['data'], true)) {
                $this->data = $sessionData['data'] ?? [];
                $this->lastFlashData = $sessionData['flash'] ?? [];

                unset($sessionData);
            }

            // We have a valid session
            if ($this->log) {
                $this->log->info("PitonSession: Valid session found");
            }

            return true;
        }

        if ($this->log) {
            $this->log->info("PitonSession: No session found in table");
        }

        // Fall back is failure
        return false;
    }

    /**
     * Create Session
     *
     * Creates a new ession
     * @return void
     */
    protected function create(): void
    {
        // Generate session ID
        $this->sessionId = $this->generateId();

        if ($this->log) {
            $this->log->info("PitonSession: Generating and saving new sesion ID {$this->sessionId}");
        }

        // Insert new session into database
        $stmt = $this->db->prepare("INSERT INTO `{$this->tableName}` (`session_id`, `user_agent`, `ip_address`, `time_updated`) VALUES (?, ?, ?, ?);");
        $stmt->execute([$this->sessionId, $this->userAgent, $this->ipAddress, $this->now]);

        // Set matching cookie
        $this->setCookie();
    }

    /**
     * Write Session Data
     *
     * Writes session data to the database.
     * @return void
     */
    protected function write(): void
    {
        $sessionData['data'] = $this->data;
        $sessionData['flash'] = $this->newFlashData;

        // Write session data to database
        $stmt = $this->db->prepare("UPDATE `{$this->tableName}` SET `data` = ? WHERE `session_id` = ?;");
        $stmt->execute([json_encode($sessionData), $this->sessionId]);
    }

    /**
     * Set Cookie
     *
     * Set session cookie
     * @param  string $value   Cookie value, defaults to $this->sessionId
     * @param  int   $expires Life of cookie, defaults to now + $this->secondsUntilExpiration
     * @return void
     */
    protected function setCookie(?string $value = null, ?int $expires = null): void
    {
        $value = $value ?? $this->sessionId;
        $expires = $expires ?? (($this->expireOnClose) ? 0 : $this->now + $this->secondsUntilExpiration);
        if ($this->log) {
            $expiresDate = date('c', $expires);
            $this->log->info("PitonSession: Setting cookie '{$this->cookieName}', value $value, until $expiresDate");
        }

        $cookieSet = setcookie(
            $this->cookieName,
            $value,
            [
                'expires' => $expires,
                'path' => '/',
                // 'domain' => getenv('HTTP_HOST'),
                'secure' => $this->secureCookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Log outcome of trying to set cookie
        if ($this->log) {
            $cookieSet = ($cookieSet) ? 'true' : 'false';
            $this->log->info("PitonSession: Set cookie status $cookieSet");
        }
    }

    /**
     * Clean Old Sessions
     *
     * Removes expired sessions from the database
     * @return void
     */
    protected function cleanExpired(): void
    {
        // 10% chance to clean the database of expired sessions
        if (mt_rand(1, 10) == 1) {
            if ($this->log) {
                $this->log->info("PitonSession: Cleaning expired sessions");
            }

            $expiredTime = $this->now - $this->secondsUntilExpiration;
            $stmt = $this->db->prepare("DELETE FROM `{$this->tableName}` WHERE `time_updated` < {$expiredTime};");
            $stmt->execute();
        }
    }

    /**
     * Generate New Session ID
     *
     * Create a unique session ID
     * @return string
     */
    protected function generateId(): string
    {
        return hash_hmac('sha256', random_bytes(32) . $this->ipAddress, $this->salt);
    }

    /**
     * Regenerate ID
     *
     * Regenerates a new session ID for the current session.
     * @return void
     */
    protected function regenerateId(): void
    {
        // Acquire a new session ID
        $oldSessionId = $this->sessionId;
        $this->sessionId = $this->generateId();

        if ($this->log) {
            $this->log->info("PitonSession: New session ID {$this->sessionId}");
        }

        // Update session ID in the database
        $stmt = $this->db->prepare("UPDATE `{$this->tableName}` SET `time_updated` = ?, `session_id` = ? WHERE `session_id` = ?;");
        $stmt->execute([$this->now, $this->sessionId, $oldSessionId]);

        // Set cookie with new name
        $this->setCookie();
    }

    /**
     * Configure Session
     *
     * Set session handler class configuration
     *
     * @param array $config Configuration options
     * @return void
     */
    protected function setConfig(array $config): void
    {
        // Cookie name
        if (isset($config['cookieName'])) {
            if (!ctype_alnum($config['cookieName'])) {
                throw new Exception('PitonSession: Invalid cookie name provided.');
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
                throw new Exception('PitonSession: Seconds until expiration must be a positive non-zero integer.');
            }

            $this->secondsUntilExpiration = (int) $config['secondsUntilExpiration'];
        }

        // How often should the session be renewed?
        if (isset($config['renewalTime'])) {
            // Anything else than digits?
            if (!is_int($config['renewalTime']) || $config['renewalTime'] <= 0) {
                throw new Exception('PitonSession: Session renewal time must be a valid non-zero integer.');
            }

            $this->renewalTime = (int) $config['renewalTime'];
        }

        // End the session when the browser is closed?
        if (isset($config['expireOnClose'])) {
            // Not true or false?
            if (!is_bool($config['expireOnClose'])) {
                throw new Exception('PitonSession: Expire on close must be either true or false.');
            }

            $this->expireOnClose = $config['expireOnClose'];
        }

        // Check IP addresses?
        if (isset($config['checkIpAddress']) && $config['checkIpAddress'] === true) {
            $this->checkIpAddress = true;
            $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        // Check user agent?
        // Truncate HTTP_USER_AGENT so it stores in a 64 CHAR size field
        if (isset($config['checkUserAgent']) && $config['checkUserAgent'] === true) {
            $this->checkUserAgent = true;
            $this->userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 64) : 'unknown';
        }

        // Set secure cookie to false, but override setting if provided explicitly
        $this->secureCookie = false;
        if (isset($config['secureCookie'])) {
            if (!is_bool($config['secureCookie'])) {
                throw new Exception('PitonSession: The secure cookie option must be either true or false.');
            }

            $this->secureCookie = $config['secureCookie'];
        }

        // Salt key
        if (isset($config['salt'])) {
            $this->salt = $config['salt'];
        } else {
            throw new Exception('PitonSession: Session salt encryption key not set');
        }

        // Auto-Run
        if (isset($config['autoRunSession'])) {
            $this->autoRunSession = $config['autoRunSession'];
        }

        // Has a PSR3 logger been provided?
        if (isset($config['log'])) {
            if ($config['log'] instanceof LoggerInterface) {
                $this->log = $config['log'];
            } else {
                throw new Exception("PitonSession: Option 'logger' must be an instance of Psr\Log\LoggerInterface");
            }
        }
    }
}
