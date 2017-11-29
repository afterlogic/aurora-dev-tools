#!/bin/bash

update_push () 
{
	#prepare tags
	loginWithPassword=$1":"$2"@github.com"
	loginWithAt=$1"@"
	emptyString=""
	guthubString="github.com"

	url="$(git config --get remote.origin.url)"
	url="${url/$loginWithAt/$emptyString}"
	resultUrl="${url/$guthubString/$loginWithPassword}"
	
	#commit
	if [ "$3" != "" ]; then
		git add  -A
		git commit -m "$3"
	fi
	
	#pull
	git pull
	
	#add tag
	if [ "$4" != "" ]; then
		git tag -a "$4" -m ""
	fi

	#push changes
	git push --repo $resultUrl --tags
} 

read -p "GitHub Login: " login
read -p "GitHub Password: " password
read -p "Commit message: " commit
read -p "Tag name: " tag

cd ../modules

for dir in $(find . -name ".git")
do
    cd ${dir%/*} > /dev/null
    
	echo ${dir%/*}

	update_push $login $password "$commit" "$tag"

	echo "";

    cd -  > /dev/null
done