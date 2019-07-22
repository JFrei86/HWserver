function userFormChange() {
    var user_elem = $("select[name='user_group']")[0];
    var is_student = user_elem.options[user_elem.selectedIndex].text === "Student";

    var regis_elem = $("select[name='registered_section']")[0];
    var is_no_regis = regis_elem.options[regis_elem.selectedIndex].text === "Not Registered";

    if(is_student && is_no_regis) {
        $("#user-form-student-error-message").show();
    }
    else {
        $("#user-form-student-error-message").hide();
    }
    if(is_student) {
        $("#user-form-assigned-sections").hide();
    }
    else {
        $("#user-form-assigned-sections").show();
    }
}

function editUserForm(user_id) {
    var url = buildUrl({'component': 'admin', 'page': 'users', 'action': 'get_user_details', 'user_id': user_id});
    $.ajax({
        url: url,
        success: function(data) {
            var json = JSON.parse(data)['data'];
            var form = $("#edit-user-form");
            form.css("display", "block");
            $("#edit-user-modal-title").css('display','block');
            $("#new-user-modal-title").css('display','none');
            $("#user-form-already-exists-error-message").css('display','none');
            $('[name="edit_user"]', form).val("true");
            var user = $('[name="user_id"]', form);
            user.val(json['user_id']);
            user.attr('readonly', 'readonly');
            if (!user.hasClass('readonly')) {
                user.addClass('readonly');
            }
            completeUserFormInformation(json);
        },
        error: function() {
            alert("Could not load user data, please refresh the page and try again.");
        }
    })
}

function newUserForm(grader_flag) {
    $('.popup-form').css('display', 'none');
    var form = $("#edit-user-form");
    form.css("display", "block");
    $("#edit-user-modal-title").css('display','none');
    $("#new-user-modal-title").css('display','block');
    $("#user-form-already-exists-error-message").css('display','none');
    $('[name="edit_user"]', form).val("false");
    $('[name="user_id"]', form).removeClass('readonly').prop('readonly', false).val("");
    $('[name="manual_registration"]', form).prop('checked', true);

    if (grader_flag) {
        $('[name="user_group"] option[value="3"]', form).prop('selected', true);
        $('#user-form-student-error-message').hide();
        $('#user-form-assigned-sections').show();
    }
    else {
        $('[name="user_group"] option[value="4"]', form).prop('selected', true);
        $('#user-form-assigned-sections').hide();
        $('#user-form-student-error-message').show();
    }
}

$("#edit-user-form").ready(function() {
    var url = buildUrl({'component': 'admin', 'page': 'users', 'action': 'new_user_information'});
    $.ajax({
        url: url,
        success: function(data) {
            var json = JSON.parse(data)['data'];
            $('[name="user_id"]').change(function() {
                autoCompleteOnUserId(json);
            });
        },
        error: function() {
            alert("Could not load user data, please refresh the page and try again.");
        }
    })
});

function autoCompleteOnUserId(user_information) {
    var form = $("#edit-user-form");
    if ($('#user_id').val() in user_information) {
        var user = user_information[$('#user_id').val()];
        var user_already_exists = user['already_in_course'] ? 'block' : 'none';
        $("#user-form-already-exists-error-message").css('display',user_already_exists);
        completeUserFormInformation(user);
    }
    else {
        $("#user-form-already-exists-error-message").css('display','none');
        clearUserFormInformation();
    }
}

function closeButton() {
    $('#edit-user-form').css('display', 'none');
    if($('[name="edit_user"]').val() == 'true') {
        clearUserFormInformation();
    }
}

function redirectToEdit() {
    editUserForm($('#user_id').val());
}

function completeUserFormInformation(user) {
    var form = $("#edit-user-form");

    $('[name="user_numeric_id"]', form).val(user['user_numeric_id']);
    $('[name="user_firstname"]', form).val(user['user_firstname']);
    $('[name="user_preferred_firstname"]', form).val(user['user_preferred_firstname']);
    $('[name="user_lastname"]', form).val(user['user_lastname']);
    $('[name="user_preferred_lastname"]', form).val(user['user_preferred_lastname']);
    $('[name="user_email"]', form).val(user['user_email']);
    var registration_section;
    if (user['registration_section'] === null) {
        registration_section = "null";
    }
    else {
        registration_section = user['registration_section'].toString();
    }
    var rotating_section;
    if (user['rotating_section'] === null) {
        rotating_section = "null";
    }
    else {
        rotating_section = user['rotating_section'].toString();
    }
    $('[name="registered_section"] option[value="' + registration_section + '"]', form).prop('selected', true);
    $('[name="rotating_section"] option[value="' + rotating_section + '"]', form).prop('selected', true);
    if (user['already_in_course']) {
        $('[name="user_group"] option[value="' + user['user_group'] + '"]', form).prop('selected', true);
        $('[name="manual_registration"]', form).prop('checked', user['manual_registration']);
    }
    $("[name='grading_registration_section[]']").prop('checked', false);
    if (user['grading_registration_sections'] !== null && user['grading_registration_sections'] !== undefined) {
        user['grading_registration_sections'].forEach(function(val) {
            $('#grs_' + val).prop('checked', true);
        });
    }
    if (registration_section === 'null' && $('[name="user_group"] option[value="4"]', form).prop('selected')) {
        $('#user-form-student-error-message').css('display', 'block');
    }
    else {
        $('#user-form-student-error-message').css('display', 'none');
    }
    if ($('[name="user_group"] option[value="4"]', form).prop('selected')) {
        $('#user-form-assigned-sections').hide();
    }
    else {
        $('#user-form-assigned-sections').show();
    }
}

function clearUserFormInformation() {
    var form = $("#edit-user-form");
    $('[name="user_numeric_id"]', form).val("");
    $('[name="user_firstname"]', form).val("");
    $('[name="user_preferred_firstname"]', form).val("");
    $('[name="user_lastname"]', form).val("");
    $('[name="user_preferred_lastname"]', form).val("");
    $('[name="user_email"]', form).val("");
    $('[name="registered_section"] option[value="null"]', form).prop('selected', true);
    $('[name="rotating_section"] option[value="null"]', form).prop('selected', true);
    $('[name="manual_registration"]', form).prop('checked', true);
    $("[name='grading_registration_section[]']").prop('checked', false);
}
