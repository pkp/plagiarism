<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismEulaAcceptanceHandler.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismEulaAcceptanceHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the reconfirmation and acceptance of iThenticate EULA
 */

import("plugins.generic.plagiarism.controllers.PlagiarismComponentHandler");
import("plugins.generic.plagiarism.IThenticate");

class PlagiarismEulaAcceptanceHandler extends PlagiarismComponentHandler {

	/**
	 * Handle the presentation of iThenticate EULA right before the submission final stage
	 *
	 * @param array $args
	 * @param Request $request
	 * 
	 * @return void
	 */
	public function handle($args, $request) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($args['submissionId']);
		$confirmSubmissionEula = $args['confirmSubmissionEula'] ?? false;

		if (!$confirmSubmissionEula) {
			SessionManager::getManager()->getUserSession()->setSessionVar('confirmSubmissionEulaError', true);
			return $this->redirectToUrl(
				$request,
				$context,
				['submissionId' => $args['submissionId']]
			);
		}

		SessionManager::getManager()->getUserSession()->unsetSessionVar('confirmSubmissionEulaError');

		static::$_plugin->stampEulaToSubmission($context, $submission);
		static::$_plugin->stampEulaToSubmittingUser($context, $submission, $user);

		return $this->redirectToUrl($request, $context, ['submissionId' => $args['submissionId']]);
	}

	/**
	 * Generate and get the redirection url
	 *
	 * @param Request $request
     * @param Context $context
     * @param array $args
	 * 
	 * @return string
	 */
	protected function redirectToUrl($request, $context, $args) {
        
		return $request->redirectUrl(
			$request->getDispatcher()->url(
				$request,
				ROUTE_PAGE,
				$context->getData('urlPath'),
				'submission',
				'wizard',
				4,
				$args
			)
		);
	}
    
}