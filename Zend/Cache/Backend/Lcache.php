<?php

// Change this to where LCache is located.
require_once 'LCache/vendor/autoload.php';

use \LCache\Address;
use \LCache\Integrated;
use \LCache\NullL1;
use \LCache\APCuL1;
use \LCache\L1CacheFactory;
use \LCache\DatabaseL2;
use \LCache\StaticL2;

require_once 'Zend/Cache/Backend/Interface.php';
require_once 'Zend/Cache/Backend.php';

class Zend_Cache_Backend_Lcache extends Zend_Cache_Backend implements Zend_Cache_Backend_Interface
{
	/**
	 * LCache backend options
	 *
	 * @var array
	 */
	protected $_options = array(
		'bin' => 's1',
		'pool' => 'p1',
		'group' => 'default',
		'can_expire' => true,
		'l1' => array(
			'type' => 'apcu',
			'config' => array()
		),
		'l2' => array(
			'type' => 'mysql',
			'prefix' => 'xg_',
			'config' => array()
		)
	);

	/**
	 * Holds the cached data
	 *
	 * @var array
	 */
	protected $_cache = array();

    /**
     * LCache object
     *
     * @var mixed $_lcache
     */
	protected $_lcache = null;

    /**
     * L1 object
     *
     * @var mixed $_l1
     */
	protected $_l1 = null;

    /**
     * L2 object
     *
     * @var mixed $_l2
     */
	protected $_l2 = null;

    /**
     * Constructor
     *
     * @param  array $options associative array of options
     * @throws Zend_Cache_Exception
     * @return void
     */
	public function __construct(array $options = array())
	{
		parent::__construct($options);

		if (!class_exists('\LCache\NullL1')) {
			Zend_Cache::throwException('Missing LCache library!');
		}

		if (!class_exists('\LCache\NullL1')) {
			Zend_Cache::throwException('Missing LCache library!');
		}

		if ($this->_options['l1']['type'] == 'apcu' && php_sapi_name() !== 'cli') {
			if (!function_exists('apcu_sma_info')) {
				Zend_Cache::throwException('The APCu extension is missing!');
				$missing['apcu-installed'] = 'APCu extension installed';
			} else if (function_exists('apcu_sma_info') && !@apcu_sma_info()) {
				Zend_Cache::throwException('The APCu extension is not enabled!');
			}
		}

		if ($this->_options['l2']['type'] == 'mysql' && empty($this->_options['l2']['config'])) {
			Zend_Cache::throwException('There was no configuration set for the MySQL L2!');
		}

		if (-1 === version_compare(PHP_VERSION, '5.6')) {
			Zend_Cache::throwException('PHP 5.6 or greater is required!');
		}

		if ($this->_options['pool'] == 'hostname') {
			$this->setOption('pool', gethostname());
		}
	}

	/**
	 * Test if a cache is available for the given id and (if yes) return it (false else)
	 *
	 * @param  string  $id                     Cache id
	 * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
	 * @return string|false cached datas
	 */
	public function load($id, $doNotTestCacheValidity = false)
	{
		if ($doNotTestCacheValidity) {
			$this->_log("Zend_Cache_Backend_Lcache::load() : \$doNotTestCacheValidity=true is unsupported by the LCache backend");
		}

		if ($this->_issetInternal($id)) {
			return $this->_getInternal($id);
		}

		$value = $this->callLCache('get', array($id));
		if (is_null($value)) {
			return false;
		}

		if (!is_numeric($value)) {
			$value = unserialize($value);
		}

		$this->_setInternal($id, $value);
		return $value;
	}

	/**
	 * Test if a cache is available for the given id and (if yes) return it (false else)
	 *
	 * Note : return value is always "string" (unserialization is done by the core not by the backend)
	 *
	 * @param  string  $id                     Cache id
	 * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
	 * @return string|false cached datas
	 */
	public function test($id)
	{
		if ($this->_issetInternal($id)) {
			return true;
		}

		return $this->callLCache('exists', array($id));
	}

	/**
	 * Save some string datas into a cache record
	 *
	 * Note : $data is always "string" (serialization is done by the
	 * core not by the backend)
	 *
	 * @param  string $data             Datas to cache
	 * @param  string $id               Cache id
	 * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
	 * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
	 * @return boolean True if no problem
	 */
	public function save($data, $id, $tags = array(), $specificLifetime = null)
	{
		if (is_object($data)) {
			$data = clone $data;
		}

		$this->_setInternal($id, $data);

		if (!is_numeric($data) || intval($data) !== $data) {
			$data = serialize($data);
		}

		$lifetime = null;
		if ($this->_options['can_expire']) {
			$lifetime = $this->getLifetime($specificLifetime);
		}

		return (bool) $this->callLCache('set', array($id), $data, $lifetime);
	}

	/**
	 * Save some string datas into a cache record
	 *
	 * Note : $data is always "string" (serialization is done by the
	 * core not by the backend)
	 *
	 * @param  string $data            Datas to cache
	 * @param  string $id              Cache id
	 * @param  array $tags             Array of strings, the cache record will be tagged by each string entry
	 * @param  int   $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
	 * @return boolean true if no problem
	 */
	public function remove($id)
	{
		if (!$this->test($id)) {
			return false;
		}

		$this->callLCache('delete', array($id));
		$this->_unsetInternal($id);

		return true;
	}

