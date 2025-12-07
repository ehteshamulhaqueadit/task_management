<?php

include("../authentication/session_check.php");
include("../db_connection.php");
$conn = db_connection(); // Establish database connection

$user_data = get_user_existence_and_id(conn: $conn);
if ($user_data[0]) {
    $user_id = $user_data[1];
} else {
    header(header: "Location: ../authentication/login.php");
    exit();
}
$current_user_id = $user_id;
$current_user_role = "non-member";
$group_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

?>


<?php


// Check if the user is an admin
if (user_type(conn: $conn, user_id: $user_id) == "admin") {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}

?>



<?php




// Fetch leader info
$stmt_leader = $conn->prepare("
    SELECT u.user_id, u.name
    FROM groups g
    JOIN created_group cg ON g.group_id = cg.group_id
    JOIN member m ON cg.membership_id = m.membership_id
    JOIN user u ON cg.user_id = u.user_id
    WHERE g.group_id = ?
    AND m.type = 'leader';
");
if ($stmt_leader) {
    $stmt_leader->bind_param("i", $group_id);
    $stmt_leader->execute();
    $result_leader = $stmt_leader->get_result();
    if ($result_leader->num_rows > 0) {
        $leader_info = $result_leader->fetch_assoc();
    }
    $stmt_leader->close();
    // No need to handle case where leader is not found, as it should always exist if group exists
} else {
     error_log("Error preparing leader info statement: " . $conn->error);
     // Continue without leader info, maybe show 'Unknown'
}
if ($leader_info['user_id'] == $current_user_id) {
    $current_user_role = 'leader'; // User is the leader of this group
} else {
    // Check if user is a general member
    $stmt_check_member = $conn->prepare("SELECT membership_id FROM joined_group WHERE user_id = ? AND group_id = ?");
    if ($stmt_check_member) {
        $stmt_check_member->bind_param("ii", $current_user_id, $group_id);
        $stmt_check_member->execute();
        $res_check_member = $stmt_check_member->get_result();
        if ($res_check_member->num_rows > 0) {
            $current_user_role = 'general'; // User is a general member
        }
        $stmt_check_member->close();
    } else {
         error_log("Error preparing check member statement: " . $conn->error);
    }
}

if ($current_user_role == 'non-member' || $current_user_role == 'general') {
   echo " You are not authorized to edit this group.";
    exit();
}

?>


<?php
$group_name = '';
$description = '';
$success_message = '';
$error_message = '';

// Fetch existing group info
$stmt_group_info = $conn->prepare("SELECT name, description FROM groups WHERE group_id = ?");
if ($stmt_group_info) {
    $stmt_group_info->bind_param("i", $group_id);
    $stmt_group_info->execute();
    $stmt_group_info->bind_result($group_name, $description);
    $stmt_group_info->fetch();
    $stmt_group_info->close();
}

// Handle update form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_name = trim($_POST['group_name'] ?? '');
    $new_desc = trim($_POST['description'] ?? '');

    if (empty($new_name)) {
        $error_message = "Group name is required.";
    } elseif (strlen($new_name) > 30) {
        $error_message = "Group name must be 30 characters or fewer.";
    } elseif (strlen($new_desc) > 200) {
        $error_message = "Description must be 200 characters or fewer.";
    } else {
        $stmt_update = $conn->prepare("UPDATE groups SET name = ?, description = ? WHERE group_id = ?");
        if ($stmt_update) {
            $stmt_update->bind_param("ssi", $new_name, $new_desc, $group_id);
            if ($stmt_update->execute()) {
                $success_message = "Group information updated successfully.";
                $group_name = $new_name;
                $description = $new_desc;
            } else {
                $error_message = "Failed to update group. Please try again.";
            }
            $stmt_update->close();
        } else {
            $error_message = "Database error: could not prepare statement.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Group Info</title>
    <style>
        :root {
            --primary: #2980b9;
            --primary-dark: #1c5980;
            --success: #2ecc71;
            --error: #e74c3c;
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #2c3e50;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text);
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: auto;
            background: var(--card);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        }

        h1 {
            text-align: center;
            margin-bottom: 25px;
            font-size: 1.6rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
        }

        input[type="text"],
        textarea {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 1rem;
            transition: border 0.3s;
        }

        input[type="text"]:focus,
        textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(41, 128, 185, 0.2);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .btn {
            padding: 12px 20px;
            background: var(--primary);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .btn:hover {
            background: var(--primary-dark);
        }

        .notification {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.95em;
            text-align: center;
        }

        .success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid rgba(46, 204, 113, 0.4);
            color: var(--success);
        }

        .error {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: var(--error);
        }

        .form-footer {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <button onclick="window.location.href='group_details.php?id=<?php echo $group_id; ?>';" class="btn" style="background: #6c757d;">Back</button>
            <h1 style="margin: 0 auto;">Update Group Info</h1>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="notification success" id="notification"><?= htmlspecialchars($success_message) ?></div>
        <?php elseif (!empty($error_message)): ?>
            <div class="notification error" id="notification"><?= htmlspecialchars($error_message) ?></div>
        <?php else: ?>
            <div class="notification" id="notification" style="display:none;"></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="group_name">Group Name *</label>
                <input type="text" id="group_name" name="group_name" maxlength="30" required
                       value="<?= htmlspecialchars($group_name) ?>">
            </div>

            <div class="form-group">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" maxlength="200"><?= htmlspecialchars($description) ?></textarea>
            </div>

            <div class="form-footer">
                <button type="submit" class="btn">Update Group</button>
            </div>
        </form>
    </div>

    <script>
        // Automatically hide notification after 3 seconds
        const notification = document.getElementById('notification');
        if (notification && notification.innerText.trim() !== "") {
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }
    </script>
</body>

</html>
