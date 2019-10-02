<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Factories;

class Shared extends Base
{
	public function getShmop()
	{
		if (array_key_exists(__FUNCTION__, $this->_cStore) === false) {
			if (extension_loaded("shmop") === true) {
				$rObj	= new \MTM\Memory\Models\Shmop\API();
				$this->_cStore[__FUNCTION__]	= $rObj;
			} else {
				throw new \Exception("Shmop extension not loaded");
			}
		}
		return $this->_cStore[__FUNCTION__];
	}
}