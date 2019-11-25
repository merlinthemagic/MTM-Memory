<?php
//© 2019 Martin Peter Madsen
namespace MTM\Memory\Models\Shared\SystemV;

class ThirdRW extends Base
{
	//Third readers-writers problem
	//src: https://en.wikipedia.org/wiki/Readers%E2%80%93writers_problem#Third_readers-writers_problem
	//FIFO queuing of readers and writers

	protected $_roRes=null;
	protected $_queueRes=null;
	protected $_roCtrlRes=null;
	protected $_rwCtrlRes=null;
	protected $_roMaxCount=9999;//max number of readers
	protected $_roBytes=4;//9999 max count takes up 4 bytes, if you change roMaxCount to 10000, change this to 5 etc
	
	//stacking the locks so a share can be passed around without loosing the lock between calls if required
	//e.g. used in method-1 that locks, then calls method-2 that also requires a lock
	//the execution will be method-1 gets lock, pass to method 2 that again locks, performs critical and unlocks.
	//When method-2 unlocks we still have a lock, process returns to method-1 finishes critical
	//and unlocks fully
	protected $_rwLocks=0;
	protected $_roLocks=0;
	protected $_queueLocks=0;
	protected $_roCtrlLocks=0;
	protected $_rwCtrlLocks=0;

