<?php

include("../../authentication/session_check.php");
include("../../db_connection.php");
$conn = db_connection(); // Establish database connection

$user_data = get_user_existence_and_id(conn: $conn);
if ($user_data[0]) {
    $user_id = $user_data[1];
} else {
    header(header: "Location: ../authentication/login.php");
    exit();
}

// Check if the user is an admin
if (user_type(conn: $conn, user_id: $user_id) == "admin") {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}

?>






