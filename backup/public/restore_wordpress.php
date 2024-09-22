<?php
#SG_DYNAMIC_DEFINES#

if (!defined('BG_RESTORE_KEY')) die ('Direct access is not allowed');
if (!defined('BG_EXTERNAL_RESTORE_RUNNING')) define('BG_EXTERNAL_RESTORE_RUNNING', true);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once (dirname(__FILE__) . '/wp-load.php');
require_once (SG_RESTORE_PATH . '/Restore.php');
require_once (SG_RESTORE_PATH . '/Extract.php');

$Restore = new Restore();
$Extract = new Extract();
$Restore->init(BG_RESTORE_KEY);
$Extract->init(BG_RESTORE_KEY);
$params = $Restore->getParams();
$type = $params['type'] ?? null;
$mode = ($type == SG_ACTION_TYPE_EXTRACT) ? 'Extracting' : 'Restoring';


switch ($type) {

	case SG_ACTION_TYPE_EXTRACT:

        switch ($Extract->getAction()) {

            case 'awake':

                    $Extract->continueExtract();
				    die ('awake');

			case 'quit':

				$Extract->quit(true);

				break;

			case 'finalize';

				$Extract->log('Inside extract finalize state');

				$Extract->quit();
				die(1);

			case 'getAction':

				$Extract->progress();
				break;

        }

    break;  // SG_ACTION_TYPE_EXTRACT

	case SG_ACTION_TYPE_RESTORE:

		switch ($Restore->getAction()) {

			case 'awake':

				try {
					$Restore->continue();
				} catch (SGExceptionDatabaseError $e) {
				} catch (SGExceptionForbidden $e) {
				} catch (SGExceptionMethodNotAllowed $e) {
				}
				die ('awake');

			case 'quit':

				//$Restore->maintenanceMode(false);
				$Restore->quit(true);


				break;

			case 'finalize';

				$Restore->quit(false);
				die(1);

			case 'getAction':

				$Restore->Progress();

				break;

			default: break;
		}



		break; // SG_ACTION_TYPE_RESTORE:




}


?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" type="text/css" href="<?php echo SG_PUBLIC_URL; ?>css/spinner.css">
    <link rel="stylesheet" type="text/css" href="<?php echo SG_PUBLIC_URL; ?>css/bgstyle.less.css">
    <link rel="stylesheet" type="text/css" href="<?php echo SG_PUBLIC_URL; ?>css/main.css">
    <title>Restore backup</title>
    <style>
        body {
            background-color: #fff;
            padding: 0;
            margin: 0;
        }

        .sg-box-center {
            width: 400px;
            position: absolute;
            left: 50%;
            margin-left: -200px;
            margin-top: 100px;
        }

        .sg-logo {
            text-align: center;
            padding: 30px 0;
            margin-bottom: 10px;
        }

        .sg-wrapper-less {
            height: 4px;
            margin: 1px 0 0;
        }

        .sg-progress-box p {
            margin-top: 10px;
            text-align: center;
        }

        .restore-progress-p {
            font-size: 21px;
            font-weight: bold;
        }

        .sg-wrapper-less .btn-primary {
            font-size: 12px;
            color: #ff6c2c;
            background-color: #ffffff;
            border-color: #ff6c2c;
            border-radius: 8px;
            font-weight: bold;
        }

        .forcestop {
            display: flex;
            justify-content: center;
            margin-top: 15px;
        }
    </style>

    <!-- entire screen refresh after 5 minutes to force data refresh -->

    <script type="text/javascript">
        setTimeout(function(){
            window.location.reload();
        }, 300000);

    </script>

