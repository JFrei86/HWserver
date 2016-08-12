<?php

//Author: Peter Bailie, Systems Programmer, RPI Computer Science, August 2016

/* MAIN ===================================================================== */

include "../header.php";

check_administrator();

/* Process ------------------------------------------------------------------ */

$view = new local_view();

//$state affects what's displayed in browser.
//Default state.
$state = "";

//Validate submission
//Is Student ID submitted (required!)
if (isset($_POST['student_id']) && $_POST['student_id'] !== "") {

	//Is this a lookup or upsert?  (determined in *'d fields are filled or not)
	if ((isset($_POST['first_name']) && $_POST['first_name'] === "") &&
	    (isset($_POST['last_name'])  && $_POST['last_name']  === "") &&
	    (isset($_POST['email'])      && $_POST['email']      === "")) {

		//Do DB lookup for student by Student ID
		$row = lookup_student_in_db($_POST['student_id']);
		if ($row !== "") {
			$view->fill_form($row);		
		} else {
			$state = "student_not_found";
		}		
	} else {
		//Do form data validation.
		//No validation on student id as pattern varies among Universities.
		//No validation on checkbox as only possible values are on (true) or
		//   off (false).
		//Email regex should match most cases, including unusual TLDs and
		//      IPv4 address domains.
		if (preg_match("~^[a-zA-Z.'`\- ]+$~",                               $_POST['first_name']) &&
		    preg_match("~^[a-zA-Z.'`\- ]+$~",                               $_POST['last_name'] ) &&
		    preg_match("~^[a-zA-Z0-9._\-]+@[a-zA-Z0-9.\-]+.[a-zA-Z0-9]+$~", $_POST['email']     ) &&
		    preg_match("~^$|^[0-9]+$~",                                     $_POST['r_section'] )) {
		    $is_validated = true;
		} else {
			$is_validated = false;
		}

		if ($is_validated) {
			//upsert's argument expects 2D array
			upsert(array( array( $_POST['student_id'],
			                     $_POST['first_name'],
			                     $_POST['last_name'],
			                     $_POST['email'],
			                    ($_POST['r_section'] === "")   ? "NULL" : $_POST['r_section'],
		                        ($_POST['is_manual'] === "on") ? "TRUE" : "FALSE" )));
		} else {
			$state = 'invalid_student_info';
		}
	}
}

//display
$view->display($state);

/* END Process -------------------------------------------------------------- */

include "../footer.php";
exit;

/* END MAIN ================================================================= */

function lookup_student_in_db($student_id) {

	$student_group = '4';

	//TODO: "SELECT user_is_manual FROM users" when DB is updated so this data
	//      is returned to function caller.
	$sql = <<<SQL
SELECT
	user_id,
	user_firstname,
	user_lastname,
	user_email,
	registration_section
FROM users
WHERE user_id=?
AND user_group=?
SQL;

		\lib\Database::query($sql, array($student_id, $student_group));
		return \lib\Database::row();
}

/* END FUNCTION lookup_student_in_db() ====================================== */

