#!/bin/bash

#php apigen.phar generate -s ./../../modules/ -d ./../../docs/api --config ./../../apigen.neon
php apigen.phar --working-dir ../../
php update-apigen.php
