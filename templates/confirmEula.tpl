{**
 * plugins/generic/plagiarism/templates/confirmEula.tpl
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Intermediate stage before final submission stage to view EULA
 *}
<script type="text/javascript">
    $(function() {ldelim}
        // Attach the JS form handler.
        // $('#confirmEulaForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

        let cancelWarningMessage = "{$cancelWarningMessage}",
            cancelRedirect = "{$cancelRedirect}";
        
        $('form#confirmEulaForm').on('click', 'a.cancelButton', function (event) {
            event.preventDefault();
            if (confirm(cancelWarningMessage) === true) {
                window.location.href = cancelRedirect;
            }
        });
    {rdelim});
</script>

<form 
    class="pkp_form" 
    id="confirmEulaForm" 
    method="post" 
    action="{$actionUrl}"
>
    {csrf}
 
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="submitStep4FormNotification"}

    <p>{$eulaAcceptanceMessage}</p>

    {fbvFormButtons id="eulaConfirmationAcceptance" submitText="plugins.generic.plagiarism.submission.eula.accept.button.title"}
</form>
