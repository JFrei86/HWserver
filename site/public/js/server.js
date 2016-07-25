/**
 * Toggles the page details box of the page, showing or not showing various information
 * such as number of queries run, length of time for script execution, and other details
 * useful for developers, but shouldn't be shown to normal users
 */
function togglePageDetails() {
    if (document.getElementById('page-info').style.visibility == 'visible') {
        document.getElementById('page-info').style.visibility = 'hidden';
    }
    else {
        document.getElementById('page-info').style.visibility = 'visible';
    }
}

/**
 * Remove an alert message from display. This works for successes, warnings, or errors to the
 * user
 * @param elem
 */
function removeMessagePopup(elem) {
    $('#' + elem).fadeOut('slow', function() {
        $('#' + elem).remove();
    });
}

function assignmentChange(url, sel){
    url = url + sel.value;
    window.location.href = url;
}
function versionChange(url, sel){
    url = url + sel.value;
    window.location.href = url;
}

function checkVersionChange(days_late, late_days_allowed){
    if(days_late > late_days_allowed){
        var message = "The max late days allowed for this assignment is " + late_days_allowed + " days. ";
        message += "You are not supposed to change your active version after this time unless you have permission from the instructor. Are you sure you want to continue?";
        return confirm(message);
    }
    return true;
}

function checkVersionsUsed(assignment, versions_used, versions_allowed) {
    versions_used = parseInt(versions_used);
    versions_allowed = parseInt(versions_allowed);
    if (versions_used >= versions_allowed) {
        var message = confirm("Are you sure you want to upload for " + assignment + "? You have already used up all of your free submissions (" + versions_used + " / " + versions_allowed + "). Uploading may result in loss of points.");
        return message;
    }
    return true;
}

function toggleDiv(id) {
    $("#" + id).toggle();
    return true;
}

/* TODO: Add way to add new errors/notices/successes to the screen for ajax forms */
$(function() {
    setTimeout(function() {
        $('.inner-message').fadeOut();
    }, 5000);
});
