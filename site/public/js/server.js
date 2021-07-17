var csrfToken = undefined;

window.addEventListener("load", function() {
  for (const elem in document.body.dataset) {
    window[elem] = document.body.dataset[elem];
  }
});

window.addEventListener("resize", checkSidebarCollapse);


////////////Begin: Removed redundant link in breadcrumbs////////////////////////
//See this pr for why we might want to remove this code at some point
//https://github.com/Submitty/Submitty/pull/5071
window.addEventListener("resize", function(){
  adjustBreadcrumbLinks();
});

var mobileHomeLink = null;
var desktopHomeLink = null;
document.addEventListener("DOMContentLoaded", function() {
  loadInBreadcrumbLinks();
  adjustBreadcrumbLinks();
});

function loadInBreadcrumbLinks(){
  mobileHomeLink = $("#home-button").attr('href');
  desktopHomeLink = $("#desktop_home_link").attr('href');
}

function adjustBreadcrumbLinks(){
  if($(document).width() > 528){
    $("#home-button").attr('href', "");
    $("#desktop_home_link").attr('href', desktopHomeLink);
  }else{
    $("#home-button").attr('href', mobileHomeLink);
    $("#desktop_home_link").attr('href', "");
  }
}
////////////End: Removed redundant link in breadcrumbs//////////////////////////



/**
 * Acts in a similar fashion to Core->buildUrl() function within the PHP code
 *
 * @param {object} parts - Object representing URL parts to append to the URL
 * @returns {string} - Built up URL to use
 */
function buildUrl(parts = []) {
    return document.body.dataset.baseUrl + parts.join('/');
}

/**
 * Acts in a similar fashion to Core->buildCourseUrl() function within the PHP code
 * Course information is prepended to the URL constructed.
 *
 * @param {object} parts - Object representing URL parts to append to the URL
 * @returns {string} - Built up URL to use
 */
function buildCourseUrl(parts = []) {
    return document.body.dataset.courseUrl + '/' + parts.join('/');
}

function changeDiffView(div_name, gradeable_id, who_id, version, index, autocheck_cnt, helper_id){
    var actual_div_name = "#" + div_name + "_0";
    var expected_div_name = "#" + div_name + "_1";
    var actual_div = $(actual_div_name).children()[0];
    var expected_div = $(expected_div_name).children()[0];
    var list_white_spaces = {};
    $('#'+helper_id).empty();
    if($("#show_char_"+index+"_"+autocheck_cnt).text() == "Visualize whitespace characters"){
        $("#show_char_"+index+"_"+autocheck_cnt).removeClass('btn-default');
        $("#show_char_"+index+"_"+autocheck_cnt).addClass('btn-primary');
        $("#show_char_"+index+"_"+autocheck_cnt).html("Display whitespace/non-printing characters as escape sequences");
        list_white_spaces['newline'] = '&#9166;';
        var option = 'unicode'
    }
    else if($("#show_char_"+index+"_"+autocheck_cnt).text() == "Display whitespace/non-printing characters as escape sequences") {
        $("#show_char_"+index+"_"+autocheck_cnt).html("Original View");
        list_white_spaces['newline'] = '\\n';
        var option = 'escape'
    }
    else {
        $("#show_char_"+index+"_"+autocheck_cnt).removeClass('btn-primary');
        $("#show_char_"+index+"_"+autocheck_cnt).addClass('btn-default');
        $("#show_char_"+index+"_"+autocheck_cnt).html("Visualize whitespace characters");
        var option = 'original'
    }
    //Insert actual and expected one at a time
    var url = buildCourseUrl(['gradeable', gradeable_id, 'grading', 'student_output', 'remove']) +
        `?who_id=${who_id}&version=${version}&index=${index}&autocheck_cnt=${autocheck_cnt}&option=${option}&which=expected`;

    let assertSuccess = function(data) {
        if (data.status === 'fail') {
            alert("Error loading diff: " + data.message);
            return false;
        }
        else if (data.status === 'error') {
            alert("Internal server error: " + data.message);
            return false;
        }
        return true;
    }

    $.getJSON({
        url: url,
        success: function (response) {
            if(!assertSuccess(response)) {
                return;
            }
            for (property in response.data.whitespaces) {
                list_white_spaces[property] = response.data.whitespaces[property];
            }
            $(expected_div).empty();
            $(expected_div).html(response.data.html);
            url = buildCourseUrl(['gradeable', gradeable_id, 'grading', 'student_output', 'remove']) +
                `?who_id=${who_id}&version=${version}&index=${index}&autocheck_cnt=${autocheck_cnt}&option=${option}&which=actual`;
            $.getJSON({
                url: url,
                success: function (response) {
                    if(!assertSuccess(response)) {
                        return;
                    }
                    for (property in response.data.whitespaces) {
                        list_white_spaces[property] = response.data.whitespaces[property];
                    }
                    for (property in list_white_spaces) {
                        $('#' + helper_id).append('<span style=\"outline:1px blue solid;\">' + list_white_spaces[property] + "</span> = " + property + " ");
                    }
                    $(actual_div).empty();
                    $(actual_div).html(response.data.html);
                },
                error: function (e) {
                    alert("Could not load diff, please refresh the page and try again.");
                }
            });
        },
        error: function (e) {
            alert("Could not load diff, please refresh the page and try again.");
        }
    });

}

function loadTestcaseOutput(div_name, gradeable_id, who_id, index, version = ''){
    let orig_div_name = div_name;
    div_name = "#" + div_name;

    let loadingTools = $("#tc_" + index).find(".loading-tools");

    if($(div_name).is(":visible")){
        $("#show_char_"+index).toggle();
        $(div_name).empty();
        toggleDiv(orig_div_name);

        loadingTools.find("span").hide();
        loadingTools.find(".loading-tools-show").show();
    }
    else{
        $("#show_char_"+index).toggle();
        var url = buildCourseUrl(['gradeable', gradeable_id, 'grading', 'student_output']) + `?who_id=${who_id}&index=${index}&version=${version}`;

        loadingTools.find("span").hide();
        loadingTools.find(".loading-tools-in-progress").show();
        $.getJSON({
            url: url,
            success: function(response) {
                if (response.status !== 'success') {
                    alert('Error getting file diff: ' + response.message);
                    return;
                }
                $(div_name).empty();
                $(div_name).html(response.data);
                toggleDiv(orig_div_name);

                loadingTools.find("span").hide();
                loadingTools.find(".loading-tools-hide").show();
                enableKeyToClick();
            },
            error: function(e) {
                alert("Could not load diff, please refresh the page and try again.");
                console.log(e);
                displayAjaxError(e);
            }
        })
    }
}

