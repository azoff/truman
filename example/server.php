#!/usr/bin/env php
<? require_once dirname(__DIR__).'/autoload.php';

// pause the script, listen, and delegate incoming jobs
truman\Truman::listen();