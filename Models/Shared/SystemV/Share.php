<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shared\SystemV;

class Share extends Base
{
	protected $_guid=null;
	protected $_segId=null;
	protected $_isInit=false;
	protected $_isTerm=false;
	protected $_initTime=null;
	protected $_name=null;
	protected $_size=null;
	protected $_perm=null;
	protected $_rwShm=null;
	protected $_rwSem=null;
	protected $_attachSem=null;
	protected $_keepAlive=null;
	protected $_sysKeys=array("shIsInit" => 512, "shCount" => 513, "shMaps" => 514, "shName" => 515, "shMapId" => 900); //mappings used by the system, maybe use int?

	public function __construct($name=null, $size=null, $perm=null)
	{
		$this->_guid		= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		$this->_name		= $name;
		$this->_size		= $size;
		$this->_perm		= $perm;
		if ($this->_name === null) {
			$this->_name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		}
		if ($this->_size === null) {
			$this->_size	= 10000;
		}
		if ($this->_perm === null) {
			$this->_perm	= "0644";
		} else {
			$this->_perm	= str_repeat("0", 4 - strlen($this->_perm)) . $this->_perm;
		}
		$this->_parentObj	= \MTM\Memory\Factories::getShared()->getSystemFive();
		$this->_segId		= $shmId			= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($this->_name, 4294967295);
		$this->_keepAlive	= $this->_parentObj->getDefaultKeepAlive();
		
		$this->initialize();
	}
	public function __destruct()
	{
		$this->terminate();
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
	public function getGuid()
	{
		return $this->_guid;
	}
	public function getName()
	{
		return $this->_name;
	}
	public function getPermission()
	{
		return $this->_perm;
	}
	public function getSize()
	{
		return $this->_size;
	}
	public function getSegmentId()
	{
		return $this->_segId;
	}
	public function setKeepAlive($bool)
	{
		//remove the share on terminate if we are the last connection
		$this->_keepAlive	= $bool;
		if ($this->getAttachSem() !== null) {
			$this->getAttachSem()->setKeepAlive($bool);
		}
		if ($this->getRwSem() !== null) {
			$this->getRwSem()->setKeepAlive($bool);
		}
		return $this;
	}
	public function getKeepAlive()
	{
		return $this->_keepAlive;
	}
	public function add($key, $value)
	{
		$this->getRwSem()->lock();
		try {

			if ($this->getExists($key) === false) {
				if (array_key_exists($key, $this->_sysKeys) === false) {
					$uId		= $this->getShmData(900);
					$this->setShmData($uId, $value); //set new variable
					
					//update maps
					$maps		= $this->getMaps();
					$maps[$key]	= $uId++;
					$this->setShmData(514, $maps);
					
					//update id counter
					$this->setShmData(900, $uId);
					
					$this->getRwSem()->unlock();
	
					return $this;
					
				} else {
					throw new \Exception("Invalid Key name. Key is used by system: " . $key);
				}
				
			} else {
				throw new \Exception("Failed to add key exists: " . $key);
			}
		
		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
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
	public function get($key, $throw=true)
	{
		$this->getRwSem()->lock();
		try {
			
			$keyId	= $this->getKeyId($key, $throw);
			if ($keyId !== null) {
				$data	= $this->getShmData($this->getKeyId($key));
			} else {
				$data	= null; //key does not exist and we are not throwing
			}
			$this->getRwSem()->unlock();
			return $data;
		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
		}
	}
	public function set($key, $value)
	{
		if (array_key_exists($key, $this->_sysKeys) === false) {
			
			$this->getRwSem()->lock();
			try {
				
				$keyId	= $this->getKeyId($key, false);
				if ($keyId === null) {
					$this->add($key, $value);
				} else {
					$this->setShmData($keyId, $value);
				}
				$this->getRwSem()->unlock();
				return $this;
			
			} catch (\Exception $e) {
				$this->getRwSem()->unlock();
				throw $e;
			}
		} else {
			throw new \Exception("Cannot set. Key is used by system: " . $key);
		}
	}
	public function clear()
	{
		$this->getRwSem()->lock();
		try {
			
			$maps	= $this->getMaps();
			foreach ($maps as $key => $id) {
				if ($id >= 1500) {
					$this->remove($key);
				}
			}

			//clear all data from share
			$this->setShmData(515, $this->getName());
			$this->setShmData(514, $this->_sysKeys);
			$this->setShmData(900, 1500);
			$this->setShmData(512, true);
			
			$this->getRwSem()->unlock();
			return $this;

		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
		}
	}
	public function remove($key)
	{
		if (array_key_exists($key, $this->_sysKeys) === false) {
			$this->getRwSem()->lock();
			try {
				
				$keyId	= $this->getKeyId($key, false);
				if ($keyId !== null) {
					
					$isValid	= shm_remove_var($this->getRwShm(), $keyId);
					if ($isValid === false) {
						throw new \Exception("Failed to remove Key: " . $key);
					}
					
					//update maps
					$maps		= $this->getMaps();
					unset($maps[$key]);
					$this->setShmData(514, $maps);
				}
				
				$this->getRwSem()->unlock();
				return $this;

			} catch (\Exception $e) {
				$this->getRwSem()->unlock();
				throw $e;
			}
			
		} else {
			throw new \Exception("Cannot remove. Key is used by system: " . $key);
		}
	}
	public function getAttachCount()
	{
		return $this->get("shCount");
	}
	protected function getShmData($id)
	{
		$this->getRwSem()->lock();
		try {
			
			$data	= shm_get_var($this->getRwShm(), $id);
			$this->getRwSem()->unlock();
			return $data;
		
		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
		}
	}
	protected function setShmData($id, $value)
	{
		$this->getRwSem()->lock();
		try {
			
			$isValid	= shm_put_var($this->getRwShm(), $id, $value);
			if ($isValid === true) {
				$this->getRwSem()->unlock();
				return $this;
			} else {
				throw new \Exception("Failed to set id: " . $id);
			}
		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
		}
	}
	protected function getMaps()
	{
		$maps	= $this->getShmData(514);
		if (is_array($maps) === true) {
			return $maps;
		} else {
			throw new \Exception("Maps is not an array");
		}
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
	protected function getAttachSem()
	{
		return $this->_attachSem;
	}
	protected function getRwSem()
	{
		return $this->_rwSem;
	}
	public function rwLock()
	{
		//keep in mind the semaphore keeps track of lock count
		//make sure to unlock after use
		$this->getRwSem()->lock();
		return $this;
	}
	public function rwUnlock()
	{
		$this->getRwSem()->unlock();
		return $this;
	}
	protected function getRwShm()
	{
		return $this->_rwShm;
	}
	public function initialize()
	{
		if ($this->isInit() === false) {

			if ($this->getSegmentId() !== null) {
				
				$semFact			= \MTM\Memory\Factories::getSemaphores()->getSystemFive();
				$this->_attachSem	= $semFact->getNewSemaphore($this->getName() . "-Attach", 1, $this->getPermission());
				$this->_attachSem->setKeepAlive($this->getKeepAlive());
				$this->getAttachSem()->lock();
				
				try {

					$this->_rwSem	= $semFact->getNewSemaphore($this->getName() . "-RW", 1, $this->getPermission());
					$this->_rwSem->setKeepAlive($this->getKeepAlive());
					$rwShm			= shm_attach($this->getSegmentId(), $this->getSize(),intval($this->getPermission(), 8));
					if (is_resource($rwShm) === true) {
						$this->_rwShm		= $rwShm;
					} else {
						throw new \Exception("Failed to attach to shared segment");
					}

					$this->getRwSem()->lock();
					try {

						$isInit		= shm_has_var($this->getRwShm(), 512);
						if ($isInit === false) {
							
							//memory is not yet structured, set fixed keys
							$isInit		= shm_has_var($this->getRwShm(), 512);
							if ($isInit === false) {
								//structure was not added while we waited for a lock
								$this->setShmData(514, $this->_sysKeys);
								$this->setShmData(515, $this->getName());
								$this->setShmData(513, 1);
								$this->setShmData(900, 1500); //user variables start at 1500
								$this->setShmData(512, true);
							}
						} else {
							$this->setShmData(513, ($this->getShmData(513) + 1));
						}
							
						$this->getRwSem()->unlock();

					} catch (\Exception $e) {
						$this->getRwSem()->unlock();
						throw $e;
					}

					$this->_initTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
					$this->_isInit		= true;
					
					$this->getAttachSem()->unlock();
					
				} catch (\Exception $e) {
					$this->getAttachSem()->unlock();
					throw $e;
				}
				
			} else {
				throw new \Exception("Cannot connect without a segment ID");
			}
		}
		return $this;
	}
	public function terminate()
	{
		if ($this->isTerm() === false) {
			$this->_isTerm	= true;

			if ($this->isInit() === true) {
				$this->setShmData(513, ($this->getShmData(513) - 1));
				$this->getRwSem()->unlock(true);
				$this->getAttachSem()->unlock(true);
				
				if ($this->getKeepAlive() === false) {
					//remove queue, you can use $this->getAttachCount() === 1
					//to determine if you wanna destroy with others attached
					$this->getAttachSem()->lock();
					shm_remove($this->getRwShm());
					
					//remove the semaphores
					$this->getRwSem()->setKeepAlive(false)->terminate();
					$this->getAttachSem()->setKeepAlive(false)->terminate();
				}
				shm_detach($this->getRwShm());
				$this->_rwShm	= null;
			}
			
			$this->getParent()->removeShare($this);
		}
	}
}