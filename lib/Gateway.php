<?php

Model::$cacheMode = false;
class Crypto_Gateway extends Model
{
	public $watch_address = false; //defaults to source address, but can be manually changed
	public $min_btc = 0.000055; //BTC dust limit, used mostly for if source token is just BTC
	public $min_confirms = 1; //Min number of confirms before vending out token
	public $miner_fee = 0.00005; //BTC miners fee for xcp transaction
	public $dust_size = 0.000055; //size to use for each dust output on xcp transactions
	public $service_fee = 0.5; //% fee to take off any incoming tokens
	public $allow_two_way = false; //set to true to allow two-way vending
	public $auto_inflate = false; //set to true to have it automatically issue new tokens when it runs out
	public $inflation_mode = 'as_needed'; //options: fixed, percent, as_needed
	public $inflation_modifier = 1; //modifier that effects how many new tokens are created per issuance, depending on mode
	public $inflatable_tokens = array(); //list of tokens which can be auto-issued
	public $source_pubkey = false; //leave false to auto attempt to get the pubkey of the source address
	public $gateway_title = false;
	public $min_amount = 0;
	
	private $token_info = false; //stores info such as divisibility etc. of main source token
	private $accepted_info = array(); //stores any info for tokens being accepted for gateway
	private $btc_data = array('issuer' => false, 'supply' => 2100000000000000, 'description' => false,
							  'locked' => true, 'divisible' => true, 'asset' => 'BTC', 'call_date' => false,
							  'owner' => false, 'call_price' => false, 'callable' => false); //don't change this!
	private $ignore_tx = array();
	
	
	/**
	 * @param $source (string) - bitcoin address to use as source vending address
	 * @param $token (string) - token name to vend out of the gateway
	 * @param $accepted (array) - array of input tokens that can be accepted in format (Token => Exchange Ratio)
	 * 
	 * */
	function __construct($source, $token, $accepted = array('BTC' => 1), $title = false)
	{
		parent::__construct();
		$this->token = strtoupper($token);
		$this->accepted = $accepted;
		$this->source_address = $source;
		$this->watch_address = $source;
		$this->btc = new BitcoinRPC(BTC_CONNECT);
		$this->xcp = new BitcoinRPC(XCP_CONNECT);
		
		//setup list of transactions to ignore
		$getIgnore = $this->getAll('ignore_tx');
		foreach($getIgnore as $row){
			$this->ignore_tx[] = $row['txId'];
		}
		
		if($token != 'BTC'){
			$this->inflatable_tokens[] = $token;
		}
		
		$this->verifySources(); //check source and watch address
		$this->grabTokenInfo(); //get info for any relevant tokens		
		
		//set a title for this gateway.. not particularly important
		$this->gateway_title = $title;
		if(!$title){
			$this->gateway_title = $token;
		}
		
		echo "[".$this->gateway_title."] constructed - ".$this->watch_address." \n";
	}
	
	/**
	 * Initialize the gateway loop etc.
	 * 
	 * */
	public function init()
	{
		$pendingIssuance = false;
		if($this->auto_inflate AND count($this->inflatable_tokens) > 0){
			$pendingIssuance = $this->checkPendingIssuances(); //if inflation enabled, check if any issuances are pending
		}
		
		$pendingSends = $this->checkPendingSends();
		
		if(!$pendingIssuance AND !$pendingSends){
			$sendsFound = $this->getIncomingSends(); //get list of incoming transactions
			if(count($sendsFound) > 0){
				echo "Sends found: ".count($sendsFound)."\n";
				$this->vend($sendsFound); //process sends
			}
		}
		
		//reset BTC and XCP transaction lists for next interval
		$this->get_api = false;
		$this->get_xcp = false;
		
	}
	
	private function verifySources()
	{
		//verify source/watch addresses
		$verify = new BTCValidate;
		if(!$verify->checkAddress($this->source_address)){
			throw new Exception("Error: Invalid source address!\n");
		}
		if(!$verify->checkAddress($this->watch_address)){
			throw new Exception("Error: Invalid watch address!\n");
		}
		
		if(!$this->source_pubkey){
			//get pubkey for source address
			try{
				$validate = $this->btc->validateaddress($this->source_address);
				if(!$validate OR !$validate['ismine']){
					throw new Exception('Address not from this wallet');
				}
				$this->source_pubkey = $validate['pubkey'];
			}
			catch(Exception $e){
				throw new Exception('Error getting source address ['.$this->source_address.'] pubkey:'. $e->getMessage()."\n");
			}
			if(!$this->source_pubkey){
				throw new Exception("Could not get source address pubkey [".$this->source_address."]\n");
			}
		}
	}
	
