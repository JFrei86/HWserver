<?php

namespace app\views\submission;

use app\views\AbstractView;

class TeamView extends AbstractView {

    /**
    * Show team management page
    * @param Gradeable $gradeable
    * @param Team[] $teams
    * @return string
    */
    public function showTeamPage($gradeable, $teams) {
        $site_url = $this->core->getConfig()->getSiteUrl();
        $semester = $this->core->getConfig()->getSemester();
        $course = $this->core->getConfig()->getCourse();
        $user_id = $this->core->getUser()->getId();
        
        $team = $gradeable->getTeam();
        $return = <<<HTML
<div class="content">
    <h2>Manage Team For: {$gradeable->getName()}</h2>
HTML;

    //Top content box, has team
    if ($team !== null) {

        //List team members
        $return .= <<<HTML
    <h3>Your Team:</h3> <br />
HTML;
        foreach ($team->getMembers() as $teammate) {
            $teammate = $this->core->getQueries()->getUserById($teammate);
            $return .= <<<HTML
        <span>&emsp;{$teammate->getFirstName()} {$teammate->getLastName()} ({$teammate->getId()}) - {$teammate->getEmail()}</span> <br />
HTML;
        }
        //Team invitations status
        if (count($team->getInvitations()) !== 0) {
            $return .= <<<HTML
    <br />
    <h3>Pending Invitations:</h3> <br />
HTML;
            foreach ($team->getInvitations() as $invited) {
                $return .= <<<HTML
        <span>&emsp;{$invited}</span> <br />
HTML;
            }
        }
    }

    //Top content box, no team
    else {
        $return .= <<<HTML
    <h4>You are not on a team.</h4> <br />
HTML;
    }
    $return .= <<<HTML
</div>

<div class="content">
HTML;

    //Bottom content box, has team
    if ($team !== null) {
        $return .= <<<HTML
    <h3>Invite new teammates by their user ID:</h3>
    <br />
    <form action="{$this->core->buildUrl(array('component' => 'student', 'gradeable_id' => $gradeable->getId(), 'page' => 'team', 'action' => 'invitation'))}" method="post">
        <input type="text" name="invite_id" placeholder="User ID" />
        <input type="submit" value = "Invite" class="btn btn-primary" />
    </form>
    <br />
    <button class="btn btn-danger" onclick="location.href='{$this->core->buildUrl(array('component' => 'student', 'gradeable_id' => $gradeable->getId(), 'page' => 'team', 'action' => 'leave_team'))}'">Leave Team</button>
HTML;
    }

    //Bottom content box, no team
    else {

        //Invitations received
        $invites_received = array();
        foreach($teams as $t) {
            if ($t->sentInvite($user_id)) {
                $invites_received[] = $t;
            }
        }

        if(count($invites_received) === 0) {
            $return .= <<<HTML
    <h4>You have not received any invitations.</h4> <br />
HTML;
        }
        else {
            $return .= <<<HTML
    <h3>Invitations:</h3> <br />
HTML;
            foreach ($invites_received as $invite) {
                $return .= <<<HTML
    <form action="{$this->core->buildUrl(array('component' => 'student', 'gradeable_id' => $gradeable->getId(), 'page' => 'team', 'action' => 'accept'))}" method="post">
        <input type="hidden" name="team_id" value={$invite->getId()} />
        &emsp;{$invite->getMemberList()}: <input type="submit" value = "Accept" class="btn btn-success" />
    </form>
    <br />
HTML;
            }
        }

        //Create new team button
        $return .= <<<HTML
    <br />
    <button class="btn btn-primary" onclick="location.href='{$this->core->buildUrl(array('component' => 'student', 'gradeable_id' => $gradeable->getId(), 'page' => 'team', 'action' => 'create_new_team'))}'">Create New Team </button>
HTML;
    }
    $return .= <<<HTML
</div>
HTML;
    return $return;
    }
}
