<?php
/**
 * Counterparty token gateway prototype 
 * Accepts BTC or another counterparty token, spits out another type of token based on defined exchange rate
 * 
 * */
require_once('conf/config.php');
include(FRAMEWORK_PATH.'/functions.php');

if(!isset($argv[1])){
	//load it up!
	echo "Constructing gateway system\n";
	include('conf/gateways.php');
	//run __constructors on each gateway
	foreach($gateways as $name => $gateway){
		$gateways[$name] = $gateway();
	}
	
	//run the loop
	echo "Gateway(s) in progress \n";
	while(true){
		foreach($gateways as $name => $gateway){
			$gateway->init();
		}
		//wait a few minutes before running the loop again
		sleep(60);
	}
}
else{
	//CLI options: <ignore>, <stats>
	include(FRAMEWORK_PATH.'/cli.php');
}
