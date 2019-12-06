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
	
	protected $_defHash=null;
	protected $_nextHash=null;
	protected $_connHash=null;
	protected $_mapsHash=null;
	
	//stay way elow _mapsId for custom Ids, as we allocate ids incrementally below that value
	protected $_mapsId=7451191; //Id that holds key maps

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
		$this->_defHash			= hash("sha256", null);
		$this->_nextHash		= hash("sha256", "nextId" . $this->getName());
		$this->_connHash		= hash("sha256", "connId" . $this->getName());
		$this->_mapsHash		= hash("sha256", "mapsId" . $this->getName());
		$this->initialize();
	}
	public function __destruct()
	{
		$this->terminate();
	}
	public function terminate()
	{
		$this->getParent()->removeShare($this);
		return $this;
	}
	public function delete()
	{
		$this->getParent()->deleteShare($this);
		return $this;
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
	public function set($value, $key=null)
	{
		$this->rwLock();
		try {
		
			$hash	= hash("sha256", $key);
			$maps	= $this->getMaps();
			if (array_key_exists($hash, $maps) === false) {
				$maps[$hash]	= $maps[$this->_nextHash];
				$maps[$this->_nextHash]--;
				$this->write($this->_mapsId, $maps);
			}
			$this->write($maps[$hash], $value);
			
		} catch (\Exception $e) {
			$this->rwUnlock();
			throw $e;
		}
		$this->rwUnlock();
		return $this;
	}
	public function get($key=null, $throw=true)
	{
		if ($this->isTerm() === false) {
			$this->roLock();
			
			try {
				
				$hash	= hash("sha256", $key);
				$maps	= $this->getMaps();
				if (array_key_exists($hash, $maps) === true) {
					$data	= $this->read($maps[$hash]);
				} elseif ($throw === true) {
					throw new \Exception("Key does not exist: " . $key);
				} else {
					$data	= null;
				}
				
			} catch (\Exception $e) {
				$this->roUnlock();
				throw $e;
			}
			
			$this->roUnlock();
			return $data;
			
		} else {
			throw new \Exception("Terminated, cannot get");
		}
	}
	protected function setDefaults()
	{
		$initId						= $this->_mapsId;;
		$maps						= array();
		$maps[$this->_mapsHash]		= $initId--;
		$maps[$this->_defHash]		= $initId--;
		$maps[$this->_connHash]		= $initId--;
		
		//next hash must be last, we will append after this one
		$maps[$this->_nextHash]		= $initId--;
		$this->write($maps[$this->_mapsHash], $maps); //set maps
		$this->write($maps[$this->_defHash], null); //set key for default value
		$this->write($maps[$this->_connHash], 0); //set connected threads/processes
		return $this;
	}
	protected function getMaps()
	{
		//i read only, but i expect you to protect me
		return $this->read($this->_mapsId);
	}
	protected function write($id, $value)
	{
		//i expect you to protect me
		if (@shm_put_var($this->_shmRes, $id, $value) === true) {
			return $this;
		} else {
			throw new \Exception("Failed to set id: " . $id);
		}
	}
	protected function read($id)
	{
		//i expect you to protect me
		if (@shm_has_var($this->_shmRes, $id) === true) {
			return shm_get_var($this->_shmRes, $id);
		} else {
			throw new \Exception("Read failed. Id does not exist: " . $id);
		}
	}
}