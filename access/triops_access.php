<?php


# derived from :  Visit http://www.zubrag.com/scripts/ for updates
############################################################### 
#
# Usage:
# Set usernames / passwords below between SETTINGS START and SETTINGS END.
# Open it in browser with "help" parameter to get the code
# to add to all files being protected. 
#    Example: password_protect.php?help
# Include protection string which it gave you into every file that needs to be protected
#
# Add following HTML code to your page where you want to have logout link
# <a href="http://www.example.com/path/to/protected/page.php?logout=1">Logout</a>
#
###############################################################

/*
-------------------------------------------------------------------
SAMPLE if you only want to request login and password on login form.
Each row represents different user.

$LOGIN_INFORMATION = array(
  'zubrag' => 'root',
  'test' => 'testpass',
  'admin' => 'passwd'
);


--------------------------------------------------------------------
*/

$LOG_FILE = "./logfile.txt";
$cookieName = "triops_a1";

$env = array(
  'sitename' => 'triops'
);

function logVisits() {
  // log site visits, assumes write access
  $addr  = $_SERVER["REMOTE_HOST"];
  $host  = $_SERVER["REMOTE_ADDR"];
  $local = $_SERVER["SERVER_ADDR"];
  if( ($_SERVER["SERVER_ADDR"] != "127.0.0.1") && ( $_SERVER["SERVER_PORT"] == "80" )) { // don't log localhost logins on dev platforms
      $dns=gethostbyaddr($_SERVER["REMOTE_ADDR"]);
      $tm = strftime("%a %m/%d/%Y %H:%M:%S [$host,$addr,$dns]\n");
      $f=fopen($LOG_FILE ,'a');
      if( $f ) {
              fprintf($f,$tm);
              fclose($f);
      }
  }
}

logVisits();

##################################################################
#  SETTINGS START
##################################################################


// Add login/password pairs below, like described above
// NOTE: all rows except last must have comma "," at the end of line
$LOGIN_INFORMATION = array(
  'admin' => 'triops',
  'demo'  => 'device'
);

// request login? true - show login and password boxes, false - password box only
define('USE_USERNAME', true);

// User will be redirected to this page after logout
define('LOGOUT_URL', "./index.php");

// time out after NN minutes of inactivity. Set to 0 to not timeout
define('TIMEOUT_MINUTES', 0);

// This parameter is only useful when TIMEOUT_MINUTES is not zero
// true - timeout time from last activity, false - timeout time from login
define('TIMEOUT_CHECK_ACTIVITY', true);

##################################################################
#  SETTINGS END
##################################################################


///////////////////////////////////////////////////////
// do not change code below
///////////////////////////////////////////////////////

// show usage example
if(isset($_GET['help'])) {
   die('Include following code into every page you would like to protect, at the very beginning (first line):<br>&lt;?php include("' . str_replace('\\','\\\\',__FILE__) . '"); ?&gt;');
}

// timeout in seconds
$timeout = (TIMEOUT_MINUTES == 0 ? 0 : time() + TIMEOUT_MINUTES * 60);

// logout?
if(isset($_GET['logout'])) {
  setcookie($cookieName, '', time() - 3600, '/');


  //setcookie($cookieName, '', $timeout, '/'); // clear password;
  header('Location: ' . LOGOUT_URL);
  exit();
}

if(!function_exists('showLoginPasswordProtect')) {

// show login form
function showLoginPasswordProtect($error_msg) {
?>

<html>
<head>
<title><?php echo $env->sitename; ?></title>
<meta HTTP-EQUIV="CACHE-CONTROL" CONTENT="NO-CACHE">
<meta HTTP-EQUIV="PRAGMA" CONTENT="NO-CACHE">
<link rel="icon" type="image/x-icon" href="../assets/favicon.ico" />
<style>
.triops-page-setup {height:100%;width:90%;margin:0 auto;padding-left:2%;padding-right:2%;left:0;top:1%;box-sizing:border-box; font-size: 14pt;}
.triops-font-serif     {font-family:Times New Roman, Times, serif;}
.triops-font-sans-serif{font-family:Arial, Helvetica, sans-serif;}
</style>
</head>

<body class='triops-page-setup triops-font-sans-serif'>
  <style>
    input { border: 1px solid black; }
  </style>
  <div id='logo' style='left:50px;padding-top:7px;'><a href='./index.php'><img src='../assets/triops-logo.png' style='height:70px;width:270px'></img></a><br /><br /></div>
  
  <form method="post">
    <h3>Enter credentials for access</h3>
    <font color="red"><?php echo $error_msg; ?></font><br />
<?php if (USE_USERNAME) echo 'Login:<br /><input type="input" name="access_login" /><br /><br />Password:<br />'; ?>
    <input type="password" name="access_password" /><p></p>
    <input type="submit" name="Submit" value="Submit" />
  </form>
  <br />
  
  </div>
</body>
</html>


<?php
  // stop at this point
  die();
}
}



// user provided password
if (isset($_POST['access_password'])) {

  $login = isset($_POST['access_login']) ? $_POST['access_login'] : '';
  $pass = $_POST['access_password'];
  if (!USE_USERNAME && !in_array($pass, $LOGIN_INFORMATION)
  || (USE_USERNAME && ( !array_key_exists($login, $LOGIN_INFORMATION) || $LOGIN_INFORMATION[$login] != $pass ) ) 
  ) {
    showLoginPasswordProtect("Incorrect password.");
  }
  else {
    // set cookie if password was validated
    setcookie($cookieName, md5($login.'%'.$pass), $timeout, '/');
    
    unset($_POST['access_login']);
    unset($_POST['access_password']);
    unset($_POST['Submit']);
  }

}
//==============
//==============
else {

  // check if password cookie is set
  if (!isset($_COOKIE[$cookieName])) {
    showLoginPasswordProtect("");
  }

  // check if cookie is good
  $found = false;
  foreach($LOGIN_INFORMATION as $key=>$val) {
    $lp = (USE_USERNAME ? $key : '') .'%'.$val;
    if ($_COOKIE[$cookieName] == md5($lp)) {
      $found = true;
      // prolong timeout
      if (TIMEOUT_CHECK_ACTIVITY) {
        setcookie($cookieName, md5($lp), $timeout, '/');

      }
      break;
    }
  }
  if (!$found) {
    showLoginPasswordProtect("");
  }

}

?>
