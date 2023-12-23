<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#plagiarismSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="plagiarismSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	
	{csrf}

	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="plagiarismSettingsFormNotification"}

	<div id="description">
		{translate key="plugins.generic.plagiarism.manager.settings.description"}
	</div>

	{if $ithenticateForced}
		<div id="ithenticate_notice">
			<b>{translate key="plugins.generic.plagiarism.manager.settings.areForced"}</b>
		</div>
	{/if}

	{fbvFormArea id="webFeedSettingsFormArea"}
		{fbvElement type="text" id="ithenticateUser" value=$ithenticateUser label="plugins.generic.plagiarism.manager.settings.username"}
		{fbvElement type="text" id="ithenticatePass" value=$ithenticatePass label="plugins.generic.plagiarism.manager.settings.password" password=true}   
	{/fbvFormArea}

	{fbvFormButtons}

	<p>
		<span class="formRequired">
			{translate key="common.requiredField"}
		</span>
	</p>
</form>
