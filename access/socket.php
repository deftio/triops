<?php
/*
simple socket server.

invoke at commandline:
php socket.php

then telent in to get echo backs.  supports multiple clients (up to max clients)

*/

error_reporting(~E_NOTICE);
set_time_limit (0);
 
$address = "0.0.0.0";
$port = 5003;
$max_clients = 35;

#socket timeouts
$timeoutsec = 20;
$timeoutusec = 100;

if(!($sock = socket_create(AF_INET, SOCK_STREAM, 0)))
{
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>$timeoutsec, 'usec'=>$timeoutusec));
    socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, array('sec'=>$timeoutsec, 'usec'=>$timeoutusec));

    die("Couldn't create socket: [$errorcode] $errormsg \n");
}
 
echo "Socket created ip: " . $adresss . " port: " . $port . "\n";
 
// Bind the source address
if( !socket_bind($sock, $address , $port) )
{
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
 
    die("Could not bind socket : [$errorcode] $errormsg \n");
}
 
echo "Socket bind OK \n";
 
if(!socket_listen ($sock , 10))
{
    $errorcode = socket_last_error();
    $errormsg = socket_strerror($errorcode);
 
    die("Could not listen on socket : [$errorcode] $errormsg \n");
}
 
echo "Socket listen OK \n";
 
echo "Waiting for incoming connections... \n";
 
//array of client sockets
$client_socks = array();
 
//array of sockets to read
$read = array();
 
//start loop to listen for incoming connections and process existing connections
while (true)
{
    //prepare array of readable client sockets
    $read = array();
 
    //first socket is the master socket
    $read[0] = $sock;
 
    //now add the existing client sockets
    for ($i = 0; $i < $max_clients; $i++)
    {
        if($client_socks[$i] != null)
        {
            $read[$i+1] = $client_socks[$i];
        }
    }
 
    //now call select - blocking call
    if(socket_select($read , $write , $except , null) === false)
    {
        $errorcode = socket_last_error();
        $errormsg = socket_strerror($errorcode);
 
        die("Could not listen on socket : [$errorcode] $errormsg \n");
    }
 
    //if ready contains the master socket, then a new connection has come in
    if (in_array($sock, $read))
    {
        for ($i = 0; $i < $max_clients; $i++)
        {
            if ($client_socks[$i] == null)
            {
                $client_socks[$i] = socket_accept($sock);
 
                //display information about the client who is connected
                if(socket_getpeername($client_socks[$i], $address, $port))
                {
                    echo "Client $address : $port is now connected.\n";
                }
 
                //Send Welcome message to client
                $message = "Welcome to socket server version 0.1 \n";
                $message .= "Enter a message and press enter, for echo back\n";
                socket_write($client_socks[$i] , $message);
                break;
            }
        }
    }
 
    //check each client if they send any data
    for ($i = 0; $i < $max_clients; $i++)
    {
        if (in_array($client_socks[$i] , $read))
        {
            $input = socket_read($client_socks[$i] , 4096);
            $n = trim($input);

            if (($n == null) || ($n == 'quit') || ($n == "exit") || ($n == '`'))
            {
                //zero length string meaning disconnected, remove and close the socket
                socket_close($client_socks[$i]);
                unset($client_socks[$i]);
                continue;
            }
 
            $n = trim($input);
 
            $output = $client_socks[$i]." Client $i Said: ... $n \n"; #debug only
 
            //echo "Sending output to client \n";
 
            //send response to client
            socket_write($client_socks[$i] , $output );

 
            // send response to other client
            //foreach (array_diff_key($client_socks, array($i => 0)) as $client_sock) {
                // socket_write($client_sock , $output);
            //}
        }
    }
}