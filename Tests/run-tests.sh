#!/bin/sh

../vendor/bin/phpunit -v -c --debug -d zend.enable_gc=0 ../phpunit.xml $*