	protected function grabTokenInfo()
	{
		//get info for source token and accepted tokens
		try{
			//check source token info
			switch($this->token){
				case 'BTC':
					$this->token_info = $this->btc_data;
					break;
				default:
					//xcp based token
					$this->token_info = $this->xcp->get_asset_info(array('assets' => array($this->token)));
					if(is_array($this->token_info)){
						$this->token_info = $this->token_info[0];
					}
					break;
			}
			$this->accepted_info[$this->token] = $this->token_info;
			
			//check accepted token info
			foreach($this->accepted as $token => $rate){
				if(is_array($rate)){
					$rate = $this->getLatestRate($rate, $token);
				}
				if($rate <= 0){
					throw new Exception($token." exchange rate must be > 0");
				}
				switch($token){
					case 'BTC':
						$this->accepted_info[$token] = $this->btc_data;
						break;
					default:
						//xcp based token
						$this->accepted_info[$token] = $this->xcp->get_asset_info(array('assets' => array($token)));
						if(is_array($this->accepted_info[$token])){
							$this->accepted_info[$token] = $this->accepted_info[$token][0];
						}						
						break;
				}
			}
		}
		catch(Exception $e){
			throw new Exception("Error obtaining asset info: ".$e->getMessage()."\n");
		}		
	}
	
	protected function getIncomingSends()
	{
		//check the watch address for new transactions
		$sendsFound = array();
		try{
			$ar1 = $this->getCounterpartySends($this->token, $this->accepted);
			$ar2 = array();
			if(isset($this->accepted['BTC'])){
				$ar2 = $this->getBitcoinSends($this->token, $this->accepted['BTC']);
			}
			$ar3 = $this->getSourceTokenSends();
			$sendsFound = array_merge($ar1, $ar2, $ar3);
		
		}
		catch(Exception $e){
			throw new Exception("Error checking watch address balances: ".$e->getMessage()." ".timestamp()."\n");
		}
		
		return $sendsFound;		
	}
	
	protected function getCounterpartySends($vend_token, $accepted = array(), $noFee = false)
	{
		$sendsFound = array();
		//check counterparty transactions
		if(!isset($this->get_xcp) OR !$this->get_xcp){
			$getSends = $this->xcp->get_sends(array('filters' =>
													array('field' => 'destination', 'op' => '==', 'value' => $this->watch_address),
													'limit' => 100,
													'order_by' => 'block_index',
													'order_dir' => 'DESC'));
			$this->get_xcp = $getSends;
		}
		else{
			$getSends = $this->get_xcp;
		}
												
		//loop through and process transaction list
		$grouped = array();
		foreach($getSends as $send){
			if($send['status'] == 'valid' AND isset($accepted[$send['asset']])){
				
				$asset = $send['asset'];
				$rate = $accepted[$asset];
				if(is_array($rate)){
					$rate = $this->getLatestRate($rate, $asset);
				}					
				
				//check if this transaction has been seen before
				$checkTx = $this->getAll('transactions', array('type' => 'gateway_receive',
															   'destination' => $this->watch_address,
															   'txId' => $send['tx_hash']));
				if(count($checkTx) > 0 OR in_array($send['tx_hash'], $this->ignore_tx)){
					//tx already recorded... carry on
					continue;
				}
				
				if(!isset($grouped[$send['source']])){
					$grouped[$send['source']] = array();
				}
				if(!isset($grouped[$send['source']][$send['asset']])){
					$grouped[$send['source']][$send['asset']] = array('tx' => array(), 'total' => 0);
				}

				$quantity = $send['quantity'];
				if($this->accepted_info[$asset]['divisible']){
					$quantity = $quantity / SATOSHI_MOD;
				}						
				
				$send['final_quantity'] = $quantity;
				$send['real_quantity'] = $quantity;				
				
				$grouped[$send['source']][$send['asset']]['tx'][] = $send;
				$grouped[$send['source']][$send['asset']]['total'] += $quantity;
				$groupQuantity = $grouped[$send['source']][$send['asset']]['total'];
				
				//prep info for list of pending sends
			
				$fee = 0;
				if(!$noFee){
					$fee = ceil(($groupQuantity * ($this->service_fee / 100)));	
					if($this->accepted_info[$asset]['divisible']){
						$fee = round(($groupQuantity * ($this->service_fee / 100)), 8);	
					}
				}
				$finalQuantity = $groupQuantity - $fee;
				$send_amount = $finalQuantity * $rate;
				
				if($send_amount < $this->min_amount){
					//echo "[".$this->gateway_title."] Not enough ".$send['asset']." funds from ".$send['source'].", waiting (".$groupQuantity." = ".$send_amount.")\n";
					continue;
				}

				$item = array();
				$item['income'] = $grouped[$send['source']][$send['asset']];
				$item['fee'] = $fee;
				$item['send_to'] = $send['source'];
				$item['amount'] = $send_amount;
				$item['vend_token'] = $vend_token;
				if(!$this->accepted_info[$vend_token]['divisible']){
					$item['amount'] = floor($item['amount']);
				}
				else{
					$item['amount'] = round($item['amount'], 8);
				}
				$sendsFound[] = $item;
			}
		}
		return $sendsFound;			
	}
	
