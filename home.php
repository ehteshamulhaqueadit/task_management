<?php
include("authentication/session_check.php");
include("db_connection.php");
$conn = db_connection(); // Establish database connection
$user_data = get_user_existence_and_id(conn: $conn);
$user_exist = $user_data[0];
if ($user_exist === False) {
    header(header: "Location: authentication/login.php"); // Redirect to login page if user is not logged in
    exit();
} else {
    $user_id = $user_data[1]; // Get the user ID from the session
    $sql = "SELECT * FROM user WHERE user_id = '$user_id'";
    $result = $conn->query(query: $sql);
    $row = $result->fetch_assoc();
    $name = $row['name'];
    $joining_date = $row['joining_date'];

}
function delete_session($conn, $user_id): bool {
    $sql = "DELETE FROM session WHERE user_id = '$user_id'";
    if ($conn->query($sql) === TRUE) {
        return true;
    } else {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    // Check if the logout button was clicked
    if (isset($_POST['logout'])) {
        // Delete the session from the database
        if (delete_session(conn: $conn, user_id: $user_id)) {

            // Clear the session cookie
            
            $cookie_value = "";
            set_cookie(name: 'session_id', value: $cookie_value, expire_in_seconds: 86400, path: '/', domain: '', secure: False, httponly: True);

            
            header(header: "Location: authentication/login.php");
            exit();
        }
    } 
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            font-family: 'Arial', sans-serif;
            background:rgb(255, 255, 255);
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

        .dashboard-button {
            background-color: #3a3d40;
            color: #ffffff;
        }

        .dashboard-button:hover {
            background-color: #292b2c;
        }

        .personal-button {
            background-color: #0056b3;
            color: #ffffff;
        }

        .personal-button:hover {
            background-color: #004494;
        }

        .group-button {
            background-color: #198754;
            color: #ffffff;
        }

        .group-button:hover {
            background-color: #146c43;
        }
        .admin-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .admin-button {
            background-color: #dc3545;
            color: #ffffff;
        }
        .admin-button:hover {
            background-color: #c82333;
        }

        .logout-form {
            position: absolute;
            top: 20px;
            right: 20px;
        }

        .logout-button {
            padding: 10px 20px;
            background-color: #d9534f;
            color: #ffffff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease;
        }

        .logout-button:hover {
            background-color: #c9302c;
        }
    </style>
</head>
<body>
    <form method="POST" action="home.php" class="logout-form">
        <button type="submit" class="logout-button" name="logout">Logout</button>
    </form>
    <div class="container">
        <div class="greeting-box">
            <?php
            echo "<h1 class='greeting'>Hello, <span class='name'>$name</span></h1>";
            echo "<h2 class='date'> joining date : <span class='name'>$joining_date</span></h2>";
            ?>
            <div class="buttons">
            <?php if (user_type(conn: $conn, user_id: $user_id) !== "admin") { ?>
                <a href="tasks/task.php" class="action-button dashboard-button">Tasks</a>
            <?php } ?>
                <a href="user_management/personal_profile.php" class="action-button personal-button">Personal Profile</a>
            <?php if (user_type(conn: $conn, user_id: $user_id) !== "admin") { ?>
                <a href="colaboration/groups.php" class="action-button group-button">Groups</a>
            <?php } ?>
            </div>
            <?php   if (user_type(conn: $conn, user_id: $user_id) == "admin") { ?>
                <div class="admin-buttons">
                    <a href="admin/admin.php" class="action-button admin-button">Admin Panel</a>
                </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>
