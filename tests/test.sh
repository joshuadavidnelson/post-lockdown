#!/bin/bash

clear
files=$(find ../ -mindepth 1 -maxdepth 2 -type f -name '*.php')

echo "Removing any surrounding spaces between array keys that contain a string or an integer...\n"

for i in $files; do
	echo "Formatting $i\n"
	cp $i $i.orig
	# This regex replaces [ 0 ] with [0], [ 123 ] with [123], [ "ID" ] with ["ID"] and [ 'ID' ] with ['ID']
	sed -ri "s/\[[ ]*([0-9]+|[\x27|\x22][^\x27|\x22]*[\x27|\x22])[ ]*\]/[\1]/g" $i
	sed -ri "s/\[ \]/[\1]/g" $i
	# Delete the backup if no changes were made
	cmp --silent post-lockdown.php post-lockdown.php.orig && rm -f $i.orig
done

echo "Running PHP Linter...\n"

for i in $files; do
	php -l $i
done

STANDARD="WordPress-Extra"

echo "Running PHPCodeSniffer with $STANDARD standards...\n"

ERRORS=0
for i in $files; do
	phpcs --standard="$STANDARD" $i

	if [ "$?" != 0 ]; then
		((ERRORS++))
	fi
done

if [ "$ERRORS" == 0 ]; then
	echo "All sniffs passed"
else
	echo "$ERRORS sniff(s) failed"
fi