	protected function getBitcoinSends($vend_token, $rate, $noFee = false)
	{
		$sendsFound = array();
		if(is_array($rate)){
			$rate = $this->getLatestRate($rate, 'BTC');
		}		
		//also check BTC balances, just use blockr.io
		if(!isset($this->get_api) OR !$this->get_api){
			$api_url = 'http://btc.blockr.io/api/v1/address/txs/';
			$get_api = json_decode(file_get_contents($api_url.$this->watch_address), true);
		}
		else{
			$get_api = $this->get_api;
		}
		
		if(!$get_api OR $get_api['code'] != 200){
			throw new Exception('Could not get blockr.io data');
		}
		$grouped = array();
		foreach($get_api['data']['txs'] as $tx){
			if($tx['confirmations'] >= $this->min_confirms
			AND $tx['amount'] > 0
			AND $tx['amount_multisig'] == 0){
				
				//check if this transaction has been seen before
				$checkTx = $this->getAll('transactions', array('type' => 'gateway_receive',
															   'destination' => $this->watch_address,
															   'txId' => $tx['tx']));
				if(count($checkTx) > 0 OR in_array($tx['tx'], $this->ignore_tx)){
					//tx already recorded... carry on
					continue;
				}					
					
				$tx['asset'] = 'BTC';
				$tx['real_quantity'] = $tx['amount'];
				$tx['quantity'] = round($tx['amount'] * SATOSHI_MOD);
				
				$fee = 0;
				if(!$noFee){
					$fee = round(($tx['amount'] * ($this->service_fee / 100)), 8);
				}
				$tx['final_amount'] = $tx['amount'] - $fee;
				$tx['final_quantity'] = $tx['final_amount'];	
				$tx['destination'] = $this->watch_address;
				$tx['tx_hash'] = $tx['tx'];
				$source = $this->getTxInputAddress($tx['tx']);
				if(!$source){
					continue;
				}
				$tx['source'] = $source;
				
				if(!isset($grouped[$tx['source']])){
					$grouped[$tx['source']] = array('tx' => array(), 'total' => 0);
				}
				$grouped[$tx['source']]['tx'][] = $tx;
				$grouped[$tx['source']]['total'] += $tx['real_quantity'];
				$groupQuantity = $grouped[$tx['source']]['total'];
				
				$send_amount = $rate * $groupQuantity;
				if($send_amount < $this->min_amount){
					//echo "[".$this->gateway_title."] Not enough BTC funds from ".$tx['source'].", waiting (".$groupQuantity." = ".$send_amount.")\n";
					continue;
				}
				
				$item = array();
				$item['fee'] = $fee;
				$item['amount'] = $send_amount;	
				$item['income'] = $grouped[$tx['source']];
				if(!$this->token_info['divisible']){
					$item['amount'] = floor($item['amount']);
				}
				else{
					$item['amount'] = round($item['amount'], 8);					
				}			
				
				$item['send_to'] = $tx['source'];
				$item['vend_token'] = $vend_token;
											
				$sendsFound[] = $item;
			}
		}
		
		return $sendsFound;			
	}
	
