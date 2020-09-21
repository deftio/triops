<?php include("../access/triops_access.php"); ?>  <!-- include this pass protect -->

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<script src="./bitwrench.min.js"></script>

</head>
<body class="bw-def-page-setup bw-font-sans-serif">
<br><h1>Raw Received Data (Unregistered Device)</h1><br>
<a href="javascript:window.location.href=window.location.href+'?logout=1'">Logout</a><br> <!--html logout -->
<br>

<?php
echo "fetch date: " . date("h:i:sa") . "\n<br>";
?> 
<div id="timestamp"></div>
<div id="data"></div>
<script>
var serverData = "no data received";
var serverDataTimestamp = "none";

<?php

try {
	
	$redis = new Redis();
	$redis->connect("localhost");
    $x = $redis->get('devicex_raw_send');

    if ($x == null)
    {
        $x = "";
    }


    echo ("serverData =  $x ;\n");

	$x = $redis->get('devicex_raw_timestamp');

    if ($x == null)
    {
        $x = "";
    }
    echo ("serverDataTimestamp =  \"$x\"  ;\n");
}
catch (Exception $e) {
	echo "<h1>redis connect failed</h1>";
	echo "<p> " . $e->getMessage() . " </p>";
	
}

?>
bw.DOM("#timestamp","data received timestamp: " + serverDataTimestamp);
bw.DOM("#data",bw.htmlJSON(serverData));
var refresh = bw.getURLParam("refresh","none");
if (refresh != "none") {
	if (refresh > 100) {
		setTimeout(function(){ window.location.href = window.location.href; }, refresh);

	}
}
</script>
</body>

</html>
