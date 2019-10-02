<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Factories;

class Shared extends Base
{
	public function getShmop()
	{
		if (array_key_exists(__FUNCTION__, $this->_cStore) === false) {
			$this->_cStore[__FUNCTION__]	= new \stdClass();
		}
		return $this->_cStore[__FUNCTION__];
	}
}