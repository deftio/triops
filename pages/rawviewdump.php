<?php #include("../access/triops_access.php"); ?>  <!-- include this pass protect -->

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<script src="./bitwrench.min.js"></script>

</head>
<body class="bw-def-page-setup bw-font-sans-serif">
<br><h1>Raw Received Data (Unregistered Device)</h1><br>
<a id='refresh'>Auto Refresh</a>
<a href="javascript:window.location.href=window.location.href+'?logout=1'">Logout</a><br> <!--html logout -->
<br>

<?php
echo "fetch date: <span id='fetch'>" . date("h:i:sa") . "\n</span><br>";
?> 
<div id="timestamp"></div>
<div id="data"></div>
<script>
var serverData = "no data received";
var serverDataTimestamp = "none";
var hrefrefresh = (function(){var u = bw.URLParamParse(window.location.href); u["refresh"] = 250; return window.location.href + bw.URLParamPack(u,true)})()
bw.DOM("#refresh")[0]["href"] = hrefrefresh;
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
	if (refresh > 50) {
		setInterval( getRawFile , refresh);
	}
}
function getRawFile () {
fetch('./data/rawfile.dump')
  .then(res => res.blob()) // Gets the response and returns it as a blob
  .then(blob => {
    blob.text().then(
        t => {
            d = JSON.parse(t);
            bw.DOM("#fetch",bw.DOM("#fetch",new Date().toTimeString()));
            bw.DOM("#data",bw.htmlJSON(JSON.parse(d["raw_post_unparsed"])));
            bw.DOM("#timestamp","data received timestamp: " + d["server_rec_timestamp"]);
        }

        )
  })
}
getRawFile();
</script>
</body>

</html>