function upsert(array $data) {
//IN:  Data to be "upserted" as 2D array.
//OUT: No return.  This is assumed to work.  (Server should throw an exception
//     if this process fails)
//PURPOSE:  "Update/Insert" data into the database.  Code capable of "batch"
//          upserts.

/* -----------------------------------------------------------------------------
 * This SQL code was adapted from upsert discussion on Stack Overflow and is
 * meant to be compatible with PostgreSQL prior to v9.5.
 *
 * 	q.v. http://stackoverflow.com/questions/17267417/how-to-upsert-merge-insert-on-duplicate-update-in-postgresql
 * -------------------------------------------------------------------------- */
 
/* -----------------------------------------------------------------------------
 * SUGGESTION: For future-facing maintenance, maintain this code for batch 
 *             transactions even though this page currently transacts only 
 *             single rows of information.
 * -------------------------------------------------------------------------- */

	$student_group = "4";
	$sql = array();
	
	//TEMPORARY table to hold all new values that will be "upserted"
	/* *** NOTE: is_manual property is not yet active until DB is updated *** */
	$sql['temp_table'] = <<<SQL
CREATE TEMPORARY TABLE temp
	(student_id VARCHAR(255),
	 first_name VARCHAR(255),
	 last_name  VARCHAR(255),
	 email      VARCHAR(255),
	 user_group INTEGER,
	 r_section  INTEGER,
	 is_manual  BOOLEAN)	
ON COMMIT DROP;
SQL;

	//INSERT new data into temporary table -- prepares all data to be upserted
	//in a single DB transaction.
	//NOTE: hard coded value 4 is used in temp.group and user.usergroup to
	//      identify students.
	for ($i=0; $i<count($data); $i++) {
		$sql["data_{$i}"] = <<<SQL
INSERT INTO temp VALUES (?,?,?,?,{$student_group},?,?);
SQL;
	}

	//LOCK will prevent sharing collisions while upsert is in process.
	$sql['lock'] = <<<SQL
LOCK TABLE users IN EXCLUSIVE MODE;
SQL;

	//This portion ensures that UPDATE will only occur when a record already exists.
	//TO-DO: "SET users.user_is_manual=temp.is_manual" once DB is updated.
	$sql['update'] = <<<SQL
UPDATE users
SET
	user_firstname=temp.first_name,
	user_lastname=temp.last_name,
	user_email=temp.email,
	registration_section=temp.r_section
FROM temp
WHERE users.user_id=temp.student_id
	AND users.user_group=temp.user_group
SQL;

	//This portion ensures that INSERT will only occur when data record is new.
	//TO-DO: "INSERT users.user_is_manual SELECT temp.is_manual" once DB is updated.
	$sql['insert'] = <<<SQL
INSERT INTO users
	(user_id,
	 user_firstname,
	 user_lastname,
	 user_email,
	 user_group,
	 registration_section)
SELECT
	temp.student_id,
	temp.first_name,
	temp.last_name,
	temp.email,
	temp.user_group,
	temp.r_section
FROM temp 
LEFT OUTER JOIN users
	ON users.user_id=temp.student_id
WHERE users.user_id is NULL
SQL;

	//Begin DB transaction
	\lib\Database::beginTransaction();
	\lib\Database::query($sql['temp_table']);
	
	foreach ($data as $index => $record) {
		\lib\Database::query($sql["data_{$index}"], $record);
	}
	
	\lib\Database::query($sql['lock']);
	\lib\Database::query($sql['update']);
	\lib\Database::query($sql['insert']);
	\lib\Database::commit();
	//All Done!
	//Server will throw exception if there is a problem with DB access.
}

/* END FUNCTION upsert() ==================================================== */

class local_view {
//View class for admin-latedays.php

	//Properties
	//Class constants to represent unicode styled X and checkmark symbols.
	const UTF8_STYLED_X  = "&#x2718";
	const UTF8_CHECKMARK = "&#x2714";

	//HTML data to be sent to browser
	static private $view;
	
	//Constructor
	public function __construct() {
		//Class constants cannot be expanded in strings with {}
		//So they are copied here for LOCAL use.
		$utf8_styled_x  = self::UTF8_STYLED_X;
		$utf8_checkmark = self::UTF8_CHECKMARK;

		self::$view = array();
		
		self::$view['head'] = <<<HTML
<div id="container" style="width:100%; margin-top:40px;">
<div class="modal hide fade in" style="display:block; margin-top:5%; z-index:100;">
<div class="modal-header">
<h3>Review Individual Student Enrollment</h3>
</div>
<div class="modal-body" style="padding-top:10px; padding-bottom:10px;">
HTML;

		self::$view['tail'] = <<<HTML
</div>
</div>
</div>
HTML;

		self::$view['student_not_found'] = <<<HTML
<p style="margin:0; padding-bottom:20px;"><em style="color:red; font-weight:bold; font-style:normal;">
{$utf8_styled_x} Student not found.</em>
HTML;

		self::$view['invalid_student_info'] = <<<HTML
<p style="margin:0; padding-bottom:20px;"><em style="color:red; font-weight:bold; font-style:normal;">
{$utf8_styled_x} Invalid student information.</em>
HTML;

		self::$view['upsert_done'] = <<<HTML
<p style="margin:0; padding-bottom:20px;"><em style="color:green; font-weight:bold; font-style:normal;">
{$utf8_checkmark} Late days are updated.</em>
HTML;

		//Build form with default values
		self::fill_form();
	}
	
/* END CLASS CONSTRUCTOR ---------------------------------------------------- */	

