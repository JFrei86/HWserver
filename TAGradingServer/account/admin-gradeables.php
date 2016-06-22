<?php

require_once "../header.php";

check_administrator();

$output = "";

$db->query("
SELECT
    g.*
FROM
    gradeable AS g
ORDER BY g_id ASC",array());

$gradeables = $db->rows();
$output .= <<<HTML
<style type="text/css">
    body {
        overflow: scroll;
    }

    select {
        margin-top:7px;
        width: 60px;
        min-width: 60px;
    }

    #container-gradeables
    {
        width:1200px;
        margin:100px auto;
        margin-top: 100px;
        background-color: #fff;
        border: 1px solid #999;
        border: 1px solid rgba(0,0,0,0.3);
        -webkit-border-radius: 6px;
        -moz-border-radius: 6px;
        border-radius: 6px;outline: 0;
        -webkit-box-shadow: 0 3px 7px rgba(0,0,0,0.3);
        -moz-box-shadow: 0 3px 7px rgba(0,0,0,0.3);
        box-shadow: 0 3px 7px rgba(0,0,0,0.3);
        -webkit-background-clip: padding-box;
        -moz-background-clip: padding-box;
        background-clip: padding-box;
    }

    #table-gradeables {
        /*width: 80%;*/
        margin-left: 30px;
        margin-top: 10px;
    }

    #table-gradeables td {
        display: inline-block;
        overflow: auto;
        padding-top: 10px;
        padding-bottom: 10px;
    }

    #table-gradeables tr {
        border-bottom: 1px solid darkgray;
        margin-top: 5px;
    }

    #table-gradeables td.gradeables-id {
        width: 50px;
    }

    #table-gradeables td.gradeables-name {
        width: 200px;
    }

    #table-gradeables td.gradeables-parts {
        width: 160px;
    }

    #table-gradeables td.gradeables-questions {
        width: 160px;
    }

    #table-gradeables td.gradeables-score {
        width: 160px;
    }

    #table-gradeables td.gradeables-due {
        width: 260px;
    }

    #table-gradeables td.gradeables-options {
        width: 100px;
    }


    .edit-gradeables-icon {
        display: block;
        float:left;
        margin-right:20px;
        margin-top: 5px;
        position: relative;
        width: 24px;
        height: 24px;
        overflow: hidden;
    }

    .edit-gradeables-confirm {
        max-width: none;
        position: absolute;
        top:0;
        left:-280px;
    }

    .edit-gradeables-cancel {
        max-width: none;
        position: absolute;
        top:0;
        left:-310px;
    }

    .submit-button {
        float: right;
        margin-top: -28px;
        margin-right: 170px;
    }

</style>
<script type="text/javascript">
    function deleteGradeable(gradeable_id) {
        var gradeable_name = $('td#gradeable-'+gradeable_id+'-title').text();
        var c = window.confirm("Are you sure you want to delete '" + gradeable_name + "'?");
        if (c == true) {
            $.ajax('{$BASE_URL}/account/ajax/admin-gradeables.php?course={$_GET['course']}&action=delete&id='+gradeable_id, {
                type: "POST",
		        data: {
                    csrf_token: '{$_SESSION['csrf']}'
                }
            })
            .done(function(response) {
                var res_array = response.split("|");
                if (res_array[0] == "success") {
                    window.alert("'" + gradeable_name + "' deleted");
                    $('tr#gradeable-'+gradeable_id).remove();
                }
                else {
                    window.alert("'" + gradeable_name + "' could not be deleted");
                }
            })
            .fail(function() {
                window.alert("[ERROR] Refresh Page");
            });
        }
    }

    function fixSequences() {
        $.ajax('{$BASE_URL}/account/ajax/admin-gradeables.php?course={$_GET['course']}&action=sequence', {
            type: "POST",
            data: {
                csrf_token: '{$_SESSION['csrf']}'
            }
        })
        .done(function(response) {
            var res_array = response.split("|");
            if (res_array[0] == "success") {
                window.alert("DB Sequences recalculated");
            }
            else {
                console.log(response);
                window.alert("[DB ERROR] Refresh page");
            }
        })
        .fail(function() {
            window.alert("[AJAX ERROR] Refresh page");
        });
    }
</script>
<div id="container-gradeables">
    <div class="modal-header">
        <h3 id="myModalgradeableel">Manage Gradeables</h3>
        <span class="submit-button">
            <input class="btn btn-primary" onclick="window.location.href='{$BASE_URL}/account/admin-gradeable.php?course={$_GET['course']}'" type="submit" value="Create New Gradeable"/>
            &nbsp;&nbsp;
            <input class="btn btn-primary" onclick="fixSequences();" type="submit" value="Fix DB Sequences" />
        </span>
    </div>
    <table id="table-gradeables">
        <tr>
            <td class="gradeables-id">ID</td>
            <td class="gradeables-name">Name</td>
            <td class="gradeables-parts">Parts</td>
            <td class="gradeables-questions">Questions</td>
            <td class="gradeables-score">Score</td>
            <td class="gradeables-due">Due Date</td>
            <td class="gradeables-options">Options</td>
        </tr>
HTML;



foreach ($gradeables as $gradeable) {
    $g_id = htmlspecialchars($gradeable['g_id']);
    $g_title = htmlspecialchars($gradeable['g_title']);
    
    $db->query("SELECT COUNT(*) as cnt FROM gradeable_component WHERE g_id=?", array($gradeable['g_id']));
    $num_questions = intval($db->row()['cnt']);
    
    $output .= <<<HTML
        <tr id='gradeable-{$g_id}'">
            <td class="gradeables-id" id="gradeable-{$g_id}-id">{$g_id}</td>
            <td class="gradeables-name" id="gradeable-{$g_id}-title">{$g_title}</td>
            <td class="gradeables-parts" id="gradeable-{$g_id}-parts"><!--{$gradeable['gradeable_parts']}--></td>
            <td class="gradeables-questions" id="gradeable-{$g_id}-questions">{$num_questions}</td>
            <td class="gradeables-score" id="gradeable-{$g_id}-score"><!-- {$gradeable['gradeable_score']} ({$gradeable['gradeable_ec']}) --></td>
            <td class="gradeables-due" id="gradeable-{$g_id}-due">{$gradeable['g_grade_released_date']}</td>
            <td id="gradeable-{$g_id}-options"><a href="{$BASE_URL}/account/admin-gradeable.php?course={$_GET['course']}&action=edit&id={$g_id}">Edit</a> |
            <a onclick="deleteGradeable({$g_id});">Delete</a></td>
        </tr>
HTML;
}

$output .= <<<HTML
    </table><br />
</div>
HTML;

print $output;

include "../footer.php";
?>