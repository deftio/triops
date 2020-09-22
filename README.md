
[![triops](./assets/triops-logo.png)](https://www.github.com/deftio/triops)

## Triops 

Triops is a *simple* web server for debugging IOT hardware with minimal setup.  


## Intro
It is written in PHP and can be used on shared hosts (though its streaming section requires access to redis).

You can see this [site.php](./pages/index.php)


## Supported Features

Triops contains a few "boiler plate" pages for checking connectivity.  

### Primitive Checks
Primitive checks allow a client to use http to get text (non HTML) data which can consoled out (eg at REPL or serial debug).

These are:
* time check - any call to this page returns plain-text server time w/o any HTML formatting
* sum - any call to this page allows the client to submit get params which will be numerically summed. userful for basic connectivity formatting checks
* ip - see client reported ip at server


### Simple post checks
* rawpost - this allows a client/device to post *any* string data to the page.  the raw-post-read page will read the *last* posted data
* rawget  - this allows a client/device to post *any* set of vars to the page.  the raw-get-read page will read the *last* get encoded data

### device posting
The following pages allow a device to post data.  However the device must be in the registry.  To do this go to the admin page and add the device


* devpost - a page which allows a registered device to post data
* devread - a page which allows a regiersred device to read data (if avail) 
* devregister - a page which allows a device to attempt to register.  Only if it is "accepted" (see admin) will that device be able to make user of devpost or devread

## Requirements & setup
Assunes apache2 or LAMP/WAMP installed.

PHP 5.x or greater - used for server side logic
Redis (and PHP redis) - in-memoryy caching server
SQLite3 (PHP extension) - file-based database


### example install (also works on WSL on Windows)
on ubuntu bash with apt:
```bash

sudo apt install redis-server
sudo apt install php php-curl php-redis 
sudo systemctl restart redis.service

```

If you're having trouble use redis-cli to trouble shoot that redis is up and running:
```bash
redis-cli
127.0.0.1:6379> ping
PONG  #  server should respond with this:
```

## Liecense
BSD 2-clause 
(c) M A Chatterjee 2020


## Release History  
* 0.1 Initial release  
  