	protected function getTxInputAddress($txId)
	{
		//decode raw tx to figure out where inputs came from
		$inputAddresses = array();
		$getRealTx = $this->btc->getrawtransaction($txId);
		$decodeRaw = $this->btc->decoderawtransaction($getRealTx);
		
		if(isset($decodeRaw['vout'][1])){
			if($decodeRaw['vout'][1]['scriptPubKey']['type'] == 'multisig'){
				//not a normal transaction, probably counterparty.. ignore
				return false;
			}
		}

		foreach($decodeRaw['vin'] as $vin){
			$getRaw2 = $this->btc->getrawtransaction($vin['txid']);
			$decodeRaw2 = $this->btc->decoderawtransaction($getRaw2);
			$inputAddresses[$decodeRaw2['vout'][$vin['vout']]['scriptPubKey']['addresses'][0]] = $decodeRaw2['vout'][$vin['vout']]['value'];
		}
		
		$biggestInput = false;
		$biggestInputAmnt = 0;
		foreach($inputAddresses as $addr => $amnt){
			if($amnt > $biggestInputAmnt){
				$biggestInputAmnt = $amnt;
				$biggestInput = $addr;
			}
		}
		
		if($biggestInput == $this->source_address OR $biggestInput == $this->watch_address){
			return false; //probably change getting sent back to itself from moving funds, just ignore!
		}
		
		return $biggestInput;
	}
	
