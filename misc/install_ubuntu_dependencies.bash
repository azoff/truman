#!/usr/bin/env bash

sudo apt-get upgrade
sudo apt-get install git
sudo apt-get install openssh-server 
sudo apt-get install virtualbox-guest-additions
sudo apt-get install virtualbox-ose-guest-utils
sudo apt-get install php5-cli
sudo apt-get install php-pear
sudo pear upgrade pear
sudo pear channel-discover pear.phpunit.de
sudo pear channel-discover components.ez.no
sudo pear channel-discover pear.symfony.com
sudo pear install --alldeps phpunit/PHPUnit
git clone git://github.com/facebook/phpsh.git
cd phpsh && python setup.py build
sudo python setup.py install
cd ../ && rm -rf phpsh/