# MTM-Memory

## Shmop:

### Get a shared memory segment:

```
$factObj	= \MTM\Memory\Factories::getShared()->getSystemFive(); //get the factory

$name		= "mySegmentName"; //optional, but helps you locate the segment in other processes
$size		= 15000; //number of bytes the segment can use, defaults to 10000
$perm		= "0600"; //permissions on the segment, defaults to 0644
$memObj	= $factObj->getNewShare($name, $size, $perm); //get a new shared memory segment

```

### Write Data:

```
$name		= "mySegmentName";
$data		= "somedata"; //mixed
$memObj->set($name, $data); //will aquire a lock (blocking) before writing
```

### Read Data:

```
$name		= "mySegmentName";
echo $memObj->get($name); //somedata (will aquire a lock, blocking, before reading
```

### get connection count on a share:

```
echo $memObj->getAttachCount(); // number of processes / threads connected to the memory segment
```

### Keep alive:

```

//if set to false (default), this instance of the share will delete the memory + semaphores if it is the last one
//connected. set to true and this instance will simply terminate and leave the data intact
$bool		= true;
$memObj->setKeepAlive($bool);

```