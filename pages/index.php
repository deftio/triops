<? php
 define('level_one_access', TRUE);
?>
<!DOCTYPE html>
<html>
<head>
<script src="./bitwrench.min.js" type="text/javascript" ></script>
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico" />
<title>triops</title>

</head>

<body class="bw-def-page-setup bw-font-sans-serif">

<h1>Kinisi Test Pages</h1>
<br>

<h2>Simple Checks</h2>
These pages provide quick debuging access (e.g. hello world type connectivity) to check basic connection issues.  Use sum.php and timestamp.php to make sure your application isn't getting stale data on basic tasks.
<ul>
	<li><a href="./timestamp.php">timestamp.php</a>&nbsp;&nbsp;Returns server timestamp in microseconds (plain text).  Example: 2020-09-16 07:11:49:117700</li>
	<li><a href="./sum.php">sum.php</a>&nbsp;&nbsp;Sums up numbers of any get variabls. Example: sum.php?a=1&b=2 returns 3.</li>
	<li><a href="./ip.php">ip.php</a>&nbsp;&nbsp;Returns client public IP address. Example: ip.php returns 134.22.342.12 (Note this doesn't account for proxies/NAT etc</li>		
</ul>
<h2>Device Data Checks</h2>
These checks show posted data the server received.  The server must have redis and php-redis installed.<br>
<ul>
	<li><a href="./testrawsend.php">Use a webform to send data to the server as if it were a device.</a>&nbsp;&nbsp;</li>
	<li><a href="./rawsend.php">post/get data (unregistered)</a>&nbsp;&nbsp;</li>
	<li><a href="./rawview.php">see last (unregistered) device data send [includes GET and POST]</a>&nbsp;&nbsp;</li>
</ul>
</body>
</html>