	protected function vend($sends)
	{
	
		//get total amounts needed to send
		$vendingTokens = array();
		foreach($sends as $send){
			if(!isset($vendingTokens[$send['vend_token']])){
				$vendingTokens[$send['vend_token']] = $send['amount'];
			}
			else{
				$vendingTokens[$send['vend_token']] += $send['amount'];
			}
		}
		
		//check balances
		try{
			$balances = $this->xcp->get_balances(array('filters' => array('field' => 'address', 'op' => '=', 'value' => $this->source_address)));
			
			$btc_info = json_decode(file_get_contents('http://btc.blockr.io/api/v1/address/info/'.$this->source_address), true);
			$btc_balance = 0;
			if($btc_info){
				$btc_balance = $btc_info['data']['balance'];
			}
		}
		catch(Exception $e){
			throw new Exception('Error getting balances: '.$e->getMessage()." ".timestamp()."\n");
		}
		
		foreach($vendingTokens as $vendAsset => $vendAmount){
			
			if($vendAsset == 'BTC'){
				if($btc_balance < $vendAmount){
					throw new Exception("Insufficient balance for ".$vendAsset." (need ".convertFloat($vendAmount).") ".timestamp()."\n");
				}
			}
			else{
				$found = false;
				foreach($balances as $balance){
					if($balance['asset'] == $vendAsset){
						$found = true;
						$divisible = true;
						if(isset($this->accepted[$vendAsset])){
							$divisible = $this->accepted_info[$vendAsset]['divisible'];
						}
						if($divisible){
							$balance['quantity'] = $balance['quantity'] / SATOSHI_MOD;
						}
						if($balance['quantity'] < $vendAmount){
							if($this->auto_inflate AND in_array($vendAsset, $this->inflatable_tokens)){
								//create a new issuance and wait until its done before moving onwards
								$needed = $vendAmount- $balance['quantity'];
								$this->autoInflateToken($vendAsset, $needed);
								return array();
							}
							throw new Exception("Insufficient balance for ".$vendAsset." (need ".convertFloat($vendAmount).") ".timestamp()."\n");
						}
					}
				}
				if(!$found){
					if($this->auto_inflate AND in_array($vendAsset, $this->inflatable_tokens)){
						$this->autoInflateToken($vendAsset, $vendAmount);
						return array();
					}
				}
			}
		}
		
		//unlock wallet
		try{
			echo "Unlocking wallet\n";
			$this->btc->walletpassphrase(XCP_WALLET, 300);
		}
		catch(Exception $e){
			throw new Exception("Could not unlock wallet: ".$e->getMessage()." ".timestamp()."\n");
		}		
		echo "Wallet unlocked\n";

		//loop through pending sends
		foreach($sends as $send){
			$sendTX = false;
			$refunded = false;
			try{
				switch($send['vend_token']){
					case 'BTC':
						echo "Sending BTC TX\n";
						if($send['amount'] < $this->dust_size){
							echo "BTC TX below dust limit (".$send['amount'].")\n";
							$this->refund($send);
							$sendTX = false;
							$refunded = true;
						}
						else{
							$this->btc->settxfee($this->miner_fee);
							$sendTX = $this->btc->sendfromaddress($this->source_address, $send['amount'], $send['send_to']);
						}
						break;
					default:
						echo "Sending XCP TX\n";
						//send out counterparty tokens
						$quantity = (int)round(round($send['amount'], 8) * SATOSHI_MOD);
						$sendData = array('source' => $this->source_address, 'destination' => $send['send_to'],
										  'asset' => $send['vend_token'], 'quantity' => $quantity, 'allow_unconfirmed_inputs' => true,
										  'pubkey' => $this->source_pubkey,
										  'fee' => ($this->miner_fee * SATOSHI_MOD),
										  'regular_dust_size' => (($this->dust_size / 2) * SATOSHI_MOD),
										  'multisig_dust_size' => (($this->dust_size / 2) * SATOSHI_MOD)
										  );
				
						$getRaw = $this->xcp->create_send($sendData);
						$sign = $this->xcp->sign_tx(array('unsigned_tx_hex' => $getRaw));
						$sendTX = $this->xcp->broadcast_tx(array('signed_tx_hex' => $sign));
						break;
				}
			}
			catch(Exception $e){
				throw new Exception('Error sending '.$send['vend_token'].': '.$e->getMessage()." ".timestamp()."\n");
			}			

			
			//save incoming/outgoing transactions
			$time = timestamp();
			if(($refunded AND !$sendTX) OR ($sendTX)){
				foreach($send['income']['tx'] as $income){
					$saveReceive = $this->insert('transactions', array('type' => 'gateway_receive',
																	   'source' => $income['source'],
																	   'destination' => $this->watch_address,
																	   'amount' => $income['real_quantity'],
																	   'txId' => $income['tx_hash'],
																	   'confirmed' => 1,
																	   'txDate' => $time,
																	   'asset' => $income['asset']));
					echo $income['real_quantity'].' '.$income['asset']." received!\n";
				}
			}
			
			if($sendTX){		   
				$saveSend = $this->insert('transactions', array('type' => 'gateway_send',
																   'source' => $this->source_address,
																   'destination' => $send['send_to'],
																   'amount' => $send['amount'],
																   'txId' => $sendTX,
																   'confirmed' => 0,
																   'txDate' => $time,
																   'asset' => $send['vend_token']));
				echo 'Vended '.$send['amount'].' '.$send['vend_token'].' to '.$send['send_to'].': '.$sendTX." ".timestamp()."\n";																		
			}
															   
			
			//wait a few seconds to avoid sending transactions too fast and causing errors
			sleep(10);
		}
		
		try{
			$this->btc->walletlock();
		}
		catch(Exception $e){
			//do nothing
		}		
		
	}
	
	protected function getSourceTokenSends()
	{
		//check transactions again for two-way vending functionality
		//vends out the first token in the "accepted" list
		
		if(!$this->allow_two_way){
			return array();
		}
		
		$firstRate = false;
		$firstToken = '';
		foreach($this->accepted as $accept => $rate){
			if(is_array($rate)){
				$rate = $this->getLatestRate($rate, $accept);
			}			
			$firstRate = $rate;
			$firstToken = $accept;
			break;
		}
		
		$useRate = 1 / $firstRate;

		switch($this->token){
			case 'BTC':
				$sends = $this->getBitcoinSends($firstToken, $useRate, true);
				break;
			default:
				$sends = $this->getCounterpartySends($firstToken, array($this->token => $useRate), true);
				break;
		}
		
		return $sends;
	}
	
	protected function getLatestSupply($token){
		$info = $this->xcp->get_asset_info(array('assets' => array($token)));
		if(is_array($info)){
			$this->accepted_info[$token]['supply'] = $info[0]['supply'];
		}
		if($this->token == $token){
			$this->token_info['supply'] = $this->accepted_info[$token]['supply'];
		}
		return $info[0]['supply'];
	}
	
