<?php

namespace app\views;

use app\libraries\Core;
use app\models\User;

class GlobalView {
    /**
     * @var Core
     */
    private $core;

    public function __construct(Core $core) {
        $this->core = $core;
    }

    public function header() {
        $messages = "";
        $cnt = count($_SESSION['messages']['errors']) +
            count($_SESSION['messages']['alerts']) + count($_SESSION['messages']['successes']);
        if ($cnt > 0) {
            $messages = <<<HTML
<div id='messages'>
HTML;
            foreach ($_SESSION['messages']['errors'] as $key => $error) {
                $messages .= <<<HTML
    <div id='error-{$key}' class="inner-message alert alert-error">
        <a class="fa fa-times message-close" onClick="removeBox('error-{$key}');"></a>
        <i class="fa fa-times-circle"></i> {$error}
    </div>
HTML;
            }
            foreach ($_SESSION['messages']['alerts'] as $key => $alert) {
                $messages .= <<<HTML
    <div id='alert-{$key}' class="inner-message alert alert-notice">
        <a class="fa fa-times message-close" onClick="removeBox('alert-{$key}');"></a>
        <i class="fa fa-exclamation-circle"></i> {$alert}
    </div>
HTML;
            }
            foreach ($_SESSION['messages']['successes'] as $key => $success) {
                $messages .= <<<HTML
    <div id="success-{$key}" class="inner-message alert alert-success">
        <a class="fa fa-times message-close" onClick="removeBox('success-{$key}');"></a>
        <i class="fa fa-check-circle"></i> {$success}
    </div>
HTML;
            }
            $messages .= <<<HTML
</div>
HTML;
        }

        if (file_exists($this->core->getConfig()->getHssCoursePath()."/override.css")) {
            $override_css = "<link rel='stylesheet' type='text/css' href='{$this->core->getConfig()->getHssCoursePath()}/override.css' />";
        }
        else {
            $override_css = '';
        }

        $return = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$this->core->getConfig()->getCourseName()} Submissions</title>
    <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.6.1/css/font-awesome.min.css" />
    <link rel="stylesheet" type="text/css" href="{$this->core->getConfig()->getBaseUrl()}public/css/server.css" />
    {$override_css}
    <script type="text/javascript" src="{$this->core->getConfig()->getBaseUrl()}public/js/jquery.min.js"></script>
    <script type="text/javascript" src="{$this->core->getConfig()->getBaseUrl()}public/js/server.js"></script>
</head>
<body>
{$messages}
<div id="container">

HTML;
        if ($this->core->getUser()->accessGrading()) {
            $return .= <<<HTML
<div id="nav">
    <ul>
        <li><a href="{$this->core->buildUrl(array('component' => 'submission', 
                                                  'page' => 'homework'))}">Submit</a></li>
        <li><a href="#">Grade Assignments</a></li>
        <li><a href="#">Grade Labs</a></li>
        <li><a href="#">Grade Tests</a></li>

HTML;
            if($this->core->getUser()->accessAdmin()) {
                $return .= <<<HTML
        <li><a href="#">Report Tools</a></li>
        <li class="dropdown"><a href="#">Manage Gradeables</a>
            <div class="dropdown-content">
                <a href="{$this->core->buildUrl(array('component' => 'admin',
                                                      'page' => 'assignments',
                                                      'action' => 'list'))}">Manage Assignments</a>
                <a href="#">Manage Labs</a>
                <a href="#">Manage Tests</a>
            </div>
        </li>
        <li><a href="#">View Students</a></li>
        <li><a href="#">View Users</a></li>
        <li><a href="{$this->core->buildUrl(array('component' => 'admin', 
                                                  'page' => 'configuration', 
                                                  'action' => 'view'))}">Class Configuration</a></li>
        <li><a href="#">View Orphans</a></li>

HTML;
                if($this->core->getUser()->isDeveloper()) {
                    $return .= <<<HTML
        <li><a href="#" onClick="togglePageDetails();">Show Page Details</a></li>

HTML;
                }
            }
            $return .= <<<HTML
    </ul>
    <span style="float: right; margin-top: 5px; margin-right: 10px;">
        Hello {$this->core->getUser()->getDetail('user_id')}
    </span>
</div>
<div id="nav-clear"></div>

HTML;
        }

        $course_name = htmlentities($this->core->getConfig()->getCourseName());
        $return .= <<<HTML
<div id="header">
    <h1 id="header-text">Homework Submissions for {$course_name}</h1>
</div>


HTML;
        return $return;
    }

    public function footer($runtime) {
        return <<<HTML
    <div id="push"></div>
</div>
<div id="footer">
    <span id="copyright">&copy; 2016 RPI</span>
    <a href="https://github.com/RCOS-Grading-Server/HWserver" target="blank" title="Fork us on Github">
        <i class="fa fa-github fa-lg"></i>
    </a>
</div>

<div id='page-info'>
    Total Queries: {$this->core->getDatabase()->totalQueries()}<br />
    Runtime: {$runtime}<br />
    Queries: <br /> {$this->core->getDatabase()->getQueries()}
</div>

</body>
</html>

HTML;
    }

    public function invalidPage($page) {
        return <<<HTML
<div class="box">
The page {$page} does not exist. Please try again.
</div>
HTML;

    }
}