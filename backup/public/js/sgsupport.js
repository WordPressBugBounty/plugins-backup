sgSupport = {};
const URL = 'https://billing.jetapps.com/submitticket.php?step=2&deptid=1';

sgSupport.createUser = function () {
    sgBackup.showAjaxSpinner('#sg-modal');
    let createSupportUser = new sgRequestHandler('createSupportUser', { token: BG_BACKUP_STRINGS.nonce });

    createSupportUser.callback = function (response, error) {
        let text = 'Login: ' + response.login + '<br>Password: ' + response.password;
        jQuery('.credentials').html(text);
        jQuery('.input_copy_wrapper').show(500);

        sgBackup.hideAjaxSpinner('#sg-modal');
    };

    createSupportUser.run();
};

sgSupport.createTicket = function () {
    window.open(URL, '_blank');
};