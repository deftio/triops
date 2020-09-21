<?php include("../access/triops_access.php"); ?>  <!-- include this pass protect -->

<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<script src="./bitwrench.min.js"></script>

</head>
<body class="bw-def-page-setup bw-font-sans-serif">
<br><h1>Test Raw Data Send</h1><br>
<a href="javascript:window.location.href=window.location.href+'?logout=1'">Logout</a><br> <!--html logout -->
Use this page to peform a raw (unregistered device) send by filling out any of the form elements below.<br>
To see the data go to <a =href"./rawview.php">rawview</a>.<br>
<br>

<form action="/action_page.php" method="post">
  <label for="data">put sample get data here:</label>
  <input type="text"  name="getdata"><br><br>
  <label for="data">put sample post data here:</label>
  <input type="text"  name="getdata"><br><br>
  <input type="submit" value="Submit">
</form>

</body>
