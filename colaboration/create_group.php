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




$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_name = trim($_POST['group_name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validate inputs
    if (empty($group_name)) {
        $error_message = "Group name is required";
    } elseif (strlen($group_name) > 30) {
        $error_message = "Group name must be 30 characters or less";
    } elseif (strlen($description) > 200) {
        $error_message = "Description must be 200 characters or less";
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert into groups table
            $stmt_group = $conn->prepare("INSERT INTO groups (name, description) VALUES (?, ?)");
            $stmt_group->bind_param("ss", $group_name, $description);
            $stmt_group->execute();
            $group_id = $conn->insert_id;
            $stmt_group->close();

            // Insert into member table (for the creator)
            $stmt_member = $conn->prepare("INSERT INTO member (type) VALUES ('leader')");
            $stmt_member->execute();
            $membership_id = $conn->insert_id;
            $stmt_member->close();

            // Insert into created_group table
            $stmt_created = $conn->prepare("INSERT INTO created_group (user_id, membership_id, group_id, creation_date) VALUES (?, ?, ?, NOW())");
            $stmt_created->bind_param("iii", $user_id, $membership_id, $group_id);
            $stmt_created->execute();
            $stmt_created->close();

            // Insert into joined_group table
            $stmt_joined = $conn->prepare("INSERT INTO joined_group (user_id, membership_id, group_id, joining_date) VALUES (?, ?, ?, NOW())");
            $stmt_joined->bind_param("iii", $user_id, $membership_id, $group_id);
            $stmt_joined->execute();
            $stmt_joined->close();

            // Commit transaction
            $conn->commit();

            $success_message = "Group created successfully!";
            // Clear form
            $group_name = $description = '';
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error creating group: " . $e->getMessage());
            $error_message = "Error creating group. Please try again.";
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
    <title>Create New Group</title>
    <style>
        :root {
            --primary-color: #e74c3c;
            --text-color: #2c3e50;
            --light-bg: #f8f9fa;
            --card-bg: #ffffff;
            --error-color: #e74c3c;
            --success-color: #2ecc71;
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
            text-align: center;
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
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #c0392b;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            font-size: 1em;
            border: 1px solid #ddd;
            border-radius: 6px;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(231, 76, 60, 0.2);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .char-count {
            font-size: 0.8em;
            color: #777;
            text-align: right;
            margin-top: 5px;
        }

        .error-message {
            color: var(--error-color);
            font-size: 0.9em;
            margin-top: 5px;
        }

        .success-message {
            color: var(--success-color);
            background-color: rgba(46, 204, 113, 0.1);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 30px;
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .header-title {
                margin-bottom: 15px;
            }
            
            .form-footer {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <a href="groups.php" class="btn btn-secondary">Back</a>
            <h1 class="header-title">Create New Group</h1>
            <div style="width: 83px;"></div> <!-- Spacer for alignment -->
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <form method="POST" action="create_group.php">
            <div class="form-group">
                <label for="group_name" class="form-label">Group Name *</label>
                <input type="text" id="group_name" name="group_name" class="form-control" 
                       value="<?= htmlspecialchars($group_name ?? '') ?>" 
                       maxlength="30" required>
                <div class="char-count"><span id="name-counter">0</span>/30 characters</div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" class="form-control" 
                          maxlength="200"><?= htmlspecialchars($description ?? '') ?></textarea>
                <div class="char-count"><span id="desc-counter">0</span>/200 characters</div>
            </div>

            <div class="form-footer">
                <button type="submit" class="btn btn-primary">Create Group</button>
            </div>
        </form>
    </div>

    <script>
        // Character counters
        document.getElementById('group_name').addEventListener('input', function() {
            const count = this.value.length;
            document.getElementById('name-counter').textContent = count;
        });

        document.getElementById('description').addEventListener('input', function() {
            const count = this.value.length;
            document.getElementById('desc-counter').textContent = count;
        });

        // Initialize counters
        document.getElementById('name-counter').textContent = 
            document.getElementById('group_name').value.length;
        document.getElementById('desc-counter').textContent = 
            document.getElementById('description').value.length;
    </script>
</body>
</html>