	protected function terminate()
	{
		if ($this->isTerm() === false) {
			parent::terminate();
			if ($this->isInit() === true) {
				if ($this->_roLocks > 0) {
					$this->_roLocks	= 1;
					$this->roUnlock();
				}
				if ($this->_rwLocks > 0) {
					$this->_rwLocks	= 1;
					$this->rwUnlock();
				}
			}
		}
	}
	public function delete()
	{
		if ($this->isTerm() === false) {
			if ($this->isInit() === true) {

				$this->terminate();
				sem_remove($this->_queueRes);
				sem_remove($this->_roCtrlRes);
				
				shm_remove($this->_shmRes);
				shmop_delete($this->_roRes);
				sem_remove($this->_rwCtrlRes);
			}

		} else {
			throw new \Exception("Cannot delete, share terminated");
		}
	}
	public function getConnectionCount()
	{
		$gotLock	= false;
		if ($this->_rwLocks === 0 && $this->_roLocks === 0) {
			//we need some type of protection to do the read
			$this->roLock();
			$gotLock	= true;
		}
		$count	= $this->read($this->getMaps()[$this->_connHash]);
		if ($gotLock === true) {
			$this->roUnlock();
		}
		return $count;
	}
	protected function rwLock()
	{
		if ($this->_rwLocks === 0) {
			if ($this->_roLocks === 0) {
				
				$this->queue(true); //wait in line to be serviced
				try {
					$this->rwCtrl(true); //request exclusive access to resource
				} catch (\Exception $e) {
					$this->queue(false); //let next in line be serviced
					throw $e;
				}
				$this->queue(false); //let next in line be serviced
				
			} else {
				//requesting a writer when you have reader access would deadlock the process
				throw new \Exception("You cannot obtain a write lock while holding a read lock");
			}
		}
		$this->_rwLocks++;
		return $this;
	}
	protected function rwUnlock()
	{
		if ($this->_rwLocks > 0) {
			if ($this->_rwLocks === 1) {
				$this->rwCtrl(false); //release resource access for next reader/writer
			}
			$this->_rwLocks--;
		}
		return $this;
	}
	protected function roLock()
	{
		if ($this->_roLocks === 0) {
			if ($this->_rwLocks === 0) {
				try {
					
					$qLock	= false;
					$roLock	= false;
					$rwLock	= false;
					$this->queue(true); //wait in line to be serviced
					$qLock	= true;
					$this->roCtrl(true); //request exclusive access to readCount
					$roLock	= true;
					$count	= @shmop_read($this->_roRes, 0, $this->_roBytes);
					if ($count === false) {
						throw new \Exception("Failed to read current read count");
					}
					$count	= intval($count);
					if ($count === 0) {
						if (@sem_acquire($this->_rwCtrlRes, false) === false) {
							// request resource access for readers (writers blocked), this lock has to be independant of the counter
							throw new \Exception("Failed to obtain read write lock for readers exclusive access");
						}
						$rwLock	= true;
					}
					if ($this->_roMaxCount > $count) {
						if (@shmop_write($this->_roRes, ($count + 1), 0) === false) {
							throw new \Exception("Failed to write new reader count");
						}
					} else {
						throw new \Exception("Max readers have been reached: " . $count);
					}

					$this->queue(false); //let next in line be serviced
					$qLock	= false;
					$this->roCtrl(false);//release access to readCount
					$roLock	= false;
					
				} catch (\Exception $e) {
					//clean up the best you can
					if ($rwLock === true) {
						try {
							if (@sem_release($this->_rwCtrlRes) === false) {
								throw new \Exception("Failed to unlock read write");
							}
						} catch (\Exception $e1) {
						}
					}
					if ($roLock === true) {
						try {
							$this->roCtrl(false);
						} catch (\Exception $e2) {
						}
					}
					if ($qLock === true) {
						try {
							$this->queue(false);
						} catch (\Exception $e3) {
						}
					}
					throw $e;
				}
			} else {
				//this gets messy, technically we are safe to read, since we hold a write lock
				//but we cannot control the order the locks are released. If the user releases
				//the write lock before the read it ends in corruption, because a writer has free access
				throw new \Exception("You cannot obtain a read lock while holding a write lock");
			}
		}
		$this->_roLocks++;
		return $this;
	}
	protected function roUnlock()
	{
		if ($this->_roLocks > 0) {
			if ($this->_roLocks === 1) {

				try {
					
					$roLock	= false;
					$this->roCtrl(true); //request exclusive access to readCount
					$roLock	= true;
					
					$count	= @shmop_read($this->_roRes, 0, $this->_roBytes);
					if ($count === false) {
						throw new \Exception("Failed to read current read count");
					}
					$count	= intval($count);
					if (@shmop_write($this->_roRes, ($count - 1), 0) === false) {
						throw new \Exception("Failed to set reader count");
					}
					if ($count === 1) {
						// release resource access for all, this unlock has to be independant of the counter
						if (@sem_release($this->_rwCtrlRes) === false) {
							throw new \Exception("Failed to unlock read write from reader exclusive access");
						}
					}
					$this->roCtrl(false);//release access to readCount
					$roLock	= false;
					
				} catch (\Exception $e) {
					//clean up the best you can
					if ($roLock === true) {
						try {
							$this->roCtrl(false);
						} catch (\Exception $e1) {
						}
					}
					throw $e;
				}
			}
			$this->_roLocks--;
		}
		return $this;
	}
	protected function initialize()
	{
		if ($this->isInit() === false) {

			$defaults		= false;
			$octPerm		= intval($this->getPermissions(), 8); //convert permissions
			//generate the share Id dynamically from the name.
			$shmId			= \MTM\Utilities\Factories::getStrings()->getHashing()->getAsInteger($this->getName(), 4294967295);
			
			//Shares
			$this->_shmRes		= @shm_attach($shmId++, $this->getSize(), $octPerm); //The shared memory segment
			$this->_roRes		= @shmop_open($shmId++, "c", $octPerm, $this->_roBytes);
			
			//Semaphores
			$this->_queueRes	= @sem_get($shmId++, 1, $octPerm, 1); //Semaphore Queue ensuring FIFO access to resource
			$this->_roCtrlRes	= @sem_get($shmId++, 1, $octPerm, 1); //Semaphore controlling access to the read counter share
			$this->_rwCtrlRes	= @sem_get($shmId++, 1, $octPerm, 1); //Semaphore controlling RW access to the share
			
			if (is_resource($this->_shmRes) === false) {
				throw new \Exception("Failed to obtain resource for share");
			} elseif (is_resource($this->_roRes) === false) {
				throw new \Exception("Failed to obtain resource for read counter");
			} elseif (is_resource($this->_queueRes) === false) {
				throw new \Exception("Failed to obtain resource for queue semaphore");
			} elseif (is_resource($this->_roCtrlRes) === false) {
				throw new \Exception("Failed to obtain resource for read counter semaphore");
			} elseif (is_resource($this->_rwCtrlRes) === false) {
				throw new \Exception("Failed to obtain resource for writer semaphore");
			}
			
			$this->rwLock();
			try {
				$this->roCtrl(true);
			} catch (\Exception $e) {
				$this->rwUnlock();
				throw $e;
			}
			try {
				
				if (trim(shmop_read($this->_roRes, 0, $this->_roBytes)) === "") {
					//we are initializing the share
					if (shmop_write($this->_roRes, 0, 0) === false) {
						throw new \Exception("Failed to initialize the read counter");
					}
					$defaults		= true;
				}

			} catch (\Exception $e) {
				$this->roCtrl(false);
				$this->rwUnlock();
				throw $e;
			}
			try {
				$this->roCtrl(false);
			} catch (\Exception $e) {
				$this->rwUnlock();
				throw $e;
			}
			
			if ($defaults === true) {
				$this->setDefaults();
			}
			$this->rwUnlock();
			$this->_isInit		= true;
		}
		return $this;
	}
	protected function queue($lock)
	{
		if ($lock === true) {
			if ($this->_queueLocks === 0) {
				if (@sem_acquire($this->_queueRes, false) === false) {
					throw new \Exception("Failed to obtain queue lock");
				}
			}
			$this->_queueLocks++;
		} else {
			if ($this->_queueLocks > 0) {
				if ($this->_queueLocks === 1) {
					if (@sem_release($this->_queueRes) === false) {
						throw new \Exception("Failed to unlock queue");
					}
				}
				$this->_queueLocks--;
			}
		}
		return $this;
	}
	protected function roCtrl($lock)
	{
		if ($lock === true) {
			if ($this->_roCtrlLocks === 0) {
				if (@sem_acquire($this->_roCtrlRes, false) === false) {
					throw new \Exception("Failed to obtain read only lock");
				}
			}
			$this->_roCtrlLocks++;
		} else {
			if ($this->_roCtrlLocks > 0) {
				if ($this->_roCtrlLocks === 1) {
					if (@sem_release($this->_roCtrlRes) === false) {
						throw new \Exception("Failed to unlock read only");
					}
				}
				$this->_roCtrlLocks--;
			}
		}
		return $this;
	}
	protected function rwCtrl($lock)
	{
		if ($lock === true) {
			if ($this->_rwCtrlLocks === 0) {
				if (@sem_acquire($this->_rwCtrlRes, false) === false) {
					throw new \Exception("Failed to obtain read write lock");
				}
			}
			$this->_rwCtrlLocks++;
		} else {
			if ($this->_rwCtrlLocks > 0) {
				if ($this->_rwCtrlLocks === 1) {
					if (@sem_release($this->_rwCtrlRes) === false) {
						throw new \Exception("Failed to unlock read write");
					}
				}
				$this->_rwCtrlLocks--;
			}
		}
		return $this;
	}
}