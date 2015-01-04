#Counterparty Gateway Prototype

This is a PHP based automated "vending machine" or "gateway" prototype/proof of concept for the Counterparty (XCP) protocol.

A Counterparty "Vending Machine" is a program which accepts one type of asset (BTC, XCP or other user created assets) and spits
out another asset using a defined exchange rate. For example, sending 0.01 BTC and receiving back 1000 LTBcoin. 

This prototype is intended for command line use only, there is no GUI or web interface at this moment.

##Features

* Send and receive both bitcoin and counterparty assets based on defined exchange rates
* Accept multiple tokens at different exchange rates
* Ability to automatically issue new tokens when the local supply runs out (requires source address to have ownership of the token in question)
* Customizable fees and other options

##Requirements

* PHP 5.4
* MySQL
* Python 3.4
* [Bitcoind](https://github.com/bitcoin/bitcoin)
* [Counterpartyd](https://github.com/CounterpartyXCP/counterpartyd)
* Your favorite flavour of linux!

##Installation

* Make sure you have an instance of both Bitcoind and Counterpartyd fully operational
* Create a new MySQL database and import "db.sql"
* Edit conf/config.php and fill out all the appropriate information

To setup your vending machine, you will first want to edit "crypto-gateway.php" (this process will be improved later)

Specifically, you will want to edit this section:

```
	$gateway = new Crypto_Gateway('1CoinEXLQtivDjckWMRBbhgVJk9RdwdYbR', 'BITCOINEX', array('BTC' => 1, 'XBTC' => 1));
	$gateway->allow_two_way = true;
	$gateway->auto_inflate = true;
	$gateway->inflation_mode = 'as_needed';
	$gateway->inflation_modifier = 1;
```

Let's break this down line by line.

```
	$gateway = new Crypto_Gateway('1CoinEXLQtivDjckWMRBbhgVJk9RdwdYbR', 'BITCOINEX', array('BTC' => 1, 'XBTC' => 1));
````

The first argument in this function is your **source address**, which is the address that outgoing transactions will be coming from. If you want it to automatically issue new tokens for you, you will need to make sure that the source address also has ownership rights to your desired token.

The second argument is the name of the primary token used by the vending machine, and can be either BTC, XCP or any Counterparty token. This is the main token that will be sent out after incoming transactions are confirmed.

The third argument is an array of which tokens are accepted and what the exchange rate is for them. In this default example, both regular BTC as well as the token "XBTC" are accepted for incoming transactions, with both of them having an equal 1:1 exchange rate. If ```'BTC' => 1 ``` was changed to ```'BTC' => 1000```, then sending 1 Bitcoin would cause 1,000 BITCOINEX to be vended out.

If you would like to have different addresses for receiving and sending transactions, you may add in the following line of code:  
``` $gateway->watch_address = 'MY_WATCH_ADDRESS'; ```

...

```
$gateway->allow_two_way = true;
```

If set to true, the vending machine will allow the **primary token** to be sent back and exchanged back to one of the accepted tokens. The first item in the array of accepted tokens is what will be sent back (in this example, BTC). Note that the service fee (if any defined) is only applied one way (e.g a fee is taken when vending BTC -> BITCOINEX, but no fee is taken with BITCOINEX -> BTC)

```
	$gateway->auto_inflate = true;
	$gateway->inflation_mode = 'as_needed';
	$gateway->inflation_modifier = 1;
```

This section defines the auto issuance behavior. If you do not want the vending machine to attempt to issue new tokens when its supply runs out, just set "auto_inflate" to false. Auto issuance is triggered when there is not enough tokens left in the source address to satisfy a new vending transaction. 

There are three inflation modes available (which also change what "inflation_modifier" does):

* "as_needed" - this mode creates only as many tokens as needed for whatever pending outgoing transactions, multiplied by the inflation_modifier. e.g 1,000 new tokens are needed, so only 1,000 new tokens are created.
* "fixed" - this creates a fixed amount of new tokens. If inflation_modifier was set to 50,000, it would simply create 50,000 new tokens
* "percent" - this increases the total existing token supply by X percent (inflation_modifier). e.g if inflation_modifier is set to 0.10 (10%) and there is already 100,000 tokens out in the wild, then an additional 10,000 would be issued.

**Other Options**

Note that some of these options are not actually used yet (will fix soon)

```
	$gateway->min_btc = 0.000055; //BTC dust limit, used mostly for if source token is just BTC
	$gateway->min_confirms = 1; //Min number of confirms before vending out token
	$gateway->miner_fee = 0.0001; //BTC miners fee
	$gateway->dust_size = 0.000055; //size to use for each dust output
	$gateway->service_fee = 0.5; //% fee to take off any incoming tokens

```


##Usage

To start up the gateway/vending machine:

```
php crypto-gateway.php > log &
```
``` > log &``` simply saves the output to a log file and runs the process in the background.

To view some basic stats on incoming/outgoing payments for each token used in the vending machine, run:

```
php crypto-gateway.php stats
```

If you want to send a transaction to your source/watch address (such as adding funds to pay for bitcoin fees, xcp for issuance fees, sending initial token supply etc.), but don't want to accidently trigger the gateway, you may add specific transactions to the "ignore list" by running the following command:

```
php crypto-gateway.php ignore <transaction ID>
```

**Running Multiple Gateways**

As this is mostly a proof of concept still, it hasn't had much testing or been fleshed out much. However, you should be able to create copies of "crypto-gateway.php" and change parameters etc. as needed and run them all simultaneously. They can all use the same database. 

### To do

* Improve setup
* Make it easier to run multiple gateways
* Finish support for some of the extra options such as custom dust size and miner fee, min confirms etc.
* Add in support for exchange rates based on price feeds
* Support for refunds / rejecting transactions
* +more!
