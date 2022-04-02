Submit data to this page via either get_encoded vars or http:post.
<?php
        $lastpacket = "./data/lastpacket.json";

        #triops redis config (move to config file)
        $triopsConfig = array (
                "unregdev-maxlen" => 512,
                "regdev-maxlen"   => 512
        );

        $v =  array(
               // "raw_query" => "",
               // "raw_get" => "",
                "raw_post_unparsed" => "",
                "raw_post_json_parsed" => ""
                );


        //if (isset($_SERVER['QUERY_STRING']))
        //        $v["raw_query"] = $_SERVER['QUERY_STRING'];

        //parse_str( parse_url( $v["raw_query"], PHP_URL_QUERY), $x );
        //$v["raw_get"] = $x;


        $v["raw_post_unparsed"]  = file_get_contents("php://input");

        try {

                $redis = new Redis();
                $redis->connect("localhost");

                #special single entry for last
                $dd =  date("h:i:sa") ;
                $v["server_rec_timestamp"] = $dd;

                # now write redis
                $vstring = json_encode($v);
                $redis->set('devicex_raw_send',$vstring);
                $redis->set('devicex_raw_timestamp', $dd);

                #store last N in this special list in redis
                unset($v["raw_post_json_parsed"]);
                $vstring = json_encode($v);

                $redis->rpush("unregdev",$vstring);
                if ($redis->llen("unregdev") >  $triopsConfig["unregdev-maxlen"] )
                        $redis->lpop("unregdev");

                #for registered devices handle here
                /*
                if ($v["device"] in database = && $v["pw"] in database) {
                        //store in redis
                        $redis->rpush("unregdev",$vstring);
                        if ($redis->llen($v["device"]) >  $triopsRedisConfig["regdev-maxlen"] )
                                $redis->lpop("unregdev");

                        if ($triopsConfig["logDeviceData"]) { // store in actual database
                                //openDB()
                                //store( deviceid, data)

                        }

                }

                */

        }
        catch (Exception $e) {
                echo "<h1>redis connect failed</h1>";
                echo "<p> " . $e->getMessage() . " </p>";

        }


        try {
                $f=fopen($lastpacket ,'w+');
                if( $f ) {
                    fwrite($f, json_encode($v));  // includes server time-stamp and unparsed packet
                    fclose($f);
                    echo ("\nOK - lastpacket.dump\n");
                }
                else {
                    echo ("\nlastpacket.dump not written\n");
                };
        }
        catch (Exception $e){
                echo ("NFW : rawpost.dump"); // no file write
        }

    try { // packet only but save as device sernum

        $vx =  json_decode($v["raw_post_unparsed"],true);
        if ($vx != null)
        {
                $fname = "./data/" . $vx["meta"]["device_id"] . "_kdata.json";
                #$fname = "./data/_kdata.json";
        
                $f=fopen($fname ,'w+');
                if( $f ) {
                    fwrite($f, json_encode($v));  // includes server time-stamp and unparsed packet
                    //fwrite($f, json_encode($v["raw_post_unparsed"]));
                    fclose($f);

                    echo ("OK:" . $fname . "\n");
                }
                else {
                     echo ("NR");
                };
        }
        else {
            $f=fopen("./debug.log","w+");
            fwrite($f,$v["raw_post_unparsed"]);
            fclose($f);
        }

    }
    catch (Exception $e){
                echo ("No file written:  device_sernum-raw-json"); // no file write
        }


    echo ("\n");
?>