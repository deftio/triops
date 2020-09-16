<?php


 $dataObj = "";

if (version_compare(phpversion(), '5.3.10', '<')) {
	$dateObj = DateTime::createFromFormat('0.u00 U', microtime());
	$dateObj->setTimeZone(new DateTimeZone('UTC'));
    // php version 5 

}
else {
	$dateObj = DateTime::createFromFormat('U.u', microtime(TRUE));
	$dateObj->setTimeZone(new DateTimeZone('UTC'));

}


echo($dateObj->format('Y-m-d H:i:s:u'));

?>