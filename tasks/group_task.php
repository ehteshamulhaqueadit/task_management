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

if ($group_id === null || $group_id === false) {
    $group_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
}


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
                // Check if the user is the task owner or a group leader

                $stmt_check_owner = $conn->prepare("SELECT task_id FROM task WHERE task_id = ? AND membership_id = ?");
                $stmt_check_owner->bind_param("ii", $task_id_to_update, $membership_id);
                $stmt_check_owner->execute();
                $result_check_owner = $stmt_check_owner->get_result();
                if ($result_check_owner->num_rows == 0 && $current_user_role !== 'leader') {
                    
                    echo "You are not authorized for this action.";
                    exit();
                }
                $stmt_check_owner->close();
            // Prepare SQL update statement based on action
            if ($action === 'done') {
                $sql_update = "UPDATE task SET status = 'done' WHERE task_id = ?";
            } elseif ($action === 'dismissed') {
                $sql_update = "UPDATE task SET status = 'dismissed' WHERE task_id = ?";
            } elseif ($action === 'delete') {
                $sql_update = "DELETE FROM task WHERE task_id = ?";
            } else {
                $sql_update = null; // Invalid action
            }

            if ($sql_update) {
                if ($stmt_update = $conn->prepare($sql_update)) {
                    $stmt_update->bind_param("i", $task_id_to_update);
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
    
}

// Build SQL query with status filter
$sql = "SELECT 
    t.task_id,
    t.title,
    t.detail,
    t.creation_time,
    t.deadline,
    t.membership_id,
    t.status,
    u.name AS task_owner_name
FROM 
    task t
JOIN 
    member m ON t.membership_id = m.membership_id
JOIN 
    ( 
        -- Get users from both created_group and joined_group based on group_id
        SELECT membership_id, user_id 
        FROM joined_group 
        WHERE group_id = ?
        
        UNION 
        
        SELECT membership_id, user_id 
        FROM created_group 
        WHERE group_id = ?
    ) AS group_members ON m.membership_id = group_members.membership_id
JOIN 
    user u ON group_members.user_id = u.user_id
";

// Add status condition if not 'all'
if ($status_filter !== 'all') {
    $sql .= " AND status = ?";
}

$sql .= " ORDER BY
            CASE WHEN deadline IS NULL THEN 1 ELSE 0 END,
            deadline ASC,
            creation_time DESC ;";

// echo $sql;
$tasks = [];
if ($stmt = $conn->prepare(query: $sql)) {
    // Bind parameters based on filter
    if ($status_filter !== 'all') {
        $stmt->bind_param("iis", $group_id, $group_id, $status_filter);
    } else {
        $stmt->bind_param("ii", $group_id, $group_id);
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
<body style="background-color:rgb(244, 244, 244);">
    <div class="container">
        <div class="page-header">
            <a href="../colaboration/group_details.php?id=<?=$group_id?>" class="btn btn-secondary">Back</a>
            <h1 class="header-title">Our Group Tasks</h1>
            <a href="create_group_task.php?id=<?php echo $group_id ?>" class="btn btn-primary">Create Task</a>
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
                                <span>Created: <?= $creation_date ?></span> <span style="color: green;">Owner: <?= $task["task_owner_name"] ?> </span>
                                <span class="task-deadline <?= $is_overdue ? 'overdue' : '' ?>">
                                    <?= $task['deadline'] ? "Deadline: $deadline_date" : 'No deadline' ?>
                                    <?= $is_overdue ? ' (Overdue)' : '' ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($current_user_role=="leader" || $membership_id==$task["membership_id"]) { ?>
                        <div class="task-actions">
                            <button class="action-btn done-btn" data-task-id="<?= $task['task_id'] ?>" onclick="showConfirmation('done', <?= $task['task_id'] ?>)">Done</button>
                            <button class="action-btn dismiss-btn" data-task-id="<?= $task['task_id'] ?>" onclick="showConfirmation('dismissed', <?= $task['task_id'] ?>)">Dismiss</button>
                            <button style="background-color: black;" class="action-btn done-btn" onclick="window.location.href='edit_group_task.php?id=<?=$group_id?>&task_id=<?= $task['task_id'] ?>'">Edit</button>
                            <button class="action-btn delete-btn" data-task-id="<?= $task['task_id'] ?>" onclick="showConfirmation('delete', <?= $task['task_id'] ?>)">Delete</button>
                        </div>
                        <?php }?>
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
                window.location.href = `?id=<?=$group_id?>&status=${status}`;
            });
        });
    </script>
</body>
</html>