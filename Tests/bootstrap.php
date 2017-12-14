<?php
/**
 * @package     FOF
 * @copyright   2010-2017 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license     GNU GPL version 2 or later
 */

// Required to load FOF and Joomla!
use FOF30\Tests\Helpers\TravisLogger;

define('_JEXEC', 1);
define('JDEBUG', 0);

if (!defined('JPATH_TESTS'))
{
	define('JPATH_TESTS', __DIR__);
}

// Include the FOF autoloader.
if (!class_exists('FOF30\\Autoloader\\Autoloader'))
{
	require_once __DIR__ . '/../fof/Autoloader/Autoloader.php';

	if (!class_exists('FOF30\\Autoloader\\Autoloader'))
	{
		echo 'ERROR: FOF Autoloader not found' . PHP_EOL;

		exit(1);
	}
}

require_once __DIR__ . '/../fof/Utils/helpers.php';

// Tell the FOF autoloader where to load test classes from (very useful for stubs!)
\FOF30\Autoloader\Autoloader::getInstance()->addMap('FOF30\\Tests\\', __DIR__);
\FOF30\Autoloader\Autoloader::getInstance()->addMap('Fakeapp\\', __DIR__ . '/Stubs/Fakeapp');
\FOF30\Autoloader\Autoloader::getInstance()->addMap('Dummyapp\\', __DIR__ . '/Stubs/Dummyapp');

TravisLogger::reset();
TravisLogger::log(4, 'Log reset');

// Include the Composer autoloader.
if (false == include_once __DIR__ . '/../vendor/autoload.php')
{
	echo 'ERROR: You need to install Composer and run `composer install` on FOF before running the tests.' . PHP_EOL;

	exit(1);
}

TravisLogger::log(4, 'Autoloader included');

// Don't report strict errors. This is needed because sometimes a test complains about arguments passed as reference
ini_set('zend.ze1_compatibility_mode', '0');
error_reporting(E_ALL & ~E_STRICT);
ini_set('display_errors', 1);

/**
  * PHPUnit 6 introduced a breaking change that
  * removed PHPUnit_Framework_TestCase as a base class,
  * and replaced it with \PHPUnit\Framework\TestCase
  */
if (!class_exists('\PHPUnit_Framework_TestCase') && class_exists('\PHPUnit\Framework\TestCase'))
{
    class_alias('\PHPUnit\Framework\TestCase', '\PHPUnit_Framework_TestCase');
}

if (!class_exists('\PHPUnit_Extensions_Database_TestCase') && class_exists('\PHPUnit\DbUnit\TestCase'))
{
    class_alias('\PHPUnit_Extensions_Database_TestCase', '\PHPUnit\DbUnit\TestCase');
}

// Fixed timezone to preserve our sanity
@date_default_timezone_set('UTC');

$jversion_test = getenv('JVERSION_TEST') ? getenv('JVERSION_TEST') : 'staging';

TravisLogger::log(4, 'Including environment info. Joomla version: ' . $jversion_test);

require_once __DIR__ . '/environments.php';

if (!isset($environments[$jversion_test]))
{
	echo('Joomla environment ' . $jversion_test . ' not recognized');
	TravisLogger::log(4, 'Joomla environment ' . $jversion_test . ' not recognized');
	exit(1);
}

$siteroot = $environments[$jversion_test];

TravisLogger::log(4, 'Siteroot for this tests: ' . $siteroot);

if (!$siteroot)
{
	echo('Empty siteroot, we can not continue');
	TravisLogger::log(4, 'Empty siteroot, we can not continue');
	exit(1);
}

