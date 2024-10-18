<?php
include "connection.php";
include "headers.php";

class StudentOperation
{
    function studentsLogin($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "SELECT * FROM tblstudents WHERE students_username = :students_username AND BINARY students_password = :students_password";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":students_username", $json["students_username"]);
        $stmt->bindParam(":students_password", $json["students_password"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
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
}

$json = isset($_POST["json"]) ? $_POST["json"] : "0";
$operation = isset($_POST["operation"]) ? $_POST["operation"] : "0";
$student = new StudentOperation();

switch ($operation) {
    case "studentsLogin":
        echo $student->studentsLogin($json);
        break;
    case "getStudentsDetails":
        echo $student->getStudentsDetails($json);
        break;
    default:
        break;
}
