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



// Get status filter from query parameter
$status_filter = $_GET['status'] ?? 'all';
$valid_statuses = ['all', 'todo', 'done', 'dismissed'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

// Handle task updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['task_id'])) {
        $action = $_POST['action'];
        $task_id_to_update = filter_input(INPUT_POST, 'task_id', FILTER_SANITIZE_NUMBER_INT);

        if ($task_id_to_update) {
            switch ($action) {
                case 'done':
                    $sql_update = "UPDATE task SET status = 'done' WHERE task_id = ? AND user_id = ?";
                    break;
                case 'dismissed':
                    $sql_update = "UPDATE task SET status = 'dismissed' WHERE task_id = ? AND user_id = ?";
                    break;
                case 'delete':
                    $sql_update = "DELETE FROM task WHERE task_id = ? AND user_id = ?";
                    break;
                default:
                    $sql_update = null;
            }

            if ($sql_update) {
                if ($stmt_update = $conn->prepare($sql_update)) {
                    $stmt_update->bind_param("ii", $task_id_to_update, $user_id);
                    if ($stmt_update->execute()) {
                        // Success, maybe set a success message
                    } else {
                        error_log("Database error updating task $task_id_to_update for user $user_id: " . $stmt_update->error);
                        $error_message = "Error updating task. Please try again.";
                    }
                    $stmt_update->close();
                } else {
                    error_log("Database preparation error for update: " . $conn->error);
                    $error_message = "System error. Please try again later.";
                }
            }
        }
    }
    // Redirect to refresh the task list
    header("Location: task.php?status=$status_filter");
    exit();
}

// Build SQL query with status filter
$sql = "SELECT task_id, title, detail, creation_time, deadline, status
        FROM task
        WHERE user_id = ? AND type = 'private'";

// Add status condition if not 'all'
if ($status_filter !== 'all') {
    $sql .= " AND status = ?";
}

$sql .= " ORDER BY
            CASE WHEN deadline IS NULL THEN 1 ELSE 0 END,
            deadline ASC,
            creation_time DESC";

