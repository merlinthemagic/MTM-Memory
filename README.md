# MTM-Memory

## Shmop:

### Get a shared memory segment:

```
$factObj	= \MTM\Memory\Factories::getShared()->getShmop(); //get the factory

$name		= "mySegmentName"; //optional, but helps you locate the segment in other processes
$size		= 15000; //number of bytes the segment can use, defaults to 10000
$perm		= "0600"; //permissions on the segment, defaults to 0644
$memObj	= $factObj->getNewShare($name, $size, $perm); //get a new shared memory segment

```

