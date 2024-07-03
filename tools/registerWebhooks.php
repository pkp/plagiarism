<?php

require(dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/tools/bootstrap.inc.php');

import("plugins.generic.plagiarism.PlagiarismPlugin");

class RegisterWebhooks extends CommandLineTool {

    /**
	 * The specific context path for which intend to update the iThenticate webhook details
	 * 
	 * @var string
	 */
	protected $contextPath = null;

    /**
	 * Constructor.
	 * 
	 * @param array $argv command-line arguments
	 */
	public function __construct($argv = []) {
		
		parent::__construct($argv);

		if (isset($this->argv[0])) {
			$this->contextPath = $this->argv[0];
		}
	}

    /**
	 * Print command usage information.
	 */
	public function usage() {
		echo "Register Webhooks for iThenticate Account.\n\n"
			. "Usage: {$this->scriptName} optional.context.path \n\n";
	}

	/**
	 * Execute the specified migration.
	 */
	public function execute() {
		try {

			$plagiarismPlugin = new PlagiarismPlugin();

			if ($this->contextPath) {
				$contextDao = Application::getContextDAO(); /** @var ContextDAO $contextDao */
				$context = $contextDao->getByPath($this->contextPath); /** @var Context $context */
				if (!$context) {
					throw new \Exception("No context found for given context path: {$this->contextPath}");
				}
				
				$this->updateWebhook($context, $plagiarismPlugin);

				return;
			}

			// check if there is a global level config e.g. one config for all context
			// 		- Run webhook update for all contexts 
			// If there is no global level config, then check if each context level config
			// 		- Run webhook update only for those specific context
			// if no global level or context level config defined, e.g. configs are managed via plugin setting from
			//		- nothing to do as plugin's setting will handle webhook update on config update

			$contextService = Services::get("context"); /** @var \APP\Services\ContextService $contextService */
			foreach($contextService->getMany() as $context) { /** @var Context $context */
				$this->updateWebhook($context, $plagiarismPlugin);
			}

		} catch (\Throwable $exception) {
			echo 'EXCEPTION: ' . $exception->getMessage() . "\n\n";
			exit(2);
		}
	}

	/**
	 * Update the webhook details for given context
	 * 
	 * @param Context 			$context
	 * @param PlagiarismPlugin	$plagiarismPlugin
	 * 
	 * @return void
	 */
	protected function updateWebhook($context, $plagiarismPlugin) {
		if (!$plagiarismPlugin->hasForcedCredentials($context)) {
			echo "ERROR: No forced credentails defined for context path : {$context->getData('urlPath')}\n\n";
			return;
		}

		/** @var IThenticate|TestIThenticate $ithenticate */
		$ithenticate = $plagiarismPlugin->initIthenticate(
			...$plagiarismPlugin->getForcedCredentials($context)
		);

		// If there is a already registered webhook for this context, need to delete it first
		// before creating a new one as webhook URL remains same which will return 409(HTTP_CONFLICT)
		$existingWebhookId = $context->getData('ithenticateWebhookId');
		if ($existingWebhookId) {
			$ithenticate->deleteWebhook($existingWebhookId);
		}

		$webhookUpdateStatus = $plagiarismPlugin->registerIthenticateWebhook($ithenticate, $context);

		echo $webhookUpdateStatus
			? "SUCCESS: updated the webhook for context path : {$context->getData('urlPath')}\n\n"
			: "ERROR: unable to updated the webhook for context path : {$context->getData('urlPath')}\n\n";
	}
}

$tool = new RegisterWebhooks(isset($argv) ? $argv : []);
$tool->execute();
