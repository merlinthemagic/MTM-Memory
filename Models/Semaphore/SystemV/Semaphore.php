<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Semaphore\SystemV;

class Semaphore extends Base
{
	protected $_id=null;
	protected $_count=1;
	protected $_guid=null;
	protected $_name=null;
	protected $_isInit=false;
	protected $_initTime=null;
	protected $_isTerm=false;
	protected $_keepAlive=true;
	protected $_perm="0644";
	protected $_locked=false;
	protected $_lockCount=0;
	protected $_semRes=null;
	
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
		if ($this->_locked === false) {
			$this->_locked	= @sem_acquire($this->getRes(), $noWait);
			if ($noWait === false && $this->_locked === false) {
				throw new \Exception("Failed to get lock");
			}
		}
		if ($this->_locked === true) {
			$this->_lockCount++;
		}
		return $this->_locked;
	}
	public function unlock($force=false)
	{
		if ($this->_locked === true) {
			if ($force === false) {
				$this->_lockCount--;
			} else {
				$this->_lockCount = 0;
			}
			if ($this->_lockCount === 0) {
				$isValid	= sem_release($this->getRes());
				if ($isValid === true) {
					$this->_locked	= false;
				} else {
					throw new \Exception("Failed to relase semaphore");
				}
			}
		}
		return $this;
	}
	public function initialize()
	{
		if ($this->_isInit === false) {

			if ($this->getId() !== null) {
				
				$semRes	= sem_get($this->getId(), $this->getCount(), intval($this->getPermission(), 8), 1);
				if (is_resource($semRes) === true) {
					$this->_semRes		= $semRes;
				} else {
					throw new \Exception("Failed to get semaphore");
				}

				$this->_initTime	= \MTM\Utilities\Factories::getTime()->getMicroEpoch();
				$this->_isInit		= true;
				
			} else {
				throw new \Exception("Cannot initialize without an ID");
			}
		}
		return $this;
	}
	public function terminate()
	{
		if ($this->_isTerm === false) {
			$this->_isTerm	= true;

			if ($this->_isInit === true) {
				$this->unlock(true);
				if ($this->getKeepAlive() === false) {
					if ($this->getParent()->getExistByName($this->getName()) === true) {
						sem_remove($this->getRes());
					}
				}
				$this->_semRes	= null;
			}
			$this->getParent()->remove($this);
		}
	}
	public function getGuid()
	{
		return $this->_guid;
	}
	public function getId()
	{
		return $this->_id;
	}
	public function setCount($int)
	{
		$this->_count	= $int;
		return $this;
	}
	public function getCount()
	{
		return $this->_count;
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