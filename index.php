<?php

/**
 * @defgroup plugins_generic_plagiarism
 */

/**
 * @file plugins/generic/plagiarism/index.php
 *
 * Copyright (c) 2003-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_plagiarism
 * @brief Wrapper for books for review plugin.
 *
 */
require_once('PlagiarismPlugin.inc.php');

return new PlagiarismPlugin();
