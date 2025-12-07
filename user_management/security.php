<?php

include("../authentication/session_check.php");
include("../db_connection.php");
$conn = db_connection(); // Establish database connection

// Helper function to extract device name from User-Agent
function getDeviceNameFromUserAgent($userAgent) {
  // Check for Android devices
  if (strpos($userAgent, 'Android') !== false) {
      preg_match('/Android.*?; (.+?) Build/', $userAgent, $matches);
      return isset($matches[1]) ? $matches[1] : 'Generic Android Device';
  }
  return 'Unknown Device';
}



$password_errors = [] ;
$password_success = [] ;
$ac_delete_errors = [] ;
$report_errors = [] ;
$report_success = [] ;

$genral_error = [];
// Check if the user is logged in

$user_data = get_user_existence_and_id(conn: $conn);

$user_exist = $user_data[0];
if ($user_exist) {
    $user_id = $user_data[1];
}else{
    header(header: "Location: ../authentication/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check form identification
    if (isset($_POST['form_id'])) {
        $form_id = $_POST['form_id'];
        
        if ($form_id == 'change_password') {
            // Handle the change password form
            $old_password = $_POST['old_password'];
            $new_password = $_POST['new_password'];
            $retype_new_password = $_POST['retype_new_password'];
            
            // Check if new passwords match
            if (strlen(string: $retype_new_password) < 6 || strlen(string: $new_password) < 6) {
                $password_errors[] = "Password must be at least 6 characters long.";
            }
            if ($new_password == $retype_new_password) {
                $old_password_hash = base64_encode(string: hash(algo: 'sha3-256', data: $old_password));
                $sql = "SELECT * FROM user WHERE user_id = '$user_id'";
                $result = $conn->query(query: $sql);
                $row = $result->fetch_assoc();
                $password_correct = $row['password_hash'] == $old_password_hash;
                if ($password_correct) {
                  $sql = " UPDATE user SET password_hash = '" . base64_encode(string: hash(algo: 'sha3-256', data: $new_password)) . "' WHERE user_id = '$user_id'";
                  $result = $conn->query(query: $sql);
                  if ($result) {
                      $password_success[] = "Password changed successfully.";
                      // Optionally, you can log the user out or redirect them to a different page
                  } else {
                      $password_errors[] = "Unexpected error. Failed to change password. Please try again.";
                  }
                } else {
                    $password_errors[] = "Old password is incorrect.";
                }

            } else {
                $password_errors[] = "New passwords do not match.";
            }
        } else if ($form_id == 'delete_account') {
            // Handle the delete account form
            $password = $_POST['password'];
            $retype_password = $_POST['retype_password'];
            
            // Check if passwords match
            if ($password == $retype_password) {
                $password_hash = base64_encode(string: hash(algo: 'sha3-256', data: $password));
                $sql = "SELECT * FROM user WHERE user_id = '$user_id'";
                $result = $conn->query(query: $sql);
                $row = $result->fetch_assoc();
                $password_correct = $row['password_hash'] == $password_hash;
                if (!$password_correct) {
                    $ac_delete_errors[] = "Password is incorrect.";
                } else {
                  $sql = "DELETE FROM user WHERE user_id = '$user_id'";
                  $result = $conn->query(query: $sql);
                  if ($result) {
                      header(header: "Location: ../authentication/login.php?account_deleted=true");
                      exit();
                  } else {
                      $ac_delete_errors[] = "Unexpected error. Failed to delete account. Please try again.";
                  }
                }
            } else {
                $ac_delete_errors[] = "Both passwords do not match.";
            }
        } else if ($form_id == 'report_to_admin') {
            // Handle the report to admin form
            $report_subject = $_POST['report_subject'];
            $report_details = $_POST['report_details'];
            if (strlen($report_subject)==0){
                $report_errors[] = "Subject cannot be empty.";
            }
            if (strlen(string: $report_details)==0){
                $report_errors[] = "Report details cannot be empty.";
            }
            // Generate a random report ID
            $generated_report_id = bin2hex(string: random_bytes(length: 64));
            // checking if the rport_id is unique or not
            $sql = "SELECT 1 FROM reports WHERE report_id = '$generated_report_id'";
            $result = $conn->query(query: $sql);
            while ($result->num_rows > 0) {
                $generated_report_id = bin2hex(string: random_bytes(length: 64));
                $sql = "SELECT 1 FROM reports WHERE report_id = '$generated_report_id'";
                $result = $conn->query(query: $sql);
            }

            // Handle file upload if any
            if (isset($_FILES['report_file']) && $_FILES['report_file']['error'] == 0) {
              // Check if the file size is under 10MB (10 * 1024 * 1024 bytes)
              if ($_FILES['report_file']['size'] > 10 * 1024 * 1024) {
                  $report_errors[] = "File size exceeds the 10MB limit.";
                  
              }
          
              // Validate file MIME type for JPEG and PNG
              $file_mime_type = mime_content_type(filename: $_FILES['report_file']['tmp_name']);
              if (!in_array(needle: $file_mime_type, haystack: ['image/jpeg', 'image/png'])) {
                  $report_errors[] = "Invalid file type. Only JPEG and PNG files are allowed.";
              
              }
              
              // Check for errors in the file upload
              if (empty($report_errors)) {
                  $extension = $file_mime_type === 'image/png' ? '.png' : '.jpg';
                  $file_name = $generated_report_id . $extension;

                  $destination = "../resources/images/reports/" . $file_name ;
              
                  // Move the uploaded file
                  $file_tmp = $_FILES['report_file']['tmp_name'];
                  $uploaded = move_uploaded_file(from: $file_tmp, to: $destination);
                  if (!$uploaded) {
                      $report_errors[] = "Failed to upload the file. Please try again.";
                  } else{
                      // File upload successful, proceed with database insertion
                      $today = date("Y-m-d");
                      $sql = "INSERT INTO reports (user_id, report_id, subject, details, file_extension, submission_date, status ) VALUES ('$user_id', '$generated_report_id', '$report_subject', '$report_details', '$extension', '$today', 'P' )";
                      $result = $conn->query(query: $sql);
                      if ($result) {
                          $report_success[] = "Report submitted successfully.";
                      } else {
                          unlink(filename: $destination); // Delete the uploaded file if database insertion fails
                          $report_errors[] = "Failed to submit the report. Please try again.";
                      }
                  }
              }

            } else {
              $today = date("Y-m-d");
              $sql = "INSERT INTO reports (user_id, report_id, subject, details, submission_date, status ) VALUES ('$user_id', '$generated_report_id', '$report_subject', '$report_details', '$today', 'P' )";
              $result = $conn->query(query: $sql);
              if ($result) {
                  $report_success[] = "Report submitted successfully.";
              } else {
                  $report_errors[] = "Failed to submit the report. Please try again.";
              }

            }

        } else {
            $genral_error[] = "Invalid form ID.";
        }
    } else {
        $genral_error[] = "Invalid form submission.";
    }
}





?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Device Login Info</title>
  <style>
    /* Reset and typography */
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f0f2f5;
      color: #333;
      line-height: 1.6;
      padding: 20px;
    }
    h1 {
      margin-bottom: 20px;
      text-align: center;
    }
    .container {
      max-width: 1000px;
      margin: auto;
      background: #fff;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    }

    /* Table Styles */
    .table-wrapper {
      overflow-x: auto;
      margin-bottom: 40px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 800px;
    }
    th, td {
      padding: 15px;
      text-align: left;
      border-bottom: 1px solid #e0e0e0;
      vertical-align: middle;
    }
    th {
      background-color: #f7f7f7;
      font-weight: 600;
    }
    tr:hover {
      background-color: #f1f1f1;
    }

    /* Buttons */
    .red-btn {
      background-color: #d54343;
      color: #fff;
      padding: 10px 15px;
      border: none;
      border-radius: 5px;
      cursor: pointer;
      transition: background-color 0.3s ease;
      font-size: 0.9rem;
    }
    .red-btn:hover {
      background-color: #8e0000;
    }
    
    /* Two-column layout */
    .management-container {
      display: flex;
      gap: 20px;
      margin-top: 30px;
    }
    .account-management, .report-admin {
      flex: 1;
      padding: 20px;
      background-color: #ffffff;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    .account-management {
      margin-right: 10px;
    }
    .report-admin {
      margin-left: 10px;
    }
    .form-group {
      margin-bottom: 15px;
    }
    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: bold;
    }
    .form-group input[type="text"],
    .form-group input[type="password"],
    .form-group input[type="file"],
    .form-group textarea {
      width: 100%;
      padding: 8px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    .form-group textarea {
      min-height: 345px;
      resize: vertical;
    }
    .submit-btn {
      width: 100%;
      padding: 10px;
      font-size: 1rem;
      background-color: #007bff;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      transition: background-color 0.3s;
    }
    .submit-btn:hover {
      background-color: #0056b3;
    }
    .section-title {
      text-align: center;
      margin-bottom: 20px;
    }
    .view-reports-btn {
        padding: 8px 16px;
        font-size: 1rem;
        background-color:rgb(225, 38, 38);
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.3s;
    }

    .view-reports-btn:hover {
        background-color:rgb(130, 37, 37);
    }


    /* Modal background */
.modal-background {
    display: none; /* Hidden by default */
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5); /* Transparent black */
    z-index: 1;
}

