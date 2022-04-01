<!DOCTYPE html>
<style>
* { font-family: 'Courier New', monospace; font-weight:600;}
</style>
<h2>SQLite Test</h2>
Below this line should print out currently avail SQLite db. <br>If nothing prints re-install php sqlite driver<br><br>
<?php

$ver = SQLite3::version();

echo "SQLite3 versionString:  " .$ver['versionString'] . "<br>";
echo "SQLite3 versionNumber:  " .$ver['versionNumber'] . "<br>";

#var_dump($ver);


?>