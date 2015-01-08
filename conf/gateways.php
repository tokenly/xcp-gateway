<?php

$gateways = array(
	'BITCOINEX' => function(){
		$gateway = new Crypto_Gateway('1CoinEXLQtivDjckWMRBbhgVJk9RdwdYbR', 'BITCOINEX', array('BTC' => 1, 'XBTC' => 1));
		$gateway->allow_two_way = true;
		$gateway->auto_inflate = true;
		$gateway->inflation_mode = 'as_needed';
		$gateway->inflation_modifier = 1;
		return $gateway;
	},
);
