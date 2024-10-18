<?php
include "connection.php";
include "headers.php";

class AdvisorOperation
{

    function advisorsLogin($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "SELECT * FROM tbl_users WHERE users_username = :users_username AND BINARY users_password = :users_password";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":users_username", $json["users_username"]);
        $stmt->bindParam(":users_password", $json["users_password"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }
    function studentsAttendance($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $scannedID = $json['students_id_number'];

        // Check if the scanned ID exists in the database
        $sql = "SELECT a.students_id_number, b.attendance_in, b.attendance_out
            FROM tblstudents AS a
            INNER JOIN tblduty_assign AS b ON b.duty_students_id = a.students_id
            WHERE a.students_id_number = :scannedID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':scannedID', $scannedID, PDO::PARAM_STR);
        $stmt->execute();

        // Fetch the data
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // If student ID exists, update the timestamp
            if (empty($result['attendance_in'])) {
                // Insert 'attendance_in' timestamp if it's empty
                $sqlUpdate = "UPDATE tblduty_assign 
                          SET attendance_in = NOW() 
                          WHERE duty_students_id = (SELECT students_id FROM tblstudents WHERE students_id_number = :scannedID)";
            } else {
                // If 'attendance_in' exists, update 'attendance_out'
                $sqlUpdate = "UPDATE tblduty_assign 
                          SET attendance_out = NOW() 
                          WHERE duty_students_id = (SELECT students_id FROM tblstudents WHERE students_id_number = :scannedID)";
            }

            // Prepare and execute the update statement
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bindParam(':scannedID', $scannedID, PDO::PARAM_STR);
            $stmtUpdate->execute();

            // Check if the update query actually updated any rows
            return $stmtUpdate->rowCount() > 0 ? 1 : 0;
        }

        // If the student ID is not found in the database, return 0
        return 0;
    }
}

$json = isset($_POST["json"]) ? $_POST["json"] : "0";
$operation = isset($_POST["operation"]) ? $_POST["operation"] : "0";
$advisor = new AdvisorOperation();

switch ($operation) {
    case "advisorsLogin":
        echo $advisor->advisorsLogin($json);
        break;
    case "studentsAttendance":
        echo $advisor->studentsAttendance($json);
        break;
    default:
        break;
}
