<?php
require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();
?>
<div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h4 class="modal-title"><?php _backupGuardT('Terms & Conditions') ?></h4>
        </div>
        <div class="modal-body sg-modal-body">
            <div class="col-md-12">
                <div class="form-group sg-justify">
                    <p>1. <?php _backupGuardT('Terms') ?></p>
                    <p><?php _backupGuardT('By using this software, you are agreeing to be bound by these software Terms and Conditions of Use, all applicable laws and regulations, and agree that you are responsible for compliance with any applicable local laws. If you do not agree with any of these terms, you are prohibited from using this software. The materials contained in this software are protected by applicable copyright and trade mark law.') ?></p>
                    <br/>
                    <p>2. <?php _backupGuardT('Disclaimer') ?></p>
                    <p><?php _backupGuardT("The materials on JetBackup's are provided") ?>
                        &quot;<?php _backupGuardT('as is') ?>
                        &quot;. <?php _backupGuardT('JetBackup makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties, including without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights. Further, JetBackup does not warrant or make any representations concerning the accuracy, likely results, or reliability of the use of the materials on its Software.') ?></p>
                    <br/>
                    <p>3. <?php _backupGuardT('Limitations') ?></p>
                    <p><?php _backupGuardT("In no event shall JetBackup or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption,) arising out of the use or inability to use the materials on JetBackup's software, even if JetBackup or a JetBackup authorized representative has been notified orally or in writing of the possibility of such damage. Because some jurisdictions do not allow limitations on implied warranties, or limitations of liability for consequential or incidental damages, these limitations may not apply to you.") ?></p>
                    <br/>
                    <p>4. <?php _backupGuardT('Revisions and Errata') ?></p>
                    <p><?php _backupGuardT("The materials appearing on JetBackup's software could include technical, typographical, or photographic errors. JetBackup does not warrant that any of the materials on its software are accurate, complete, or current. JetBackup may make changes to the materials contained on its software at any time without notice. JetBackup does not, however, make any commitment to update the materials.") ?></p>
                    <br/>
                    <p>5. <?php _backupGuardT('Terms of Use Modifications') ?></p>
                    <p><?php _backupGuardT('JetBackup may revise these terms of use for its software at any time without notice. By using this software you are agreeing to be bound by the then current version of these Terms and Conditions of Use.') ?></p>
                </div>
            </div>
            <div class="clearfix"></div>
        </div>
        <div class="modal-footer">
            <button type="button" data-dismiss="modal" class="btn btn-primary"><?php _backupGuardT('Ok') ?></button>
        </div>
    </div>
</div>
