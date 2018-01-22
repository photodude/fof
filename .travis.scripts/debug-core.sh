#!/bin/sh

PHP_BINARY=`which php`
gdb -batch -ex "bt full" -ex "quit" "${PHP_BINARY}" "${1}"
