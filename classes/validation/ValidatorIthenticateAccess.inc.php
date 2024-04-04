<?php

/**
 * @file plugins/generic/plagiarism/classes/validation/ValidatorIthenticateAccess.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ValidatorIthenticateAccess
 * @see Validator
 *
 * @brief Validator to validate iThenticate service access
 */

import ('lib.pkp.classes.validation.Validator');
import("plugins.generic.plagiarism.IThenticate");
import("plugins.generic.plagiarism.TestIThenticate");

class ValidatorIthenticateAccess extends Validator {
    
    /**
	 * The iThenticate API communicating service class instance
	 * 
	 * @var \IThenticate|\TestIThenticate
	 */
    protected $ithenticate;

    public function __construct($ithenticate) {
        $this->ithenticate = $ithenticate;
    }

    public function isValid($value) {
		return $this->ithenticate->validateAccess();
	}
} 
