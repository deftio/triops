<?php

    $deviceid = $_GET["device_id"];

    $res = array ();
    $f = ""; //file contents

    // get result from redis


    // file based retrieve if redis not avail

    if (isset($deviceid)){
        // attempt to get that device data
        $f = file_get_contents("./data/" .$deviceid . "_kdata.json");
        
    }
    else {
        //get last raw dump file
        $f = file_get_contents("./data/lastpacket.json");
        
    }
    if (! $f)
            $f = array();
    $res["data"] = $f;
    $res["packet_fetch_time"] = date("h:i:sa") ;
    echo json_encode($res);
?>