$tasks = [];
if ($stmt = $conn->prepare($sql)) {
    // Bind parameters based on filter
    if ($status_filter !== 'all') {
        $stmt->bind_param("is", $user_id, $status_filter);
    } else {
        $stmt->bind_param("i", $user_id);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
    } else {
        error_log("Database error for user $user_id: " . $stmt->error);
        $error_message = "Error fetching tasks. Please try again later.";
    }
    $stmt->close();
} else {
    error_log("Database preparation error: " . $conn->error);
    $error_message = "System error. Please try again later.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Private Tasks</title>
    <style>
        :root {
            --todo-color: #3498db;
            --done-color: #2ecc71;
            --dismissed-color: #95a5a6;
            --overdue-color: #e74c3c;
            --text-color: #2c3e50;
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
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
            max-width: 950px; /* Increased max-width to accommodate buttons */
            margin: 20px auto;
            padding: 25px 30px;
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title {
            flex: 1;
            min-width: 200px;
        }

        .btn {
            padding: 10px 18px;
            font-size: 0.95em;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            border: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .btn-primary {
            background: var(--todo-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .status-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .status-filter {
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.2s ease;
            border: 1px solid #ddd;
            background: white;
        }

        .status-filter:hover, .status-filter.active {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .status-filter.active[data-status="all"] {
            background: #34495e;
            color: white;
            border-color: #34495e;
        }

        .status-filter.active[data-status="todo"] {
            background: var(--todo-color);
            color: white;
            border-color: var(--todo-color);
        }

        .status-filter.active[data-status="done"] {
            background: var(--done-color);
            color: white;
            border-color: var(--done-color);
        }

        .status-filter.active[data-status="dismissed"] {
            background: var(--dismissed-color);
            color: white;
            border-color: var(--dismissed-color);
        }

        .task-list {
            list-style: none;
            padding: 0;
        }

        .task-item {
            background: var(--card-bg);
            border-left: 4px solid var(--todo-color);
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center; /* Align buttons and content vertically */
            justify-content: space-between; /* Space out buttons and text */
        }

        .task-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .task-item.done {
            border-left-color: var(--done-color);
            opacity: 0.8;
        }

        .task-item.dismissed {
            border-left-color: var(--dismissed-color);
            opacity: 0.7;
        }

        .task-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 5px 8px;
            font-size: 0.8em;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            color: white;
            transition: background-color 0.2s ease;
        }

        .done-btn {
            background-color: var(--done-color);
        }

        .done-btn:hover {
            background-color: #27ae60;
        }

        .dismiss-btn {
            background-color: var(--dismissed-color);
        }

        .dismiss-btn:hover {
            background-color: #7f8c8d;
        }

        .delete-btn {
            background-color: #e74c3c;
        }

        .delete-btn:hover {
            background-color: #c0392b;
        }

        .task-content {
            flex-grow: 1;
            margin-right: 10px; /* Add some margin to the text content */
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .task-title {
            font-size: 1.2em;
            font-weight: 600;
            color: var(--text-color);
        }

        .task-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-todo {
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--todo-color);
        }

        .status-done {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--done-color);
        }

        .status-dismissed {
            background-color: rgba(149, 165, 166, 0.1);
            color: var(--dismissed-color);
        }

        .task-detail {
            color: #555;
            margin-bottom: 10px;
            font-size: 0.95em;
            word-wrap: break-word;
        }

        .task-footer {
            display: flex;
            justify-content: space-between;
            font-size: 0.85em;
            color: #777;
            flex-wrap: wrap;
            gap: 10px;
        }

        .task-deadline {
            font-weight: 500;
        }

        .overdue {
            color: var(--overdue-color);
            font-weight: bold;
        }

        .no-tasks, .error-message {
            text-align: center;
            padding: 30px;
            background: #f9f9f9;
            border: 1px dashed #ddd;
            border-radius: 8px;
        }

        .error-message {
            color: var(--overdue-color);
            background: #fdecea;
            border-color: #f5c6cb;
        }

        .confirmation-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 10;
        }

        .confirmation-box {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        .confirmation-buttons {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .confirm-btn, .cancel-btn {
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            font-weight: bold;
            transition: opacity 0.2s ease;
            min-width: 100px;
            text-align: center;
        }

        .confirm-btn {
            background-color: #2ecc71;
            color: white;
        }

        .cancel-btn {
            background-color: #f39c12;
            color: white;
        }

        .confirm-btn:hover, .cancel-btn:hover {
            opacity: 0.9;
        }

        @media (max-width: 650px) {
            .container {
                padding: 20px 15px;
            }
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            .task-item {
                flex-direction: column;
                align-items: stretch;
            }
            .task-actions {
                flex-direction: row;
                justify-content: flex-end;
                margin-bottom: 10px;
            }
            .task-content {
                margin-bottom: 10px;
            }
            .task-footer {
                flex-direction: column;
            }
            .confirmation-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <a href="../home.php" class="btn btn-secondary">Back</a>
            <h1 class="header-title">My Private Tasks</h1>
            <a href="create_task.php" class="btn btn-primary">Create Task</a>
        </div>

        <div class="status-filters">
            <div class="status-filter <?= $status_filter === 'all' ? 'active' : '' ?>" data-status="all">All Tasks</div>
            <div class="status-filter <?= $status_filter === 'todo' ? 'active' : '' ?>" data-status="todo">To Do</div>
            <div class="status-filter <?= $status_filter === 'done' ? 'active' : '' ?>" data-status="done">Done</div>
            <div class="status-filter <?= $status_filter === 'dismissed' ? 'active' : '' ?>" data-status="dismissed">Dismissed</div>
        </div>

        <?php if (!empty($error_message)): ?>
            <p class="error-message"><?= htmlspecialchars($error_message) ?></p>
        <?php elseif (empty($tasks)): ?>
            <p class="no-tasks">No tasks found with the selected filter.</p>
        <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tasks as $task):
                    $created = new DateTime($task['creation_time']);
                    $creation_date = $created->format("M d, Y, h:i A");

                    $deadline_date = 'No deadline';
                    $is_overdue = false;
                    if ($task['deadline']) {
                        $deadline = new DateTime($task['deadline']);
                        $deadline_date = $deadline->format("M d, Y, h:i A");
                        $is_overdue = ($deadline < new DateTime()) && $task['status'] === 'todo';
                    }
                ?>
                    <li class="task-item <?= $task['status'] ?>">
                        <div class="task-content">
                            <div class="task-header">
                                <div class="task-title"><?= htmlspecialchars($task['title'] ?? 'Untitled Task') ?></div>
                                <div class="task-status status-<?= $task['status'] ?>">
                                    <?= ucfirst($task['status']) ?>
                                </div>
                            </div>
                            <?php if (!empty($task['detail'])): ?>
                                <p class="task-detail"><?= nl2br(htmlspecialchars($task['detail'])) ?></p>
                            <?php endif; ?>
                            <div class="task-footer">
                                <span>Created: <?= $creation_date ?></span>
                                <span class="task-deadline <?= $is_overdue ? 'overdue' : '' ?>">
                                    <?= $task['deadline'] ? "Deadline: $deadline_date" : 'No deadline' ?>
                                    <?= $is_overdue ? ' (Overdue)' : '' ?>
                                </span>
                            </div>
                        </div>
                        <div class="task-actions">
                            <button class="action-btn done-btn" data-task-id="<?= $task['task_id'] ?>" onclick="showConfirmation('done', <?= $task['task_id'] ?>)">Done</button>
                            <button class="action-btn dismiss-btn" data-task-id="<?= $task['task_id'] ?>" onclick="showConfirmation('dismissed', <?= $task['task_id'] ?>)">Dismiss</button>
                            <button style="background-color: black;" class="action-btn done-btn" onclick="window.location.href='edit_task.php?task_id=<?= $task['task_id'] ?>'">Edit</button>
                            <button class="action-btn delete-btn" data-task-id="<?= $task['task_id'] ?>" onclick="showConfirmation('delete', <?= $task['task_id'] ?>)">Delete</button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="confirmation-overlay" id="confirmationOverlay">
        <div class="confirmation-box">
            <p id="confirmationText">Are you sure?</p>
            <div class="confirmation-buttons">
                <button class="confirm-btn" id="confirmButton">Confirm</button>
                <button class="cancel-btn" onclick="hideConfirmation()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        let taskIdToUpdate;
        let actionToPerform;

        function showConfirmation(action, taskId) {
            taskIdToUpdate = taskId;
            actionToPerform = action;
            let confirmationText = "";
            switch (action) {
                case 'done':
                    confirmationText = "Mark this task as done?";
                    break;
                case 'dismissed':
                    confirmationText = "Dismiss this task?";
                    break;
                case 'delete':
                    confirmationText = "Delete this task?";
                    break;
            }
            
            document.getElementById("confirmationText").textContent = confirmationText;
            document.getElementById("confirmationOverlay").style.display = "flex";
        }

        function hideConfirmation() {
            document.getElementById("confirmationOverlay").style.display = "none";
        }

        document.getElementById("confirmButton").addEventListener("click", () => {
            // Send form submission here
            let form = document.createElement('form');
            form.method = 'post';
            form.action = ''; // Submit to the same page
            
            let actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = actionToPerform;
            form.appendChild(actionInput);

            let taskIdInput = document.createElement('input');
            taskIdInput.type = 'hidden';
            taskIdInput.name = 'task_id';
            taskIdInput.value = taskIdToUpdate;
            form.appendChild(taskIdInput);
            
            document.body.appendChild(form);  // Append form to the body
            form.submit();                   // Submit the form
            
            hideConfirmation(); // Hide the overlay after submission
        });

        // Add click handlers for status filters
        document.querySelectorAll('.status-filter').forEach(filter => {
            filter.addEventListener('click', () => {
                const status = filter.getAttribute('data-status');
                window.location.href = `?status=${status}`;
            });
        });
    </script>
</body>
</html>