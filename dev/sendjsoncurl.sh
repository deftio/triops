# simple curl command to test sending data
if [ -z "$1" ]
  then
    echo 'sendjson to server:'
    echo $'./sendjsoncurl.sh http://localhost/triops/pages/rawsend.php \'{"foo":123}\'';
else

curl --header "Content-Type: application/json" \
  --request POST \
  --data $2 \
  $1

fi