	protected function autoInflateToken($token, $needed = 0)
	{
		//create a new token issuance
		$supply = $this->getlatestSupply($token);
		if($this->accepted_info[$token]['divisible']){
			$supply = $supply / SATOSHI_MOD;
		}
		$newTokens = 0;
		switch($this->inflation_mode){
			case 'fixed':
				$newTokens = $this->inflation_modifier; //create a fixed amount of tokens, e.g 50,000
				break;
			case 'percent':
				$newTokens = $supply * $this->inflation_modifier; //increase by percentage of total supply. e.g $supply * 0.05 = 5% inflation
				break;
			case 'as_needed':
			default:
				$newTokens = $needed * $this->inflation_modifier; //create exactly as much tokens as needed, or 2X, 3X etc.
				break;
		}
		
		$realTokens = $newTokens;
		if($this->accepted_info[$token]['divisible']){
			$newTokens = round($newTokens * SATOSHI_MOD);
		}
		else{
			$newTokens = round($newTokens);
		}
		
		try{
			$this->btc->walletpassphrase(XCP_WALLET, 300);
		}
		catch(Exception $e){
			throw new Exception("Could not unlock wallet: ".$e->getMessage()."\n");
		}				
		
		$issueData = array('source' => $this->source_address, 'quantity' => $newTokens,
						   'asset' => $token, 'allow_unconfirmed_inputs' => true, 'description' => $this->accepted_info[$token]['description']);
						   
		$getRaw = $this->xcp->create_issuance($issueData);
		$sign = $this->xcp->sign_tx(array('unsigned_tx_hex' => $getRaw));
		$sendTX = $this->xcp->broadcast_tx(array('signed_tx_hex' => $sign));						   
		
		try{
			$this->btc->walletlock();
		}
		catch(Exception $e){
			//do nothing
		}
		
		$issuance = array('source' => $issueData['source'], 'asset' => $issueData['asset'], 'amount' => $realTokens,
						  'new_supply' => ($supply + $realTokens), 'txId' => $sendTX, 'complete' => 0, 'issueDate' => timestamp());
		$add = $this->insert('issuances', $issuance);
		
		echo 'New issuance of '.$realTokens.' '.$token.' created: '.$sendTX." ".timestamp()."\n";
		$this->insert('ignore_tx', array('txId' => $sendTX));
		
		return true;
	}
	
	protected function checkPendingIssuances()
	{
		$getItems = $this->getAll('issuances', array('source' => $this->source_address, 'complete' => 0));
		if(count($getItems) == 0){
			return false; //no pending issuances, all clear
		}
		
		$incomplete = count($getItems);
		foreach($getItems as $item){
			$checkTx = json_decode(file_get_contents('http://btc.blockr.io/api/v1/tx/info/'.$item['txId']), true);
			if($checkTx AND isset($checkTx['data']['confirmations'])){
				if($checkTx['data']['confirmations'] > 0){
					$update = $this->edit('issuances', $item['id'], array('complete' => 1));
					if($update){
						echo "Issuance complete: ".$item['amount']." ".$item['asset']." ".timestamp()."\n";
						$incomplete--;
						//lets wait a minute to avoid duplicate issuances
						sleep(60);
					}
				}
			}
		}
		
		if($incomplete <= 0){
			return false;
		}
		return true;
	}
	
	protected function checkPendingSends()
	{
		$getItems = $this->getAll('transactions', array('source' => $this->source_address, 'confirmed' => 0, 'type' => 'gateway_send'));
		if(count($getItems) == 0){
			return false; //no pending issuances, all clear
		}
		
		$incomplete = count($getItems);
		foreach($getItems as $item){
			$checkTx = json_decode(file_get_contents('http://btc.blockr.io/api/v1/tx/info/'.$item['txId']), true);
			if($checkTx AND isset($checkTx['data']['confirmations'])){
				if($checkTx['data']['confirmations'] > 0){
					$update = $this->edit('transactions', $item['id'], array('confirmed' => 1));
					if($update){
						$incomplete--;
					}
				}
			}
		}
		
		if($incomplete <= 0){
			return false;
		}
		return true;
	}
	