	public function fill_form($db_data = null) {
	//IN:  data from database used to build form
	//OUT: no return, although form data is propogated as a class property
	//PURPOSE: Craft HTML required to display the form.
	//NOTE:    Instead of creating a table to display a student's info, the info
	//         is filled into the form for easy and quick tweaking.

	//$db_data expected indices:
	//[0]: student id
	//[1]: student first name
	//[2]: student last name
	//[3]: student email
	//[4]: student registered section
	//[5]: student "is manual" boolean flag

		//validate data parameters and/or set defaults.
		if (!is_array($db_data)) {
			$db_data = array("", "", "" , "", "", true);
		} else {
			//Some fields may be OK, and others may need to be set to default.
			//Check each individually to be thorough.
			for ($i = 0; $i<=4; $i++) {
				if (!isset($db_data[$i]) || $db_data[$i] == 'null') {
					$db_data[$i] = "";
				}
			}

			if (!isset($db_data[5]) || $db_data[5] == 'on') {
				$db_data[5] = true;
			}
		}

		//Build form
		//Determine if is_manual checkbox should be checked by default
		$is_checked = ($db_data[5]) ? "checked" : "";
		
		//Construct rest of form.  Note string expansions in HTML: {}
		self::$view['form'] = <<<HTML
<form action="admin-single-student-review.php" method="POST" enctype="multipart/form-data">
<div style="width:30%; display:block;">Student ID:<br><input type="text" name="student_id" value="{$db_data[0]}" style="width:95%;"></div>
<p>To only lookup a student's enrollment, leave blank all fields marked <span style="color:red;">*</span>.
<div style="width:30%; display:inline-block; vertical-align:top; padding-right:10px;">First Name: <span style="color:red;">*</span><br><input type="text" name="first_name" value="{$db_data[1]}" style="width:95%;"></div>
<div style="width:30%; display:inline-block; vertical-align:top; padding-right:10px;">Last Name: <span style="color:red;">*</span><br><input type="text" name="last_name" value="{$db_data[2]}" style="width:95%;"></div>
<div style="width:30%; display:inline-block; vertical-align:top; padding-right:10px;">Email: <span style="color:red;">*</span><br><input type="text" name="email" value="{$db_data[3]}" style="width:95%;"></div>
<div style="width:30%; display:inline-block; vertical-align:top; padding-right:10px;">Registered Section:<br><input type="text" name="r_section" value="{$db_data[4]}" style="width:95%;"></div>
<div style="width:50%; display:inline-block; vertical-align:top; padding-right:5px;"><p><input type="checkbox" name="is_manual" style="position:inherit; text-align:center; padding-top:1em;" {$is_checked}> Manually Registered Student<br>
	<p><span style="color:red;">*</span> Required <span style="font-style:italic;">only</span> for add/updating student.</div>
<div style="display:inline-block; vertical-align:top; padding-top:1.5em;"><input type="submit" name="submit" value="Submit"></div>
</form>
HTML;

}
	
/* END CLASS METHOD fill_form() --------------------------------------------- */

	public function display($state) {
	//IN:  Current "display state" determined in MAIN process
	//OUT: No return, although ALL crafted HTML is sent to browser
	//PURPOSE:  Display appropriate page contents.
	
		switch($state) {
		case 'student_not_found':
			echo self::$view['head']              .
			     self::$view['form']              . 
			     self::$view['student_not_found'] .
			     self::$view['tail'];
			break;
		case 'invalid_student_info':
			echo self::$view['head']                 .
			     self::$view['form']                 . 
			     self::$view['invalid_student_info'] . 
			     self::$view['tail'];
			break;
		case 'upsert_done':
			echo self::$view['head']        .
			     self::$view['form']        . 
			     self::$view['upsert_done'] . 
			     self::$view['tail'];
			break;
		default:
			echo self::$view['head'] .
			     self::$view['form'] . 
			     self::$view['tail'];
			break;
		}
	}
}
/* END CLASS METHOD display() ----------------------------------------------- */
/* END CLASS local_view ===================================================== */
/* EOF ====================================================================== */
?>