<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Semaphore\SystemV;

class API
{
	protected $_semObjs=array();
	protected $_keepAlive=true;
	
	public function getNewSemaphore($name=null, $count=null, $perm=null)
	{
		if ($name === null) {
			$name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		} else {
			$name	= trim($name);
		}

		$semObj	= $this->getByName($name, false);
		if ($semObj === null) {
			
			//there seems to be a 32bit limit on the address space, if we do not limit we will not be able to find the share
			//attached count, because the max id can be 64bit/2
			$id		= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
			$rObj	= new \MTM\Memory\Models\Semaphore\SystemV\Semaphore($id);
			$rObj->setParent($this)->setName($name)->setKeepAlive($this->getDefaultKeepAlive());
			
			if ($count !== null) {
				$rObj->setCount($count);
			}
			if ($perm !== null) {
				$perm	= str_repeat("0", 4 - strlen($perm)) . $perm;
				$rObj->setPermission($perm);
			}

			$rObj->initialize();
			$hash					= hash("sha256", $name);
			$this->_semObjs[$hash]	= $rObj;

			return $rObj;
			
		} else {
			throw new \Exception("Cannot add semaphore exists with name: " . $name);
		}
	}
	public function remove($semObj)
	{
		$hash	= hash("sha256", $semObj->getName());
		if (array_key_exists($hash, $this->_semObjs) === true) {
			unset($this->_semObjs[$hash]);
			$semObj->terminate();
		}
		return $this;
	}
	public function getByName($name, $throw=false)
	{
		$hash	= hash("sha256", $name);
		if (array_key_exists($hash, $this->_semObjs) === true) {
			return $this->_semObjs[$hash];
		} elseif ($throw === true) {
			throw new \Exception("No semaphore with name: " . $name);
		} else {
			return null;
		}
	}
	public function setDefaultKeepAlive($bool)
	{
		//should new semaphores delete once terminated
		$this->_keepAlive	= $bool;
		return $this;
	}
	public function getDefaultKeepAlive()
	{
		return $this->_keepAlive;
	}
	public function clearSystem()
	{
		//remove all semaphores owned by this user on the system
		$strCmd	= "ipcs -s | grep \$(whoami) | grep -E \"3\s+$\"; echo \" \""; //must be type "3"
		$rObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell()->write($strCmd)->read();
		$lines	= explode("\n", trim($rObj->data));
		foreach ($lines as $line) {
			$id		= substr($line, 0, strpos($line, " "));
			$semRes	= @sem_get(hexdec($id));
			if (is_resource($semRes) === true) {
				@sem_remove($semRes);
			}
		}
		return $this;
	}
}