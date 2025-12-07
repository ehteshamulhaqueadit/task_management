<?php

$servername = "localhost";  
$username = "tm_admin";         

function db_connection(): mysqli{
$servername = "localhost";  // Server name (usually localhost for local development)
$serverusername = "tm_admin";       

$password = "tmadmin1234";            
$dbname = "task_management"; 


$conn = new mysqli(hostname: $servername, username: $serverusername, password: $password, database: $dbname);


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}else{
    return $conn;
}

}

?>