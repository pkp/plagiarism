<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#plagiarismSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form 
	class="pkp_form" 
	id="plagiarismSettingsForm" 
	method="post" 
	action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="plagiarismSettingsFormNotification"}

	<div id="description">
		{translate key="plugins.generic.plagiarism.manager.settings.description"}
	</div>

	{if $ithenticateForced}
		<div id="ithenticate_notice" class="pkpNotification pkpNotification--warning ithenticate--warning">
			<b>{translate key="plugins.generic.plagiarism.manager.settings.areForced"}</b>
		</div>
	{/if}

	{fbvFormArea id="ithenticateServiceAccessFormArea"}
		{fbvElement 
			type="text" 
			id="ithenticateApiUrl" 
			value=$ithenticateApiUrl 
			label="plugins.generic.plagiarism.manager.settings.apiUrl"
		}

		{fbvElement 
			type="text" 
			id="ithenticateApiKey" 
			value=$ithenticateApiKey 
			label="plugins.generic.plagiarism.manager.settings.apiKey"
		}
	{/fbvFormArea}

	{fbvFormArea id="ithenticateSimilarityReportSettings"}
		{fbvFormSection title="plugins.generic.plagiarism.similarityCheck.settings.title" list=true}
			<div id="ithenticate_similarity_config_notice" class="pkpNotification pkpNotification--warning ithenticate--warning">
				<b>{translate key="plugins.generic.plagiarism.similarityCheck.settings.warning.note"}</b>
			</div>

			{fbvElement type="checkbox" name="addToIndex" 			id="addToIndex" 			checked=$addToIndex 			label="plugins.generic.plagiarism.similarityCheck.settings.field.addToIndex" 			translate="true"}
			{fbvElement type="checkbox" name="excludeQuotes" 		id="excludeQuotes" 			checked=$excludeQuotes 			label="plugins.generic.plagiarism.similarityCheck.settings.field.excludeQuotes" 		translate="true"}
			{fbvElement type="checkbox" name="excludeBibliography" 	id="excludeBibliography" 	checked=$excludeBibliography 	label="plugins.generic.plagiarism.similarityCheck.settings.field.excludeBibliography" 	translate="true"}
			{fbvElement type="checkbox" name="excludeCitations" 	id="excludeCitations" 		checked=$excludeCitations 		label="plugins.generic.plagiarism.similarityCheck.settings.field.excludeCitations" 		translate="true"}
			{fbvElement type="checkbox" name="excludeAbstract" 		id="excludeAbstract" 		checked=$excludeAbstract 		label="plugins.generic.plagiarism.similarityCheck.settings.field.excludeAbstract" 		translate="true"}
			{fbvElement type="checkbox" name="excludeMethods" 		id="excludeMethods" 		checked=$excludeMethods 		label="plugins.generic.plagiarism.similarityCheck.settings.field.excludeMethods" 		translate="true"}
			{fbvElement type="checkbox" name="allowViewerUpdate" 	id="allowViewerUpdate" 		checked=$allowViewerUpdate 		label="plugins.generic.plagiarism.similarityCheck.settings.field.allowViewerUpdate" 	translate="true"}
			
			{fbvFormSection description="plugins.generic.plagiarism.similarityCheck.settings.field.excludeSmallMatches.description"}
				{fbvElement 
					type="text" 
					id="excludeSmallMatches" 
					value=$excludeSmallMatches 
					label="plugins.generic.plagiarism.similarityCheck.settings.field.excludeSmallMatches.label"
				}
			{/fbvFormSection}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}

	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>

<style>
	.ithenticate--warning {
		margin-top: 5px;
		margin-bottom: 5px;
	}
</style>
