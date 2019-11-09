<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Semaphore\SystemV;

class API
{
	protected $_semObjs=array();
	protected $_keepAlive=true;
	
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
		$rObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell()->write($strCmd)->read();
		if (intval($rObj->data) < 0) {
			return false;
		} else {
			return true;
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
		
// 		#!/bin/sh
// 		for i in $(ipcs -s | awk '{ print $2 }' | sed 1,2d);
// 		do
// 			echo "ipcrm -s $i"
// 			ipcrm -s $i
// 		done
	}
	public function getSegmentIdFromName($name)
	{
		//there seems to be a 32bit limit on the address space, if we do not limit we will not be able to find the share
		//attached count, because the max id can be 64bit/2
		return \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
	}
}