<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Factories;

class Semaphores extends Base
{
	public function getSystemFive()
	{
		if (array_key_exists(__FUNCTION__, $this->_cStore) === false) {
			if (extension_loaded("sysvsem") === true) {
				$rObj	= new \MTM\Memory\Models\Semaphore\SystemV\API();
				$this->_cStore[__FUNCTION__]	= $rObj;
			} else {
				throw new \Exception("sysvsem extension not loaded");
			}
		}
		return $this->_cStore[__FUNCTION__];
	}
}