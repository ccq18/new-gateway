#!/usr/bin/env bash
a=$*
docker run --rm -it -p 55151:55151   -p 1236:1236    -p 7272:7272   -v $PWD:/www  ccq18/php-cli:7.1-v2  php /www/$1 ${a##*$1}
