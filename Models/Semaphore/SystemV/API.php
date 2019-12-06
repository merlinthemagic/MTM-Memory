<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Semaphore\SystemV;

class API
{
	protected $_shellObj=null;
	protected $_semObjs=array();
	
	public function getNewSemaphore($name=null, $count=null, $perm=null)
	{
		$semObj	= $this->getByName($name, false);
		if ($semObj === null) {
			
			//there seems to be a 32bit limit on the address space, if we do not limit we will not be able to find the share
			//attached count, because the max id can be 64bit/2
			$semObj		= new \MTM\Memory\Models\Semaphore\SystemV\Semaphore($name, $count, $perm);
			$this->_semObjs[hash("sha256", $name)]	= $semObj->initialize();
		}
		return $semObj;
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
	public function delete($semObj)
	{
		if ($this->getExistByName($semObj->getName()) === true) {
			$segId		= $this->getSegmentIdFromName($semObj->getName());
			$semRes		= sem_get($segId);
			$isValid	= sem_remove($semRes);
			if ($isValid === false) {
				throw new \Exception("Failed to delete semaphore: " . $semObj->getName());
			}
		}
		$this->remove($semObj);
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
	public function getExistByName($name)
	{
		//does the semaphore exist?
		$segId	= $this->getSegmentIdFromName($name);
		$strCmd	= "ipcs -s | grep \"" . dechex($segId) . "\" | awk '{print \$NF} END { if (!NR) print -1 }'";
		$rObj	= $this->getShell()->write($strCmd)->read();
		if (intval($rObj->data) < 0) {
			return false;
		} else {
			return true;
		}
	}

	public function clearSystem()
	{
		//remove all semaphores owned by this user on the system
		$strCmd	= "ipcs -s | grep \$(whoami) | grep -E \"3\s+$\"; echo \" \""; //must be type "3"
		$rObj	= $this->getShell()->write($strCmd)->read();
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
	public function getSegmentIdFromName($name)
	{
		//there seems to be a 32bit limit on the address space, if we do not limit we will not be able to find the share
		//attached count, because the max id can be 64bit/2
		return \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
	}
	protected function getShell()
	{
		//cache in class to ensure its torn down after the API
		if ($this->_shellObj === null) {
			$this->_shellObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell();
		}
		return $this->_shellObj;
	}
}