	/**
	 * Remove a cache record
	 *
	 * @param  string $id cache id
	 * @return boolean true if no problem
	 */
	public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
	{
		switch ($mode) {
			case Zend_Cache::CLEANING_MODE_ALL:
				return $this->callLCache('delete', array(null));
				break;
			case Zend_Cache::CLEANING_MODE_OLD:
				$this->_log("Zend_Cache_Backend_Lcache::clean() : CLEANING_MODE_OLD is unsupported by the Apc backend");
				break;
			case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
			case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
			case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
				$this->_log(self::TAGS_UNSUPPORTED_BY_CLEAN_OF_APC_BACKEND);
				break;
			default:
				Zend_Cache::throwException('Invalid mode for clean() method');
				break;
		}
	}

	/**
	 * Clean some cache records
	 *
	 * Available modes are :
	 * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
	 * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
	 * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
	 *                                               ($tags can be an array of strings or a single string)
	 * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
	 *                                               ($tags can be an array of strings or a single string)
	 * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
	 *                                               ($tags can be an array of strings or a single string)
	 *
	 * @param  string $mode Clean mode
	 * @param  array  $tags Array of tags
	 * @return boolean true if no problem
	 */
	public function callLCache($method)
	{
		$arguments = func_get_args();
		array_shift($arguments); // ignore $method

		if ($this->_getConnection()) {
			$address = new Address($this->_options['bin'], $arguments[0][0]);

			$passed_args = $arguments;
			if (isset($passed_args[0])) {
				$passed_args[0] = $address;
			}

			return call_user_func_array(array($this->_lcache, $method), $passed_args);
		}

		// Mock expected behavior from APCu for these methods
		switch ($method) {
			case 'delete':
				return true;
			case 'exists':
			case 'get':
				return null;
		}
	}

	/**
	 * Callback to purge items from the L2 cache
	 */
	public function collectGarbage($itemLimit = null)
	{
		$this->_lcache->collectGarbage($itemLimit);
	}

	/**
	 * Get a value from the internal object cache
	 *
	 * @param string $key
	 * @return mixed
	 */
	protected function _getInternal($key)
	{
		$value = null;
		$group = $this->_options['group'];

		if (isset($this->_cache[$group][$key])) {
			$value = $this->_cache[$group][$key];
		}

		if (is_object($value)) {
			return clone $value;
		}

		return $value;
	}

	/**
	 * Set a value to the internal object cache
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	protected function _setInternal($key, $value)
	{
		// LCache expects null to be an empty string
		if (is_null($value)) {
			$value = '';
		}

		$this->_cache[$this->_options['group']][$key] = $value;
	}

	/**
	 * Check whether there's a value in the internal object cache.
	 *
	 * @param string $key
	 * @return boolean
	 */
	protected function _issetInternal($key)
	{
		return isset($this->_cache[$this->_options['group']][$key]);
	}

	/**
	 * Utility function to generate the APCu key for a given key and group.
	 *
	 * @param  string $key   The cache key.
	 * @return string        A properly prefixed APCu cache key.
	 */
	protected function _unsetInternal($key)
	{
		$group = $this->_options['group'];
		if (isset($this->_cache[$group][$key])) {
			unset($this->_cache[$group][$key]);
		}
	}

	/**
	 * Return the connection resource
	 *
	 * If we are not connected, the connection is made
	 *
	 * @throws Zend_Cache_Exception
	 * @return resource Connection resource
	 */
	protected function _getConnection()
	{
		if ($this->_lcache === null) {
			$pool = $this->_options['pool'];
			$l1Config = $this->_options['l1']['config'];

			$liFactory = new L1CacheFactory();

			if ($this->_options['l1']['type'] == 'apcu') {
				$this->_l1 = $liFactory->create('apcu', $pool);
			}

			if (is_null($this->_l1)) {
				$this->_l1 = $liFactory->create('null', $pool);				
			}

			$l2Config = $this->_options['l2']['config'];

			if ($this->_options['l2']['type'] == 'mysql') {
				try {
					$db = Zend_Db::factory('Pdo_Mysql',
						array(
							'host' => $l2Config['host'],
							'port' => $l2Config['port'],
							'username' => $l2Config['username'],
							'password' => $l2Config['password'],
							'dbname' => $l2Config['dbname'],
							'driver_options' => array(
								PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
								PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode="ANSI_QUOTES"'
							)
						)
					);

					$this->_l2 = new DatabaseL2($db->getConnection(), $this->_options['l2']['prefix'], true);
				} catch (Zend_Db_Adapter_Exception $e) {
					Zend_Cache::throwException('Failed to connect to the MySQL Server!', $e);
				}
			}

			if (is_null($this->_l2)) {
				$this->_l2 = new StaticL2();
			}

			$this->_lcache = new Integrated($this->_l1, $this->_l2);
			$this->_lcache->synchronize();

			if ($errors = $this->_l2->getErrors()) {
				Zend_Cache::throwException('There were errors connecting to the database! Either the required LCache tables do not exist or another error has occured.');
			}
		}

		return $this->_lcache;
	}
}