function newDeleteGradeableForm(form_action, gradeable_name) {
    $('.popup-form').css('display', 'none');
    var form = $("#delete-gradeable-form");
    $('[id="delete-gradeable-message"]', form).html('');
    $('[id="delete-gradeable-message"]', form).append('<b>'+gradeable_name+'</b>');
    $('[name="delete-confirmation"]', form).attr('action', form_action);
    form.css("display", "block");
    captureTabInModal("delete-gradeable-form");
}

function displayCloseSubmissionsWarning(form_action,gradeable_name) {
    $('.popup-form').css('display', 'none');
    var form = $("#close-submissions-form");
    $('[id="close-submissions-message"]', form).html('');
    $('[id="close-submissions-message"]', form).append('<b>'+gradeable_name+'</b>');
    $('[name="close-submissions-confirmation"]', form).attr('action', form_action);
    form.css("display", "block");
    captureTabInModal("close-submissions-form");
    form.find('.form-body').scrollTop(0);
}

function newDeleteCourseMaterialForm(path, file_name) {
    let url = buildCourseUrl(["course_materials", "delete"]) + "?path=" + path;
    var current_y_offset = window.pageYOffset;
    document.cookie = 'jumpToScrollPostion='+current_y_offset;

    $('[id^=div_viewer_]').each(function() {
        var number = this.id.replace('div_viewer_', '').trim();

        var elem = $('#div_viewer_' + number);
        if (elem.hasClass('open')) {
            document.cookie = "cm_" +number+ "=1;";
        }
        else {
            document.cookie = "cm_" +number+ "=0;";
        }
    });

    $('.popup-form').css('display', 'none');
    var form = $("#delete-course-material-form");
    $('.delete-course-material-message', form).html('');
    $('.delete-course-material-message', form).append('<b>'+file_name+'</b>');
    $('[name="delete-confirmation"]', form).attr('action', url);
    form.css("display", "block");
    captureTabInModal("delete-course-material-form");
    form.find('.form-body').scrollTop(0);
}

function newUploadImagesForm() {
    $('.popup-form').css('display', 'none');
    var form = $("#upload-images-form");
    form.css("display", "block");
    captureTabInModal("upload-images-form");
    form.find('.form-body').scrollTop(0);
    $('[name="upload"]', form).val(null);
}

function newUploadCourseMaterialsForm() {

    createArray(1);

    var fileList = document.getElementsByClassName("file-viewer-data");

    var files = [];
    for(var i=0;i<fileList.length;i++){
        var file = fileList[i];
        files.push(file.getAttribute('data-file_url'));
        readPrevious(file.getAttribute('data-file_url'), 1);
    }

    $('.popup-form').css('display', 'none');
    var form = $("#upload-course-materials-form");

    $('[name="existing-file-list"]', form).html('');
    $('[name="existing-file-list"]', form).append('<b>'+JSON.stringify(files)+'</b>');

    form.css("display", "block");
    captureTabInModal("upload-course-materials-form");
    form.find('.form-body').scrollTop(0);
    $('[name="upload"]', form).val(null);

}

function newEditCourseMaterialsForm(path, dir, this_file_section, this_hide_from_students, release_time) {

    let form = $("#edit-course-materials-form");

    let element = document.getElementById("edit-picker");

    element._flatpickr.setDate(release_time);

    if(this_hide_from_students == "on"){
        $("#hide-materials-checkbox-edit", form).prop('checked',true);
    }

    else{
        $("#hide-materials-checkbox-edit", form).prop('checked',false);
    }

    $('#show-some-section-selection-edit :checkbox:enabled').prop('checked', false);

    if(this_file_section != null){
        for(let index = 0; index < this_file_section.length; ++index){
            $("#section-edit-" + this_file_section[index], form).prop('checked',true);
        }
        $("#all-sections-showing-no", form).prop('checked',false);
        $("#all-sections-showing-yes", form).prop('checked',true);
        $("#show-some-section-selection-edit", form).show();
    }
    else{
        $("#show-some-section-selection-edit", form).hide();
        $("#all-sections-showing-yes", form).prop('checked',false);
        $("#all-sections-showing-no", form).prop('checked',true);
    }
    $("#material-edit-form", form).attr('data-directory', path);
    $("#edit-picker", form).attr('value', release_time);
    $("#edit-sort", form).attr('value', dir);
    form.css("display", "block");
    captureTabInModal("edit-course-materials-form");
}

var lastActiveElement = null;
function captureTabInModal(formName, resetFocus=true){
    if(resetFocus){
        lastActiveElement = document.activeElement;
    }

    var form = $("#".concat(formName));
    form.off('keydown');//Remove any old redirects

    /*get all the elements to tab through*/
    var inputs = form.find(':focusable').filter(':visible');
    var firstInput = inputs.first();
    var lastInput = inputs.last();

    /*set focus on first element*/
    if(resetFocus){
      firstInput.focus();
    }

    /*redirect last tab to first element*/
    form.on('keydown', function (e) {
        if ((e.which === 9 && !e.shiftKey && $(lastInput).is(':focus'))) {
            firstInput.focus();
            e.preventDefault();
        }
        else if ((e.which === 9 && e.shiftKey && $(firstInput).is(':focus'))) {
            lastInput.focus();
            e.preventDefault();
        }
    });

    //Watch for the modal to be hidden
    let observer = new MutationObserver(function(){
        if(form[0].style.display === 'none'){
            releaseTabFromModal(formName);
        }
    });
    observer.observe(form[0], { attributes: true, childList: true });
}

function releaseTabFromModal(formName){
    var form = $("#".concat(formName));
    form.off('keydown');
    lastActiveElement.focus();
}

function setFolderRelease(changeActionVariable,releaseDates,id,inDir){

    $('.popup-form').css('display', 'none');

    var form = $("#set-folder-release-form");
    form.css("display", "block");

    captureTabInModal("set-folder-release-form");

    form.find('.form-body').scrollTop(0);
    $('[id="release_title"]',form).attr('data-path',changeActionVariable);
    $('[name="release_date"]', form).val(releaseDates);
    $('[name="release_date"]',form).attr('data-fp',changeActionVariable);

    inDir = JSON.stringify(inDir);
    $('[name="submit"]',form).attr('data-iden',id);
    $('[name="submit"]',form).attr('data-inDir',inDir);

}

