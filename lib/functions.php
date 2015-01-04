<?php
//include some libraries
include(FRAMEWORK_PATH.'/Model.php');
include(FRAMEWORK_PATH.'/BitcoinRPC.php');
include(FRAMEWORK_PATH.'/BTCValidate.php');
include(FRAMEWORK_PATH.'/Gateway.php');

function convertFloat($float)
{
	if($float === 0){
		return 0;
	}
	$str = rtrim(sprintf('%.8F', $float), '0');
	$checkLast = substr($str, -1);
	if($checkLast == '.'){
		$str = str_replace('.', '', $str);
	}
	return $str;
}


function debug($var)
{
	echo '<pre>';
	print_r($var);
	echo '</pre>';
}

function timestamp()
{
	return date('Y-m-d H:i:s');
}


?>
