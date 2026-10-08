#!/bin/sh
set -eu
cd "$(dirname "$0")/../server"
python3 -m unittest discover -s tests -v
python3 -m compileall -q .
