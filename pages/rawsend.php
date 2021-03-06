Submit data to this page via either get_encoded vars or http:post.
<?php
	$rawfile = "./data/rawfile.dump";

	#triops redis config (move to config file)
	$triopsConfig = array (
		"unregdev-maxlen" => 512,
		"regdev-maxlen"   => 512
	);

	$v =  array(
	  	"raw_query" => "",
	  	"raw_get" => "",
	  	"raw_post_unparsed" => "",
	  	"raw_post_json_parsed" => ""
	  	);
	  
	  
	if (isset($_SERVER['QUERY_STRING']))
		$v["raw_query"] = $_SERVER['QUERY_STRING'];
	  
	parse_str( parse_url( $v["raw_query"], PHP_URL_QUERY), $x );
	$v["raw_get"] = $x;


	$v["raw_post_unparsed"]  = file_get_contents("php://input");

	$v["raw_post_json_parsed"] =  json_decode($v["raw_post_unparsed"]); 
	if ($v["raw_post_json_parsed"] == null)
		$v["raw_post_json_parsed"] = "";


	try {
	
		$redis = new Redis();
		$redis->connect("localhost");
		
		#special single entry for last 
		$dd =  date("h:i:sa") ;
		$v["server_rec_timestamp"] = $dd;
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
		$f=fopen($rawfile ,'w+');	
		if( $f ) {
			      fwrite($f, json_encode($v));
		      fclose($f);

		      echo ("OK");
		}
		else {
			echo ("NR");
		};
	}
	catch (Exception $e){
		echo ("NFW"); // no file write
	}
	echo ("\n");
?>
