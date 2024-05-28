{**
 * plugins/generic/plagiarism/templates/confirmEula.tpl
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Intermediate stage before final submission stage to view EULA
 *}
<script type="text/javascript">
    $(function() {ldelim}

        const isValidUrl = urlString => {
            let url;

            try { 
                url = new URL(urlString); 
            } catch(e) { 
                return false; 
            }

            return url.protocol === "http:" || url.protocol === "https:";
        }
        
        let cancelWarningMessage = "{$cancelWarningMessage}" || undefined,
            cancelRedirect = "{$cancelRedirect}" || undefined;

        
        if (isValidUrl(cancelRedirect)) {
            $('form#confirmEulaForm').on('click', 'a.cancelButton', function (event) {
                if (cancelRedirect === 'modal') {
                    return true;
                }
                event.preventDefault();
                if (confirm(cancelWarningMessage) === true) {
                    window.location.href = cancelRedirect;
                }
            });
        } else {
            // Attach the JS form handler.
            $('#confirmEulaForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
        }
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
	    
    {fbvFormArea id="EulaConfirmationSection"}
        {fbvFormSection}
            <input type="hidden" name="submissionId" value="{$submissionId|escape}" />
            <div>
                {fbvElement 
                    type="checkbox" 
                    name="confirmSubmissionEula" 
                    id="confirmSubmissionEula"
                    label="plugins.generic.plagiarism.submission.eula.acceptance.confirm" 
                    translate="true"
                    
                }
                
                {if SessionManager::getManager()->getUserSession()->getSessionVar('confirmSubmissionEulaError')}
                    <span>
                        <label class="sub_label error">
                            {translate key="plugins.generic.plagiarism.submission.eula.acceptance.error"}
                        </label>
                    </span>
                {/if}
            </div>
    
            {fbvFormButtons 
                id="eulaConfirmationAcceptance"
                submitText="plugins.generic.plagiarism.submission.eula.accept.button.title"
            }
        {/fbvFormSection}
	{/fbvFormArea}
</form>

<style>
    .pkp_form li {
        list-style: none;
    }
</style>
