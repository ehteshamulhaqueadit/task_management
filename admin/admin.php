<?php

include("../authentication/session_check.php"); //import 
include("../db_connection.php"); //import 
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
    // User is an admin, proceed with the admin panel
} else {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            font-family: 'Arial', sans-serif;
            background: rgb(255, 255, 255);
            color: #333;
        }

        .container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .greeting-box {
            text-align: center;
            padding: 25px 50px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(12px);
            box-shadow: 0px 6px 15px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        h1.greeting {
            font-size: 2.4em;
            margin: 0 0 15px 0;
            font-weight: 600;
        }

        .buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }

        .action-button {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1em;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-weight: 500;
        }

        .action-button:hover {
            transform: translateY(-2px);
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1);
        }

        .back-button {
            background-color: #3a3d40;
            color: #ffffff;
        }

        .back-button:hover {
            background-color: #292b2c;
        }

        .users-button {
            background-color: #0056b3;
            color: #ffffff;
        }

        .users-button:hover {
            background-color: #004494;
        }

        .reports-button {
            background-color: #198754;
            color: #ffffff;
        }

        .reports-button:hover {
            background-color: #146c43;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="greeting-box">
            <h1 class="greeting">Admin Panel</h1>
            <div class="buttons">
                <a href="../home.php" class="action-button back-button">Go Back</a>
                <a href="users/users.php" class="action-button users-button">Users</a>
                <a href="reports/reports.php" class="action-button reports-button">Reports</a>
            </div>
        </div>
    </div>
</body>
</html>
