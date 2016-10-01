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

    public function header($breadcrumbs, $css=array()) {
        $messages = <<<HTML
<div id='messages'>

HTML;
        foreach (array('error', 'notice', 'success') as $type) {
            foreach ($_SESSION['messages'][$type] as $key => $error) {
                $messages .= <<<HTML
    <div id='{$type}-{$key}' class="inner-message alert alert-{$type}">
        <a class="fa fa-times message-close" onClick="removeMessagePopup('{$type}-{$key}');"></a>
        <i class="fa fa-times-circle"></i> {$error}
    </div>

HTML;
                unset($_SESSION['messages'][$type][$key]);
            }
        }
        $messages .= <<<HTML
</div>

HTML;
        $override_css = '';
        if (file_exists($this->core->getConfig()->getCoursePath()."/config/override.css")) {
            $override_css = "<style type='text/css'>".file_get_contents($this->core->getConfig()->getCoursePath()."/config/override.css")."</style>";
        }

        $is_dev = ($this->core->userLoaded() && $this->core->getUser()->isDeveloper()) ? "true" : "false";
        $return = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$this->core->getFullCourseName()}</title>
    <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css" />
    <link rel="stylesheet" type="text/css" href="{$this->core->getConfig()->getBaseUrl()}css/server.css" />
    <link rel="stylesheet" type="text/css" href="{$this->core->getConfig()->getBaseUrl()}css/diff-viewer.css" />
HTML;
    foreach($css as $css_ref){
        $return .= <<<HTML
        <link rel="stylesheet" type="text/css" href="{$css_ref}" />
HTML;
    }

    $return .= <<<HTML
    {$override_css}
    <script type="text/javascript" src="{$this->core->getConfig()->getBaseUrl()}js/jquery.min.js"></script>
    <script type="text/javascript" src="{$this->core->getConfig()->getBaseUrl()}js/diff-viewer.js"></script>
    <script type="text/javascript" src="{$this->core->getConfig()->getBaseUrl()}js/server.js"></script>
    <script type="text/javascript">
        var is_developer = {$is_dev};
    </script>
</head>
<body>
{$messages}
<div id="container">

HTML;
        if ($this->core->getUser() != null) {
            if($this->core->getUser()->accessGrading()) {
                $ta_base_url = $this->core->getConfig()->getTABaseUrl();
                $semester = $this->core->getConfig()->getSemester();
                $course = $this->core->getConfig()->getCourse();
                if($this->core->getUser()->accessAdmin()) {
                    $return .= <<<HTML
    <div id="nav">
        <ul>
            <li>
                <a href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'configuration', 'action' => 'view'))}">Course Settings</a>
            </li>
            <li>
              <!--<a href="{$ta_base_url}/account/admin-students.php?course={$course}&semester={$semester}&this=View%20Students">Students</a>-->
                <a href="{$ta_base_url}/account/admin-students.php?course={$course}&semester={$semester}&this=Students">Students</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-users.php?course={$course}&semester={$semester}&this=Users">Users</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-single-user-review.php?course={$course}&semester={$semester}&this=Manage%20Users">Manage Users</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-rotating-sections.php?course={$course}&semester={$semester}&this=Setup%20Rotating%20Sections">Setup Rotating Sections</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-latedays.php?course={$course}&semester={$semester}&this=Late%20Days%20Allowed">Late Days Allowed</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-latedays-exceptions.php?course={$course}&semester={$semester}&this=Excused%20Absense%20Extensions">Excused Absense Extensions</a>
            </li>

            <li>
                <a href="{$ta_base_url}/account/admin-grade-summaries.php?course={$course}&semester={$semester}&this=Grade%20Summaries">Grade Summaries</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-csv-report.php?course={$course}&semester={$semester}&this=CSV%20Report">CSV Report</a>
            </li>
            <li>
                <a href="{$ta_base_url}/account/admin-hw-report.php?course={$course}&semester={$semester}&this=Homework%20Report">HWReport</a>
            </li>

HTML;
                    if ($this->core->getUser()->isDeveloper()) {
                        $return .= <<<HTML
            <li><a href="#" onClick="togglePageDetails();">Show Page Details</a></li>

HTML;
                    }
                    $return .= <<<HTML
        </ul>
    </div>
    
HTML;
                }
            }
        }

        $return .= <<<HTML
<div id="header">
    <a href="http://submitty.org" target=_blank><div id="logo-submitty"></div></a>
    <div id="header-text">
        <h2>
HTML;
        if ($this->core->userLoaded()) {
            $logout_link = $this->core->buildUrl(array('component' => 'authentication', 'page' => 'logout'));
            $my_preferred_name = $this->core->getUser()->getPreferredFirstName();
            $id = $this->core->getUser()->getId();
            $return .= <<<HTML
            <span id="login">Hello <span id="login-id">{$my_preferred_name}</span></span> (<a id='logout' href='{$logout_link}'>Logout</a>)
HTML;
        }
        else {
            $return .= <<<HTML
            <span id="login-guest">Hello Guest</span>
HTML;
        }
        $return .= <<<HTML
        </h2>
        <h2>
        {$breadcrumbs}
        </h2>
    </div>
</div>


HTML;
        return $return;
    }

    public function footer($runtime) {
        $return = <<<HTML
    <div id="push"></div>
</div>
<div id="footer">
    <span id="copyright">&copy; 2016 RPI</span>
    <a href="https://github.com/RCOS-Grading-Server/HWserver" target="blank" title="Fork us on Github">
        <i class="fa fa-github fa-lg"></i>
    </a>
</div>
HTML;
        if ($this->core->userLoaded() && $this->core->getUser()->isDeveloper()) {
            $return .= <<<HTML
<div id='page-info'>
    Total Queries: {$this->core->getDatabase()->totalQueries()}<br />
    Runtime: {$runtime}<br />
    Queries: <br /> {$this->core->getDatabase()->getQueries()}
</div>
HTML;
        }
        $return .= <<<HTML
</body>
</html>

HTML;

        return $return;
    }

    public function invalidPage($page) {
        return <<<HTML
<div class="box">
The page {$page} does not exist. Please try again.
</div>
HTML;

    }
}
