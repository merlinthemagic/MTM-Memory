<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shared\SystemV;

class API
{
	protected $_shObjs=array();
	protected $_keepAlive=true;
	
	public function getShare($name=null, $size=null, $perm=null)
	{
		if ($name === null) {
			$name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		} else {
			$name	= trim($name);
		}

		$shObj	= $this->getShareByName($name, false);
		if ($shObj === null) {
			
			$segId		= $this->getSegmentIdFromName($name);
			$shObj		= new \MTM\Memory\Models\Shared\SystemV\Share($segId);
			$shObj->setParent($this)->setName($name)->setKeepAlive($this->getDefaultKeepAlive());

			if ($size !== null) {
				//make check if size does not match
				$shObj->setSize($size);
			}
			if ($perm !== null) {
				//make check if permissions do not match
				$perm	= str_repeat("0", 4 - strlen($perm)) . $perm;
				$shObj->setPermission($perm);
			}

			$shObj->initialize();
			$hash					= hash("sha256", $name);
			$this->_shObjs[$hash]	= $shObj;
		}
		return $shObj;
	}
	public function setDefaultKeepAlive($bool)
	{
		//should shares delete once terminated if there are no other connections
		$this->_keepAlive	= $bool;
		return $this;
	}
	public function getDefaultKeepAlive()
	{
		return $this->_keepAlive;
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
	public function getShareExistByName($name)
	{
		//does the share exist?
		$segId	= $this->getSegmentIdFromName($name);
		$strCmd	= "ipcs -m | grep \"" . dechex($segId) . "\" | awk '{print \$NF} END { if (!NR) print -1 }'";
		$rObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell()->write($strCmd)->read();
		if (intval($rObj->data) < 0) {
			return false;
		} else {
			return true;
		}
	}
	public function getShareAttachCount($shareObj)
	{
		//how many processes are attached to the share?
		$strCmd	= "ipcs -m | grep \"" . dechex($shareObj->getSegmentId()) . "\" | awk '{print \$NF} END { if (!NR) print 0 }'";
		$rObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell()->write($strCmd)->read();
		return intval($rObj->data);
	}
	public function clearSystem()
	{
		//remove all shares owned by this user on the system
		$strCmd	= "ipcs -m | grep \$(whoami); echo \" \"";
		$rObj	= \MTM\Utilities\Factories::getSoftware()->getPhpTool()->getShell()->write($strCmd)->read();
		$lines	= explode("\n", trim($rObj->data));
		foreach ($lines as $line) {
			$id		= substr($line, 0, strpos($line, " "));
			$shmRes	= @shm_attach(hexdec($id));
			if (is_resource($shmRes) === true) {
				@shm_remove($shmRes);
			}
		}
		return $this;
		
// 			#!/bin/sh
// 			for i in $(ipcs -m | awk '{ print $2 }' | sed 1,2d);
// 			do
// 				echo "ipcrm -m $i"
// 				ipcrm -m $i
// 			done
	}
	protected function getSegmentIdFromName($name)
	{
		//there seems to be a 32bit limit on the address space, if we do not limit we will not be able to find the share
		//attached count, because the max id can be 64bit/2 
		return \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
	}
}