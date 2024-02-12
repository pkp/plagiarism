<?php

/**
 * @file PlagiarismPlugin.inc.php
 *
 * Copyright (c) 2003-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.db.DAORegistry');

class PlagiarismPlugin extends GenericPlugin {

	public const PLUGIN_INTEGRATION_NAME = 'Plagiarism plugin for OJS/OMP/OPS';

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		$this->addLocaleData();
		
		if (!($success || $this->getEnabled())) {
			return false;	
		}

		HookRegistry::register('submissionsubmitstep4form::execute', [$this, 'submitForPlagiarismCheck']);

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		HookRegistry::register("Schema::get::{$submissionDao->schemaName}", [$this, 'addPlagiarismCheckDataToSubmissionSchema']);

		$schemaName = $this->hasForcedCredentials() ? SCHEMA_SITE : Application::get()->getContextDAO()->schemaName;
		HookRegistry::register("Schema::get::{$schemaName}", [$this, 'addPlagiarismCheckWebhookDataToSchema']);

		HookRegistry::register('LoadComponentHandler', [$this, 'setupWebhookHandler']);

		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.plagiarism.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.plagiarism.description');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	public function getCanEnable($contextId = null) {
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	public function getCanDisable($contextId = null) {
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	public function getEnabled($contextId = null) {
		return parent::getEnabled($contextId) || Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Add properties for this type of public identifier to the submission entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::submission`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addPlagiarismCheckDataToSubmissionSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->ithenticate_id = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service submission uuid that return back after a submission has been successful submitted',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		return false;
	}

	/**
	 * Add properties for this type of public identifier to the site/context entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::context` or `Schema::get::site`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addPlagiarismCheckWebhookDataToSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->ithenticate_webhook_signing_secret = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook registration signing secret',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_webhook_id = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook id that return back after successful webhook registration',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		return false;
	}

	/**
	 * Fetch credentials from config.inc.php, if available
	 * 
	 * @return array api url and api key, or null(s)
	 */
	public function getForcedCredentials() {
		$request = Application::get()->getRequest(); /** @var Request $request */
		$context = $request->getContext(); /** @var Context $context */
		$contextPath = $context?->getPath();

		$apiUrl = Config::getVar(
			'ithenticate',
			'api_url[' . $contextPath . ']',
			Config::getVar('ithenticate', 'api_url')
		);

		$apiKey = Config::getVar(
			'ithenticate',
			'api_key[' . $contextPath . ']',
			Config::getVar('ithenticate', 'api_key')
		);

		return [$apiUrl, $apiKey];
	}

	/**
	 * Check and determine if plagiarism checking service creds has been set forced in config.inc.php
	 * 
	 * @return bool
	 */
	public function hasForcedCredentials() {
		list($apiUrl, $apiKey) = $this->getForcedCredentials();
		return !empty($apiUrl) && !empty($apiKey);
	}

	/**
	 * Send the editor an error message
	 * 
	 * @param int $submissionid
	 * @param string $message
	 * 
	 * @return void
	 */
	public function sendErrorMessage($submissionId, $message) {
		$request = Application::get()->getRequest(); /** @var Request $request */
		$context = $request->getContext(); /** @var Context $context */
		
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		$roleDao = DAORegistry::getDAO('RoleDAO'); /** @var RoleDAO $roleDao  */
		
		// Get the managers.
		$managers = $roleDao->getUsersByRoleId(ROLE_ID_MANAGER, $context->getId()); /** @var DAOResultFactory $managers */
		while ($manager = $managers->next()) {
			$notificationManager->createTrivialNotification(
				$manager->getId(), 
				NOTIFICATION_TYPE_ERROR, 
				['contents' => __(
					'plugins.generic.plagiarism.errorMessage', [
						'submissionId' => $submissionId,
						'errorMessage' => $message
					]
				)]
			);
		}

		error_log("iThenticate submission {$submissionId} failed: {$message}");
	}

	/**
	 * Setup the handler for webhook request
	 * 
	 * @param string $hookName
	 * @param array $args
	 * 
	 * @return bool
	 */
	public function setupWebhookHandler($hookName, $params) {
		$component =& $params[0];

		if ($component == 'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler') {
			import($component);
			PlagiarismWebhookHandler::setPlugin($this);
			return true;
		}

		return false;
	}

	/**
	 * Confirm EULA, create submission and upload submission files to iThenticate service
	 * 
	 * @param string $hookName
	 * @param array $args
	 * 
	 * @return bool
	 */
	public function submitForPlagiarismCheck($hookName, $args) {
		$request = Application::get()->getRequest(); /** @var Request $request */
		$context = $request->getContext(); /** @var Context $context */
		$siteDao = DAORegistry::getDAO('SiteDAO'); /** @var SiteDAO $siteDao */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($request->getUserVar('submissionId')); /** @var Submission $submission */
		$publication = $submission->getCurrentPublication(); /** @var Publication $publication */
		$author = $publication->getPrimaryAuthor(); /** @var Author $author */

		// try to get credentials for current context otherwise use default config
		list($apiUrl, $apiKey) = $this->hasForcedCredentials()
			? $this->getForcedCredentials()
			: [
				$this->getSetting($context->getId(), 'ithenticateApiUrl'), 
				$this->getSetting($context->getId(), 'ithenticateApiKey')
			];

		import("plugins.generic.plagiarism.IThenticate");

		/** @var \IThenticate $ithenticate */
		$ithenticate = new IThenticate(
			$apiUrl,
			$apiKey,
			static::PLUGIN_INTEGRATION_NAME,
			$this->getCurrentVersion()->getData('current')
		);

		// Try to validate and confirm the EULA on user's behalf
		if (!$ithenticate->confirmEula($request->getUser(), $context)) {
			$this->sendErrorMessage($submission->getId(), 'Could not confirm EULA at iThenticate.');
			return false;
		}

		// Create the submission at iThenticate's end
		$submissionUuid = $ithenticate->submitSubmission(
			$submission,
			$request->getUser(),
			$author,
			$request->getSite()
		);

		if (!$submissionUuid) {
			$this->sendErrorMessage($submission->getId(), 'Could not submit the submission at iThenticate.');
			return false;
		}

		// $submission->setData('ithenticate_id', $submissionUuid);
		// $submissionDao->updateObject($submission);		
		import('classes.core.Services');
		Services::get("submission")->edit($submission, [
			'ithenticate_id' => $submissionUuid,
		], $request);
		
		// Upload submission files for successfully created submission at iThenticate's end
		if (!$ithenticate->uploadSubmissionFile($submissionUuid, $submission)) {
			$this->sendErrorMessage($submission->getId(), 'Could not complete the file upload at iThenticate.');
			return false;
		}

		// If no webhook previously registered for this Site/Context, register it
		$webhookStorable = $this->hasForcedCredentials() ? $request->getSite() : $context; /** @var Site|Context $webhookStorable */

		if (!$webhookStorable->getData('ithenticate_webhook_id')) {
			$signingSecret = \Illuminate\Support\Str::random(12);
			
			$webhookUrl = $request->getDispatcher()->url(
                $request,
                ROUTE_COMPONENT,
                $context->getData('urlPath'),
                'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
                'handle'
            );

			if ($webhookId = $ithenticate->registerWebhook($signingSecret, $webhookUrl)) {
				$webhookStorable->setData('ithenticate_webhook_signing_secret', $signingSecret);
				$webhookStorable->setData('ithenticate_webhook_id', $webhookId);
				$this->hasForcedCredentials()
					? $siteDao->updateObject($webhookStorable)
					: Application::get()->getContextDAO()->updateObject($webhookStorable);
			} else {
				error_log("unable to complete the iThenticate webhook registration during the submission process of ID : {$submission->getId()}");
			}
		}

		return true;
	}

	/**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb) {
        $router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
        
		return array_merge(
			$this->getEnabled() 
				? [
					new LinkAction(
                    	'settings',
                    	new AjaxModal(
                            $router->url(
								$request, 
								null, 
								null, 
								'manage', 
								null, 
								['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']
							),
                        	$this->getDisplayName()
                    	),
                    	__('manager.plugins.settings'),
                    	null
            		),
				] : [],
			parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext(); /** @var Context $context */

                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
                $templateMgr = TemplateManager::getManager($request); /** @var TemplateManager $templateMgr */
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);

                $this->import('PlagiarismSettingsForm');
                $form = new PlagiarismSettingsForm($this, $context->getId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }
}
