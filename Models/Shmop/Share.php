<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shmop;

class Share extends Base
{
	protected $_guid=null;
	protected $_isInit=false;
	protected $_isTerm=false;
	protected $_initTime=null;
	protected $_name=null;
	protected $_keepAlive=false;
	protected $_segId=null;
	protected $_size=10000;
	protected $_perm="0644";
	protected $_rwShm=null;
	protected $_rwLock=false;
	protected $_rwLockStack=0;
	protected $_rwSem=null;
	
	public function __construct($segId=null)
	{
		$this->setSegmentId($segId);
		$this->_guid	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
	}
	public function __destruct()
	{
		$this->terminate();
	}
	public function add($key, $value)
	{
		if ($this->getExists($key) === false) {
			$keyId	= $this->addKey($key);
			$this->setShmData($keyId, $value);
			return $this;
		} else {
			throw new \Exception("Failed to add key exists: " . $key);
		}
	}
	public function getExists($key)
	{
		if ($this->getKeyId($key, false) !== null) {
			return true;
		} else {
			return false;
		}
	}
	public function get($key)
	{
		return $this->getShmData($this->getKeyId($key));
	}
	public function set($key, $value)
	{
		$keyId	= $this->getKeyId($key, false);
		if ($keyId === null) {
			$this->add($key, $value);
		} else {
			$this->setShmData($keyId, $value);
		}
		return $this;
	}
	public function remove($key)
	{
		if ($this->getExists($key) === true) {
			$this->removeKey($key);
			return $this;
		} else {
			throw new \Exception("Failed to remove key does not exist: " . $key);
		}
	}
	protected function getShmData($id)
	{
		return shm_get_var($this->getRwShm(), $id);
	}
	protected function setShmData($id, $value)
	{
		$this->rwLock();
		$isValid	= shm_put_var($this->getRwShm(), $id, $value);
		$this->rwUnlock();
		if ($isValid === true) {
			return $this;
		} else {
			throw new \Exception("Failed to set id: " . $id);
		}
	}
	public function initialize()
	{
		if ($this->_isInit === false) {

			if ($this->getSegmentId() !== null) {
				$rwShm	= shm_attach($this->getSegmentId(), $this->getSize(),intval($this->getPermission(), 8));
				if (is_resource($rwShm) === true) {
					$this->_rwShm		= $rwShm;
				} else {
					throw new \Exception("Failed to attach to shared segment");
				}
				$rwSem	= sem_get($this->getSegmentId() + 2, 1, intval($this->getPermission(), 8), 1);
				if (is_resource($rwSem) === true) {
					$this->_rwSem		= $rwSem;
				} else {
					throw new \Exception("Failed to get to read/write semaphore");
				}

				$isInit		= shm_has_var($this->getRwShm(), 512);
				if ($isInit === false) {
					//memory is not yet structured, set fixed keys
					$this->rwLock();
					try {
						
						$isInit		= shm_has_var($this->getRwShm(), 512);
						if ($isInit === false) {
							//structure was not added while we waited for a lock
							$maps	= array(
									"isInit"	=> 512,
									"maps"		=> 514,
									"mapId"		=> 900 
							);
							$this->setShmData(514, $maps);
							$this->setShmData(900, 1500); //user variables start at 1500
							$this->setShmData(512, true);
							
						}
						$this->rwUnlock();
						
					} catch (\Exception $e) {
						$this->rwUnlock();
						throw $e;
					}
				}
				
				$this->_initTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
				$this->_isInit		= true;
				
			} else {
				throw new \Exception("Cannot connect without a segment ID");
			}
		}
		return $this;
	}
	public function terminate()
	{
		if ($this->_isTerm === false) {
			$this->_isTerm	= true;
			
			$this->rwUnlock(true);
			if ($this->getRwShm() !== null) {
				shm_detach($this->getRwShm());
				$this->_rwShm	= null;
			}
			
			$this->getParent()->removeShare($this);
		}
	}
	public function getGuid()
	{
		return $this->_guid;
	}
	public function setName($name)
	{
		$this->_name	= $name;
		return $this;
	}
	public function getName()
	{
		return $this->_name;
	}
	public function setPermission($str)
	{
		$this->_perm	= $str;
		return $this;
	}
	public function getPermission()
	{
		return $this->_perm;
	}
	public function setSize($bytes)
	{
		$this->_size	= $bytes;
		return $this;
	}
	public function getSize()
	{
		return $this->_size;
	}
	public function setSegmentId($id)
	{
		$this->_segId	= $id;
		return $this;
	}
	public function getSegmentId()
	{
		return $this->_segId;
	}
	public function setKeepAlive($bool)
	{
		//remove the resource on terminate if we are the last
		//connected
		$this->_keepAlive	= $bool;
		return $this;
	}
	public function getKeepAlive()
	{
		return $this->_keepAlive;
	}
	protected function getMaps()
	{
		return $this->getShmData(514);
	}
	protected function getKeyId($key, $throw=true)
	{
		$maps	= $this->getMaps();
		if (array_key_exists($key, $maps) === true) {
			return $maps[$key];
		} elseif ($throw === true) {
			throw new \Exception("Key does not exist: " . $key);
		} else {
			return null;
		}
	}
	protected function addKey($key)
	{
		$key	= trim($key);
		$keyId	= $this->getKeyId($key, false);
		if ($keyId === null) {
			$this->rwLock();
			
			try {
				
				$keyId	= $this->getKeyId($key, false);
				if ($keyId === null) {
					//key was not added while we waited for lock
					$uId		= $this->getShmData(900);
					$this->setShmData($uId, null); //set new variable
					
					//update maps
					$maps		= $this->getMaps();
					$maps[$key]	= $uId++;
					$this->setShmData(514, $maps); 
					
					//update id counter
					$this->setShmData(900, $uId); 
					
					//return the keyId
					$keyId	= $this->getKeyId($key);
				}
				$this->rwUnlock();
				
			} catch (\Exception $e) {
				$this->rwUnlock();
				throw $e;
			}
		}
		return $keyId;
	}
	protected function removeKey($key)
	{
		$key	= trim($key);
		$keyId	= $this->getKeyId($key, false);
		if ($keyId !== null) {
			
			$this->rwLock();
			
			try {
				
				$keyId	= $this->getKeyId($key, false);
				if ($keyId !== null) {
					//key was not removed while we waited for lock
					
					$isValid	= shm_remove_var($this->getRwShm(), $keyId);
					if ($isValid === false) {
						throw new \Exception("Failed to remove Key: " . $key);
					}
					
					//update maps
					$maps		= $this->getMaps();
					unset($maps[$key]);
					$this->setShmData(514, $maps);
				}
				
				$this->rwUnlock();
				
			} catch (\Exception $e) {
				$this->rwUnlock();
				throw $e;
			}
		}
		return $this;
	}
	protected function rwLock($noWait=false)
	{
		if ($this->_rwLock === false) {
			$this->_rwLock	= sem_acquire($this->getRwSem(), $noWait);
		}
		if ($this->_rwLock === true) {
			$this->_rwLockStack++;
		}
		return $this->_rwLock;
	}
	protected function rwUnlock($purgeStack=false)
	{
		if ($this->_rwLock === true) {
			if ($purgeStack === false) {
				$this->_rwLockStack--;
			} else {
				$this->_rwLockStack = 0;
			}
			if ($this->_rwLockStack === 0) {
				$isValid	= sem_release($this->getRwSem());
				if ($isValid === true) {
					$this->_rwLock	= false;
				} else {
					throw new \Exception("Failed to relase RW semaphore");
				}
			}
		}
		return $this;
	}
	protected function getRwShm()
	{
		return $this->_rwShm;
	}
	protected function getRwSem()
	{
		return $this->_rwSem;
	}
}