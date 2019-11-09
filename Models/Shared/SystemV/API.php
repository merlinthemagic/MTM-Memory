<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shared\SystemV;

class API
{
	protected $_shObjs=array();
	protected $_keepAlive=true;
	
	public function getShare($name=null, $size=null, $perm=null)
	{
		$rObj								= new \MTM\Memory\Models\Shared\SystemV\Share($name, $size, $perm);
		$this->_shObjs[$rObj->getGuid()]	= $rObj;
		return $rObj;
	}
	public function getThirdRW($name=null, $size=null, $perm=null)
	{
		$rObj								= new \MTM\Memory\Models\Shared\SystemV\ThirdRW($name, $size, $perm);
		$this->_shObjs[$rObj->getGuid()]	= $rObj;
		return $rObj;
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
		if (array_key_exists($shareObj->getGuid(), $this->_shObjs) === true) {
			unset($this->_shObjs[$shareObj->getGuid()]);
			$shareObj->terminate();
		}
		return $this;
	}
	public function getShareExistByName($name)
	{
		//does the share exist?
		
		$segId	= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
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
		$segId	= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($shareObj->getName(), 4294967295);
		$strCmd	= "ipcs -m | grep \"" . dechex($segId) . "\" | awk '{print \$NF} END { if (!NR) print 0 }'";
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
}