function copyToClipboard(code) {
    var download_info = JSON.parse($('#download_info_json_id').val());
    var required_emails = [];

    $('#download-form input:checkbox').each(function() {
        if ($(this).is(':checked')) {
            var thisVal = $(this).val();

            if (thisVal === 'instructor') {
                for (var i = 0; i < download_info.length; ++i) {
                    if (download_info[i].group === 'Instructor') {
                        required_emails.push(download_info[i].email);
                    }
                }
            }
            else if (thisVal === 'full_access_grader') {
                for (var i = 0; i < download_info.length; ++i) {
                    if (download_info[i].group === 'Full Access Grader (Grad TA)') {
                        required_emails.push(download_info[i].email);
                    }
                }
            }
            else if (thisVal === 'limited_access_grader') {
                for (var i = 0; i < download_info.length; ++i) {
                    if (download_info[i].group === "Limited Access Grader (Mentor)") {
                        required_emails.push(download_info[i].email);
                    }
                }
            }
            else {
                for (var i = 0; i < download_info.length; ++i) {
                    if (code === 'user') {
                        if (download_info[i].reg_section === thisVal) {
                            required_emails.push(download_info[i].email);
                        }
                    }
                    else if (code === 'grader') {
                        if (download_info[i].reg_section === 'All') {
                            required_emails.push(download_info[i].email);
                        }

                        if ($.inArray(thisVal, download_info[i].reg_section.split(',')) !== -1) {
                            required_emails.push(download_info[i].email);
                        }
                    }
                }
            }
        }
    });

    required_emails = $.unique(required_emails);
    var temp_element = $("<textarea></textarea>").text(required_emails.join(','));
    $(document.body).append(temp_element);
    temp_element.select();
    document.execCommand('copy');
    temp_element.remove();
    setTimeout(function() {
        $('#copybuttonid').prop('value', 'Copied');
    }, 0);
    setTimeout(function() {
        $('#copybuttonid').prop('value', 'Copy Emails to Clipboard');
    }, 1000);
}

function downloadCSV(code) {
    var download_info = JSON.parse($('#download_info_json_id').val());
    var csv_data = 'First Name,Last Name,User ID,Email,Secondary Email,UTC Offset,Time Zone,Registration Section,Rotation Section,Group\n';
    var required_user_id = [];

    $('#download-form input:checkbox').each(function() {
        if ($(this).is(':checked')) {
            var thisVal = $(this).val();

            if (thisVal === 'instructor') {
                for (var i = 0; i < download_info.length; ++i) {
                    if ((download_info[i].group === 'Instructor') && ($.inArray(download_info[i].user_id,required_user_id) === -1)) {
                        csv_data += [download_info[i].first_name, download_info[i].last_name, download_info[i].user_id, download_info[i].email, download_info[i].secondary_email, download_info[i].utc_offset, download_info[i].time_zone, '"'+download_info[i].reg_section+'"', download_info[i].rot_section, download_info[i].group].join(',') + '\n';
                        required_user_id.push(download_info[i].user_id);
                    }
                }
            }
            else if (thisVal === 'full_access_grader') {
                for (var i = 0; i < download_info.length; ++i) {
                    if ((download_info[i].group === 'Full Access Grader (Grad TA)') && ($.inArray(download_info[i].user_id,required_user_id) === -1)) {
                        csv_data += [download_info[i].first_name, download_info[i].last_name, download_info[i].user_id, download_info[i].email, download_info[i].secondary_email, download_info[i].utc_offset, download_info[i].time_zone, '"'+download_info[i].reg_section+'"', download_info[i].rot_section, download_info[i].group].join(',') + '\n';
                        required_user_id.push(download_info[i].user_id);
                    }
                }
            }
            else if (thisVal === 'limited_access_grader') {
                for (var i = 0; i < download_info.length; ++i) {
                    if ((download_info[i].group === 'Limited Access Grader (Mentor)') && ($.inArray(download_info[i].user_id,required_user_id) === -1)) {
                        csv_data += [download_info[i].first_name, download_info[i].last_name, download_info[i].user_id, download_info[i].email, download_info[i].secondary_email, download_info[i].utc_offset, download_info[i].time_zone, '"'+download_info[i].reg_section+'"', download_info[i].rot_section, download_info[i].group].join(',') + '\n';
                        required_user_id.push(download_info[i].user_id);
                    }
                }
            }
            else {
                for (var i = 0; i < download_info.length; ++i) {
                    if (code === 'user') {
                        if ((download_info[i].reg_section === thisVal) && ($.inArray(download_info[i].user_id,required_user_id) === -1)) {
                            csv_data += [download_info[i].first_name, download_info[i].last_name, download_info[i].user_id, download_info[i].email, download_info[i].secondary_email, download_info[i].utc_offset, download_info[i].time_zone, '"'+download_info[i].reg_section+'"', download_info[i].rot_section, download_info[i].group].join(',') + '\n';
                            required_user_id.push(download_info[i].user_id);
                        }
                    }
                    else if (code === 'grader') {
                        if ((download_info[i].reg_section === 'All') && ($.inArray(download_info[i].user_id,required_user_id) === -1)) {
                            csv_data += [download_info[i].first_name, download_info[i].last_name, download_info[i].user_id, download_info[i].email, download_info[i].secondary_email, download_info[i].utc_offset, download_info[i].time_zone, '"'+download_info[i].reg_section+'"', download_info[i].rot_section, download_info[i].group].join(',') + '\n';
                            required_user_id.push(download_info[i].user_id);
                        }
                        if (($.inArray(thisVal, download_info[i].reg_section.split(',')) !== -1) && ($.inArray(download_info[i].user_id, required_user_id) === -1)) {
                            csv_data += [download_info[i].first_name, download_info[i].last_name, download_info[i].user_id, download_info[i].email, download_info[i].secondary_email, download_info[i].utc_offset, download_info[i].time_zone, '"'+download_info[i].reg_section+'"', download_info[i].rot_section, download_info[i].group].join(',') + '\n';
                            required_user_id.push(download_info[i].user_id);
                        }
                    }
                }
            }
        }
    });

    // Setup default name for the CSV file
    let course = $('#download_info_json_id').data('course');
    let semester = $('#download_info_json_id').data('semester');
    let csv_name = [semester, course, 'users', 'data'].join('_') + '.csv'

    var temp_element = $('<a id="downloadlink"></a>');
    var address = "data:text/csv;charset=utf-8," + encodeURIComponent(csv_data);
    temp_element.attr('href', address);
    temp_element.attr('download', csv_name);
    temp_element.css('display', 'none');
    $(document.body).append(temp_element);
    $('#downloadlink')[0].click();
    $('#downloadlink').remove();
}