	public function getLatestRate($rate, $token)
	{
		switch($rate['type']){
			case 'fixed':
				$rate = $rate['value'];
				break;
			case 'function':
				$rate = $rate['function']();
				break;
			case 'feed':
				$ch = curl_init();
				$curlFeed = array(CURLOPT_URL => $rate['endpoint'],
								  CURLOPT_RETURNTRANSFER => true);
				if(isset($rate['method']) AND $rate['method'] == 'POST'){
					$curlFeed[CURLOPT_POST] = true;
					if(isset($rate['data'])){
						$curlFeed[CURLOPT_POSTFIELDS] = $rate['data'];
					}								
				}
				else{
					if(isset($rate['data'])){
						$curlFeed[CURLOPT_URL] .= '?'.http_build_query($rate['data']);
					}
				}
				curl_setopt_array($ch, $curlFeed);
				$getFeed = curl_exec($ch);

				if(!$getFeed){
					throw new Exception('Failed getting rate for '.$token);
				}
				
				$decode = json_decode($getFeed, true);
				
				$expField = explode('.', $rate['field']);
				$numLevels = count($expField);
				$lastField = false;
				for($i = 0; $i < $numLevels; $i++){
					if($i == 0){
						if(isset($decode[$expField[$i]])){
							$lastField = $decode[$expField[$i]];
						}
						else{
							throw new Exception('Field for '.$token.' rate not found: '.$expField[$i]);
						}
					}
					else{
						if(isset($lastField[$expField[$i]])){
							$lastField = $lastField[$expField[$i]];
						}
						else{
							throw new Exception('Field for '.$token.' rate not found: '.$expField[$i]);
						}
					}
				}
				if(!$lastField){
					throw new Exception('Field for '.$token.' rate not found: '.$rate['field']);
				}
				
				if(isset($rate['opt'])){
					switch($rate['opt']){
						case 'reverse-price':
							if($lastField > 0){
								$lastField = 1 / $lastField;
							}
							break;
					}
				}
				
				if(isset($rate['modifier'])){
					$lastField = $lastField * $rate['modifier'];
				}
				
				$rate = $lastField;
				break;
			case 'broadcast':
				$getBroadcast = $this->xcp->get_broadcasts(array('filters' => 
																	array('field' => 'source',
																		  'op' => '==',
																		  'value' => $rate['source']),
																  'limit' => 100,
																  'order_by' => 'block_index',
																  'order_dir' => 'DESC'));
				foreach($getBroadcast as $cast){
					if($cast['text'] == $rate['text'] AND $cast['status'] == 'valid'){
						$rate = $cast['value'];
						break;
					}
				}
				break;
		}
		return floatval($rate);
	}
	
	public function refund($send)
	{
		$refunds = array();
		$send_to = $send['send_to'];
		foreach($send['income']['tx'] as $tx){
			if(!isset($refunds[$tx['asset']])){
				$refunds[$tx['asset']] = 0;
			}
			$refunds[$tx['asset']] += $tx['quantity'];
		}
		
		foreach($refunds as $asset => $amount){
			$sendTX = false;
			if($asset == 'BTC'){
				
			}
			else{
				$sendData = array('source' => $this->source_address, 'destination' => $send_to,
								  'asset' => $asset, 'quantity' => $amount, 'allow_unconfirmed_inputs' => true,
								  'pubkey' => $this->source_pubkey,
								  'fee' => ($this->miner_fee * SATOSHI_MOD),
								  'regular_dust_size' => (($this->dust_size / 2) * SATOSHI_MOD),
								  'multisig_dust_size' => (($this->dust_size / 2) * SATOSHI_MOD)
								  );

				$getRaw = $this->xcp->create_send($sendData);
				$sign = $this->xcp->sign_tx(array('unsigned_tx_hex' => $getRaw));
				$sendTX = $this->xcp->broadcast_tx(array('signed_tx_hex' => $sign));
			}
			
			if($sendTX){
				$float_amount = round($amount / SATOSHI_MOD, 8);
				$saveSend = $this->insert('transactions', array('type' => 'gateway_refund',
																   'source' => $this->source_address,
																   'destination' => $send_to,
																   'amount' => $float_amount,
																   'txId' => $sendTX,
																   'confirmed' => 0,
																   'txDate' => timestamp(),
																   'asset' => $asset));
																   
				echo 'Refunded '.$float_amount.' '.$asset.' to '.$send_to.': '.$sendTX." ".timestamp()."\n";
				sleep(10);
			}	
			else{
				echo 'Refund failed.. '.$send_to."\n";
			}		
		}
	}
}