</head>
<body>
<div class="sg-wrapper-less">
    <div id="sg-wrapper">
        <div id="sg-backup-page-content-backups" class="sg-backup-page-content ">
            <div class="sg-box-center">
                <div class="sg-logo">
                    <img width="172px" src="<?php echo SG_PUBLIC_URL; ?>img/jetbackup.svg">
                </div>
                <div class="sg-progress-box">
                    <div style="display: block; text-align: center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 38 38">
                            <defs>
                                <linearGradient x1="8.042%" y1="0%" x2="65.682%" y2="23.865%" id="a">
                                    <stop stop-color="#000" stop-opacity="0" offset="0%"/>
                                    <stop stop-color="#000" stop-opacity=".631" offset="63.146%"/>
                                    <stop stop-color="#000" offset="100%"/>
                                </linearGradient>
                            </defs>
                            <g fill="none" fill-rule="evenodd">
                                <g transform="translate(1 1)">
                                    <path d="M36 18c0-9.94-8.06-18-18-18" id="Oval-2" stroke="url(#a)" stroke-width="2">
                                        <animateTransform attributeName="transform" type="rotate" from="0 18 18" to="360 18 18" dur="0.9s" repeatCount="indefinite"/>
                                    </path>
                                    <circle fill="#fff" cx="36" cy="18" r="1">
                                        <animateTransform attributeName="transform" type="rotate" from="0 18 18" to="360 18 18" dur="0.9s" repeatCount="indefinite"/>
                                    </circle>
                                </g>
                            </g>
                        </svg>
                    </div>
                    <p class="restore-progress-p"><?php echo $mode ?> <span id="progressItem">files</span>: <span id="progressTxt">0%</span></p>
                    <p class="restore-progress-file" style="font-style: italic; font-size: 12px;"><span id="progressFile">...</span></p>
                    <div class="forcestop">
                        <a onclick="abortProcess()" href="javascript:void(0)" id="sg-backup-with-migration" class="pull-left btn btn-primary sg-backup-action-buttons">
                            <span class="sg-backup-buttons-text sg-backup-buttons-content">Abort Action</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    function bgRunAwake(url) {
        var req;
        if (window.XMLHttpRequest) {
            req = new XMLHttpRequest();
        } else if (window.ActiveXObject) {
            req = new ActiveXObject("Microsoft.XMLHTTP");
        }
        req.open("GET", url, true);
        req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        req.send();
    }

    function bgRunAjax(url, responseHandler, params) {
        var req;
        if (window.XMLHttpRequest) {
            req = new XMLHttpRequest();
        } else if (window.ActiveXObject) {
            req = new ActiveXObject("Microsoft.XMLHTTP");
        }
        req.onreadystatechange = function () {
            if (req.readyState == 4) {
                if (req.status < 400) {
                    responseHandler(req, params);
                }
            }
        };
        req.open("POST", url, true);
        req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        req.send(params);
    }

    function bgUpdateProgress(progress) {
        var progressInPercents = progress + '%';
        var progressTxt = document.getElementById('progressTxt');
        progressTxt.innerHTML = progressInPercents;
    }

    function abortProcess() {
        bgRunAjax("<?php echo BG_RESTORE_URL; ?>&action=quit", function () {
            try {
                bgUpdateProgress(100);
                location.href = '<?php echo BG_PLUGIN_URL; ?>';
            } catch (e) {}
        }, "");
    }

    var getActionRunning = false;

    function getAction() {
        if (getActionRunning) return;
        getActionRunning = true;
        bgRunAjax("<?php echo BG_RESTORE_URL; ?>&action=getAction", function (response) {
            try {
                var response = eval('(' + response.responseText + ')');
                if (response === 1) {
                    clearInterval(getActionTimer);
                    clearInterval(getAwakeTimer);
                    bgRunAjax("<?php echo BG_RESTORE_URL; ?>&action=finalize", function (response) {
                        bgUpdateProgress(100);
                        location.href = '<?php echo BG_PLUGIN_URL; ?>';
                    }, "");
                    return;
                } else if (response === 0) {
                    clearInterval(getActionTimer);
                    clearInterval(getAwakeTimer);
                    bgUpdateProgress(100);
                    location.href = '<?php echo BG_PLUGIN_URL; ?>';
                    return;
                } else if (typeof response === 'object') {
                    bgUpdateProgress(response.progress);
                    if (response.status == <?php echo SG_ACTION_STATUS_IN_PROGRESS_FILES; ?>) {
                        progressItem.innerHTML = 'files';
                    } else {
                        progressItem.innerHTML = 'database';
                    }
                    progressFile.innerHTML = response.lastAction;
                }
            } catch (e) {
            }
            getActionRunning = false;
        }, "");
    }

    //get action  (for progress)
    var getActionTimer = setInterval(function () {
        getAction();
    }, 2000);

    //get action  (for progress)
    var getAwakeTimer = setInterval(function () {
        bgRunAwake("<?php echo BG_RESTORE_URL; ?>&action=awake");
    }, 4000);

    getAction();
</script>
</body>
</html>