<?php
require_once("../authentication/session_check.php");
require_once("../db_connection.php");

$conn = db_connection();

// Check user login status
$user_data = get_user_existence_and_id(conn: $conn);
if (!$user_data[0]) {
    header("Location: ../authentication/login.php");
    exit();
}
$user_id = $user_data[1];



// Check if the user is an admin
if (user_type(conn: $conn, user_id: $user_id) == "admin") {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}



// Initialize variables
$error_message = '';
$success_message = '';
$task_id = null;
$task = null;

// Get task ID from URL
if (isset($_GET['task_id'])) {
    $task_id = filter_input(INPUT_GET, 'task_id', FILTER_SANITIZE_NUMBER_INT);
    
    // Fetch the task data
    $sql = "SELECT * FROM task WHERE task_id = ? AND user_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ii", $task_id, $user_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $task = $result->fetch_assoc();
            
            if (!$task) {
                $error_message = "Task not found or you don't have permission to edit it.";
            }
        } else {
            error_log("Database error fetching task: " . $stmt->error);
            $error_message = "Error fetching task data. Please try again.";
        }
        $stmt->close();
    } else {
        error_log("Database preparation error: " . $conn->error);
        $error_message = "System error. Please try again later.";
    }
} else {
    $error_message = "No task specified for editing.";
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && $task_id) {
    // Sanitize and validate input
    $title = trim($_POST['title'] ?? '');
    $detail = trim($_POST['detail'] ?? '');
    $deadline_input = trim($_POST['deadline'] ?? '');
    $status = trim($_POST['status'] ?? 'todo');

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
            }
        }

        if (empty($error_message)) {
            // Update the task in database
            $sql = "UPDATE task SET 
                    title = ?, 
                    detail = ?, 
                    deadline = ?, 
                    status = ? 
                    WHERE task_id = ? AND user_id = ?";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssssii", $title, $detail, $deadline, $status, $task_id, $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Task updated successfully!";
                    // Refresh task data
                    $task['title'] = $title;
                    $task['detail'] = $detail;
                    $task['deadline'] = $deadline;
                    $task['status'] = $status;
                } else {
                    error_log("Database error updating task: " . $stmt->error);
                    $error_message = "Error updating task. Please try again.";
                }
                $stmt->close();
            } else {
                error_log("Database preparation error: " . $conn->error);
                $error_message = "System error. Please try again later.";
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
    <title>Edit Task</title>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --text-color: #2c3e50;
            --light-bg: #f8f9fa;
            --edit-color: #3498db;
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
            justify-content: space-between;
            align-items: center;
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

        .btn-edit {
            background-color: var(--edit-color);
            color: white;
        }

        .btn-edit:hover {
            background-color: #2980b9;
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
            <a href="task.php" class="btn btn-secondary">Back to Tasks</a>
        </div>

        <h1>Edit Task</h1>

        <?php if (!empty($error_message)): ?>
            <div class="message error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php elseif (!empty($success_message)): ?>
            <div class="message success-message"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if ($task): ?>
            <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>?task_id=<?= $task_id ?>" method="post" class="task-form">
                <div class="form-group">
                    <label for="title" class="required-field">Task Title</label>
                    <input type="text" id="title" name="title" maxlength="30" required 
                           value="<?= htmlspecialchars($task['title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="detail">Details</label>
                    <textarea id="detail" name="detail" maxlength="200"><?= htmlspecialchars($task['detail'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="deadline">Deadline</label>
                    <input type="datetime-local" id="deadline" name="deadline" 
                           value="<?= $task['deadline'] ? htmlspecialchars(str_replace(' ', 'T', substr($task['deadline'], 0, 16))) : '' ?>">
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="todo" <?= ($task['status'] ?? '') === 'todo' ? 'selected' : '' ?>>To Do</option>
                        <option value="done" <?= ($task['status'] ?? '') === 'done' ? 'selected' : '' ?>>Done</option>
                        <option value="dismissed" <?= ($task['status'] ?? '') === 'dismissed' ? 'selected' : '' ?>>Dismissed</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Update Task</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>