<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory;

class Factories
{
	private static $_cStore=array();
	
	//USE: $aFact		= \MTM\Memory\Factories::getSemaphores();
	
	public static function getShared()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\Memory\Factories\Shared();
		}
		return self::$_cStore[__FUNCTION__];
	}
	public static function getSemaphores()
	{
		if (array_key_exists(__FUNCTION__, self::$_cStore) === false) {
			self::$_cStore[__FUNCTION__]	= new \MTM\Memory\Factories\Semaphores();
		}
		return self::$_cStore[__FUNCTION__];
	}
}