/* Modal content */
.modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background-color: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0px 4px 6px rgba(0, 0, 0, 0.1);
    z-index: 2;
    width: 300px; /* Adjust as needed */
    text-align: center;
}

/* Confirm button */
.confirm-btn {
    background-color: #dc3545;
    color: white;
    border: none;
    padding: 10px 20px;
    margin: 10px;
    border-radius: 4px;
    cursor: pointer;
}

/* Cancel button */
.close-button {
    background-color: #6c757d;
    color: white;
    border: none;
    padding: 10px 20px;
    margin: 10px;
    border-radius: 4px;
    cursor: pointer;
}

  </style>
</head>
<body>
  <div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
      <!-- Go Back Button -->
      <button onclick="window.location.href='./personal_profile.php'" 
              style="padding: 10px 20px; font-size: 1rem; background-color: #007bff; color: white; 
                    border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s;">
        Go Back
      </button>
      
      <!-- Centered Heading -->
      <h1 style="flex-grow: 1; text-align: center; margin: 0;">Device Login Information</h1>
      
      <!-- Refresh Button -->
      <button onclick="location.reload()" 
              style="padding: 10px 20px; font-size: 1rem; background-color: #28a745; color: white; 
                    border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s;">
        Refresh
      </button>
    </div>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Operating System</th>
            <th>Browser</th>
            <th>IP</th>
            <th>Device Name</th>
            <th>Session ID</th>
            <th>Expire Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $result = get_all_sessions(conn: $conn ,user_id: $user_id);
          if ($result == null) {
            echo "<script>alert('No sessions found for this user. Unexpected Error');</script>";
          } else {
              while ($session = $result->fetch_assoc()) {
                  $info = $session['device_login_info']; // Example: User_agent:-<value>|IP:-<value>|Device Name:-<value>

                  // Extract individual components
                  preg_match(pattern: '/User_agent:-(.+?)\|IP:-(.+?)\|Device Name:-(.+)/', subject: $info, matches: $matches);

                  $userAgent = isset($matches[1]) ? $matches[1] : "Unknown User Agent";
                  $ip = isset($matches[2]) ? $matches[2] : "Unknown IP";
                  
                  // Use User-Agent to fetch device name if it matches the IP
                  $deviceName = (isset($matches[3]) && $matches[3] !== $ip) ? $matches[3] : getDeviceNameFromUserAgent($userAgent);

                  // Truncate the session ID
                  // Store both the truncated and full session ID
                  $shortSessionId = substr($session['session_id'], offset: 0, length: 10) . '...';

                  echo "
                      <tr>
                          <td class='os'>Loading...</td>
                          <td class='browser'>Loading...</td>
                          <td>{$ip}</td>
                          <td>{$deviceName}</td>
                          <td>
                              <div class='collapsible' 
                                  data-full-session-id='{$session['session_id']}'
                                  data-short-session-id='{$shortSessionId}'
                                  onclick='toggleSessionId(this)'>{$shortSessionId}</div>
                          </td>
                          <td>{$session['expire_time']}</td>
                          <td><button class='red-btn' onclick='logout(event)'>Log Out<br><small>From Device</small></button></td>
                          <td hidden class='user-agent'>{$userAgent}</td>
                      </tr>
                  ";
              }
          }
          ?>
        </tbody>
      </table>
    </div>

    <!-- Two-column layout for Account Management and Report to Admin -->
    <div class="management-container">
      <!-- Account Management Section -->
      <div class="account-management">
        <h2 class="section-title">Account Management</h2>
        <!-- Change Password Form -->
        <form action="./security.php" method="POST">
              <!-- Hidden input for form identification -->
          <input type="hidden" name="form_id" value="change_password">
          <h3 style="text-align: center; margin-bottom: 20px;">Change Password</h3>
          <?php
          // Display password errors if any
          if (!empty($password_errors)) {
              echo "<div style='color: red; margin-bottom: 10px; text-align: center;'>";
              foreach ($password_errors as $error) {
                  echo "<p>$error</p>";
              }
              echo "</div>";
          }
          // Display password success message if any
          if (!empty($password_success)) {
              echo "<div style='color: green; margin-bottom: 10px; text-align: center;'>";
              foreach ($password_success as $success) {
                  echo "<p>$success</p>";
              }
              echo "</div>";
          }

          ?>
          <div class="form-group">
            <label for="old_password">Old Password:</label>
            <input type="password" id="old_password" name="old_password" placeholder="Enter your account's old password" required>
          </div>
          
          <div class="form-group">
            <label for="new_password">New Password:</label>
            <input type="password" id="new_password" name="new_password" placeholder="Enter new password ( minimum length is 6 )" required>
          </div>
          
          <div class="form-group">
            <label for="retype_new_password">Retype New Password:</label>
            <input type="password" id="retype_new_password" name="retype_new_password" placeholder="Retype new password" required>
          </div>
          
          <button type="submit" class="submit-btn">Change Password</button>
        </form>

        <!-- Delete Account Form -->
        <form action="./security.php" method="POST" id="delete-form" style="margin-top: 20px;">
            <input type="hidden" name="form_id" value="delete_account">
            <h3 style="text-align: center; margin-bottom: 20px;">Delete Account</h3>
            <?php
            // Display account deletion errors if any
            if (!empty($ac_delete_errors)) {
                echo "<div style='color: red; margin-bottom: 10px; text-align: center;'>";
                foreach ($ac_delete_errors as $error) {
                    echo "<p>$error</p>";
                }
                echo "</div>";
            }
            // Display account deletion success message if any
            ?>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" placeholder="Enter your account's Password" required>
            </div>
            <div class="form-group">
                <label for="retype_password">Retype Password:</label>
                <input type="password" id="retype_password" name="retype_password" placeholder="Retype your account's Password" required>
            </div>
            <button type="button" onclick="openModal()" class='red-btn' >Delete Account</button>
        </form>

        <!-- Modal structure for confirmation -->
        <div id="modal-background" class="modal-background" onclick="closeModal()">
            <div class="modal-content" onclick="event.stopPropagation();">
                <h3 style="text-align: center;">Warning</h3>
                <p style="text-align: center;">Are you sure you want to delete your account? This action is irreversible.</p>
                <div style="text-align: center;">
                    <button class="confirm-btn" onclick="submitForm()">Yes, Delete</button>
                    <button class="close-button" onclick="closeModal()">Cancel</button>
                </div>
            </div>
        </div>
      </div>



      <?php   if (user_type(conn: $conn, user_id: $user_id) != "admin") { ?>

        <!-- Report to Admin Section -->
        <div class="report-admin">
          <h2 class="section-title">Report to Admin</h2>
          <?php
          // Display report errors if any
          if (!empty($report_errors)) {
              echo "<div style='color: red; margin-bottom: 10px; text-align: center;'>";
              foreach ($report_errors as $error) {
                  echo "<p>$error</p>";
              }
              echo "</div>";
          }
          // Display report success message if any
          if (!empty($report_success)) {
              echo "<div style='color: green; margin-bottom: 10px; text-align: center;'>";
              foreach ($report_success as $success) {
                  echo "<p>$success</p>";
              }
              echo "</div>";
          }
          ?>
          <form action="./security.php" method="POST" enctype="multipart/form-data">
                <!-- Hidden input for form identification -->
            <input type="hidden" name="form_id" value="report_to_admin">
            <div class="form-group">
              <label for="report_subject">Subject:</label>
              <input type="text" id="report_subject" name="report_subject" required placeholder="Enter report subject (1 to 100 characters)">
            </div>
            
            <div class="form-group">
              <label for="report_details">Report Details:</label>
              <textarea id="report_details" name="report_details" required placeholder="Describe your issue in detail (1 to 1000 characters)"></textarea>
            </div>
            
            <div class="form-group">
              <label for="report_file">Attach File (Optional):</label>
              <input type="file" id="report_file" name="report_file">
            </div>
            <div style="justify-content: center; display: flex; gap: 16px;">
              <button type="submit" class="submit-btn">Submit Report</button>
              <button class="view-reports-btn" onclick="window.location.href='../reports_management/reports.php'">View Reports</button>
            </div>
          </form>
        </div>

      <?php } ?>
    
    </div>
  </div>
  
  <script>
    // Function to determine OS and Browser from User Agent
    function parseUserAgent(userAgent) {
      let os = "Unknown OS";
      let browser = "Unknown Browser";

      // Detect Operating System
      if (userAgent.includes("Windows NT 10.0")) {
        os = "Windows 10";
      } else if (userAgent.includes("Windows NT 6.1")) {
        os = "Windows 7";
      } else if (userAgent.includes("Macintosh")) {
        os = "Mac OS";
      } else if (userAgent.includes("Android")) {
        os = "Android";
      } else if (userAgent.includes("Linux")) {
        os = "Linux";
      }

      // Detect Browser
      if (userAgent.includes("Edg/")) {
        browser = "Microsoft Edge";
      } else if (userAgent.includes("Chrome/")) {
        browser = "Google Chrome";
      } else if (userAgent.includes("Safari/") && !userAgent.includes("Chrome/")) {
        browser = "Safari";
      } else if (userAgent.includes("Firefox/")) {
        browser = "Mozilla Firefox";
      }

      return { os, browser };
    }

    // Apply the parsing function to update the table dynamically
    document.querySelectorAll("tr").forEach(row => {
      const userAgentCell = row.querySelector(".user-agent");
      if (userAgentCell) {
        let userAgent = userAgentCell.textContent.trim();

        // Remove the "User_agent:-" prefix if present
        if (userAgent.startsWith("User_agent:-")) {
          userAgent = userAgent.replace("User_agent:-", "").trim();
        }

        // Parse the user agent
        const result = parseUserAgent(userAgent);

        // Update the OS and Browser cells
        row.querySelector(".os").textContent = result.os;
        row.querySelector(".browser").textContent = result.browser;
      }
    });

    function logout(event) {
      // Get the full session ID from the clicked row's div
      const sessionRow = event.target.closest("tr"); // Get the closest <tr> to the button
      const fullSessionId = sessionRow.querySelector(".collapsible").getAttribute("data-full-session-id");

      // Send the request to the server
      fetch('/user_management/delete_session.php', {
          method: 'POST',
          headers: {
              'Content-Type': 'application/json',
          },
          body: JSON.stringify({ delete_session_id: fullSessionId }), // Send the full session ID as JSON
      })
      .then(response => {
          if (response.ok) {
              alert('Session deleted successfully!');
              location.reload(); // Refresh the page to update the session list
          } else {
              alert('Failed to delete the session.');
          }
      })
      .catch(error => {
          console.error('Error:', error);
          alert('An error occurred while trying to delete the session.');
      });
    }

    function toggleSessionId(element) {
      // Get the full and truncated session IDs
      const fullSessionId = element.getAttribute("data-full-session-id");
      const shortSessionId = element.getAttribute("data-short-session-id");

      // Toggle the display between the full and truncated session ID
      if (element.textContent.trim() === shortSessionId) {
          element.textContent = fullSessionId; // Expand to full session ID
      } else {
          element.textContent = shortSessionId; // Collapse to truncated session ID
      }
    }
    // Function to open the modal
function openModal() {
    document.getElementById("modal-background").style.display = "block";
}

// Function to close the modal
function closeModal() {
    document.getElementById("modal-background").style.display = "none";
}

// Function to submit the form
function submitForm() {
    document.getElementById("delete-form").submit(); // Submits the form with POST request
    closeModal(); // Closes the modal after submission
}

  </script>
</body>
</html>