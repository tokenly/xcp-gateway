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
	$gateway = new Crypto_Gateway('1CoinEXLQtivDjckWMRBbhgVJk9RdwdYbR', 'BITCOINEX', array('BTC' => 1, 'XBTC' => 1));
	$gateway->allow_two_way = true;
	$gateway->auto_inflate = true;
	$gateway->inflation_mode = 'as_needed';
	$gateway->inflation_modifier = 1;
	$gateway->init();
	
}
else{
	//extra options: <ignore>, <stats>
	$model = new Model;
	switch($argv[1]){
		case 'ignore':
			if(!isset($argv[2])){
				echo "Error: Please include a transaction ID \n";
				die();
			}
			
			$add = $model->insert('ignore_tx', array('txId' => $argv[2]));
			if(!$add){
				echo "Error adding transaction to ignore list \n";
				die();
			}
			else{
				echo "Transaction added to ignore list!\n";
			}
			break;
		case 'stats':
			$getList = $model->getAll('transactions');
			$assetStats = array();
			foreach($getList as $tx){
				$token = $tx['asset'];
				if(!isset($assetStats[$token])){
					$assetStats[$token] = array('received' => 0, 'sent' => 0, 'profit' => 0, 'num_tx' => 0, 'num_sent' => 0, 'num_received' => 0);
				}
				$valid = false;
				switch($tx['type']){
					case 'gateway_receive':
						$valid = true;
						$assetStats[$token]['num_received']++;
						$assetStats[$token]['received']+=$tx['amount'];						
						break;
					case 'gateway_send':
						$valid = true;
						$assetStats[$token]['num_sent']++;
						$assetStats[$token]['sent']+=$tx['amount'];
						break;
				}
				if(!$valid){
					continue;
				}
				$assetStats[$token]['num_tx']++;
				
			}
			foreach($assetStats as &$stat){
				$stat['profit'] = $stat['received'] - $stat['sent'];
			}
			debug($assetStats);
			break;
	}
}
