<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shared\SystemV;

class API
{
	protected $_shellObj=null;
	protected $_shObjs=array();
	
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
	public function getShares()
	{
		return array_values($this->_shObjs);
	}
	public function removeShare($shareObj)
	{
		if (array_key_exists($shareObj->getGuid(), $this->_shObjs) === true) {
			unset($this->_shObjs[$shareObj->getGuid()]);
			$shareObj->terminate();
		}
		return $this;
	}
	public function deleteShare($shareObj)
	{	
		$this->removeShare($shareObj);
		$segId		= $this->getSegmentIdFromName($shareObj->getName());
		$shRes		= shm_attach($segId);
		$isValid	= shm_remove($shRes);
		if ($isValid === false) {
			throw new \Exception("Failed to delete share: " . $shareObj->getName());
		}
		return $this;
	}
	public function getShareExistByName($name)
	{
		//does the share exist?
		$segId	= $this->getSegmentIdFromName($name);
		$strCmd	= "ipcs -m | grep \"" . dechex($segId) . "\" | awk '{print \$NF} END { if (!NR) print -1 }'";
		$rObj	= $this->getShell()->write($strCmd)->read();
		if (intval($rObj->data) < 0) {
			return false;
		} else {
			return true;
		}
	}
	public function getShareAttachCount($shareObj)
	{
		//how many processes are attached to the share?
		$segId	= $this->getSegmentIdFromName($name);
		$strCmd	= "ipcs -m | grep \"" . dechex($segId) . "\" | awk '{print \$NF} END { if (!NR) print 0 }'";
		$rObj	= $this->getShell()->write($strCmd)->read();
		return intval($rObj->data);
	}
	public function clearSystem()
	{
		//remove all shares owned by this user on the system
		$strCmd	= "ipcs -m | grep \$(whoami); echo \" \"";
		$rObj	= $this->getShell()->write($strCmd)->read();
		$lines	= explode("\n", trim($rObj->data));
		foreach ($lines as $line) {
			$id		= substr($line, 0, strpos($line, " "));
			$shmRes	= @shm_attach(hexdec($id));
			if (is_resource($shmRes) === true) {
				@shm_remove($shmRes);
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