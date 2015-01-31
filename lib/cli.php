<?php
	$model = new Model;
	switch($argv[1]){
		case 'ignore':
			if(!isset($argv[2])){
				echo "Error: Please include a transaction ID \n";
				die();
			}
			$get = $model->get('ignore_tx', $argv[2], array(), 'txId');
			if($get){
				echo "Transaction already being ignored \n";
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
		case 'ignoreall':
			if(!isset($argv[2])){
				echo "Error: Please include a bitcoin address \n";
				die();
			}
			
			$validate = new BTCValidate;
			if(!$validate->checkAddress($argv[2])){
				echo "Invalid bitcoin address \n";
				die();
			}
			
			$api_url = 'http://btc.blockr.io/api/v1/address/txs/';
			$get_api = json_decode(file_get_contents($api_url.$argv[2]), true);
			$added = 0;
			foreach($get_api['data']['txs'] as $tx){
			$get = $model->get('ignore_tx', $tx['tx'], array(), 'txId');
				if($get){
					continue;
				}
				$add = $model->insert('ignore_tx', array('txId' => $tx['tx']));
				if(!$add){
					echo "Error adding transaction to ignore list [".$tx['tx']."] \n";
					continue;
				}
				else{
					echo "Transaction added to ignore list! [".$tx['tx']."] \n";
					$added++;
				}
			}
			echo $added." transactions ignored \n";
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
		case 'rates':
			include(SITE_BASE.'/conf/gateways.php');
			foreach($gateways as $name => $gateway){
				$gateway = $gateway();
				echo 'Gateway: '.$gateway->gateway_title."\n";
				foreach($gateway->accepted as $token => $rate){
					if(is_array($rate)){
						$rate = $gateway->getLatestRate($rate, $token);
					}
			
					$rate = convertFloat($rate);
					
					echo $token.': '.$rate."\n";
				}
				echo "\n";
			}
			break;
	}
