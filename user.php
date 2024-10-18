<?php
include "connection.php";
include "headers.php";

class User
{

    function userLogin($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "SELECT * FROM tbl_users WHERE users_username = :users_username AND BINARY users_password = :users_password";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":users_username", $json["users_username"]);
        $stmt->bindParam(":users_password", $json["users_password"]);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? json_encode($result) : 0;
    }
    function studentsAttendance($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $scannedID = $json['personal_id_number'];

        // Get the current year and generate school year string (e.g., "2024-2025")
        $currentYear = date('Y');
        $nextYear = $currentYear + 1;
        $schoolYear = "$currentYear-$nextYear";

        // Set the default semester
        $semester = 1;

        // Get the current date
        $currentDate = date('Y-m-d');

        // Query to check if the scanned ID exists and get duty_id and today's attendance record
        $sql = "SELECT 
                    a.personal_id_number, 
                    e.duty_id, 
                    d.dtr_time_in, 
                    d.dtr_time_out, 
                    d.dtr_id,
                    d.dtr_date,
                    e.duty_hours
                FROM tbl_personal_info AS a 
                INNER JOIN tbl_users AS b ON b.users_personal_info = a.personal_id
                INNER JOIN tbl_students AS c ON c.students_user_id = b.users_id
                INNER JOIN tblduty_assign AS e ON e.duty_students_id = c.students_id
                LEFT JOIN tbl_daily_time_record AS d 
                    ON d.dtr_duty_assign_id = e.duty_id 
                    AND d.dtr_date = :currentDate
                WHERE a.personal_privilege_status = 2 
                AND a.personal_id_number = :scannedID";

        // Prepare the statement
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':scannedID', $scannedID, PDO::PARAM_STR);
        $stmt->bindValue(':currentDate', $currentDate, PDO::PARAM_STR); // Bind current date
        $stmt->execute();

        // Fetch the result
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $dutyAssignId = $result['duty_id'];   // Get the duty assignment ID

            // Check if a record exists for today
            if (empty($result['dtr_id'])) {
                // No record exists for today, insert a new attendance record
                $sqlInsert = "
                    INSERT INTO tbl_daily_time_record 
                        (dtr_duty_assign_id, dtr_date, dtr_time_in, dtr_school_year, dtr_semester) 
                    VALUES 
                        (:duty_assign_id, CURDATE(), NOW(), :school_year, :semester)
                ";

                // Prepare the insert statement
                $stmtInsert = $conn->prepare($sqlInsert);
                $stmtInsert->bindValue(':duty_assign_id', $dutyAssignId, PDO::PARAM_INT);
                $stmtInsert->bindValue(':school_year', $schoolYear, PDO::PARAM_STR);
                $stmtInsert->bindValue(':semester', $semester, PDO::PARAM_INT);

                // Execute the insert query
                $stmtInsert->execute();

                // Check if the insert query succeeded
                return $stmtInsert->rowCount() > 0 ? 1 : 0;
            } else {
                // If the record exists for today but is incomplete, update it
                $dtrId = $result['dtr_id'];

                if (empty($result['dtr_time_in'])) {
                    // Update to insert dtr_time_in if it's empty
                    $sqlUpdate = "
                        UPDATE tbl_daily_time_record 
                        SET dtr_time_in = NOW() 
                        WHERE dtr_id = :dtr_id
                    ";
                } else {
                    // Update to insert dtr_time_out if dtr_time_in exists
                    $sqlUpdate = "
                        UPDATE tbl_daily_time_record 
                        SET dtr_time_out = NOW() 
                        WHERE dtr_id = :dtr_id
                    ";
                }

                // Prepare and execute the update statement
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bindValue(':dtr_id', $dtrId, PDO::PARAM_INT);
                $stmtUpdate->execute();

                // Check if the update query succeeded
                if ($stmtUpdate->rowCount() > 0) {
                    // Now we need to check if both dtr_time_in and dtr_time_out are set for the record
                    $sqlCheckTime = "
                        SELECT dtr_time_in, dtr_time_out 
                        FROM tbl_daily_time_record 
                        WHERE dtr_id = :dtr_id
                    ";

                    // Prepare and execute the check statement
                    $stmtCheckTime = $conn->prepare($sqlCheckTime);
                    $stmtCheckTime->bindValue(':dtr_id', $dtrId, PDO::PARAM_INT);
                    $stmtCheckTime->execute();
                    $timeResult = $stmtCheckTime->fetch(PDO::FETCH_ASSOC);

                    if ($timeResult && !empty($timeResult['dtr_time_in']) && !empty($timeResult['dtr_time_out'])) {
                        // Calculate total hours, minutes, and seconds worked
                        $timeIn = new DateTime($timeResult['dtr_time_in']);
                        $timeOut = new DateTime($timeResult['dtr_time_out']);
                        $interval = $timeIn->diff($timeOut);

                        // Convert the interval to total seconds worked
                        $totalWorkedSeconds = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;

                        // Update the duty_hours in the tblduty_assign table
                        $sqlUpdateDutyHours = "
                            UPDATE tblduty_assign 
                            SET duty_hours = duty_hours - :worked_seconds
                            WHERE duty_id = :duty_assign_id
                        ";

                        // Prepare the update for duty hours
                        $stmtUpdateDutyHours = $conn->prepare($sqlUpdateDutyHours);
                        $stmtUpdateDutyHours->bindValue(':worked_seconds', $totalWorkedSeconds, PDO::PARAM_INT);
                        $stmtUpdateDutyHours->bindValue(':duty_assign_id', $dutyAssignId, PDO::PARAM_INT);
                        $stmtUpdateDutyHours->execute();

                        // Prepare the response with hours, minutes, and seconds
                        $totalHours = $interval->h;
                        $totalMinutes = $interval->i;
                        $totalSeconds = $interval->s;

                        // Return the total hours, minutes, and seconds worked along with a success response
                        return json_encode([
                            'status' => 1,
                            'total_time' => [
                                'hours' => $totalHours,
                                'minutes' => $totalMinutes,
                                'seconds' => $totalSeconds
                            ]
                        ]);
                    }

                    return 1; // Indicates update was successful but hours not calculated
                }
            }
        }

        // If the scanned ID is not found, return 0
        return 0;
    }

    function getStudentsDetails($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        // Fetch a single student row
        $sql = "SELECT * FROM tblstudents WHERE students_id = :students_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":students_id", $json["students_id"]);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? json_encode($result) : 0;
    }

    function getStudentsDetailsAndStudentDutyAssign($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        // Modify SQL query to convert duty_hours from seconds to hours
        $sql = "SELECT 
                a.personal_id_number, 
                a.personal_first_name, 
                a.personal_last_name, 
                e.rooms_number, 
                f.building_name, 
                g.subjects_code, 
                g.subjects_name, 
                j.personal_first_name AS advisor_first_name, 
                j.personal_last_name AS advisor_last_name, 
                (d.duty_hours / 3600) AS duty_hours   -- Convert seconds to hours
            FROM tbl_personal_info AS a 
            LEFT JOIN tbl_users AS b ON b.users_personal_info = a.personal_id
            LEFT JOIN tbl_students AS c ON c.students_user_id = b.users_id
            LEFT JOIN tblduty_assign AS d ON d.duty_students_id = c.students_id
            LEFT JOIN tblrooms AS e ON e.rooms_id = d.duty_room_id
            LEFT JOIN tblbuilding AS f ON f.building_id = d.duty_building_id
            LEFT JOIN tblsubjects AS g ON g.subjects_id = d.duty_subject_id
            LEFT JOIN tbl_advisor AS h ON h.advisor_id = d.duty_advisors_id
            LEFT JOIN tbl_users AS i ON i.users_id = h.advisor_user_Id
            LEFT JOIN tbl_personal_info AS j ON j.personal_id = i.users_personal_info
            WHERE b.users_id = :users_id AND a.personal_privilege_status = 2";

        // Prepare and execute the SQL query
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':users_id', $json['users_id']);
        $stmt->execute();

        // Fetch and return the result as JSON
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? json_encode($result) : 0;
    }


    function getStudentDtr($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        $sql = "SELECT 
                    dtr_date, 
                    TIME(dtr_time_in) AS dtr_time_in, 
                    TIME(dtr_time_out) AS dtr_time_out, 
                    dtr_school_year, 
                    dtr_semester, 
                    b.duty_hours, 
                    TIMESTAMPDIFF(SECOND, dtr_time_in, dtr_time_out) AS total_seconds
                FROM 
                    tbl_daily_time_record AS a 
                INNER JOIN 
                    tblduty_assign AS b ON b.duty_id = a.dtr_duty_assign_id
                INNER JOIN 
                    tbl_students AS c ON c.students_id = b.duty_students_id
                WHERE 
                    b.duty_students_id = (SELECT students_id FROM tbl_students WHERE students_user_id = :users_id)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':users_id', $json['users_id']);
        $stmt->execute();

        // Fetch all records
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the total_seconds into hours, minutes, and seconds
        foreach ($result as &$row) {
            $totalSeconds = $row['total_seconds'];
            $hours = floor($totalSeconds / 3600);
            $minutes = floor(($totalSeconds % 3600) / 60);
            $seconds = $totalSeconds % 60;

            // Format the time as "HH:MM:SS"
            $row['dutyH_time'] = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        // Return as JSON: either the result or an empty array
        return json_encode($result);
    }


    function getAssignedScholars($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "
            SELECT 
                a.personal_id_number, 
                CONCAT(a.personal_first_name , ' ', a.personal_last_name) AS personal_full_name, 
                a.personal_contact_number,
                a.personal_email_address,
                a.personal_address,
                e.rooms_number, 
                f.building_name, 
                g.subjects_code, 
                g.subjects_name, 
                d.duty_hours
            FROM tbl_personal_info AS a
            LEFT JOIN tbl_users AS b ON b.users_personal_info = a.personal_id
            LEFT JOIN tbl_students AS c ON c.students_user_id = b.users_id
            LEFT JOIN tblduty_assign AS d ON d.duty_students_id = c.students_id
            LEFT JOIN tblrooms AS e ON e.rooms_id = d.duty_room_id
            LEFT JOIN tblbuilding AS f ON f.building_id = d.duty_building_id
            LEFT JOIN tblsubjects AS g ON g.subjects_id = d.duty_subject_id
            LEFT JOIN tbl_advisor AS h ON h.advisor_id = d.duty_advisors_id
            LEFT JOIN tbl_users AS i ON i.users_id = h.advisor_user_Id
            WHERE d.duty_advisors_id = (
                SELECT advisor_id FROM tbl_advisor WHERE advisor_user_Id = :users_id
            ) 
            AND a.personal_privilege_status = 2
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':users_id', $json['users_id']);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ? json_encode($result) : 0;
    }
}

$json = isset($_POST["json"]) ? $_POST["json"] : "0";
$operation = isset($_POST["operation"]) ? $_POST["operation"] : "0";
$advisor = new User();

switch ($operation) {
    case "userLogin":
        echo $advisor->userLogin($json);
        break;
    case "studentsAttendance":
        echo $advisor->studentsAttendance($json);
        break;
    case "getStudentsDetails":
        echo $advisor->getStudentsDetails($json);
        break;
    case "getStudentsDetailsAndStudentDutyAssign":
        echo $advisor->getStudentsDetailsAndStudentDutyAssign($json);
        break;
    case "getStudentDtr":
        echo $advisor->getStudentDtr($json);
        break;
    case "getAssignedScholars":
        echo $advisor->getAssignedScholars($json);
        break;
    default:
        break;
}
