<?php
	
	$sum = 0;
	foreach ($_GET as $key => $value) { 
		$sum += (int)($value);
	}
	echo $sum;
?>