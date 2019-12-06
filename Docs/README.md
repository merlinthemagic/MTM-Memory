### What is this?



#### How to clear all shared memory:

```
#!/bin/sh
for i in $(ipcs -q | grep -E "^0x" | awk '{ print $2 }' | sed 1,2d);
do
	ipcrm -q $i
done

for i in $(ipcs -m | grep -E "^0x" | awk '{ print $2 }' | sed 1,2d);
do
	ipcrm -m $i
done

for i in $(ipcs -s | grep -E "^0x" | awk '{ print $2 }' | sed 1,2d);
do
	ipcrm -s $i
done

```