/**
 * Toggles the page details box of the page, showing or not showing various information
 * such as number of queries run, length of time for script execution, and other details
 * useful for developers, but shouldn't be shown to normal users
 */
function togglePageDetails() {
    var element = document.getElementById('page-info');
    if (element.style.display === 'block') {
        element.style.display = 'none';
    }
    else {
        element.style.display = 'block';
        // Hide the box if you click outside of it
        document.body.addEventListener('mouseup', function pageInfo(event) {
            if (!element.contains(event.target)) {
                element.style.display = 'none';
                document.body.removeEventListener('mouseup', pageInfo, false);
            }
        });
    }
}

/**
 * Opens a new tab on https://validator.w3.org with the contents of the current html page
 */
function validateHtml() {
  //Code copied from https://validator.w3.org/nu/about.html under "Check serialized DOM of current page" secton
  function c(a, b) {
    const c = document.createElement("textarea");
    c.name = a;
    c.value = b;
    d.appendChild(c)
  }
  const e = ((a) => {
      for (var b = "", a = a.firstChild; a;) {
        switch (a.nodeType) {
          case Node.ELEMENT_NODE:
            b += a.outerHTML;
            break;
          case Node.TEXT_NODE:
            b += a.nodeValue;
            break;
          case Node.CDATA_SECTION_NODE:
            b += "<![CDATA[" + a.nodeValue + "]]\>";
            break;
          case Node.COMMENT_NODE:
            b += "<\!--" + a.nodeValue + "--\>";
            break;
          case Node.DOCUMENT_TYPE_NODE:
            b += "<!DOCTYPE " + a.name + ">\n"
        }
        a = a.nextSibling
      }
      return b
  })(document);
  const d = document.createElement("form");
  d.method = "POST";
  d.action = "https://validator.w3.org/nu/";
  d.enctype = "multipart/form-data";
  d.target = "_blank";
  d.acceptCharset = "utf-8";
  c("showsource", "yes");
  c("content", e);
  document.body.appendChild(d);
  d.submit();
  d.outerHTML = "";
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

function gradeableChange(url, sel){
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

function checkTaVersionChange(){
    var message = "You are overriding the student's chosen submission. Are you sure you want to continue?";
    return confirm(message);
}

function checkVersionsUsed(gradeable, versions_used, versions_allowed) {
    versions_used = parseInt(versions_used);
    versions_allowed = parseInt(versions_allowed);
    if (versions_used >= versions_allowed) {
        return confirm("Are you sure you want to upload for " + gradeable + "? You have already used up all of your free submissions (" + versions_used + " / " + versions_allowed + "). Uploading may result in loss of points.");
    }
    return true;
}

function toggleDiv(id) {
    $("#" + id).toggle();
    return true;
}


function checkRefreshPage(url) {
    setTimeout(function() {
        check_server(url)
    }, 1000);
}

function check_server(url) {
    $.get(url,
        function(data) {
            if (data.indexOf("REFRESH_ME") > -1) {
                location.reload(true);
            }
        else {
                checkRefreshPage(url);
            }
        }
    );
}

function downloadFile(path, dir) {
    window.location = buildCourseUrl(['download']) + `?dir=${encodeURIComponent(dir)}&path=${encodeURIComponent(path)}`;
}

function downloadTestCaseResult(testcase, name, version, gradeable, user) {
    window.location = buildCourseUrl(['gradeable', gradeable, 'downloadTestCaseResult']) + `?version=${version}&test_case=${testcase+1}&file_name=${name}&user_id=${user}`;
}

function downloadStudentAnnotations(url, path, dir) {
    window.open(url, "_blank", "toolbar=no, scrollbars=yes, resizable=yes, width=700, height=600");
}

function downloadSubmissionZip(grade_id, user_id, version, origin = null, is_anon = false) {
    window.open(buildCourseUrl(['gradeable', grade_id, 'download_zip']) + `?dir=submissions&user_id=${user_id}&version=${version}&origin=${origin}&is_anon=${is_anon}`, "_blank");
    return false;
}

function downloadCourseMaterialZip(dir_name, path) {
    window.location = buildCourseUrl(['course_materials', 'download_zip']) + '?dir_name=' + dir_name + '&path=' + path;
}

function checkColorActivated() {
    var pos = 0;
    var seq = "&&((%'%'BA\r";
    $(document.body).keyup(function colorEvent(e) {
        pos = seq.charCodeAt(pos) === e.code ? pos + 1 : 0;
        if (pos === seq.length) {
            setInterval(function() { $("*").addClass("rainbow"); }, 100);
            $(document.body).off('keyup', colorEvent);
        }
    });
}
$(checkColorActivated);

function changeColor(div, hexColor){
    div.style.color = hexColor;
}

function openDiv(id) {
    var elem = $('#' + id);
    if (elem.hasClass('open')) {
        elem.hide();
        elem.removeClass('open');
        $('#' + id + '-span').removeClass('fa-folder-open').addClass('fa-folder');
    }
    else {
        elem.show();
        elem.addClass('open');
        $('#' + id + '-span').removeClass('fa-folder').addClass('fa-folder-open');
    }
    return false;
}

function openDivForCourseMaterials(num) {
    var elem = $('#div_viewer_' + num);
    if (elem.hasClass('open')) {
        elem.hide();
        elem.removeClass('open');
        $($($(elem.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-folder-open').addClass('fa-folder');
        return 'closed';
    }
    else {
        elem.show();
        elem.addClass('open');
        $($($(elem.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-folder').addClass('fa-folder-open');
        return 'open';
    }
    return false;
}

function hideEmptyCourseMaterialFolders() {
  // fetch all the folders and remove those one which have no `file` within.
  $('.folder-container').each(function() {
    $(this).find('.file-container').length === 0 ? $(this).remove() : null;
  });
}

function closeDivForCourseMaterials(num) {
    var elem = $('#div_viewer_' + num);
    elem.hide();
    elem.removeClass('open');
    $($($(elem.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-folder-open').addClass('fa-folder');
    return 'closed';


}
function openAllDivForCourseMaterials() {
    var elem = $("[id ^= 'div_viewer_']");
    if (elem.hasClass('open')) {
        elem.hide();
        elem.removeClass('open');
        $($($(elem.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-folder-open').addClass('fa-folder');
        return 'closed';
    }
    else {
        elem.show();
        elem.addClass('open');
        $($($(elem.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-folder').addClass('fa-folder-open');
        return 'open';
    }
    return false;
}

function openUrl(url) {
    window.open(url, "_blank", "toolbar=no, scrollbars=yes, resizable=yes, width=700, height=600");
    return false;
}

function changeName(element, user, visible_username, anon){
    var new_element = element.getElementsByTagName("strong")[0];
    anon = (anon == 'true');
    icon = element.getElementsByClassName("fas fa-eye")[0];
    if(icon == undefined){
        icon = element.getElementsByClassName("fas fa-eye-slash")[0];
        if(anon) {
            new_element.style.color = "black";
            new_element.style.fontStyle = "normal";
        }
        new_element.innerHTML = visible_username;
        icon.className = "fas fa-eye";
        icon.title = "Show full user information";
    }
    else {
        if(anon) {
            new_element.style.color = "grey";
            new_element.style.fontStyle = "italic";
        }
        new_element.innerHTML = user;
        icon.className = "fas fa-eye-slash";
        icon.title = "Hide full user information";
    }
}

function openFrame(url, id, filename, ta_grading_interpret=false) {
    var iframe = $('#file_viewer_' + id);
    if (!iframe.hasClass('open')) {
        var iframeId = "file_viewer_" + id + "_iframe";
        if(ta_grading_interpret) {
            let display_file_url = buildCourseUrl(['display_file']);
            let directory = "";
            if (url.includes("submissions")) {
                directory = "submissions";
            }
            else if (url.includes("results_public")) {
                directory = "results_public";
            }
            else if (url.includes("results")) {
                directory = "results";
            }
            else if (url.includes("checkout")) {
                directory = "checkout";
            }
            url = `${display_file_url}?dir=${encodeURIComponent(directory)}&file=${encodeURIComponent(filename)}&path=${encodeURIComponent(url)}&ta_grading=true`
        }
        // handle pdf
        if(filename.substring(filename.length - 3) === "pdf") {
            iframe.html("<iframe id='" + iframeId + "' src='" + url + "' width='750px' height='1200px' style='border: 0'></iframe>");
        }
        else {
            iframe.html("<iframe id='" + iframeId + "' onload='resizeFrame(\"" + iframeId + "\");' src='" + url + "' width='750px' style='border: 0'></iframe>");
        }
        iframe.addClass('open');
    }

    if (!iframe.hasClass('shown')) {
        iframe.show();
        iframe.addClass('shown');
        $($($(iframe.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-plus-circle').addClass('fa-minus-circle');
    }
    else {
        iframe.hide();
        iframe.removeClass('shown');
        $($($(iframe.parent().children()[0]).children()[0]).children()[0]).removeClass('fa-minus-circle').addClass('fa-plus-circle');
    }
    return false;
}

function resizeFrame(id, max_height = 500, force_height=-1) {
    let img = undefined;
    let visible = $("iframe#" + id).is(":visible");
    img = $("iframe#" + id).contents().find("img");
    if($("iframe#" + id).contents().find("html").length !== 0) {
        $("iframe#" + id).contents().find("html").css("height", "inherit");
        img = $("iframe#" + id).contents().find("img");
        if(img.length !== 0) {
            img.removeAttr("width");
            img.removeAttr("height");
            img.css("width", "");
            img.css("height", "");
            img.css("max-width", "100%");
        }
        var height = parseInt($("iframe#" + id).contents().find("body").css('height').slice(0,-2));
    } else { //Handling issue with FireFox and jQuery not being able to access iframe contents for PDF reader
        var height = max_height;
    }
    if (force_height != -1) {
        document.getElementById(id).height = force_height + "px";
    } else if (height >= max_height) {
        document.getElementById(id).height= max_height + "px";
    }
    else {
        document.getElementById(id).height = (height+18) + "px";
    }
    //Workarounds for FireFox changing height/width of img sometime after this code runs
    if(img.length !== 0) {
        const observer = new ResizeObserver(function(mutationsList, observer) {
            img.removeAttr("width");
            img.removeAttr("height");
            img.css("width", "");
            img.css("height", "");
            observer.disconnect();
        })
        observer.observe(img[0]);
    }
    if(!visible) {
        const observer = new IntersectionObserver(function(entries, observer) {
            if($("iframe#" + id).is(":visible")) {
                $("iframe#" + id).removeAttr("height");
                let iframeFunc = $("iframe#" + id)[0].contentWindow.iFrameInit;
                if(typeof(iframeFunc) === "function") {
                    iframeFunc();
                }
                observer.disconnect();
                resizeFrame(id, max_height, force_height);
            }
        });
        observer.observe($("iframe#" + id)[0]);
    }
}

function batchImportJSON(url, csrf_token){
    $.ajax(url, {
        type: "POST",
        data: {
            csrf_token: csrf_token
        }
    })
    .done(function(response) {
        window.alert(response);
        location.reload(true);
    })
    .fail(function() {
        window.alert("[AJAX ERROR] Refresh page");
    });
}

function submitAJAX(url, data, callbackSuccess, callbackFailure) {
    $.ajax(url, {
        type: "POST",
        data: data
    })
    .done(function(response) {
        try{
            response = JSON.parse(response);
            if (response['status'] === 'success') {
                callbackSuccess(response);
            }
            else {
                console.log(response['message']);
                callbackFailure();
                if (response['status'] === 'error') {
                    window.alert("[SAVE ERROR] Refresh Page");
                }
            }
        }
        catch (e) {
            console.log(response);
            callbackFailure();
            window.alert("[SAVE ERROR] Refresh Page");
        }
    })
    .fail(function() {
        window.alert("[SAVE ERROR] Refresh Page");
    });
}

$(function() {
    if (window.location.hash !== "") {
        if ($(window.location.hash).offset().top > 0) {
            var minus = 60;
            $("html, body").animate({scrollTop: ($(window.location.hash).offset().top - minus)}, 800);
        }
    }

    for (const elem of document.getElementsByClassName('alert-success')) {
        setTimeout(() => {
            $(elem).fadeOut();
        }, 5000);
    }
});

function getFileExtension(filename){
    return (filename.substring(filename.lastIndexOf(".")+1)).toLowerCase();
}

function openPopUp(css, title, count, testcase_num, side) {
    var element_id = "container_" + count + "_" + testcase_num + "_" + side;
    var elem_html = "<link rel=\"stylesheet\" type=\"text/css\" href=\"" + css + "\" />";
    elem_html += title + document.getElementById(element_id).innerHTML;
    my_window = window.open("", "_blank", "status=1,width=750,height=500");
    my_window.document.write(elem_html);
    my_window.document.close();
    my_window.focus();
}

let messages = 0;

function displayErrorMessage(message){
    displayMessage(message, 'error');
}

function displaySuccessMessage(message) {
    displayMessage(message, 'success');
}

/**
 * Display a toast message after an action.
 *
 * The styling here should match what's used in GlobalHeader.twig to define the messages coming from PHP
 *
 * @param {string} message
 * @param {string} type either 'error' or 'success'
 */
function displayMessage(message, type) {
    const id = `${type}-js-${messages}`;
    message = `<div id="${id}" class="inner-message alert alert-${type}"><span><i style="margin-right:3px;" class="fas fa-${type === 'error' ? 'times' : 'check'}-circle"></i>${message.replace(/(?:\r\n|\r|\n)/g, '<br />')}</span><a class="fas fa-times" onClick="removeMessagePopup('${type}-js-${messages}');"></a></div>`;
    $('#messages').append(message);
    $('#messages').fadeIn('slow');
    if (type === 'success') {
        setTimeout(() => {
            $(`#${id}`).fadeOut();
        }, 5000);
    }
    messages++;
}

/**
 * Enables the use of TAB key to indent within a textarea control.
 *
 * VPAT requires that keyboard navigation through all controls is always available.
 * Since TAB is being redefined to indent code/text, ESC will be defined, in place
 * of TAB, to proceed to the next control element.  SHIFT+TAB  shall be preserved
 * with its default behavior of returning to the previous control element.
 *
 * @param string jQuerySelector
 */
function enableTabsInTextArea(jQuerySelector) {
    var t = $(jQuerySelector);
    t.on('input', function() {
        $(this).outerHeight(38).outerHeight(this.scrollHeight);
    });
    t.trigger('input');
    t.keydown(function(t) {
        if (t.which == 27) {  //ESC was pressed, proceed to next control element.
            // Next control element may not be a sibling, so .next().focus() is not guaranteed
            // to work.  There is also no guarantee that controls are properly wrapped within
            // a <form>.  Therefore, retrieve a master list of all visible controls and switch
            // focus to the next control in the list.
            var controls = $(":tabbable").filter(":visible");
            controls.eq(controls.index(this) + 1).focus();
            return false;
        }
        else if (!t.shiftKey && t.code === "Tab") { //TAB was pressed without SHIFT, text indent
            var text = this.value;
            var beforeCurse = this.selectionStart;
            var afterCurse = this.selectionEnd;
            this.value = text.substring(0, beforeCurse) + '\t' + text.substring(afterCurse);
            this.selectionStart = this.selectionEnd = beforeCurse+1;
            return false;
        }
        // No need to test for SHIFT+TAB as it is not being redefined.
    });
}

function updateGradeOverride(data) {
    var fd = new FormData($('#gradeOverrideForm').get(0));
    var url = buildCourseUrl(['grade_override', $('#g_id').val(), 'update']);
    $.ajax({
        url: url,
        type: "POST",
        data: fd,
        processData: false,
        cache: false,
        contentType: false,
        success: function(data) {
            try {
                var json = JSON.parse(data);
            } catch(err){
                displayErrorMessage('Error parsing data. Please try again.');
                return;
            }
            if(json['status'] === 'fail'){
                displayErrorMessage(json['message']);
                return;
            }
            refreshOnResponseOverriddenGrades(json);
            $('#user_id').val(this.defaultValue);
            $('#marks').val(this.defaultValue);
            $('#comment').val(this.defaultValue);
            displaySuccessMessage(`Updated overridden Grades for ${json['data']['gradeable_id']}`);
        },
        error: function() {
            window.alert("Something went wrong. Please try again.");
        }
    })
    return false;
}

function loadOverriddenGrades(g_id) {
    var url = buildCourseUrl(['grade_override', g_id]);
    $.ajax({
        url: url,
        success: function(data) {
            try {
                var json = JSON.parse(data);
            } catch(err){
                displayErrorMessage('Error parsing data. Please try again.');
                return;
            }
            if(json['status'] === 'fail'){
                displayErrorMessage(json['message']);
                return;
            }
            refreshOnResponseOverriddenGrades(json);
        },
        error: function() {
            window.alert("Something went wrong. Please try again.");
        }
    });
}

function refreshOnResponseOverriddenGrades(json) {
    const form = $("#load-overridden-grades");
    $('#grade-override-table tr:gt(0)').remove();
    let title = 'Overridden Grades for ' + json['data']['gradeable_id'];
    $('#title').text(title);
    if(json['data']['users'].length === 0){
      $("#load-overridden-grades").addClass('d-none');
      $("#empty-table").removeClass('d-none');
      $('#empty-table').text('There are no overridden grades for this homework');
    }
    else {
        json['data']['users'].forEach(function(elem){
            let delete_button = "<a onclick=\"deleteOverriddenGrades('" + elem['user_id'] + "', '" + json['data']['gradeable_id'] + "');\"><i class='fas fa-trash'></i></a>"
            let bits = ['<tr><td class="align-left">' + elem['user_id'], elem['user_firstname'], elem['user_lastname'], elem['marks'], elem['comment'], delete_button + '</td></tr>'];
            $('#grade-override-table').append(bits.join('</td><td class="align-left">'));
        });
      $("#load-overridden-grades").removeClass('d-none');
      $("#empty-table").addClass('d-none');
    }
}

function deleteOverriddenGrades(user_id, g_id) {
    var url = buildCourseUrl(['grade_override', g_id, 'delete']);
    var confirm = window.confirm("Are you sure you would like to delete this entry?");
    if (confirm) {
        $.ajax({
            url: url,
            type: "POST",
            data: {
                csrf_token: csrfToken,
                user_id: user_id
            },
            success: function(data) {
                var json = JSON.parse(data);
                if(json['status'] === 'fail'){
                    displayErrorMessage(json['message']);
                    return;
                }
                displaySuccessMessage('Overridden Grades deleted.');
                refreshOnResponseOverriddenGrades(json);
            },
            error: function() {
                window.alert("Something went wrong. Please try again.");
            }
        })
    }
    return false;
}

function toggleRegradeRequests(){
    var element = document.getElementById("regradeBoxSection");
    if (element.style.display === 'block') {
        element.style.display = 'none';
    }
    else {
        element.style.display = 'block';
    }

}
/**
  * Taken from: https://stackoverflow.com/questions/1787322/htmlspecialchars-equivalent-in-javascript
  */
function escapeSpecialChars(text) {
  var map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };

  return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function escapeHTML(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function setChildNewDateTime(path, changeDate,handleData) {
    //change the date and time of the subfiles in the folder with the time chosen for the whole
    //folder (passed in)
    var success;
    success = false;
    success  = changeFolderNewDateTime(path,changeDate,function (output) {
        if(output){
            success =true;
            if(handleData){
                handleData(success);
            }
        }
    });
}

function changeFolderNewDateTime(filenames, newdatatime,handleData) {
    // send to server to handle folder date/time change
    let url = buildCourseUrl(['course_materials', 'modify_timestamp']) + '?filenames=' + encodeURIComponent(filenames[0]) + '&newdatatime=' + newdatatime;
    var tbr = false;
    $.ajax({
        type: "POST",
        url: url,
        data: {'fn':filenames,csrf_token: csrfToken},
        success: function(data) {
            var jsondata = JSON.parse(data);
            if (jsondata.status === 'fail') {
                alert("ERROR: Invalid date.");
                return false;
            }

            tbr=true;
            if(handleData){
                handleData(data);
            }
            return true;
        },
        error: function(e) {
            alert("Encounter saving the NewDateTime.");
            return false;
        }
    })
}

// edited slightly from https://stackoverflow.com/a/40658647
// returns a boolean value indicating whether or not the element is entirely in the viewport
// i.e. returns false iff there is some part of the element outside the viewport
$.fn.isInViewport = function() {                                        // jQuery method: use as $(selector).isInViewPort()
    var elementTop = $(this).offset().top;                              // get top offset of element
    var elementBottom = elementTop + $(this).outerHeight();             // add height to top to get bottom

    var viewportTop = $(window).scrollTop();                            // get top of window
    var viewportBottom = viewportTop + $(window).height();              // add height to get bottom

    return elementTop > viewportTop && elementBottom < viewportBottom;
};

function checkSidebarCollapse() {
    var size = $(document.body).width();
    if (size < 1150) {
        document.cookie = "collapse_sidebar=true;";
        $("aside").toggleClass("collapsed", true);
    }
    else{
        document.cookie = "collapse_sidebar=false;";
        $("aside").toggleClass("collapsed", false);
    }
}

function updateTheme(){
  let choice = $("#theme_change_select option:selected").val();
  if(choice === "system_black"){
    localStorage.removeItem("theme");
    localStorage.setItem("black_mode", "black");
  }else if(choice === "light"){
    localStorage.setItem("theme", "light");
  }else if(choice === "dark"){
    localStorage.setItem("theme", "dark");
    localStorage.setItem("black_mode", "dark");
  }else if(choice === "dark_black"){
    localStorage.setItem("theme", "dark");
    localStorage.setItem("black_mode", "black");
  }else{ //choice === "system"
    localStorage.removeItem("black_mode");
    localStorage.removeItem("theme");
  }
  detectColorScheme();
}
$(document).ready(function() {
  if(localStorage.getItem("theme")){
      if(localStorage.getItem("theme") === "dark"){
        if(localStorage.getItem("black_mode") === "black"){
          $("#theme_change_select").val("dark_black");
        }else{
          $("#theme_change_select").val("dark");
        }
      }else{
        $("#theme_change_select").val("light");
      }
  }else{
    if(localStorage.getItem("black_mode") === "black"){
      $("#theme_change_select").val("system_black");
    }else{
      $("#theme_change_select").val("system");
    }
  }
});

//Called from the DOM collapse button, toggle collapsed and save to localStorage
function toggleSidebar() {
    var sidebar = $("aside");
    var shown = sidebar.hasClass("collapsed");

    document.cookie = "collapse_sidebar=" + (!shown).toString() + ";";
    sidebar.toggleClass("collapsed", !shown);
}

$(document).ready(function() {
    //Collapsed sidebar tooltips with content depending on state of sidebar
    $('[data-toggle="tooltip"]').tooltip({
        position: { my: "right+0 bottom+0" },
        content: function () {
            if($("aside").hasClass("collapsed")) {
                if ($(this).attr("title") === "Collapse Sidebar") {
                    return "Expand Sidebar";
                }
                return $(this).attr("title")
            }
            else {
                return ""
            }
        }
    });

    //If they make their screen too small, collapse the sidebar to allow more horizontal space
    $(document.body).resize(function() {
        checkSidebarCollapse();
    });
});

function checkBulkProgress(gradeable_id){
    var url = buildCourseUrl(['gradeable', gradeable_id, 'bulk', 'progress']);
    $.ajax({
        url: url,
        data: null,
        type: "GET",
        success: function(data) {
            data = JSON.parse(data)['data'];
            var result = {};
            updateBulkProgress(data['job_data'], data['count']);
        },
        error: function(e) {
            console.log("Failed to check job queue");
        }
    })
}
// Credit to https://stackoverflow.com/a/24676492/2972004
//      Solution to autoexpand the height of a textarea
function auto_grow(element) {
    element.style.height = "5px";
    element.style.height = (element.scrollHeight + 5)+"px";
}

/**
 * Sets the 'noscroll' textareas to have the correct height
 */
function resizeNoScrollTextareas() {
    // Make sure textareas resize correctly
    $('textarea.noscroll').each(function() {
        auto_grow(this);
    });
}

$(document).ready(function() {
  enableKeyToClick();
});

function keyToClickKeydown(event){
  if (event.code === "Enter") {
    event.preventDefault();
    event.stopPropagation();
    $(event.target).click();
  }
}

function keyToClickKeyup(event){
  if (event.code === "Space") {
    event.preventDefault();
    event.stopPropagation();
    $(event.target).click();
  }
}

function enableKeyToClick(){
  const key_to_click = document.getElementsByClassName("key_to_click");
  for (var i = 0; i < key_to_click.length; i++) {
    //In case this function is run multiple times, we need to remove the old event listeners
    key_to_click[i].removeEventListener('keyup', keyToClickKeyup);
    key_to_click[i].removeEventListener('keydown', keyToClickKeydown);

    key_to_click[i].addEventListener('keyup', keyToClickKeyup);
    key_to_click[i].addEventListener('keydown', keyToClickKeydown);
  }
}

function peerFeedbackUpload(grader_id, user_id, g_id, feedback){
    $('#save_status').html('Saving Feedback...');
    var url = buildCourseUrl(['gradeable', g_id, 'feedback' , 'set']);
    let formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('grader_id', grader_id);
    formData.append('user_id', user_id);
    formData.append('feedback', feedback);
    $.ajax({
        url: url,
        type: "POST",
        data: formData,
        processData: false,
        cache: false,
        contentType: false,
        success: function(data) {
            try {
                $('#save_status').html('All Changes Saved');
            } catch(err){
                return;
            }
        },
        error: function() {
            window.alert("Something went wrong. Please try again.");
            $('#save_status').html('<span style="color: red">Some Changes Failed!</span>');
        }
    })
}

function popOutSubmittedFile(html_file, url_file) {
    var directory = "";
    let display_file_url = buildCourseUrl(['display_file']);
    if (url_file.includes("submissions")) {
      directory = "submissions";
      url_file = url_file;
    }
    else if (url_file.includes("results_public")) {
      directory = "results_public";
    }
    else if (url_file.includes("results")) {
      directory = "results";
    }
    else if (url_file.includes("checkout")) {
      directory = "checkout";
    }
    else if (url_file.includes("split_pdf")) {
      directory = "split_pdf";
    }
    window.open(display_file_url + "?dir=" + encodeURIComponent(directory) + "&file=" + encodeURIComponent(html_file) + "&path=" + encodeURIComponent(url_file) + "&ta_grading=true","_blank","toolbar=no,scrollbars=yes,resizable=yes, width=700, height=600");
    return false;
  }

/**
 * Function for course staff to flag/unflag a user's preferred photo as inappropriate.
 *
 * @param user_id The user_id of the user who's preferred photo should be flagged
 * @param flag A boolean indicating whether to flag or unflag the image.
 *             True to flag
 *             False to unflag
 */
function flagUserImage(user_id, flag) {
    let confirm_message;
    let success_message;

    if (flag) {
        confirm_message = `You are flagging ${user_id}'s preferred image as inappropriate.\nThis should be done if the image is not a recognizable passport style photo.\n\nDo you wish to proceed?`;
        success_message = `${user_id}'s preferred image was successfully flagged.`;
    }
    else {
        confirm_message = `${user_id}'s preferred image has be flagged as inappropriate.\nThis was done because the image is not a recognizable, passport style photo.\n\nYou are reverting to ${user_id}'s preferred image.\nDo you wish to proceed?`;
        success_message = `${user_id}'s preferred image was successfully restored.`;
    }

    const confirmed = confirm(confirm_message);

    if (confirmed) {
        const url = buildCourseUrl(['flag_user_image']);

        const form_data = new FormData();
        form_data.append('user_id', user_id);
        form_data.append('csrf_token', csrfToken);
        form_data.append('flag', flag);

        const makeRequest = async () => {
            const image_container = document.querySelector(`.${user_id}-image-container`);

            // Show working message
            const working_message = document.createElement('i');
            working_message.innerHTML = '<span style="color:var(--no-image-available)">Working...</span>';
            image_container.removeChild(image_container.firstElementChild);
            image_container.prepend(working_message);

            const response = await fetch(url, {method: 'POST', body: form_data});
            const result = await response.json();

            if (result.status === 'success') {
                const data = result.data;

                // Change image
                let new_content;
                if (data.image_data && data.image_mime_type) {
                    new_content = document.createElement('img');
                    new_content.setAttribute('alt', data.first_last_username);
                    new_content.setAttribute('src', `data:${data.image_mime_type};base64,${data.image_data}`);
                }
                else {
                    new_content = document.createElement('i');
                    new_content.innerHTML = '<span style="color:var(--no-image-available)">No Image Available</span>';
                }

                image_container.removeChild(image_container.firstElementChild);
                image_container.prepend(new_content);

                // Change icon
                const a = image_container.querySelector('a');
                a.href = data.href;
                a.innerHTML = data.icon_html;

                displaySuccessMessage(success_message);
            }
            else {
                displayErrorMessage(result.message);
            }
        };

        try {
            makeRequest();
        }
        catch (err) {
            console.error(err);
            displayErrorMessage('Something went wrong!');
        }
    }
}

/**
 * Get an array of all focusable elements currently in the dom.
 *
 * @returns {Element[]}
 */
function getFocusableElements() {
    let focusable_elements = $(':focusable:tabbable');
    return Array.from(focusable_elements);
}

/**
 * Function to toggle markdown rendering preview
 *
 * @param markdown_textarea JQuery element of the textarea where the markdown is being entered
 * @param preview_element JQuery element of the span the markdown will be inserted into
 * @param preview_button JQuery element of the "Preview Markdown" button
 *                       Should have title="Preview Markdown"
 * @param data Object whose properties will get sent through a POST request
 */
function previewMarkdown(markdown_textarea, preview_element, preview_button, data) {
    const enablePreview = preview_element.is(':hidden');

    $.ajax({
        url: buildCourseUrl(['markdown', 'preview']),
        type: 'POST',
        data: {
            enablePreview: enablePreview,
            ...data,
            csrf_token: csrfToken
        },
        success: function(data){
            if (enablePreview) {
                preview_element.empty();
                preview_element.append(data);
                preview_element.show();
                markdown_textarea.hide();

                preview_button.empty();
                preview_button.append('Edit <i class="fa fa-edit fa-1x"></i>');

            }
            else {
                preview_element.hide();
                markdown_textarea.show();

                preview_button.empty();
                preview_button.append('Preview <i class="fas fa-eye fa-1x"></i>');
            }
        },
        error: function() {
            displayErrorMessage('Something went wrong while trying to preview markdown. Please try again.');
        }
    });
}

/**
 * Function to toggle markdown rendering preview
 *
 * @param markdownContainer JQuery element of the textarea where the markdown should be rendered
 * @param url url to send ajax request to
 * @param content Text content of the unrendered markdown
 */
function renderMarkdown(markdownContainer, url, content) {
    $.ajax({
        url: url,
        type: 'POST',
        data: {
            enablePreview: true,
            content: content,
            csrf_token: csrfToken
        },
        success: function(data){
            markdownContainer.empty();
            markdownContainer.append(data);
        },
        error: function() {
            displayErrorMessage('Something went wrong while trying to preview markdown. Please try again.');
        }
    });
}

/**
 * Function to toggle markdown rendering preview
 *
 * @param type Number representing the type of markdown preset to insert
 *             0: code
 *             1: link
 *             2: bold text
 *             3: italic text
 * @param divTitle JQuery compatible identifier for where to add the markdown presets
 */
function addMarkdownCode(type, divTitle){
    var cursor = $(divTitle).prop('selectionStart');
    var text = $(divTitle).val();
    var insert = "";
    if(type == 1) {
        insert = "[display text](url)";
    }
    else if(type == 0){
        insert = "```" +
            "\ncode\n```";
    }
    else if(type == 2){
        insert = "__bold text__ ";
    }
    else if(type == 3){
        insert = "_italic text_ ";
    }
    $(divTitle).val(text.substring(0, cursor) + insert + text.substring(cursor));
}
