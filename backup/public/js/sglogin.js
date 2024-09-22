jQuery(document).ready(function() {
	jQuery('#bg-login-btn').click(function(event) {
		event.preventDefault();
		sgBackup.login();
	});
});

jQuery(document).keypress(function( event ) {
	if ( event.which == 13 ) {
		sgBackup.login();
	}
});

sgBackup.login = function()
{
	sgBackup.showAjaxSpinner('#bg-wrapper');

	jQuery('#bg-login-error').hide();

	var license = jQuery('#license').val();

	var ajaxHandler = new sgRequestHandler('login', {
		license: license,
		token: BG_BACKUP_STRINGS.nonce
	});

	ajaxHandler.callback = function(response) {
		sgBackup.hideAjaxSpinner();
		if (response.status == 0) {
			location.reload();
		} else {
			jQuery('#bg-login-error').show();
		}
	};
	ajaxHandler.run();
}
