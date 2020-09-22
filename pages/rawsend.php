Submit data to this page via either get encoded vars or http:post.
<?php
	$rawfile = "./data/rawfile.dump";

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
		$redis->set('devicex_raw_send',json_encode($v));
		$redis->set('devicex_raw_timestamp',  date("h:i:sa"));
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
