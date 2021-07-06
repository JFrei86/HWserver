/* exported sendEmail */
/* eslint no-undef: "off" */


function sendEmail(url) {
    const emailContent = $('#email-content').val();
    const emailSubject = $('#email-subject').val();
    // Check checkboxes for options
    const emailInstructor = $('#email-instructor').is(':checked');
    const emailFullAcess = $('#email-full-access').is(':checked');
    const emailLimitedAccess = $('#email-limited-access').is(':checked');
    const emailStudent = $('#email-student').is(':checked');
    const emailToSecondary = $('#email-to-secondary').is(':checked');
    const emailFaculty = $('#email-faculty').is(':checked');
    $('#email-content').prop('disabled', true);
    $('#send-email').prop('disabled', true);
    $.ajax({
        url: url,
        type: 'POST',
        data: {
            'emailContent': emailContent,
            'emailSubject': emailSubject,
            'emailFullAccess': emailFullAcess,
            'emailLimitedAccess': emailLimitedAccess,
            'emailInstructor': emailInstructor,
            'emailStudent': emailStudent,
            'emailToSecondary': emailToSecondary,
            'emailFaculty': emailFaculty,
            csrf_token: csrfToken,
        },
        cache: false,
        error: function(err) {
            window.alert('Something went wrong. Please try again.');
            console.error(err);
        },
        success: function(data) {
            try {
                const parsedData = JSON.parse(data);
                if (parsedData['status'] == 'success') {
                    $('#email-content').val('');
                    $('#email-subject').val('');
                    displaySuccessMessage(parsedData['data']['message']);
                }
                else {
                    displayErrorMessage(parsedData['message']);
                }
                $('#email-content').prop('disabled', false);
                $('#send-email').prop('disabled', false);
            }
            catch (e) {
                console.error(e);
            }
        },
    });
}
