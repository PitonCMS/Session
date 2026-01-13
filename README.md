# Piton Session Handler

This class maintains session state across page views. A hashed, salted session key is set in a cookie which is the key to the session record in a MySQL table. The session key is regenerated every 5 minutes or as set in the configuation array. No information, other than the key, is stored client side. All user session information is kept server side in your database.

When the session starts the session handler looks for a session record matching the cookie key, and if found then runs optional checks to validate the session. If any of the checks fail, or if the session has timed out, the session is destoyed and a new session is started.

Session data can be set and retrieved at any time as either a key-value pair, or an array of key-value pairs. Flash data is also supported, which only persists until the next request.

## Installation
You can use Composer to install the session handler or just download the files to your project.

### Using Composer
Either require the project in composer,

```sh
composer require pitoncms/session
```

or modify your `composer.json` project file to require this package and run an update or install.

```json
"require": {
  "pitoncms/session": "^3.0.0"
}
```

This will download the files and register the class with the composer autoloader.

### Or Just the Files, Please
If you do not use Composer, download this project and unzip. The only file you need is `src/SessionHandler.php`. Place that file in your project and be sure to include it in your startup script.

### Create the Table
You will need to create the session table in your MySQL database using the **SessionTable.sql** script. You can change the table name in the script if desired, but you will need to provide the table name as a configuration item for `tableName`.

## Usage
To use the session handler create a new instance of `Piton\Session\SessionHandler` passing in a PDO database connection and the configuration array.

### PDO Connection
Define a new PDO connection and pass it in as the first argument of the constructor.

```php
$dsn = 'mysql:host=' . HOST . ';dbname=' . DBNAME;
$dbh = new PDO($dsn, USERNAME, PASSWORD, DB_OPTIONS);
```

### Define Configuruation
Define a configuration array and only include the options you wish to change. The only required configuration option is your application's encryption key. Use a long, random string and do not share it.

**Options**

Option|Default|Description
---|:---:|---
cookieName | 'sessionCookie' | Your session cookie name.
tableName|'session'|Name of the MySQL table that stores your sessions.
secondsUntilExpiration|7200|How long before the session expires in seconds.
renewalTime|300|How long before the session key is regenerated in seconds.
expireOnClose|false|Whether or not to destroy the session when the browser closes, true or false.
checkIpAddress|false|Whether or not to match the IP address to the stored session, true or false.
checkUserAgent|false|Whether or not to match the User Agent to the stored session, true or false.
secureCookie|false|Whether or not to set cookie when on an HTTPS connection, true or false.
salt|*none*|Your custom encryption key. Any long (16+ characters) string of characters.
autoRunSession|true|Whether to run the session automatically, or only needed.

```php
$config['salt'] = 'akjfao8ygoa8hba9707lakusdof87'; // Use your own salt, not this one!
$config['secondsUntilExpiration'] = 1800; // 30 minutes. Can also use 60*60*24 to specify 1 day

// Other configuration options ...
```

### Creating a Session
Create a new session as part of your application flow.

```php
$Session = new Piton\Session\SessionHandler($dbh, $config);
```

The session runs immediately if `autoRunSession` is set to true (which is the default) and checks for a valid session, regenerates the session key if necessary, and loads any existing session data for immediate retrieval. Create the session object once, or simply add it to your Dependency Injection Container as a singleton.

When the session runs, it queries the database. If you need to make the session class available but do not need to immediately run the session you can set autoRunSession to false in the configuration. Then when you are ready to run a session call the `run()` method.

```php
$Session->run();
```

### Save Session Data
Once the session is running, you can add or update session data by passing in key-value pairs or an array of key-value pairs using the `setData()` method. The value can be any type as long as it is serializable to JSON.

```php
// Save simple key-value pair
$Session->setData('lupine', 'wolf');

// Save array of key-value pairs
$sessionData = array(
  'feline' => 'cat',
  'canine' => 'dog',
  'lastModified' => 1422592486
);
$Session->setData($sessionData);
```

Supplying the same key will overwrite any prior value saved in session.

### Get Session Data
To get session data pass in the item key to `getData()`. The method returns `null` if no key was found. To get all session data simply do not provide an argument.

```php
// Get one session item
$value = $Session->getData('keyName');

// Get all session items
$values = $Session->getData();
```

### Save Flash Data
Flash data will only stay until the next request, after which it is removed automatically.

You can add flash data by passing in a string, a key-value pair, or an array of key-value pairs using the `setFlashData()` method. The value can be any type as long as it is serializable to JSON.

```php
// Save simple key-value pair
$Session->setFlashData('Something went wrong!');
$Session->setFlashData('alert', 'Something went very wrong!');
```

### Get Flash Data
To get flash data pass in the item key to `getFlashData()`. The method returns `null` if no key was found. To get all flash data simply do not provide an argument. Flash data is automatically removed on the next request, so there's no need to explicitly unset flash data.

```php
// Get one flash item
$value = $Session->getFlashData('alert');

// Get all flash items
$values = $Session->getFlashData();
```

### Unset Session Data
You can delete a session item by passing in the item key to `unsetData()`, or delete all session data by not providing an argument.

```php
// Delete one session item
$Session->unsetData('keyName');

// Delete all session data
$Session->unsetData();
```

### Delete a Session
Any session that does not pass validation will be destoyed automatically. But, if you want to delete the current session call the `destroy()` method.

```php
$Session->destroy();
```

# WARNING
Use this session class at your own risk. Read the code, and understand what it does if you intend on using this for for anything secure. We make no warranty if something goes wonky.
