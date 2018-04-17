#!/bin/bash

if [ "$1" == "" ]; then
	read -p "Command: " command
fi

cd ../modules

for dir in $(find . -name ".git")
do
    cd ${dir%/*} > /dev/null
	echo ${dir%/*}

	if [ "$1" == "" ]; then
		$command
	else
		"$@"
	fi

	echo "";
    cd -  > /dev/null
done
