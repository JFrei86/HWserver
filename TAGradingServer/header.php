<?php

// Prevent back button from showing sensitive cached content after logout.
header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1.
header('Pragma: no-cache'); // HTTP 1.0.
header('Expires: 0'); // Proxies.
require_once __DIR__."/toolbox/functions.php";

print <<<HTML
<!DOCTYPE html>
<html>

	<head>
		<meta http-equiv="content-type" content="text/html;charset=UTF-8"/>
		<title>$COURSE_NAME Grading</title>
		<meta name="description" content="CONFIDENTIAL: RPI Grading"/>
	    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>

        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
        <meta http-equiv="Pragma" content="no-cache" />
        <meta http-equiv="Expires" content="0" />

HTML;
if (__DEBUG__) {
    print <<<HTML
        <link rel="shortcut icon" type="image/x-icon" href="{$BASE_URL}/toolbox/include/custom/img/favicon_debug.ico?v=2"/>

HTML;
}
else {
    print <<<HTML
		<link rel="shortcut icon" type="image/x-icon" href="{$BASE_URL}/toolbox/include/custom/img/favicon.ico"/>

HTML;
}
print <<<HTML

		<link type="text/css" rel="stylesheet" href="{$BASE_URL}/toolbox/include/bootstrap/css/bootstrap.min.css" />
		<link type="text/css" rel="stylesheet" href="{$BASE_URL}/toolbox/include/custom/css/jquery-ui.min.css" />
		<link type="text/css" rel="stylesheet" href="{$BASE_URL}/toolbox/include/custom/css/jquery-ui-timepicker-addon.css" />

		<link type="text/css" rel="stylesheet" href="{$BASE_URL}/toolbox/include/codemirror/lib/codemirror.css" />
		<link type="text/css" rel="stylesheet" href="{$BASE_URL}/toolbox/include/codemirror/theme/eclipse.css" />

		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/custom/js/jquery-2.0.3.min.map.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/custom/js/jquery-ui.min.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/custom/js/jquery-ui-timepicker-addon.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/custom/js/jquery.color-2.1.2.min.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/bootstrap/js/bootstrap.min.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/custom/js/script.js"></script>


		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/codemirror/lib/codemirror.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/codemirror/mode/clike/clike.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/codemirror/mode/python/python.js"></script>
		<script type="text/javascript" language="javascript" src="{$BASE_URL}/toolbox/include/codemirror/mode/shell/shell.js"></script>

		<link type="text/css" rel="stylesheet" href="{$BASE_URL}/toolbox/include/custom/css/style.css" />

	</head>

	<body onunload="">
HTML;

if(__DEBUG__) {
    echo "<div style='border-top: 2px solid red; width:100%; position:fixed; top:0px; z-index: 2000;'></div>";
}

if ($user_logged_in) {
    print <<<HTML
        <div class="navbar navbar-inverse navbar-fixed-top">
            <div class="navbar-inner">
                <div class="container-fluid" style="font-weight: 300; display: inline-block;color:#999;">
<!--
                <div class="container-fluid">
                    <a class="brand" href="{$BASE_URL}/account/index.php">$COURSE_NAME Grading Server</a>

                    <ul class="nav" role="navigation">
HTML;
    if ($user_is_administrator) {
        print <<<HTML
<!--
                        <li class="divider-vertical"
                            style="border-right-color: #666;height: 18px; margin-top: 11px;"></li>

                        <li class="dropdown">
                            <a id="drop1" href="#" role="button" class="dropdown-toggle" data-toggle="dropdown">
                                Grading Tools <b class="caret"></b>
                            </a>
                            <ul class="dropdown-menu" role="menu" aria-labelledby="drop-grade">
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-hw-report.php" role="button" data-toggle="modal">
                                    Generate Homework Report
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-grade-summaries.php" role="button" data-toggle="modal">
                                    Generate Grade Summaries
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-csv-report.php" role="button" data-toggle="modal">
                                    Generate CSV Report
                                </a></li>
                            </ul>
                        </li>
                        <li class="dropdown">
                            <a id="drop1" href="#" role="button" class="dropdown-toggle" data-toggle="dropdown">
                                Gradeables <b class="caret"></b>
                            </a>
                            <ul class="dropdown-menu" role="menu" aria-labelledby="drop-grade">
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-gradeables.php" role="button" data-toggle="modal">
                                Manage Gradeables
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/account-numerictext-gradeable.php" role="button" data-toggle="modal">
                                Numeric/Text Gradeables
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/account-checkpoints-gradeable.php" role="button" data-toggle="modal">
                                Checkpoints Gradeables
                                </a></li>
                            </ul>
                        </li>                        
                        <li class="dropdown">
                            <a id="drop1" href="#" role="button" class="dropdown-toggle" data-toggle="dropdown">
                                System Management <b class="caret"></b>
                            </a>
                            <ul class="dropdown-menu" role="menu" aria-labelledby="drop-utility">
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-students.php" role="button" data-toggle="modal">
                                    View Students
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-users.php" role="button" data-toggle="modal">
                                    View Users
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-classlist.php" role="button" data-toggle="modal">
                                    Upload Classlist
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-single-user-review.php" role="button" data-toggle="modal">
									Individual User Enrollment Review
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-rotating-sections.php" role="button" data-toggle="modal">
                                    Setup Rotating Sections
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-latedays.php" role="button" data-toggle="modal">
                                    Add Late Days To Course
                                </a></li>
                                <li><a tabindex="-1" href="{$BASE_URL}/account/admin-latedays-exceptions.php" role="button" data-toggle="modal">
                                    Add Late Day Exceptions For Students
                                </a></li>
                            </ul>
                        </li>
-->
HTML;
    }

    $submission_url = __SUBMISSION_URL__;
    $semester = __COURSE_SEMESTER__;
    $semester_upper = strtoupper($semester);
    $course = __COURSE_CODE__;
    $course_upper = strtoupper($course);
    $course_name = __COURSE_NAME__;
    
    print <<<HTML
                    <h4>{$semester_upper} &gt;
                    <a href="{$submission_url}/index.php?semester={$semester}&course={$course}" role="button" data-toggle="modal">
                        {$course_upper}: {$course_name}
                    </a>
HTML;
    if(isset($_GET['g_id'])){
        $db->query("SELECT g_title FROM gradeable WHERE g_id=?",array($_GET['g_id']));
        $title = $db->row()['g_title'];
        print <<<HTML
                &gt; {$title}
HTML;
    }
    if(isset($_GET['this'])){
        print <<<HTML
                &gt; {$_GET['this']}
HTML;
    }
    print <<<HTML
        </h4>
            </div>
        </div>
        </div>
HTML;
    print <<<HTML
HTML;
}