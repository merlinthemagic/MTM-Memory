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
	protected $_attachLock=false;
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
		$this->rwLock();
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
				
				$this->rwUnlock();
				
				return $this;
				
			} else {
				throw new \Exception("Failed to add key exists: " . $key);
			}
		
		} catch (\Exception $e) {
			$this->rwUnlock();
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
		$this->rwLock();
		try {
			$data = $this->getShmData($this->getKeyId($key));
			$this->rwUnlock();
			return $data;
		} catch (\Exception $e) {
			$this->rwUnlock();
			throw $e;
		}
	}
	public function set($key, $value)
	{
		$this->rwLock();
		try {
			
			$keyId	= $this->getKeyId($key, false);
			if ($keyId === null) {
				$this->add($key, $value);
			} else {
				$this->setShmData($keyId, $value);
			}
			return $this;
		
		} catch (\Exception $e) {
			$this->rwUnlock();
			throw $e;
		}
	}
	public function remove($key)
	{
		$this->rwLock();
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

				return $this;
				
			} else {
				throw new \Exception("Failed to remove key does not exist: " . $key);
			}
			
		} catch (\Exception $e) {
			$this->rwUnlock();
			throw $e;
		}
	}
	public function getAttachCount()
	{
		//how many processes are attached to the share?
		$strCmd	= "ipcs -m | grep \"" . dechex($this->getSegmentId()) . "\" | awk '{print \$NF} END { if (!NR) print 0 }'";
		$rObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell()->write($strCmd)->read();
		return intval($rObj->data);
	}
	protected function getShmData($id)
	{
		$this->rwLock();
		try {
			
			$data	= shm_get_var($this->getRwShm(), $id);
			$this->rwUnlock();
			return $data;
		
		} catch (\Exception $e) {
			$this->rwUnlock();
			throw $e;
		}
	}
	protected function setShmData($id, $value)
	{
		$this->rwLock();
		try {
			$isValid	= shm_put_var($this->getRwShm(), $id, $value);
			
			if ($isValid === true) {
				$this->rwUnlock();
				return $this;
			} else {
				throw new \Exception("Failed to set id: " . $id);
			}
		} catch (\Exception $e) {
			$this->rwUnlock();
			throw $e;
		}
	}
	public function initialize()
	{
		if ($this->_isInit === false) {

			if ($this->getSegmentId() !== null) {
				
				$attachSem	= sem_get($this->getSegmentId() + 1, 1, intval($this->getPermission(), 8), 1);
				if (is_resource($attachSem) === true) {
					$this->_attachSem		= $attachSem;
				} else {
					throw new \Exception("Failed to get to attach semaphore");
				}
				
				$this->attachLock();
				try {
				
					$rwSem	= sem_get($this->getSegmentId() + 2, 1, intval($this->getPermission(), 8), 1);
					if (is_resource($rwSem) === true) {
						$this->_rwSem		= $rwSem;
					} else {
						throw new \Exception("Failed to get to read/write semaphore");
					}
					
					$rwShm	= shm_attach($this->getSegmentId(), $this->getSize(),intval($this->getPermission(), 8));
					if (is_resource($rwShm) === true) {
						$this->_rwShm		= $rwShm;
					} else {
						throw new \Exception("Failed to attach to shared segment");
					}
					
					$hasLock	= $this->rwLock(true);
					if ($hasLock === true) {
						
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
							$this->rwUnlock();

						} catch (\Exception $e) {
							$this->rwUnlock();
							throw $e;
						}
					} else {
						//someone else has the lock, they will be setting up the structure
						//and release the lock when ready.
					}
					$this->_initTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
					$this->_isInit		= true;
					
					$this->attachUnlock();
				
				} catch (\Exception $e) {
					$this->attachUnlock();
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
				
				$this->rwUnlock();
				$this->attachUnlock();
				
				if ($this->getKeepAlive() === false) {
					//if we are the last process attached to the segment we clean up
					$this->attachLock();
					try {
						
						if ($this->getAttachCount() === 1) {
							shm_remove($this->getRwShm());
							sem_remove($this->getRwSem());
							sem_remove($this->getAttachSem());
							$this->_attachLock	= false; //on account of deleted
						} else {
							$this->attachUnlock();
						}
	
					} catch (\Exception $e) {
						$this->attachUnlock();
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
	protected function rwLock($noWait=false)
	{
		if ($this->_rwLock === false) {
			$this->_rwLock	= @sem_acquire($this->getRwSem(), $noWait);
			if ($noWait === false && $this->_rwLock === false) {
				throw new \Exception("Failed to get read/write lock");
			}
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
	protected function attachLock($noWait=false)
	{
		if ($this->_attachLock === false) {
			$this->_attachLock	= @sem_acquire($this->getAttachSem(), $noWait);
			if ($noWait === false && $this->_attachLock === false) {
				//happens if the semaphore is deleted
				throw new \Exception("Failed to get attach lock");
			}
		}
		return $this->_attachLock;
	}
	protected function attachUnlock()
	{
		if ($this->_attachLock === true) {
			$isValid	= sem_release($this->getAttachSem());
			if ($isValid === true) {
				$this->_attachLock	= false;
			} else {
				throw new \Exception("Failed to relase Attach semaphore");
			}
		}
		return $this;
	}
	protected function getRwShm()
	{
		return $this->_rwShm;
	}
	protected function getAttachSem()
	{
		return $this->_attachSem;
	}
	protected function getRwSem()
	{
		return $this->_rwSem;
	}
}