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
	protected $_keepAlive=true;
	protected $_segId=null;
	protected $_size=10000;
	protected $_perm="0644";
	protected $_rwShm=null;
	protected $_rwSem=null;
	protected $_attachSem=null;
	
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
		$this->getRwSem()->lock();
		try {

			if ($this->getExists($key) === false) {
				
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
	public function get($key)
	{
		$this->getRwSem()->lock();
		try {
			$data = $this->getShmData($this->getKeyId($key));
			$this->getRwSem()->unlock();
			return $data;
		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
		}
	}
	public function set($key, $value)
	{
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
	}
	public function remove($key)
	{
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

				$this->getRwSem()->unlock();
				return $this;
				
			} else {
				throw new \Exception("Failed to remove key does not exist: " . $key);
			}
			
		} catch (\Exception $e) {
			$this->getRwSem()->unlock();
			throw $e;
		}
	}
	public function getAttachCount()
	{
		return $this->getParent()->getShareAttachCount($this);
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
	protected function getAttachSem()
	{
		return $this->_attachSem;
	}
	protected function getRwSem()
	{
		return $this->_rwSem;
	}
	protected function getRwShm()
	{
		return $this->_rwShm;
	}
	public function initialize()
	{
		if ($this->_isInit === false) {

			if ($this->getSegmentId() !== null) {
				
				$semFact			= \MTM\Memory\Factories::getShared()->getSemaphore();
				$this->_attachSem	= $semFact->getNewSemaphore($this->getName() . "-Attach", 1, $this->getPermission());
				$this->_attachSem->setKeepAlive(true);
				$this->getAttachSem()->lock();
				
				try {

					$this->_rwSem	= $semFact->getNewSemaphore($this->getName() . "-RW", 1, $this->getPermission());
					$this->_rwSem->setKeepAlive(true);
					$rwShm			= shm_attach($this->getSegmentId(), $this->getSize(),intval($this->getPermission(), 8));
					if (is_resource($rwShm) === true) {
						$this->_rwShm		= $rwShm;
					} else {
						throw new \Exception("Failed to attach to shared segment");
					}

					if ($this->getRwSem()->lock(true) === true) {
						
						try {

							$isInit		= shm_has_var($this->getRwShm(), 512);
							if ($isInit === false) {
								
								//memory is not yet structured, set fixed keys
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
							}
							$this->getRwSem()->unlock();

						} catch (\Exception $e) {
							$this->getRwSem()->unlock();
							throw $e;
						}
					} else {
						//someone else has the lock, they will be setting up the structure
						//and release the lock when ready.
						//we deadlock if we wait for the lock for unknown reasons
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
		if ($this->_isTerm === false) {
			$this->_isTerm	= true;

			if ($this->_isInit === true) {
				
				$this->getRwSem()->unlock(true);
				$this->getAttachSem()->unlock(true);
				
				if ($this->getKeepAlive() === false) {
					//if we are the last process attached to the segment we clean up
					$this->getAttachSem()->lock();
					try {
						
						if ($this->getAttachCount() === 1) {
							shm_remove($this->getRwShm());
							
							//remove the semaphores
							$this->getRwSem()->setKeepAlive(false)->terminate();
							$this->getAttachSem()->setKeepAlive(false)->terminate();
							
						} else {
							$this->getAttachSem()->unlock();
						}

					} catch (\Exception $e) {
						$this->getAttachSem()->unlock();
						//dont throw, may be shutting down
					}
				}
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
		//remove the share on terminate if we are the last connection
		$this->_keepAlive	= $bool;
		return $this;
	}
	public function getKeepAlive()
	{
		return $this->_keepAlive;
	}
}