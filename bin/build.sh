#!/usr/bin/env bash

if [ $# -lt 1 ]; then
	echo "usage: $0 <plugin-slug>"
	exit 1
fi

PLUGIN=${1}

# Absolute path to this script, e.g. /home/user/bin/foo.sh
SCRIPT=$(readlink -f "$0")

# Absolute path this script is in, thus /home/user/bin
SCRIPTPATH=$(dirname "$SCRIPT")

cd $SCRIPTPATH/..

# If build directory for plugin exists, remove its contents but keep the directory
if [ -d "./build/$PLUGIN" ]; then
	# remove all entries (including dotfiles) safely
	find "./build/$PLUGIN" -mindepth 1 -exec rm -rf -- {} +
fi
mkdir -p ./build/$PLUGIN

# Build rsync exclude parameters from .distignore file
EXCLUDE_PARAMS=(--exclude .distignore)
if [ -f .distignore ]; then
	while IFS= read -r line || [ -n "$line" ]; do
		# Skip empty lines and comments
		[[ -z "$line" || "$line" =~ ^# ]] && continue
		EXCLUDE_PARAMS+=(--exclude "$line")
	done < .distignore
fi

rsync -av . ./build/$PLUGIN "${EXCLUDE_PARAMS[@]}"

cd ./build
zip -r ./$PLUGIN.zip ./$PLUGIN
