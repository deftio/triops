<?php include("../access/triops_access.php"); ?>  <!-- include this pass protect -->

<?php 
	//url-ify the data for the POST
	

	//open connection
	$ch = curl_init();
	
	$actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

	$url = dirname($actual_link) . "/rawsend.php";

	//echo ($url);
	//set the url, number of POST vars, POST data
	curl_setopt($ch,CURLOPT_URL, $url);
	
	
	$postvars = array("rawpost" => file_get_contents("php://input"));

	curl_setopt($ch,CURLOPT_POST, count($postvars));
	curl_setopt($ch,CURLOPT_POSTFIELDS,urldecode(ltrim($postvars["rawpost"],"rawpost_data=")));
 	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	//execute post
	$result = curl_exec($ch);

	//close connection
	curl_close($ch);

	
 ?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<script src="./bitwrench.min.js"></script>

</head>
<body class="bw-def-page-setup bw-font-sans-serif">
<br><h1>Test Raw Data Send</h1><br>
<a href="javascript:window.location.href=window.location.href+'?logout=1'">Logout</a><br><br> <!--html logout -->
Use this page to peform a raw (unregistered device) send by filling out text below.<br>
To see the data go to <a =href"./rawview.php">rawview</a>.<br>
JSON is permitted (use double quotes for strings)
<br>


<form action="./testrawsend.php" method="post">
  <label for="data">Write Sample POST data here</label><br>
  <textarea rows="40" style="width:80%;" name="rawpost_data" placeholder='{"deviceid":"aa234923d2d", "sensor_value" : 2343.32}'></textarea><br><br>
  <button onclick="submit">submit</button>
</form>

</body>
