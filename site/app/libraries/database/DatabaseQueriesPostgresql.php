<?php

namespace app\libraries\database;

use app\libraries\Utils;
use \app\libraries\GradeableType;
use app\models\Gradeable;
use app\models\GradeableComponent;
use app\models\GradeableComponentMark;
use app\models\GradeableVersion;
use app\models\User;
use app\models\SimpleLateUser;
use app\models\Team;

class DatabaseQueriesPostgresql extends AbstractDatabaseQueries{

    public function getSubmittyUser($user_id) {
        $this->submitty_db->query("SELECT * FROM users WHERE user_id=?", array($user_id));
        return ($this->submitty_db->rowCount() > 0) ? new User($this->core, $this->submitty_db->row()) : null;
    }

    public function getUserById($user_id) {
        $this->course_db->query("
SELECT u.*, sr.grading_registration_sections
FROM users u
LEFT JOIN (
	SELECT array_agg(sections_registration_id) as grading_registration_sections, user_id
	FROM grading_registration
	GROUP BY user_id
) as sr ON u.user_id=sr.user_id
WHERE u.user_id=?", array($user_id));
        return ($this->course_db->rowCount() > 0) ? new User($this->core, $this->course_db->row()) : null;
    }

    public function getGradingSectionsByUserId($user_id) {
        $this->course_db->query("
SELECT array_agg(sections_registration_id) as grading_registration_sections, user_id
FROM grading_registration
GROUP BY user_id
WHERE user_id=?", array($user_id));
        return $this->course_db->row();
    }
    
    public function getAllUsers($section_key="registration_section") {
        $keys = array("registration_section", "rotating_section");
        $section_key = (in_array($section_key, $keys)) ? $section_key : "registration_section";
        $this->course_db->query("
SELECT u.*, sr.grading_registration_sections
FROM users u
LEFT JOIN (
	SELECT array_agg(sections_registration_id) as grading_registration_sections, user_id
	FROM grading_registration
	GROUP BY user_id
) as sr ON u.user_id=sr.user_id
ORDER BY u.{$section_key}, u.user_id");
        $return = array();
        foreach ($this->course_db->rows() as $row) {
            $return[] = new User($this->core, $row);
        }
        return $return;
    }

    public function getAllGraders() {
        $this->course_db->query("
SELECT u.*, sr.grading_registration_sections
FROM users u
LEFT JOIN (
	SELECT array_agg(sections_registration_id) as grading_registration_sections, user_id
	FROM grading_registration
	GROUP BY user_id
) as sr ON u.user_id=sr.user_id
WHERE u.user_group < 4
ORDER BY u.registration_section, u.user_id");
        $return = array();
        foreach ($this->course_db->rows() as $row) {
            $return[] = new User($this->core, $row);
        }
        return $return;
    }

    public function getGradeableComponentsMarks($gc_id) {
      $this->course_db->query("
SELECT *
FROM gradeable_component_mark
WHERE gc_id=?
ORDER BY gcm_order ASC
", array($gc_id));
      $return = array();
        foreach ($this->course_db->rows() as $row) {
            $return[$row['gcm_id']] = new GradeableComponentMark($this->core, $row);
        }
        return $return;
    }

    public function insertSubmittyUser(User $user) {
        $array = array($user->getId(), $user->getPassword(), $user->getFirstName(), $user->getPreferredFirstName(),
                       $user->getLastName(), $user->getEmail());

        $this->submitty_db->query("
INSERT INTO users (user_id, user_password, user_firstname, user_preferred_firstname, user_lastname, user_email) 
VALUES (?, ?, ?, ?, ?, ?)", $array);
    }

    public function insertCourseUser(User $user, $semester, $course) {
        $params = array($semester, $course, $user->getId(), $user->getGroup(), $user->getRegistrationSection(),
                        Utils::convertBooleanToString($user->isManualRegistration()));
        $this->submitty_db->query("
INSERT INTO courses_users (semester, course, user_id, user_group, registration_section, manual_registration) 
VALUES (?,?,?,?,?,?)", $params);

        $params = array($user->getRotatingSection(), $user->getId());
        $this->course_db->query("UPDATE users SET rotating_section=? WHERE user_id=?", $params);
        $this->updateGradingRegistration($user->getId(), $user->getGroup(), $user->getGradingRegistrationSections());
    }

    public function updateUser(User $user, $semester, $course) {
        $array = array($user->getPassword(), $user->getFirstName(), $user->getPreferredFirstName(),
                       $user->getLastName(), $user->getEmail(), $user->getId());
        $this->submitty_db->query("
UPDATE users SET user_password=?, user_firstname=?, user_preferred_firstname=?, user_lastname=?, user_email=?
WHERE user_id=?", $array);

        $params = array($user->getGroup(), $user->getRegistrationSection(),
                        Utils::convertBooleanToString($user->isManualRegistration()), $semester, $course,
                        $user->getId());
        $this->submitty_db->query("
UPDATE courses_users SET user_group=?, registration_section=?, manual_registration=? 
WHERE semester=? AND course=? AND user_id=?", $params);

        $params = array($user->getRotatingSection(), $user->getId());
        $this->course_db->query("UPDATE users SET rotating_section=? WHERE user_id=?", $params);
        $this->updateGradingRegistration($user->getId(), $user->getGroup(), $user->getGradingRegistrationSections());
    }

    public function updateGradingRegistration($user_id, $user_group, $sections) {
        $this->course_db->query("DELETE FROM grading_registration WHERE user_id=?", array($user_id));
        if ($user_group < 4) {
            foreach ($sections as $section) {
                $this->course_db->query("
    INSERT INTO grading_registration (user_id, sections_registration_id) VALUES(?, ?)", array($user_id, $section));
            }
        }
    }

    public function getGradeableComponents($g_id, $gd_id=null) {
        $this->course_db->query("
SELECT gcd.*, gc.*
FROM gradeable_component AS gc
LEFT JOIN (
  SELECT *
  FROM gradeable_component_data
  WHERE gd_id = ?
) as gcd ON gc.gc_id = gcd.gc_id
WHERE gc.g_id=?
", array($gd_id, $g_id));

        $return = array();
        foreach ($this->course_db->rows() as $row) {
            $return[$row['gc_id']] = new GradeableComponent($this->core, $row);
        }
        return $return;
    }

// This has to be updated to also load components for each version
    public function getGradeableVersions($g_id, $user_id, $team_id, $due_date) {
        if ($user_id === null) {
            $this->course_db->query("
SELECT egd.*, egv.active_version = egd.g_version as active_version
FROM electronic_gradeable_data AS egd
LEFT JOIN (
  SELECT *
  FROM electronic_gradeable_version
) AS egv ON egv.active_version = egd.g_version AND egv.team_id = egd.team_id AND egv.g_id = egd.g_id
WHERE egd.g_id=? AND egd.team_id=?
ORDER BY egd.g_version", array($g_id, $team_id));
        }
        else {
            $this->course_db->query("
SELECT egd.*, egv.active_version = egd.g_version as active_version
FROM electronic_gradeable_data AS egd
LEFT JOIN (
  SELECT *
  FROM electronic_gradeable_version
) AS egv ON egv.active_version = egd.g_version AND egv.user_id = egd.user_id AND egv.g_id = egd.g_id
WHERE egd.g_id=? AND egd.user_id=?
ORDER BY egd.g_version", array($g_id, $user_id));
        }
        
        $return = array();
        foreach ($this->course_db->rows() as $row) {
            $row['submission_time'] = new \DateTime($row['submission_time'], $this->core->getConfig()->getTimezone());
            $return[$row['g_version']] = new GradeableVersion($this->core, $row, $due_date);
        }

        return $return;
    }

    public function getAllGradeables($user_id = null) {
        return $this->getGradeables(null, $user_id);
    }

    public function getGradeable($g_id = null, $user_id = null) {
        return $this->getGradeables($g_id, $user_id)[0];
    }

    /*
     * TODO:
     * This should take in for:
     *  gradeable: [string] or [array] which then maps that into a where clause (g_id = string) OR (g_id IN (?, ?))
     *  users: [string] or [array] which then maps that into a where clause as well as adding in additional
     *      components for the SELECT cause and in the FROM clause (don't need gradeable_data if this is null, etc.)
     *  section_key:
     */
    public function getGradeables($g_ids = null, $user_ids = null, $section_key="registration_section", $sort_key="u.user_id", $g_type = null) {
        $return = array();
        $g_ids_query = "";
        $users_query = "";
        $g_type_query = "";
        $params = array();
        if ($g_ids !== null) {
            if (!is_array($g_ids)) {
                $g_ids = array($g_ids);
            }
            if (count($g_ids) > 0) {
                $g_ids_query = implode(",", array_fill(0, count($g_ids), "?"));
                $params = $g_ids;
            }
            else {
                return $return;
            }
        }

        if ($user_ids !== null) {
            if (!is_array($user_ids)) {
                $user_ids = array($user_ids);
            }
            if (count($user_ids) > 0) {
                $users_query = implode(",", array_fill(0, count($user_ids), "?"));
                $params = array_merge($params, $user_ids);
            }
            else {
                return $return;
            }
        }
        // added toggling of gradeable type to only grab Homeworks for HWReport generation
        if ($g_type !== null) {
            if (!is_array($g_type)) {
                $g_type = array($g_type);
            }
            if (count($g_type) > 0) {
                $g_type_query = implode(",", array_fill(0, count($g_type), "?"));
                $params = array_merge($params, $g_type);
            }
            else {
                return $return;
            }
        }
        $section_keys = array("registration_section", "rotating_section");
        $section_key = (in_array($section_key, $section_keys)) ? $section_key : "registration_section";
        $sort_keys = array("u.user_firstname", "u.user_lastname", "u.user_id");
        $sort_key = (in_array($sort_key, $sort_keys)) ? $sort_key : "u.user_id";
        $sort = array();
        switch ($sort_key) {
            case 'u.user_firstname':
                $sort[] = 'u.user_firstname';
            case 'u.user_lastname':
                $sort[] = 'u.user_lastname';
            case 'u.user_id':
                $sort[] = 'u.user_id';
                break;
            default:
                $sort[] = 'u.user_firstname';
        }
        $sort_key = implode(', ', $sort);
        $query = "
SELECT";
        if ($user_ids !== null) {
            $query .= "
  u.*,";
        }
        $query .= "
  g.*,
  eg.eg_config_path,
  eg.eg_is_repository,
  eg.eg_subdirectory,
  eg.eg_use_ta_grading,
  eg.eg_peer_grading,
  eg.eg_submission_open_date,
  eg.eg_submission_due_date,
  eg.eg_late_days,
  eg.eg_precision,
  gc.array_gc_id,
  gc.array_gc_title,
  gc.array_gc_ta_comment,
  gc.array_gc_student_comment,
  gc.array_gc_max_value,
  gc.array_gc_is_text,
  gc.array_gc_is_extra_credit,
<<<<<<< HEAD
=======
  gc.array_gc_is_peer,
>>>>>>> 13f5f67c735907902a4e4051bdec1dbcdd90f3ef
  gc.array_gc_order,
  gc.array_array_gcm_id,
  gc.array_array_gc_id,
  gc.array_array_gcm_points,
  gc.array_array_gcm_note,
  gc.array_array_gcm_order";
        if ($user_ids !== null) {
            $query .= ",
  gd.gd_id,
  gd.gd_overall_comment,
  gd.gd_user_viewed_date,
<<<<<<< HEAD
  gd.array_array_gcm_mark,
=======
  gd.array_array_gcm_id,
>>>>>>> 13f5f67c735907902a4e4051bdec1dbcdd90f3ef
  gd.array_gcd_gc_id,
  gd.array_gcd_score,
  gd.array_gcd_component_comment,
  gd.array_gcd_grader_id,
  gd.array_gcd_graded_version,
  gd.array_gcd_grade_time,
  gd.array_gcd_user_id,
  gd.array_gcd_user_firstname,
  gd.array_gcd_user_preferred_firstname,
  gd.array_gcd_user_lastname,
  gd.array_gcd_user_email,
  gd.array_gcd_user_group,
  CASE WHEN egd.active_version IS NULL THEN 
    0 ELSE 
    egd.active_version 
  END AS active_version,
  egd.team_id,
  egd.g_version,
  egd.autograding_non_hidden_non_extra_credit,
  egd.autograding_non_hidden_extra_credit,
  egd.autograding_hidden_non_extra_credit,
  egd.autograding_hidden_extra_credit,
  egd.submission_time,
  egv.highest_version
FROM users AS u
NATURAL JOIN gradeable AS g";
        }
        else {
            $query .= "
FROM gradeable AS g";
        }
        $query .= "
LEFT JOIN (
  SELECT *
  FROM electronic_gradeable
) AS eg ON eg.g_id=g.g_id
LEFT JOIN (
  SELECT
    g_id,
    array_agg(gc_is_peer) as array_gc_is_peer,
    array_agg(gc_id) as array_gc_id,
    array_agg(gc_title) AS array_gc_title,
    array_agg(gc_ta_comment) AS array_gc_ta_comment,
    array_agg(gc_student_comment) AS array_gc_student_comment,
    array_agg(gc_max_value) AS array_gc_max_value,
    array_agg(gc_is_text) AS array_gc_is_text,
    array_agg(gc_is_extra_credit) AS array_gc_is_extra_credit,
    array_agg(gc_order) AS array_gc_order,
    array_agg(array_gcm_id) AS array_array_gcm_id,
    array_agg(array_gc_id) AS array_array_gc_id,
    array_agg(array_gcm_points) AS array_array_gcm_points,
    array_agg(array_gcm_note) AS array_array_gcm_note,
    array_agg(array_gcm_order) AS array_array_gcm_order
  FROM
  (SELECT gc.*, gcm.array_gcm_id, gcm.array_gc_id, gcm.array_gcm_points, array_gcm_note, array_gcm_order
  FROM gradeable_component AS gc
  LEFT JOIN(
    SELECT
      gc_id,
      array_to_string(array_agg(gcm_id), ',') as array_gcm_id,
      array_to_string(array_agg(gc_id), ',') as array_gc_id,
      array_to_string(array_agg(gcm_points), ',') as array_gcm_points,
      array_to_string(array_agg(gcm_note), ',') as array_gcm_note,
      array_to_string(array_agg(gcm_order), ',') as array_gcm_order
    FROM gradeable_component_mark
    GROUP BY gc_id
  ) AS gcm
  ON gc.gc_id=gcm.gc_id) as gradeable_component
  GROUP BY g_id
) AS gc ON gc.g_id=g.g_id";
        if ($user_ids !== null) {
            $query .= "
LEFT JOIN (
  SELECT 
    in_gd.*,
    in_gcd.array_gcd_gc_id,
    in_gcd.array_gcd_score,
    in_gcd.array_gcd_component_comment,
    in_gcd.array_gcd_grader_id,
    in_gcd.array_gcd_graded_version,
    in_gcd.array_gcd_grade_time,
<<<<<<< HEAD
    in_gcd.array_array_gcm_mark,
=======
    in_gcd.array_array_gcm_id,
>>>>>>> 13f5f67c735907902a4e4051bdec1dbcdd90f3ef
    in_gcd.array_gcd_user_id,
    in_gcd.array_gcd_user_firstname,
    in_gcd.array_gcd_user_preferred_firstname,
    in_gcd.array_gcd_user_lastname,
    in_gcd.array_gcd_user_email,
    in_gcd.array_gcd_user_group
  FROM gradeable_data as in_gd
  LEFT JOIN (
    SELECT
      gcd.gd_id,
      array_agg(gc_id) AS array_gcd_gc_id,
      array_agg(gcd_score) AS array_gcd_score,
      array_agg(gcd_component_comment) AS array_gcd_component_comment,
      array_agg(gcd_grader_id) AS array_gcd_grader_id,
      array_agg(gcd_graded_version) AS array_gcd_graded_version,
      array_agg(gcd_grade_time) AS array_gcd_grade_time,
<<<<<<< HEAD
      array_agg(array_gcm_mark) AS array_array_gcm_mark,
=======
      array_agg(array_gcm_id) AS array_array_gcm_id,
>>>>>>> 13f5f67c735907902a4e4051bdec1dbcdd90f3ef
      array_agg(u.user_id) AS array_gcd_user_id,
      array_agg(u.user_firstname) AS array_gcd_user_firstname,
      array_agg(u.user_preferred_firstname) AS array_gcd_user_preferred_firstname,
      array_agg(u.user_lastname) AS array_gcd_user_lastname,
      array_agg(u.user_email) AS array_gcd_user_email,
      array_agg(u.user_group) AS array_gcd_user_group
    FROM(
<<<<<<< HEAD
        SELECT gcd.* , gcmd.array_gcm_mark
        FROM gradeable_component_data AS gcd 
        LEFT JOIN (
          SELECT gc_id, gd_id, array_to_string(array_agg(gcm_id), ',') as array_gcm_mark
          FROM gradeable_component_mark_data AS gcmd
          GROUP BY gc_id, gd_id, gd_id
        ) as gcmd
    ON gcd.gc_id=gcmd.gc_id AND gcd.gd_id=gcmd.gd_id
    WHERE array_gcm_mark IS NOT NULL
=======
        SELECT gcd.*, gcmd.array_gcm_id
        FROM gradeable_component_data AS gcd 
        LEFT JOIN (
          SELECT gc_id, gd_id, array_to_string(array_agg(gcm_id), ',') as array_gcm_id
          FROM gradeable_component_mark_data AS gcmd
          GROUP BY gc_id, gd_id
        ) as gcmd
    ON gcd.gc_id=gcmd.gc_id AND gcd.gd_id=gcmd.gd_id 
>>>>>>> 13f5f67c735907902a4e4051bdec1dbcdd90f3ef
    ) AS gcd
    INNER JOIN users AS u ON gcd.gcd_grader_id = u.user_id 
    GROUP BY gcd.gd_id
  ) AS in_gcd ON in_gd.gd_id = in_gcd.gd_id
) AS gd ON gd.gd_user_id = u.user_id AND g.g_id = gd.g_id
LEFT JOIN (
  SELECT
    egd.*,
    egv.active_version
  FROM electronic_gradeable_version AS egv, electronic_gradeable_data AS egd
  WHERE egv.active_version = egd.g_version AND egv.g_id = egd.g_id AND (egv.user_id = egd.user_id OR egv.team_id = egd.team_id)
) AS egd ON g.g_id = egd.g_id AND (u.user_id = egd.user_id OR u.user_id IN ( 
    SELECT
      t.user_id
    FROM gradeable_teams AS gt, teams AS t
    WHERE g.g_id = gt.g_id AND gt.team_id = t.team_id AND t.team_id = egd.team_id AND t.state = 1)
) 
LEFT JOIN (
  SELECT
    g_id,
    user_id,
    team_id,
    count(*) as highest_version
  FROM electronic_gradeable_data
  GROUP BY g_id, user_id, team_id
) AS egv ON g.g_id = egv.g_id AND (u.user_id = egv.user_id OR u.user_id IN (
    SELECT
      t.user_id
    FROM gradeable_teams AS gt, teams AS t
    WHERE g.g_id = gt.g_id AND gt.team_id = t.team_id AND t.team_id = egv.team_id AND t.state = 1)
)";
        }

        $where = array();
        if ($g_ids !== null) {
            $where[] = "g.g_id IN ({$g_ids_query})";
        }
        if ($user_ids !== null) {
            $where[] = "u.user_id IN ({$users_query})";
        }
        if ($g_type !== null) {
            $where[] = "g.g_gradeable_type IN ({$g_type_query})";
        }
        if (count($where) > 0) {
            $query .= "
WHERE ".implode(" AND ", $where);
        }
        if ($user_ids !== null) {
          $query .= "
ORDER BY u.{$section_key}, {$sort_key}";
        }


        $this->course_db->query($query, $params);

        foreach ($this->course_db->rows() as $row) {
            $user = (isset($row['user_id']) && $row['user_id'] !== null) ? new User($this->core, $row) : null;
            $return[] = new Gradeable($this->core, $row, $user);
        }

        return $return;
    }

    // Moved from class LateDaysCalculation on port from TAGrading server.  May want to incorporate late day information into gradeable object rather than having a separate query 
    public function getLateDayUpdates() {
        $this->course_db->query("SELECT * FROM late_days");
        return $this->course_db->rows();
    }
    
    // Moved from class LateDaysCalculation on port from TAGrading server.  May want to incorporate late day information into gradeable object rather than having a separate query
    public function getLateDayInformation() {
        $params = array(300);
        $query = "SELECT
                      submissions.*
                      , coalesce(late_day_exceptions, 0) extensions
                      , greatest(0, ceil((extract(EPOCH FROM(coalesce(submission_time, eg_submission_due_date) - eg_submission_due_date)) - (?*60))/86400):: integer) as days_late
                    FROM
                      (
                        SELECT
                        base.g_id
                        , g_title
                        , base.assignment_allowed
                        , base.user_id
                        , eg_submission_due_date
                        , coalesce(active_version, -1) as active_version
                        , submission_time
                      FROM
                      (
                        --Begin BASE--
                        SELECT
                          g.g_id,
                          u.user_id,
                          g.g_title,
                          eg.eg_submission_due_date,
                          eg.eg_late_days AS assignment_allowed
                        FROM
                          users u
                          , gradeable g
                          , electronic_gradeable eg
                        WHERE
                          g.g_id = eg.g_id
                        --End Base--
                      ) as base
                    LEFT JOIN
                    (
                        --Begin Details--
                        SELECT
                          egv.g_id
                          , egv.user_id
                          , active_version
                          , g_version
                          , submission_time
                        FROM
                          electronic_gradeable_version egv INNER JOIN electronic_gradeable_data egd
                        ON
                          egv.active_version = egd.g_version
                          AND egv.g_id = egd.g_id
                          AND egv.user_id = egv.user_id
                        --WHERE egv.user_id = ?
                        GROUP BY  egv.g_id,egv.user_id, active_version, g_version, submission_time
                        --End Details--
                    ) as details
                    ON
                      base.user_id = details.user_id
                      AND base.g_id = details.g_id
                    ) 
                      AS submissions 
                      FULL OUTER JOIN 
                        late_day_exceptions AS lde 
                      ON submissions.g_id = lde.g_id 
                      AND submissions.user_id = lde.user_id";
        //Query database and return results.
        
        $this->course_db->query($query, $params);
        return $this->course_db->rows();
    }

    public function getUsersByRegistrationSections($sections) {
        $return = array();
        if (count($sections) > 0) {
            $query = implode(",", array_fill(0, count($sections), "?"));
            $this->course_db->query("SELECT * FROM users WHERE registration_section IN ({$query}) ORDER BY registration_section", $sections);
            foreach ($this->course_db->rows() as $row) {
                $return[] = new User($this->core, $row);
            }
        }
        return $return;
    }

    public function getTotalUserCountByRegistrationSections($sections) {
        $return = array();
        $params = array();
        $where = "";
        if (count($sections) > 0) {
            $where = "WHERE registration_section IN (".implode(",", array_fill(0, count($sections), "?")).")";
            $params = $sections;
        }
        $this->course_db->query("
SELECT count(*) as cnt, registration_section 
FROM users 
{$where}
GROUP BY registration_section 
ORDER BY registration_section", $params);
        foreach ($this->course_db->rows() as $row) {
            if ($row['registration_section'] === null) {
                $row['registration_section'] = "NULL";
            }
            $return[$row['registration_section']] = intval($row['cnt']);
        }
        return $return;
    }

    public function getTotalComponentCount($g_id) {
      $params=array($g_id);
      return $this->course_db->query("SELECT count(*) FROM gradeable_component WHERE g_id=?", $params);
    }

    public function getGradedComponentsCountByRegistrationSections($g_id, $sections) {
        $return = array();
        $params = array($g_id);
        $where = "";
        if (count($sections) > 0) {
            $where = "WHERE registration_section IN (".implode(",", array_fill(0, count($sections), "?")).")";
            $params = array_merge($params, $sections);
        }
        $this->course_db->query("
SELECT count(u.*) as cnt, u.registration_section
FROM users AS u
INNER JOIN (
  SELECT gd.* FROM gradeable_data AS gd LEFT JOIN gradeable_component_data AS gcd ON gcd.gd_id = gd.gd_id WHERE g_id=? GROUP BY gd.gd_id
) AS gd ON u.user_id = gd.gd_user_id
{$where}
GROUP BY u.registration_section
ORDER BY u.registration_section", $params);
        foreach ($this->course_db->rows() as $row) {
            if ($row['registration_section'] === null) {
                $row['registration_section'] = "NULL";
            }
            $return[$row['registration_section']] = intval($row['cnt']);
        }
        return $return;
    }

    public function getGradersForRegistrationSections($sections) {
        $return = array();
        $params = array();
        $where = "";
        if (count($sections) > 0) {
            $where = "WHERE sections_registration_id IN (" . implode(",", array_fill(0, count($sections), "?")) . ")";
            $params = $sections;
        }
        $this->course_db->query("
SELECT g.*, u.* 
FROM grading_registration AS g
LEFT JOIN (
  SELECT *
  FROM users
) AS u ON u.user_id = g.user_id
{$where}
ORDER BY g.sections_registration_id, g.user_id", $params);
        $user_store = array();
        foreach ($this->course_db->rows() as $row) {
            if ($row['sections_registration_id'] === null) {
                $row['sections_registration_id'] = "NULL";
            }
            if (!isset($return[$row['sections_registration_id']])) {
                $return[$row['sections_registration_id']] = array();
            }
            if (!isset($user_store[$row['user_id']])) {
                $user_store[$row['user_id']] = new User($this->core, $row);
            }
            $return[$row['sections_registration_id']][] = $user_store[$row['user_id']];
        }
        return $return;
    }

    public function getRotatingSectionsForGradeableAndUser($g_id, $user) {
        $this->course_db->query("SELECT sections_rotating_id FROM grading_rotating WHERE g_id=? AND user_id=?", array($g_id, $user));
        $return = array();
        foreach ($this->course_db->rows() as $row) {
            $return[] = $row['sections_rotating_id'];
        }
        return $return;
    }

    public function getUsersByRotatingSections($sections) {
        $return = array();
        if (count($sections) > 0) {
            $query = implode(",", array_fill(0, count($sections), "?"));
            $this->course_db->query("SELECT * FROM users WHERE rotating_section IN ({$query}) ORDER BY rotating_section", $sections);
            foreach ($this->course_db->rows() as $row) {
                $return[] = new User($this->core, $row);
            }
        }
        return $return;
    }

    public function getTotalUserCountByRotatingSections($sections) {
        $return = array();
        $where = "";
        $params = array();
        if (count($sections) > 0) {
            $where = "WHERE rotating_section IN (".implode(",", array_fill(0, count($sections), "?")).")";
            $params = $sections;
        }
        $this->course_db->query("
SELECT count(*) as cnt, rotating_section 
FROM users 
{$where}
GROUP BY rotating_section 
ORDER BY rotating_section", $params);
        foreach ($this->course_db->rows() as $row) {
            if ($row['rotating_section'] === null) {
                $row['rotating_section'] = "NULL";
            }
            $return[$row['rotating_section']] = intval($row['cnt']);
        }
        return $return;
    }

    public function getGradedComponentsCountByRotatingSections($g_id, $sections) {
        $return = array();
        $params = array($g_id);
        $where = "";
        if (count($sections) > 0) {
            $where = "WHERE rotating_section IN (".implode(",", array_fill(0, count($sections), "?")).")";
            $params = array_merge($params, $sections);
        }
        $this->course_db->query("
SELECT count(u.*) as cnt, u.rotating_section
FROM users AS u
INNER JOIN (
    SELECT gd.* FROM gradeable_data AS gd LEFT JOIN gradeable_component_data AS gcd ON gcd.gd_id = gd.gd_id WHERE g_id=? GROUP BY gd.gd_id
) AS gd ON u.user_id = gd.gd_user_id
{$where}
GROUP BY u.rotating_section
ORDER BY u.rotating_section", $params);
        foreach ($this->course_db->rows() as $row) {
            if ($row['rotating_section'] === null) {
                $row['rotating_section'] = "NULL";
            }
            $return[$row['rotating_section']] = intval($row['cnt']);
        }
        return $return;
    }

    public function getGradersForRotatingSections($g_id, $sections) {
        $return = array();
        $params = array($g_id);
        $where = "";
        if (count($sections) > 0) {
            $where = " AND sections_rotating_id IN (" . implode(",", array_fill(0, count($sections), "?")) . ")";
            $params = array_merge($params, $sections);
        }
        $this->course_db->query("
SELECT g.*, u.* 
FROM grading_rotating AS g
LEFT JOIN (
  SELECT *
  FROM users
) AS u ON u.user_id = g.user_id
WHERE g.g_id=? {$where}
ORDER BY g.sections_rotating_id, g.user_id", $params);
        $user_store = array();
        foreach ($this->course_db->rows() as $row) {
            if ($row['sections_rotating_id'] === null) {
                $row['sections_rotating_id'] = "NULL";
            }
            if (!isset($return[$row['sections_rotating_id']])) {
                $return[$row['sections_rotating_id']] = array();
            }
            if (!isset($user_store[$row['user_id']])) {
                $user_store[$row['user_id']] = new User($this->core, $row);
            }
            $return[$row['sections_rotating_id']][] = $user_store[$row['user_id']];
        }
        return $return;
    }

    public function getRegistrationSections() {
        $this->course_db->query("SELECT * FROM sections_registration ORDER BY sections_registration_id");
        return $this->course_db->rows();
    }

    public function getRotatingSections() {
        $this->course_db->query("SELECT * FROM sections_rotating ORDER BY sections_rotating_id");
        return $this->course_db->rows();
    }

    public function getGradeablesPastAndSection() {
        $this->course_db->query("
  SELECT
    gu.g_id, gu.user_id, gu.user_group, gr.sections_rotating_id, g_grade_start_date
  FROM (SELECT g.g_id, u.user_id, u.user_group, g_grade_start_date
          FROM (SELECT user_id, user_group FROM users WHERE user_group BETWEEN 1 AND 3) AS u CROSS JOIN (
            SELECT
              DISTINCT g.g_id,
              g_grade_start_date
            FROM gradeable AS g
            LEFT JOIN
              grading_rotating AS gr ON g.g_id = gr.g_id
            WHERE g_grade_by_registration = 'f') AS g ) as gu
        LEFT JOIN (
              SELECT
                g_id, user_id, array_agg(sections_rotating_id) as sections_rotating_id
              FROM
                grading_rotating
              GROUP BY
              g_id, user_id) AS gr ON gu.user_id=gr.user_id AND gu.g_id=gr.g_id
              ORDER BY user_group, user_id, g_grade_start_date");
        return $this->course_db->rows();
    }

    public function getRotatingSectionsGradeableIDS() {
        $this->course_db->query("SELECT g_id FROM gradeable WHERE g_grade_by_registration = 'f' ORDER BY g_grade_start_date ASC");
        return $this->course_db->rows();
    }

    public function getCountUsersRotatingSections() {
        $this->course_db->query("
SELECT rotating_section, count(*) as count
FROM users
WHERE (registration_section IS NOT NULL OR manual_registration)
GROUP BY rotating_section
ORDER BY rotating_section");
        return $this->course_db->rows();
    }

    public function getGradersForAllRotatingSections($gradeable_id) {
        $this->course_db->query("
    SELECT 
        u.user_id, array_agg(sections_rotating_id ORDER BY sections_rotating_id ASC) AS sections
    FROM 
        users AS u INNER JOIN grading_rotating AS gr ON u.user_id = gr.user_id
    WHERE 
        g_id=?
    AND 
        u.user_group BETWEEN 1 AND 3
    GROUP BY 
        u.user_id
    ",array($gradeable_id));
        return $this->course_db->rows();
    }

    public function getGradersFromUserType($user_type) {
        $this->course_db->query("SELECT user_id FROM users WHERE user_group=? ORDER BY user_id ASC", array($user_type));
        return $this->course_db->rows();
    }

    public function getCountNullUsersRotatingSections() {
        $this->course_db->query("
SELECT rotating_section, count(*) as count
FROM users
WHERE (registration_section IS NULL and NOT manual_registration) AND rotating_section IS NOT NULL
GROUP BY rotating_section
ORDER BY rotating_section");
        return $this->course_db->rows();
    }

    public function getRegisteredUserIdsWithNullRotating() {
        $this->course_db->query("
SELECT user_id 
FROM users 
WHERE
    (rotating_section IS NULL) and 
    (registration_section IS NOT NULL or manual_registration)
ORDER BY user_id ASC");
        return array_map(function($elem) { return $elem['user_id']; }, $this->course_db->rows());
    }

    public function getRegisteredUserIds() {
        $this->course_db->query("
SELECT user_id 
FROM users 
WHERE
    (registration_section IS NOT NULL) OR 
    (manual_registration)
ORDER BY user_id ASC");
        return array_map(function($elem) { return $elem['user_id']; }, $this->course_db->rows());
    }

    public function setAllUsersRotatingSectionNull() {
        $this->course_db->query("UPDATE users SET rotating_section=NULL");
    }

    public function setNonRegisteredUsersRotatingSectionNull() {
        $this->course_db->query("UPDATE users SET rotating_section=NULL WHERE registration_section IS NULL AND NOT manual_registration");
    }

    public function deleteAllRotatingSections() {
        $this->course_db->query("DELETE FROM sections_rotating");
    }

    public function getMaxRotatingSection() {
        $this->course_db->query("SELECT MAX(sections_rotating_id) as max FROM sections_rotating");
        $row = $this->course_db->row();
        return $row['max'];
    }

    public function insertNewRotatingSection($section) {
        $this->course_db->query("INSERT INTO sections_rotating (sections_rotating_id) VALUES(?)", array($section));
    }

    public function setupRotatingSections($graders, $gradeable_id) {
        $this->course_db->query("DELETE FROM grading_rotating WHERE g_id=?", array($gradeable_id));
        foreach ($graders as $grader=>$sections){
            foreach($sections as $i=>$section){
                $this->course_db->query("INSERT INTO grading_rotating(g_id, user_id, sections_rotating_id) VALUES(?,?,?)", array($gradeable_id,$grader,$section));
            }
        }
    }

    public function updateUsersRotatingSection($section, $users) {
        $update_array = array_merge(array($section), $users);
        $update_string = implode(",", array_pad(array(), count($users), "?"));
        $this->course_db->query("UPDATE users SET rotating_section=? WHERE user_id IN ({$update_string})", $update_array);
    }
    public function insertVersionDetails($g_id, $user_id, $team_id, $version, $timestamp) {
        $this->course_db->query("
INSERT INTO electronic_gradeable_data 
(g_id, user_id, team_id, g_version, autograding_non_hidden_non_extra_credit, autograding_non_hidden_extra_credit, 
autograding_hidden_non_extra_credit, autograding_hidden_extra_credit, submission_time) 

VALUES(?, ?, ?, ?, 0, 0, 0, 0, ?)", array($g_id, $user_id, $team_id, $version, $timestamp));
        if ($user_id === null) {
            $this->course_db->query("SELECT * FROM electronic_gradeable_version WHERE g_id=? AND team_id=?",
            array($g_id, $team_id));
        }
        else {
            $this->course_db->query("SELECT * FROM electronic_gradeable_version WHERE g_id=? AND user_id=?",
            array($g_id, $user_id));
        }
        $row = $this->course_db->row();
        if (!empty($row)) {
            $this->updateActiveVersion($g_id, $user_id, $team_id, $version);
        }
        else {
            $this->course_db->query("INSERT INTO electronic_gradeable_version (g_id, user_id, team_id, active_version) VALUES(?, ?, ?, ?)",
                array($g_id, $user_id, $team_id, $version));
        }
    }

    public function updateActiveVersion($g_id, $user_id, $team_id, $version) {
        if ($user_id === null) {
            $this->course_db->query("UPDATE electronic_gradeable_version SET active_version=? WHERE g_id=? AND team_id=?",
            array($version, $g_id, $team_id));
        }
        else {
            $this->course_db->query("UPDATE electronic_gradeable_version SET active_version=? WHERE g_id=? AND user_id=?",
            array($version, $g_id, $user_id));
        }  
    }

    public function insertGradeableData(Gradeable $gradeable) {
        $params = array($gradeable->getId(), $gradeable->getUser()->getId(),
                        $gradeable->getOverallComment());
        $this->course_db->query("INSERT INTO 
gradeable_data (g_id, gd_user_id, gd_overall_comment)
VALUES (?, ?, ?)", $params);
        return $this->course_db->getLastInsertId("gradeable_data_gd_id_seq");
    }

    public function updateGradeableData(Gradeable $gradeable) {
        $params = array($gradeable->getOverallComment(), $gradeable->getGdId());

        $this->course_db->query("UPDATE gradeable_data SET gd_overall_comment=? WHERE gd_id=?", $params);
    }

    public function insertGradeableComponentData($gd_id, GradeableComponent $component) {
        $params = array($component->getId(), $gd_id, $component->getScore(), $component->getComment(), $component->getGrader()->getId(), $component->getGradedVersion(), $component->getGradeTime()->format("Y-m-d H:i:s"));
        $this->course_db->query("
INSERT INTO gradeable_component_data (gc_id, gd_id, gcd_score, gcd_component_comment, gcd_grader_id, gcd_graded_version, gcd_grade_time) 
VALUES (?, ?, ?, ?, ?, ?, ?)", $params);
    }

    public function updateGradeableComponentData($gd_id, GradeableComponent $component) {
        $params = array($component->getScore(), $component->getComment(), $component->getGrader()->getId(), $component->getGradedVersion(), $component->getGradeTime()->format("Y-m-d H:i:s"), $component->getId(), $gd_id);
        $this->course_db->query("
UPDATE gradeable_component_data SET gcd_score=?, gcd_component_comment=?, gcd_grader_id=?, gcd_graded_version=?, gcd_grade_time=? WHERE gc_id=? AND gd_id=?", $params);
    }

    public function insertGradeableComponentMarkData($gd_id, $gc_id, GradeableComponentMark $mark) {
        $params = array($gc_id, $gd_id, $mark->getId());
        $this->course_db->query("
INSERT INTO gradeable_component_mark_data (gc_id, gd_id, gcm_id)
VALUES (?, ?, ?)", $params);
    }

    public function deleteGradeableComponentMarkData($gd_id, $gc_id, GradeableComponentMark $mark) {
        $params = array($gc_id, $gd_id, $mark->getId());
        $this->course_db->query("
DELETE FROM gradeable_component_mark_data WHERE gc_id=? AND gd_id=? AND gcm_id=?", $params);
    }

    public function insertGradeableComponentMarkData($gd_id, $gc_id, GradeableComponentMark $mark) {
        $params = array($gc_id, $gd_id, $mark->getId());
        $this->course_db->query("
INSERT INTO gradeable_component_mark_data (gc_id, gd_id, gcm_id)
VALUES (?, ?, ?)", $params);
    }

    public function deleteGradeableComponentMarkData($gd_id, $gc_id, GradeableComponentMark $mark) {
        $params = array($gc_id, $gd_id, $mark->getId());
        $this->course_db->query("
DELETE FROM gradeable_component_mark_data WHERE gc_id=? AND gd_id=? AND gcm_id=?");
    }

    public function createNewGradeable(Gradeable $gradeable) {
        $params = array($gradeable->getId(), $gradeable->getName(), $gradeable->getInstructionsUrl(), $gradeable->getTaInstructions(), var_export($gradeable->getTeamAssignment(),true), $gradeable->getType(), var_export($gradeable->getGradeByRegistration(), true), $gradeable->getTaViewDate()->format('Y/m/d H:i:s'), $gradeable->getGradeStartDate()->format('Y/m/d H:i:s'), $gradeable->getGradeReleasedDate()->format('Y/m/d H:i:s'), $gradeable->getMinimumGradingGroup(), $gradeable->getBucket());
        $this->course_db->query("
INSERT INTO gradeable(g_id, g_title, g_instructions_url,g_overall_ta_instructions, g_team_assignment, g_gradeable_type, g_grade_by_registration, g_ta_view_start_date, g_grade_start_date,  g_grade_released_date,  g_min_grading_group, g_syllabus_bucket) 
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", $params);
        if ($gradeable->getType() === GradeableType::ELECTRONIC_FILE) {
            $params = array($gradeable->getId(), $gradeable->getOpenDate()->format('Y/m/d H:i:s'), $gradeable->getDueDate()->format('Y/m/d H:i:s'), var_export($gradeable->getIsRepository(), true), $gradeable->getSubdirectory(), var_export($gradeable->getTaGrading(), true), $gradeable->getConfigPath(), $gradeable->getLateDays(), $gradeable->getPointPrecision(), var_export($gradeable->getPeerGrading(), true), $gradeable->getPeerGradeSet());
            $this->course_db->query("
INSERT INTO electronic_gradeable(g_id, eg_submission_open_date, eg_submission_due_date, eg_is_repository, 
eg_subdirectory, eg_use_ta_grading, eg_config_path, eg_late_days, eg_precision, eg_peer_grading, eg_peer_grade_set) 
VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", $params);
>>>>>>> 13f5f67c735907902a4e4051bdec1dbcdd90f3ef
        }
    }

    public function updateGradeable(Gradeable $gradeable) {
        $params = array($gradeable->getName(), $gradeable->getInstructionsUrl(), $gradeable->getTaInstructions(), var_export($gradeable->getTeamAssignment(),true), $gradeable->getType(), var_export($gradeable->getGradeByRegistration(), true), $gradeable->getTaViewDate()->format('Y/m/d H:i:s'), $gradeable->getGradeStartDate()->format('Y/m/d H:i:s'), $gradeable->getGradeReleasedDate()->format('Y/m/d H:i:s'), $gradeable->getMinimumGradingGroup(), $gradeable->getBucket(), $gradeable->getId());
        $this->course_db->query("
UPDATE gradeable SET g_title=?, g_instructions_url=?, g_overall_ta_instructions=?, g_team_assignment=?, 
g_gradeable_type=?, g_grade_by_registration=?, g_ta_view_start_date=?, g_grade_start_date=?, 
g_grade_released_date=?, g_min_grading_group=?, g_syllabus_bucket=? WHERE g_id=?", $params);
        if ($gradeable->getType() === 0) {
          $params = array($gradeable->getOpenDate()->format('Y/m/d H:i:s'), $gradeable->getDueDate()->format('Y/m/d H:i:s'), var_export($gradeable->getIsRepository(), true), $gradeable->getSubdirectory(), var_export($gradeable->getTaGrading(), true), $gradeable->getConfigPath(), $gradeable->getLateDays(), $gradeable->getPointPrecision(), var_export($gradeable->getPeerGrading(), true), $gradeable->getPeerGradeSet(), $gradeable->getId());
          $this->course_db->query("
UPDATE electronic_gradeable SET eg_submission_open_date=?, eg_submission_due_date=?, eg_is_repository=?, 
eg_subdirectory=?, eg_use_ta_grading=?, eg_config_path=?, eg_late_days=?, eg_precision=?, eg_peer_grading=?, eg_peer_grade_set=? WHERE g_id=?", $params);
        }
    }

    public function createNewGradeableComponent(GradeableComponent $component, Gradeable $gradeable) {
        $params = array($gradeable->getId(), $component->getTitle(), $component->getTaComment(), $component->getStudentComment(), $component->getMaxValue(), var_export($component->getIsText(), true), var_export($component->getIsExtraCredit(), true), $component->getOrder(), var_export($component->getIsPeer(), true));
        $this->course_db->query("
INSERT INTO gradeable_component(g_id, gc_title, gc_ta_comment, gc_student_comment, gc_max_value, 
gc_is_text, gc_is_extra_credit, gc_order, gc_is_peer) 
VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)", $params);
    }   

    public function updateGradeableComponent(GradeableComponent $component) {
        $params = array($component->getTitle(), $component->getTaComment(), $component->getStudentComment(), $component->getMaxValue(), var_export($component->getIsText(), true), var_export($component->getIsExtraCredit(), true), $component->getOrder(), var_export($component->getIsPeer()), $component->getId());
        $this->course_db->query("
UPDATE gradeable_component SET gc_title=?, gc_ta_comment=?, gc_student_comment=?, gc_max_value=?, gc_is_text=?, gc_is_extra_credit=?, gc_order=?, gc_is_peer=? WHERE gc_id=?", $params);
    }

    public function deleteGradeableComponent(GradeableComponent $component) {
        $this->course_db->query("DELETE FROM gradeable_component_data WHERE gc_id=?",array($component->getId()));
        $this->course_db->query("DELETE FROM gradeable_component WHERE gc_id=?", array($component->getId()));
    }

    public function createGradeableComponentMark(GradeableComponentMark $mark) {
        $params = array($mark->getGcId(), $mark->getPoints(), $mark->getNote(), $mark->getOrder());

        $this->course_db->query("
INSERT INTO gradeable_component_mark (gc_id, gcm_points, gcm_note, gcm_order)
VALUES (?, ?, ?, ?)", $params);
        return $this->course_db->getLastInsertId();
    }

    public function updateGradeableComponentMark(GradeableComponentMark $mark) {
        $params = array($mark->getGcId(), $mark->getPoints(), $mark->getNote(), $mark->getOrder(), $mark->getId());

        $this->course_db->query("
UPDATE gradeable_component_mark SET gc_id=?, gcm_points=?, gcm_note=?, gcm_order=?
WHERE gcm_id=?", $params);
    }

    public function deleteGradeableComponentMark(GradeableComponentMark $mark) {
        $this->course_db->query("DELETE FROM gradeable_component_mark_data WHERE gcm_id=?",array($mark->getId()));
        $this->course_db->query("DELETE FROM gradeable_component_mark WHERE gcm_id=?", array($mark->getId()));
    }

    public function getGradeableData($gradeable_id) {
        $this->course_db->query("SELECT * FROM gradeable WHERE g_id=?",array($gradeable_id));
        $old_gradeable = $this->course_db->row();
        $this->course_db->query("SELECT * FROM gradeable_component WHERE g_id=? ORDER BY gc_order", array($gradeable_id));
        $old_components = json_encode($this->course_db->rows());
        if ($old_gradeable['g_gradeable_type']==2){
            $this->course_db->query("SELECT COUNT(*) AS cnt FROM gradeable AS g INNER JOIN gradeable_component AS gc 
                        ON g.g_id=gc.g_id WHERE g.g_id=? AND gc_is_text='false'", array($gradeable_id));
            $num_numeric = $this->course_db->row()['cnt'];
            $this->course_db->query("SELECT COUNT(*) AS cnt FROM gradeable AS g INNER JOIN gradeable_component AS gc 
                        ON g.g_id=gc.g_id WHERE g.g_id=? AND gc_is_text='true'", array($gradeable_id));
            $num_text = $this->course_db->row()['cnt'];
        }

        $this->course_db->query("SELECT COUNT(*) as cnt FROM gradeable AS g INNER JOIN gradeable_component AS gc ON g.g_id=gc.g_id 
                    INNER JOIN gradeable_component_data AS gcd ON gcd.gc_id=gc.gc_id WHERE g.g_id=?",array($gradeable_id));
        $has_grades= $this->course_db->row()['cnt'];

        if($old_gradeable['g_gradeable_type']==0){
            //get the electronic file stuff
            $this->course_db->query("SELECT * FROM electronic_gradeable WHERE g_id=?", array($gradeable_id));
            $electronic_gradeable = $this->course_db->row();
            $use_ta_grading = $electronic_gradeable['eg_use_ta_grading'];

            $initial_ta_grading_compare_date = "Due Date (+ max allowed late days)";

            if ($use_ta_grading) {
              $initial_grades_released_compare_date = "TA Grading Open Date";
            } else {
              $initial_grades_released_compare_date = "Due Date";
            }

            $is_repository = $electronic_gradeable['eg_is_repository'];
            $late_days = $electronic_gradeable['eg_late_days'];
            $this->course_db->query("SELECT gc_title, gc_ta_comment, gc_student_comment, gc_max_value, gc_is_extra_credit, gc_is_peer FROM gradeable_component 
                        WHERE g_id=? GROUP BY gc_id ORDER BY gc_order ASC",array($gradeable_id));
            $tmp_questions = $this->course_db->rows();
            $old_questions = array();
            if ($use_ta_grading) {
                foreach($tmp_questions as $question){

                    array_push($old_questions, array('question_message' => $question['gc_title'],
                                                    'question_grading_note' => $question['gc_ta_comment'],
                                                    'student_grading_note'  => $question['gc_student_comment'],
                                                    'question_total'        => $question['gc_max_value'],
                                                    'question_extra_credit' => $question['gc_is_extra_credit'],
                                                    'peer_component'        => $question['gc_is_peer']));
                }
            }
        } else {
         // numeric or checkpoint
         $initial_ta_grading_compare_date = "TA Beta Testing Date";
         $initial_grades_released_compare_date = "TA Grading Open Date";
        }
        if ($old_gradeable['g_gradeable_type']==0) {
            $data = array($old_gradeable, $old_components, $has_grades, $electronic_gradeable, $initial_grades_released_compare_date, 
                $old_questions);
        }
        if ($old_gradeable['g_gradeable_type']==1) {
            $data = array($old_gradeable, $old_components, $has_grades, $initial_ta_grading_compare_date, $initial_grades_released_compare_date);
        }
        if ($old_gradeable['g_gradeable_type']==2) {
            $data = array($old_gradeable, $old_components, $has_grades, $num_numeric, $num_text, $initial_ta_grading_compare_date, 
                $initial_grades_released_compare_date);
        }

        return $data;
    }

    public function updateUserViewedDate(Gradeable $gradeable) {
        if ($gradeable->getGdId() !== null) {
            $this->course_db->query("UPDATE gradeable_data SET gd_user_viewed_date = NOW() WHERE gd_id=?",
                array($gradeable->getGdId()));
        }
    }

    public function getNumberRotatingSections() {
        $this->course_db->query("SELECT COUNT(*) AS cnt FROM sections_rotating");
        return $this->course_db->row()['cnt'];
    }

    public function getSession($session_id) {
        $this->submitty_db->query("SELECT * FROM sessions WHERE session_id=?", array($session_id));
        return $this->submitty_db->row();
    }

    public function updateSessionExpiration($session_id) {
        $this->submitty_db->query("UPDATE sessions SET session_expires=(current_timestamp + interval '336 hours') 
                                   WHERE session_id=?", array($session_id));
    }

    public function getAllGradeablesIdsAndTitles() {
        $this->course_db->query("SELECT g_id, g_title FROM gradeable ORDER BY g_title ASC");
        return $this->course_db->rows();
    }
    

    public function newSession($session_id, $user_id, $csrf_token) {
        $this->submitty_db->query("INSERT INTO sessions (session_id, user_id, csrf_token, session_expires) 
                                   VALUES(?,?,?,current_timestamp + interval '336 hours')",
            array($session_id, $user_id, $csrf_token));

    }

    public function removeExpiredSessions() {
        $this->submitty_db->query("DELETE FROM sessions WHERE session_expires < current_timestamp");
    }

    public function removeSessionById($session_id) {
        $this->submitty_db->query("DELETE FROM sessions WHERE session_id=?", array($session_id));
    }
    
    public function getAllGradeablesIds() {
        $this->course_db->query("SELECT g_id FROM gradeable ORDER BY g_id");
        return $this->course_db->rows();
    }
    public function getAllElectronicGradeablesIds() {
        $this->course_db->query("SELECT g_id, g_title FROM gradeable WHERE g_gradeable_type=0 ORDER BY g_grade_released_date DESC");
        return $this->course_db->rows();
    }
      
    public function newTeam($g_id, $user_id) {
        $this->course_db->query("SELECT * FROM gradeable_teams ORDER BY team_id");
        $team_id_prefix = count($this->course_db->rows());
        $team_id = "{$team_id_prefix}_{$user_id}";
        $this->course_db->query("INSERT INTO gradeable_teams (team_id, g_id) VALUES(?,?)", array($team_id, $g_id));
        $this->course_db->query("INSERT INTO teams (team_id, user_id, state) VALUES(?,?,1)", array($team_id, $user_id));
    }

    public function newTeamInvite($team_id, $user_id) {
        $this->course_db->query("INSERT INTO teams (team_id, user_id, state) VALUES(?,?,0)", array($team_id, $user_id,));
    }

    public function newTeamMember($team_id, $user_id) {
        $this->course_db->query("INSERT INTO teams (team_id, user_id, state) VALUES(?,?,1)", array($team_id, $user_id,));
    }

    public function removeTeamUser($g_id, $user_id) {
        $this->course_db->query("
          DELETE FROM teams AS t
          USING gradeable_teams AS gt
          WHERE gt.g_id=? AND gt.team_id = t.team_id AND t.user_id=?",
          array($g_id, $user_id));
    }

    public function getTeamsByGradeableId($g_id) {
        $this->course_db->query("
          SELECT gt.team_id, t.user_id, t.state
          FROM gradeable_teams AS gt 
          LEFT JOIN (
            SELECT *
            FROM teams
          ) AS t ON gt.team_id=t.team_id
          WHERE g_id=?
          ORDER BY team_id",
          array($g_id));

        $team_rows = array();
        foreach($this->course_db->rows() as $row) {
            if (!isset($team_rows[$row['team_id']])){
                $team_rows[$row['team_id']] = array();
            }
            $team_rows[$row['team_id']][] = $row;
        }
        $teams = array();
        foreach($team_rows as $team_row) {
            $teams[] = new Team($this->core, $team_row);
        }
        return $teams;
    }

    public function getTeamByUserId($g_id, $user_id) {
        $this->course_db->query("
          SELECT team_id, user_id, state
          FROM gradeable_teams NATURAL JOIN teams
          WHERE g_id=? AND team_id IN (
            SELECT team_id
            FROM teams
            WHERE user_id=?)",
          array($g_id, $user_id));

        if (count($this->course_db->rows()) === 0) {
            return null;
        }
        else {
            $team = new Team($this->core, $this->course_db->rows());
            return $team;
        }
    }

    public function getUsersWithLateDays() {
      $this->course_db->query("
        SELECT u.user_id, user_firstname, user_preferred_firstname, 
          user_lastname, allowed_late_days, since_timestamp::timestamp::date
        FROM users AS u
        FULL OUTER JOIN late_days AS l
          ON u.user_id=l.user_id
        WHERE allowed_late_days IS NOT NULL
          AND allowed_late_days>0
        ORDER BY
          user_email ASC, since_timestamp DESC;");

      $return = array();
      foreach($this->course_db->rows() as $row){
        $return[] = new SimpleLateUser($this->core, $row);
      }
      return $return;
    }

    public function getUsersWithExtensions($gradeable_id) {
      $this->course_db->query("
        SELECT u.user_id, user_firstname,
          user_preferred_firstname, user_lastname, late_day_exceptions
        FROM users as u
        FULL OUTER JOIN late_day_exceptions as l
          ON u.user_id=l.user_id
        WHERE g_id=?
          AND late_day_exceptions IS NOT NULL
          AND late_day_exceptions>0
        ORDER BY user_email ASC;", array($gradeable_id));

      $return = array();
      foreach($this->course_db->rows() as $row){
        $return[] = new SimpleLateUser($this->core, $row);
      }
      return $return;
    }

    public function updateLateDays($user_id, $timestamp, $days){
        $this->course_db->query("
          UPDATE late_days
          SET allowed_late_days=?
          WHERE user_id=?
            AND since_timestamp=?", array($days, $user_id, $timestamp));
        if(count($this->course_db->rows())==0){
          $this->course_db->query("
            INSERT INTO late_days
            (user_id, since_timestamp, allowed_late_days)
            VALUES(?,?,?)", array($user_id, $timestamp, $days));
        }
    }

    public function updateExtensions($user_id, $g_id, $days){
        $this->course_db->query("
          UPDATE late_day_exceptions
          SET late_day_exceptions=?
          WHERE user_id=?
            AND g_id=?;", array($days, $user_id, $g_id));
        if(count($this->course_db->rows())==0){
          $this->course_db->query("
            INSERT INTO late_day_exceptions
            (user_id, g_id, late_day_exceptions)
            VALUES(?,?,?)", array($user_id, $g_id, $days));
        }
    }
    
    public function getPeerGradingAssignNumber($gradeable_id) {
        $this->course_db->query("SELECT eg_peer_grade_set FROM electronic_gradeable WHERE g_id=?", array($gradeable_id));
        return $this->course_db->row()['eg_peer_grade_set'];
    }
    
    public function clearPeerGradingAssignments($gradeable_id) {
        $this->course_db->query("DELETE FROM peer_assign WHERE g_id=?", array($gradeable_id));
    }
    
    public function insertPeerGradingAssignment($grader, $student, $gradeable_id) {
        $this->course_db->query("INSERT INTO peer_assign(grader_id, user_id, g_id) VALUES (?,?,?)", array($grader, $student, $gradeable_id));
    }
}

