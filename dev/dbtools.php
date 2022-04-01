<?php
//https://phpdelusions.net/pdo

$host = '127.0.0.1';
$db   = 'test';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset"; // MySQL

$dsn = "sqlite:./db_file.sqlite3"; // sqlite
/*
// apache group writable config settings may be necessary:
chown -R www-data:www-data /var/databases/myapp/
chmod -R u+w /var/databases/myapp/
*/

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
     throw new PDOException($e->getMessage(), (int)$e->getCode());
}


$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND status=:status');
$stmt->execute(['email' => $email, 'status' => $status]);
$user = $stmt->fetch();



//limit and offset examples
$stmt = $pdo->prepare("SELECT * FROM users LIMIT :limit, :offset");
$stmt->execute(['limit' => $limit, 'offset' => $offset]); 
$data = $stmt->fetchAll();

// and somewhere later:
foreach ($data as $row) {
    echo $row['name']."<br />\n";
}

//password auth
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$_POST['email']]);
$user = $stmt->fetch();

if ($user && password_verify($_POST['pass'], $user['pass']))
{
    echo "valid!";
} else {
    echo "invalid";
}

?>