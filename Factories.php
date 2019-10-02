<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory;

class Factories
{
	private static $_cStore=array();
	
	//USE: $aFact		= \MTM\Memory\Factories::$METHOD_NAME();
	
	public static function getShared()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\Memory\Factories\Shared();
		}
		return self::$_cStore[__FUNCTION__];
	}
}