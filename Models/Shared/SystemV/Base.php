<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shared\SystemV;

abstract class Base
{
	protected $_guid=null;
	protected $_isInit=false;
	protected $_isTerm=false;
	protected $_name=null;
	protected $_size=null;
	protected $_perm=null;
	protected $_shmRes=null;
	protected $_parentObj=null;
	
	//stay way elow _mapsId for custom Ids, as we allocate ids incrementally below that value
	protected $_mapsId=7654321; //Id that holds key maps

	public function __construct($name=null, $size=null, $perm=null)
	{
		$this->_guid		= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		$this->_parentObj	= \MTM\Memory\Factories::getShared()->getSystemFive();
		
		$this->_name		= $name;
		$this->_size		= $size;
		$this->_perm		= $perm;
		if ($this->_name === null) {
			$this->_name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		}
		if ($this->_size === null) {
			$this->_size	= ini_get("sysvshm.init_mem"); // want to be explicit about the segment size
			if ($this->_size === false) {
				$this->_size	= 10000;
			}
		} elseif ($this->_size < 40) {
			throw new \Exception("Minimum segment size is 40 bytes");
		}
		if ($this->_perm === null) {
			$this->_perm	= "0644";
		} else {
			$this->_perm	= str_repeat("0", 4 - strlen($this->_perm)) . $this->_perm;
		}
		$this->initialize();
	}
	public function __destruct()
	{
		$this->terminate(); //must be implemented by child, need interface
	}
	public function getGuid()
	{
		return $this->_guid;
	}
	public function isInit()
	{
		return $this->_isInit;
	}
	public function isTerm()
	{
		return $this->_isTerm;
	}
	public function getName()
	{
		return $this->_name;
	}
	public function getPermissions()
	{
		return $this->_perm;
	}
	public function getSize()
	{
		return $this->_size;
	}
	public function getParent()
	{
		return $this->_parentObj;
	}
	public function writeLock()
	{
		$this->rwLock();
		return $this;
	}
	public function writeUnlock()
	{
		$this->rwUnlock();
		return $this;
	}
	public function write($id, $value)
	{
		if ($this->isTerm() === false) {
			$this->writeLock();
			$success	= shm_put_var($this->_shmRes, $id, $value);
			$this->writeUnlock();
			if ($success === true) {
				return $this;
			} else {
				throw new \Exception("Failed to set id: " . $id);
			}
		} else {
			throw new \Exception("Terminated, cannot write");
		}
	}
	public function set($value, $key=null)
	{
		$this->writeLock();
		try {

			$hash	= hash("sha256", $key);
			$maps	= shm_get_var($this->_shmRes, $this->_mapsId);
			if (array_key_exists($hash, $maps) === false) {
				$maps[$hash]	= $maps["nextId"];
				$maps["nextId"]--;
				$this->write($this->_mapsId, $maps);
			}
			$this->write($maps[$hash], $value);

		} catch (\Exception $e) {
			$this->writeUnlock();
			throw $e;
		}
		$this->writeUnlock();
		return $this;
	}
	public function readLock()
	{
		$this->roLock();
		return $this;
	}
	public function readUnlock()
	{
		$this->roUnlock();
		return $this;
	}
	public function read($id)
	{
		if ($this->isTerm() === false) {
			$this->readLock();
			if (shm_has_var($this->_shmRes, $id) === true) {
				$data	= @shm_get_var($this->_shmRes, $id);
				$this->readUnlock();
			} else {
				$this->readUnlock();
				throw new \Exception("Read failed. Id does not exist: " . $id);
			}
			return $data;
		} else {
			throw new \Exception("Terminated, cannot read");
		}
	}
	public function get($key=null, $throw=true)
	{
		if ($this->isTerm() === false) {
			$this->readLock();
			
			try {
				
				$hash	= hash("sha256", $key);
				$maps	= shm_get_var($this->_shmRes, $this->_mapsId);
				if (array_key_exists($hash, $maps) === true) {
					$data	= $this->read($maps[$hash]);
				} elseif ($throw === true) {
					throw new \Exception("Key does not exist: " . $key);
				} else {
					$data	= null;
				}
			
			} catch (\Exception $e) {
				$this->readUnlock();
				throw $e;
			}
			
			$this->readUnlock();
			return $data;
		} else {
			throw new \Exception("Terminated, cannot get");
		}
	}
	protected function setDefaults()
	{
		//null hash for default value
		$this->write($this->_mapsId, array(hash("sha256", null) => ($this->_mapsId - 1), "nextId" => ($this->_mapsId - 2))); //set maps
		$this->write(($this->_mapsId - 1), null); //set key for default value
	}
}