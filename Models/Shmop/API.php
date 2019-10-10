<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shmop;

class API
{
	protected $_shObjs=array();
	protected $_keepAlive=true;
	
	public function getNewShare($name=null, $size=null, $perm=null)
	{
		if ($name === null) {
			$name	= \MTM\Utilities\Factories::getGuids()->getV4()->get(false);
		} else {
			$name	= trim($name);
		}

		$shObj	= $this->getShareByName($name, false);
		if ($shObj === null) {
			
			//there seems to be a 32bit limit on the address space, if we do not limit we will not be able to find the share
			//attached count, because the max id can be 64bit/2  
			$segId		= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($name, 4294967295);
			$rObj		= new \MTM\Memory\Models\Shmop\Share($segId);
			$rObj->setParent($this)->setName($name)->setKeepAlive($this->getDefaultKeepAlive());

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
	}
}