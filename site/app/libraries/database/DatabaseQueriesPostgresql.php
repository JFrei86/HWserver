<?php

namespace app\libraries\database;

use app\libraries\Database;

class DatabaseQueriesPostgresql implements IDatabaseQueries{
    private $database;

    /**
     * QueriesPostgresql constructor.
     *
     * @param Database $database
     */
    public function __construct(Database $database) {
        $this->database = $database;
    }

    public function getUserById($user_id) {
        $this->database->query("SELECT * FROM users WHERE user_id=?", array($user_id));
        return $this->database->row();
    }

    public function getAssignmentById($assignment_id) {
        // TODO: Implement getAssignmentById() method.
    }

    public function getAllGradeables() {
        $this->database->query("
SELECT r.*, CASE WHEN (q.cnt > 0) THEN true ELSE false END as has_rubric
FROM assignments as r
LEFT JOIN (
	SELECT assignment_id, count(*) as cnt
	FROM questions
	GROUP BY assignment_id
) as q ON q.assignment_id=r.assignment_id
ORDER BY assignment_due_date, assignment_id");
        return $this->database->rows();
    }

    public function getAllStudents() {
        $this->database->query("
SELECT u.*, s.section_title
FROM users u 
LEFT JOIN (
    SELECT section_number, section_title 
    FROM sections
) as s ON s.section_number = u.user_course_section
WHERE user_group=1
ORDER BY u.user_course_section, u.user_id");
        return $this->database->rows();
    }

    public function getAllUsers() {
        $this->database->query("
SELECT u.*, s.section_title, g.group_name
FROM users u 
LEFT JOIN (
    SELECT section_number, section_title 
    FROM sections
) as s ON s.section_number = u.user_course_section
LEFT JOIN (
    SELECT group_number, group_name
    FROM groups
) as g ON g.group_number = u.user_group
WHERE user_group > 1
ORDER BY u.user_id");
        return $this->database->rows();
    }

    public function getAllGroups() {
        $this->database->query("SELECT * FROM groups ORDER BY group_number");
        return $this->database->rows();
    }

    public function getAllCourseSections() {
        $this->database->query("SELECT * FROM sections ORDER BY section_number");
        return $this->database->rows();
    }

    public function getSession($session_id) {
        // TODO: Implement getSession() method.
        return array();
    }

    public function newSession($user_id) {

    }
    
    public function removeExpiredSessions() {
        // TODO: Implement removeOldSessions() method.
    }
}