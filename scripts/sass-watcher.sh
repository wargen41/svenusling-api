#!/bin/sh
SCRIPT="$0"
cd "$(dirname "$SCRIPT")" || exit 1
cd ..
sass --watch src/Styles:public/css --style=expanded --source-map
