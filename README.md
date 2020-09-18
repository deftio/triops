
[![triops](./assets/triops-logo.png)](https://www.github.com/deftio/triops)

## Triops 

Triops is a *simple* web server for debugging IOT hardware with minimal setup.  


## Intro
It is written in PHP and can be used on shared hosts (though its streaming section requires access to redis).

You can[site.php](./pages/index.php)


## Supported Features

Triops contains a few "boiler plate" pages for checking connectivity.  

### Primitive Checks
These are:
* time check - any call to this page returns plain-text server time w/o any HTML formatting
* sum - any call to this page allows the client to submit get params which will be numerically summed. userful for basic connectivity formatting checks
* 


### Simple post checks
* rawpost - this allows a client/device to post *any* string data to the page.  the raw-post-read page will read the *last* posted data
* rawget  - this allows a client/device to post *any* set of vars to the page.  the raw-get-read page will read the *last* get encoded data

### device posting
The following pages allow a device to post data.  However the device must be in the registry.  To do this go to the admin page and add the device


* devpost - a page which allows a registered device to post data
* devread - a page which allows a regiersred device to read data (if avail) 
* devregister - a page which allows a device to attempt to register.  Only if it is "accepted" (see admin) will that device be able to make user of devpost or devread

## Requirements & setup

PHP 5.x or greater - used for server side logic
Redis (and PHP redis) - in-memoryy caching server
SQLite3 - file-based database


### example install (also works on WSL on Windows)
on ubuntu bash with apt:
```bash

```


## Liecense
BSD 2-clause 
(c) M A Chatterjee 2020


## Release History  
* 0.1 Initial release  
  

