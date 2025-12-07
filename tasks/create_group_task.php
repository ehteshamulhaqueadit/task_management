



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

if ($current_user_role == 'non-member') {
   echo " You are not authorized to edit this group.";
    exit();
}

// membership varification is done
// next to this will be task list and other things
?>

<?php


$sql = "SELECT membership_id 
FROM created_group 
WHERE user_id = '$user_id' AND group_id = '$group_id' 
UNION 
SELECT membership_id 
FROM joined_group 
WHERE user_id = '$user_id' AND group_id = '$group_id'";

$result = $conn->query($sql);

$membership_id = $result->fetch_assoc()['membership_id'];

?>












<?php

// --- Form Submission Handling ---
$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and retrieve form data
    $title = trim($_POST['title'] ?? '');
    $detail = trim($_POST['detail'] ?? '');
    $deadline_input = trim($_POST['deadline'] ?? '');
    $type = 'group'; // Force type to private
    $status = 'todo'; // Default status for new tasks

    // Validation
    if (empty($title)) {
        $error_message = "Task title is required.";
    } elseif (strlen($title) > 30) {
        $error_message = "Task title cannot exceed 30 characters.";
    } elseif (strlen($detail) > 200) {
        $error_message = "Task detail cannot exceed 200 characters.";
    } else {
        // Process deadline
        $deadline = null;
        if (!empty($deadline_input)) {
            try {
                $deadline_dt = new DateTime($deadline_input);
                $deadline = $deadline_dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $error_message = "Invalid deadline format provided.";
                $deadline = null;
            }
        }

        if (empty($error_message)) {
            // Updated SQL to include status
            $sql = "INSERT INTO task (title, detail, creation_time, deadline, type, status, user_id, membership_id)
                    VALUES (?, ?, NOW(), ?, ?, ?, NULL, ?)";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssssi", $title, $detail, $deadline, $type, $status, $membership_id);

                if ($stmt->execute()) {
                    $success_message = "Task created successfully!";
                    $_POST = array();
                } else {
                    error_log("Task creation error for user $user_id: " . $stmt->error);
                    $error_message = "An error occurred while creating the task.";
                }
                $stmt->close();
            } else {
                error_log("Database preparation error (INSERT task): " . $conn->error);
                $error_message = "An error occurred. Please try again later.";
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Task</title>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --text-color: #2c3e50;
            --light-bg: #f8f9fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        body {
            background-color: var(--light-bg);
            color: var(--text-color);
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 30px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .page-header {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        h1 {
            color: var(--primary-color);
            margin-bottom: 25px;
            text-align: center;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        input[type="text"],
        textarea,
        input[type="datetime-local"],
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1em;
            background-color: white;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus,
        textarea:focus,
        input[type="datetime-local"]:focus,
        select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn {
            display: inline-block;
            padding: 10px 18px;
            font-size: 0.95em;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 25px;
            font-size: 1.05em;
            width: 100%;
            margin-top: 10px;
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            text-align: center;
            font-weight: 500;
        }

        .error-message {
            background-color: #f8d7da;
            color: var(--danger-color);
            border: 1px solid #f5c6cb;
        }

        .success-message {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        .required-field::after {
            content: " *";
            color: var(--danger-color);
        }

        @media (max-width: 600px) {
            .container {
                padding: 20px;
                margin: 20px auto;
            }
            h1 {
                font-size: 1.6em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <a href="group_task.php?id=<?php echo $group_id ?>" class="btn btn-secondary">Back to Tasks</a>
        </div>

        <h1>Create New Task</h1>

        <?php if (!empty($success_message)): ?>
            <div class="message success-message"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="message error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) . '?id=' . urlencode($group_id) ?>" method="post" class="task-form">
            <input type="hidden" name="group_id" value="<?= htmlspecialchars($group_id) ?>">

            <div class="form-group">
                <label for="title" class="required-field">Task Title</label>
                <input type="text" id="title" name="title" maxlength="30" required 
                       value="<?= isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '' ?>">
            </div>

            <div class="form-group">
                <label for="detail">Details</label>
                <textarea id="detail" name="detail" maxlength="200"><?= isset($_POST['detail']) ? htmlspecialchars($_POST['detail']) : '' ?></textarea>
            </div>

            <div class="form-group">
                <label for="deadline">Deadline</label>
                <input type="datetime-local" id="deadline" name="deadline" 
                       value="<?= isset($_POST['deadline']) ? htmlspecialchars($_POST['deadline']) : '' ?>">
            </div>

            <button type="submit" class="btn btn-primary">Create Task</button>
        </form>
    </div>
</body>
</html>