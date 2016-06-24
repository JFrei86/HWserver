<?php

include "../../toolbox/functions.php";
$g_id = $_GET['g_id'];
$section = intval($_GET['section_id']);

$db->query("SELECT * FROM gradeable WHERE g_id=?", array($g_id));
$check_g = $db->row();

print <<<HTML
Name: ____________&emsp;&emsp;&emsp;&emsp;
Date: ____________________&emsp;&emsp;&emsp;
<b>{$check_g['g_title']}</b>&emsp;&emsp;&emsp;&emsp;
Section: <b>{$section}</b>
<br /><br />
<table border="1">
    <tr>
    <td style="width: 20%">User ID</td>
    <td style="width: 20%">Last Name</td>
    <td style="width: 20%">First Name</td>
HTML;

//Get the names of all of the checkpoints 

$db->query("SELECT gc_title FROM gradeable as g INNER JOIN gradeable_component gc ON g.g_id = gc.g_id WHERE g.g_id=?",array($g_id));
$checkpoints = array();
foreach($db->rows() as $row){
    array_push($checkpoints, $row['gc_title']);
}

$width = (40/count($checkpoints));
for($i = 0; $i < count($checkpoints); $i++) {
    print <<<HTML
        <td style="width: {$width}%">{$checkpoints[$i]}</td>
HTML;
}

print <<<HTML
    </tr>
HTML;

$db->query("SELECT * FROM students WHERE student_section_id=? ORDER BY student_rcs", array($section));

$j = 0;
foreach($db->rows() as $student) {
    $color = ($j % 2 == 0) ? "white" : "lightgrey";
    print <<<HTML
    <tr style="background-color: {$color}">
        <td>
            {$student['student_rcs']}
        </td>
        <td>
            {$student['student_last_name']}
        </td>
        <td>
            {$student['student_first_name']}
        </td>
HTML;
    for($i = 0; $i < count($checkpoints); $i++) {
        print <<<HTML
        <td></td>
HTML;
    }
    print <<<HTML
    </tr>
HTML;
    $j++;
}

print <<<HTML
</table>
HTML;

?>