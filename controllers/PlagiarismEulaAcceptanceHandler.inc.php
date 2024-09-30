<?php

/**
 * @file controllers/PlagiarismEulaAcceptanceHandler.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismEulaAcceptanceHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the reconfirmation and acceptance of iThenticate EULA
 */

import("plugins.generic.plagiarism.controllers.PlagiarismComponentHandler");
import("plugins.generic.plagiarism.IThenticate");
import('lib.pkp.classes.security.authorization.SubmissionAccessPolicy');

class PlagiarismEulaAcceptanceHandler extends PlagiarismComponentHandler {

	/**
	 * @copydoc PKPHandler::__construct()
	 */
	public function __construct() {
		parent::__construct();

		$this->addRoleAssignment(
			[ROLE_ID_AUTHOR, ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR],
			['handle']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments, 'submissionId'));
		return parent::authorize($request, $args, $roleAssignments);
	}

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
		$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION); /** @var Submission $submission */
		$confirmSubmissionEula = $request->getUserVar('confirmSubmissionEula') ?? false;

		if (!$confirmSubmissionEula) {
			SessionManager::getManager()->getUserSession()->setSessionVar('confirmSubmissionEulaError', true);
			return $this->redirectToUrl(
				$request,
				$context,
				['submissionId' => $submission->getId()]
			);
		}

		SessionManager::getManager()->getUserSession()->unsetSessionVar('confirmSubmissionEulaError');

		static::$_plugin->stampEulaToSubmission($context, $submission);
		static::$_plugin->stampEulaToSubmittingUser($context, $submission, $user);

		return $this->redirectToUrl(
			$request,
			$context, 
			['submissionId' => $submission->getId()]
		);
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
