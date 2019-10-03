<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shmop;

class API
{
	protected $_strMaxId=null;
	protected $_maxId=null;
	protected $_shObjs=array();
	
	public function getNewShare($name=null, $size=null, $perm=null)
	{
		if ($name === null) {
			$name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		} else {
			$name	= trim($name);
		}

		$shObj	= $this->getShareByName($name, false);
		if ($shObj === null) {
			
			//there seems to be a 32bit limit on addresses
			$segId	= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
			if ($this->getMaxId() > $segId) {
				$segId	= $this->getMaxId() % $segId;
			} else {
				$segId	= $segId % $this->getMaxId();
			}

			$rObj		= new \MTM\Memory\Models\Shmop\Share($segId);
			$rObj->setParent($this)->setName($name);
			
			if ($size !== null) {
				$rObj->setSize($size);
			}
			if ($perm !== null) {
				$rObj->setPermission($perm);
			}

			$rObj->initialize();
			$hash					= hash("sha256", $name);
			$this->_shObjs[$hash]	= $rObj;

			return $rObj;
			
		} else {
			throw new \Exception("Cannot add share exists with name: " . $name);
		}
	}
	public function removeShare($shareObj)
	{
		$hash	= hash("sha256", $shareObj->getName());
		if (array_key_exists($hash, $this->_shObjs) === true) {
			unset($this->_shObjs[$hash]);
			$shareObj->terminate();
		}
		return $this;
	}
	public function getShareByName($name, $throw=false)
	{
		$hash	= hash("sha256", $name);
		if (array_key_exists($hash, $this->_shObjs) === true) {
			return $this->_shObjs[$hash];
		} elseif ($throw === true) {
			throw new \Exception("No share with name: " . $name);
		} else {
			return null;
		}
	}
	public function getMaxId()
	{
		if ($this->_maxId === null) {
			$this->_maxId	= intval($this->getMaxIdAsString());
		}
		return $this->_maxId;
	}
	public function getMaxIdAsString()
	{
		if ($this->_strMaxId === null) {
			//cant specify unsigned int in PHP, the max will only be half the address space if cast to int
			exec("sysctl kernel.shmmax -n", $rData);
			if (count($rData) == 1 && ctype_digit($rData[0]) === true) {
				$this->_strMaxId	= $rData[0];
			} else {
				throw new \Exception("Failed to get shmax");
			}
		}
		return $this->_strMaxId;
	}
}