//Am I in Travis CI?
if (getenv('TRAVIS'))
{
	TravisLogger::log(4, 'Including special Travis configuration file');
	require_once __DIR__ . '/config_travis.php';

	// Set the test configuration site root if not set in travis
	if (!isset($fofTestConfig['site_root']))
	{
		$fofTestConfig['site_root'] = $siteroot;
	}
}
else
{
	if (!file_exists(__DIR__ . '/config.php'))
	{
		echo "Configuration file not found. Please copy the config.dist.php file and rename it to config.php\n";
		echo "Then update its contents with the connection details to your database";
		exit(1);
	}

	require_once __DIR__ . '/config.php';

	if (isset($fofTestConfig['site_root']))
	{
		$siteroot = $fofTestConfig['site_root'];
	}
}

if (!isset($fofTestConfig['host']) || !isset($fofTestConfig['user']) || !isset($fofTestConfig['password']) || !isset($fofTestConfig['db']))
{
	echo "Your config file is missing one or more required info. Please copy the config.dist.php file and rename it to config.php\n";
	echo "then update its contents with the connection details to your database";
	exit(1);
}

TravisLogger::log(4, 'Including defines.php from Joomla environment');

// Set up the Joomla! environment
if (file_exists($siteroot . '/defines.php'))
{
	include_once $siteroot . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', $siteroot);

	require_once JPATH_BASE . '/includes/defines.php';
}

// Bootstrap the CMS libraries.
TravisLogger::log(4, 'Bootstrap the CMS libraries.');
require_once JPATH_LIBRARIES . '/import.legacy.php';
require_once JPATH_LIBRARIES . '/cms.php';

// Since there is no configuration file inside Joomla cloned repo, we have to read the installation one...
TravisLogger::log(4, 'Including configuration.php-dist from Joomla environment');
$config = JFactory::getConfig(JPATH_SITE . '/installation/configuration.php-dist');

TravisLogger::log(4, 'Changing values for the JConfig object');
// ... and then hijack some details
$dbtype = function_exists('mysqli_connect') ? 'mysqli' : 'mysql';
$config->set('dbtype', $dbtype);
$config->set('host', $fofTestConfig['host']);
$config->set('user', $fofTestConfig['user']);
$config->set('password', $fofTestConfig['password']);
$config->set('db', $fofTestConfig['db']);
$config->set('tmp_path', JPATH_ROOT . '/tmp');
$config->set('log_path', JPATH_ROOT . '/logs');
// Despite its name, this is the session STORAGE, NOT the session HANDLER. Because that somehow makes sense. NOT.
$config->set('session_handler', 'none');

// We need to set up the JSession object
require_once 'Stubs/Session/FakeSession.php';
$sessionHandler = new JSessionHandlerFake();
$session = JSession::getInstance('none', array(), $sessionHandler);
$input = new JInputCli();
$dispatcher = new JEventDispatcher();
$session->initialise($input, $dispatcher);
JFactory::$session = $session;

// Do I have a Joomla database schema ready? If not, let's import the installation SQL file
$db = JFactory::getDbo();

try
{
	TravisLogger::log(4, 'Checking if core tables are there');
	$db->setQuery('SHOW COLUMNS FROM `jos_assets`')->execute();
}
catch (Exception $e)
{
	TravisLogger::log(4, 'Core tables not found, attempt to create them');

	// Core table missing, let's import them
	$file    = JPATH_SITE . '/installation/sql/mysql/joomla.sql';
	$queries = $db->splitSql(file_get_contents($file));

	foreach ($queries as $query)
	{
		$query = trim($query);

		if (!$query)
		{
			continue;
		}

		try
		{
			$db->setQuery($query)->execute();
		}
		catch (Exception $e)
		{
			// Something went wrong, let's log the exception and then throw it again
			TravisLogger::log(4, 'An error occurred while creating core tables. Error: ' . $e->getMessage());
			throw $e;
		}
	}
}

TravisLogger::log(4, 'Create test specific tables');

// Let's use our class to create the schema
$importer = new \FOF30\Database\Installer(JFactory::getDbo(), JPATH_TESTS . '/Stubs/schema');
$importer->updateSchema();
unset($importer);

TravisLogger::log(4, 'Boostrap ended');
