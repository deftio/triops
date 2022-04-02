<?php //include("../access/triops_access.php"); ?>  <!-- include this pass protect -->

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<script src="../assets/bitwrench.min.js"></script>

</head>
<body class="bw-def-page-setup bw-font-sans-serif">
<br><h1>Kinisi Raw Received Data</h1><br>
<a id='refresh'>Auto Refresh</a>
<a href="javascript:window.location.href=window.location.href+'?logout=1'">Logout</a><br> <!--html logout -->
<br>
<div>Fetch           Time: <span id="fetchtime"></span></div>
<div>Packet Received Time: <span id="packet_rec_time"></span></div>
<div id="data"></div>
<script>
var serverData = "no data received";
var serverDataTimestamp = "none";
var hrefrefresh = (function(){var u = bw.URLParamParse(window.location.href); u["refresh"] = 250; return window.location.href + bw.URLParamPack(u,true)})()
bw.DOM("#refresh")[0]["href"] = hrefrefresh;


var refresh = bw.getURLParam("refresh","none");
if (refresh != "none") {
	if (refresh > 50) {
		setInterval( getData , refresh);
	}
}

function getData () {
    fetch("./sleevestreamapi.php?device_id=" + device_id)
    .then(res => res.blob()) // Gets the response and returns it as a blob
    .then(blob => {
        blob.text().then(
            t => {
                if (t.length > 20) {
                bw.DOM("#fetchtime",new Date().toTimeString());
                d = JSON.parse(t);
                lastPacket = d["data"];
                data = JSON.parse(d["data"]);
                //bw.DOM("#data",bw.htmlJSON(JSON.parse(d["raw_post_unparsed"])));
                bw.DOM("#packet_rec_time", data["server_rec_timestamp"]);
                packet = JSON.parse(data["raw_post_unparsed"]);
                bw.DOM("#data",bw.htmlJSON(packet));
                }
            }

            )
    })
}
const  device_id = bw.getURLParam("device","");
var lastPacket;
getData(device_id);
</script>
</body>

</script>
</body>

</html>
