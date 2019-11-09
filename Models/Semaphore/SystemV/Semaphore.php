<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Semaphore\SystemV;

class Semaphore extends Base
{
	protected $_id=null;
	protected $_guid=null;
	protected $_name=null;
	protected $_count=null;
	protected $_perm=null;
	protected $_isInit=false;
	protected $_isTerm=false;
	protected $_initTime=null;
	protected $_keepAlive=true;
	protected $_lockCount=0;
	protected $_semRes=null;
	
	public function __construct($name=null, $count=null, $perm=null)
	{
		$this->_name		= $name;
		$this->_count		= $count;
		$this->_perm		= $perm;
		if ($this->_name === null) {
			$this->_name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		}
		if ($this->_count === null) {
			$this->_count	= 1;
		}
		if ($this->_perm === null) {
			$this->_perm	= "0644";
		} else {
			$this->_perm	= str_repeat("0", 4 - strlen($this->_perm)) . $this->_perm;
		}
		$this->_guid		= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		$this->_parentObj	= \MTM\Memory\Factories::getSemaphores()->getSystemFive();
		$this->_keepAlive	= $this->_parentObj->getDefaultKeepAlive();
	}
	public function __construct($id)
	{
		$this->_id		= $id;
		$this->_guid	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
	}
	public function __destruct()
	{
		$this->terminate();
	}
	public function lock($noWait=false)
	{
		if ($this->isTerm() === false) {
			$locked	= true;
			if ($this->_lockCount === 0) {
				$locked	= @sem_acquire($this->getRes(), $noWait);
				if ($noWait === false && $locked === false) {
					throw new \Exception("Failed to get lock");
				}
			}
			if ($locked === true) {
				$this->_lockCount++;
			}
			return $locked;
		} else {
			throw new \Exception("Cannot lock, is terminated");
		}
	}
	public function unlock($deep=false)
	{
		if ($this->isTerm() === false) {
			$locked	= false;
			if ($this->_lockCount > 0) {
				if ($deep === false) {
					$this->_lockCount--;
				} else {
					$this->_lockCount = 0;
				}
				if ($this->_lockCount === 0) {
					$unlocked	= @sem_release($this->getRes());
					if ($unlocked === false) {
						throw new \Exception("Failed to relase semaphore");
					}
				} else {
					$locked	= true;
				}
			}
			return $locked;
		} else {
			throw new \Exception("Cannot unlock, is terminated");
		}
	}
	public function isInit()
	{
		return $this->_isInit;
	}
	public function isTerm()
	{
		return $this->_isTerm;
	}
	public function initialize()
	{
		if ($this->isInit() === false) {

			$segId	= $this->_parentObj->getSegmentIdFromName($this->getName());
			$semRes	= @sem_get($segId, $this->getCount(), intval($this->getPermission(), 8), 1);
			if (is_resource($semRes) === true) {
				$this->_semRes		= $semRes;
			} else {
				//linux has a default max of 128
				//increse to 175: printf '250\t32000\t32\t175' >/proc/sys/kernel/sem
				throw new \Exception("Failed to get semaphore");
			}
			$this->_initTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
			$this->_isInit		= true;	
		}
		return $this;
	}
	public function terminate()
	{
		if ($this->isTerm() === false) {
			if ($this->isInit() === true) {
				$this->unlock(true);
				if ($this->getKeepAlive() === false) {
					@sem_remove($this->getRes());
				}
				$this->_semRes	= null;
			}
			$this->_isTerm	= true;
			$this->getParent()->remove($this);
		}
	}
	public function getGuid()
	{
		return $this->_guid;
	}
	public function getCount()
	{
		return $this->_count;
	}
	public function getName()
	{
		return $this->_name;
	}
	public function getPermission()
	{
		return $this->_perm;
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
	protected function getRes()
	{
		return $this->_semRes;
	}
}