<?php
require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

?>
<style>
    .row {
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .col {
        flex: 1;
        display: flex;
        justify-content: center;
        padding: 0 5%;
    }
    .pt10 {
        padding-top: 10px;
    }
    .pb10 {
        padding-bottom: 10px;
    }
    .input_copy_wrapper{
        width: 450px;
        padding: 15px;
        margin : 0 auto;
    }
    .input_copy {
        padding: 15px 25px;
        background: #f5f5f5;
        border: 1px solid #aaa;
        color: #5b5b5b;
        font-size: 1em;
    }

    .input_copy .icon {
        display: block;
        cursor: pointer;
        float: right;
        transform: translateY(50%);
    }

    .input_copy .icon img{
        max-width: 20px;
    }
    .input_copy .credentials {
        width: 90%;
        display: inline-block;
        overflow: hidden;
    }
    .flashBG {
        animation-name: flash;
        animation-timing-function: ease-out;
        animation-duration: 1s;
    }

    @keyframes flash {
        0% {
            background: #ccc;
        }
        100% {
            background: transparent;
        }
    }
</style>
<div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <div id="sg-modal-support-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                <h4 class="modal-title">Support</h4>
            </div>
        </div>
        <form class="form-horizontal" method="post" id="supportModal">
            <div class="modal-body sg-modal-body">
                <div class="input_copy_wrapper" style="display: none">
                    <div class="input_copy">
                        <span class="credentials"></span>
                        <span class="icon right"><img src="<?=SG_IMAGE_URL?>copy-24.png" title="Click to Copy"></span>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <h4>Grant Temporary Access</h4>
                    </div>
                    <div class="col">
                        <h4>Open Ticket Without Access</h4>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <p>This will generate a support user and will show the credentials to copy</p>
                    </div>
                    <div class="col">
                        <p>This would then redirect to the page with form to the open support ticket</p>
                    </div>
                </div>
                <div class="row pt10 pb10">
                    <div class="col">
                        <button type="button" onclick="sgSupport.createUser()"
                                class="btn btn-success"><?php _backupGuardT('Create temporary user') ?></button>
                    </div>
                    <div class="col">
                        <button type="button" onclick="sgSupport.createTicket()"
                                class="btn btn-success"><?php _backupGuardT('Create support ticket') ?></button>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>
<script>
    jQuery('.input_copy .icon').click(function() {
        let text = jQuery('.credentials').html().replace('<br>', '\r\n');
        navigator.clipboard.writeText(text);
        jQuery('.credentials').addClass('flashBG')
            .delay('1000').queue(function(){
            jQuery('.credentials').removeClass('flashBG').dequeue();
        });
    });
</script>