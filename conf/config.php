<?php
ini_set('display_errors', 0); //error reporting
define('SITE_BASE', '/var/www/html/xcp-vending-machine'); //full path to main installation directory
define('SITE_PATH', SITE_BASE);
define('FRAMEWORK_PATH', SITE_PATH.'/lib');

define('MYSQL_DB', '');
define('MYSQL_USER', '');
define('MYSQL_PASS', '');
define('MYSQL_HOST', 'localhost');

define('XCP_USER', ''); //Counterparty username
define('XCP_PASS', ''); //Counterparty RPC password
define('XCP_IP', 'localhost:4000'); //Counterarty IP:Port
define('XCP_CONNECT', 'http://'.XCP_USER.':'.XCP_PASS.'@'.XCP_IP.'/api/');
define('XCP_WALLET', ''); //bitcoind wallet passphrase
define('SATOSHI_MOD', 100000000);


define('BTC_USER', ''); //Bitcoind RPC User
define('BTC_PASS', ''); //Bitcoind RPC Password
define('BTC_IP', 'localhost:8332'); //Bitcoind IP:Port
define('BTC_CONNECT', 'http://'.BTC_USER.':'.BTC_PASS.'@'.BTC_IP.'/');

